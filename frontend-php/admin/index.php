<?php
// Admin console landing — real metrics from MySQL.
//
// Operational metadata only. Message contents are never exposed to an admin,
// and that boundary must survive every future addition to this page.
require_once dirname(__DIR__) . '/includes/admin-init.php';

// Dismissing the getting-started card. Stored instance-wide rather than per
// admin: the checklist describes the instance, not a person, and a second admin
// does not need to dismiss it again.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dismiss_setup') {
    if (!verifyCsrf()) {
        redirect(APP_URL . '/admin/index.php');
    }
    setAppSetting($conn, 'setup_checklist_dismissed', '1');
    logAudit($conn, 'admin.setup.dismissed', 'app_settings', null);
    redirect(APP_URL . '/admin/index.php');
}

$setupSteps = instanceSetupSteps($conn);
$setupOutstanding = count(array_filter($setupSteps, fn($s) => !$s['done']));
// Hidden once everything is done, whether or not it was ever dismissed — a
// checklist with nothing left on it is clutter.
$showSetup = $setupOutstanding > 0 && !instanceSetupDismissed($conn);

// One round trip for the counters. These are all indexed lookups or small
// aggregates; the tenant table is the only one that grows with signups.
$stats = $conn->query(
    "SELECT
        (SELECT COUNT(*) FROM users) AS total_users,
        (SELECT COUNT(*) FROM users WHERE status = 'active' AND is_active = 1) AS active_users,
        (SELECT COUNT(*) FROM users WHERE status = 'suspended') AS suspended_users,
        (SELECT COUNT(*) FROM users WHERE is_active = 0) AS unactivated_users,
        (SELECT COUNT(*) FROM users
          WHERE created_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')) AS new_this_month,
        (SELECT COUNT(*) FROM wa_accounts) AS total_accounts,
        (SELECT COUNT(*) FROM wa_accounts WHERE status = 'connected') AS connected_accounts,
        (SELECT COUNT(*) FROM wa_accounts WHERE status IN ('logged_out','failed')) AS broken_accounts,
        (SELECT COALESCE(SUM(value), 0) FROM usage_counters
          WHERE metric = 'messages_sent' AND period_ym = DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m')) AS messages_this_month,
        (SELECT COUNT(*) FROM users u JOIN plans p ON u.plan_id = p.id WHERE p.price_cents > 0) AS paid_tenants"
)->fetch_assoc();

// Top tenants by messages sent this month.
$topTenants = $conn->query(
    "SELECT u.id, u.name, u.email, uc.value AS messages, p.name AS plan_name
     FROM usage_counters uc
     JOIN users u ON uc.user_id = u.id
     LEFT JOIN plans p ON u.plan_id = p.id
     WHERE uc.metric = 'messages_sent' AND uc.period_ym = DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m')
     ORDER BY uc.value DESC LIMIT 8"
)->fetch_all(MYSQLI_ASSOC);

$planBreakdown = $conn->query(
    "SELECT p.name, p.code, p.price_cents, p.currency, p.billing_period, p.is_active,
            (SELECT COUNT(*) FROM users u WHERE u.plan_id = p.id) AS tenants
     FROM plans p ORDER BY p.sort_order"
)->fetch_all(MYSQLI_ASSOC);

$totals = paymentTotals($conn);
$lapsing = lapsingSubscriptions($conn, 7);

// 60 days of outbound activity and bot replies, bucketed per UTC day. Both
// series are metadata counts only — message contents stay unread.
$days = [];
for ($i = 59; $i >= 0; $i--) {
    $days[gmdate('Y-m-d', strtotime("-$i days"))] = 0;
}
$msgSeries = $days;
$aiSeries = $days;
foreach ($conn->query(
    "SELECT DATE(message_timestamp) AS day, COUNT(*) AS total
     FROM wa_messages
     WHERE from_me = 1 AND message_timestamp >= DATE_SUB(UTC_DATE(), INTERVAL 59 DAY)
     GROUP BY DATE(message_timestamp) ORDER BY day"
)->fetch_all(MYSQLI_ASSOC) as $r) {
    if (isset($msgSeries[$r['day']])) $msgSeries[$r['day']] = (int)$r['total'];
}
foreach ($conn->query(
    "SELECT DATE(created_at) AS day, COUNT(*) AS total
     FROM chatbot_events
     WHERE outcome = 'replied' AND created_at >= DATE_SUB(UTC_DATE(), INTERVAL 59 DAY)
     GROUP BY DATE(created_at) ORDER BY day"
)->fetch_all(MYSQLI_ASSOC) as $r) {
    if (isset($aiSeries[$r['day']])) $aiSeries[$r['day']] = (int)$r['total'];
}
$msgPrev = array_slice($msgSeries, 0, 30);
$msgCurr = array_slice($msgSeries, 30);
$aiPrev = array_slice($aiSeries, 0, 30);
$aiCurr = array_slice($aiSeries, 30);
$msgPrevTotal = array_sum($msgPrev);
$msgCurrTotal = array_sum($msgCurr);
$aiPrevTotal = array_sum($aiPrev);
$aiCurrTotal = array_sum($aiCurr);
$trendMax = max(max($msgCurr ?: [0]), max($aiCurr ?: [0]), 1);

function overviewTrendPoints(array $values, int $max): string {
    $pts = [];
    $i = 0;
    foreach ($values as $v) {
        $x = 12 + (588 - 12) * $i / 29;
        $y = 142 - ($v / $max) * 120;
        $pts[] = round($x, 1) . ',' . round($y, 1);
        $i++;
    }
    return implode(' ', $pts);
}
function overviewTrendPhrase(int $curr, int $prev): string {
    if ($prev === 0) return $curr === 0 ? 'No change from the previous 30 days' : 'No activity in the previous 30 days';
    $pct = (int)round(($curr - $prev) / $prev * 100);
    if ($pct === 0) return 'No change from the previous 30 days';
    return ($pct > 0 ? 'Up ' : 'Down ') . abs($pct) . '% from the previous 30 days';
}

// Per-tenant quota usage, for the 80%+ watch list. Counts and limits only.
$quotaRows = $conn->query(
    "SELECT u.id, u.name, u.email, p.name AS plan_name,
            p.max_wa_accounts, p.max_contacts, p.max_messages_per_month,
            p.max_chatbot_replies, p.max_services,
            (SELECT COUNT(*) FROM wa_accounts wa WHERE wa.user_id = u.id) AS wa_accounts_used,
            (SELECT COUNT(*) FROM wa_contacts wc
             JOIN wa_accounts wa2 ON wc.account_id = wa2.id
             WHERE wa2.user_id = u.id
               AND wc.chat_id NOT IN ('status@broadcast', '0@s.whatsapp.net')
               AND wc.chat_id NOT LIKE '%@newsletter'
               AND wc.chat_id NOT LIKE '%@broadcast') AS contacts_used,
            COALESCE((SELECT uc.value FROM usage_counters uc
                      WHERE uc.user_id = u.id AND uc.period_ym = DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m')
                        AND uc.metric = 'messages_sent'), 0) AS messages_used,
            COALESCE((SELECT uc.value FROM usage_counters uc
                      WHERE uc.user_id = u.id AND uc.period_ym = DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m')
                        AND uc.metric = 'chatbot_replies'), 0) AS replies_used,
            (SELECT COUNT(*) FROM appointment_services aps WHERE aps.user_id = u.id) AS services_used,
            CASE WHEN cc.byo_provider_code IS NOT NULL AND cc.byo_provider_code <> ''
                       AND cc.byo_api_key_encrypted IS NOT NULL AND cc.byo_api_key_encrypted <> ''
                       AND JSON_UNQUOTE(JSON_EXTRACT(p.features, '$.llm_byok')) = 'true'
                 THEN 0 ELSE 1 END AS ai_quota_applies
     FROM users u
     JOIN plans p ON u.plan_id = p.id
     LEFT JOIN chatbot_configs cc ON cc.user_id = u.id
     WHERE u.is_admin = 0 AND u.status = 'active'
     ORDER BY u.name"
)->fetch_all(MYSQLI_ASSOC);

$quotaWatch = [];
foreach ($quotaRows as $q) {
    $resources = [
        ['Accounts',    'wa_accounts_used',  'max_wa_accounts'],
        ['Contacts',    'contacts_used',     'max_contacts'],
        ['Messages',    'messages_used',     'max_messages_per_month'],
        ['AI replies',  'replies_used',      'max_chatbot_replies'],
        ['Services',    'services_used',     'max_services'],
    ];
    foreach ($resources as [$label, $usedKey, $limitKey]) {
        if ($q[$limitKey] === null) continue;
        if ($label === 'AI replies' && !(int)$q['ai_quota_applies']) continue;
        $used = (int)$q[$usedKey];
        $limit = (int)$q[$limitKey];
        $pct = $limit > 0 ? (int)round($used / $limit * 100) : ($used > 0 ? 100 : 0);
        if ($pct < 80) continue;
        $quotaWatch[] = [
            'id' => (int)$q['id'], 'tenant' => $q['name'], 'plan' => $q['plan_name'],
            'resource' => $label, 'used' => $used, 'limit' => $limit, 'pct' => $pct,
        ];
    }
}
usort($quotaWatch, fn($a, $b) => $b['pct'] <=> $a['pct'] ?: strcmp($a['tenant'], $b['tenant']));
$quotaWatch = array_slice($quotaWatch, 0, 8);

// Automation, delivery, billing, engagement — all counts/metadata.
$automation = $conn->query(
    "SELECT
      (SELECT COUNT(*) FROM chatbot_configs WHERE is_enabled = 1) AS chatbot_enabled,
      (SELECT COUNT(*) FROM chatbot_configs cc
       JOIN users u ON u.id = cc.user_id
       LEFT JOIN plans pl ON pl.id = u.plan_id
       LEFT JOIN llm_models m ON m.id = cc.model_id
       LEFT JOIN llm_providers lp ON lp.id = m.provider_id
       WHERE cc.is_enabled = 1
         AND NOT ((cc.byo_provider_code IS NOT NULL AND cc.byo_provider_code <> ''
                   AND cc.byo_api_key_encrypted IS NOT NULL AND cc.byo_api_key_encrypted <> ''
                   AND JSON_UNQUOTE(JSON_EXTRACT(pl.features, '$.llm_byok')) = 'true')
                  OR (m.id IS NOT NULL AND m.is_enabled = 1 AND lp.is_enabled = 1))) AS chatbot_misconfigured,
      (SELECT COUNT(*) FROM chatbot_events
       WHERE outcome = 'replied' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)) AS replies_24h,
      (SELECT COUNT(*) FROM chatbot_events
       WHERE outcome IN ('config_error', 'llm_error', 'send_error')
         AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)) AS errors_24h,
      (SELECT COUNT(*) FROM llm_providers WHERE is_enabled = 1) AS providers_enabled,
      (SELECT COUNT(*) FROM llm_providers WHERE is_enabled = 1 AND last_test_ok = 1) AS providers_healthy,
      (SELECT COUNT(*) FROM chatbot_events
       WHERE outcome = 'handoff' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)) AS handoffs_30d"
)->fetch_assoc();

