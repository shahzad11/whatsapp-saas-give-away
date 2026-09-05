<?php

// Builds the headers that authenticate this frontend to the Node backend.
//
// The tenant id is taken from the PHP session, not from an argument, so a
// caller cannot accidentally (or deliberately) act for a different tenant.
// $tenantId is only ever passed explicitly by maintenance/admin code paths.
function backendHeaders($tenantId = null) {
    $tenantId = $tenantId ?? currentTenantId();

    $headers = [
        'Content-Type: application/json',
        'X-Api-Key: ' . BACKEND_API_KEY,
    ];
    if ($tenantId !== null) {
        $headers[] = 'X-Tenant-Id: ' . $tenantId;
    }
    return $headers;
}

function callBackendApi($method, $path, $data = null, $tenantId = null) {
    $url = BACKEND_URL . $path;
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    // The backend is an internal service on a known hostname; never let it
    // redirect us somewhere else.
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, backendHeaders($tenantId));

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log('Backend call failed: ' . $error);
        return ['ok' => false, 'error' => 'Backend unreachable', 'httpCode' => 0];
    }

    $decoded = json_decode($response, true);
    // A non-JSON body (proxy error page, PHP notice, truncated response) used to
    // produce "Attempt to assign property on null" further down. Fail cleanly.
    if (!is_array($decoded)) {
        error_log("Backend returned non-JSON (HTTP $httpCode) for $path");
        return ['ok' => false, 'error' => 'Invalid backend response', 'httpCode' => $httpCode];
    }
    $decoded['httpCode'] = $httpCode;
    return $decoded;
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function flash($key, $message = null) {
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }

    if (isset($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
    return null;
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf() {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        flash('error', 'Invalid request. Please try again.');
        return false;
    }
    return true;
}

function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Delivers through the admin-configured SMTP server.
//
// This used to call mail(), which could never have worked: the frontend
// container has no MTA, /usr/sbin/sendmail does not exist, and mail() returns
// false. Every activation and password-reset email was silently dropped.
//
// Returns bool for the existing callers; use sendMailNow() directly when the
// failure reason is needed (the admin test-send button does).
function sendEmail($to, $subject, $body, $textBody = '') {
    [$ok] = sendMailNow($to, $subject, $body, $textBody);
    return $ok;
}

// The one function that decides what a chat or sender is called in the UI.
//
// It must never return a JID or an unexplained identifier. WhatsApp uses two
// JID formats and they are not equally useful:
//   <digits>@s.whatsapp.net — the digits are a real phone number
//   <opaque>@lid            — an internal id that resembles a phone number and
//                             is not one; showing it actively misleads
//
// So the order is: known name → phone number in international form → an honest
// placeholder. Returning the raw identifier is never an option.
function chatDisplayName($name, $phone, $jid, $isGroup = false) {
    $name = trim((string)$name);

    // A stored name that is itself an identifier is not a name — older rows
    // were written before this rule existed.
    //
    // The separator class matters: a legacy group JID is
    // `<creator-phone>-<timestamp>@g.us`, so its prefix is digits *and a
    // hyphen* and a digits-only test lets it straight through. The 10-character
    // floor keeps genuinely short numeric names (shortcodes, "786") usable.
    $identifierLike = str_contains($name, '@') || preg_match('/^[\d\s.\-]{10,}$/', $name);

    if ($name !== '' && !$identifierLike) {
        return $name;
    }

    if ($isGroup) {
        // A group's identity is its subject. Without it there is nothing
        // meaningful to show — the JID prefix is a timestamp and a serial.
        return 'Group chat';
    }

    // A phone number is only ever taken from the phone column or from an
    // @s.whatsapp.net JID, never parsed out of the name. An @lid identifier is
    // 14-15 digits, which is a valid E.164 length — deriving a number from one
    // would fabricate a plausible-looking phone number that does not exist.
    $phone = preg_replace('/\D+/', '', (string)$phone);
    if ($phone === '' && str_ends_with((string)$jid, '@s.whatsapp.net')) {
        $phone = preg_replace('/\D+/', '', explode('@', $jid)[0]);
    }
    if ($phone !== '') {
        return '+' . $phone;
    }

    return 'Unknown contact';
}

function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . 'y ago';
    if ($diff->m > 0) return $diff->m . 'mo ago';
    if ($diff->d > 0) return $diff->d . 'd ago';
    if ($diff->h > 0) return $diff->h . 'h ago';
    if ($diff->i > 0) return $diff->i . 'm ago';
    return 'just now';
}

function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

function currentPage() {
    return basename($_SERVER['PHP_SELF'], '.php');
}

// A tenant's own timezone wins; otherwise they inherit the instance default the
// admin set. Previously this fell back to 'UTC' while every other layer assumed
// Asia/Karachi, so a tenant who never opened Settings saw timestamps five hours
// off the rest of the app.
function getUserTimezone($conn, $userId) {
    $stmt = $conn->prepare("SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = 'timezone'");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && isValidTimezone($row['setting_value'])) {
        return $row['setting_value'];
    }
    return appTimezone($conn);
}

function convertToUserTz($utcDatetime, $timezone) {
    if (empty($utcDatetime)) return null;
    try {
        $dt = new DateTime($utcDatetime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($timezone));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return $utcDatetime;
    }
}
