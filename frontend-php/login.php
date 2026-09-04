<?php
require_once __DIR__ . '/config/init.php';
requireGuest();

$error = '';
$success = flash('success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please fill in all fields.';
        } elseif (isLoginBlocked($conn, $email)) {
            $error = 'Too many failed attempts. Please try again in ' . LOGIN_LOCKOUT_MINUTES . ' minutes.';
        } else {
            $stmt = $conn->prepare("SELECT id, name, email, password, is_active, status FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            if (!$user || !password_verify($password, $user['password'])) {
                recordLoginAttempt($conn, $email, false);
                // Deliberately identical for "no such user" and "wrong
                // password" so the form cannot be used to enumerate accounts.
                $error = 'Invalid email or password.';
            } elseif ($user['status'] === 'suspended') {
                recordLoginAttempt($conn, $email, false);
                $error = 'This account has been suspended. Please contact support.';
            } elseif (!$user['is_active']) {
                $error = 'Your account is not activated. Please check your email.';
            } else {
                recordLoginAttempt($conn, $email, true);
                clearLoginAttempts($conn, $email);
                loginUser($user);
                logAudit($conn, 'login', 'user', $user['id']);
                redirect(APP_URL . '/dashboard.php');
            }
        }
    }
}

$pageTitle = 'Login';
require_once __DIR__ . '/includes/auth-header.php';
?>

<div class="auth-card">
    <div class="auth-brand">
        <i class="bi bi-whatsapp"></i>
        <h2><?= APP_NAME ?></h2>
        <p>Sign in to your account</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= sanitize($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= sanitize($success) ?></div>
    <?php endif; ?>

    <form method="POST">
        <?= csrfField() ?>
        <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" placeholder="you@example.com"
                   value="<?= sanitize($_POST['email'] ?? '') ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" placeholder="Enter password" required>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="remember">
                <label class="form-check-label small" for="remember">Remember me</label>
            </div>
            <a href="<?= APP_URL ?>/forgot-password.php" class="small text-decoration-none">Forgot password?</a>
        </div>
        <button type="submit" class="btn btn-primary w-100 mb-3">Sign In</button>
        <?php if (ALLOW_REGISTRATION): ?>
        <p class="text-center small text-muted mb-0">
            Don't have an account? <a href="<?= APP_URL ?>/register.php" class="text-decoration-none">Create one</a>
        </p>
        <?php endif; ?>
    </form>
</div>

<?php require_once __DIR__ . '/includes/auth-footer.php'; ?>
