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
        $plan = getPlanByCode($conn, DEFAULT_PLAN_CODE);
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

function checkMessageQuota(mysqli $conn, $userId) {
    $plan = getUserPlan($conn, $userId);
    $limit = planLimit($plan, 'max_messages_per_month');
    $used = usageCount($conn, $userId, 'messages_sent');

    if ($limit === null) return [true, $used, null];
    return [$used < $limit, $used, $limit];
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

function assignPlan(mysqli $conn, $userId, $planId) {
    $stmt = $conn->prepare("UPDATE users SET plan_id = ? WHERE id = ?");
    $stmt->bind_param('ii', $planId, $userId);
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
