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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle) ?> - <?= APP_NAME ?> Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
    <link href="<?= APP_URL ?>/assets/css/admin.css" rel="stylesheet">
</head>
<body class="admin-console">
<div class="app-wrapper">
    <aside class="app-sidebar" id="appSidebar">
        <div class="sidebar-brand">
            <i class="bi bi-shield-lock"></i>
            <span><?= APP_NAME ?></span>
        </div>
        <div class="admin-scope-banner">Admin console</div>
        <nav class="sidebar-nav">
            <div class="nav-section">
                <span class="nav-section-title">Platform</span>
                <a href="<?= APP_URL ?>/admin/index.php" class="nav-link-item <?= $currentPage === 'index' ? 'active' : '' ?>">
                    <i class="bi bi-speedometer2"></i><span>Overview</span>
                </a>
                <a href="<?= APP_URL ?>/admin/tenants.php" class="nav-link-item <?= in_array($currentPage, ['tenants', 'tenant'], true) ? 'active' : '' ?>">
                    <i class="bi bi-people"></i><span>Tenants</span>
                </a>
                <a href="<?= APP_URL ?>/admin/plans.php" class="nav-link-item <?= $currentPage === 'plans' ? 'active' : '' ?>">
                    <i class="bi bi-box-seam"></i><span>Plans</span>
                </a>
                <a href="<?= APP_URL ?>/admin/payments.php" class="nav-link-item <?= $currentPage === 'payments' ? 'active' : '' ?>">
                    <i class="bi bi-cash-coin"></i><span>Payments</span>
                </a>
            </div>
            <div class="nav-section">
                <span class="nav-section-title">Instance</span>
                <a href="<?= APP_URL ?>/admin/settings.php" class="nav-link-item <?= $currentPage === 'settings' ? 'active' : '' ?>">
                    <i class="bi bi-sliders"></i><span>Settings</span>
                </a>
                <a href="<?= APP_URL ?>/admin/email.php" class="nav-link-item <?= $currentPage === 'email' ? 'active' : '' ?>">
                    <i class="bi bi-envelope-at"></i><span>Email / SMTP</span>
                </a>
                <a href="<?= APP_URL ?>/admin/llm.php" class="nav-link-item <?= $currentPage === 'llm' ? 'active' : '' ?>">
                    <i class="bi bi-robot"></i><span>AI / LLM</span>
                </a>
                <a href="<?= APP_URL ?>/admin/system.php" class="nav-link-item <?= $currentPage === 'system' ? 'active' : '' ?>">
                    <i class="bi bi-activity"></i><span>System &amp; Audit</span>
                </a>
            </div>
            <div class="nav-section">
                <span class="nav-section-title">Your account</span>
                <a href="<?= APP_URL ?>/dashboard.php" class="nav-link-item">
                    <i class="bi bi-box-arrow-left"></i><span>Exit to app</span>
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
        <nav class="app-topbar">
            <div class="d-flex align-items-center">
                <button class="btn btn-link sidebar-toggle d-lg-none me-2" onclick="toggleSidebar()">
                    <i class="bi bi-list fs-4"></i>
                </button>
                <h5 class="mb-0 fw-600"><?= sanitize($pageTitle) ?></h5>
            </div>
            <div class="d-flex align-items-center gap-3">
                <a href="<?= APP_URL ?>/dashboard.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-box-arrow-left me-1"></i>Exit to app
                </a>
                <a href="<?= APP_URL ?>/logout.php" class="btn btn-sm btn-link text-danger text-decoration-none">
                    <i class="bi bi-box-arrow-right me-1"></i>Logout
                </a>
            </div>
        </nav>
        <div class="app-content">
