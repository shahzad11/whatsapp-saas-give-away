<?php
require_once __DIR__ . '/config/init.php';
requireGuest();

// Signup is closed unless explicitly enabled for this environment. An open
// registration form on a service that links people's WhatsApp accounts is not
// something to leave on by accident.
if (!allowRegistration($conn)) {
    flash('error', 'Registration is currently closed.');
    redirect(APP_URL . '/login.php');
}

$error = '';
$formData = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $formData = ['name' => $name, 'email' => $email];

        if (empty($name) || empty($email) || empty($password)) {
            $error = 'Please fill in all fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = 'An account with this email already exists.';
            }
            $stmt->close();

            if (!$error) {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $activationToken = generateToken();

                // Every new tenant starts on the default plan, so quota checks
                // always have a plan to read.
                $defaultPlan = getPlanByCode($conn, defaultPlanCode($conn));
                $planId = $defaultPlan['id'] ?? null;

                $stmt = $conn->prepare("INSERT INTO users (name, email, password, activation_token, is_active, plan_id) VALUES (?, ?, ?, ?, 0, ?)");
                $stmt->bind_param("ssssi", $name, $email, $hashedPassword, $activationToken, $planId);

                if ($stmt->execute()) {
                    $newUserId = $conn->insert_id;
                    if ($planId) {
                        assignPlan($conn, $newUserId, $planId);
                    }
                    logAudit($conn, 'register', 'user', $newUserId, ['email' => $email]);
                    $activationLink = APP_URL . '/activate.php?token=' . $activationToken;
                    $emailBody = "
                        <h2>Welcome to " . APP_NAME . "!</h2>
                        <p>Hi {$name},</p>
                        <p>Click the link below to activate your account:</p>
                        <p><a href='{$activationLink}'>{$activationLink}</a></p>
                    ";
                    sendEmail($email, 'Activate your account', $emailBody);

                    flash('success', 'Account created! Please check your email to activate your account.');
                    redirect(APP_URL . '/login.php');
                } else {
                    $error = 'Registration failed. Please try again.';
                }
                $stmt->close();
            }
        }
    }
}

$pageTitle = 'Register';
require_once __DIR__ . '/includes/auth-header.php';
?>

<div class="auth-card">
    <div class="auth-brand">
        <i class="bi bi-whatsapp"></i>
        <h2>Create Account</h2>
        <p>Get started with <?= APP_NAME ?></p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= sanitize($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <?= csrfField() ?>
        <div class="mb-3">
            <label class="form-label">Full Name</label>
            <input type="text" name="name" class="form-control" placeholder="John Doe"
                   value="<?= sanitize($formData['name']) ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" placeholder="you@example.com"
                   value="<?= sanitize($formData['email']) ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" placeholder="Min. 8 characters" required>
        </div>
        <div class="mb-4">
            <label class="form-label">Confirm Password</label>
            <input type="password" name="confirm_password" class="form-control" placeholder="Repeat password" required>
        </div>
        <button type="submit" class="btn btn-primary w-100 mb-3">Create Account</button>
        <p class="text-center small text-muted mb-0">
            Already have an account? <a href="<?= APP_URL ?>/login.php" class="text-decoration-none">Sign in</a>
        </p>
    </form>
</div>

<?php require_once __DIR__ . '/includes/auth-footer.php'; ?>
