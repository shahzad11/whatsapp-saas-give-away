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
    if (!$user || $user['status'] === 'suspended' || !$user['is_active']
        // A session minted before a password change, a suspension or a
        // "log out everywhere" is dead even though the cookie still validates:
        // the counter in the row has moved on (#13). A session predating the
        // column has no key at all, which reads as 0 — the same value existing
        // accounts start at — so deploying this does not log everyone out.
        || (int)$user['session_version'] !== (int)($_SESSION['session_version'] ?? 0)) {
        logoutUser();
        flash('error', 'Your session has ended. Please sign in again.');
        redirect(APP_URL . '/login.php');
    }

    requirePasswordChanged($user);
}

// A tenant given a temporary password by an admin (#48) can do exactly one
// thing until they replace it.
//
// Enforced here, on every authenticated request, rather than once at login. A
// temporary password is a secret that an admin has read out or pasted into a
// message, so it is already shared by the time it is first used; checking only
// at the login page would let anything reached by a direct URL — an AJAX
// endpoint, an export — be used with it indefinitely.
//
// The exemptions are the pages needed to comply and to leave. Without them this
// redirects to itself forever.
function requirePasswordChanged($user) {
    if (empty($user['must_change_password'])) return;

    $page = currentPage();
    if (in_array($page, ['set-password', 'logout'], true)) return;

    // An XHR caller cannot follow a 302 to an HTML page in any useful way — it
    // would parse the login form as JSON and report a broken server — so it is
    // told plainly what is wrong and where to go.
    if (function_exists('isXhrRequest') && isXhrRequest()) {
        jsonOut(['ok' => false, 'errors' => [],
                 'message' => 'Set a permanent password before continuing.',
                 'redirect' => APP_URL . '/set-password.php'], 403);
    }

    redirect(APP_URL . '/set-password.php');
}

// The guard every AJAX/JSON endpoint runs instead of a bare isLoggedIn() (#3).
//
// A session cookie proves a login happened; it does not prove the account is
// still usable. Suspension, deactivation and session_version bumps (#13) all
// have to bite on the very next request, not the next login, or a suspended
// tenant's open tabs keep working indefinitely.
//
// Returns the user row on success. On failure it answers and exits:
//   - 401 when there is no session, or the session's owner is gone, suspended,
//     deactivated or superseded by a newer session_version;
//   - 403 when the account still holds only an admin-issued temporary
//     password — the only thing that session may do is set a real one, and the
//     payload says where.
// $json = false is for the endpoints that answer plain text (get-media.php).
function requireActiveUser($json = true) {
    if (!isLoggedIn()) {
        if ($json) {
            jsonOut(['ok' => false, 'error' => 'Unauthorized'], 401);
        }
        http_response_code(401);
        echo 'Unauthorized';
        exit;
    }

    global $conn;
    $user = getCurrentUser();

    $versionMismatch = $user
        && (int)$user['session_version'] !== (int)($_SESSION['session_version'] ?? 0);

    if (!$user || $user['status'] === 'suspended' || !$user['is_active'] || $versionMismatch) {
        // The session is destroyed, not merely refused: leaving it valid would
        // let it outlive a suspension it was created before.
        logoutUser();
        $message = $versionMismatch
            ? 'Your session has ended. Please sign in again.'
            : 'Your account is no longer active.';
        if ($json) {
            jsonOut(['ok' => false, 'error' => $message], 401);
        }
        http_response_code(401);
        echo $message;
        exit;
    }

    if (!empty($user['must_change_password'])) {
        $message = 'Set a permanent password before continuing.';
        if ($json) {
            jsonOut(['ok' => false, 'errors' => [], 'error' => $message,
                     'message' => $message,
                     'redirect' => APP_URL . '/set-password.php'], 403);
        }
        http_response_code(403);
        echo $message;
        exit;
    }

    return $user;
}

function requireActiveUserJson() {
    return requireActiveUser(true);
}

function isAdmin() {
    $user = getCurrentUser();
    return $user && (int)$user['is_admin'] === 1;
}

// Guards every page under /admin/. Reached through includes/admin-init.php so an
// admin page cannot be added without it.
//
// A non-admin gets 302 → dashboard, identical to the pre-existing behaviour. The
// previous http_response_code(403) here was dead code: PHP replaces the status
// with 302 when a Location header is sent unless a 201 or 3xx was already set,
// so it never reached the client and only implied a response this never returns.
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
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
        "SELECT id, name, email, avatar, is_active, is_admin, status, plan_id,
                must_change_password, session_version, pending_email, created_at
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

    // The login query is supposed to carry the value, but a caller that
    // selected fewer columns must not mint a session stamped 0 — the first
    // bump would then kill it for no reason.
    if (isset($user['session_version'])) {
        $version = (int)$user['session_version'];
    } else {
        $stmt = $conn->prepare("SELECT session_version FROM users WHERE id = ?");
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        $version = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
    }
    $_SESSION['session_version'] = $version;

    $stmt = $conn->prepare("UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $stmt->close();
}