$waHealth = $conn->query(
    "SELECT COUNT(*) AS total,
            SUM(status = 'connected') AS connected,
            SUM(status IN ('logged_out', 'failed', 'disconnected')) AS unhealthy,
            SUM(status = 'reconnecting') AS reconnecting,
            SUM(provider = 'baileys') AS baileys,
            SUM(provider = 'cloud') AS cloud,
            SUM(provider = 'cloud' AND cloud_last_error IS NOT NULL AND cloud_last_error <> '') AS cloud_errors
     FROM wa_accounts"
)->fetch_assoc();
$waPct = (int)$waHealth['total'] > 0 ? (int)round((int)$waHealth['connected'] / (int)$waHealth['total'] * 100) : 0;

$billing = $conn->query(
    "SELECT
      (SELECT COUNT(*) FROM users u JOIN plans p ON p.id = u.plan_id
       WHERE u.is_admin = 0 AND p.price_cents > 0
         AND NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id = u.id
                         AND s.status IN ('active', 'trialing', 'past_due'))) AS paid_without_subscription,
      (SELECT COUNT(*) FROM users u JOIN plans p ON p.id = u.plan_id
       WHERE u.is_admin = 0 AND p.price_cents > 0
         AND u.created_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')) AS new_paid_this_month"
)->fetch_assoc();

