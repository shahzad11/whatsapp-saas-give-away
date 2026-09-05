<?php

// Plan + quota layer. Every limit is per tenant (= per user).
//
// A NULL limit column means "unlimited". That distinction matters: 0 is a real
// limit meaning "none allowed", so limits are compared with an explicit null
// check rather than a falsy test.

function getUserPlan(mysqli $conn, $userId) {
    static $cache = [];
    $userId = (int)$userId;
    if (isset($cache[$userId])) return $cache[$userId];

    $stmt = $conn->prepare(
        "SELECT p.* FROM users u JOIN plans p ON u.plan_id = p.id WHERE u.id = ?"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // A tenant with no plan assigned (legacy row, failed signup) still needs
    // limits applied, so fall back to the configured default rather than to
    // "unlimited".
    if (!$plan) {
        $plan = getPlanByCode($conn, defaultPlanCode($conn));
    }

    $cache[$userId] = $plan;
    return $plan;
}

function getPlanByCode(mysqli $conn, $code) {
    $stmt = $conn->prepare("SELECT * FROM plans WHERE code = ?");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $plan ?: null;
}

function getActivePlans(mysqli $conn) {
    $result = $conn->query("SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order ASC");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function planLimit($plan, $key) {
    if (!$plan || !array_key_exists($key, $plan)) return null;
    return $plan[$key] === null ? null : (int)$plan[$key];
}

function countWaAccounts(mysqli $conn, $userId) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM wa_accounts WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

function countContacts(mysqli $conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM wa_contacts c
         JOIN wa_accounts a ON c.account_id = a.id WHERE a.user_id = ?"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

function currentPeriod() {
    return gmdate('Y-m');
}

function usageCount(mysqli $conn, $userId, $metric, $period = null) {
    $period = $period ?? currentPeriod();
    $stmt = $conn->prepare(
        "SELECT value FROM usage_counters WHERE user_id = ? AND period_ym = ? AND metric = ?"
    );
    $stmt->bind_param('iss', $userId, $period, $metric);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['value'] ?? 0);
}

function incrementUsage(mysqli $conn, $userId, $metric, $by = 1) {
    $period = currentPeriod();
    $stmt = $conn->prepare(
        "INSERT INTO usage_counters (user_id, period_ym, metric, value) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE value = value + VALUES(value)"
    );
    $stmt->bind_param('issi', $userId, $period, $metric, $by);
    $stmt->execute();
    $stmt->close();
}

// Returns [allowed(bool), used(int), limit(int|null)].
function checkWaAccountQuota(mysqli $conn, $userId) {
    $plan = getUserPlan($conn, $userId);
    $limit = planLimit($plan, 'max_wa_accounts');
    $used = countWaAccounts($conn, $userId);

    if ($limit === null) return [true, $used, null];
    return [$used < $limit, $used, $limit];
}

// Contacts are not created by an explicit user action — they appear as a side
// effect of WhatsApp sync — so this quota cannot be enforced by refusing a
// request. The caller uses the headroom to stop storing *new* contacts while
// still updating the ones it already has: freezing updates would make the chat
// list rot, and deleting to fit would destroy data the tenant did not choose to
// lose. Reaching the cap therefore stops growth, nothing else.
function checkContactQuota(mysqli $conn, $userId) {
    $plan = getUserPlan($conn, $userId);
    $limit = planLimit($plan, 'max_contacts');
    $used = countContacts($conn, $userId);

    if ($limit === null) return [true, $used, null];
    return [$used < $limit, $used, $limit];
}

function checkMessageQuota(mysqli $conn, $userId) {
    $plan = getUserPlan($conn, $userId);
    $limit = planLimit($plan, 'max_messages_per_month');
    $used = usageCount($conn, $userId, 'messages_sent');

    if ($limit === null) return [true, $used, null];
    return [$used < $limit, $used, $limit];
}

// AI replies, metered separately from messages.
//
// An AI reply is also a message, so it consumes both this and the message quota,
// and the stricter of the two wins. They are separate because they cost
// different things: a message costs nothing but WhatsApp goodwill, while a reply
// costs real money per token at a vendor. Without this, "how much AI is included"
// could not be priced apart from "how many messages are included".
//
// A tenant on their own key (`llm_byok`) is billed by the vendor directly, so
// this limit is skipped for them — capping usage the platform is not paying for
// would be arbitrary. The BYO branch is authoritative in chatbotResolveModel(),
// so the condition here matches the one that actually decides whose key is used.
function checkChatbotReplyQuota(mysqli $conn, $userId, array $config = null) {
    $plan = getUserPlan($conn, $userId);
    $used = usageCount($conn, $userId, 'chatbot_replies');

    if ($config !== null
        && !empty($config['byo_provider_code'])
        && !empty($config['byo_api_key_encrypted'])
        && planHasFeature($plan, 'llm_byok')) {
        return [true, $used, null];
    }

    $limit = planLimit($plan, 'max_chatbot_replies');
    if ($limit === null) return [true, $used, null];
    return [$used < $limit, $used, $limit];
}

// Bookable services. Counted live rather than metered per month: this is a
// "how many can exist" limit, not a "how many per period" one.
function checkServiceQuota(mysqli $conn, $userId) {
    $plan = getUserPlan($conn, $userId);
    $limit = planLimit($plan, 'max_services');
    $used = countServices($conn, $userId);

    if ($limit === null) return [true, $used, null];
    return [$used < $limit, $used, $limit];
}

function countServices(mysqli $conn, $userId) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM appointment_services WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

function formatLimit($limit) {
    return $limit === null ? 'Unlimited' : number_format($limit);
}

// No currency is hardcoded here. A price is displayed in the currency it is
// *denominated* in (`plans.currency`), falling back to the instance-wide
// `currency` setting when the row does not say.
//
// The row wins deliberately. Reading the global setting unconditionally meant a
// plan the seed had not converted — an admin-edited price still stored as USD
// 7900 — rendered as "Rs. 79" once the instance currency was PKR: a $79 plan
// shown as 79 rupees. Relabelling money is never safe; only converting it is,
// and that needs a rate we do not have.
//
// Because a fresh install and the seed migration both leave every row in the
// instance currency, changing that setting still re-renders every price, which
// is the behaviour #10 asked for — it just cannot silently misstate a row that
// legitimately differs.
function formatPrice($plan) {
    $minor = (int)($plan['price_cents'] ?? 0);
    if ($minor === 0) return 'Free';

    $currency = $plan['currency'] ?? null;
    $amount = formatMoney($minor, $currency ?: appCurrency());
    if (($plan['billing_period'] ?? 'month') === 'none') return $amount;

    $period = ($plan['billing_period'] ?? 'month') === 'year' ? '/year' : '/month';
    return $amount . $period;
}

// --- Feature flags ----------------------------------------------------------
//
// Stored as JSON in plans.features rather than as a column per flag: they
// accrete (chatbot, BYO LLM key, transcription…) and each one should not cost a
// migration. Absent or unparseable JSON reads as "no features", so a plan row
// written before this existed is simply a plan with nothing switched on.

// The three chatbot sub-features are listed after `chatbot` because they depend
// on it: with `chatbot` off, none of them is reachable regardless of its own
// value. That dependency is enforced in code, not just implied by the order —
// see planHasFeature()'s callers in the reply path.
function planFeatureDefinitions() {
    return [
        'chatbot'             => ['label' => 'AI chatbot',              'help' => 'Tenant may run an LLM chatbot on their WhatsApp accounts.'],
        'llm_byok'            => ['label' => 'Bring your own LLM key',  'help' => 'Tenant may supply their own provider API key.'],
        'appointments'        => ['label' => 'Appointment booking',     'help' => 'Customers may book, reschedule and cancel in the chat. Needs the AI chatbot.'],
        'handoff'             => ['label' => 'Human handover',          'help' => 'Customers may reach a person, and Live chats is available. Needs the AI chatbot.'],
        'voice_transcription' => ['label' => 'Voice note understanding', 'help' => 'Incoming voice notes are transcribed, then answered. Needs the AI chatbot and a transcription model.'],
        'media_send'          => ['label' => 'Send media',              'help' => 'Attachments, images, video and voice notes in the composer.'],
        'csv_export'          => ['label' => 'CSV export',              'help' => 'Export contacts to CSV.'],
    ];
}

function planFeatures($plan) {
    $raw = $plan['features'] ?? null;
    if ($raw === null || $raw === '') return [];
    if (is_array($raw)) return $raw;

    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : [];
}

function planHasFeature($plan, $key) {
    $features = planFeatures($plan);
    return !empty($features[$key]);
}

// Only known flags are persisted, so a crafted POST cannot inject arbitrary
// keys into the JSON document.
function encodePlanFeatures(array $submitted) {
    $out = [];
    foreach (array_keys(planFeatureDefinitions()) as $key) {
        $out[$key] = !empty($submitted[$key]);
    }
    return json_encode($out);
}

function countTenantsOnPlan(mysqli $conn, $planId) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM users WHERE plan_id = ?");
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

function getPlanById(mysqli $conn, $planId) {
    $stmt = $conn->prepare("SELECT * FROM plans WHERE id = ?");
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $plan ?: null;
}

// Moves a tenant onto a plan and opens a subscription period for it.
//
// The previous live period is closed first. Without that, a tenant who changed
// plan ended up with two rows in a live status, and lapsingSubscriptions() joins
// on status rather than on "the newest row" — so the admin's lapsing report
// listed the tenant twice, once under a plan they had already left. Moving plan
// ends the old subscription; that is what 'canceled' means here.
function assignPlan(mysqli $conn, $userId, $planId) {
    $stmt = $conn->prepare("UPDATE users SET plan_id = ? WHERE id = ?");
    $stmt->bind_param('ii', $planId, $userId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare(
        "UPDATE subscriptions SET status = 'canceled'
          WHERE user_id = ? AND status IN ('active','trialing','past_due')"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare(
        "INSERT INTO subscriptions (user_id, plan_id, status, current_period_start, current_period_end)
         VALUES (?, ?, 'active', UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 MONTH))"
    );
    $stmt->bind_param('ii', $userId, $planId);
    $stmt->execute();
    $stmt->close();
}
