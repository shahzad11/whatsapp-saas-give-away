<?php
require_once __DIR__ . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$user = getCurrentUser();
$plan = getUserPlan($conn, $userId);
$plans = getActivePlans($conn);
$profile = getUserProfile($conn, $userId);

$accountsUsed = countWaAccounts($conn, $userId);
$contactsUsed = countContacts($conn, $userId);
$messagesUsed = usageCount($conn, $userId, 'messages_sent');

$accountsLimit = planLimit($plan, 'max_wa_accounts');
$contactsLimit = planLimit($plan, 'max_contacts');
$messagesLimit = planLimit($plan, 'max_messages_per_month');

$stmt = $conn->prepare(
    "SELECT status, current_period_end FROM subscriptions
     WHERE user_id = ? ORDER BY id DESC LIMIT 1"
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$subscription = $stmt->get_result()->fetch_assoc();
$stmt->close();

$payments = getPayments($conn, $userId, 24);
$instructions = paymentInstructions($conn);

// The furthest date any logged payment covers.
$paidUntil = null;
foreach ($payments as $p) {
    if ($p['period_end'] && ($paidUntil === null || $p['period_end'] > $paidUntil)) {
        $paidUntil = $p['period_end'];
    }
}

// A null limit is unlimited, which has no meaningful percentage.
function usagePercent($used, $limit) {
    if ($limit === null || $limit <= 0) return 0;
    return min(100, (int)round(($used / $limit) * 100));
}

function usageBarClass($percent) {
    if ($percent >= 90) return 'bg-danger';
    if ($percent >= 70) return 'bg-warning';
    return 'bg-success';
}

$pageTitle = 'Billing & Usage';
require_once __DIR__ . '/includes/header.php';
?>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= sanitize($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">Current Plan</div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h4 class="mb-1"><?= sanitize($plan['name'] ?? 'None') ?></h4>
                        <p class="text-muted small mb-0"><?= sanitize($plan['description'] ?? '') ?></p>
                    </div>
                    <span class="badge bg-primary fs-6"><?= sanitize(formatPrice($plan ?: [])) ?></span>
                </div>

                <?php if ($subscription): ?>
                    <ul class="list-unstyled small text-muted mb-0">
                        <li><strong>Status:</strong> <?= sanitize(ucfirst($subscription['status'])) ?></li>
                        <?php if ($subscription['current_period_end']): ?>
                            <li><strong>Renews:</strong> <?= sanitize(date('M j, Y', strtotime($subscription['current_period_end']))) ?></li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>

                <hr>
                <div class="small">
                    <div class="text-muted mb-1">Billed to</div>
                    <div class="fw-500"><?= sanitize(tenantDisplayName($user, $profile)) ?></div>
                    <?php $lines = addressLines($profile); ?>
                    <?php if ($lines): ?>
                        <div class="text-muted"><?= implode('<br>', array_map('sanitize', $lines)) ?></div>
                    <?php else: ?>
                        <?php // Nudge rather than block: every profile field is optional. ?>
                        <div class="text-muted">
                            No billing address on file.
                            <a href="<?= APP_URL ?>/profile.php">Add one</a>.
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($paidUntil): ?>
                    <hr>
                    <div class="small">
                        <div class="text-muted mb-1">Paid through</div>
                        <div class="fw-500"><?= sanitize(date('M j, Y', strtotime($paidUntil))) ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">Usage This Month</div>
            <div class="card-body">
                <?php
                $meters = [
                    ['WhatsApp Accounts', $accountsUsed, $accountsLimit],
                    ['Contacts',          $contactsUsed, $contactsLimit],
                    ['Messages Sent',     $messagesUsed, $messagesLimit],
                ];
                foreach ($meters as [$label, $used, $limit]):
                    $pct = usagePercent($used, $limit);
                ?>
                <div class="mb-4">
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="fw-500"><?= sanitize($label) ?></span>
                        <span class="text-muted"><?= number_format($used) ?> / <?= sanitize(formatLimit($limit)) ?></span>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar <?= usageBarClass($pct) ?>" style="width: <?= $limit === null ? 4 : $pct ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($instructions !== '' || $payments): ?>
<div class="row g-4 mt-1">
    <?php if ($instructions !== ''): ?>
    <div class="col-lg-5" id="howToPay">
        <div class="card h-100">
            <div class="card-header">How to Pay</div>
            <div class="card-body">
                <?php // Admin-authored plain text. nl2br over an escaped string, never
                      // raw HTML — an admin is trusted, but a stored-XSS foothold in a
                      // field every tenant renders is not a risk worth taking. ?>
                <div class="small text-muted"><?= nl2br(sanitize($instructions)) ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($payments): ?>
    <div class="col-lg-<?= $instructions !== '' ? '7' : '12' ?>">
        <div class="card table-card h-100">
            <div class="card-header">Payment History</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Date</th><th>Amount</th><th>Plan</th><th>Period</th><th>Method</th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td class="small text-muted"><?= sanitize(date('M j, Y', strtotime($p['created_at']))) ?></td>
                            <td class="small fw-500"><?= sanitize(formatMoney((int)$p['amount_minor'], $p['currency'])) ?></td>
                            <td class="small"><?= sanitize($p['plan_name'] ?? '—') ?></td>
                            <td class="small text-muted">
                                <?= $p['period_start'] ? sanitize(date('M j', strtotime($p['period_start']))) . ' – ' . sanitize(date('M j, Y', strtotime($p['period_end']))) : '—' ?>
                            </td>
                            <td class="small"><?= sanitize(paymentMethodLabel($p['method'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<h5 class="mt-5 mb-3">Available Plans</h5>
<div class="row g-4">
    <?php foreach ($plans as $p): ?>
    <div class="col-md-4">
        <div class="card h-100 <?= ($plan && $p['id'] == $plan['id']) ? 'border-primary' : '' ?>">
            <div class="card-body d-flex flex-column">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="mb-0"><?= sanitize($p['name']) ?></h5>
                    <?php if ($plan && $p['id'] == $plan['id']): ?>
                        <span class="badge bg-primary">Current</span>
                    <?php endif; ?>
                </div>
                <div class="h3 mb-3"><?= sanitize(formatPrice($p)) ?></div>
                <p class="text-muted small"><?= sanitize($p['description']) ?></p>
                <ul class="list-unstyled small mb-4">
                    <li><i class="bi bi-check2 text-success me-2"></i><?= sanitize(formatLimit(planLimit($p, 'max_wa_accounts'))) ?> WhatsApp account(s)</li>
                    <li><i class="bi bi-check2 text-success me-2"></i><?= sanitize(formatLimit(planLimit($p, 'max_contacts'))) ?> contacts</li>
                    <li><i class="bi bi-check2 text-success me-2"></i><?= sanitize(formatLimit(planLimit($p, 'max_messages_per_month'))) ?> messages / month</li>
                </ul>
                <div class="mt-auto">
                    <?php if ($plan && $p['id'] == $plan['id']): ?>
                        <button class="btn btn-outline-secondary w-100" disabled>Current Plan</button>
                    <?php elseif ($instructions !== ''): ?>
                        <?php // Billing is manual by design — no gateway, so no checkout
                              // button. Point at the admin's own instructions when they
                              // exist, and fall back to email when they do not. ?>
                        <a href="#howToPay" class="btn btn-primary w-100">See how to pay</a>
                    <?php else: ?>
                        <a href="mailto:<?= sanitize(MAIL_FROM) ?>?subject=<?= rawurlencode('Plan change request: ' . $p['name']) ?>"
                           class="btn btn-primary w-100">Contact us to switch</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
