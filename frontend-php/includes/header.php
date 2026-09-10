<?php
$currentPage = currentPage();
$user = getCurrentUser();
$pageTitle = $pageTitle ?? 'Dashboard';
// #23: the admin's own name, falling back to APP_NAME from the env.
$brandName = brandName($conn ?? null);
$brandFavicon = brandFaviconUrl($conn ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle) ?> - <?= sanitize($brandName) ?></title>
    <?php if ($brandFavicon !== ''): ?>
        <link rel="icon" href="<?= sanitize($brandFavicon) ?>">
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php // #33 §17. The first tab stop on the page, so a keyboard or screen-reader
      // user is not walked through every navigation link to reach the content
      // on every single page. Hidden off-screen until it takes focus. ?>
<a class="skip-link" href="#mainContent">Skip to main content</a>
<div class="app-wrapper">
    <?php require_once dirname(__DIR__) . '/includes/sidebar.php'; ?>
    <div class="app-main">
        <nav class="app-topbar" aria-label="Page header">
            <div class="d-flex align-items-center">
                <button class="btn btn-link sidebar-toggle d-lg-none me-2" onclick="toggleSidebar()"
                        aria-label="Toggle navigation" aria-controls="appSidebar">
                    <i class="bi bi-list fs-4"></i>
                </button>
                <h5 class="mb-0"><?= sanitize($pageTitle) ?></h5>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="dropdown">
                    <button class="btn btn-link dropdown-toggle user-menu-btn" data-bs-toggle="dropdown">
                        <div class="user-avatar-sm">
                            <?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>
                        </div>
                        <span class="d-none d-md-inline"><?= sanitize($user['name'] ?? 'User') ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php // No Settings item here (#40). The page this used to open had
                              // nothing left on it — timezone moved to Profile and automatic
                              // reconnection was never a per-tenant choice — so it was a menu
                              // entry that led to a summary of two things the tenant could not
                              // change from it. The route now belongs to the tenant's
                              // automation settings (#41), which lives in the sidebar next to
                              // the features it configures rather than in the account menu. ?>
                        <li><a class="dropdown-item" href="<?= APP_URL ?>/profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
                        <?php // The only route into the admin console from the tenant app, and
                              // only for admins. Never rendered for a regular tenant — and the
                              // console guards itself server-side regardless, since hiding a
                              // link is not access control. ?>
                        <?php if (isAdmin()): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/index.php"><i class="bi bi-shield-lock me-2"></i>Admin console</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </nav>
        <main class="app-content" id="mainContent">
        <?php // Drained here rather than per page (#33): every page gets every
              // severity, and a redirect-and-flash save produces the same toast
              // an AJAX save does. ?>
        <?php renderFlash(); ?>
