<?php

// Provider dispatcher (Phase 27). Every place that used to talk to the Node
// backend about a WhatsApp session comes through here now, and this file is
// the only one that knows there are two providers.
//
// The contract is that the 'baileys' branch of each function is the call the
// caller used to make, byte-for-byte — the QR path is untouched. 'cloud'
// branches do the same job over the Graph API and MySQL, so callers (the
// chatbot pipeline, the composer, the calendar card) stay provider-blind.

require_once __DIR__ . '/wa-cloud.php';

// The full wa_accounts row for a session id, cached per request.
//
// Deliberately unscoped by user: this exists for provider dispatch *after*
// ownership has already been proven (requireOwnedAccount / the page's own
// check), or for the inbound path, where it mirrors what
// chatbotAccountForSession() does. It must never become an ownership check.
// The $conn fallback is for callers like chatbotFetchHistory() that carry no
// connection — settingsConn() resolves the global one.
function waAccountRowBySession(?mysqli $conn, $sessionId) {
    static $cache = [];
    $key = (string)$sessionId;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $db = settingsConn($conn);
    if (!$db) return $cache[$key] = null;
    $stmt = $db->prepare("SELECT * FROM wa_accounts WHERE session_id = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $cache[$key] = ($row ?: null);
}

function waIsCloud(?mysqli $conn, $sessionId) {
    $row = waAccountRowBySession($conn, $sessionId);
    return ($row['provider'] ?? 'baileys') === 'cloud';
}

// Local copy of chatbotPhoneFromJid(): this file loads before chatbot.php, so
// it cannot call it, and the rule is one regex.
function waToDigits($chatId) {
    if (!str_ends_with((string)$chatId, '@s.whatsapp.net')) return null;
    $digits = preg_replace('/\D+/', '', explode('@', $chatId)[0]);
    return $digits !== '' ? $digits : null;
}

// The one place a failed Cloud send is recorded for the tenant to see.
// errorCode 190 means the stored token is dead, and saying "failed" rather
// than "connected" is what sends them to the Edit form to replace it.
function waCloudNoteFailure(?mysqli $conn, array $account, $errorCode, $message) {
    if ($conn === null) return;
    if ((int)$errorCode === 190) {
        $stmt = $conn->prepare("UPDATE wa_accounts SET status = 'failed', cloud_last_error = ? WHERE id = ?");
    } else {
        $stmt = $conn->prepare("UPDATE wa_accounts SET cloud_last_error = ? WHERE id = ?");
    }
    $id = (int)$account['id'];
    $stmt->bind_param('si', $message, $id);
    $stmt->execute();
    $stmt->close();
}

function waSendText(?mysqli $conn, $sessionId, $chatId, $text, $tenantId = null, $timeout = 30) {
    $account = waAccountRowBySession($conn, $sessionId);
    if (($account['provider'] ?? 'baileys') !== 'cloud') {
        return callBackendApi('POST', '/api/v1/wa/sessions/' . urlencode($sessionId)
            . '/chats/' . urlencode($chatId) . '/messages', ['text' => $text], $tenantId, $timeout);
    }

    $creds = cloudCreds($account);
    if ($creds['token'] === null || !$creds['phone_number_id']) {
        return ['ok' => false, 'error' => 'Cloud API credentials are missing or unreadable — re-enter them.', 'httpCode' => 0];
    }
    $digits = waToDigits($chatId);
    if ($digits === null) {
        // Cloud has no groups, @lid or newsletters — only real phone numbers.
        return ['ok' => false, 'error' => 'Only individual phone-number chats are supported on the Cloud API.', 'httpCode' => 0];
    }

    $r = cloudSendText($creds, $digits, $text);
    if ($r['ok']) {
        $db = $conn ?? settingsConn();
        if ($db) {
            cloudRecordOutbound($db, $account, $chatId,
                (string)($r['messageId'] ?? ''), $text);
        }
        return ['ok' => true, 'messageId' => $r['messageId'], 'httpCode' => 200];
    }
    $friendly = cloudErrorMessage((int)$r['errorCode'], (string)$r['error']);
    waCloudNoteFailure($conn ?? settingsConn(), $account, $r['errorCode'], $friendly);
    return ['ok' => false, 'error' => $friendly, 'httpCode' => (int)$r['httpCode']];
}

function waSendMedia(?mysqli $conn, $sessionId, $chatId, $kind, $bytes, $mime, $filename, $caption, $tenantId = null, $timeout = 150) {
    $account = waAccountRowBySession($conn, $sessionId);
    if (($account['provider'] ?? 'baileys') !== 'cloud') {
        return callBackendApi('POST', '/api/v1/wa/sessions/' . urlencode($sessionId)
            . '/chats/' . urlencode($chatId) . '/media', [
            'kind' => $kind,
            'data' => base64_encode($bytes),
            'mimetype' => $mime,
            'fileName' => $filename ?: null,
            'caption' => $caption,
        ], $tenantId, $timeout);
    }

    $creds = cloudCreds($account);
    $db = $conn ?? settingsConn();
    if ($creds['token'] === null || !$creds['phone_number_id']) {
        return ['ok' => false, 'error' => 'Cloud API credentials are missing or unreadable — re-enter them.', 'httpCode' => 0];
    }
    $digits = waToDigits($chatId);
    if ($digits === null) {
        return ['ok' => false, 'error' => 'Only individual phone-number chats are supported on the Cloud API.', 'httpCode' => 0];
    }

    $up = cloudUploadMedia($creds, $bytes, $mime, $filename);
    if (!$up['ok'] || empty($up['mediaId'])) {
        $friendly = cloudErrorMessage((int)($up['errorCode'] ?? 0), (string)($up['error'] ?? 'Upload failed'));
        waCloudNoteFailure($db, $account, $up['errorCode'] ?? null, $friendly);
        return ['ok' => false, 'error' => $friendly, 'httpCode' => 0];
    }
    $r = cloudSendMedia($creds, $digits, $kind, $up['mediaId'], $caption, $filename);
    if ($r['ok']) {
        if ($db) {
            cloudRecordOutbound($db, $account, $chatId, (string)($r['messageId'] ?? ''),
                (string)$caption, $kind, $mime, $filename ?: null);
        }
        return ['ok' => true, 'messageId' => $r['messageId'], 'httpCode' => 200];
    }
    $friendly = cloudErrorMessage((int)$r['errorCode'], (string)$r['error']);
    waCloudNoteFailure($db, $account, $r['errorCode'], $friendly);
    return ['ok' => false, 'error' => $friendly, 'httpCode' => (int)$r['httpCode']];
}

function waSendEvent(?mysqli $conn, $sessionId, $chatId, array $payload, $tenantId = null, $timeout = 30) {
    if (!waIsCloud($conn, $sessionId)) {
        return callBackendApi('POST', '/api/v1/wa/sessions/' . urlencode($sessionId)
            . '/chats/' . urlencode($chatId) . '/event', $payload, $tenantId, $timeout);
    }
    // Meta has no native event-card message type. Returning a plain failure is
    // the designed path: the caller already falls back to sending the .ics
    // document instead.
    return ['ok' => false, 'error' => 'Native event cards are not supported on the Cloud API', 'httpCode' => 0];
}

function waMarkRead(?mysqli $conn, $sessionId, $chatId, $tenantId = null, $timeout = 10) {
    $account = waAccountRowBySession($conn, $sessionId);
    if (($account['provider'] ?? 'baileys') !== 'cloud') {
        $resp = callBackendApi('POST', '/api/v1/wa/sessions/' . urlencode($sessionId)
            . '/chats/' . urlencode($chatId) . '/read', null, $tenantId, $timeout);
        return (bool)($resp['ok'] ?? false);
    }

    // Best effort, exactly like the Baileys branch: a read receipt that did
    // not go through is cosmetic. Cloud marks a *message* read, so the newest
    // inbound one in this chat is the target.
    $creds = cloudCreds($account);
    if ($creds['token'] === null) return false;
    $db = $conn ?? settingsConn();
    if (!$db) return false;
    $stmt = $db->prepare("SELECT message_id FROM wa_messages
        WHERE account_id = ? AND chat_id = ? AND from_me = 0
        ORDER BY message_timestamp DESC, id DESC LIMIT 1");
    $stmt->bind_param('is', $account['id'], $chatId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;
    return cloudMarkRead($creds, $row['message_id']);
}

// Chatbot context. Baileys returns the backend's message shape; Cloud answers
// from wa_messages in the same shape so chatbotFetchHistory()'s filter and
// slice keep working unchanged.
function waFetchHistory(?mysqli $conn, $sessionId, $chatId, $tenantId = null, $limit = 10) {
    $account = waAccountRowBySession($conn, $sessionId);
    if (($account['provider'] ?? 'baileys') !== 'cloud') {
        return callBackendApi('GET', '/api/v1/wa/sessions/' . urlencode($sessionId)
            . '/chats/' . urlencode($chatId) . '/messages', null, $tenantId, 15);
    }

    $db = $conn ?? settingsConn();
    if (!$db) return null;
    $limit = (int)$limit;
    $stmt = $db->prepare("SELECT message_id, from_me, message_text, message_timestamp
        FROM wa_messages
        WHERE account_id = ? AND chat_id = ? AND message_text <> ''
        ORDER BY message_timestamp DESC, id DESC LIMIT " . ($limit + 5));
    $stmt->bind_param('is', $account['id'], $chatId);
    $stmt->execute();
    $rows = array_reverse($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();

    $messages = [];
    foreach ($rows as $r) {
        $messages[] = [
            'id' => $r['message_id'],
            'fromMe' => (bool)$r['from_me'],
            'text' => $r['message_text'],
            'time' => $r['message_timestamp'],
        ];
    }
    return ['ok' => true, 'messages' => $messages];
}

// Message bytes for transcription and for the get-media proxy. Both providers
// return ['ok'=>bool, 'bytes', 'mime', 'filename']; on Baileys the mime is the
// upstream Content-Type and the filename was never known, so it stays null.
function waFetchMediaBytes(?mysqli $conn, $sessionId, $messageId, $tenantId = null) {
    $account = waAccountRowBySession($conn, $sessionId);
    if (($account['provider'] ?? 'baileys') !== 'cloud') {
        $url = BACKEND_URL . '/api/v1/wa/sessions/' . urlencode($sessionId)
            . '/messages/' . urlencode($messageId) . '/media';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => backendHeaders($tenantId),
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: null;
        curl_close($ch);
        if ($status === 200 && $body !== false && $body !== '') {
            return ['ok' => true, 'bytes' => $body, 'mime' => $mime, 'filename' => null];
        }
        return ['ok' => false, 'bytes' => null, 'mime' => null, 'filename' => null];
    }

    $creds = cloudCreds($account);
    if ($creds['token'] === null) {
        return ['ok' => false, 'bytes' => null, 'mime' => null, 'filename' => null];
    }
    $db = $conn ?? settingsConn();
    if (!$db) return ['ok' => false, 'bytes' => null, 'mime' => null, 'filename' => null];
    $stmt = $db->prepare("SELECT media_meta, media_mime, media_filename
        FROM wa_messages WHERE account_id = ? AND message_id = ?");
    $stmt->bind_param('is', $account['id'], $messageId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $meta = $row ? json_decode((string)$row['media_meta'], true) : null;
    $mediaId = $meta['cloud']['mediaId'] ?? null;
    if (!$mediaId) {
        return ['ok' => false, 'bytes' => null, 'mime' => null, 'filename' => $row['media_filename'] ?? null];
    }
    $r = cloudFetchMedia($creds, $mediaId);
    if (!$r['ok']) {
        return ['ok' => false, 'bytes' => null, 'mime' => null, 'filename' => $row['media_filename'] ?? null];
    }
    return ['ok' => true, 'bytes' => $r['bytes'],
            'mime' => $r['mime'] ?? ($row['media_mime'] ?? null),
            'filename' => $row['media_filename'] ?? null];
}

// Converts voice-note bytes to mp3 for FenLLM transcription, which accepts no
// ogg. The PHP image has no ffmpeg, so the backend does the conversion — it is
// provider-independent work, identical for Baileys and Cloud audio.
function waTranscodeAudioForTranscription($audioBytes, $tenantId = null) {
    $ch = curl_init(BACKEND_URL . '/api/v1/wa/audio/transcode');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array_merge(backendHeaders($tenantId), ['Content-Type: application/json']),
        CURLOPT_POSTFIELDS => json_encode(['data' => base64_encode($audioBytes)]),
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = is_string($body) ? json_decode($body, true) : null;
    if ($status === 200 && !empty($decoded['ok']) && !empty($decoded['data'])) {
        return ['ok' => true, 'bytes' => base64_decode($decoded['data'], true) ?: null, 'error' => null];
    }
    $error = is_array($decoded) && !empty($decoded['error'])
        ? (string)$decoded['error']
        : 'Audio conversion failed (HTTP ' . $status . ')';
    return ['ok' => false, 'bytes' => null, 'error' => $error];
}

// What get-status.php answers. Cloud has no live session to ask, so the row's
// own state is the truth — which is exactly what a working webhook keeps
// updating.
function waSessionStatus(?mysqli $conn, $sessionId, $timeout = 30) {
    $account = waAccountRowBySession($conn, $sessionId);
    if (($account['provider'] ?? 'baileys') !== 'cloud') {
        return callBackendApi('GET', '/api/v1/wa/sessions/' . urlencode($sessionId) . '/status', null, null, $timeout);
    }
    return [
        'ok' => true,
        'status' => $account['status'],
        'user' => ['id' => $account['phone_number'], 'name' => $account['push_name']],
        'connectedAt' => $account['connected_at'],
        'syncInfo' => null,
        'provider' => 'cloud',
        'lastError' => $account['cloud_last_error'],
    ];
}

// The "Sync" equivalent for a Cloud row: ask Meta whether the stored
// credentials still work and write the verdict back, the same columns
// waApplyBackendStatus() fills for Baileys.
function waVerifyCloudAccount(mysqli $conn, array $account) {
    $creds = cloudCreds($account);
    if ($creds['token'] === null || !$creds['phone_number_id']) {
        $stmt = $conn->prepare("UPDATE wa_accounts SET status = 'failed', cloud_last_error = ? WHERE id = ?");
        $msg = 'Cloud API credentials are missing or unreadable — re-enter them.';
        $stmt->bind_param('si', $msg, $account['id']);
        $stmt->execute();
        $stmt->close();
        return ['ok' => false, 'error' => $msg];
    }

    $r = cloudVerifyCredentials($creds['phone_number_id'], $creds['token']);
    if ($r['ok']) {
        $stmt = $conn->prepare("UPDATE wa_accounts SET status = 'connected', phone_number = ?, push_name = ?,
            connected_at = COALESCE(connected_at, UTC_TIMESTAMP()), cloud_last_error = NULL WHERE id = ?");
        $stmt->bind_param('ssi', $r['phone'], $r['name'], $account['id']);
        $stmt->execute();
        $stmt->close();
        return ['ok' => true, 'error' => null];
    }

    $stmt = $conn->prepare("UPDATE wa_accounts SET status = 'failed', cloud_last_error = ? WHERE id = ?");
    $stmt->bind_param('si', $r['error'], $account['id']);
    $stmt->execute();
    $stmt->close();
    return ['ok' => false, 'error' => $r['error']];
}
