<?php

// Meta WhatsApp Cloud API client and payload normaliser (Phase 27).
//
// Everything in here is deliberately stateless: the Cloud API is plain HTTPS
// both ways, so unlike the Baileys backend there is no process to hold a
// session — credentials live encrypted on the wa_accounts row and each call
// stands alone. A failure here can only ever affect the one Cloud account it
// belongs to; the QR-linked path never touches this file's network calls.
//
// The pure helpers (signature check, message normaliser, error map) are
// covered by tests/wa-cloud-test.php, which loads this file with no database
// and no config — so nothing here may assume $conn, env() or crypto.php exist
// at load time. decryptSecret() is only ever reached inside cloudCreds() at
// runtime, where init.php has already loaded crypto.php.

// Key-derivation context for the stored credentials. Changing it would make
// every stored token unreadable; it is fixed for the life of the feature.
const WA_CLOUD_CONTEXT = 'wa-cloud-v1';
const WA_CLOUD_GRAPH = 'https://graph.facebook.com/v21.0';

// Decrypts one account's stored credentials into the shape every send helper
// takes. A token that cannot be decrypted comes back null rather than going
// out as a wrong credential — the caller treats null as "re-enter them".
function cloudCreds(array $account) {
    $token = null;
    $secret = null;
    if (function_exists('decryptSecret')) {
        $token = decryptSecret($account['cloud_access_token_encrypted'] ?? null, WA_CLOUD_CONTEXT);
        $secret = decryptSecret($account['cloud_app_secret_encrypted'] ?? null, WA_CLOUD_CONTEXT);
    }
    return [
        'phone_number_id' => $account['cloud_phone_number_id'] ?? null,
        'token' => $token,
        'app_secret' => $secret,
    ];
}

// One HTTPS call to the Graph API. The token travels in the Authorization
// header only — it is never logged and never appears in the URL, where a
// proxy or an access log would keep a copy of it.
//
// Returns ['ok'=>bool, 'httpCode'=>int, 'data'=>?array, 'error'=>?string,
// 'errorCode'=>?int]. On a non-2xx the error fields are read out of Meta's own
// error envelope (error.message / error.code / error.error_data.details),
// because those codes are what cloudErrorMessage() maps.
function cloudGraph($method, $path, $token, $data = null, $timeout = 30, $multipart = false) {
    $ch = curl_init(WA_CLOUD_GRAPH . $path);
    $headers = ['Authorization: Bearer ' . $token];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        // The upload URL Meta hands back for media lives on a CDN; the bearer
        // token must never follow a redirect to a host we did not choose.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            if ($multipart) {
                // CURLFile fields; curl builds the multipart body and sets the
                // boundary header itself.
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            } else {
                curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json']));
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
    }

    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log('Cloud API call failed: ' . $curlErr);
        return ['ok' => false, 'httpCode' => 0, 'data' => null, 'error' => 'Meta API unreachable', 'errorCode' => null];
    }

    $decoded = json_decode((string)$body, true);
    $ok = $httpCode >= 200 && $httpCode < 300;

    $error = null;
    $errorCode = null;
    if (!$ok && is_array($decoded) && isset($decoded['error'])) {
        $e = $decoded['error'];
        $error = (string)($e['error_data']['details'] ?? $e['message'] ?? 'Meta API error');
        $errorCode = isset($e['code']) ? (int)$e['code'] : null;
    } elseif (!$ok) {
        $error = 'Meta API error (HTTP ' . $httpCode . ')';
    }

    return [
        'ok' => $ok,
        'httpCode' => $httpCode,
        'data' => is_array($decoded) ? $decoded : null,
        'error' => $error,
        'errorCode' => $errorCode,
    ];
}

// Meta's numeric error codes, in the tenant's terms. The raw message is kept
// as the fallback (and for code 100, where the detail string names the
// offending parameter) because Meta's messages are usually specific.
function cloudErrorMessage($code, $raw) {
    switch ((int)$code) {
        case 131047:
            return 'Outside the 24-hour customer service window — the customer must message first (templates are not supported yet).';
        case 190:
            return 'The access token is invalid or expired. Update it under Accounts → Edit.';
        case 131026:
            return 'That number is not on WhatsApp.';
        case 131030:
            return 'Recipient not in the allowed list (the Meta app is still in development mode).';
        case 131051:
            return 'Unsupported message type.';
        case 100:
            return 'Invalid parameter: ' . $raw;
        case 80007:
        case 130429:
            return 'Rate limit reached, try again shortly.';
        case 131000:
        case 131016:
            return 'WhatsApp service error, try again.';
        default:
            return $raw;
    }
}

