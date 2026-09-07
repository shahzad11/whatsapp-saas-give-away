<?php
require_once __DIR__ . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];

$totalAccounts = 0;
$connectedAccounts = 0;
$disconnectedAccounts = 0;

$stmt = $conn->prepare("SELECT status, COUNT(*) as cnt FROM wa_accounts WHERE user_id = ? GROUP BY status");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $totalAccounts += $row['cnt'];
    if ($row['status'] === 'connected') $connectedAccounts = $row['cnt'];
    if (in_array($row['status'], ['disconnected', 'logged_out'])) $disconnectedAccounts += $row['cnt'];
}
$stmt->close();

$stmt = $conn->prepare("SELECT id, session_id, label, status, phone_number, push_name, connected_at, created_at FROM wa_accounts WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->bind_param("i", $userId);
$stmt->execute();
$recentAccounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Hiding the tenant's own getting-started card (#33). Per tenant, unlike the
// instance-wide one in the console: this checklist describes one account, so one
// tenant hiding it must not hide it for everybody.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dismiss_setup') {
    if (verifyCsrf()) {
        setUserSetting($conn, $userId, 'setup_checklist_dismissed', '1');
    }
    redirect(APP_URL . '/dashboard.php');
}

// #33 §10. Both of these are read here rather than in the template so the page
// can decide its layout before it starts drawing it — an outstanding checklist
// changes what the top of this page is for.
$tenantSteps = tenantSetupSteps($conn, $userId);
$tenantOutstanding = count(array_filter($tenantSteps, fn($s) => !$s['done']));
$showTenantSetup = $tenantOutstanding > 0 && !tenantSetupDismissed($conn, $userId);

// Never dismissible, unlike the checklist: these are live faults, not
// onboarding. They disappear when they are fixed and not before.
$healthWarnings = tenantHealthWarnings($conn, $userId);

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<?php
// For admins on a not-yet-configured instance only. This dashboard is the page
// you land on after logging in, and on a fresh deployment it shows all zeros and
// invites you to link a WhatsApp account — which is step five of six. The other
// five live in the admin console, and this is the only place that says so.
//
// Not shown to tenants (they cannot act on any of it) and not shown once the
// instance is set up or the checklist has been dismissed.
$adminSetupOutstanding = 0;
if (isAdmin() && !instanceSetupDismissed($conn)) {
    $adminSetupOutstanding = count(array_filter(instanceSetupSteps($conn), fn($s) => !$s['done']));
}
?>
<?php if ($adminSetupOutstanding > 0): ?>
    <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <i class="bi bi-rocket-takeoff me-1"></i>
            <strong>This instance is not finished being set up.</strong>
            <?= (int)$adminSetupOutstanding ?> step(s) still need an administrator — outgoing email, AI keys,
            plans. Tenants will hit dead ends until they are done.
        </div>
        <a href="<?= APP_URL ?>/admin/index.php" class="btn btn-sm btn-primary">Open admin console</a>
    </div>
<?php endif; ?>

<?php // #33 §10. Faults, in severity order, each with the page that fixes it.
      // Above the metrics on purpose: a counter reading "1 disconnected" is a
      // number, and this is the sentence that number needed. Nothing is
      // rendered at all when there is nothing wrong, which is the normal case. ?>
<?php foreach ($healthWarnings as $w): ?>
    <div class="alert alert-<?= $w['severity'] === 'danger' ? 'danger' : 'warning' ?> d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <i class="bi bi-exclamation-triangle-fill me-1"></i><?= sanitize($w['message']) ?>
        </div>
        <a href="<?= sanitize($w['url']) ?>" class="btn btn-sm btn-<?= $w['severity'] === 'danger' ? 'danger' : 'warning' ?>">
            <?= sanitize($w['action']) ?>
        </a>
    </div>
<?php endforeach; ?>

<?php // #33, priority item 2. The same design as the console's instance
      // checklist, and for the same reason: a first-time tenant landed on four
      // zeroes and one button, with nothing to say what the other steps were.
      // Each step reads live state, so "Hide this" hides a reminder rather than
      // claiming anything is finished. ?>