// Invalidates every session but, for the caller's own account, keeps this one
// alive (#13).
//
// Session stores (files, database handlers) are opaque to us — there is no
// "delete that user's sessions" that works across drivers. The version counter
// is the portable kill switch: every guard compares the stamp in the session
// against the row, so +1 ends them all at once. When the bump is *self*-initiated
// (a password change, "log out other devices") the caller's session is re-stamped
// with the new value or the user would log themselves out mid-request.
function bumpSessionVersion(mysqli $conn, $userId) {
    $userId = (int)$userId;
    $stmt = $conn->prepare("UPDATE users SET session_version = session_version + 1 WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT session_version FROM users WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $new = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();

    if ((int)($_SESSION['user_id'] ?? 0) === $userId) {
        $_SESSION['session_version'] = $new;
    }
    return $new;
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

// The one question login.php asks before verifying a password (#2, #27).
//
// Two separate limits, because they protect against different things:
//
//   'ip'    — hard block. A single source spraying failures at many accounts
//             is throttled outright for the whole window. The ceiling is much
//             higher than the per-email one because a shared office or carrier
//             NAT address is many people at once; hitting it has to mean a
//             flood, not a bad Monday.
//   'email' — progressive delay, not a lockout. Once failures pass
//             loginMaxAttempts() each further attempt must wait a little
//             longer than the last (2s, 4s, 8s … capped at 60s). A flat
//             15-minute block let anyone lock a victim out by typing their
//             email (#27); an exponential pause costs an attacker the same
//             attempts but costs the victim nothing they can feel.
//
// A browser holding a valid known-device cookie for that account skips the
// per-email counter entirely — the failures are almost certainly the owner
// mistyping, and a device that has already logged in proves nothing by
// succeeding again.
//
// Times are asked of MySQL (UTC_TIMESTAMP), the same clock that writes
// created_at, so PHP clock drift cannot stretch or shrink a window.
function loginThrottle(mysqli $conn, $email) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $window = loginLockoutMinutes($conn);

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS n,
                TIMESTAMPDIFF(SECOND, MIN(created_at), UTC_TIMESTAMP()) AS oldest_age
         FROM login_attempts
         WHERE success = 0 AND ip_address = ?
           AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)"
    );
    $stmt->bind_param('si', $ip, $window);
    $stmt->execute();
    $ipRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ((int)($ipRow['n'] ?? 0) >= loginMaxAttemptsPerIp($conn)) {
        $wait = max(0, $window * 60 - (int)($ipRow['oldest_age'] ?? 0));
        return ['blocked' => true, 'reason' => 'ip', 'wait_seconds' => $wait];
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS n,
                TIMESTAMPDIFF(SECOND, MAX(created_at), UTC_TIMESTAMP()) AS last_age
         FROM login_attempts
         WHERE success = 0 AND email = ?
           AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)"
    );
    $stmt->bind_param('si', $email, $window);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $n = (int)($row['n'] ?? 0);
    if ($n < loginMaxAttempts($conn) || knownDeviceValid($conn, $email)) {
        return ['blocked' => false, 'reason' => null, 'wait_seconds' => 0];
    }

    $delay = min(60, 2 ** ($n - loginMaxAttempts($conn) + 1));
    $since = (int)($row['last_age'] ?? $delay);
    if ($since < $delay) {
        return ['blocked' => true, 'reason' => 'email', 'wait_seconds' => $delay - $since];
    }
    return ['blocked' => false, 'reason' => null, 'wait_seconds' => 0];
}

// Sends the "several failed sign-in attempts" email exactly once per burst:
// when the counter reaches the threshold, not on every failure past it.
// Best effort by construction — a mail failure must never break a login page.
function loginAlertThresholdReached(mysqli $conn, $email) {
    $window = loginLockoutMinutes($conn);
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE success = 0 AND email = ?
           AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)"
    );
    $stmt->bind_param('si', $email, $window);
    $stmt->execute();
    $n = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();

    if ($n !== loginMaxAttempts($conn) || !smtpConfigured($conn)) return;

    $stmt = $conn->prepare("SELECT name FROM users WHERE email = ?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) return;

    try {
        [$html, $text] = mailFailedLoginAlert($user['name'] ?? '', $email);
        sendEmail($email, 'Failed sign-in attempts on your account', $html, $text);
    } catch (Throwable $e) {
        error_log('failed-login alert could not be sent: ' . $e->getMessage());
    }
}

function clearLoginAttempts(mysqli $conn, $email) {
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE email = ? AND success = 0");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->close();
}