$totalsPrev = paymentTotals($conn, gmdate('Y-m', strtotime('first day of previous month UTC')));
$currencyCompare = [];
foreach ($totals as $t) $currencyCompare[$t['currency']]['curr'] = (int)$t['total'];
foreach ($totalsPrev as $t) $currencyCompare[$t['currency']]['prev'] = (int)$t['total'];
$recentPayments = getPayments($conn, null, 5);

$today = gmdate('Y-m-d');
$lapsingExpired = array_values(array_filter($lapsing, fn($s) => substr((string)$s['current_period_end'], 0, 10) < $today));
$lapsingUpcoming = array_values(array_filter($lapsing, fn($s) => substr((string)$s['current_period_end'], 0, 10) >= $today));

$engagementRows = $conn->query(
    "SELECT u.id, u.name, u.email, u.created_at, u.last_login_at,
            (SELECT MAX(wm.message_timestamp) FROM wa_messages wm
             JOIN wa_accounts wa ON wa.id = wm.account_id WHERE wa.user_id = u.id) AS last_message_at,
            (SELECT COUNT(*) FROM wa_accounts wa WHERE wa.user_id = u.id) AS account_count,
            (SELECT COUNT(*) FROM wa_messages wm
             JOIN wa_accounts wa ON wa.id = wm.account_id
             WHERE wa.user_id = u.id AND wm.from_me = 1) AS sent_count
     FROM users u WHERE u.is_admin = 0 ORDER BY u.name"
)->fetch_all(MYSQLI_ASSOC);
$engagement = ['24h' => 0, '7d' => 0, '30d' => 0, 'older' => 0, 'never' => 0, 'never_linked' => 0, 'linked_never_sent' => 0];
$now = time();
foreach ($engagementRows as $e) {
    if ((int)$e['account_count'] === 0) $engagement['never_linked']++;
    elseif ((int)$e['sent_count'] === 0) $engagement['linked_never_sent']++;
    $last = max(
        $e['last_login_at'] ? strtotime($e['last_login_at'] . ' UTC') : 0,
        $e['last_message_at'] ? strtotime($e['last_message_at'] . ' UTC') : 0
    );
    $age = $last ? $now - $last : null;
    if ($age === null) $engagement['never']++;
    elseif ($age <= 86400) $engagement['24h']++;
    elseif ($age <= 7 * 86400) $engagement['7d']++;
    elseif ($age <= 30 * 86400) $engagement['30d']++;
    else $engagement['older']++;
}

$planAssignments = $conn->query(
    "SELECT a.action, a.entity_id, a.created_at, target.name AS tenant_name, pl.name AS plan_name
     FROM audit_log a
     LEFT JOIN users target ON target.id = CAST(a.entity_id AS UNSIGNED)
     LEFT JOIN plans pl ON pl.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(a.meta, '$.plan_id')) AS UNSIGNED)
     WHERE a.action IN ('admin.user.change_plan', 'admin.payment.apply_plan')
     ORDER BY a.id DESC LIMIT 5"
)->fetch_all(MYSQLI_ASSOC);

