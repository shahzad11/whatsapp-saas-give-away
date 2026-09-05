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

// High-water mark for the incremental fetch. This poll runs every 5s, and it
// used to ask for the entire thread and re-run the upsert over every row —
// thousands of pointless writes per poll on a chat near the backend's
// MAX_MESSAGES_PER_CHAT (5,000).
//
// The mark is read from MySQL rather than kept in the session: it is one
// indexed MAX() over (account_id, chat_id), it cannot drift out of sync with
// what is actually stored, and it survives a new PHP worker or a second tab.
//
// needs_backfill counts rows stored before media_meta existed. Those messages
// have no payload to render, and the incremental fetch would never look far
// enough back to recover one — so a chat containing any of them takes one more
// full fetch, which populates the column and lets every later poll go
// incremental. Self-healing, and it costs one extra fetch per affected chat
// rather than a migration that cannot reach data only the backend holds.
$stmtMark = $conn->prepare(
    "SELECT MAX(message_timestamp) AS mark,
            SUM(media_type IN ('contact', 'location') AND media_meta IS NULL) AS needs_backfill
     FROM wa_messages WHERE account_id = ? AND chat_id = ?"
);
$stmtMark->bind_param("is", $accountId, $chatId);
$stmtMark->execute();
$markRow = $stmtMark->get_result()->fetch_assoc() ?: [];
$mark = $markRow['mark'] ?? null;
$needsBackfill = (int)($markRow['needs_backfill'] ?? 0) > 0;
$stmtMark->close();

// Deliberately rewound by a minute. message_timestamp has second granularity,
// so `> mark` alone would drop a message that arrived in the same second as the
// newest stored one. Overlapping re-upserts a handful of recent rows — which
// also keeps the sender_name refresh below working for them — while still
// replacing thousands of writes with a few.
$sinceParam = '';
if ($mark !== null && !$needsBackfill) {
    $sinceMs = (strtotime($mark . ' UTC') - 60) * 1000;
    if ($sinceMs > 0) $sinceParam = '?since=' . $sinceMs;
}

$resp = callBackendApi('GET', '/api/v1/wa/sessions/' . urlencode($sessionId) . '/chats/' . urlencode($chatId) . '/messages' . $sinceParam);

if ($resp && !empty($resp['ok']) && !empty($resp['messages'])) {
    // INSERT IGNORE skips a row that already exists, so a sender name resolved
    // after the fact would never land. The name is therefore also refreshed on
    // duplicate — but only ever to a *better* value, never back to NULL.
    $stmtIns = $conn->prepare("INSERT INTO wa_messages
        (account_id, session_id, message_id, chat_id, sender_name, sender_jid, from_me, message_text, media_type, media_mime, media_filename, message_timestamp, media_meta)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        sender_name = COALESCE(VALUES(sender_name), sender_name),
        sender_jid = COALESCE(VALUES(sender_jid), sender_jid),
        media_meta = COALESCE(VALUES(media_meta), media_meta)");

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
        // The shared-contact / location payload, persisted rather than
        // re-attached from each response. NULL when there is none, so the
        // COALESCE above cannot blank a stored payload if a later poll happens
        // to report the message without it.
        $meta = [];
        if (!empty($msg['contactInfo'])) $meta['contactInfo'] = $msg['contactInfo'];
        if (!empty($msg['locationInfo'])) $meta['locationInfo'] = $msg['locationInfo'];
        $mediaMeta = $meta ? json_encode($meta) : null;

        // Types must line up with the 13 columns above:
        // account_id(i) session_id(s) message_id(s) chat_id(s) sender_name(s)
        // sender_jid(s) from_me(i) message_text(s) media_type(s) media_mime(s)
        // media_filename(s) message_timestamp(s) media_meta(s)
        $stmtIns->bind_param("isssssissssss", $accountId, $sessionId, $msgId, $chatId, $senderName, $senderJid, $fromMe, $text, $mediaType, $mediaMime, $mediaFilename, $msgTime, $mediaMeta);
        $stmtIns->execute();
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
           m.media_meta,
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
    // From the stored column, so a contact or location message renders the same
    // whether or not this poll happened to fetch it. It used to come from the
    // live backend response, which only worked while every poll re-fetched the
    // whole thread.
    if (!empty($r['media_meta'])) {
        $meta = json_decode($r['media_meta'], true);
        if (is_array($meta)) {
            if (!empty($meta['contactInfo'])) $msgEntry['contactInfo'] = $meta['contactInfo'];
            if (!empty($meta['locationInfo'])) $msgEntry['locationInfo'] = $meta['locationInfo'];
        }
    }
    $messages[] = $msgEntry;
}
$stmtFetch->close();

echo json_encode(['ok' => true, 'sessionId' => $sessionId, 'messages' => $messages]);
