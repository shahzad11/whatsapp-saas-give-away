<?php

// Calendar cards (#47): the .ics file both parties get after a booking, a move
// or a cancellation.
//
// The build half of this file is pure — a line of text in, bytes out — for the
// same reason the slot maths is: the interesting cases (escaping, folding, a
// cancelled event that must update rather than duplicate) are stated as data in
// tests/calendar-test.php. The send half reuses the appointment_notifications
// machinery (#45), so a card that did not go out is a row the tenant can see
// and retry, exactly like the message it accompanies.

// RFC 5545 TEXT escaping. What arrives here is user data — a service name, a
// customer name — so a comma or semicolon in it must not become structure.
function apptIcsEscape($text) {
    $text = str_replace("\r", '', (string)$text);
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace(["\n", ';', ','], ['\\n', '\\;', '\\,'], $text);
    return $text;
}

// Content lines are at most 75 octets; longer ones fold, each continuation
// starting with a space. The break is measured in bytes but must not land inside
// a multibyte character — a UTF-8 sequence split across a fold renders as
// mojibake in every calendar app.
function apptIcsFold($line) {
    $line = (string)$line;
    $out = '';
    $first = true;
    while (true) {
        // A continuation line spends one of its 75 octets on the leading space.
        $limit = $first ? 75 : 74;
        if (strlen($line) <= $limit) {
            return $out . ($first ? '' : ' ') . $line;
        }
        $cut = $limit;
        // Back off to a character boundary: a UTF-8 continuation byte is
        // 0b10xxxxxx, so a cut that leaves one trailing is mid-character.
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;
        if ($cut === 0) $cut = $limit;   // invalid UTF-8: fold anyway rather than loop
        if (!$first) $out .= ' ';
        $out .= substr($line, 0, $cut) . "\r\n";
        $line = substr($line, $cut);
        $first = false;
    }
}

// One appointment as a VCALENDAR. Every timestamp is the UTC Z form on purpose
// — no VTIMEZONE block — because a VTIMEZONE only ever tells the app what it
// already knew, while an unambiguous instant converts correctly in every
// calendar the file lands in.
//
// Deliberately absent: the customer's phone number and the booking's notes.
// Both travel to a calendar account that syncs to every device the customer
// owns; the card is a convenience, not a copy of the diary row.
//
// $opts: method ('PUBLISH'|'CANCEL'), business_name, business_address (one
// line, may be ''), host (UID domain), now_utc ('Y-m-d H:i:s', injectable),
// contact_line.
function apptIcsBuild(array $appt, array $opts): string {
    $method = ($opts['method'] ?? 'PUBLISH') === 'CANCEL' ? 'CANCEL' : 'PUBLISH';
    $business = trim((string)($opts['business_name'] ?? ''));
    $host = trim((string)($opts['host'] ?? '')) ?: 'whatsapp-bot';
    $nowUtc = (string)($opts['now_utc'] ?? '') ?: gmdate('Y-m-d H:i:s');
    $contact = trim((string)($opts['contact_line'] ?? ''));

    $start = new DateTime((string)$appt['scheduled_at'], new DateTimeZone('UTC'));
    $end = (clone $start)->modify('+' . max(1, (int)($appt['duration_minutes'] ?? 30)) . ' minutes');
    $stamp = (new DateTime($nowUtc, new DateTimeZone('UTC')))->format('Ymd\THis\Z');

    $description = ['Service: ' . (string)($appt['service_name'] ?? '')];
    if ($business !== '') $description[] = 'With: ' . $business;
    $customer = trim((string)($appt['customer_name'] ?? ''));
    if ($customer !== '') $description[] = 'Customer: ' . $customer;
    if ($contact !== '') $description[] = '';
    if ($contact !== '') $description[] = $contact;

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//' . ($business !== '' ? $business : 'WhatsApp Bot') . '//Appointments//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:' . $method,
        'BEGIN:VEVENT',
        'UID:appt-' . (int)$appt['id'] . '@' . $host,
        'DTSTAMP:' . $stamp,
        'SEQUENCE:' . (int)($appt['ics_sequence'] ?? 0),
        'STATUS:' . ($method === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED'),
        'DTSTART:' . $start->format('Ymd\THis\Z'),
        'DTEND:' . $end->format('Ymd\THis\Z'),
        'SUMMARY:' . apptIcsEscape((string)($appt['service_name'] ?? '') . ($business !== '' ? ' — ' . $business : '')),
    ];
    $address = trim((string)($opts['business_address'] ?? ''));
    if ($address !== '') $lines[] = 'LOCATION:' . apptIcsEscape($address);
    $lines[] = 'DESCRIPTION:' . apptIcsEscape(implode("\n", $description));
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';

    $out = '';
    foreach ($lines as $line) $out .= apptIcsFold($line) . "\r\n";
    return $out;
}

// The download and the WhatsApp attachment share one name; only digits and the
// id are in it, so there is nothing to sanitise beyond forcing the shape.
function apptIcsFilename(array $appt): string {
    return 'appointment-' . (int)$appt['id'] . '.ics';
}