$activity = $conn->query(
    "SELECT a.action, a.entity, a.entity_id, a.created_at, actor.name AS actor_name
     FROM audit_log a
     LEFT JOIN users actor ON actor.id = a.user_id
     WHERE a.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
       AND a.action IN ('admin.user.create','admin.user.suspend','admin.user.activate',
                        'admin.user.change_plan','admin.user.toggle_admin',
                        'admin.payment.record','admin.payment.apply_plan',
                        'admin.plan.create','admin.plan.update','admin.plan.toggle_active',
                        'admin.settings.update','admin.smtp.update','admin.branding.update',
                        'llm.provider_saved','llm.provider_tested','llm.model_added',
                        'llm.model_toggled','llm.model_deleted','llm.plan_access_saved','llm.toggles_saved',
                        'wa_account.link','wa_account.relink','wa_account.unlink','wa_account.cloud_connect')
     ORDER BY a.id DESC LIMIT 6"
)->fetch_all(MYSQLI_ASSOC);

$failedSignins = (int)$conn->query(
    "SELECT COUNT(*) AS uncleared_failed_signins_24h
     FROM login_attempts
     WHERE success = 0 AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)"
)->fetch_assoc()['uncleared_failed_signins_24h'];

$pageTitle = 'Overview';
require_once dirname(__DIR__) . '/includes/admin-header.php';
?>

<?php if ($showSetup): ?>
<div class="card mb-4 admin-setup-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-rocket-takeoff me-2"></i>Getting started —
            <?= (int)$setupOutstanding ?> of <?= count($setupSteps) ?> steps left</span>
        <form method="POST" class="d-inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="dismiss_setup">
            <button class="btn btn-sm btn-link text-muted text-decoration-none" type="submit"
                    title="Hides this card. Nothing is marked as done.">Hide this</button>
        </form>
    </div>
    <div class="card-body">
        <p class="text-muted small">
            A new app needs these steps before customers can use it. Each step is checked against
            the live configuration, not a completed wizard.
        </p>
        <ol class="list-unstyled mb-0">
            <?php foreach ($setupSteps as $step): ?>
            <li class="d-flex align-items-start gap-2 mb-2">
                <i class="bi <?= $step['done'] ? 'bi-check-circle-fill text-success' : 'bi-circle text-muted' ?> mt-1"></i>
                <div>
                    <?php if ($step['done']): ?>
                        <span class="text-muted"><s><?= sanitize($step['label']) ?></s></span>
                    <?php else: ?>
                        <a href="<?= sanitize($step['url']) ?>" class="fw-500"><?= sanitize($step['label']) ?></a>
                        <div class="x-small text-muted"><?= sanitize($step['why']) ?></div>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>
</div>
<?php endif; ?>

<?php if ($lapsing): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center">
    <div>
        <i class="bi bi-exclamation-triangle me-2"></i>
        <strong><?= count($lapsing) ?></strong> paid billing <?= count($lapsing) === 1 ? 'period is' : 'periods are' ?> due within 7 days or already overdue with no payment recorded.
    </div>
    <a href="<?= APP_URL ?>/admin/payments.php" class="btn btn-sm btn-warning">Review</a>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card kpi-card h-100">
            <span class="kpi-icon"><i class="bi bi-people"></i></span>
            <div>
                <div class="kpi-value"><?= number_format((int)$stats['total_users']) ?></div>
                <div class="kpi-label">Customers</div>
                <div class="kpi-sub"><?= number_format((int)$stats['active_users']) ?> active &middot; <?= number_format((int)$stats['new_this_month']) ?> new this month</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card kpi-card h-100">
            <span class="kpi-icon"><i class="bi bi-plug"></i></span>
            <div>
                <div class="kpi-value"><?= number_format((int)$stats['connected_accounts']) ?></div>
                <div class="kpi-label">Connected WhatsApp accounts</div>
                <div class="kpi-sub">of <?= number_format((int)$stats['total_accounts']) ?> linked</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card kpi-card h-100">
            <span class="kpi-icon"><i class="bi bi-chat-dots"></i></span>
            <div>
                <div class="kpi-value"><?= number_format((int)$stats['messages_this_month']) ?></div>
                <div class="kpi-label">Messages this month</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card kpi-card h-100">
            <span class="kpi-icon"><i class="bi bi-cash-coin"></i></span>
            <div>
                <?php if (!$totals): ?>
                    <div class="kpi-value">—</div>
                <?php else: ?>
                    <?php // One line per currency: a cross-currency total would be
                          // meaningless without a rate, and would be believed. ?>
                    <?php foreach ($totals as $t): ?>
                        <div class="kpi-value kpi-value-sm"><?= sanitize(formatMoney((int)$t['total'], $t['currency'])) ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="kpi-label">Received this month</div>
                <div class="kpi-sub"><?= number_format((int)$stats['paid_tenants']) ?> paying <?= (int)$stats['paid_tenants'] === 1 ? 'customer' : 'customers' ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card overview-trend-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-graph-up"></i>30-day activity</span>
        <span class="trend-legend">
            <span><i class="trend-swatch is-messages"></i>Outgoing messages</span>
            <span><i class="trend-swatch is-ai"></i>AI replies</span>
        </span>
    </div>
    <div class="card-body">
        <div class="trend-summary">
            <div class="trend-stat">
                <span class="trend-label"><i class="trend-swatch is-messages"></i>Outgoing messages</span>
                <span class="trend-value"><?= number_format($msgCurrTotal) ?></span>
                <span class="trend-comparison"><?= overviewTrendPhrase($msgCurrTotal, $msgPrevTotal) ?></span>
            </div>
            <div class="trend-stat">
                <span class="trend-label"><i class="trend-swatch is-ai"></i>AI replies</span>
                <span class="trend-value"><?= number_format($aiCurrTotal) ?></span>
                <span class="trend-comparison"><?= overviewTrendPhrase($aiCurrTotal, $aiPrevTotal) ?></span>
            </div>
        </div>
        <svg class="trend-chart" viewBox="0 0 600 160" role="img" aria-labelledby="trendChartTitle">
            <title id="trendChartTitle">Outgoing messages and AI replies per day for the last 30 days</title>
            <?php for ($g = 0; $g < 4; $g++): ?>
                <line class="trend-grid" x1="12" x2="588" y1="<?= 22 + $g * 40 ?>" y2="<?= 22 + $g * 40 ?>" />
            <?php endfor; ?>
            <polyline class="trend-line is-messages" points="<?= overviewTrendPoints($msgCurr, $trendMax) ?>" />
            <polyline class="trend-line is-ai" points="<?= overviewTrendPoints($aiCurr, $trendMax) ?>" />
        </svg>
        <?php if ($msgCurrTotal === 0 && $aiCurrTotal === 0): ?>
            <p class="text-muted x-small mb-0">No recorded activity in this period.</p>
        <?php endif; ?>
        <div class="trend-axis">
            <span><?= sanitize(gmdate('M j', strtotime(array_key_first($msgCurr)))) ?></span>
            <span><?= sanitize(gmdate('M j', strtotime(array_keys($msgCurr)[14]))) ?></span>
            <span><?= sanitize(gmdate('M j', strtotime(array_key_last($msgCurr)))) ?></span>
        </div>
    </div>
