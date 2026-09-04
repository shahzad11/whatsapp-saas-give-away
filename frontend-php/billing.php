<?php
require_once __DIR__ . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$plan = getUserPlan($conn, $userId);
$plans = getActivePlans($conn);

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
                    <?php else: ?>
                        <!-- No payment provider is wired up yet, so this is a
                             contact prompt rather than a fake checkout button. -->
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
