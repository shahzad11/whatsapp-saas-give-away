<?php
require_once __DIR__ . '/config/init.php';
requireGuest();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            if ($user) {
                $resetToken = generateToken();
                $resetExpires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $stmt = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
                $stmt->bind_param("ssi", $resetToken, $resetExpires, $user['id']);
                $stmt->execute();
                $stmt->close();

                $resetLink = APP_URL . '/reset-password.php?token=' . $resetToken;
                [$html, $text] = mailPasswordReset($user['name'], $resetLink, 1);
                if (!sendEmail($email, 'Reset your password', $html, $text)) {
                    error_log("Password reset email could not be sent to {$email}");
                }
            }

            // Deliberately identical whether or not the address exists, and
            // whether or not the send succeeded — anything else turns this form
            // into an account-enumeration oracle.
            $success = 'If that email exists, we sent a reset link. Check your inbox.';
        }
    }
}

$pageTitle = 'Forgot Password';
require_once __DIR__ . '/includes/auth-header.php';
?>

<div class="auth-card">
    <div class="auth-brand">
        <i class="bi bi-whatsapp"></i>
        <h2>Forgot Password</h2>
        <p>Enter your email to receive a reset link</p>
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
        <button type="submit" class="btn btn-primary w-100 mb-3">Send Reset Link</button>
        <p class="text-center small text-muted mb-0">
            Remember your password? <a href="<?= APP_URL ?>/login.php" class="text-decoration-none">Sign in</a>
        </p>
    </form>
</div>

<?php require_once __DIR__ . '/includes/auth-footer.php'; ?>