</div>

<?php // The exceptions panel replaces the Suspended / Paid tenants / WA broken /
      // Active cards: a zero in a coloured card looked like a problem. A count
      // above zero is a link to the filtered list that answers it; a zero is
      // muted text, because a link to an empty filter is a dead end. ?>
<?php
$attention = [
    ['suspended',   'person-slash',      'is-danger', (int)$stats['suspended_users'],   'Suspended customers',               APP_URL . '/admin/tenants.php?status=suspended'],
    ['unactivated', 'person-exclamation','is-warn',   (int)$stats['unactivated_users'], 'Customers not activated',           APP_URL . '/admin/tenants.php?status=unactivated'],
    ['broken',      'plug-fill',         'is-warn',   (int)$stats['broken_accounts'],   'WhatsApp accounts needing a rescan', APP_URL . '/admin/system.php'],
];
$allClear = !array_filter(array_column($attention, 3));
?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-exclamation-circle"></i>Needs attention</div>
    <div class="card-body pt-1 pb-2">
        <?php if ($allClear): ?>
            <div class="d-flex align-items-center gap-2 py-2">
                <span class="kpi-icon kpi-icon-sm is-ok"><i class="bi bi-check-lg"></i></span>
                <span class="text-muted">Nothing needs attention.</span>
            </div>
        <?php else: ?>
            <?php foreach ($attention as [$key, $icon, $tone, $count, $label, $href]): ?>
                <?php if ($count > 0): ?>
                    <a class="attention-row" href="<?= $href ?>">
                        <span class="kpi-icon <?= $tone ?>"><i class="bi bi-<?= $icon ?>"></i></span>
                        <span class="attention-count"><?= number_format($count) ?></span>
                        <span class="small"><?= $label ?></span>
                        <i class="bi bi-chevron-right ms-auto text-muted"></i>
                    </a>
                <?php else: ?>
                    <div class="attention-row is-muted">
                        <span class="kpi-icon is-neutral"><i class="bi bi-<?= $icon ?>"></i></span>
                        <span class="attention-count">0</span>
                        <span class="small"><?= $label ?></span>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card quota-watch-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-speedometer2"></i>Quota watch</span>
        <span class="badge-tag">80%+</span>
    </div>
    <div class="card-body pt-1 pb-2">
        <?php if (!$quotaWatch): ?>
            <div class="d-flex align-items-center gap-2 py-2">
                <span class="kpi-icon kpi-icon-sm is-ok"><i class="bi bi-check-lg"></i></span>
                <span class="text-muted">Every customer is comfortably within their plan limits.</span>
            </div>
        <?php else: ?>
            <?php foreach ($quotaWatch as $q): ?>
                <a class="quota-row" href="<?= APP_URL ?>/admin/tenant.php?id=<?= (int)$q['id'] ?>">
                    <span class="quota-tenant">
                        <span class="quota-name"><?= sanitize($q['tenant']) ?></span>
                        <span class="quota-plan"><?= sanitize($q['plan']) ?></span>
                    </span>
                    <span class="quota-resource"><?= sanitize($q['resource']) ?></span>
                    <span class="quota-usage num"><?= number_format($q['used']) ?><?= $q['limit'] > 0 ? ' / ' . number_format($q['limit']) : '' ?></span>
                    <span class="quota-bar"><span class="quota-fill<?= $q['pct'] >= 100 ? ' is-full' : '' ?>" style="width: <?= min(100, $q['pct']) ?>%"></span></span>
                    <span class="quota-pct num"><?= $q['pct'] ?>%</span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card insight-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-robot"></i>Automation health</span>
                <a href="<?= APP_URL ?>/admin/llm.php" class="btn btn-sm btn-link text-decoration-none">AI &amp; models</a>
            </div>
            <div class="card-body">
                <div class="insight-metrics">
                    <div class="insight-metric">
                        <span class="insight-value"><?= number_format((int)$automation['chatbot_enabled']) ?></span>
                        <span class="insight-label">Enabled chatbots</span>
                    </div>
                    <div class="insight-metric">
                        <span class="insight-value"><?= number_format((int)$automation['replies_24h']) ?></span>
                        <span class="insight-label">AI replies · 24h</span>
                    </div>
                    <div class="insight-metric">
                        <span class="insight-value"><?= number_format((int)$automation['handoffs_30d']) ?></span>
                        <span class="insight-label">Handovers · 30d</span>
                    </div>
                    <div class="insight-metric">
                        <span class="insight-value"><?= number_format((int)$automation['providers_healthy']) ?>/<?= number_format((int)$automation['providers_enabled']) ?></span>
                        <span class="insight-label">Providers healthy</span>
                    </div>
                </div>
                <?php $autoBad = (int)$automation['errors_24h'] > 0 || (int)$automation['chatbot_misconfigured'] > 0; ?>
                <div class="insight-status <?= $autoBad ? 'is-danger' : 'is-ok' ?>">
                    <?php if ($autoBad): ?>
                        <i class="bi bi-exclamation-triangle"></i>
                        <?= number_format((int)$automation['errors_24h']) ?> reply <?= (int)$automation['errors_24h'] === 1 ? 'error' : 'errors' ?> in the last 24h
                        <?php if ((int)$automation['chatbot_misconfigured'] > 0): ?>
                            · <?= number_format((int)$automation['chatbot_misconfigured']) ?> enabled <?= (int)$automation['chatbot_misconfigured'] === 1 ? 'chatbot has' : 'chatbots have' ?> no working model
                        <?php endif; ?>
                    <?php else: ?>
                        <i class="bi bi-check-circle"></i>No reply errors in the last 24h; every enabled chatbot has a working model.
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card insight-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-whatsapp"></i>WhatsApp reliability</span>
                <a href="<?= APP_URL ?>/admin/system.php" class="btn btn-sm btn-link text-decoration-none">System</a>
            </div>
            <div class="card-body">
                <div class="insight-metrics">
                    <div class="insight-metric">
                        <span class="insight-value"><?= $waPct ?>%</span>
                        <span class="insight-label">Connected (<?= number_format((int)$waHealth['connected']) ?> of <?= number_format((int)$waHealth['total']) ?>)</span>
                    </div>
                    <div class="insight-metric">
                        <span class="insight-value"><?= number_format((int)$waHealth['baileys']) ?> / <?= number_format((int)$waHealth['cloud']) ?></span>
                        <span class="insight-label">Baileys / Cloud API</span>
                    </div>
                    <div class="insight-metric">
                        <span class="insight-value"><?= number_format((int)$waHealth['reconnecting']) ?></span>
                        <span class="insight-label">Reconnecting</span>
                    </div>
                    <div class="insight-metric">
                        <span class="insight-value"><?= number_format((int)$waHealth['unhealthy']) ?></span>
                        <span class="insight-label">Accounts needing attention</span>
                    </div>
                </div>
                <?php $waBad = (int)$waHealth['unhealthy'] > 0; $waWarn = !$waBad && ((int)$waHealth['reconnecting'] > 0 || (int)$waHealth['cloud_errors'] > 0); ?>
                <div class="insight-status <?= $waBad ? 'is-danger' : ($waWarn ? 'is-warn' : 'is-ok') ?>">
                    <?php if ($waBad): ?>
                        <i class="bi bi-exclamation-triangle"></i><?= number_format((int)$waHealth['unhealthy']) ?> <?= (int)$waHealth['unhealthy'] === 1 ? 'account needs' : 'accounts need' ?> attention
                        <?= (int)$waHealth['cloud_errors'] > 0 ? '· ' . number_format((int)$waHealth['cloud_errors']) . ' Cloud API ' . ((int)$waHealth['cloud_errors'] === 1 ? 'error' : 'errors') : '' ?>
                    <?php elseif ($waWarn): ?>
                        <i class="bi bi-exclamation-circle"></i><?= (int)$waHealth['reconnecting'] > 0 ? number_format((int)$waHealth['reconnecting']) . ' reconnecting ' : '' ?>
                        <?= (int)$waHealth['cloud_errors'] > 0 ? number_format((int)$waHealth['cloud_errors']) . ' Cloud API ' . ((int)$waHealth['cloud_errors'] === 1 ? 'error' : 'errors') : '' ?>
                    <?php else: ?>
                        <i class="bi bi-check-circle"></i>All linked accounts are connected.
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card insight-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-cash-stack"></i>Billing outlook</span>
                <a href="<?= APP_URL ?>/admin/payments.php" class="btn btn-sm btn-link text-decoration-none">Payments</a>
            </div>
            <div class="card-body">
                <div class="insight-metrics">
                    <div class="insight-metric">
                        <span class="insight-value"><?= number_format(count($lapsingExpired)) ?></span>
                        <span class="insight-label">Periods expired, unpaid</span>
                    </div>
                    <div class="insight-metric">
                        <span class="insight-value"><?= number_format(count($lapsingUpcoming)) ?></span>
                        <span class="insight-label">Lapsing within 7 days</span>
                    </div>
                    <div class="insight-metric">
                        <span class="insight-value"><?= number_format((int)$billing['paid_without_subscription']) ?></span>
                        <span class="insight-label">Paid plan without an active billing period</span>
                    </div>
                    <div class="insight-metric">
                        <span class="insight-value"><?= number_format((int)$billing['new_paid_this_month']) ?></span>
                        <span class="insight-label">New customers currently on paid plans</span>
                    </div>
                </div>
                <?php if ($currencyCompare): ?>
                    <div class="insight-list">
                        <?php foreach ($currencyCompare as $cur => $c): ?>
                            <div class="insight-row">
                                <span class="small"><?= sanitize($cur) ?> this month</span>
                                <span class="num fw-500"><?= sanitize(formatMoney((int)($c['curr'] ?? 0), $cur)) ?></span>
                                <span class="x-small text-muted">prev <?= sanitize(formatMoney((int)($c['prev'] ?? 0), $cur)) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($recentPayments): ?>
                    <div class="insight-list">
                        <?php foreach (array_slice($recentPayments, 0, 3) as $pay): ?>
                            <div class="insight-row">
                                <span class="small"><?= sanitize($pay['tenant_name']) ?></span>
                                <span class="num fw-500"><?= sanitize(formatMoney((int)$pay['amount_minor'], $pay['currency'])) ?></span>
                                <span class="x-small text-muted"><?= sanitize(timeAgo($pay['created_at'])) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card insight-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-activity"></i>Customer engagement</span>
                <a href="<?= APP_URL ?>/admin/tenants.php" class="btn btn-sm btn-link text-decoration-none">Customers</a>
            </div>
            <div class="card-body">
                <?php
                $buckets = [
                    ['Active in last 24h', $engagement['24h']],
                    ['Active 1–7 days ago', $engagement['7d']],
                    ['Active 8–30 days ago', $engagement['30d']],
                    ['Inactive over 30 days', $engagement['older']],
                    ['No recorded activity', $engagement['never']],
                ];
                $bucketTotal = max(1, array_sum(array_column($buckets, 1)));
                ?>
                <div class="insight-list">
                    <?php foreach ($buckets as [$blabel, $bcount]): ?>
                        <div class="insight-row">
                            <span class="small"><?= $blabel ?></span>
                            <span class="engagement-bar"><span class="engagement-fill" style="width: <?= (int)round($bcount / $bucketTotal * 100) ?>%"></span></span>
                            <span class="num fw-500"><?= number_format($bcount) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="insight-status is-neutral">
                    <i class="bi bi-info-circle"></i><?= number_format($engagement['never_linked']) ?> <?= $engagement['never_linked'] === 1 ? 'customer has' : 'customers have' ?> never linked an account · <?= number_format($engagement['linked_never_sent']) ?> <?= $engagement['linked_never_sent'] === 1 ? 'customer has' : 'customers have' ?> linked an account but never sent a message
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card table-card h-100">
            <div class="card-header"><i class="bi bi-bar-chart"></i>Top customers by messages this month</div>
            <div class="table-responsive">
                <?php // #33 §4. The message column is read down, not across, so it
                      // aligns right and in tabular figures — .num does both. ?>
                <table class="table align-middle mb-0">
                    <thead><tr><th>Customer</th><th>Plan</th><th class="num">Messages</th></tr></thead>
                    <tbody>
                    <?php if (!$topTenants): ?>
                        <tr><td colspan="3" class="text-muted small">No messages sent this month.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($topTenants as $t): ?>
                        <tr>
                            <td class="small">
                                <a href="<?= APP_URL ?>/admin/tenant.php?id=<?= (int)$t['id'] ?>" class="text-decoration-none">
                                    <?= sanitize($t['name']) ?>
                                </a>
                            </td>
                            <td class="small text-muted"><?= sanitize($t['plan_name'] ?? '—') ?></td>
                            <td class="small fw-500 num"><?= number_format((int)$t['messages']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card table-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-box-seam"></i>Customers per plan</span>
                <a href="<?= APP_URL ?>/admin/plans.php" class="btn btn-sm btn-link text-decoration-none">Manage</a>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Plan</th><th class="num">Price</th><th class="num">Customers</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($planBreakdown as $p): ?>
                        <tr class="<?= $p['is_active'] ? '' : 'opacity-50' ?>">
                            <td class="small fw-500"><?= sanitize($p['name']) ?></td>
                            <td class="small num"><?= sanitize(formatPrice($p)) ?></td>
                            <td class="small num"><?= number_format((int)$p['tenants']) ?></td>
                            <?php // A tag, not a state: it labels the plan row. ?>
                            <td><?php if (!$p['is_active']): ?><span class="badge-tag">Inactive</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-6">
        <div class="card insight-card h-100">
            <div class="card-header"><i class="bi bi-arrow-left-right"></i>Recent plan assignments</div>
            <div class="card-body pt-1 pb-2">
                <?php if (!$planAssignments): ?>
                    <div class="d-flex align-items-center gap-2 py-2">
                        <span class="kpi-icon kpi-icon-sm is-neutral"><i class="bi bi-dash-lg"></i></span>
                        <span class="text-muted">No plan assignments recorded yet.</span>
                    </div>
                <?php else: ?>
                    <div class="insight-list">
                        <?php foreach ($planAssignments as $pa): ?>
                            <div class="insight-row">
                                <span class="activity-icon"><i class="bi bi-box-seam"></i></span>
                                <span class="small">
                                    <?php if ($pa['tenant_name'] !== null && ctype_digit((string)$pa['entity_id'])): ?>
                                        <a href="<?= APP_URL ?>/admin/tenant.php?id=<?= (int)$pa['entity_id'] ?>" class="text-decoration-none"><?= sanitize($pa['tenant_name']) ?></a>
                                    <?php else: ?>
                                        <?= sanitize($pa['tenant_name'] ?? 'Former customer') ?>
                                    <?php endif; ?>
                                    → <?= sanitize($pa['plan_name'] ?? 'a plan') ?>
                                </span>
                                <span class="x-small text-muted ms-auto"><?= sanitize(timeAgo($pa['created_at'])) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card insight-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-journal-text"></i>Important activity</span>
                <a href="<?= APP_URL ?>/admin/system.php?days=30#tab-audit" class="btn btn-sm btn-link text-decoration-none">Audit log</a>
            </div>
            <div class="card-body pt-1 pb-2">
                <div class="insight-row mb-1">
                    <span class="small">Uncleared failed sign-ins · 24h</span>
                    <span class="badge-tag ms-auto<?= $failedSignins > 0 ? ' is-warn' : '' ?>"><?= number_format($failedSignins) ?></span>
                </div>
                <?php if (!$activity): ?>
                    <div class="d-flex align-items-center gap-2 py-2">
                        <span class="text-muted">No admin activity in the last 30 days.</span>
                    </div>
                <?php else: ?>
                    <div class="insight-list">
                        <?php foreach ($activity as $a): ?>
                            <div class="insight-row">
                                <span class="activity-icon"><i class="bi bi-dot"></i></span>
                                <span class="small"><?= sanitize(auditActionLabel($a['action'])) ?>
                                    <span class="text-muted">· <?= sanitize($a['actor_name'] ?? 'System') ?></span>
                                </span>
                                <span class="x-small text-muted ms-auto"><?= sanitize(timeAgo($a['created_at'])) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<section class="card webzeto-partner mt-4" aria-labelledby="webzetoPartnerTitle">
    <div class="webzeto-partner-main">
        <div class="webzeto-partner-copy">
            <span class="webzeto-mark" aria-hidden="true">W</span>
            <div>
                <div class="webzeto-eyebrow"><i class="bi bi-code-slash"></i>Development partner</div>
                <h2 id="webzetoPartnerTitle">Built by <a href="https://webzeto.com/" target="_blank" rel="noopener noreferrer">Webzeto</a></h2>
                <p>This WhatsApp bot was designed and developed by Webzeto. Need a custom workflow, new integrations, a business website, or another automation or bot application? Webzeto’s team can turn your idea into a tailored solution.</p>
            </div>
        </div>
        <div class="webzeto-actions" aria-label="Contact Webzeto">
            <a class="webzeto-action webzeto-action-primary" href="https://webzeto.com/contact/" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right"></i>Visit website</a>
            <a class="webzeto-action" href="https://wa.me/923369329386?text=Hi%20Webzeto%2C%20I%27m%20contacting%20you%20from%20the%20WABA%20AI%20Bot%20admin%20dashboard%20about%20a%20project." target="_blank" rel="noopener noreferrer"><i class="bi bi-whatsapp"></i>WhatsApp</a>
            <a class="webzeto-action" href="mailto:sales@webzeto.com?subject=Project%20inquiry%20from%20WABA%20AI%20Bot"><i class="bi bi-envelope"></i>Email</a>
        </div>
    </div>
    <div class="webzeto-contact-strip">
        <a href="tel:+924232311047"><i class="bi bi-telephone"></i><span>+92 42 3231 1047</span></a>
        <a href="https://www.google.com/maps?q=135+Rachna+Block,+Allama+Iqbal+Town,+Lahore" target="_blank" rel="noopener noreferrer"><i class="bi bi-geo-alt"></i><span>135 Rachna Block, Allama Iqbal Town, Lahore</span></a>
        <span><i class="bi bi-clock"></i><span>Mon–Fri, 10:00 AM–6:30 PM</span></span>
    </div>
</section>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
