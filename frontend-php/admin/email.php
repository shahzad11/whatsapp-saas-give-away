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

$self = APP_URL . '/admin/email.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    formRequireCsrf($self);

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
        $errors['smtp_password'] = cryptoSecretMissingMessage();
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
                formRespond(false, 'The password could not be encrypted and was not saved.', $self,
                    ['smtp_password' => 'This instance cannot encrypt secrets — see the note above.']);
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

        if ($action === 'verify') {
            [$ok, $message, $transcript] = verifySmtpConnection(smtpSettings($conn));
            logAudit($conn, 'admin.smtp.verify', 'app_settings', null, ['host' => $host, 'port' => $port, 'ok' => $ok]);
            // Same contract as the send-test below: the probe's own outcome
            // lives in the result panel, so $ok here reports that the save
            // succeeded and the AJAX path reloads to show the panel rather
            // than shrinking a diagnostic into a toast.
            $_SESSION['smtp_test'] = ['kind' => 'connection', 'ok' => $ok, 'message' => $message, 'transcript' => $transcript];
            formRespond(true, $ok ? 'Connection test passed.' : 'Connection test failed — see the details on the page.',
                $self, [], ['redirect' => $self]);
        }

        if ($action === 'test') {
            $to = trim($_POST['test_to'] ?? '');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                formRespond(false, 'Settings saved, but the test address is not a valid email.', $self,
                    ['test_to' => 'Enter a valid email address to send the test to.']);
            }
            [$html, $text] = mailTest();
            [$ok, $message] = sendMailNow($to, 'Test message from ' . brandName($conn), $html, $text, $conn);
            logAudit($conn, 'admin.smtp.test', 'app_settings', null, ['to' => $to, 'ok' => $ok]);
            // The full result panel (including the SPF note) is rendered on the
            // page, so the AJAX path reloads to show it rather than shrinking a
            // diagnostic into a toast.
            $_SESSION['smtp_test'] = ['kind' => 'message', 'ok' => $ok, 'message' => $message, 'to' => $to];
            formRespond(true, $ok ? 'Test message accepted by the server.' : 'Test message failed — see the details on the page.',
                $self, [], ['redirect' => $self]);
        }

        formRespond(true, 'Email settings saved.', $self);
    }

    formErrors('Please correct the highlighted fields.', $errors);
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

<?php if ($testResult && ($testResult['kind'] ?? 'message') === 'connection'): ?>
    <div class="alert alert-<?= $testResult['ok'] ? 'success' : 'danger' ?>">
        <strong><?= $testResult['ok'] ? 'Connection test passed' : 'Connection test failed' ?></strong>
        <div class="small mt-1"><?= sanitize($testResult['message']) ?></div>
        <?php if ($testResult['ok']): ?>
            <div class="small mt-1 text-muted">
                This proves the server is reachable and the credentials work. It does not prove a
                message will reach an inbox — use "Save &amp; send test" for that.
            </div>
        <?php endif; ?>
        <?php // SmtpClient redacts credentials in its transcript (AUTH PLAIN
              // <redacted>, <username>, <redacted>), which is why it is safe to
              // render here — do not add unredacted lines to the log. ?>
        <?php // Empty when the socket never opened — an empty disclosure titled
              // "Server conversation" reads as a bug, so it is omitted. ?>
        <?php if (!empty($testResult['transcript'])): ?>
            <details class="mt-2">
                <summary>Server conversation</summary>
                <pre class="small mb-0"><?= sanitize(implode("\n", $testResult['transcript'])) ?></pre>
            </details>
        <?php endif; ?>
    </div>
<?php elseif ($testResult): ?>
    <div class="alert alert-<?= $testResult['ok'] ? 'success' : 'danger' ?>">
        <strong><?= $testResult['ok'] ? 'Test message sent' : 'Test message failed' ?></strong>
        to <?= sanitize($testResult['to']) ?>.
        <div class="small mt-1"><?= sanitize($testResult['message']) ?></div>
        <?php if ($testResult['ok']): ?>
            <div class="small mt-1 text-muted">
                The server accepted it, which is as far as this test can see — delivery is up to the
                recipient's provider. If it does not arrive, check the spam folder first. Landing in spam
                usually means the sending domain has no <strong>SPF record</strong>: a line in the domain's
                DNS naming the servers allowed to send mail for it. Whoever manages your DNS adds it, and
                your email provider documents the exact value to use.
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php // One strip states the state — including the positive one, which the old
      // page never showed. The long explanations move into <details> under it:
      // the reasoning is worth keeping, but it is not worth re-reading on every
      // visit. Both are preserved word for word. ?>
<div class="alert alert-<?= $unreadable ? 'danger' : ($configured ? 'success' : 'warning') ?> d-flex align-items-start gap-2">
    <i class="bi bi-<?= $unreadable ? 'exclamation-octagon' : ($configured ? 'check-circle' : 'exclamation-triangle') ?>"></i>
    <div class="small">
        <?php if ($unreadable): ?>
            <strong>Credential problem</strong> — the stored SMTP password cannot be decrypted,
            so email will not send.
        <?php elseif (!$configured): ?>
            <strong>Not configured</strong> — this instance cannot send email.
        <?php else: ?>
            <strong>Configured</strong> — sending through
            <code><?= sanitize($stored['smtp_host']) ?></code>
            as <?= sanitize($stored['smtp_from_email'] !== '' ? $stored['smtp_from_email'] : $stored['smtp_username']) ?>.
        <?php endif; ?>
    </div>
