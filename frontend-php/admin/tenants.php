<?php
// Tenant management. Moved out of admin/index.php so the console landing can be
// an overview rather than a single table; see includes/admin-init.php for the
// guard.
require_once dirname(__DIR__) . '/includes/admin-init.php';

$self = APP_URL . '/admin/tenants.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    formRequireCsrf($self);

    $action = $_POST['action'] ?? '';
    $targetId = (int)($_POST['user_id'] ?? 0);

    // An admin locking themselves out, or demoting the last admin, leaves the
    // instance unadministrable. Block both.
    if ($targetId === $adminId && in_array($action, ['suspend', 'toggle_admin'], true)) {
        formRespond(false, 'You cannot change your own access.', $self);
    }

    if ($action === 'suspend' || $action === 'activate') {
        $status = $action === 'suspend' ? 'suspended' : 'active';
        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $status, $targetId);
        $stmt->execute();
        $stmt->close();
        logAudit($conn, 'admin.user.' . $action, 'user', $targetId);
        formRespond(true, 'User ' . $action . 'd.', $self);
    }

    if ($action === 'change_plan') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        // assignPlan() rather than a bare UPDATE on users.plan_id. The bare
        // update changed the tenant's limits and features immediately but left
        // the subscriptions row pointing at the old plan, so Billing kept showing
        // the previous plan's renewal date and the lapsing report kept naming the
        // old plan. The tenant saw one plan's limits with another plan's billing.
        if ($planId > 0 && getPlanById($conn, $planId)) {
            assignPlan($conn, $targetId, $planId);
            logAudit($conn, 'admin.user.change_plan', 'user', $targetId, ['plan_id' => $planId]);
            formRespond(true, 'Plan updated.', $self);
        }
        // The key is the <select>'s own name, so the AJAX path marks the very
        // control that was wrong — a plan can disappear between the page being
        // rendered and the Set button being pressed.
        formRespond(false, 'That plan does not exist.', $self, ['plan_id' => 'Pick a plan that still exists.']);
    }

    if ($action === 'toggle_admin') {
        $stmt = $conn->prepare("UPDATE users SET is_admin = 1 - is_admin WHERE id = ?");
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $stmt->close();
        logAudit($conn, 'admin.user.toggle_admin', 'user', $targetId);
        formRespond(true, 'Admin flag toggled.', $self);
    }

    // Every branch above exits, so this is only reached by a POST naming an
    // action that does not exist. It has to answer through formRespond() rather
    // than redirect(): a bare 302 to an HTML page would come back to fetch() as
    // something it cannot parse, and the submit would look like a network error.
    formRespond(false, 'Unknown action.', $self);
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
                        <form method="POST" class="d-flex gap-1" data-ajax>
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
                            <form method="POST" data-ajax>
                                <?= csrfField() ?>
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <input type="hidden" name="action" value="<?= $u['status'] === 'suspended' ? 'activate' : 'suspend' ?>">
                                <?php // Suspending locks the tenant out on their next request, so it
                                      // is confirmed. Reactivating is additive and needs no prompt.
                                      //
                                      // The message now goes in a data attribute rather than into an
                                      // onsubmit="confirm(...)", so it is plain text in HTML and
                                      // sanitize() is exactly the right escaping. The old code had to
                                      // json_encode() first because the value was JavaScript source,
                                      // where sanitize()'s &#039; comes back as a quote and breaks
                                      // the call. Removing the inline script removes that trap. ?>
                                <button class="btn btn-sm btn-outline-<?= $u['status'] === 'suspended' ? 'success' : 'danger' ?>"
                                    <?php if ($u['status'] !== 'suspended'): ?>
                                        data-confirm="Suspend <?= sanitize($u['email']) ?>? They will be signed out and unable to log in until reactivated."
                                    <?php endif; ?>>
                                    <?= $u['status'] === 'suspended' ? 'Reactivate' : 'Suspend' ?>
                                </button>
                            </form>
                            <form method="POST" data-ajax>
                                <?= csrfField() ?>
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <input type="hidden" name="action" value="toggle_admin">
                                <?php // Both directions are confirmed here, unlike the Suspend button
                                      // above: removing admin rights can leave a colleague locked out
                                      // of the console, and granting them hands one tenant account
                                      // full sight of every other tenant on the instance. Neither is
                                      // the harmless additive case that a dialog would devalue. ?>
                                <button class="btn btn-sm btn-outline-dark"
                                    data-confirm="<?= $u['is_admin']
                                        ? 'Remove admin rights from ' . sanitize($u['email']) . '? They keep their own tenant account and lose the admin console.'
                                        : 'Give ' . sanitize($u['email']) . ' admin rights? They will be able to see and change every tenant on this instance.' ?>">Admin</button>
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