// The one call made before credentials are ever stored: proves the
// phone_number_id and token actually work, and learns the display number and
// verified name the account list shows.
function cloudVerifyCredentials($phoneNumberId, $token) {
    $resp = cloudGraph('GET', '/' . urlencode($phoneNumberId)
        . '?fields=display_phone_number,verified_name,quality_rating', $token, null, 15);
    if (!$resp['ok']) {
        return ['ok' => false, 'phone' => null, 'name' => null,
                'error' => cloudErrorMessage((int)$resp['errorCode'], (string)$resp['error'])];
    }
    $d = $resp['data'] ?? [];
    return [
        'ok' => true,
        'phone' => preg_replace('/\D+/', '', (string)($d['display_phone_number'] ?? '')),
        'name' => $d['verified_name'] ?? null,
        'error' => null,
    ];
}

function cloudSendText(array $creds, $toDigits, $text) {
    $resp = cloudGraph('POST', '/' . urlencode($creds['phone_number_id']) . '/messages', $creds['token'], [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $toDigits,
        'type' => 'text',
        'text' => ['preview_url' => false, 'body' => $text],
    ]);
    return [
        'ok' => $resp['ok'],
        'messageId' => $resp['data']['messages'][0]['id'] ?? null,
        'error' => $resp['error'],
        'errorCode' => $resp['errorCode'],
        'httpCode' => $resp['httpCode'],
    ];
}

// Two-step media send, step one: the bytes go to /{phone_number_id}/media and
// Meta answers with an id the send call then references. curl needs a real
// file for the multipart part, so the bytes take a detour through a temp file.
function cloudUploadMedia(array $creds, $bytes, $mime, $filename) {
    $tmp = tempnam(sys_get_temp_dir(), 'wacloud');
    if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
        return ['ok' => false, 'mediaId' => null, 'error' => 'Could not stage the upload'];
    }
    try {
        $resp = cloudGraph('POST', '/' . urlencode($creds['phone_number_id']) . '/media', $creds['token'], [
            'messaging_product' => 'whatsapp',
            'type' => $mime,
            'file' => new CURLFile($tmp, $mime, $filename ?: 'file'),
        ], 120, true);
    } finally {
        @unlink($tmp);
    }
    return [
        'ok' => $resp['ok'],
        'mediaId' => $resp['data']['id'] ?? null,
        'error' => $resp['error'],
        'errorCode' => $resp['errorCode'],
    ];
}

// Step two: send the uploaded media id. 'voice' is an audio message Meta
// renders as a voice note; only document carries a filename, and caption is
// meaningless on audio/sticker so it is only sent where Meta accepts it.
function cloudSendMedia(array $creds, $toDigits, $kind, $mediaId, $caption, $filename) {
    $type = $kind === 'voice' ? 'audio' : $kind;
    $body = ['id' => $mediaId];
    if ($type === 'document' && $filename) {
        $body['filename'] = $filename;
    }
    if (in_array($type, ['image', 'video', 'document'], true) && $caption !== '' && $caption !== null) {
        $body['caption'] = $caption;
    }
    $resp = cloudGraph('POST', '/' . urlencode($creds['phone_number_id']) . '/messages', $creds['token'], [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $toDigits,
        'type' => $type,
        $type => $body,
    ], 60);
    return [
        'ok' => $resp['ok'],
        'messageId' => $resp['data']['messages'][0]['id'] ?? null,
        'error' => $resp['error'],
        'errorCode' => $resp['errorCode'],
        'httpCode' => $resp['httpCode'],
    ];
}

// Read receipts on Cloud are a status POST against the message id, not the
// chat — the caller looks up the newest inbound id first.
function cloudMarkRead(array $creds, $messageId) {
    $resp = cloudGraph('POST', '/' . urlencode($creds['phone_number_id']) . '/messages', $creds['token'], [
        'messaging_product' => 'whatsapp',
        'status' => 'read',
        'message_id' => $messageId,
    ]);
    return (bool)$resp['ok'];
}

