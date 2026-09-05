<?php
// Manual payment logging. No payment gateway exists anywhere in this codebase:
// tenants pay out of band, an admin records it here, and recording it is what
// moves the tenant onto the plan and extends their period.
require_once dirname(__DIR__) . '/includes/admin-init.php';

$errors = [];
$search = trim($_GET['q'] ?? '');
$prefillUser = (int)($_GET['user'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('error', 'Invalid request.');
        redirect(APP_URL . '/admin/payments.php');
    }

    // Renewal reminders are a separate action on this page: the admin is
    // already looking at who is lapsing, so this is where it belongs.
    if (($_POST['action'] ?? '') === 'remind') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $sent = 0;
        $failed = 0;
        $instructions = paymentInstructions($conn);

        foreach (lapsingSubscriptions($conn, 7) as $l) {
            // A single-tenant reminder still goes through the same list, so a
            // reminder can only ever be sent to someone who is actually lapsing.
            if ($targetId > 0 && (int)$l['user_id'] !== $targetId) continue;

            [$html, $text] = mailPlanExpiring(
                $l['name'], $l['plan_name'], $l['current_period_end'],
                $instructions, APP_URL . '/billing.php'
            );
            if (sendEmail($l['email'], 'Your ' . APP_NAME . ' plan is expiring', $html, $text)) {
                $sent++;
                logAudit($conn, 'admin.payment.reminder_sent', 'user', $l['user_id'], [
                    'period_end' => $l['current_period_end'],
                ]);
            } else {
                $failed++;
            }
        }

        if ($sent === 0 && $failed === 0) {
            flash('error', 'Nobody to remind — no paid period is lapsing.');
        } elseif ($failed > 0) {
            flash('error', "Sent {$sent}, failed {$failed}. Check Email / SMTP settings.");
        } else {
            flash('success', "Sent {$sent} renewal reminder(s).");
        }
        redirect(APP_URL . '/admin/payments.php');
    }

    $userId = (int)($_POST['user_id'] ?? 0);
    $planId = (int)($_POST['plan_id'] ?? 0) ?: null;
    $currency = strtoupper(trim($_POST['currency'] ?? appCurrency($conn)));
    $method = $_POST['method'] ?? '';
    $reference = trim($_POST['reference'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $periodStart = trim($_POST['period_start'] ?? '');
    $periodEnd = trim($_POST['period_end'] ?? '');
    $applyToPlan = isset($_POST['apply_to_plan']);

    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) $errors['user_id'] = 'Select a tenant.';
    $stmt->close();

    if (!isValidCurrency($currency) || !array_key_exists($currency, currencyFormats())) {
        $errors['currency'] = 'Select a currency.';
    }

    $amountMinor = parseMoneyInput($_POST['amount'] ?? '', $currency);
    if ($amountMinor === null || $amountMinor === 0) {
        $errors['amount'] = 'Enter the amount received, e.g. 1500.';
    }

    if (!array_key_exists($method, paymentMethods())) $errors['method'] = 'Select a method.';
    if (mb_strlen($reference) > 120) $errors['reference'] = 'Max 120 characters.';
    if (mb_strlen($note) > 500) $errors['note'] = 'Max 500 characters.';

    // Dates are optional, but if given must parse and be the right way round.
    $validDate = fn($d) => $d === '' || (bool)DateTime::createFromFormat('Y-m-d', $d);
    if (!$validDate($periodStart)) $errors['period_start'] = 'Use YYYY-MM-DD.';
    if (!$validDate($periodEnd)) $errors['period_end'] = 'Use YYYY-MM-DD.';
    if (!$errors && $periodStart !== '' && $periodEnd !== '' && $periodEnd < $periodStart) {
        $errors['period_end'] = 'The period ends before it starts.';
    }
    if ($applyToPlan && ($periodStart === '' || $periodEnd === '')) {
        $errors['period_end'] = 'A period is required to extend the subscription.';
    }
    if ($applyToPlan && !$planId) {
        $errors['plan_id'] = 'Choose the plan this payment is for.';
    }

    if (!$errors) {
        $paymentId = recordPayment($conn, [
            'user_id' => $userId,
            'plan_id' => $planId,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'period_start' => $periodStart ?: null,
            'period_end' => $periodEnd ?: null,
            'method' => $method,
            'reference' => $reference ?: null,
            'note' => $note ?: null,
            'created_by' => $adminId,
        ]);

        logAudit($conn, 'admin.payment.record', 'payment', $paymentId, [
            'user_id' => $userId, 'amount_minor' => $amountMinor, 'currency' => $currency,
            'plan_id' => $planId, 'period' => $periodStart . '..' . $periodEnd,
        ]);

        // Recording money is separate from granting access, so extending the
        // subscription is opt-in: an admin may be back-filling a historical
        // payment, which must not move a live period.
        if ($applyToPlan) {
            applyPaymentToSubscription($conn, $userId, $planId, $periodStart, $periodEnd);
            logAudit($conn, 'admin.payment.apply_plan', 'user', $userId, [
                'plan_id' => $planId, 'period_end' => $periodEnd, 'payment_id' => $paymentId,
            ]);
            flash('success', 'Payment logged and plan applied through ' . $periodEnd . '.');
        } else {
            flash('success', 'Payment logged.');
        }

        redirect(APP_URL . '/admin/payments.php');
    }

    flash('error', 'Please correct the highlighted fields.');
    $prefillUser = $userId;
}

