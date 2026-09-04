<?php

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        flash('error', 'Please log in to continue.');
        redirect(APP_URL . '/login.php');
    }

    // A tenant suspended mid-session must lose access immediately, not at the
    // next login. Checked on every authenticated request.
    global $conn;
    $user = getCurrentUser();
    if (!$user || $user['status'] === 'suspended' || !$user['is_active']) {
        logoutUser();
        flash('error', 'Your account is no longer active. Please contact support.');
        redirect(APP_URL . '/login.php');
    }
}

function isAdmin() {
    $user = getCurrentUser();
    return $user && (int)$user['is_admin'] === 1;
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        http_response_code(403);
        flash('error', 'You do not have access to that area.');
        redirect(APP_URL . '/dashboard.php');
    }
}

function requireGuest() {
    if (isLoggedIn()) {
        redirect(APP_URL . '/dashboard.php');
    }
}

function getCurrentUser() {
    static $cached = null;
    if ($cached !== null) return $cached;
    if (!isLoggedIn()) return null;

    global $conn;
    $id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare(
        "SELECT id, name, email, avatar, is_active, is_admin, status, plan_id, created_at
         FROM users WHERE id = ?"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    $cached = $user;
    return $user;
}

function loginUser($user) {
    // Rotate the session id on privilege change, otherwise an attacker who can
    // set a victim's pre-login cookie keeps a valid authenticated session
    // (session fixation).
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];

    global $conn;
    $stmt = $conn->prepare("UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $stmt->close();
}

function logoutUser() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

// --- Login throttling -------------------------------------------------------
// Counts recent failures for the email and for the source IP independently, so
// neither a targeted account nor a spraying host can brute force.

function recordLoginAttempt(mysqli $conn, $email, $success) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $flag = $success ? 1 : 0;
    $stmt = $conn->prepare("INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, ?)");
    $stmt->bind_param('ssi', $email, $ip, $flag);
    $stmt->execute();
    $stmt->close();
}

function isLoginBlocked(mysqli $conn, $email) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $window = LOGIN_LOCKOUT_MINUTES;
    $max = LOGIN_MAX_ATTEMPTS;

    $stmt = $conn->prepare(
        "SELECT
            SUM(email = ?) AS by_email,
            SUM(ip_address = ?) AS by_ip
         FROM login_attempts
         WHERE success = 0
           AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)
           AND (email = ? OR ip_address = ?)"
    );
    $stmt->bind_param('ssiss', $email, $ip, $window, $email, $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($row['by_email'] ?? 0) >= $max) || ((int)($row['by_ip'] ?? 0) >= $max);
}

function clearLoginAttempts(mysqli $conn, $email) {
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE email = ? AND success = 0");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->close();
}
