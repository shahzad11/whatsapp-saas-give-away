<?php
require_once __DIR__ . '/config/init.php';
requireGuest();

// Without this page an account whose activation email failed to send was
// stranded permanently: is_active stayed 0, the only key was in an email that
// never arrived, and login.php just repeated "check your email".
//
// register.php no longer gates accounts at all when SMTP is unconfigured, so
// this page exists for the other case — SMTP works, but that one send failed
// (greylisting, a transient 4xx, a typo'd relay since fixed).

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');

        // Throttled per session rather than through login_attempts. Writing
        // failures to that table would have made every resend count toward the
        // login lockout for the same email and IP — so a user fixing their
        // activation would have locked themselves out of the login they were
        // trying to reach. A dropped cookie defeats this, which is acceptable:
        // the only thing an attacker can trigger is an activation link
        // delivered to the inbox that already owns the unactivated account.
        $now = time();
        $recent = array_values(array_filter(
            $_SESSION['resend_activation'] ?? [],
            fn($t) => $t > $now - 900
        ));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (count($recent) >= 3) {
            $error = 'Too many attempts. Please try again in 15 minutes.';
            $_SESSION['resend_activation'] = $recent;
        } elseif (!smtpConfigured($conn)) {
            // Not per-account information, so saying it plainly leaks nothing —
            // and it is the only honest answer. Silently "succeeding" here
            // would recreate the dead end this page exists to remove.
            $error = 'This site cannot send email yet. Please contact ' . MAIL_FROM . ' to have your account activated.';
        } else {
            $stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ? AND is_active = 0 AND status = 'active'");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user) {
                // A fresh token invalidates any earlier link. The old one may
                // be sitting in a mail queue or a log, and only one activation
                // link should ever be live.
                $token = generateToken();
                $stmt = $conn->prepare("UPDATE users SET activation_token = ? WHERE id = ?");
                $stmt->bind_param('si', $token, $user['id']);
                $stmt->execute();
                $stmt->close();

                [$html, $text] = mailActivation($user['name'], APP_URL . '/activate.php?token=' . $token);
                if (!sendEmail($email, 'Activate your account', $html, $text)) {
                    error_log("Activation email could not be re-sent to {$email}");
                }
                logAudit($conn, 'register.activation_resent', 'user', $user['id']);
            }

            // Counted whether or not anything was sent, so the throttle accrues
            // for a spray across addresses too, not just repeats of one.
            $recent[] = $now;
            $_SESSION['resend_activation'] = $recent;

            // Identical for "no such address", "already activated" and "sent",
            // for the same reason forgot-password.php is: any difference turns
            // the form into an account-enumeration oracle.
            $success = 'If that email needs activating, we sent a new link. Check your inbox.';
        }
    }
}

$pageTitle = 'Resend Activation';
require_once __DIR__ . '/includes/auth-header.php';
?>

<div class="auth-card">
    <div class="auth-brand">
        <i class="bi bi-whatsapp"></i>
        <h2>Resend Activation</h2>
        <p>Enter your email to receive a new activation link</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= sanitize($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= sanitize($success) ?></div>
    <?php endif; ?>

    <form method="POST">
        <?= csrfField() ?>
        <div class="mb-4">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" placeholder="you@example.com" required>
        </div>
        <button type="submit" class="btn btn-primary w-100 mb-3">Send Activation Link</button>
        <p class="text-center small text-muted mb-0">
            Already activated? <a href="<?= APP_URL ?>/login.php" class="text-decoration-none">Sign in</a>
        </p>
    </form>
</div>

<?php require_once __DIR__ . '/includes/auth-footer.php'; ?>
