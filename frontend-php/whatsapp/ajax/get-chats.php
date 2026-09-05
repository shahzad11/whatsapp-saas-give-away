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

// The plan's contact cap. A contact appears here as a side effect of sync, so
// the cap can only stop *growth*: chats already stored keep syncing normally,
// and new ones stop being recorded once the tenant is at the limit. Existing
// rows are never deleted to make room.
[, $contactsUsed, $contactLimit] = checkContactQuota($conn, $userId);
$contactsCapped = false;

if ($resp && !empty($resp['ok']) && !empty($resp['chats'])) {
    // What is already stored, so an unchanged chat can be skipped entirely.
    // This poll runs every 10s and used to run the upsert over every chat in
    // the list each time, whether or not anything about it had moved.
    //
    // Loaded unconditionally now — it used to be fetched only when a contact
    // cap applied, purely for the existence check below, which it still serves.
    // One indexed read replaces N writes.
    $stored = [];
    $stmtKnown = $conn->prepare("SELECT chat_id, contact_name, phone_number, last_message, last_message_time, is_group, is_archived
        FROM wa_contacts WHERE account_id = ?");
    $stmtKnown->bind_param("i", $accountId);
    $stmtKnown->execute();
    $res = $stmtKnown->get_result();
    while ($k = $res->fetch_assoc()) $stored[$k['chat_id']] = $k;
    $stmtKnown->close();

    // Presence map for the contact-cap check below. Kept separate from $stored
    // because the cap loop adds entries to it for chats accepted during this
    // pass, and $stored must keep meaning "what the database currently holds".
    $known = array_fill_keys(array_keys($stored), true);

    $stmtUpsert = $conn->prepare("INSERT INTO wa_contacts (account_id, session_id, chat_id, contact_name, phone_number, last_message, last_message_time, is_group, is_archived)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
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
        is_group = VALUES(is_group),
        -- Archive state is owned by WhatsApp, so the backend's value always
        -- wins. Unlike the name and timestamp there is no 'better' value to
        -- preserve: the phone is the source of truth and it can toggle either way.
        is_archived = VALUES(is_archived)");

    foreach ($resp['chats'] as $chat) {
        $chatId = $chat['id'] ?? '';
        // displayName is the backend's resolved identity and is null when
        // genuinely unknown. Never fall back to the JID: for an @lid chat that
        // is an opaque internal id which looks like a phone number and is not.
        $name = $chat['displayName'] ?? ($chat['name'] ?? '');
        if ($name !== '' && (str_contains($name, '@') || preg_match('/^\d{10,}$/', $name))) {
            $name = '';
        }
        $lastMsg = $chat['lastMessage'] ?? '';
        $lastTime = null;
        if (!empty($chat['lastTime'])) {
            $lastTime = gmdate('Y-m-d H:i:s', strtotime($chat['lastTime']));
        }
        $isGroup = (int)(str_ends_with($chatId, '@g.us'));
        $isArchived = (int)!empty($chat['archived']);
        // Phone from backend (mapped from LID), or extract from @s.whatsapp.net
        $phone = $chat['phone'] ?? null;
        if (!$phone && str_ends_with($chatId, '@s.whatsapp.net')) {
            $phone = explode('@', $chatId)[0];
        }
        // Provable no-op: every column this statement could write already holds
        // exactly what would be written, so the upsert cannot change the row
        // whatever the CASE branches decide. Skipping is the whole point of
        // #17 — in the steady state almost every chat in the list is unchanged.
        //
        // Compared field-by-field against the stored row rather than by a
        // timestamp watermark, because a chat can change *without* its
        // last_message_time moving: archiving one, or a contact name finally
        // resolving. A watermark would have frozen both.
        $prev = $stored[$chatId] ?? null;
        if ($prev !== null
            && (string)$prev['contact_name'] === $name
            && (string)$prev['phone_number'] === (string)$phone
            && (string)$prev['last_message'] === $lastMsg
            && (string)$prev['last_message_time'] === (string)$lastTime
            && (int)$prev['is_group'] === $isGroup
            && (int)$prev['is_archived'] === $isArchived) {
            continue;
        }

        // A chat we have never stored is new, and a new one counts against the
        // plan. Skip it rather than upserting, or the INSERT would create the
        // row the cap exists to prevent.
        if ($contactLimit !== null && !isset($known[$chatId])) {
            if ($contactsUsed >= $contactLimit) {
                $contactsCapped = true;
                continue;
            }
            $contactsUsed++;
            $known[$chatId] = true;
        }

        $stmtUpsert->bind_param("issssssii", $accountId, $sessionId, $chatId, $name, $phone, $lastMsg, $lastTime, $isGroup, $isArchived);
        $stmtUpsert->execute();
    }
    $stmtUpsert->close();
}

// Scoped by account_id, not session_id. Ownership was already proven above,
// but keeping the tenant predicate inside the query means this stays correct
// even if the guard above is ever refactored away.
$stmtFetch = $conn->prepare("SELECT chat_id, contact_name, phone_number, last_message, last_message_time, is_group, is_archived
    FROM wa_contacts WHERE account_id = ? ORDER BY last_message_time DESC");
$stmtFetch->bind_param("i", $accountId);
$stmtFetch->execute();
$result = $stmtFetch->get_result();

$chats = [];
$archivedCount = 0;
while ($r = $result->fetch_assoc()) {
    $archived = (bool)$r['is_archived'];
    if ($archived) $archivedCount++;

    $chats[] = [
        'id' => $r['chat_id'],
        // chatDisplayName never returns a JID or a bare identifier — see
        // includes/functions.php. A group with no known subject reads as
        // "Group chat", a contact with no known name as their phone number,
        // and an unmappable @lid as "Unknown contact".
        'name' => chatDisplayName($r['contact_name'], $r['phone_number'], $r['chat_id'], (bool)$r['is_group']),
        'lastMessage' => $r['last_message'] ?: '',
        'lastTime' => convertToUserTz($r['last_message_time'], $userTz),
        'isGroup' => (bool)$r['is_group'],
        'archived' => $archived,
    ];
}
$stmtFetch->close();

echo json_encode([
    'ok' => true,
    'chats' => $chats,
    'archivedCount' => $archivedCount,
    // Silently dropping chats would read as "sync is broken". Say so instead.
    'contactsCapped' => $contactsCapped,
    'contactLimit' => $contactLimit,
]);
