<?php
require_once __DIR__ . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$success = '';
$error = '';

// getUserSetting()/setUserSetting() now live in includes/settings.php — the
// profile page needs them too, and two page-local copies would drift.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Invalid request.';
    } else {
        $notifyConnect = isset($_POST['notify_connect']) ? '1' : '0';
        $notifyDisconnect = isset($_POST['notify_disconnect']) ? '1' : '0';
        $autoReconnect = isset($_POST['auto_reconnect']) ? '1' : '0';

        setUserSetting($conn, $userId, 'notify_connect', $notifyConnect);
        setUserSetting($conn, $userId, 'notify_disconnect', $notifyDisconnect);
        setUserSetting($conn, $userId, 'auto_reconnect', $autoReconnect);

        $success = 'Settings saved successfully.';
    }
}

$notifyConnect = getUserSetting($conn, $userId, 'notify_connect', '1');
$notifyDisconnect = getUserSetting($conn, $userId, 'notify_disconnect', '1');
$autoReconnect = getUserSetting($conn, $userId, 'auto_reconnect', '1');
$timezone = getUserSetting($conn, $userId, 'timezone', appTimezone($conn));

$pageTitle = 'Settings';
require_once __DIR__ . '/includes/header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= sanitize($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= sanitize($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<form method="POST">
    <?= csrfField() ?>

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
        <div class="card-header">Notifications</div>
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="notify_connect" id="notifyConnect"
                       <?= $notifyConnect === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="notifyConnect">Notify when account connects</label>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="notify_disconnect" id="notifyDisconnect"
                       <?= $notifyDisconnect === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="notifyDisconnect">Notify when account disconnects</label>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">WhatsApp Behaviour</div>
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="auto_reconnect" id="autoReconnect"
                       <?= $autoReconnect === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="autoReconnect">Auto-reconnect disconnected accounts</label>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">Save Settings</button>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
