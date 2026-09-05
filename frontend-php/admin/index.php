<?php
// Admin console landing — real metrics from MySQL.
//
// Operational metadata only. Message contents are never exposed to an admin,
// and that boundary must survive every future addition to this page.
require_once dirname(__DIR__) . '/includes/admin-init.php';

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

$pageTitle = 'Overview';
require_once dirname(__DIR__) . '/includes/admin-header.php';
?>

<?php foreach (['success' => 'success', 'error' => 'danger'] as $key => $cls): ?>
    <?php if ($msg = flash($key)): ?>
        <div class="alert alert-<?= $cls ?> alert-dismissible fade show">
            <?= sanitize($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php if ($lapsing): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center">
    <div>
        <i class="bi bi-exclamation-triangle me-2"></i>
        <strong><?= count($lapsing) ?></strong> paid period(s) lapsing within 7 days with no payment logged.
    </div>
    <a href="<?= APP_URL ?>/admin/payments.php" class="btn btn-sm btn-warning">Review</a>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Tenants',        $stats['total_users'],          'people',       'primary', $stats['new_this_month'] . ' new this month'],
        ['Active',         $stats['active_users'],          'person-check', 'success', (int)$stats['unactivated_users'] . ' unactivated'],
        ['Suspended',      $stats['suspended_users'],       'person-slash', 'danger',  ''],
        ['Paid tenants',   $stats['paid_tenants'],          'star',         'info',    ''],
        ['WA connected',   $stats['connected_accounts'],    'plug',         'success', (int)$stats['total_accounts'] . ' linked total'],
        ['WA broken',      $stats['broken_accounts'],       'plug-fill',    'warning', 'need a QR rescan'],
        ['Messages / mo',  $stats['messages_this_month'],   'chat-dots',    'primary', ''],
    ];
    foreach ($cards as [$label, $value, $icon, $colour, $sub]): ?>
    <div class="col-6 col-lg-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <i class="bi bi-<?= $icon ?> fs-3 text-<?= $colour ?>"></i>
                <div>
                    <div class="h4 mb-0"><?= number_format((int)$value) ?></div>
                    <div class="text-muted small"><?= $label ?></div>
                    <?php if ($sub): ?><div class="text-muted x-small"><?= sanitize($sub) ?></div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="col-6 col-lg-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <i class="bi bi-cash-coin fs-3 text-success"></i>
                <div>
                    <?php if (!$totals): ?>
                        <div class="h4 mb-0">—</div>
                    <?php else: ?>
                        <?php // One line per currency: a cross-currency total would be
                              // meaningless without a rate, and would be believed. ?>
                        <?php foreach ($totals as $t): ?>
                            <div class="h5 mb-0"><?= sanitize(formatMoney((int)$t['total'], $t['currency'])) ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <div class="text-muted small">Received this month</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card table-card h-100">
            <div class="card-header">Top Tenants by Messages (this month)</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Tenant</th><th>Plan</th><th>Messages</th></tr></thead>
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
                            <td class="small fw-500"><?= number_format((int)$t['messages']) ?></td>
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
                <span>Tenants per Plan</span>
                <a href="<?= APP_URL ?>/admin/plans.php" class="btn btn-sm btn-link text-decoration-none">Manage</a>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Plan</th><th>Price</th><th>Tenants</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($planBreakdown as $p): ?>
                        <tr class="<?= $p['is_active'] ? '' : 'opacity-50' ?>">
                            <td class="small fw-500"><?= sanitize($p['name']) ?></td>
                            <td class="small"><?= sanitize(formatPrice($p)) ?></td>
                            <td class="small"><?= number_format((int)$p['tenants']) ?></td>
                            <td><?php if (!$p['is_active']): ?><span class="badge bg-secondary x-small">inactive</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