$tenants = $conn->query(
    "SELECT u.id, u.name, u.email, p.name AS plan_name
     FROM users u LEFT JOIN plans p ON u.plan_id = p.id
     ORDER BY u.name"
)->fetch_all(MYSQLI_ASSOC);

$plans = $conn->query("SELECT id, name, code, price_cents, currency, billing_period FROM plans ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
$payments = getPayments($conn, null, 100, $search);
$totals = paymentTotals($conn);
$lapsing = lapsingSubscriptions($conn, 7);

function yErr($k) { global $errors; return empty($errors[$k]) ? '' : '<div class="invalid-feedback d-block">' . sanitize($errors[$k]) . '</div>'; }
function yCls($k) { global $errors; return empty($errors[$k]) ? '' : ' is-invalid'; }

$pageTitle = 'Payments';
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
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Received This Month</div>
            <div class="card-body">
                <?php if (!$totals): ?>
                    <p class="text-muted small mb-0">No payments logged this month.</p>
                <?php else: ?>
                    <?php // One row per currency, never a single total: summing across
                          // currencies without a rate produces a number that would be
                          // believed and is meaningless. ?>
                    <?php foreach ($totals as $t): ?>
                        <div class="d-flex justify-content-between align-items-baseline mb-1">
                            <span class="h5 mb-0"><?= sanitize(formatMoney((int)$t['total'], $t['currency'])) ?></span>
                            <span class="text-muted small"><?= (int)$t['payments'] ?> payment(s)</span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Needs Attention</span>
                <?php if ($lapsing): ?>
                    <form method="POST" onsubmit="return confirm('Email a renewal reminder to all <?= count($lapsing) ?> tenant(s)?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="remind">
                        <input type="hidden" name="user_id" value="0">
                        <button class="btn btn-sm btn-outline-primary" <?= smtpConfigured($conn) ? '' : 'disabled title="Configure SMTP first"' ?>>
                            <i class="bi bi-envelope me-1"></i>Remind all
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$lapsing): ?>
                    <p class="text-muted small mb-0">No paid periods lapsing in the next 7 days.</p>
                <?php else: ?>
                    <?php // Flagged, never auto-downgraded: with manual billing, an
                          // unrecorded payment is far more likely than a non-payment,
                          // and cutting off a paying tenant is the worse error. ?>
                    <ul class="list-unstyled small mb-0">
                    <?php foreach ($lapsing as $l): ?>
                        <li class="d-flex justify-content-between mb-1">
                            <a href="<?= APP_URL ?>/admin/payments.php?user=<?= (int)$l['user_id'] ?>#logForm">
                                <?= sanitize($l['name']) ?>
                            </a>
                            <span class="text-<?= $l['current_period_end'] < gmdate('Y-m-d H:i:s') ? 'danger' : 'warning' ?>">
                                <?= sanitize($l['plan_name']) ?> ends <?= sanitize(date('M j', strtotime($l['current_period_end']))) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4" id="logForm">
    <div class="card-header">Log a Payment</div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">Tenant <span class="text-danger">*</span></label>
                    <select name="user_id" class="form-select<?= yCls('user_id') ?>" required>
                        <option value="">— Select tenant —</option>
                        <?php foreach ($tenants as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= $prefillUser === (int)$t['id'] ? 'selected' : '' ?>>
                                <?= sanitize($t['name']) ?> (<?= sanitize($t['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= yErr('user_id') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Plan paid for</label>
                    <select name="plan_id" class="form-select<?= yCls('plan_id') ?>">
                        <option value="">— None / other —</option>
                        <?php foreach ($plans as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= sanitize($p['name']) ?> — <?= sanitize(formatPrice($p)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= yErr('plan_id') ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Method <span class="text-danger">*</span></label>
                    <select name="method" class="form-select<?= yCls('method') ?>" required>
                        <?php foreach (paymentMethods() as $v => $l): ?>
                            <option value="<?= $v ?>"><?= sanitize($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= yErr('method') ?>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Amount received <span class="text-danger">*</span></label>
                    <input type="text" name="amount" class="form-control<?= yCls('amount') ?>" placeholder="1500" required>
                    <?= yErr('amount') ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Currency</label>
                    <select name="currency" class="form-select<?= yCls('currency') ?>">
                        <?php $cur = appCurrency($conn); ?>
                        <?php foreach (currencyChoices() as $code => $label): ?>
                            <option value="<?= $code ?>" <?= $cur === $code ? 'selected' : '' ?>><?= sanitize($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= yErr('currency') ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Period start</label>
                    <input type="date" name="period_start" class="form-control<?= yCls('period_start') ?>"
                           value="<?= sanitize(gmdate('Y-m-d')) ?>">
                    <?= yErr('period_start') ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Period end</label>
                    <input type="date" name="period_end" class="form-control<?= yCls('period_end') ?>"
                           value="<?= sanitize(gmdate('Y-m-d', strtotime('+1 month'))) ?>">
                    <?= yErr('period_end') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Reference</label>
                    <input type="text" name="reference" class="form-control<?= yCls('reference') ?>"
                           maxlength="120" placeholder="Transaction id / cheque no.">
                    <?= yErr('reference') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Note</label>
                    <input type="text" name="note" class="form-control<?= yCls('note') ?>" maxlength="500">
                    <?= yErr('note') ?>
                </div>
            </div>

            <div class="form-check form-switch mt-3 mb-3">
                <input class="form-check-input" type="checkbox" name="apply_to_plan" id="applyToPlan" checked>
                <label class="form-check-label" for="applyToPlan">
                    Move the tenant onto this plan and extend their period to the end date
                </label>
                <div class="form-text">
                    Turn off when back-filling a historical payment — otherwise it would move a live period.
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Log Payment</button>
        </form>
    </div>
</div>

<div class="card table-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Payment History</span>
        <form method="GET" class="d-flex gap-2">
            <input type="search" name="q" class="form-control form-control-sm" style="width:220px"
                   placeholder="Tenant or reference" value="<?= sanitize($search) ?>">
            <button class="btn btn-sm btn-outline-secondary">Search</button>
            <?php if ($search !== ''): ?>
                <a href="<?= APP_URL ?>/admin/payments.php" class="btn btn-sm btn-link">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr><th>Date</th><th>Tenant</th><th>Amount</th><th>Plan</th><th>Period</th><th>Method</th><th>Reference</th><th>Logged by</th></tr>
            </thead>
            <tbody>
            <?php if (!$payments): ?>
                <tr><td colspan="8" class="text-muted small">No payments recorded<?= $search !== '' ? ' matching that search' : '' ?>.</td></tr>
            <?php endif; ?>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td class="small text-muted"><?= sanitize(date('M j, Y', strtotime($p['created_at']))) ?></td>
                    <td class="small">
                        <a href="<?= APP_URL ?>/admin/tenant.php?id=<?= (int)$p['user_id'] ?>" class="text-decoration-none">
                            <?= sanitize($p['tenant_name']) ?>
                        </a>
                    </td>
                    <td class="small fw-500"><?= sanitize(formatMoney((int)$p['amount_minor'], $p['currency'])) ?></td>
                    <td class="small"><?= sanitize($p['plan_name'] ?? '—') ?></td>
                    <td class="small text-muted">
                        <?= $p['period_start'] ? sanitize(date('M j', strtotime($p['period_start']))) . ' – ' . sanitize(date('M j, Y', strtotime($p['period_end']))) : '—' ?>
                    </td>
                    <td class="small"><?= sanitize(paymentMethodLabel($p['method'])) ?></td>
                    <td class="small text-muted"><?= sanitize($p['reference'] ?? '—') ?></td>
                    <td class="small text-muted"><?= sanitize($p['logged_by'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
