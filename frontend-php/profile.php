<?php
require_once __DIR__ . '/config/init.php';
requireLogin();

$user = getCurrentUser();
$error = '';
$success = '';
$passwordError = '';
$passwordSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) {
        $error = 'Invalid request.';
    } else {
        if ($_POST['action'] === 'update_profile') {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');

            if (empty($name) || empty($email)) {
                $error = 'Name and email are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email address.';
            } else {
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->bind_param("si", $email, $user['id']);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $error = 'This email is already in use.';
                }
                $stmt->close();

                if (!$error) {
                    $stmt = $conn->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
                    $stmt->bind_param("ssi", $name, $email, $user['id']);
                    $stmt->execute();
                    $stmt->close();

                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_email'] = $email;
                    $user = getCurrentUser();
                    $success = 'Profile updated successfully.';
                }
            }
        }

        if ($_POST['action'] === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $user['id']);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!password_verify($currentPassword, $row['password'])) {
                $passwordError = 'Current password is incorrect.';
            } elseif (strlen($newPassword) < 8) {
                $passwordError = 'New password must be at least 8 characters.';
            } elseif ($newPassword !== $confirmPassword) {
                $passwordError = 'Passwords do not match.';
            } else {
                $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $hashed, $user['id']);
                $stmt->execute();
                $stmt->close();
                $passwordSuccess = 'Password changed successfully.';
            }
        }
    }
}

$pageTitle = 'Profile';
require_once __DIR__ . '/includes/header.php';
?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card text-center">
            <div class="card-body py-4">
                <div class="user-avatar-lg mx-auto mb-3">
                    <?= strtoupper(substr($user['name'], 0, 1)) ?>
                </div>
                <h5 class="fw-600 mb-1"><?= sanitize($user['name']) ?></h5>
                <p class="text-muted small mb-2"><?= sanitize($user['email']) ?></p>
                <span class="badge bg-light text-dark">Member since <?= date('M Y', strtotime($user['created_at'])) ?></span>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header">Profile Information</div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= sanitize($error) ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?= sanitize($success) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_profile">
                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="name" class="form-control" value="<?= sanitize($user['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?= sanitize($user['email']) ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Change Password</div>
            <div class="card-body">
                <?php if ($passwordError): ?>
                    <div class="alert alert-danger"><?= sanitize($passwordError) ?></div>
                <?php endif; ?>
                <?php if ($passwordSuccess): ?>
                    <div class="alert alert-success"><?= sanitize($passwordSuccess) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" placeholder="Min. 8 characters" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
