<?php
// The one page a tenant on a temporary password may use (#48).
//
// An admin who creates a tenant without email available gets a temporary
// password to pass on by hand. That makes it a shared secret the moment it is
// issued — read down a phone, typed into a chat window, possibly written on
// something — so it buys exactly one login and no more. requireLogin() sends
// every other request here until it has been replaced.
//
// It is deliberately NOT part of profile.php. That page needs a sidebar, a
// plan, a profile and a timezone, all of which invite the tenant to wander off
// and none of which they may touch yet; this one has a single form and a way
// out.
require_once __DIR__ . '/config/init.php';

// requireLogin() exempts this page from its own redirect, so this is the
// ordinary "are you signed in and not suspended" check — a temporary password
// still had to be used to get here.
requireLogin();

$user = getCurrentUser();
$userId = (int)$user['id'];

// Someone who is not flagged has nothing to do here. Sent to the dashboard
// rather than shown a second password form: profile.php already owns changing a
// password you already know, and two pages doing it would drift.
if (empty($user['must_change_password'])) {
    redirect(APP_URL . '/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // The temporary password is asked for again even though it was just
        // used to log in. The session may have been left open on a shared
        // machine, and this is the request that sets a credential — proving the
        // person at the keyboard is the one the password was given to costs one
        // field.
        if (!password_verify($current, $row['password'])) {
            $error = 'That is not the temporary password you were given.';
        } elseif (($pwProblem = passwordProblem($new, ['email' => $user['email'], 'name' => $user['name']])) !== null) {
            $error = $pwProblem;
        } elseif ($new !== $confirm) {
            $error = 'The two passwords do not match.';
        } elseif ($new === $current) {
            // Otherwise the flag clears while the shared secret stays live,
            // which is the entire thing this page exists to prevent.
            $error = 'Choose a different password from the temporary one.';
        } else {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                "UPDATE users SET password = ?, must_change_password = 0,
                                  reset_token = NULL, reset_expires = NULL
                 WHERE id = ?"
            );
            $stmt->bind_param('si', $hashed, $userId);
            $stmt->execute();
            $stmt->close();

            // The credential just changed, so the session id must too: anyone
            // holding the old one was holding a session opened with the
            // temporary password. The version bump (#13) does the same to every
            // *other* session — this one is re-stamped inside the call.
            session_regenerate_id(true);
            bumpSessionVersion($conn, $userId);

            logAudit($conn, 'profile.password_change', 'user', $userId, ['reason' => 'first_login']);
            flash('success', 'Password set. Welcome aboard.');
            redirect(APP_URL . '/dashboard.php');
        }
    }
}

$pageTitle = 'Set your password';
require_once __DIR__ . '/includes/auth-header.php';
?>

<div class="auth-card">
    <div class="auth-brand">
        <i class="bi bi-shield-lock"></i>
        <h2>Set your password</h2>
        <p>Your account was set up with a temporary password. Choose your own to continue.</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= sanitize($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <?= csrfField() ?>
        <div class="mb-3">
            <label class="form-label">Temporary password</label>
            <input type="password" name="current_password" class="form-control"
                   placeholder="The one you were given" required autofocus>
        </div>
        <div class="mb-3">
            <label class="form-label">New password</label>
            <input type="password" name="new_password" class="form-control"
                   placeholder="At least 10 characters" required>
        </div>
        <div class="mb-4">
            <label class="form-label">Confirm new password</label>
            <input type="password" name="confirm_password" class="form-control"
                   placeholder="Repeat it" required>
        </div>
        <button type="submit" class="btn btn-primary w-100 mb-3">Save and continue</button>
        <p class="text-center small text-muted mb-0">
            <a href="<?= APP_URL ?>/logout.php" class="text-decoration-none">Sign out instead</a>
        </p>
    </form>
</div>

<?php require_once __DIR__ . '/includes/auth-footer.php'; ?>
