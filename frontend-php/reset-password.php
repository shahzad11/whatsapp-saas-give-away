<?php
require_once __DIR__ . '/config/init.php';
requireGuest();

$error = '';
$token = $_GET['token'] ?? '';

if (empty($token)) {
    flash('error', 'Invalid reset link.');
    redirect(APP_URL . '/forgot-password.php');
}

$stmt = $conn->prepare("SELECT id, name, email FROM users WHERE reset_token = ? AND reset_expires > NOW()");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    flash('error', 'Reset link is invalid or has expired.');
    redirect(APP_URL . '/forgot-password.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (($pwProblem = passwordProblem($password, ['email' => $user['email'], 'name' => $user['name']])) !== null) {
            $error = $pwProblem;
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            // must_change_password is cleared as well (#48). This page serves
            // two flows now: the ordinary reset, and the password-setup
            // invitation an admin-created tenant receives. It also catches the
            // tenant who was given a temporary password and then used "forgot
            // password" instead of the forced-change page — without this they
            // would set a working password and still be sent to
            // set-password.php, which would ask them for a temporary password
            // that no longer opens anything.
            $stmt = $conn->prepare(
                "UPDATE users SET password = ?, must_change_password = 0,
                                  reset_token = NULL, reset_expires = NULL
                 WHERE id = ?"
            );
            $stmt->bind_param("si", $hashed, $user['id']);
            $stmt->execute();
            $stmt->close();

            // The reset link is proof of inbox ownership, not of which sessions
            // are safe — any still-open session (the thief's included) dies here
            // (#13). Nobody is kept: this page is for guests.
            bumpSessionVersion($conn, (int)$user['id']);

            flash('success', 'Password reset successfully. Please sign in.');
            redirect(APP_URL . '/login.php');
        }
    }
}

$pageTitle = 'Reset Password';
require_once __DIR__ . '/includes/auth-header.php';
?>

<div class="auth-card">
    <div class="auth-brand">
        <i class="bi bi-whatsapp"></i>
        <h2>Reset Password</h2>
        <p>Enter your new password</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= sanitize($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <?= csrfField() ?>
        <div class="mb-3">
            <label class="form-label">New Password</label>
            <input type="password" name="password" class="form-control" placeholder="At least 10 characters" required>
        </div>
        <div class="mb-4">
            <label class="form-label">Confirm Password</label>
            <input type="password" name="confirm_password" class="form-control" placeholder="Repeat password" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Reset Password</button>
    </form>
</div>

<?php require_once __DIR__ . '/includes/auth-footer.php'; ?>
