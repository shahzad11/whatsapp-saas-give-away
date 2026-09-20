<?php
// Tenant management. Moved out of admin/index.php so the console landing can be
// an overview rather than a single table; see includes/admin-init.php for the
// guard.
require_once dirname(__DIR__) . '/includes/admin-init.php';

$self = APP_URL . '/admin/tenants.php';

// Carried across a plain (non-AJAX) create so the temporary password can be
// shown once on the page that follows the redirect. Deliberately the session
// and not a query string: a password in a URL lands in the access log, the
// browser history and any Referer sent to a CDN.
$justCreated = $_SESSION['admin_tenant_created'] ?? null;
unset($_SESSION['admin_tenant_created']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    formRequireCsrf($self);

    $action = $_POST['action'] ?? '';
    $targetId = (int)($_POST['user_id'] ?? 0);

    // An admin locking themselves out, or demoting the last admin, leaves the
    // instance unadministrable. Block both.
    if ($targetId === $adminId && in_array($action, ['suspend', 'toggle_admin'], true)) {
        formRespond(false, 'You cannot change your own access.', $self);
    }

    // The self-check above already makes zero admins unreachable, because the
    // only admin on an instance cannot act on themselves. This states the
    // invariant rather than leaving it emergent (#48): it is the check that
    // still holds if the self-check is ever relaxed, and it catches the case
    // the self-check cannot see — suspending or demoting the *other* admin
    // when your own account is the one that is about to be suspended.
    if (in_array($action, ['suspend', 'toggle_admin'], true) && wouldOrphanInstance($conn, $targetId)) {
        formRespond(false, 'That is the last account that can administer this instance. '
            . 'Give another tenant admin rights first.', $self);
    }

    // Creating a tenant (#48, #49). The rules live in createTenant() so this
    // page is only responsible for the form and the reply.
    if ($action === 'create') {
        $result = createTenant($conn, [
            'name'         => $_POST['name'] ?? '',
            'email'        => $_POST['email'] ?? '',
            'company_name' => $_POST['company_name'] ?? '',
            'plan_id'      => $_POST['plan_id'] ?? 0,
            'status'       => $_POST['status'] ?? 'active',
            'onboarding'   => $_POST['onboarding'] ?? 'invite',
        ], $adminId);

        if (!$result['ok']) {
            formRespond(false, $result['message'], $self, $result['errors']);
        }

        // A temporary password or an undelivered invite link is shown exactly
        // once, and only to the admin who just created the account. Neither is
        // recoverable afterwards — the password is only stored as a hash, and
        // re-issuing the link means creating a new one.
        if ($result['temp_password'] !== null || $result['invite_link'] !== null) {
            $_SESSION['admin_tenant_created'] = [
                'email'         => trim((string)($_POST['email'] ?? '')),
                'temp_password' => $result['temp_password'],
                'invite_link'   => $result['invite_link'],
            ];
        }

        // The JSON path is given a redirect rather than a reload: the one-time
        // secret is rendered by the *next* page load, and a toast that vanishes
        // after four seconds is not where a password goes.
        //
        // forms.js follows res.redirect without showing the message, so the
        // flash is set by hand for that path only — formRespond() sets its own
        // on the plain path, and doing both would say it twice.
        if (isXhrRequest()) flash('success', $result['message']);
        formRespond(true, $result['message'], $self, [], ['redirect' => $self]);
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

// There are deliberately no server-rendered field errors on the create form,
// unlike admin/plans.php.
//
// Every action on this page answers through formRespond(), which redirects the
// plain path — so a rejected create never comes back to a re-rendered form and
// per-field markup would be code that cannot run. The AJAX path, which is the
// one an admin actually uses, gets the same $errors array and forms.js paints
// it onto the inputs; the plain path gets a flash that names each problem in
// full, which is why createTenant() builds its message from them.

// Whether a password-setup invitation can actually be delivered. Read once and
// passed to the partial rather than called from inside it, so the form and the
// server-side check in createTenant() cannot disagree about it.
$canEmail = smtpConfigured($conn);

$pageTitle = 'Tenants';
require_once dirname(__DIR__) . '/includes/admin-header.php';
?>

<?php // The one-time secret, if the create that just happened produced one.
      //
      // Rendered at the top of the page and nowhere else. It is gone from the
      // session by the time this runs, so a refresh does not show it again —
      // which is the honest behaviour, because the password only exists as a
      // hash and the link is single-use. ?>
<?php if ($justCreated): ?>
    <div class="card border-warning mb-4">
        <div class="card-header bg-warning-subtle d-flex align-items-center gap-2">
            <i class="bi bi-key"></i>
            <span>Shown once — copy it now</span>
        </div>
        <div class="card-body">
            <p class="small mb-3">
                For <strong><?= sanitize($justCreated['email']) ?></strong>. This will not be
                shown again: reloading this page loses it, and it cannot be recovered afterwards.
            </p>
            <?php if (!empty($justCreated['temp_password'])): ?>
                <label class="form-label small text-muted">Temporary password</label>
                <input type="text" class="form-control font-monospace mb-2" readonly
                       onclick="this.select()" value="<?= sanitize($justCreated['temp_password']) ?>">
                <p class="x-small text-muted mb-0">
                    They must replace it the first time they sign in — nothing else on the
                    account is reachable until they do.
                </p>
            <?php endif; ?>
            <?php if (!empty($justCreated['invite_link'])): ?>
                <label class="form-label small text-muted">Password-setup link</label>
                <input type="text" class="form-control font-monospace mb-2" readonly
                       onclick="this.select()" value="<?= sanitize($justCreated['invite_link']) ?>">
                <p class="x-small text-muted mb-0">
                    The email could not be sent, so send this to them yourself. It can be used
                    once and expires in 7 days.
                </p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

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
                <select name="status" class="form-select form-select-sm" onchange="this.form.requestSubmit()">
                    <option value="">Any</option>
                    <?php foreach (['active' => 'Active', 'suspended' => 'Suspended', 'unactivated' => 'Unactivated'] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= $fStatus === $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label x-small text-muted mb-1">Plan</label>
                <select name="plan" class="form-select form-select-sm" onchange="this.form.requestSubmit()">
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
                <select name="sort" class="form-select form-select-sm" onchange="this.form.requestSubmit()">
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
        <div class="d-flex align-items-center gap-3">
            <span class="text-muted small">
                <?= number_format(count($users)) ?> <?= $filtered ? 'matching' : 'total' ?>
            </span>
            <?php // A real link to the form's own anchor, so it works with
                  // JavaScript off; data-modal-target upgrades it to the modal
                  // forms.js promoted the card into. ?>
            <a href="#tenantShell" class="btn btn-sm btn-primary"
               data-modal-target="#tenantModal" data-modal-reset="on"
               data-modal-title="New tenant">
                <i class="bi bi-plus-lg me-1"></i>New tenant
            </a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table align-middle table-stack">
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
                    <td data-label="Tenant">
                        <div class="fw-500">
                            <?php // href is the full detail page, which is what a click does
                                  // with JavaScript off. With it, the same URL is fetched as a
                                  // fragment into the modal below — one server-rendered
                                  // source, two ways of showing it (#49). ?>
                            <a href="<?= APP_URL ?>/admin/tenant.php?id=<?= (int)$u['id'] ?>"
                               class="text-decoration-none"
                               data-modal-target="#tenantViewModal"
                               data-modal-url="<?= APP_URL ?>/admin/tenant.php?id=<?= (int)$u['id'] ?>"
                               data-modal-title="<?= sanitize($u['company_name'] ?: $u['name']) ?>">
                                <?= sanitize($u['company_name'] ?: $u['name']) ?>
                            </a>
                            <?php if ($u['is_admin']): ?><span class="badge bg-dark ms-1">admin</span><?php endif; ?>
                        </div>
                        <div class="text-muted small"><?= sanitize($u['email']) ?></div>
                        <div class="text-muted x-small">tenant id: t<?= (int)$u['id'] ?></div>
                    </td>
                    <td data-label="Plan">
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
                    <td data-label="WA"><?= (int)$u['wa_count'] ?></td>
                    <td data-label="Status">
                        <?php if (!$u['is_active']): ?>
                            <span class="badge bg-secondary">unactivated</span>
                        <?php elseif ($u['status'] === 'suspended'): ?>
                            <span class="badge bg-danger">suspended</span>
                        <?php else: ?>
                            <span class="badge bg-success">active</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small" data-label="Last Login">
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

<?php // The read-only tenant detail, loaded on demand (#49).
      //
      // Written out here rather than promoted from a card by forms.js, because
      // there is no card to promote: the body is fetched from admin/tenant.php
      // and there is nothing to render until a name is clicked. It is a separate
      // modal from the create one on purpose — sharing would mean a view
      // replacing the create form's markup with a tenant's details. ?>
<div class="modal fade" id="tenantViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" data-role="modal-title"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" data-role="modal-body"></div>
        </div>
    </div>
</div>

<?php // Rendered once as a plain card and promoted to a modal by forms.js (#24).
      //
      // Unlike the plan editor this form is not fetched: it starts empty every
      // time, so there is nothing for the server to populate and a round trip to
      // ask for a blank form would be a request for nothing. data-modal-reset
      // on the trigger clears it between opens. ?>
<div class="card mt-4" id="tenantShell" data-modal-shell="tenantModal" data-modal-title="New tenant">
    <div class="card-header">New Tenant</div>
    <div class="card-body">
        <?php require __DIR__ . '/partials/tenant-form.php'; ?>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