<?php if ($showTenantSetup): ?>
<div class="card mb-4 border-primary">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-rocket-takeoff"></i>Getting started —
            <?= (int)$tenantOutstanding ?> of <?= count($tenantSteps) ?> steps left</span>
        <form method="POST" class="d-inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="dismiss_setup">
            <button class="btn btn-sm btn-link text-muted text-decoration-none" type="submit"
                    title="Hides this card. Nothing is marked as done.">Hide this</button>
        </form>
    </div>
    <div class="card-body">
        <ol class="list-unstyled mb-0">
            <?php foreach ($tenantSteps as $step): ?>
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

<?php // #33 §3. "Link Account" used to be the fourth cell of the stat grid,
      // under the heading "Quick Actions" — a button dressed as a metric, in a
      // row of three real ones. An action that belongs to the whole page goes in
      // the page's own toolbar. ?>
<div class="page-toolbar">
    <div>
        <p class="page-toolbar-title">Your WhatsApp accounts</p>
        <p class="page-toolbar-subtitle">Link a phone, then send and receive from the Chats page.</p>
    </div>
    <div class="page-toolbar-actions">
        <a href="<?= APP_URL ?>/whatsapp/chats.php" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-chat-dots me-1"></i>Open chats
        </a>
        <a href="<?= APP_URL ?>/whatsapp/link.php" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Link account
        </a>
    </div>
</div>

<?php // Three metrics now that the action has moved out, so the row divides
      // evenly at every breakpoint instead of leaving a stray fourth cell. ?>
<div class="row g-4 mb-4">
    <?php foreach ([
        ['Total accounts', $totalAccounts,        'bi-phone',        'primary'],
        ['Connected',      $connectedAccounts,    'bi-check-circle', 'success'],
        ['Disconnected',   $disconnectedAccounts, 'bi-x-circle',     'danger'],
    ] as [$label, $value, $icon, $tone]): ?>
    <div class="col-sm-6 col-xl-4">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label"><?= $label ?></div>
                    <div class="stat-value"><?= number_format((int)$value) ?></div>
                </div>
                <div class="stat-icon" style="background:var(--<?= $tone ?>-light);color:var(--<?= $tone ?>);">
                    <i class="bi <?= $icon ?>"></i>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-phone"></i>Recent WhatsApp accounts</span>
        <a href="<?= APP_URL ?>/whatsapp/accounts.php" class="btn btn-sm btn-outline-primary">View all</a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recentAccounts)): ?>
            <?php // #33 §8. An empty state is the page a new tenant sees first,
                  // so it says what linking involves and how long it lasts,
                  // rather than "get started". ?>
            <div class="empty-state">
                <i class="bi bi-phone d-block"></i>
                <h5>No accounts yet</h5>
                <p>
                    Linking takes about a minute: you scan a QR code with the phone that
                    holds the WhatsApp account, and it stays linked until you unlink it or
                    the phone does.
                </p>
                <a href="<?= APP_URL ?>/whatsapp/link.php" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i>Link account
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive table-card">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Connected</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAccounts as $acc): ?>
                        <tr>
                            <td class="fw-500"><?= sanitize($acc['label'] ?: 'Unnamed') ?></td>
                            <td><?= sanitize($acc['phone_number'] ?: '-') ?></td>
                            <td><span class="badge-status <?= waStatusClass($acc['status']) ?>"><?= sanitize(waStatusLabel($acc['status'])) ?></span></td>
                            <td class="text-muted small"><?= $acc['connected_at'] ? timeAgo($acc['connected_at']) : '-' ?></td>
                            <td>
                                <?php // #33 §4. An icon on its own is a guess, and to a
                                      // screen reader it is a link with no name at all. The
                                      // label is visible from md up and the title and
                                      // aria-label carry it everywhere else. ?>
                                <a href="<?= APP_URL ?>/whatsapp/chats.php?account=<?= $acc['id'] ?>"
                                   class="btn btn-sm btn-outline-primary"
                                   title="Open this account's chats"
                                   aria-label="Open chats for <?= sanitize($acc['label'] ?: 'this account') ?>">
                                    <i class="bi bi-chat-dots"></i><span class="d-none d-md-inline ms-1">Chats</span>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