// The caption the document carries on WhatsApp. The time is rendered the way
// the tenant sees every other appointment time — their timezone, 'D j M Y,
// H:i' — because "Sat 12 Sep, 11:00" is what they just told the customer.
function apptIcsCaption(array $appt, $method, $timezone): string {
    $when = formatUserDate($appt['scheduled_at'] ?? '', $timezone, 'D j M Y, H:i');
    $service = (string)($appt['service_name'] ?? '');
    if ($method === 'CANCEL') {
        return "Cancelled: {$service} on {$when}. Open this to remove it from your calendar.";
    }
    return "Your {$service} on {$when} — tap to add it to your calendar.";
}

// SEQUENCE is what makes a second card about the same booking *update* the
// calendar entry instead of adding a duplicate next to it, so every change
// bumps it. Called from apptReschedule() and apptSetStatus() — centrally, so
// the chatbot's moves and the dashboard's cannot disagree about which revision
// the customer is holding.
function apptBumpIcsSequence(mysqli $conn, $userId, $id) {
    $stmt = $conn->prepare(
        "UPDATE appointments SET ics_sequence = ics_sequence + 1 WHERE id = ? AND user_id = ?"
    );
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    $stmt->close();
}

// Where the tenant's copy goes: their own number, as a message to self.
//
// The account is taken from the booking row when it has one; for a manual
// booking (account_id NULL) it falls back to the tenant's single connected
// account — and only when there is exactly one. Two connected numbers and no
// booking row to say which one booked it means the card goes nowhere rather
// than to a guess: the dashboard download is the fallback.
function apptTenantCalendarChannel(mysqli $conn, $userId, array $appt) {
    $accountId = (int)($appt['account_id'] ?? 0);
    if ($accountId) {
        $stmt = $conn->prepare(
            "SELECT session_id, phone_number FROM wa_accounts WHERE id = ? AND user_id = ?"
        );
        $stmt->bind_param('ii', $accountId, $userId);
    } else {
        $stmt = $conn->prepare(
            "SELECT session_id, phone_number FROM wa_accounts
             WHERE user_id = ? AND status = 'connected' AND phone_number IS NOT NULL AND phone_number != ''"
        );
        $stmt->bind_param('i', $userId);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($accountId ? !$rows : count($rows) !== 1) return null;
    $phone = preg_replace('/\D+/', '', (string)$rows[0]['phone_number']);
    if ($phone === '') return null;
    return ['session_id' => $rows[0]['session_id'], 'chat_id' => $phone . '@s.whatsapp.net'];
}

// Sends the .ics to both parties through the appointment_notifications
// machinery (#45), so each card is claimed, deduplicated and retryable exactly
// like the text notice it accompanies.
//
// The fingerprint carries the sequence — 'ics:PUBLISH:3' — so a reschedule's
// card is a new change to tell about, not a duplicate of the booking's.
//
// Returns ['customer' => result, 'tenant' => result]; $only restricts a retry
// to the one party whose card failed rather than sending both again.
function apptSendCalendarCards(mysqli $conn, $userId, array $appt, $method, $channelName = 'dashboard', $only = null): array {
    // Re-read: the caller's row predates the SEQUENCE bump, and the number on
    // the card must be the number in the diary.
    $appt = apptById($conn, $userId, (int)$appt['id']) ?: $appt;
    $method = $method === 'CANCEL' ? 'CANCEL' : 'PUBLISH';
    $timezone = getUserTimezone($conn, $userId);
    $profile = getUserProfile($conn, $userId);

    $address = implode(', ', array_filter(array_map('trim', [
        $profile['address_line1'] ?? '', $profile['address_line2'] ?? '',
        $profile['city'] ?? '', $profile['state_region'] ?? '', $profile['postal_code'] ?? '',
    ])));

    $ics = apptIcsBuild($appt, [
        'method' => $method,
        'business_name' => (string)($profile['company_name'] ?? ''),
        'business_address' => $address,
        'host' => parse_url(APP_URL, PHP_URL_HOST) ?: 'whatsapp-bot',
        'now_utc' => gmdate('Y-m-d H:i:s'),
        'contact_line' => 'Reply on WhatsApp to reschedule or cancel.',
    ]);
    $caption = apptIcsCaption($appt, $method, $timezone);
    $filename = apptIcsFilename($appt);
    $fingerprint = 'ics:' . $method . ':' . (int)($appt['ics_sequence'] ?? 0);

    $eventOpts = [
        'method' => $method,
        'business_name' => (string)($profile['company_name'] ?? ''),
        'business_address' => $address,
        'contact_line' => 'Reply on WhatsApp to reschedule or cancel.',
    ];

    $depsFor = function (callable $channelFn) use ($conn, $userId, $appt, $channelName, $ics, $filename, $method, $eventOpts) {
        return [
            'claim' => fn($kind, $fingerprint, $body) => apptClaimNotice($conn, $userId, (int)$appt['id'],
                $kind, $fingerprint, $body, $channelName),
            'mark' => fn($noticeId, $status, $detail) => apptMarkNotice($conn, $noticeId, $status, $detail),
            'channel' => $channelFn,
            'quota' => function () use ($conn, $userId) { [$ok] = checkMessageQuota($conn, $userId); return $ok; },
            // The native event card is preferred — it is what WhatsApp renders
            // as a tappable appointment — with the .ics document as the
            // fallback for a backend that cannot carry one. A string return is
            // which form went out; apptSendNotice records it as the detail.
            'send' => function (array $channel, $text) use ($conn, $userId, $appt, $method, $eventOpts, $ics, $filename) {
                if (apptSendNativeEvent($conn, $userId, 't' . $userId,
                        $channel['session_id'], $channel['chat_id'], $appt, $method, $eventOpts)) {
                    return 'native event';
                }
                return chatbotSendDocument($conn, $userId, 't' . $userId,
                    $channel['session_id'], $channel['chat_id'], $ics, $filename, 'text/calendar', $text)
                    ? 'ics fallback' : false;
            },
        ];
    };

    $results = ['customer' => ['status' => 'none', 'detail' => ''], 'tenant' => ['status' => 'none', 'detail' => '']];

    if ($only === null || $only === 'customer') {
        $results['customer'] = apptSendNotice(
            $depsFor(fn() => apptNoticeChannel($conn, $userId, $appt)),
            'calendar_customer', $fingerprint, $caption);
    }

    if ($only === null || $only === 'tenant') {
        // No channel means no row at all: a manual booking on an unlinked
        // account would otherwise record "skipped" on every booking and flag
        // work the tenant cannot do anything about. The dashboard download is
        // the documented fallback.
        $tenantChannel = apptTenantCalendarChannel($conn, $userId, $appt);
        if ($tenantChannel !== null) {
            $results['tenant'] = apptSendNotice(
                $depsFor(fn() => $tenantChannel),
                'calendar_tenant', $fingerprint, $caption);
        }
    }

    return $results;
}

// The payload for a native WhatsApp event message (#47 follow-up). Pure, so
// the field maths are testable: name/description are the same composition the
// .ics carries (SUMMARY and DESCRIPTION), and times are epoch milliseconds —
// what the backend's `event` content takes, converted here because the wire
// format belongs to the caller, not to Baileys.
//
// $opts: method ('PUBLISH'|'CANCEL'), business_name, business_address (one
// line, may be ''), contact_line. No phone or notes — the same privacy rule
// the .ics follows.
function apptEventPayload(array $appt, array $opts): array {
    $business = trim((string)($opts['business_name'] ?? ''));
    $contact = trim((string)($opts['contact_line'] ?? ''));
    $service = (string)($appt['service_name'] ?? '');

    // scheduled_at is UTC, exactly as apptIcsBuild() treats it.
    $start = new DateTime((string)$appt['scheduled_at'], new DateTimeZone('UTC'));
    $end = (clone $start)->modify('+' . max(1, (int)($appt['duration_minutes'] ?? 30)) . ' minutes');

    $description = ['Service: ' . $service];
    if ($business !== '') $description[] = 'With: ' . $business;
    // No location field on the wire: the proto's pin wants coordinates, and an
    // address-only pin would render as a map of Null Island. The address goes
    // in the description instead — the .ics keeps its LOCATION line because a
    // calendar file does take a bare address.
    $address = trim((string)($opts['business_address'] ?? ''));
    if ($address !== '') $description[] = 'Where: ' . $address;
    $customer = trim((string)($appt['customer_name'] ?? ''));
    if ($customer !== '') $description[] = 'Customer: ' . $customer;
    if ($contact !== '') {
        $description[] = '';
        $description[] = $contact;
    }

    return [
        'name' => $service . ($business !== '' ? ' — ' . $business : ''),
        'description' => implode("\n", $description),
        'startMs' => $start->getTimestamp() * 1000,
        'endMs' => $end->getTimestamp() * 1000,
        'cancelled' => (($opts['method'] ?? 'PUBLISH') === 'CANCEL'),
    ];
}

// Sends the native event card through the backend's /event endpoint. It lives
// here with the rest of the calendar-card code so the feature is
// self-contained; its shape mirrors chatbotSendDocument() deliberately — same
// callBackendApi() hop, same metering, same mark-read.
function apptSendNativeEvent(mysqli $conn, $userId, $tenantId, $sessionId, $chatId, array $appt, $method, array $opts): bool {
    $payload = apptEventPayload($appt, $opts + ['method' => $method]);

    // Metered like every send path: reserve first, hand it back on failure (#18).
    if (!quotaReserveMessage($conn, $userId)) return false;
    $resp = waSendEvent($conn, $sessionId, $chatId, $payload, $tenantId, 30);

    if (!$resp || empty($resp['ok'])) {
        quotaRelease($conn, $userId, 'messages_sent');
        return false;
    }
    chatbotMarkChatRead($tenantId, $sessionId, $chatId);
    return true;
}
