<?php
require_once dirname(__DIR__) . '/config/init.php';
requireAdmin();

$adminId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('error', 'Invalid request.');
        redirect(APP_URL . '/admin/index.php');
    }

    $action = $_POST['action'] ?? '';
    $targetId = (int)($_POST['user_id'] ?? 0);

    // An admin locking themselves out, or demoting the last admin, leaves the
    // instance unadministrable. Block both.
    if ($targetId === $adminId && in_array($action, ['suspend', 'toggle_admin'], true)) {
        flash('error', 'You cannot change your own access.');
        redirect(APP_URL . '/admin/index.php');
    }

    if ($action === 'suspend' || $action === 'activate') {
        $status = $action === 'suspend' ? 'suspended' : 'active';
        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $status, $targetId);
        $stmt->execute();
        $stmt->close();
        logAudit($conn, 'admin.user.' . $action, 'user', $targetId);
        flash('success', 'User ' . $action . 'd.');
    }

    if ($action === 'change_plan') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE users SET plan_id = ? WHERE id = ?");
        $stmt->bind_param('ii', $planId, $targetId);
        $stmt->execute();
        $stmt->close();
        logAudit($conn, 'admin.user.change_plan', 'user', $targetId, ['plan_id' => $planId]);
        flash('success', 'Plan updated.');
    }

    if ($action === 'toggle_admin') {
        $stmt = $conn->prepare("UPDATE users SET is_admin = 1 - is_admin WHERE id = ?");
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $stmt->close();
        logAudit($conn, 'admin.user.toggle_admin', 'user', $targetId);
        flash('success', 'Admin flag toggled.');
    }

    redirect(APP_URL . '/admin/index.php');
}

$plans = getActivePlans($conn);

$users = $conn->query(
    "SELECT u.id, u.name, u.email, u.is_active, u.is_admin, u.status, u.created_at, u.last_login_at,
            p.name AS plan_name, p.id AS plan_id,
            (SELECT COUNT(*) FROM wa_accounts wa WHERE wa.user_id = u.id) AS wa_count
     FROM users u
     LEFT JOIN plans p ON u.plan_id = p.id
     ORDER BY u.created_at DESC"
)->fetch_all(MYSQLI_ASSOC);

$stats = $conn->query(
    "SELECT
        (SELECT COUNT(*) FROM users) AS total_users,
        (SELECT COUNT(*) FROM users WHERE status = 'active' AND is_active = 1) AS active_users,
        (SELECT COUNT(*) FROM wa_accounts) AS total_accounts,
        (SELECT COUNT(*) FROM wa_accounts WHERE status = 'connected') AS connected_accounts"
)->fetch_assoc();

$pageTitle = 'Admin — Tenants';
require_once dirname(__DIR__) . '/includes/header.php';
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
        ['WA Accounts', $stats['total_accounts'], 'phone', 'info'],
        ['Connected', $stats['connected_accounts'], 'plug', 'warning'],
    ];
    foreach ($cards as [$label, $value, $icon, $colour]): ?>
    <div class="col-6 col-lg-3">
        <div class="card">
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

<div class="card table-card">
    <div class="card-header">All Tenants</div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Tenant</th>
                    <th>Plan</th>
                    <th>WA</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td>
                        <div class="fw-500">
                            <?= sanitize($u['name']) ?>
                            <?php if ($u['is_admin']): ?><span class="badge bg-dark ms-1">admin</span><?php endif; ?>
                        </div>
                        <div class="text-muted small"><?= sanitize($u['email']) ?></div>
                        <div class="text-muted x-small">tenant id: t<?= (int)$u['id'] ?></div>
                    </td>
                    <td>
                        <form method="POST" class="d-flex gap-1">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="change_plan">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <select name="plan_id" class="form-select form-select-sm" style="min-width:110px">
                                <?php foreach ($plans as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>" <?= $p['id'] == $u['plan_id'] ? 'selected' : '' ?>>
                                        <?= sanitize($p['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-outline-primary">Set</button>
                        </form>
                    </td>
                    <td><?= (int)$u['wa_count'] ?></td>
                    <td>
                        <?php if (!$u['is_active']): ?>
                            <span class="badge bg-secondary">unactivated</span>
                        <?php elseif ($u['status'] === 'suspended'): ?>
                            <span class="badge bg-danger">suspended</span>
                        <?php else: ?>
                            <span class="badge bg-success">active</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small">
                        <?= $u['last_login_at'] ? sanitize(timeAgo($u['last_login_at'])) : '—' ?>
                    </td>
                    <td>
                        <?php if ((int)$u['id'] !== $adminId): ?>
                        <div class="d-flex gap-1">
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <input type="hidden" name="action" value="<?= $u['status'] === 'suspended' ? 'activate' : 'suspend' ?>">
                                <button class="btn btn-sm btn-outline-<?= $u['status'] === 'suspended' ? 'success' : 'danger' ?>">
                                    <?= $u['status'] === 'suspended' ? 'Reactivate' : 'Suspend' ?>
                                </button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Toggle admin rights for this tenant?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <input type="hidden" name="action" value="toggle_admin">
                                <button class="btn btn-sm btn-outline-dark">Admin</button>
                            </form>
                        </div>
                        <?php else: ?>
                            <span class="text-muted small">you</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
