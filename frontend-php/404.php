<?php
require_once __DIR__ . '/config/init.php';
http_response_code(404);

$pageTitle = 'Page not found';
require_once __DIR__ . '/includes/auth-header.php';
?>

<div class="auth-card">
    <div class="auth-brand">
        <?php $authLogo = brandLogoUrl($conn); ?>
        <?php if ($authLogo !== ''): ?>
            <img src="<?= sanitize($authLogo) ?>" alt="<?= sanitize($brandName) ?>" class="auth-brand-logo">
        <?php else: ?>
            <i class="bi bi-whatsapp"></i>
            <h2><?= sanitize($brandName) ?></h2>
        <?php endif; ?>
    </div>

    <h1 class="h5 text-center mb-2">Page not found</h1>
    <p class="text-muted small text-center mb-4">
        That page does not exist, or the link that brought you here is out of date.
    </p>

    <?php if (isLoggedIn()): ?>
        <a href="<?= APP_URL ?>/dashboard.php" class="btn btn-primary w-100">Go to dashboard</a>
    <?php else: ?>
        <a href="<?= APP_URL ?>/login.php" class="btn btn-primary w-100">Sign in</a>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/auth-footer.php'; ?>
