<?php
require_once dirname(__DIR__, 2) . '/config/init.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$sessionId = $_GET['session_id'] ?? '';
$chatId = $_GET['chat_id'] ?? '';

if (empty($sessionId) || empty($chatId)) {
    echo json_encode(['ok' => false, 'error' => 'Missing session_id or chat_id']);
    exit;
}

[$accountId, $tenantId, $userId] = requireOwnedAccount($conn, $sessionId);
$userTz = getUserTimezone($conn, $userId);

$resp = callBackendApi('GET', '/api/v1/wa/sessions/' . urlencode($sessionId) . '/chats/' . urlencode($chatId) . '/messages');

if ($resp && !empty($resp['ok']) && !empty($resp['messages'])) {
    $stmtIns = $conn->prepare("INSERT IGNORE INTO wa_messages
        (account_id, session_id, message_id, chat_id, sender_name, from_me, message_text, media_type, media_mime, media_filename, message_timestamp)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    // Store extra media data (contact/location) in a lookup for the response
    $extraData = [];
    foreach ($resp['messages'] as $msg) {
        $msgId = $msg['id'] ?? '';
        if (empty($msgId)) continue;
        $senderName = $msg['senderName'] ?? null;
        $fromMe = (int)($msg['fromMe'] ?? 0);
        $text = $msg['text'] ?? '';
        $mediaType = $msg['mediaType'] ?? 'text';
        $mediaMime = $msg['mediaMime'] ?? null;
        $mediaFilename = $msg['mediaFilename'] ?? null;
        $msgTime = null;
        if (!empty($msg['time'])) {
            $msgTime = gmdate('Y-m-d H:i:s', strtotime($msg['time']));
        }
        // Types must line up with the 11 columns above:
        // account_id(i) session_id(s) message_id(s) chat_id(s) sender_name(s)
        // from_me(i) message_text(s) media_type(s) media_mime(s)
        // media_filename(s) message_timestamp(s)
        $stmtIns->bind_param("issssisssss", $accountId, $sessionId, $msgId, $chatId, $senderName, $fromMe, $text, $mediaType, $mediaMime, $mediaFilename, $msgTime);
        $stmtIns->execute();

        if (!empty($msg['contactInfo'])) $extraData[$msgId]['contactInfo'] = $msg['contactInfo'];
        if (!empty($msg['locationInfo'])) $extraData[$msgId]['locationInfo'] = $msg['locationInfo'];
    }
    $stmtIns->close();
}

// account_id rather than session_id: the tenant scope lives in the query
// itself, not only in the ownership check above.
$stmtFetch = $conn->prepare("SELECT message_id, sender_name, from_me, message_text, media_type, media_mime, media_filename, message_timestamp
    FROM wa_messages WHERE account_id = ? AND chat_id = ? ORDER BY message_timestamp ASC");
$stmtFetch->bind_param("is", $accountId, $chatId);
$stmtFetch->execute();
$result = $stmtFetch->get_result();
$messages = [];
while ($r = $result->fetch_assoc()) {
    $msgEntry = [
        'id' => $r['message_id'],
        'fromMe' => (bool)$r['from_me'],
        'text' => $r['message_text'] ?: '',
        'mediaType' => $r['media_type'] ?: 'text',
        'mediaMime' => $r['media_mime'],
        'mediaFilename' => $r['media_filename'],
        'senderName' => $r['sender_name'],
        'time' => convertToUserTz($r['message_timestamp'], $userTz)
    ];
    if (isset($extraData[$r['message_id']]['contactInfo'])) $msgEntry['contactInfo'] = $extraData[$r['message_id']]['contactInfo'];
    if (isset($extraData[$r['message_id']]['locationInfo'])) $msgEntry['locationInfo'] = $extraData[$r['message_id']]['locationInfo'];
    $messages[] = $msgEntry;
}
$stmtFetch->close();

echo json_encode(['ok' => true, 'sessionId' => $sessionId, 'messages' => $messages]);
