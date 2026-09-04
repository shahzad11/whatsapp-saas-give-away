<?php
require_once __DIR__ . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$success = '';
$error = '';

function getUserSetting($conn, $userId, $key, $default = '') {
    $stmt = $conn->prepare("SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = ?");
    $stmt->bind_param("is", $userId, $key);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? $row['setting_value'] : $default;
}

function setUserSetting($conn, $userId, $key, $value) {
    $stmt = $conn->prepare("INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param("iss", $userId, $key, $value);
    $stmt->execute();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Invalid request.';
    } else {
        $notifyConnect = isset($_POST['notify_connect']) ? '1' : '0';
        $notifyDisconnect = isset($_POST['notify_disconnect']) ? '1' : '0';
        $autoReconnect = isset($_POST['auto_reconnect']) ? '1' : '0';

        $allowedTimezones = array_keys(timezoneOptions());
        $timezone = trim($_POST['timezone'] ?? 'Asia/Karachi');
        if (!in_array($timezone, $allowedTimezones, true)) {
            $timezone = 'Asia/Karachi';
        }

        setUserSetting($conn, $userId, 'notify_connect', $notifyConnect);
        setUserSetting($conn, $userId, 'notify_disconnect', $notifyDisconnect);
        setUserSetting($conn, $userId, 'auto_reconnect', $autoReconnect);
        setUserSetting($conn, $userId, 'timezone', $timezone);

        $success = 'Settings saved successfully.';
    }
}

function timezoneOptions() {
    return [
        'Asia/Karachi' => 'Pakistan (PKT, UTC+5)',
        'Asia/Dubai' => 'UAE/Gulf (GST, UTC+4)',
        'Asia/Riyadh' => 'Saudi Arabia (AST, UTC+3)',
        'Asia/Kolkata' => 'India (IST, UTC+5:30)',
        'Asia/Dhaka' => 'Bangladesh (BST, UTC+6)',
        'Asia/Shanghai' => 'China (CST, UTC+8)',
        'Asia/Tokyo' => 'Japan (JST, UTC+9)',
        'Europe/London' => 'UK (GMT/BST, UTC+0/+1)',
        'Europe/Berlin' => 'Central Europe (CET, UTC+1)',
        'America/New_York' => 'US Eastern (EST, UTC-5)',
        'America/Chicago' => 'US Central (CST, UTC-6)',
        'America/Los_Angeles' => 'US Pacific (PST, UTC-8)',
        'Australia/Sydney' => 'Australia Eastern (AEST, UTC+10)',
        'UTC' => 'UTC',
    ];
}

$notifyConnect = getUserSetting($conn, $userId, 'notify_connect', '1');
$notifyDisconnect = getUserSetting($conn, $userId, 'notify_disconnect', '1');
$autoReconnect = getUserSetting($conn, $userId, 'auto_reconnect', '1');
$timezone = getUserSetting($conn, $userId, 'timezone', 'Asia/Karachi');

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

    <div class="card mb-4">
        <div class="card-header">Display</div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Timezone</label>
                <select name="timezone" class="form-select">
                    <?php foreach (timezoneOptions() as $tz => $label): ?>
                        <option value="<?= sanitize($tz) ?>" <?= $timezone === $tz ? 'selected' : '' ?>><?= sanitize($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">All chat timestamps will be displayed in this timezone</div>
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
