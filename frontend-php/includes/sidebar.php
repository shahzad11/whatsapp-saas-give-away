<?php $currentPage = $currentPage ?? currentPage(); ?>
<aside class="app-sidebar" id="appSidebar">
    <div class="sidebar-brand">
        <i class="bi bi-whatsapp"></i>
        <span><?= APP_NAME ?></span>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section">
            <span class="nav-section-title">Main</span>
            <a href="<?= APP_URL ?>/dashboard.php" class="nav-link-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                <i class="bi bi-grid-1x2"></i><span>Dashboard</span>
            </a>
        </div>
        <div class="nav-section">
            <span class="nav-section-title">WhatsApp</span>
            <a href="<?= APP_URL ?>/whatsapp/accounts.php" class="nav-link-item <?= $currentPage === 'accounts' ? 'active' : '' ?>">
                <i class="bi bi-phone"></i><span>Accounts</span>
            </a>
            <a href="<?= APP_URL ?>/whatsapp/link.php" class="nav-link-item <?= $currentPage === 'link' ? 'active' : '' ?>">
                <i class="bi bi-qr-code"></i><span>Link Account</span>
            </a>
            <a href="<?= APP_URL ?>/whatsapp/contacts.php" class="nav-link-item <?= $currentPage === 'contacts' ? 'active' : '' ?>">
                <i class="bi bi-people"></i><span>Contacts</span>
            </a>
            <a href="<?= APP_URL ?>/whatsapp/chats.php" class="nav-link-item <?= $currentPage === 'chats' ? 'active' : '' ?>">
                <i class="bi bi-chat-dots"></i><span>Chats</span>
            </a>
        </div>
        <div class="nav-section">
            <span class="nav-section-title">Account</span>
            <a href="<?= APP_URL ?>/profile.php" class="nav-link-item <?= $currentPage === 'profile' ? 'active' : '' ?>">
                <i class="bi bi-person"></i><span>Profile</span>
            </a>
            <a href="<?= APP_URL ?>/billing.php" class="nav-link-item <?= $currentPage === 'billing' ? 'active' : '' ?>">
                <i class="bi bi-credit-card"></i><span>Billing &amp; Usage</span>
            </a>
            <a href="<?= APP_URL ?>/settings.php" class="nav-link-item <?= $currentPage === 'settings' ? 'active' : '' ?>">
                <i class="bi bi-gear"></i><span>Settings</span>
            </a>
        </div>
        <?php if (isAdmin()): ?>
        <div class="nav-section">
            <span class="nav-section-title">Administration</span>
            <a href="<?= APP_URL ?>/admin/index.php" class="nav-link-item <?= $currentPage === 'index' && str_contains($_SERVER['PHP_SELF'], '/admin/') ? 'active' : '' ?>">
                <i class="bi bi-shield-lock"></i><span>Tenants</span>
            </a>
        </div>
        <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
        <div class="d-flex align-items-center gap-2">
            <div class="user-avatar-sm"><?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?></div>
            <div class="sidebar-user-info">
                <div class="fw-500 text-white small"><?= sanitize($user['name'] ?? 'User') ?></div>
                <div class="text-muted-light x-small"><?= sanitize($user['email'] ?? '') ?></div>
            </div>
        </div>
    </div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
