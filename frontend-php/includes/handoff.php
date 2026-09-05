<?php

// Human handoff (#16): moving a conversation from the bot to a person.
//
// The important property is the one that is easy to get wrong — while a handoff
// is open the bot must be **silent**. A customer who has asked for a human and
// keeps getting robot answers is worse served than one who was never offered a
// bot at all. So the check happens before anything else in the reply path, and
// it is a single query on an indexed column.

function handoffStatuses() {
    return [
        'waiting'   => 'Waiting',
        'claimed'   => 'With an agent',
        'resolved'  => 'Resolved',
        'abandoned' => 'Abandoned',
    ];
}

// The live handoff for a chat, if any. 'waiting' and 'claimed' are both live;
// resolved and abandoned are history and do not silence the bot.
function handoffOpenForChat(mysqli $conn, $userId, $chatId) {
    $stmt = $conn->prepare(
        "SELECT * FROM chat_handoffs
         WHERE user_id = ? AND chat_id = ? AND status IN ('waiting','claimed')
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param('is', $userId, $chatId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// Did the customer ask for a person, in so many words?
//
// Checked in PHP before the model is called, not by the model: it is free,
// deterministic, and it works even when the model is down — which is exactly
// when someone is most likely to be asking for a human.
function handoffPhraseMatch(array $config, $text) {
    if (empty($config['handoff_enabled'])) return null;

    $text = mb_strtolower(trim((string)$text));
    if ($text === '') return null;

    foreach (explode(',', (string)($config['handoff_phrases'] ?? '')) as $phrase) {
        $phrase = mb_strtolower(trim($phrase));
        if ($phrase === '' || mb_strlen($phrase) < 3) continue;

        // Whole-word for single words so "management" does not match "agent";
        // substring for multi-word phrases, where people pad them with filler.
        $isSingleWord = !str_contains($phrase, ' ');
        $hit = $isSingleWord
            ? (bool)preg_match('/(?:^|\W)' . preg_quote($phrase, '/') . '(?:\W|$)/u', $text)
            : str_contains($text, $phrase);

        if ($hit) return $phrase;
    }
    return null;
}

// Opens a handoff, or returns the existing one. Never creates a second live row
// for the same chat: a customer asking three times is one person waiting, not
// three queue entries.
function handoffOpen(mysqli $conn, $userId, array $data) {
    $existing = handoffOpenForChat($conn, $userId, $data['chat_id']);
    if ($existing) {
        handoffTouch($conn, (int)$existing['id']);
        return [(int)$existing['id'], false];
    }

    $stmt = $conn->prepare(
        "INSERT INTO chat_handoffs
           (user_id, account_id, chat_id, customer_name, customer_phone, status, reason, topic,
            requested_at, last_customer_at)
         VALUES (?,?,?,?,?,'waiting',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );
    $accountId = $data['account_id'] ?? null;
    $name = $data['customer_name'] ?? null;
    $phone = $data['customer_phone'] ?? null;
    $reason = mb_substr((string)($data['reason'] ?? 'requested'), 0, 120);
    $topic = mb_substr((string)($data['topic'] ?? ''), 0, 255) ?: null;
    $stmt->bind_param('iisssss', $userId, $accountId, $data['chat_id'], $name, $phone, $reason, $topic);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return [$id, true];
}

function handoffTouch(mysqli $conn, $id) {
    $stmt = $conn->prepare("UPDATE chat_handoffs SET last_customer_at = UTC_TIMESTAMP() WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}

// Claiming is a race: two agents click at the same moment. The conditional
// UPDATE is the lock — exactly one of them wins, and the other is told so
// rather than both replying to the same customer.
function handoffClaim(mysqli $conn, $userId, $id, $agentId) {
    $stmt = $conn->prepare(
        "UPDATE chat_handoffs SET status = 'claimed', claimed_at = UTC_TIMESTAMP(), claimed_by = ?
         WHERE id = ? AND user_id = ? AND status = 'waiting'"
    );
    $stmt->bind_param('iii', $agentId, $id, $userId);
    $stmt->execute();
    $won = $stmt->affected_rows === 1;
    $stmt->close();
    return $won;
}

function handoffRelease(mysqli $conn, $userId, $id) {
    $stmt = $conn->prepare(
        "UPDATE chat_handoffs SET status = 'waiting', claimed_at = NULL, claimed_by = NULL
         WHERE id = ? AND user_id = ? AND status = 'claimed'"
    );
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    $changed = $stmt->affected_rows === 1;
    $stmt->close();
    return $changed;
}

function handoffClose(mysqli $conn, $userId, $id, $status = 'resolved') {
    if (!in_array($status, ['resolved', 'abandoned'], true)) return false;
    $stmt = $conn->prepare(
        "UPDATE chat_handoffs SET status = ?, resolved_at = UTC_TIMESTAMP()
         WHERE id = ? AND user_id = ? AND status IN ('waiting','claimed')"
    );
    $stmt->bind_param('sii', $status, $id, $userId);
    $stmt->execute();
    $changed = $stmt->affected_rows === 1;
    $stmt->close();
    return $changed;
}

function handoffSaveNotes(mysqli $conn, $userId, $id, $notes) {
    $notes = mb_substr((string)$notes, 0, 4000);
    $stmt = $conn->prepare("UPDATE chat_handoffs SET notes = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param('sii', $notes, $id, $userId);
    $stmt->execute();
    $stmt->close();
}

function handoffById(mysqli $conn, $userId, $id) {
    $stmt = $conn->prepare("SELECT * FROM chat_handoffs WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// Scoped by user_id in the query: an account id from a row is still only
// resolvable to an account the caller owns.
function handoffAccountById(mysqli $conn, $userId, $accountId) {
    $stmt = $conn->prepare("SELECT id, session_id FROM wa_accounts WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $accountId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function handoffQueue(mysqli $conn, $userId, $status = 'open', $limit = 100) {
    $limit = max(1, min(200, (int)$limit));
    $sql = "SELECT h.*, a.session_id, u.name AS agent_name
            FROM chat_handoffs h
            LEFT JOIN wa_accounts a ON h.account_id = a.id
            LEFT JOIN users u ON h.claimed_by = u.id
            WHERE h.user_id = ?";
    if ($status === 'open')      $sql .= " AND h.status IN ('waiting','claimed')";
    elseif ($status !== 'all')   $sql .= " AND h.status = '" . ($status === 'resolved' ? 'resolved' : 'abandoned') . "'";
    // Longest wait first: the queue is a queue.
    $sql .= " ORDER BY h.status = 'waiting' DESC, h.requested_at ASC LIMIT " . $limit;

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function handoffCounts(mysqli $conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT SUM(status='waiting') AS waiting, SUM(status='claimed') AS claimed,
                SUM(status='resolved') AS resolved, SUM(status='abandoned') AS abandoned
         FROM chat_handoffs WHERE user_id = ?"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return array_map('intval', $row ?: []);
}

function handoffWaitLabel($requestedAt) {
    $seconds = max(0, time() - strtotime($requestedAt . ' UTC'));
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return intdiv($seconds, 60) . 'm';
    if ($seconds < 86400) return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
    return intdiv($seconds, 86400) . 'd';
}

// Tells the tenant a customer is waiting. Best effort by design: a failed
// notification must never stop the handoff being recorded, because the queue is
// the real mechanism and the notification is only a nudge.
function handoffNotify(mysqli $conn, $userId, array $config, array $handoff, $sessionId, $tenantId) {
    $who = $handoff['customer_name'] ?: ($handoff['customer_phone'] ? '+' . $handoff['customer_phone'] : 'A customer');
    $topic = trim((string)($handoff['topic'] ?? ''));
    $body = "{$who} has asked to speak to a person on WhatsApp."
        . ($topic !== '' ? "\n\nThey said: \"{$topic}\"" : '')
        . "\n\nOpen Live chats to reply.";

    $number = preg_replace('/\D+/', '', (string)($config['handoff_notify_number'] ?? ''));
    if ($number !== '' && $sessionId) {
        // Sent as a normal message, so it is metered like one — a notification
        // that quietly bypassed the meter would be free messaging.
        chatbotSendReply($conn, $userId, $tenantId, $sessionId, $number . '@s.whatsapp.net', $body);
    }

    $email = trim((string)($config['handoff_notify_email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendEmail($email, 'A customer is waiting on WhatsApp', nl2br(sanitize($body)), $body);
    }
}
