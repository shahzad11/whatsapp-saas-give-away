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
    // INSERT IGNORE skips a row that already exists, so a sender name resolved
    // after the fact would never land. The name is therefore also refreshed on
    // duplicate — but only ever to a *better* value, never back to NULL.
    $stmtIns = $conn->prepare("INSERT INTO wa_messages
        (account_id, session_id, message_id, chat_id, sender_name, sender_jid, from_me, message_text, media_type, media_mime, media_filename, message_timestamp)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        sender_name = COALESCE(VALUES(sender_name), sender_name),
        sender_jid = COALESCE(VALUES(sender_jid), sender_jid)");

    // Store extra media data (contact/location) in a lookup for the response
    $extraData = [];
    foreach ($resp['messages'] as $msg) {
        $msgId = $msg['id'] ?? '';
        if (empty($msgId)) continue;
        $senderName = $msg['senderName'] ?? null;
        $senderJid = $msg['senderJid'] ?? null;
        // A JID or bare identifier is not a name. Store NULL so the read path
        // can fall back to the phone number instead of showing an @lid.
        if ($senderName !== null && (str_contains($senderName, '@') || preg_match('/^\d{10,}$/', $senderName))) {
            $senderName = null;
        }
        $fromMe = (int)($msg['fromMe'] ?? 0);
        $text = $msg['text'] ?? '';
        $mediaType = $msg['mediaType'] ?? 'text';
        $mediaMime = $msg['mediaMime'] ?? null;
        $mediaFilename = $msg['mediaFilename'] ?? null;
        $msgTime = null;
        if (!empty($msg['time'])) {
            $msgTime = gmdate('Y-m-d H:i:s', strtotime($msg['time']));
        }
        // Types must line up with the 12 columns above:
        // account_id(i) session_id(s) message_id(s) chat_id(s) sender_name(s)
        // sender_jid(s) from_me(i) message_text(s) media_type(s) media_mime(s)
        // media_filename(s) message_timestamp(s)
        $stmtIns->bind_param("isssssisssss", $accountId, $sessionId, $msgId, $chatId, $senderName, $senderJid, $fromMe, $text, $mediaType, $mediaMime, $mediaFilename, $msgTime);
        $stmtIns->execute();

        if (!empty($msg['contactInfo'])) $extraData[$msgId]['contactInfo'] = $msg['contactInfo'];
        if (!empty($msg['locationInfo'])) $extraData[$msgId]['locationInfo'] = $msg['locationInfo'];
    }
    $stmtIns->close();
}

// account_id rather than session_id: the tenant scope lives in the query
// itself, not only in the ownership check above.
$isGroupChat = str_ends_with($chatId, '@g.us');

// LEFT JOIN wa_contacts on the sender's JID: if that participant also has a
// 1:1 chat, its resolved name is better than anything stored on the message.
// Scoped by account_id so it cannot read another tenant's contacts.
$stmtFetch = $conn->prepare("SELECT m.message_id, m.sender_name, m.sender_jid, m.from_me,
           m.message_text, m.media_type, m.media_mime, m.media_filename, m.message_timestamp,
           c.contact_name AS sender_contact_name, c.phone_number AS sender_phone
    FROM wa_messages m
    LEFT JOIN wa_contacts c ON c.account_id = m.account_id AND c.chat_id = m.sender_jid
    WHERE m.account_id = ? AND m.chat_id = ? ORDER BY m.message_timestamp ASC");
$stmtFetch->bind_param("is", $accountId, $chatId);
$stmtFetch->execute();
$result = $stmtFetch->get_result();
$messages = [];
while ($r = $result->fetch_assoc()) {
    // Only group threads label senders — WhatsApp does the same, because in a
    // 1:1 chat the sender is implied by which side the bubble is on.
    $sender = null;
    if ($isGroupChat && !$r['from_me']) {
        $sender = chatDisplayName(
            $r['sender_contact_name'] ?: $r['sender_name'],
            $r['sender_phone'],
            $r['sender_jid'] ?: '',
            false
        );
    }

    $msgEntry = [
        'id' => $r['message_id'],
        'fromMe' => (bool)$r['from_me'],
        'text' => $r['message_text'] ?: '',
        'mediaType' => $r['media_type'] ?: 'text',
        'mediaMime' => $r['media_mime'],
        'mediaFilename' => $r['media_filename'],
        'senderName' => $sender,
        'time' => convertToUserTz($r['message_timestamp'], $userTz)
    ];
    if (isset($extraData[$r['message_id']]['contactInfo'])) $msgEntry['contactInfo'] = $extraData[$r['message_id']]['contactInfo'];
    if (isset($extraData[$r['message_id']]['locationInfo'])) $msgEntry['locationInfo'] = $extraData[$r['message_id']]['locationInfo'];
    $messages[] = $msgEntry;
}
$stmtFetch->close();

echo json_encode(['ok' => true, 'sessionId' => $sessionId, 'messages' => $messages]);