// Cloud media is also two steps: the media id resolves to a short-lived CDN
// url, and that url is what actually serves the bytes — still authenticated
// with the same bearer token.
function cloudFetchMedia(array $creds, $mediaId) {
    $info = cloudGraph('GET', '/' . urlencode($mediaId), $creds['token'], null, 30);
    if (!$info['ok'] || empty($info['data']['url'])) {
        return ['ok' => false, 'bytes' => null, 'mime' => null, 'error' => $info['error'] ?? 'No media URL'];
    }
    $ch = curl_init((string)$info['data']['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $creds['token']],
    ]);
    $bytes = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200 || $bytes === false || $bytes === '') {
        return ['ok' => false, 'bytes' => null, 'mime' => null, 'error' => 'Media download failed'];
    }
    return ['ok' => true, 'bytes' => $bytes, 'mime' => $info['data']['mime_type'] ?? null, 'error' => null];
}

// X-Hub-Signature-256 is "sha256=<hex hmac>" over the raw request body, keyed
// by the app's secret. Pure: the caller supplies body, header and secret.
function cloudVerifySignature($rawBody, $headerValue, $appSecret) {
    if (!is_string($headerValue) || !str_starts_with($headerValue, 'sha256=')) {
        return false;
    }
    $expected = hash_hmac('sha256', (string)$rawBody, (string)$appSecret);
    return hash_equals($expected, substr($headerValue, 7));
}

// One element of value.messages[] → the internal inbound shape. Pure so the
// tests can drive it; Meta's field names are mapped at this edge and nowhere
// else, so everything downstream sees the same media types the Baileys
// backend produces.
//
// Returns null for a message with no id or no from — without either it cannot
// be stored or deduplicated.
function cloudNormaliseMessage(array $m, array $contactsByWaId = []) {
    $id = $m['id'] ?? null;
    $fromRaw = $m['from'] ?? null;
    if (!$id || !$fromRaw) return null;

    $from = preg_replace('/\D+/', '', (string)$fromRaw);
    if ($from === '') return null;

    $type = (string)($m['type'] ?? '');
    $mediaType = 'text';
    $text = '';
    $mime = null;
    $filename = null;
    $meta = null;
    $cloudMediaId = null;

    switch ($type) {
        case 'text':
            $text = (string)($m['text']['body'] ?? '');
            break;

        case 'image':
        case 'video':
        case 'document':
        case 'sticker':
            $mediaType = $type;
            $d = $m[$type] ?? [];
            $text = (string)($d['caption'] ?? '');
            $mime = $d['mime_type'] ?? null;
            $cloudMediaId = $d['id'] ?? null;
            if ($type === 'document') {
                $filename = $d['filename'] ?? null;
            }
            break;

        case 'audio':
            // Meta marks push-to-talk audio with voice=true; that flag is the
            // whole difference between a voice note and an attached sound file.
            $d = $m['audio'] ?? [];
            $mediaType = !empty($d['voice']) ? 'voice' : 'audio';
            $mime = $d['mime_type'] ?? null;
            $cloudMediaId = $d['id'] ?? null;
            break;

        case 'location':
            $mediaType = 'location';
            $d = $m['location'] ?? [];
            $meta = ['locationInfo' => [
                'latitude' => (float)($d['latitude'] ?? 0),
                'longitude' => (float)($d['longitude'] ?? 0),
                'name' => $d['name'] ?? null,
                'address' => $d['address'] ?? null,
            ]];
            break;

        case 'contacts':
            $mediaType = 'contact';
            $list = [];
            foreach (($m['contacts'] ?? []) as $c) {
                $fn = (string)($c['name']['formatted_name'] ?? '');
                $tel = (string)($c['phones'][0]['phone'] ?? '');
                // The UI renders a vCard; a minimal one is all it needs.
                $list[] = [
                    'displayName' => $fn,
                    'vcard' => "BEGIN:VCARD\nFN:{$fn}\nTEL:{$tel}\nEND:VCARD",
                ];
            }
            $meta = ['contactInfo' => $list];
            break;

        case 'button':
            $text = (string)($m['button']['text'] ?? '');
            break;

        case 'interactive':
            $d = $m['interactive'] ?? [];
            $text = (string)($d['button_reply']['title'] ?? $d['list_reply']['title'] ?? '');
            break;

        case 'reaction':
            // Stored but never answered: a reaction is not something a chatbot
            // should reply to, so it normalises to an empty text message.
            $mediaType = 'text';
            $text = '';
            break;

        default:
            // Unknown/system types are kept in the history as empty text rows
            // rather than dropped, so the thread the tenant reads matches what
            // WhatsApp shows.
            $mediaType = 'text';
            $text = '';
            break;
    }

    if ($cloudMediaId !== null) {
        // The Cloud media id is what get-media.php later resolves to bytes;
        // without it stored here the media would be unfetchable.
        $meta = $meta ?? [];
        $meta['cloud'] = ['mediaId' => $cloudMediaId, 'mime' => $mime];
    }

    return [
        'messageId' => (string)$id,
        'chatId' => $from . '@s.whatsapp.net',
        'phone' => $from,
        'senderName' => $contactsByWaId[$from] ?? null,
        'timestamp' => gmdate('Y-m-d H:i:s', (int)($m['timestamp'] ?? 0)),
        'mediaType' => $mediaType,
        'text' => $text,
        'mediaMime' => $mime,
        'mediaFilename' => $filename,
        'mediaMeta' => $meta,
        'cloudMediaId' => $cloudMediaId,
    ];
}

