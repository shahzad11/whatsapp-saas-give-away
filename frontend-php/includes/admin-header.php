<?php
// Admin console layout — deliberately separate from includes/header.php.
//
// The admin area is its own application with its own navigation, not a tab in
// the tenant menu. The red accent and the "Admin console" badge exist so it is
// never ambiguous which surface you are acting on: the actions here affect
// other people's accounts.
$currentPage = currentPage();
$user = getCurrentUser();
$pageTitle = $pageTitle ?? 'Admin';
$brandName = brandName($conn ?? null);
$brandLogo = brandLogoUrl($conn ?? null);
$brandFavicon = brandFaviconUrl($conn ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle) ?> - <?= sanitize($brandName) ?> Admin</title>
    <?php if ($brandFavicon !== ''): ?>
        <link rel="icon" href="<?= sanitize($brandFavicon) ?>">
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= APP_URL ?>/assets/css/style.css?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/css/style.css') ?>" rel="stylesheet">
    <link href="<?= APP_URL ?>/assets/css/admin.css?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/css/admin.css') ?>" rel="stylesheet">
    <?php // Same reasoning as the modal fallbacks: every toggleable element here
          // renders real content first and upgrades to a tab/accordion/collapse
          // with JavaScript. Without it, display:none leaves sections that were
          // always visible unreachable, so the noscript sheet forces them open. ?>
    <noscript><style>
        .admin-console .tab-pane { display: block !important; opacity: 1 !important; }
        .admin-console .collapse { display: block !important; }
        .admin-console .accordion-collapse { display: block !important; }
    </style></noscript>
</head>
<body class="admin-console">
<?php // #33 §17 — see includes/header.php. Both layouts need it; neither can
      // inherit it from the other, which is the cost of them being separate. ?>
<a class="skip-link" href="#mainContent">Skip to main content</a>
<div class="app-wrapper">
    <aside class="app-sidebar" id="appSidebar" aria-label="Admin console navigation">
        <?php // The shield stays even with a logo set: this is the console, and the
              // icon is what makes that unmistakable at a glance. ?>
        <div class="sidebar-brand">
            <i class="bi bi-shield-lock"></i>
            <?php if ($brandLogo !== ''): ?>
                <img src="<?= sanitize($brandLogo) ?>" alt="<?= sanitize($brandName) ?>" class="sidebar-brand-logo">
            <?php else: ?>
                <span><?= sanitize($brandName) ?></span>
            <?php endif; ?>
        </div>
        <div class="admin-scope-banner">Admin console</div>
        <nav class="sidebar-nav">
            <div class="nav-section">
                <span class="nav-section-title">Platform</span>
                <a href="<?= APP_URL ?>/admin/index.php" class="nav-link-item <?= $currentPage === 'index' ? 'active' : '' ?>">
                    <i class="bi bi-speedometer2"></i><span>Overview</span>
                </a>
                <a href="<?= APP_URL ?>/admin/tenants.php" class="nav-link-item <?= in_array($currentPage, ['tenants', 'tenant'], true) ? 'active' : '' ?>">
                    <i class="bi bi-people"></i><span>Customers</span>
                </a>
                <a href="<?= APP_URL ?>/admin/plans.php" class="nav-link-item <?= $currentPage === 'plans' ? 'active' : '' ?>">
                    <i class="bi bi-box-seam"></i><span>Plans</span>
                </a>
                <a href="<?= APP_URL ?>/admin/payments.php" class="nav-link-item <?= $currentPage === 'payments' ? 'active' : '' ?>">
                    <i class="bi bi-cash-coin"></i><span>Payments</span>
                </a>
                <?php // #50. Under Platform rather than Instance: it is the owner's
                      // own prospecting list, not a setting. Nothing tenant-facing
                      // links here and `leads` has no tenant column. ?>
                <a href="<?= APP_URL ?>/admin/leads.php" class="nav-link-item <?= $currentPage === 'leads' ? 'active' : '' ?>">
                    <i class="bi bi-binoculars"></i><span>Leads</span>
                </a>
            </div>
            <div class="nav-section">
                <span class="nav-section-title">Instance</span>
                <a href="<?= APP_URL ?>/admin/settings.php" class="nav-link-item <?= $currentPage === 'settings' ? 'active' : '' ?>">
                    <i class="bi bi-sliders"></i><span>Settings</span>
                </a>
                <a href="<?= APP_URL ?>/admin/branding.php" class="nav-link-item <?= $currentPage === 'branding' ? 'active' : '' ?>">
                    <i class="bi bi-palette"></i><span>Branding</span>
                </a>
                <a href="<?= APP_URL ?>/admin/email.php" class="nav-link-item <?= $currentPage === 'email' ? 'active' : '' ?>">
                    <i class="bi bi-envelope-at"></i><span>Email / SMTP</span>
                </a>
                <a href="<?= APP_URL ?>/admin/llm.php" class="nav-link-item <?= $currentPage === 'llm' ? 'active' : '' ?>">
                    <i class="bi bi-robot"></i><span>AI &amp; models</span>
                </a>
                <a href="<?= APP_URL ?>/admin/system.php" class="nav-link-item <?= $currentPage === 'system' ? 'active' : '' ?>">
                    <i class="bi bi-activity"></i><span>System &amp; audit</span>
                </a>
            </div>
            <div class="nav-section">
                <span class="nav-section-title">Your account</span>
                <a href="<?= APP_URL ?>/dashboard.php" class="nav-link-item">
                    <i class="bi bi-box-arrow-left"></i><span>Back to app</span>
                </a>
            </div>
        </nav>
        <div class="sidebar-footer">
            <div class="d-flex align-items-center gap-2">
                <div class="user-avatar-sm"><?= strtoupper(substr($user['name'] ?? 'A', 0, 1)) ?></div>
                <div class="sidebar-user-info">
                    <div class="fw-500 text-white small"><?= sanitize($user['name'] ?? 'Admin') ?></div>
                    <div class="text-muted-light x-small"><?= sanitize($user['email'] ?? '') ?></div>
                </div>
            </div>
        </div>
    </aside>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    <div class="app-main">
        <nav class="app-topbar" aria-label="Page header">
            <div class="d-flex align-items-center">
                <button class="btn btn-link sidebar-toggle d-lg-none me-2" onclick="toggleSidebar()"
                        aria-label="Toggle navigation" aria-controls="appSidebar">
                    <i class="bi bi-list fs-4"></i>
                </button>
                <h1 class="h5 mb-0"><?= sanitize($pageTitle) ?></h1>
            </div>
            <div class="d-flex align-items-center gap-3">
                <a href="<?= APP_URL ?>/admin/help.php" class="btn btn-sm btn-link text-secondary text-decoration-none topbar-help-link"
                   aria-label="Help Center" title="Help Center"
                   <?= $currentPage === 'help' ? 'aria-current="page"' : '' ?>>
                    <i class="bi bi-question-circle"></i>
                    <span class="d-none d-md-inline ms-1">Help</span>
                </a>
                <a href="<?= APP_URL ?>/logout.php" class="btn btn-sm btn-link text-secondary text-decoration-none">
                    <i class="bi bi-box-arrow-right me-1"></i>Log out
                </a>
            </div>
        </nav>
        <main class="app-content" id="mainContent">
        <?php renderFlash(); ?>
