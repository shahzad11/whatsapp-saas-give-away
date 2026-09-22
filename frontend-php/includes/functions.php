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

// $timeout is a parameter because one caller legitimately needs longer than the
// rest: an attachment send waits for the backend to upload the file to
// WhatsApp's media servers. Timing that out would be the worst outcome — the
// message may already have been sent, and the frontend would report a failure
// and skip counting it.
function callBackendApi($method, $path, $data = null, $tenantId = null, $timeout = 30) {
    $url = BACKEND_URL . $path;
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
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

function helpTip(string $text): string {
    $t = sanitize($text);
    return '<button type="button" class="help-tip" data-bs-toggle="tooltip" data-bs-placement="top" '
        . 'data-bs-title="' . $t . '" aria-label="' . $t . '"><i class="bi bi-question-circle"></i></button>';
}

// The single comparison every CSRF check goes through, including the JSON and
// multipart endpoints that cannot use verifyCsrf() because their token does not
// arrive in $_POST.
//
// The empty check is the point. `hash_equals($_SESSION['csrf_token'] ?? '', '')`
// is **true** when the session has not minted a token yet, so the obvious
// one-liner accepts a request that sends no token at all. That is reachable: a
// session exists from the first request, but `csrf_token` is only set once
// something calls csrfToken(). Requiring both sides to be non-empty closes it.
function csrfTokenValid($token) {
    $expected = $_SESSION['csrf_token'] ?? '';
    $token = is_string($token) ? $token : '';
    return $expected !== '' && $token !== '' && hash_equals($expected, $token);
}

function verifyCsrf() {
    if (!csrfTokenValid($_POST['csrf_token'] ?? '')) {
        flash('error', 'Invalid request. Please try again.');
        return false;
    }
    return true;
}

function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// E.164 in the only form worth storing: digits, no punctuation, a country code
// first. Returns '' for "no number given", `false` for "that is not a number",
// and the digits otherwise — so a caller can tell a blank field from a typo,
// which are very different answers.
//
// The leading '+' is presentation and is added back on the way out, because a
// stored '+' would have to be stripped again at every use site (a WhatsApp JID
// has no plus, and neither does a wa.me link) and one of those sites would
// eventually forget.
//
// 8 is the shortest real international number (a few small countries); 15 is
// E.164's own maximum. A local number written with a trunk prefix
// ("03001234567") is the most likely mistake of all, and it cannot be corrected
// without knowing the country — so it is refused rather than guessed at. Refuse,
// never normalise into something unreachable: a number that looks saved and can
// never be dialled or messaged is the worst outcome available.
//
// Lives here rather than in one feature's file because two features now depend
// on the same rule — handover alerts (#26) and the billing sales contact (#43) —
// and a second copy is a second set of edge cases.
function e164Digits($raw) {
    $digits = preg_replace('/\D+/', '', (string)$raw);
    if ($digits === '') return '';
    if ($digits[0] === '0') return false;
    return (strlen($digits) >= 8 && strlen($digits) <= 15) ? $digits : false;
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

// Turns a failed backend call into something a tenant can act on.
//
// The backend's own error strings are written for whoever reads the logs —
// "Backend unreachable", "Invalid backend response", "Failed to create session".
// A tenant does not know what a backend is, and none of those say what to do
// next. Exactly one backend error is genuinely useful to them, the rate
// limiter's "Try again in Ns", so that one is passed through and everything else
// becomes one sentence. The real reason still goes to the error log, which is
// where it was always meant to be read.
function waBackendErrorMessage($resp, $context = 'wa') {
    $code = (int)($resp['httpCode'] ?? 0);
    $raw = (string)($resp['error'] ?? '');

    if ($code === 429 && $raw !== '') {
        return $raw;
    }

    error_log("Backend failure shown to tenant as a generic message [$context]: HTTP $code $raw");

    return $code === 0
        ? 'Could not reach WhatsApp right now. Please try again in a few minutes — if it keeps happening, contact support.'
        : 'Something went wrong on our side. Please try again in a few minutes — if it keeps happening, contact support.';
}

// The backend's session status is an internal state name — 'qr_required',
// 'logged_out', 'failed'. Those are the names the code reasons about; they are
// not sentences, and a tenant reading "logged_out" learns nothing about what to
// do next. Every place that renders a status goes through these three functions
// so the vocabulary is defined once: a label, a badge class, and (for the admin
// health view) what the operator is supposed to do about it.
//
// An unrecognised status still renders: a new backend state must degrade to
// something readable rather than to a blank badge.
const WA_STATUS_LABELS = [
    'qr_required'  => 'Scan QR code',
    'connected'    => 'Connected',
    'authenticated'=> 'Connecting…',
    'reconnecting' => 'Reconnecting…',
    'disconnected' => 'Disconnected',
    'logged_out'   => 'Logged out — re-link needed',
    'failed'       => 'Connection failed — re-link needed',
];

function waStatusLabel($status) {
    $status = (string)$status;
    return WA_STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status ?: 'unknown'));
}

