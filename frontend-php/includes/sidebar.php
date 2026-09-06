<?php
$currentPage = $currentPage ?? currentPage();
// #23. A logo replaces both the icon and the name: an operator who uploaded
// their own mark does not want ours next to it.
$brandName = $brandName ?? brandName($conn ?? null);
$brandLogo = brandLogoUrl($conn ?? null);
?>
<aside class="app-sidebar" id="appSidebar">
    <div class="sidebar-brand">
        <?php if ($brandLogo !== ''): ?>
            <img src="<?= sanitize($brandLogo) ?>" alt="<?= sanitize($brandName) ?>" class="sidebar-brand-logo">
        <?php else: ?>
            <i class="bi bi-whatsapp"></i>
            <span><?= sanitize($brandName) ?></span>
        <?php endif; ?>
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
            <?php // Shown to everyone, including plans without the feature: the page
                  // explains what the plan is missing and links to billing, which is
                  // more useful than the entry silently not existing. The page and
                  // every endpoint behind it still enforce the flag server-side. ?>
            <a href="<?= APP_URL ?>/chatbot.php" class="nav-link-item <?= $currentPage === 'chatbot' ? 'active' : '' ?>">
                <i class="bi bi-robot"></i><span>Chatbot</span>
            </a>
            <a href="<?= APP_URL ?>/live-chats.php" class="nav-link-item <?= $currentPage === 'live-chats' ? 'active' : '' ?>">
                <i class="bi bi-headset"></i><span>Live chats</span>
            </a>
            <a href="<?= APP_URL ?>/appointments.php" class="nav-link-item <?= $currentPage === 'appointments' ? 'active' : '' ?>">
                <i class="bi bi-calendar-check"></i><span>Appointments</span>
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
        <?php // Still no Administration *section* here: platform management is its
              // own console under /admin/* with its own layout and nav, not a set of
              // tabs in the tenant menu. But the single door into it was hidden in
              // the top-right user dropdown, which a first-time admin has no reason
              // to open — so this is one link out, mirroring the console's own
              // "Exit to app", and it is only rendered for admins. Visibility is
              // never the control: requireAdmin() runs on every /admin/ request. ?>
        <?php if (isAdmin()): ?>
        <div class="nav-section">
            <span class="nav-section-title">Platform</span>
            <a href="<?= APP_URL ?>/admin/index.php" class="nav-link-item">
                <i class="bi bi-shield-lock"></i><span>Admin console</span>
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
