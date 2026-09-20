<?php
require_once __DIR__ . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];

// Connected rows that never got their identity written back ask the backend
// for it once here, so the numbers below reflect what the phone actually is.
waRefreshAccountIdentity($conn, $userId);

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

// The four KPI values, read up here so the page can decide which "needs
// attention" items exist before it starts drawing.
$messagesThisMonth = usageCount($conn, $userId, 'messages_sent');
$aiRepliesThisMonth = usageCount($conn, $userId, 'chatbot_replies');
$handoff = handoffCounts($conn, $userId);
$handoffWaiting = $handoff['waiting'] ?? 0;
$handoffClaimed = $handoff['claimed'] ?? 0;
$appts = apptCounts($conn, $userId);
$apptUpcoming = $appts['upcoming'] ?? 0;
$apptOverdue = $appts['overdue'] ?? 0;

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
$adminSetupLabels = [];
if (isAdmin() && !instanceSetupDismissed($conn)) {
    $adminSetupLabels = array_column(
        array_filter(instanceSetupSteps($conn), fn($s) => !$s['done']),
        'label'
    );
}
?>
<?php if ($adminSetupLabels): ?>
    <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <i class="bi bi-rocket-takeoff me-1"></i>
            <strong>Setup is not finished.</strong>
            Still to do: <?= sanitize(implode(', ', $adminSetupLabels)) ?>.
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
        <p class="page-toolbar-title">Overview</p>
        <p class="page-toolbar-subtitle">What is happening on your WhatsApp numbers right now.</p>
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

<?php
$accountTone = $connectedAccounts > 0 && $disconnectedAccounts === 0 ? 'success'
    : ($disconnectedAccounts > 0 ? 'danger' : 'secondary');
$kpis = [
    [
        'Connected accounts',
        $connectedAccounts,
        'of ' . number_format((int)$totalAccounts) . ' linked',
        'bi-phone',
        $accountTone,
        APP_URL . '/whatsapp/accounts.php',
    ],
    [
        'Messages this month',
        $messagesThisMonth,
        number_format((int)$aiRepliesThisMonth) . ' AI replies',
        'bi-chat-dots',
        'primary',
        APP_URL . '/whatsapp/chats.php',
    ],
    [
        'Live chats waiting',
        $handoffWaiting,
        number_format((int)$handoffClaimed) . ' with an agent',
        'bi-headset',
        $handoffWaiting > 0 ? 'warning' : 'secondary',
        APP_URL . '/live-chats.php',
    ],
    [
        'Upcoming appointments',
        $apptUpcoming,
        $apptOverdue > 0
            ? number_format((int)$apptOverdue) . ' past due'
            : 'next 30 days',
        'bi-calendar-check',
        $apptOverdue > 0 ? 'warning' : 'primary',
        APP_URL . '/appointments.php',
    ],
];
?>
<div class="row g-4 mb-4">
    <?php foreach ($kpis as [$label, $value, $sub, $icon, $tone, $href]): ?>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= sanitize($href) ?>" class="text-decoration-none text-reset">
            <div class="stat-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label"><?= $label ?></div>
                        <div class="stat-value"><?= number_format((int)$value) ?></div>
                        <div class="kpi-sub"><?= $sub ?></div>
                    </div>
                    <div class="stat-icon" style="background:var(--<?= $tone ?>-light);color:var(--<?= $tone ?>);">
                        <i class="bi <?= $icon ?>"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<?php
$attention = [];
if ($disconnectedAccounts > 0) {
    $attention[] = [
        $disconnectedAccounts . ' WhatsApp account(s) disconnected — the bot cannot reply on them.',
        'Fix',
        APP_URL . '/whatsapp/accounts.php',
    ];
}
if ($handoffWaiting > 0) {
    $attention[] = [
        $handoffWaiting . ' customer(s) waiting for a human.',
        'Open live chats',
        APP_URL . '/live-chats.php',
    ];
}
if ($apptOverdue > 0) {
    $attention[] = [
        $apptOverdue . ' appointment(s) past due with no outcome recorded.',
        'Review',
        APP_URL . '/appointments.php?status=booked',
    ];
}
?>
<?php if ($attention): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-exclamation-circle"></i>Needs attention</div>
    <ul class="list-group list-group-flush">
        <?php foreach ($attention as [$text, $action, $href]): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center">
            <span><?= sanitize($text) ?></span>
            <a href="<?= sanitize($href) ?>" class="btn btn-sm btn-outline-primary flex-shrink-0 ms-3"><?= $action ?></a>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($totalAccounts === 0): ?>
<div class="card">
    <div class="card-body p-0">
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
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
