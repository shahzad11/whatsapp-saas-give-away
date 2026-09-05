<?php
// Admin console landing. Operational metadata only — message contents are never
// exposed to an admin, and that boundary must survive every future addition
// here. The full metrics set is issue #6; this is the shell it lands in.
require_once dirname(__DIR__) . '/includes/admin-init.php';

$stats = $conn->query(
    "SELECT
        (SELECT COUNT(*) FROM users) AS total_users,
        (SELECT COUNT(*) FROM users WHERE status = 'active' AND is_active = 1) AS active_users,
        (SELECT COUNT(*) FROM users WHERE status = 'suspended') AS suspended_users,
        (SELECT COUNT(*) FROM wa_accounts) AS total_accounts,
        (SELECT COUNT(*) FROM wa_accounts WHERE status = 'connected') AS connected_accounts"
)->fetch_assoc();

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

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Total Tenants', $stats['total_users'], 'people', 'primary'],
        ['Active Tenants', $stats['active_users'], 'person-check', 'success'],
        ['Suspended', $stats['suspended_users'], 'person-slash', 'danger'],
        ['WA Accounts', $stats['total_accounts'], 'phone', 'info'],
        ['Connected', $stats['connected_accounts'], 'plug', 'warning'],
    ];
    foreach ($cards as [$label, $value, $icon, $colour]): ?>
    <div class="col-6 col-lg">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <i class="bi bi-<?= $icon ?> fs-3 text-<?= $colour ?>"></i>
                <div>
                    <div class="h4 mb-0"><?= number_format((int)$value) ?></div>
                    <div class="text-muted small"><?= $label ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header">Management</div>
    <div class="list-group list-group-flush">
        <a href="<?= APP_URL ?>/admin/tenants.php" class="list-group-item list-group-item-action d-flex align-items-center gap-3">
            <i class="bi bi-people fs-4 text-primary"></i>
            <div>
                <div class="fw-500">Tenants</div>
                <div class="text-muted small">Plans, suspension, admin rights</div>
            </div>
        </a>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
