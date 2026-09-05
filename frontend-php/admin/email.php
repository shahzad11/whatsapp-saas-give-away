<?php
// SMTP configuration and test send.
//
// The password is encrypted at rest (libsodium secretbox, see includes/crypto.php)
// and is NEVER rendered back into the form. The field shows a placeholder when
// one is stored; submitting it blank leaves the stored value untouched.
require_once dirname(__DIR__) . '/includes/admin-init.php';

$errors = [];
$testResult = null;

$stored = [
    'smtp_host'       => (string)appSetting($conn, 'smtp_host', ''),
    'smtp_port'       => (string)appSetting($conn, 'smtp_port', '587'),
    'smtp_encryption' => (string)appSetting($conn, 'smtp_encryption', 'tls'),
    'smtp_username'   => (string)appSetting($conn, 'smtp_username', ''),
    'smtp_from_email' => (string)appSetting($conn, 'smtp_from_email', ''),
    'smtp_from_name'  => (string)appSetting($conn, 'smtp_from_name', ''),
];
$hasPassword = (string)appSetting($conn, 'smtp_password_enc', '') !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('error', 'Invalid request.');
        redirect(APP_URL . '/admin/email.php');
    }

    $action = $_POST['action'] ?? 'save';

    $host = trim($_POST['smtp_host'] ?? '');
    $port = (int)($_POST['smtp_port'] ?? 0);
    $encryption = $_POST['smtp_encryption'] ?? 'tls';
    $username = trim($_POST['smtp_username'] ?? '');
    $password = (string)($_POST['smtp_password'] ?? '');
    $fromEmail = trim($_POST['smtp_from_email'] ?? '');
    $fromName = trim($_POST['smtp_from_name'] ?? '');

    // Hostnames only — no scheme, no path. A URL here would fail at connect
    // time with a confusing error.
    if ($host === '' || !preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/', $host)) {
        $errors['smtp_host'] = 'Enter a hostname, e.g. smtp.gmail.com (no https://).';
    }
    if ($port < 1 || $port > 65535) {
        $errors['smtp_port'] = 'Enter a port between 1 and 65535.';
    }
    if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        $errors['smtp_encryption'] = 'Choose an encryption mode.';
    }
    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $errors['smtp_from_email'] = 'Enter a valid sender address.';
    }
    // These land in mail headers, so a newline would be header injection.
    if ($fromName !== mailHeaderSafe($fromName) || mb_strlen($fromName) > 100) {
        $errors['smtp_from_name'] = 'No line breaks, max 100 characters.';
    }
    if ($username !== mailHeaderSafe($username)) {
        $errors['smtp_username'] = 'No line breaks.';
    }

    // A password is required the first time, but not on later saves — blank
    // means "keep the stored one".
    if ($password === '' && !$hasPassword && $username !== '') {
        $errors['smtp_password'] = 'Enter the password for this username.';
    }
    if ($password !== '' && !cryptoSecretAvailable()) {
        // Refuse rather than store a secret in the clear.
        $errors['smtp_password'] = 'Cannot encrypt the password: no instance secret is available. Set APP_SECRET_KEY.';
    }

    if (!$errors) {
        setAppSetting($conn, 'smtp_host', $host);
        setAppSetting($conn, 'smtp_port', (string)$port);
        setAppSetting($conn, 'smtp_encryption', $encryption);
        setAppSetting($conn, 'smtp_username', $username);
        setAppSetting($conn, 'smtp_from_email', $fromEmail);
        setAppSetting($conn, 'smtp_from_name', $fromName);

        if ($password !== '') {
            $enc = encryptSecret($password);
            if ($enc === null) {
                flash('error', 'The password could not be encrypted and was not saved.');
                redirect(APP_URL . '/admin/email.php');
            }
            setAppSetting($conn, 'smtp_password_enc', $enc);
            $hasPassword = true;
        }
        // Clearing the username is how you drop to an unauthenticated relay.
        if ($username === '') {
            setAppSetting($conn, 'smtp_password_enc', '');
            $hasPassword = false;
        }

        // Metadata only — the password, encrypted or not, never reaches the log.
        logAudit($conn, 'admin.smtp.update', 'app_settings', null, [
            'host' => $host, 'port' => $port, 'encryption' => $encryption,
            'username_set' => $username !== '', 'from' => $fromEmail,
        ]);

        if ($action === 'test') {
            $to = trim($_POST['test_to'] ?? '');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                flash('error', 'Settings saved, but the test address is not a valid email.');
                redirect(APP_URL . '/admin/email.php');
            }
            [$html, $text] = mailTest();
            [$ok, $message] = sendMailNow($to, 'Test message from ' . APP_NAME, $html, $text, $conn);
            logAudit($conn, 'admin.smtp.test', 'app_settings', null, ['to' => $to, 'ok' => $ok]);
            $_SESSION['smtp_test'] = ['ok' => $ok, 'message' => $message, 'to' => $to];
        } else {
            flash('success', 'Email settings saved.');
        }

        redirect(APP_URL . '/admin/email.php');
    }

    flash('error', 'Please correct the highlighted fields.');
    $stored = [
        'smtp_host' => $host, 'smtp_port' => (string)$port, 'smtp_encryption' => $encryption,
        'smtp_username' => $username, 'smtp_from_email' => $fromEmail, 'smtp_from_name' => $fromName,
    ];
}

