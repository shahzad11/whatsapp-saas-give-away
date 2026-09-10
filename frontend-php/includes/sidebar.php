<?php
$currentPage = $currentPage ?? currentPage();
// #23. A logo replaces both the icon and the name: an operator who uploaded
// their own mark does not want ours next to it.
$brandName = $brandName ?? brandName($conn ?? null);
$brandLogo = brandLogoUrl($conn ?? null);
?>
<aside class="app-sidebar" id="appSidebar" aria-label="Main navigation">
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
        <?php // #35. Only destinations live here. "Link Account" was removed: it is
              // an action on the Accounts page, not a place, and it is already the
              // primary button there, in the dashboard toolbar and in every empty
              // state. Accounts stays lit while linking so the nav still says where
              // you are. ?>
        <div class="nav-section">
            <span class="nav-section-title">WhatsApp</span>
            <a href="<?= APP_URL ?>/whatsapp/accounts.php" class="nav-link-item <?= in_array($currentPage, ['accounts', 'link'], true) ? 'active' : '' ?>">
                <i class="bi bi-phone"></i><span>Accounts</span>
            </a>
            <a href="<?= APP_URL ?>/whatsapp/chats.php" class="nav-link-item <?= $currentPage === 'chats' ? 'active' : '' ?>">
                <i class="bi bi-chat-dots"></i><span>Chats</span>
            </a>
            <a href="<?= APP_URL ?>/whatsapp/contacts.php" class="nav-link-item <?= $currentPage === 'contacts' ? 'active' : '' ?>">
                <i class="bi bi-people"></i><span>Contacts</span>
            </a>
        </div>
        <?php // Split out of "WhatsApp" (#35), which had grown to seven entries
              // covering two unrelated jobs: operating the mailbox by hand, and
              // configuring the thing that answers it. These three are one feature
              // set — the bot, the queue it escalates to, and what it books.
              //
              // Shown to everyone, including plans without the feature: the page
              // explains what the plan is missing and links to billing, which is
              // more useful than the entry silently not existing. The page and
              // every endpoint behind it still enforce the flag server-side. ?>
        <div class="nav-section">
            <span class="nav-section-title">Automation</span>
            <?php // #41. "Chatbot" named a third of what is behind this link: the same
                  // page configures appointment booking and human handover, and tenants
                  // were being sent to "Chatbot → Appointments" to set up a calendar.
                  // "Settings" inside the Automation section says it without competing
                  // with Profile (personal details) or the admin console (the whole
                  // instance). 'chatbot' stays in the active-state test so the old route
                  // lights the right item for the moment it takes to redirect. ?>
            <a href="<?= APP_URL ?>/settings.php" class="nav-link-item <?= in_array($currentPage, ['settings', 'chatbot'], true) ? 'active' : '' ?>">
                <i class="bi bi-sliders"></i><span>Settings</span>
            </a>
            <a href="<?= APP_URL ?>/live-chats.php" class="nav-link-item <?= $currentPage === 'live-chats' ? 'active' : '' ?>">
                <i class="bi bi-headset"></i><span>Live chats</span>
            </a>
            <a href="<?= APP_URL ?>/appointments.php" class="nav-link-item <?= $currentPage === 'appointments' ? 'active' : '' ?>">
                <i class="bi bi-calendar-check"></i><span>Appointments</span>
            </a>
        </div>
        <?php // Profile left this section (#35): it is already in the top-right user
              // menu, which is where personal account items are looked for. The old
              // tenant settings page that sat beside it is gone entirely (#40) — it
              // held a read-only summary of a timezone that Profile owns and a
              // reconnection that was never a choice. Billing stays: it is the one a
              // tenant is sent to from elsewhere in the app — every quota wall and
              // every locked feature links here. ?>
        <div class="nav-section">
            <span class="nav-section-title">Account</span>
            <a href="<?= APP_URL ?>/billing.php" class="nav-link-item <?= $currentPage === 'billing' ? 'active' : '' ?>">
                <i class="bi bi-credit-card"></i><span>Billing &amp; Usage</span>
            </a>
        </div>
        <?php // No Administration section, and no longer a single "Admin console" link
              // either (#35): platform management is its own console under /admin/*
              // with its own layout and nav, and it already has two doors from the
              // tenant app — the top-right user menu, and the dashboard banner that
              // appears while the instance is unconfigured, which is exactly when a
              // first-time admin needs to find it. Visibility was never the control:
              // requireAdmin() runs on every /admin/ request. ?>
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