function waStatusClass($status) {
    switch ((string)$status) {
        case 'connected':    return 'badge-connected';
        case 'authenticated':
        case 'reconnecting': return 'badge-reconnecting';
        case 'qr_required':  return 'badge-qr_required';
        case 'logged_out':
        case 'failed':       return 'badge-failed';
        default:             return 'badge-disconnected';
    }
}

// 'disconnected' is deliberately absent: the backend retries it on a backoff and
// it usually recovers on its own, so offering a re-link there would send tenants
// through a QR scan they did not need.
function waStatusNeedsRelink($status) {
    return in_array((string)$status, ['qr_required', 'logged_out', 'failed'], true);
}

function waStatusHint($status) {
    switch ((string)$status) {
        case 'qr_required':
            return 'Waiting for someone to scan the QR code.';
        case 'logged_out':
            return 'The phone unlinked this device. The tenant has to re-link and scan a new QR code.';
        case 'failed':
            return 'Connecting failed repeatedly. The tenant has to re-link and scan a new QR code.';
        case 'disconnected':
            return 'Reconnecting automatically. If it stays here, the tenant should re-link.';
        default:
            return '';
    }
}

// Writes the backend's view of one session into wa_accounts. Shared by the
// "Sync All" POST and waRefreshAccountIdentity() so the write exists once.
function waApplyBackendStatus(mysqli $conn, int $userId, array $acc, array $resp): void {
    $status = $resp['status'] ?? 'disconnected';
    $phone = $resp['user']['id'] ?? null;
    $pushName = $resp['user']['name'] ?? null;
    $connAt = $resp['connectedAt'] ?? null;
    if ($connAt) {
        $connAt = date('Y-m-d H:i:s', strtotime($connAt));
    }
    if ($phone) {
        $phone = explode(':', $phone)[0] ?? $phone;
    }

    $stmt = $conn->prepare("UPDATE wa_accounts SET status = ?, phone_number = ?, push_name = ?, connected_at = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ssssii", $status, $phone, $pushName, $connAt, $acc['id'], $userId);
    $stmt->execute();
    $stmt->close();
}

// Pulls status / phone / push name / connected_at from the backend for the
// rows that have none, so a connected account never shows "-" for its own
// number. Bounded: only rows missing data, only when the backend answers.
function waRefreshAccountIdentity(mysqli $conn, int $userId, int $maxRows = 10): void {
    $stmt = $conn->prepare(
        "SELECT id, session_id FROM wa_accounts
         WHERE user_id = ? AND status = 'connected' AND provider = 'baileys'
           AND (phone_number IS NULL OR phone_number = '' OR connected_at IS NULL)
         LIMIT " . max(1, $maxRows)
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $acc) {
        $resp = callBackendApi('GET', '/api/v1/wa/sessions/' . urlencode($acc['session_id']) . '/status', null, null, 3);
        if ($resp && ($resp['ok'] ?? false)) {
            waApplyBackendStatus($conn, $userId, $acc, $resp);
        }
    }
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

// Renders a UTC *instant* in the tenant's timezone with a date() format string.
//
// Only for instants — TIMESTAMP and DATETIME columns. A DATE column (payments'
// period_start / period_end) is a calendar date, not a moment: shifting it by an
// offset would move a billing period onto the wrong day, so those are formatted
// without conversion on purpose.
function formatUserDate($utcDatetime, $timezone, $format = 'M j, Y') {
    if (empty($utcDatetime)) return '';
    $local = convertToUserTz($utcDatetime, $timezone);
    $ts = strtotime((string)$local);
    return $ts === false ? '' : date($format, $ts);
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
