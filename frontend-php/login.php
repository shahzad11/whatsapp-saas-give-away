<?php
require_once __DIR__ . '/config/init.php';
requireGuest();

$error = '';
$success = flash('success');
// Set only for the "not activated" case, so the resend link appears exactly
// when it is the thing that unblocks the user — and never as a hint that some
// other address does have an account.
$showResend = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please fill in all fields.';
        } elseif (isLoginBlocked($conn, $email)) {
            $error = 'Too many failed attempts. Please try again in ' . loginLockoutMinutes($conn) . ' minutes.';
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
                $error = 'Your account is not activated. Please check your email for the activation link.';
                $showResend = true;
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
        <?php // #23: $brandName and the logo come from auth-header.php. ?>
        <?php $authLogo = brandLogoUrl($conn); ?>
        <?php if ($authLogo !== ''): ?>
            <img src="<?= sanitize($authLogo) ?>" alt="<?= sanitize($brandName) ?>" class="auth-brand-logo">
        <?php else: ?>
            <i class="bi bi-whatsapp"></i>
            <h2><?= sanitize($brandName) ?></h2>
        <?php endif; ?>
        <p>Sign in to your account</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger mb-3">
            <?= sanitize($error) ?>
            <?php if ($showResend): ?>
                <div class="mt-2">
                    <a href="<?= APP_URL ?>/resend-activation.php" class="alert-link small">Resend activation email</a>
                </div>
            <?php endif; ?>
        </div>
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
        <?php // No "Remember me": the checkbox that used to sit here had no name
              // attribute and no handler, so it could not even be submitted. A
              // persistent login needs a signed long-lived cookie and a way to
              // revoke it; until that exists, offering the control is a lie. ?>
        <?php // Both links are always present. resend-activation.php answers
              // identically for every address, so offering it unconditionally
              // reveals nothing — and a signup whose activation mail failed
              // lands here from register.php, where the page has no way to
              // know the account is unactivated. ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <a href="<?= APP_URL ?>/resend-activation.php" class="small text-decoration-none">Resend activation email</a>
            <a href="<?= APP_URL ?>/forgot-password.php" class="small text-decoration-none">Forgot password?</a>
        </div>
        <button type="submit" class="btn btn-primary w-100 mb-3">Sign In</button>
        <?php if (allowRegistration($conn)): ?>
        <p class="text-center small text-muted mb-0">
            Don't have an account? <a href="<?= APP_URL ?>/register.php" class="text-decoration-none">Create one</a>
        </p>
        <?php endif; ?>
    </form>
</div>

<?php require_once __DIR__ . '/includes/auth-footer.php'; ?>
