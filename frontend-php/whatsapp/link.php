<?php
require_once dirname(__DIR__) . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$error = '';
$sessionId = '';
// A re-link reuses an existing session, so the QR panel has to say so — the
// instructions are the same but the outcome is not: this is repairing an account
// that already has history, not adding a new one.
$relinking = false;

$plan = getUserPlan($conn, $userId);
[$quotaOk, $quotaUsed, $quotaLimit] = checkWaAccountQuota($conn, $userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if (!verifyCsrf()) {
        $error = 'Invalid request.';
    } elseif ($action === 'relink') {
        // Deliberately not quota-checked: this account is already linked and
        // already counted. Refusing to repair it because the plan is full would
        // strand a tenant at their limit with a broken account and no way back.
        $accountId = (int)($_POST['account_id'] ?? 0);
        $stmt = $conn->prepare("SELECT session_id, label FROM wa_accounts WHERE id = ? AND user_id = ?");
        $stmt->bind_param('ii', $accountId, $userId);
        $stmt->execute();
        $acc = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$acc) {
            $error = 'That account could not be found.';
        } else {
            $resp = callBackendApi('POST', '/api/v1/wa/sessions/' . urlencode($acc['session_id']) . '/relink');

            if ($resp && ($resp['ok'] ?? false)) {
                $sessionId = $acc['session_id'];
                $relinking = true;

                $stmt = $conn->prepare("UPDATE wa_accounts SET status = 'qr_required', connected_at = NULL WHERE id = ? AND user_id = ?");
                $stmt->bind_param('ii', $accountId, $userId);
                $stmt->execute();
                $stmt->close();

                logAudit($conn, 'wa_account.relink', 'wa_account', $acc['session_id']);
            } elseif ((int)($resp['httpCode'] ?? 0) === 404) {
                // The backend has no directory for this session — its history is
                // already gone, so a QR would pair a session with nothing behind
                // it. Removing and linking again is the honest instruction.
                $error = 'This account can no longer be repaired. Remove it and link the number again.';
            } else {
                $error = waBackendErrorMessage($resp, 'relink');
            }
        }
    } elseif (!$quotaOk) {
        // Re-checked here rather than trusting the page render: the form could
        // have been loaded while under quota and submitted after hitting it.
        $error = 'Your ' . htmlspecialchars($plan['name'] ?? 'current') . ' plan allows '
               . formatLimit($quotaLimit) . ' WhatsApp account(s). Upgrade to link more.';
    } else {
        $label = trim($_POST['label'] ?? '');

        $resp = callBackendApi('POST', '/api/v1/wa/sessions', ['label' => $label]);

        if ($resp && ($resp['ok'] ?? false)) {
            $sessionId = $resp['sessionId'];

            $stmt = $conn->prepare("INSERT INTO wa_accounts (user_id, session_id, label, status) VALUES (?, ?, ?, 'qr_required')");
            $stmt->bind_param("iss", $userId, $sessionId, $label);
            $stmt->execute();
            $stmt->close();

            logAudit($conn, 'wa_account.link', 'wa_account', $sessionId, ['label' => $label]);
        } else {
            $error = waBackendErrorMessage($resp, 'create session');
        }
    }
}

$pageTitle = 'Link WhatsApp Account';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= sanitize($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!$sessionId): ?>
<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Link a New WhatsApp Account</span>
                <span class="badge bg-light text-dark">
                    <?= (int)$quotaUsed ?> / <?= sanitize(formatLimit($quotaLimit)) ?> used
                </span>
            </div>
            <div class="card-body">
                <?php if (!$quotaOk): ?>
                    <div class="alert alert-warning">
                        <strong>Plan limit reached.</strong>
                        Your <?= sanitize($plan['name'] ?? 'current') ?> plan allows
                        <?= sanitize(formatLimit($quotaLimit)) ?> WhatsApp account(s).
                        <a href="<?= APP_URL ?>/billing.php" class="alert-link">Upgrade your plan</a> to link more.
                    </div>
                <?php else: ?>
                <p class="text-muted small mb-4">Give your account a label to identify it easily, then click "Generate QR Code" to start the linking process.</p>
                <form method="POST">
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label">Account Label</label>
                        <input type="text" name="label" class="form-control" placeholder="e.g. Sales, Support, Personal" maxlength="100">
                        <div class="form-text">Optional. Helps you identify this account later.</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-qr-code me-2"></i>Generate QR Code
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <?php // The other way in: an official Cloud API number. Gated on the
              // plan's cloud_api feature — when it is off the card explains
              // rather than linking, so the option is visible but not
              // reachable. ?>
        <div class="card h-100">
            <div class="card-header">Connect an official Cloud API number</div>
            <div class="card-body">
                <p class="text-muted small">
                    Use Meta's official WhatsApp Business Platform instead of scanning a QR code.
                    You will need a Meta app with a phone number id, a permanent access token,
                    and its app secret.
                </p>
                <?php if (planHasFeature($plan, 'cloud_api')): ?>
                    <a href="<?= APP_URL ?>/whatsapp/connect-cloud.php" class="btn btn-outline-primary w-100">
                        <i class="bi bi-cloud me-2"></i>Connect Cloud API
                    </a>
                <?php else: ?>
                    <button type="button" class="btn btn-outline-secondary w-100" disabled>
                        <i class="bi bi-cloud me-2"></i>Not included in your plan
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><?= $relinking ? 'Re-link — Scan QR Code' : 'Scan QR Code' ?></span>
                <span class="badge bg-warning text-dark" id="connectionBadge">Waiting...</span>
            </div>
            <div class="card-body">
                <?php if ($relinking): ?>
                    <div class="alert alert-info small">
                        Scan this code with the <strong>same phone number</strong> as before.
                        Your chats and messages are kept — only the connection is being re-made.
                    </div>
                <?php endif; ?>
                <div class="qr-container" id="qrContainer">
                    <img id="qrImage" src="" alt="QR Code" style="display:none;">
                    <div id="qrStatus" class="qr-status text-muted">
                        <div class="spinner-border spinner-border-sm me-2"></div>Generating QR code...
                    </div>
                </div>
                <div class="mt-4">
                    <h6 class="fw-600">How to link:</h6>
                    <ol class="small text-muted">
                        <li>Open WhatsApp on your phone</li>
                        <li>Go to <strong>Settings &rarr; Linked Devices</strong></li>
                        <li>Tap <strong>"Link a Device"</strong></li>
                        <li>Point your phone camera at the QR code above</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        startQrPolling('<?= sanitize($sessionId) ?>');
    });
</script>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
