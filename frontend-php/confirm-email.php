<?php
// Promotes a pending email change (#5).
//
// Public on purpose: the link lands in the NEW inbox, and the recipient may or
// may not have a live session in this browser — the token itself is the proof.
// Only the sha256 of it is stored, so the address bar token and a database dump
// are not the same credential.
require_once __DIR__ . '/config/init.php';

$token = (string)($_GET['token'] ?? '');
$message = '';
$ok = false;

if ($token !== '') {
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare(
        "SELECT id, name, email, pending_email FROM users
         WHERE email_change_token = ? AND pending_email IS NOT NULL
           AND email_change_expires > NOW()"
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        $message = 'This confirmation link is invalid or has expired.';
    } else {
        // Re-checked at click time, not just at request time: the address may
        // have been taken in the 24 hours between, and email is UNIQUE — the
        // race is small but the failure without this check is a 500.
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->bind_param('si', $user['pending_email'], $user['id']);
        $stmt->execute();
        $taken = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($taken) {
            $message = 'That email address is already in use by another account.';
        } else {
            $stmt = $conn->prepare(
                "UPDATE users SET email = pending_email, pending_email = NULL,
                                  email_change_token = NULL, email_change_expires = NULL
                 WHERE id = ?"
            );
            $stmt->bind_param('i', $user['id']);
            $stmt->execute();
            $stmt->close();

            logAudit($conn, 'profile.email_change', 'user', (int)$user['id'],
                ['old' => $user['email'], 'new' => $user['pending_email']], (int)$user['id']);

            // The session banner follows the change when this browser is the
            // owner's: otherwise it would keep showing the old address.
            if ((int)($_SESSION['user_id'] ?? 0) === (int)$user['id']) {
                $_SESSION['user_email'] = $user['pending_email'];
            }

            $ok = true;
            $message = 'Your sign-in email is now ' . $user['pending_email'] . '.';
        }
    }
} else {
    $message = 'This confirmation link is invalid or has expired.';
}

$pageTitle = 'Confirm email change';
require_once __DIR__ . '/includes/auth-header.php';
?>

<div class="auth-card">
    <div class="auth-brand">
        <?php $authLogo = brandLogoUrl($conn); ?>
        <?php if ($authLogo !== ''): ?>
            <img src="<?= sanitize($authLogo) ?>" alt="<?= sanitize($brandName) ?>" class="auth-brand-logo">
        <?php else: ?>
            <i class="bi bi-envelope-check"></i>
            <h2><?= sanitize($brandName) ?></h2>
        <?php endif; ?>
        <p>Email change</p>
    </div>

    <div class="alert alert-<?= $ok ? 'success' : 'danger' ?>"><?= sanitize($message) ?></div>

    <a href="<?= APP_URL . (isLoggedIn() ? '/profile.php' : '/login.php') ?>"
       class="btn btn-primary w-100"><?= isLoggedIn() ? 'Back to your profile' : 'Sign in' ?></a>
</div>

<?php require_once __DIR__ . '/includes/auth-footer.php'; ?>