// --- Known-device cookie ----------------------------------------------------
//
// "Has this browser ever signed in as this account?" — the bypass for the
// progressive per-email delay above (#27). A cookie is the right store because
// the alternative (remembering IPs) dies the first time someone logs in from a
// new network, and a database token store is a second session table to expire.
//
// The value is `userId.nonce.hmac`. The MAC covers the user's session_version,
// so a password change or a force-logout revokes every known device at once —
// the same counter that kills the sessions kills the bypass, which is the
// point: a stolen cookie must not keep skipping the throttle after the owner
// has reacted.
//
// The key is a subkey of the instance secret (crypto.php) so it never collides
// with the encryption keys, and so a rotation of APP_SECRET_KEY merely forgets
// known devices rather than corrupting stored data.
const KNOWN_DEVICE_COOKIE = 'wa_kd';
const KNOWN_DEVICE_DAYS = 180;

function knownDeviceKey() {
    $secret = cryptoInstanceSecret();
    if ($secret === null) return null;
    return hash_hmac('sha256', 'known-device', $secret);
}

function knownDeviceMac($userId, $nonce, $sessionVersion) {
    $key = knownDeviceKey();
    if ($key === null) return null;
    return hash_hmac('sha256', $userId . '|' . $nonce . '|' . (int)$sessionVersion, $key);
}

// Issued on a successful login. No secret is recoverable from the cookie — the
// nonce is meaningless without the keyed MAC over it.
function setKnownDeviceCookie(mysqli $conn, $userId) {
    $userId = (int)$userId;
    $stmt = $conn->prepare("SELECT session_version FROM users WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $version = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();

    $nonce = bin2hex(random_bytes(16));
    $mac = knownDeviceMac($userId, $nonce, $version);
    if ($mac === null) return;   // no instance secret: throttle stays strict

    setcookie(KNOWN_DEVICE_COOKIE, $userId . '.' . $nonce . '.' . $mac, [
        'expires'  => time() + KNOWN_DEVICE_DAYS * 86400,
        'path'     => '/',
        'secure'   => SESSION_SECURE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Does this browser carry a currently-valid known-device cookie for $email?
function knownDeviceValid(mysqli $conn, $email) {
    $raw = (string)($_COOKIE[KNOWN_DEVICE_COOKIE] ?? '');
    $parts = explode('.', $raw);
    if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_xdigit($parts[1]) || !ctype_xdigit($parts[2])) {
        return false;
    }
    [$userId, $nonce, $mac] = $parts;

    $stmt = $conn->prepare("SELECT session_version FROM users WHERE id = ? AND email = ?");
    $id = (int)$userId;
    $stmt->bind_param('is', $id, $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;

    $expected = knownDeviceMac($id, $nonce, (int)$row['session_version']);
    return $expected !== null && hash_equals($expected, $mac);
}

// --- Password policy --------------------------------------------------------
//
// One rule, one place (#24): the three forms that set a password used to carry
// their own copy of "8 or more characters", which is how the rule drifted.
// Length plus a blocklist is the current NIST guidance — no "must contain a
// symbol" rules, which produce `Password1!` and nothing else.

// The ~10k most common passwords, shipped as a text file in includes/ (which
// the vhost denies). Loaded lazily and kept as a hash set — this runs on a
// form submit, not per row.
function commonPasswords() {
    static $set = null;
    if ($set !== null) return $set;

    $set = [];
    $path = __DIR__ . '/common-passwords.txt';
    if (is_readable($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $set[strtolower(trim($line))] = true;
        }
    }
    return $set;
}

// null when the password is acceptable, otherwise the sentence to show.
// $context carries the owner's 'email' and 'name' so `jane@…` cannot become
// `Jane2024secure` — credentials derived from public facts are the ones
// credential stuffing finds first.
function passwordProblem($password, array $context = []) {
    if (strlen($password) < 10) {
        return 'Use at least 10 characters.';
    }
    // bcrypt reads at most 72 bytes and silently drops the rest — accepting a
    // longer password stores a secret that is not the one the user typed.
    if (strlen($password) > 72) {
        return 'Use at most 72 characters.';
    }

    $lower = strtolower($password);
    if (isset(commonPasswords()[$lower])) {
        return 'That password is too common — choose a longer, less predictable one.';
    }

    // Identity-derived passwords are the first thing credential stuffing
    // tries. For the name, each word is checked too — "Sadia Khan" must reject
    // `khan…`, not only `sadiakhan…`.
    $pieces = [];
    $local = strtolower(trim((string)($context['email'] ?? '')));
    if (str_contains($local, '@')) $local = explode('@', $local)[0];
    if ($local !== '') $pieces['your email address'] = [$local];
    $name = strtolower(trim((string)($context['name'] ?? '')));
    if ($name !== '') {
        $pieces['your name'] = array_merge([$name], preg_split('/\s+/', $name));
    }

    foreach ($pieces as $what => $candidates) {
        foreach ($candidates as $piece) {
            // Under 4 characters the check rejects more than it protects: "ali"
            // appears inside thousands of fine passwords.
            if (strlen($piece) >= 4 && str_contains($lower, $piece)) {
                return 'The password must not contain ' . $what . '.';
            }
            if ($piece === $lower && strlen($piece) > 0 && strlen($piece) < 4) {
                return 'The password must not be ' . $what . '.';
            }
        }
    }

    return null;
}
