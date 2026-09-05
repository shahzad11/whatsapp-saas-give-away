<?php
// Tenant management. Moved out of admin/index.php so the console landing can be
// an overview rather than a single table; see includes/admin-init.php for the
// guard.
require_once dirname(__DIR__) . '/includes/admin-init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('error', 'Invalid request.');
        redirect(APP_URL . '/admin/tenants.php');
    }

    $action = $_POST['action'] ?? '';
    $targetId = (int)($_POST['user_id'] ?? 0);

    // An admin locking themselves out, or demoting the last admin, leaves the
    // instance unadministrable. Block both.
    if ($targetId === $adminId && in_array($action, ['suspend', 'toggle_admin'], true)) {
        flash('error', 'You cannot change your own access.');
        redirect(APP_URL . '/admin/tenants.php');
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

    redirect(APP_URL . '/admin/tenants.php');
}

$plans = getActivePlans($conn);

// --- Search / filter ---
$q          = trim($_GET['q'] ?? '');
$fStatus    = $_GET['status'] ?? '';
$fPlan      = (int)($_GET['plan'] ?? 0);
$fSince     = trim($_GET['since'] ?? '');
$sort       = $_GET['sort'] ?? 'created_desc';

// LEFT JOIN on user_profiles, not INNER: the profile is optional, and a tenant
// who never filled one in must still appear in this list.
$sql = "SELECT u.id, u.name, u.email, u.is_active, u.is_admin, u.status, u.created_at, u.last_login_at,
               p.name AS plan_name, p.id AS plan_id,
               up.company_name,
               (SELECT COUNT(*) FROM wa_accounts wa WHERE wa.user_id = u.id) AS wa_count
        FROM users u
        LEFT JOIN plans p ON u.plan_id = p.id
        LEFT JOIN user_profiles up ON up.user_id = u.id";

$where = [];
$types = '';
$args = [];

if ($q !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR up.company_name LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'sss';
    array_push($args, $like, $like, $like);
}
// `unactivated` is not a `status` value — it is is_active = 0 — so it cannot be
// folded into the same comparison.
if ($fStatus === 'active' || $fStatus === 'suspended') {
    $where[] = 'u.status = ? AND u.is_active = 1';
    $types .= 's';
    $args[] = $fStatus;
} elseif ($fStatus === 'unactivated') {
    $where[] = 'u.is_active = 0';
}
if ($fPlan > 0) {
    $where[] = 'u.plan_id = ?';
    $types .= 'i';
    $args[] = $fPlan;
}
if ($fSince !== '' && DateTime::createFromFormat('Y-m-d', $fSince)) {
    $where[] = 'u.created_at >= ?';
    $types .= 's';
    $args[] = $fSince . ' 00:00:00';
}
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);

// Whitelisted, never interpolated from the raw parameter.
$sorts = [
    'created_desc' => 'u.created_at DESC',
    'created_asc'  => 'u.created_at ASC',
    'name'         => 'u.name ASC',
    'login_desc'   => 'u.last_login_at IS NULL, u.last_login_at DESC',
    'wa_desc'      => 'wa_count DESC',
];
$sql .= ' ORDER BY ' . ($sorts[$sort] ?? $sorts['created_desc']);

$stmt = $conn->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$args);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$filtered = $q !== '' || $fStatus !== '' || $fPlan > 0 || $fSince !== '';

$pageTitle = 'Tenants';
require_once dirname(__DIR__) . '/includes/admin-header.php';
?>

<?php foreach (['success' => 'success', 'error' => 'danger'] as $key => $cls): ?>
    <?php if ($msg = flash($key)): ?>
        <div class="alert alert-<?= $cls ?> alert-dismissible fade show">
            <?= sanitize($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label x-small text-muted mb-1">Search</label>
                <input type="search" name="q" class="form-control form-control-sm"
                       placeholder="Name, email or company" value="<?= sanitize($q) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label x-small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Any</option>
                    <?php foreach (['active' => 'Active', 'suspended' => 'Suspended', 'unactivated' => 'Unactivated'] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= $fStatus === $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label x-small text-muted mb-1">Plan</label>
                <select name="plan" class="form-select form-select-sm">
                    <option value="0">Any</option>
                    <?php foreach ($plans as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $fPlan === (int)$p['id'] ? 'selected' : '' ?>><?= sanitize($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label x-small text-muted mb-1">Signed up after</label>
                <input type="date" name="since" class="form-control form-control-sm" value="<?= sanitize($fSince) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label x-small text-muted mb-1">Sort</label>
                <select name="sort" class="form-select form-select-sm">
                    <?php foreach ([
                        'created_desc' => 'Newest first', 'created_asc' => 'Oldest first',
                        'name' => 'Name', 'login_desc' => 'Recent login', 'wa_desc' => 'Most accounts',
                    ] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= $sort === $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex gap-2 mt-2">
                <button class="btn btn-sm btn-primary">Apply</button>
                <?php if ($filtered || $sort !== 'created_desc'): ?>
                    <a href="<?= APP_URL ?>/admin/tenants.php" class="btn btn-sm btn-link">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card table-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Tenants</span>
        <span class="text-muted small">
            <?= number_format(count($users)) ?> <?= $filtered ? 'matching' : 'total' ?>
        </span>
    </div>
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
            <?php if (!$users): ?>
                <tr><td colspan="6" class="text-muted small">No tenants match those filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td>
                        <div class="fw-500">
                            <a href="<?= APP_URL ?>/admin/tenant.php?id=<?= (int)$u['id'] ?>" class="text-decoration-none">
                                <?= sanitize($u['company_name'] ?: $u['name']) ?>
                            </a>
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
                            <select name="plan_id" class="form-select form-select-sm" style="min-width:150px">
                                <?php foreach ($plans as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>" <?= $p['id'] == $u['plan_id'] ? 'selected' : '' ?>>
                                        <?= sanitize($p['name']) ?> — <?= sanitize(formatPrice($p)) ?>
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

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