</div>

<?php if ($unreadable): ?>
    <details class="mb-4">
        <summary class="small text-muted">Why the stored password cannot be recovered</summary>
        <div class="alert alert-warning small mt-2 mb-0">
            <strong>The stored SMTP password cannot be decrypted.</strong>
            This happens when the instance secret (<code>APP_SECRET_KEY</code>, or
            <code>BACKEND_API_KEY</code> when that is unset) has changed since it was saved.
            Re-enter the password below to fix it. Email will not send until you do.
        </div>
    </details>
<?php endif; ?>
<?php if (!$configured): ?>
    <details class="mb-4">
        <summary class="small text-muted">What stops working, and why there is no built-in fallback</summary>
        <div class="alert alert-warning small mt-2 mb-0">
            <strong>Email is not configured, so this instance cannot send any.</strong>
            Account activation, password resets, renewal reminders and human-handover alerts are all
            silently undeliverable until the fields below are filled in.
            <?php // #15 asked for a local EXIM relay so a fresh deployment could send
                  // mail before an admin configured SMTP. It was declined, and this is
                  // where that decision has to be visible — otherwise the absence of a
                  // fallback looks like something that has not been built yet.
                  //
                  // The reasoning: a self-hosted MTA on a VPS with no SPF record, no
                  // DKIM signing and generic reverse DNS does not reach inboxes, it
                  // reaches spam folders. Mail that is silently filtered is strictly
                  // worse than mail that visibly fails, because nobody investigates a
                  // password reset that "was sent". An SMTP relay the operator already
                  // owns has the reputation these messages need. ?>
            <div class="small mt-2">
                There is <strong>deliberately</strong> no local mail server to fall back to. A mail server
                running on this VPS would have no SPF record, no DKIM signature and generic reverse DNS,
                so most providers would filter its mail into spam — and mail that is silently filtered is
                worse than mail that visibly fails, because nobody investigates a reset link that "was
                sent". Use an SMTP relay whose domain reputation you already own: your own mail provider,
                or a transactional service. Any of them works here.
            </div>
        </div>
    </details>
<?php endif; ?>

<form method="POST" data-ajax>
    <?= csrfField() ?>

    <div class="card mb-4">
        <div class="card-header">SMTP server</div>
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
                    <?php // Two acronyms and two port numbers, with nothing saying which to pick.
                          // Your provider's own setup page is the authority, so the guidance is
                          // "use the common one unless told otherwise" rather than a lecture. ?>
                    <div class="form-text">
                        For most providers, use <strong>STARTTLS on port 587</strong>. Use SSL/TLS on
                        port 465 only when your provider requires it. Never use None over the public
                        internet because credentials and messages would be unencrypted.
                    </div>
                    <?= eErr('smtp_encryption') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Username</label>
                    <input type="text" name="smtp_username" class="form-control<?= eCls('smtp_username') ?>"
                           value="<?= sanitize($stored['smtp_username']) ?>" autocomplete="off">
                    <div class="form-text">
                        Usually the same as the From address below, or the one your provider gave you.
                        Leave it blank only if your mail server accepts mail from this machine without a
                        login (an "unauthenticated relay" — typically a server on your own network).
                    </div>
                    <?= eErr('smtp_username') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Password</label>
                    <?php // The placeholder used to claim "stored — leave blank to keep" on a first
                          // visit with nothing stored, which reads as "a password already exists"
                          // and invites leaving it blank. It now only says that when it is true. ?>
                    <input type="password" name="smtp_password" class="form-control<?= eCls('smtp_password') ?>"
                           autocomplete="new-password"
                           placeholder="<?= $hasPassword ? '•••••••• (stored — leave blank to keep)' : 'Enter your SMTP password' ?>">
                    <div class="form-text">
                        Encrypted at rest. It is never displayed again after saving.
                        Gmail, Outlook and most providers with two-factor authentication need an
                        app-specific password here, not your normal account password.
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
                           value="<?= sanitize($stored['smtp_from_name'] ?: brandName($conn)) ?>" maxlength="100">
                    <?= eErr('smtp_from_name') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">Test</div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Send a test message to</label>
                    <input type="email" name="test_to" class="form-control"
                           value="<?= sanitize($user['email'] ?? '') ?>">
                    <div class="form-text">Saves the settings above, then sends using them. Sending a
                        test proves end-to-end delivery.</div>
                </div>
                <div class="col-md-6">
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="submit" name="action" value="test" class="btn btn-outline-primary">
                            <i class="bi bi-send me-1"></i>Save &amp; send test
                        </button>
                        <button type="submit" name="action" value="verify" class="btn btn-outline-secondary"
                                data-busy-label="Testing…">
                            <i class="bi bi-plug me-1"></i>Save &amp; test connection
                        </button>
                    </div>
                    <div class="form-text">Tests whether the host, port, encryption, username and
                        password are accepted. No message is sent.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">Emails sent by this app</div>
        <div class="card-body">
            <ul class="list-unstyled small mb-0">
                <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>New-customer password setup</li>
                <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Password reset</li>
                <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Plan expiry / renewal reminder
                    <span class="text-muted">— sent from
                    <a href="<?= APP_URL ?>/admin/payments.php">Payments</a></span></li>
                <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Human handover alerts
                    <span class="text-muted">— when a customer asks for one on their Chatbot page.
                    That field stays disabled for customers until email works here.</span></li>
            </ul>
        </div>
    </div>

    <button type="submit" name="action" value="save" class="btn btn-primary">Save settings</button>
</form>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
