<?php
require_once dirname(__DIR__, 2) . '/config/init.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$sessionId = $_GET['session_id'] ?? '';
if (empty($sessionId)) {
    echo json_encode(['ok' => false, 'error' => 'Missing session_id']);
    exit;
}

[$accountId, $tenantId, $userId] = requireOwnedAccount($conn, $sessionId);
$userTz = getUserTimezone($conn, $userId);

$resp = callBackendApi('GET', '/api/v1/wa/sessions/' . urlencode($sessionId) . '/chats');

if ($resp && !empty($resp['ok']) && !empty($resp['chats'])) {
    $stmtUpsert = $conn->prepare("INSERT INTO wa_contacts (account_id, session_id, chat_id, contact_name, phone_number, last_message, last_message_time, is_group)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        contact_name = CASE
            WHEN VALUES(contact_name) REGEXP '^[0-9]+$' THEN COALESCE(contact_name, VALUES(contact_name))
            ELSE COALESCE(VALUES(contact_name), contact_name)
        END,
        phone_number = COALESCE(VALUES(phone_number), phone_number),
        -- Only ever move a chat forwards in time. During a history sync the
        -- backend can report an older message as the chat's last one, and
        -- overwriting unconditionally reshuffled the chat list on every poll.
        last_message = CASE
            WHEN VALUES(last_message_time) IS NULL THEN last_message
            WHEN last_message_time IS NULL OR VALUES(last_message_time) >= last_message_time THEN VALUES(last_message)
            ELSE last_message
        END,
        last_message_time = CASE
            WHEN VALUES(last_message_time) IS NULL THEN last_message_time
            WHEN last_message_time IS NULL OR VALUES(last_message_time) >= last_message_time THEN VALUES(last_message_time)
            ELSE last_message_time
        END,
        is_group = VALUES(is_group)");

    foreach ($resp['chats'] as $chat) {
        $chatId = $chat['id'] ?? '';
        $name = $chat['name'] ?? '';
        $lastMsg = $chat['lastMessage'] ?? '';
        $lastTime = null;
        if (!empty($chat['lastTime'])) {
            $lastTime = gmdate('Y-m-d H:i:s', strtotime($chat['lastTime']));
        }
        $isGroup = (int)(str_ends_with($chatId, '@g.us'));
        // Phone from backend (mapped from LID), or extract from @s.whatsapp.net
        $phone = $chat['phone'] ?? null;
        if (!$phone && str_ends_with($chatId, '@s.whatsapp.net')) {
            $phone = explode('@', $chatId)[0];
        }
        $stmtUpsert->bind_param("issssssi", $accountId, $sessionId, $chatId, $name, $phone, $lastMsg, $lastTime, $isGroup);
        $stmtUpsert->execute();
    }
    $stmtUpsert->close();
}

// Scoped by account_id, not session_id. Ownership was already proven above,
// but keeping the tenant predicate inside the query means this stays correct
// even if the guard above is ever refactored away.
$stmtFetch = $conn->prepare("SELECT chat_id, contact_name, last_message, last_message_time, is_group
    FROM wa_contacts WHERE account_id = ? ORDER BY last_message_time DESC");
$stmtFetch->bind_param("i", $accountId);
$stmtFetch->execute();
$result = $stmtFetch->get_result();
$chats = [];
while ($r = $result->fetch_assoc()) {
    $chats[] = [
        'id' => $r['chat_id'],
        'name' => $r['contact_name'] ?: explode('@', $r['chat_id'])[0],
        'lastMessage' => $r['last_message'] ?: '',
        'lastTime' => convertToUserTz($r['last_message_time'], $userTz),
        'isGroup' => (bool)$r['is_group']
    ];
}
$stmtFetch->close();

echo json_encode(['ok' => true, 'chats' => $chats]);