// The whole webhook payload → normalised inbound messages for *this* account.
// A Meta app can carry several phone numbers, so changes addressed to a
// different phone_number_id are counted and skipped rather than stored.
//
// Returns ['messages'=>[...], 'statuses'=>int, 'ignored'=>int].
function cloudExtractInbound(array $payload, $expectedPhoneNumberId) {
    $messages = [];
    $statuses = 0;
    $ignored = 0;

    foreach (($payload['entry'] ?? []) as $entry) {
        foreach (($entry['changes'] ?? []) as $change) {
            if (($change['field'] ?? '') !== 'messages') continue;
            $value = $change['value'] ?? [];

            if ((string)($value['metadata']['phone_number_id'] ?? '') !== (string)$expectedPhoneNumberId) {
                $ignored++;
                continue;
            }

            // wa_id → display name, so a message's senderName does not depend
            // on the contact block arriving in the same change.
            $contactsByWaId = [];
            foreach (($value['contacts'] ?? []) as $c) {
                $waId = preg_replace('/\D+/', '', (string)($c['wa_id'] ?? ''));
                if ($waId !== '') {
                    $contactsByWaId[$waId] = $c['profile']['name'] ?? null;
                }
            }

            $statuses += count($value['statuses'] ?? []);

            foreach (($value['messages'] ?? []) as $m) {
                $n = cloudNormaliseMessage($m, $contactsByWaId);
                if ($n !== null) $messages[] = $n;
            }
        }
    }

    return ['messages' => $messages, 'statuses' => $statuses, 'ignored' => $ignored];
}

// A Cloud account's session_id is synthetic — it exists so the account can
// share the session_id-keyed tables (wa_contacts, wa_messages, chat_handoffs)
// with QR-linked accounts without any of them knowing the difference.
function cloudNewSessionId() {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

// Webhook keys and verify tokens are drawn from the same space.
function cloudNewKey() {
    return bin2hex(random_bytes(24));
}

// Writes an outbound Cloud send into the same tables the Baileys poll fills.
// There is no backend process to learn this message from — the webhook only
// delivers inbound — so without this the tenant's own reply would never
// appear in their chat list.
function cloudRecordOutbound(mysqli $conn, array $account, $chatId, $messageId, $text,
                             $mediaType = 'text', $mediaMime = null, $mediaFilename = null) {
    $now = gmdate('Y-m-d H:i:s');

    $stmt = $conn->prepare("INSERT IGNORE INTO wa_messages
        (account_id, session_id, message_id, chat_id, sender_name, sender_jid, from_me, message_text, media_type, media_mime, media_filename, message_timestamp, media_meta)
        VALUES (?, ?, ?, ?, NULL, NULL, 1, ?, ?, ?, ?, ?, NULL)");
    $accountId = (int)$account['id'];
    $sessionId = $account['session_id'];
    $stmt->bind_param("issssssss", $accountId, $sessionId, $messageId, $chatId, $text, $mediaType, $mediaMime, $mediaFilename, $now);
    $stmt->execute();
    $stmt->close();

    $phone = waToDigits($chatId);
    // Same forward-only upsert get-chats.php runs: a newer last_message can
    // land, an older sync result cannot roll one back.
    $stmt = $conn->prepare("INSERT INTO wa_contacts (account_id, session_id, chat_id, contact_name, phone_number, last_message, last_message_time, is_group, is_archived)
        VALUES (?, ?, ?, NULL, ?, ?, ?, 0, 0)
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
    $lastMsg = $text !== '' ? $text : '[' . ucfirst($mediaType) . ']';
    $stmt->bind_param("isssss", $accountId, $sessionId, $chatId, $phone, $lastMsg, $now);
    $stmt->execute();
    $stmt->close();
}
