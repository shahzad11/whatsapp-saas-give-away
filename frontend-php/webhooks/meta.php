<?php
// Public endpoint for Meta's WhatsApp Cloud API webhooks (Phase 27).
//
// No session and no login: Meta is the caller. Authentication is the
// per-account webhook key in the URL (GET verification) and the
// X-Hub-Signature-256 HMAC keyed by that account's app secret (POST). The URL
// is only ever shown to the tenant who owns the account, inside Meta's
// dashboard instructions.
require_once dirname(__DIR__) . '/config/init.php';

// The key is what makes each account's callback URL unguessable; anything
// else gets a flat 404 rather than a hint about the endpoint's shape.
$key = $_GET['key'] ?? '';
if (!is_string($key) || !preg_match('/^[a-f0-9]{1,64}$/', $key)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$stmt = $conn->prepare("SELECT * FROM wa_accounts WHERE cloud_webhook_key = ? AND provider = 'cloud'");
$stmt->bind_param('s', $key);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$account) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

// --- Verification handshake -------------------------------------------------
// Meta calls GET with hub.mode=subscribe, hub.verify_token and
// hub.challenge when the tenant saves the callback URL. PHP turns the dots in
// the parameter names into underscores.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['hub_mode'] ?? '') === 'subscribe'
        && hash_equals((string)$account['cloud_verify_token'], (string)($_GET['hub_verify_token'] ?? ''))) {
        http_response_code(200);
        header('Content-Type: text/plain');
        echo $_GET['hub_challenge'] ?? '';
    } else {
        http_response_code(403);
    }
    exit;
}

// --- Delivery ---------------------------------------------------------------
$raw = file_get_contents('php://input');

$creds = cloudCreds($account);
if ($creds['app_secret'] === null) {
    // Without the app secret a signature cannot be checked, and accepting
    // unsigned "messages" would let anyone inject a chat. Refuse loudly.
    http_response_code(500);
    echo 'Cloud API secret unavailable';
    exit;
}

if (!cloudVerifySignature($raw, $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '', $creds['app_secret'])) {
    http_response_code(401);
    echo 'Invalid signature';
    exit;
}

$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

// Answer Meta immediately and keep working. Meta retries a delivery that is
// not acknowledged in a few seconds, and a reply pass (model call + send) can
// take far longer than that — so the 200 goes out first and the work happens
// behind a closed connection. mod_php has no fastcgi_finish_request(), so the
// response is ended by hand: Content-Length pins it to two bytes and the
// buffers are drained so Apache can release the client.
// mod_deflate compresses text/plain and strips Content-Length, which holds
// the connection open past Meta's ack window — gzip must be off for the OK.
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
http_response_code(200);
header('Connection: close');
header('Content-Type: text/plain');
header('Content-Length: 2');
echo 'OK';
while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();
set_time_limit(180);
ignore_user_abort(true);

$in = cloudExtractInbound($payload, (string)$account['cloud_phone_number_id']);

if (empty($in['messages'])) {
    // Status receipts and changes for other phone numbers need no work.
    exit;
}

$accountId = (int)$account['id'];
$userId = (int)$account['user_id'];
$sessionId = $account['session_id'];

// Same contact cap as get-chats.php: reaching the limit stops *growth* only —
// a chat we already know keeps updating, a new one is not recorded, and the
// message itself is still stored either way.
[, $contactsUsed, $contactLimit] = checkContactQuota($conn, $userId);

$stmtKnown = $conn->prepare("SELECT 1 FROM wa_contacts WHERE account_id = ? AND chat_id = ?");
$stmtUpsert = $conn->prepare("INSERT INTO wa_contacts (account_id, session_id, chat_id, contact_name, phone_number, last_message, last_message_time, is_group, is_archived)
    VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0)
    ON DUPLICATE KEY UPDATE
    contact_name = COALESCE(VALUES(contact_name), contact_name),
    phone_number = COALESCE(VALUES(phone_number), phone_number),
    last_message = CASE
        WHEN VALUES(last_message_time) IS NULL THEN last_message
        WHEN last_message_time IS NULL OR VALUES(last_message_time) >= last_message_time THEN VALUES(last_message)
        ELSE last_message
    END,
    last_message_time = CASE
        WHEN VALUES(last_message_time) IS NULL THEN last_message_time
        WHEN last_message_time IS NULL OR VALUES(last_message_time) >= last_message_time THEN VALUES(last_message_time)
        ELSE last_message_time
    END");
$stmtIns = $conn->prepare("INSERT IGNORE INTO wa_messages
    (account_id, session_id, message_id, chat_id, sender_name, sender_jid, from_me, message_text, media_type, media_mime, media_filename, message_timestamp, media_meta)
    VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)");

$mediaLabels = [
    'image' => '[Photo]', 'video' => '[Video]', 'audio' => '[Audio]',
    'voice' => '[Voice message]', 'document' => '[Document]',
    'sticker' => '[Sticker]', 'location' => '[Location]', 'contact' => '[Contact]',
];

foreach ($in['messages'] as $m) {
    try {
        $chatId = $m['chatId'];

        $stmtKnown->bind_param('is', $accountId, $chatId);
        $stmtKnown->execute();
        $known = (bool)$stmtKnown->get_result()->fetch_row();

        if ($contactLimit === null || $known || $contactsUsed < $contactLimit) {
            if (!$known) $contactsUsed++;
            $lastMsg = $m['text'] !== '' ? $m['text'] : ($mediaLabels[$m['mediaType']] ?? '[Message]');
            $stmtUpsert->bind_param('issssss', $accountId, $sessionId, $chatId,
                $m['senderName'], $m['phone'], $lastMsg, $m['timestamp']);
            $stmtUpsert->execute();
        }

        $metaJson = $m['mediaMeta'] ? json_encode($m['mediaMeta']) : null;
        $stmtIns->bind_param('isssssssssss', $accountId, $sessionId, $m['messageId'], $chatId,
            $m['senderName'], $chatId, $m['text'], $m['mediaType'], $m['mediaMime'],
            $m['mediaFilename'], $m['timestamp'], $metaJson);
        $stmtIns->execute();

        // affected_rows 0 means the (session_id, message_id) unique key was
        // already there — Meta redelivers on a slow ack, and a duplicate must
        // not be answered twice.
        if ($stmtIns->affected_rows === 0) {
            continue;
        }

        // Reactions and unsupported types are stored for the thread but are
        // not something to answer.
        if ($m['mediaType'] === 'text' && trim($m['text']) === '') {
            continue;
        }

        chatbotHandleInbound($conn, [
            'sessionId' => $sessionId,
            'chatId' => $chatId,
            'messageId' => $m['messageId'],
            'text' => $m['text'],
            'mediaType' => $m['mediaType'],
            'fromMe' => false,
            'isGroup' => false,
            'archived' => false,
            'isSelfChat' => false,
        ]);
    } catch (Throwable $e) {
        // One bad message must not take the rest of the batch down — Meta
        // would retry the whole payload otherwise.
        error_log('meta webhook message failed: ' . $e->getMessage());
        continue;
    }
}

$stmtIns->close();
$stmtUpsert->close();
$stmtKnown->close();

// A message that arrived proves the connection is alive — it was signed with
// this account's own secret — so a stale "credentials failed" note left by an
// earlier send attempt is cleared rather than left to mislead.
$stmt = $conn->prepare("UPDATE wa_accounts SET cloud_last_error = NULL, status = 'connected'
    WHERE id = ? AND (status <> 'connected' OR cloud_last_error IS NOT NULL)");
$stmt->bind_param('i', $accountId);
$stmt->execute();
$stmt->close();