if (isset($_SESSION['smtp_test'])) {
    $testResult = $_SESSION['smtp_test'];
    unset($_SESSION['smtp_test']);
}

$unreadable = smtpCredentialUnreadable($conn);
$configured = smtpConfigured($conn);

function eErr($k) { global $errors; return empty($errors[$k]) ? '' : '<div class="invalid-feedback d-block">' . sanitize($errors[$k]) . '</div>'; }
function eCls($k) { global $errors; return empty($errors[$k]) ? '' : ' is-invalid'; }

$pageTitle = 'Email / SMTP';
require_once dirname(__DIR__) . '/includes/admin-header.php';
?>

<?php foreach (['success' => 'success', 'error' => 'danger'] as $key => $cls): ?>
    <?php if ($msg = flash($key)): ?>
        <div class="alert alert-<?= $cls ?> alert-dismissible fade show">
            <?= sanitize($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php if ($testResult): ?>
    <div class="alert alert-<?= $testResult['ok'] ? 'success' : 'danger' ?>">
        <strong><?= $testResult['ok'] ? 'Test message sent' : 'Test message failed' ?></strong>
        to <?= sanitize($testResult['to']) ?>.
        <div class="small mt-1"><?= sanitize($testResult['message']) ?></div>
        <?php if ($testResult['ok']): ?>
            <div class="small mt-1 text-muted">
                If it does not arrive, check the spam folder and the sender domain's SPF record.
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($unreadable): ?>
    <div class="alert alert-warning">
        <strong>The stored SMTP password cannot be decrypted.</strong>
        This happens when the instance secret (<code>APP_SECRET_KEY</code>, or
        <code>BACKEND_API_KEY</code> when that is unset) has changed since it was saved.
        Re-enter the password below to fix it. Email will not send until you do.
    </div>
<?php elseif (!$configured): ?>
    <div class="alert alert-info">
        <strong>Email is not configured yet.</strong>
        Until it is, account activation, password resets and renewal reminders cannot be delivered.
        <?php // Worth stating plainly: this container has no local MTA, so there
              // is no silent fallback that happens to work. ?>
        There is no local mail server to fall back to.
    </div>
<?php endif; ?>

<form method="POST">
    <?= csrfField() ?>

    <div class="card mb-4">
        <div class="card-header">SMTP Server</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Host <span class="text-danger">*</span></label>
                    <input type="text" name="smtp_host" class="form-control<?= eCls('smtp_host') ?>"
                           value="<?= sanitize($stored['smtp_host']) ?>" placeholder="smtp.gmail.com" required>
                    <?= eErr('smtp_host') ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Port <span class="text-danger">*</span></label>
                    <input type="number" name="smtp_port" class="form-control<?= eCls('smtp_port') ?>"
                           value="<?= sanitize($stored['smtp_port']) ?>" min="1" max="65535" required>
                    <?= eErr('smtp_port') ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Encryption</label>
                    <select name="smtp_encryption" class="form-select<?= eCls('smtp_encryption') ?>">
                        <?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None'] as $v => $l): ?>
                            <option value="<?= $v ?>" <?= $stored['smtp_encryption'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= eErr('smtp_encryption') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Username</label>
                    <input type="text" name="smtp_username" class="form-control<?= eCls('smtp_username') ?>"
                           value="<?= sanitize($stored['smtp_username']) ?>" autocomplete="off">
                    <div class="form-text">Leave blank for an unauthenticated relay.</div>
                    <?= eErr('smtp_username') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Password</label>
                    <input type="password" name="smtp_password" class="form-control<?= eCls('smtp_password') ?>"
                           autocomplete="new-password"
                           placeholder="<?= $hasPassword ? '•••••••• (stored — leave blank to keep)' : '' ?>">
                    <div class="form-text">
                        Encrypted at rest. It is never displayed again after saving.
                    </div>
                    <?= eErr('smtp_password') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">Sender</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">From address <span class="text-danger">*</span></label>
                    <input type="email" name="smtp_from_email" class="form-control<?= eCls('smtp_from_email') ?>"
                           value="<?= sanitize($stored['smtp_from_email'] ?: MAIL_FROM) ?>" required>
                    <div class="form-text">
                        Most providers require this to match the authenticated account, or they reject the message.
                    </div>
                    <?= eErr('smtp_from_email') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">From name</label>
                    <input type="text" name="smtp_from_name" class="form-control<?= eCls('smtp_from_name') ?>"
                           value="<?= sanitize($stored['smtp_from_name'] ?: APP_NAME) ?>" maxlength="100">
                    <?= eErr('smtp_from_name') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">Send a Test</div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Send a test message to</label>
                    <input type="email" name="test_to" class="form-control"
                           value="<?= sanitize($user['email'] ?? '') ?>">
                    <div class="form-text">Saves the settings above, then sends using them.</div>
                </div>
                <div class="col-md-6">
                    <button type="submit" name="action" value="test" class="btn btn-outline-primary">
                        <i class="bi bi-send me-1"></i>Save &amp; send test
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">Messages This Instance Sends</div>
        <div class="card-body">
            <ul class="list-unstyled small mb-0">
                <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Account activation (on signup)</li>
                <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Password reset</li>
                <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Plan expiry / renewal reminder
                    <span class="text-muted">— sent from
                    <a href="<?= APP_URL ?>/admin/payments.php">Payments</a></span></li>
            </ul>
        </div>
    </div>

    <button type="submit" name="action" value="save" class="btn btn-primary">Save Settings</button>
</form>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
