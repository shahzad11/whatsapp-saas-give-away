<?php
// Tenant settings.
//
// This page has no form. It used to offer "Notify when account connects",
// "Notify when account disconnects" and "Auto-reconnect disconnected accounts",
// all three of which were written to `user_settings` and read by nothing: there
// is no notification sender for connect/disconnect events, and reconnection is
// unconditional in the backend rather than a per-tenant choice. Saving them
// reported success and changed no behaviour, so they were removed instead of
// being left as three switches that lie.
//
// What remains is the state a tenant actually needs to see, with a link to the
// one place that can change it.
require_once __DIR__ . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$timezone = getUserSetting($conn, $userId, 'timezone', appTimezone($conn));

$pageTitle = 'Settings';
require_once __DIR__ . '/includes/header.php';
?>

<?php // Timezone lives on the profile with the rest of the locale/identity
      // fields. It is shown here read-only rather than duplicated as a second
      // editor: two forms writing one value is a lost-update waiting to
      // happen, and it is never obvious which one last won. ?>
<div class="card mb-4">
    <div class="card-header">Display</div>
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <div class="fw-500">Timezone</div>
                <div class="text-muted small">
                    Chat timestamps use <?= sanitize($timezone) ?>
                    (<?= sanitize(timezoneOffsetLabel($timezone)) ?>)
                </div>
            </div>
            <a href="<?= APP_URL ?>/profile.php" class="btn btn-sm btn-outline-secondary">Change in Profile</a>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">WhatsApp behaviour</div>
    <div class="card-body">
        <p class="text-muted small mb-0">
            Linked accounts reconnect automatically whenever the connection drops — there is
            nothing to configure. If an account stops reconnecting it has been logged out on
            the phone and needs to be linked again from
            <a href="<?= APP_URL ?>/whatsapp/accounts.php">Accounts</a>.
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
