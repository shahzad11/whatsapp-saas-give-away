<?php
// The calendar card, without a database (#47).
//
// Run with:  php tests/calendar-test.php
//
// The things a .ics file can get wrong are all in the bytes — escaping, the
// 75-octet fold, the SEQUENCE a calendar app needs to tell an update from a
// duplicate — and none of them need MySQL to assert. The send half is covered
// by the notice machinery's own guarantees (#45); what is tested here is the
// document itself.

require_once __DIR__ . '/../frontend-php/includes/functions.php';    // formatUserDate()
require_once __DIR__ . '/../frontend-php/includes/appointments.php';
require_once __DIR__ . '/../frontend-php/includes/calendar.php';

$passed = 0;
$failed = 0;

function check($name, $condition, $detail = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   {$name}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function equals($name, $expected, $actual) {
    check($name, $expected === $actual,
        'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function group($title) {
    echo "\n{$title}\n";
}

// A booking in the shape apptById() returns.
function appt(array $over = []) {
    return $over + [
        'id' => 42,
        'service_name' => 'Consultation',
        'duration_minutes' => 45,
        'customer_name' => 'Aisha',
        'customer_phone' => '923001234567',
        'chat_id' => '923001234567@s.whatsapp.net',
        'scheduled_at' => '2026-09-12 06:00:00',   // UTC
        'notes' => 'prefers the quiet room',
        'status' => 'booked',
        'ics_sequence' => 0,
    ];
}

function opts(array $over = []) {
    return $over + [
        'method' => 'PUBLISH',
        'business_name' => 'Bright Clinic',
        'business_address' => '12 Main St, Lahore',
        'host' => 'srv1931558.hstgr.cloud',
        'now_utc' => '2026-09-01 09:00:00',
        'contact_line' => 'Reply on WhatsApp to reschedule or cancel.',
    ];
}

// --- Escaping and folding ----------------------------------------------------

group('TEXT values are escaped per RFC 5545');

equals('comma, semicolon, backslash and newline',
    'a\\, b\\; c\\\\d\\nnext', apptIcsEscape("a, b; c\\d\nnext"));
equals('a stray carriage return is stripped', 'ab', apptIcsEscape("a\rb"));

group('Lines fold at 75 octets without splitting a character');

$long = 'SUMMARY:' . str_repeat('Very long service name, ', 9);
$folded = apptIcsFold($long);
$tooLong = 0;
foreach (explode("\r\n", $folded) as $line) if (strlen($line) > 75) $tooLong++;
equals('no physical line exceeds 75 octets', 0, $tooLong);
check('continuations start with a space',
    !str_contains($folded, "\r\n") || str_contains($folded, "\r\n "));
equals('and the fold unfolds back to the original line',
    $long, str_replace("\r\n ", '', $folded));

$mb = 'SUMMARY:' . str_repeat('Ünïcode ', 20);
$folded = apptIcsFold($mb);
$tooLong = 0;
$splitChar = false;
foreach (explode("\r\n", $folded) as $line) {
    if (strlen($line) > 75) $tooLong++;
    // A continuation byte (10xxxxxx) right after the leading space, or a lead
    // byte dangling at the end, is a character cut in half.
    $line = ltrim($line, ' ');
    if ($line !== '' && (ord($line[0]) & 0xC0) === 0x80) $splitChar = true;
}
equals('multibyte lines stay within 75 octets', 0, $tooLong);
check('and no fold lands inside a character', !$splitChar);
equals('a multibyte fold unfolds losslessly', $mb, str_replace("\r\n ", '', $folded));

// --- The document ------------------------------------------------------------

group('The event carries the booking, in UTC');

$ics = apptIcsBuild(appt(), opts());
check('CRLF line endings throughout',
    !str_contains(str_replace("\r\n", '', $ics), "\n") && str_contains($ics, "\r\n"));
check('DTSTART is the UTC instant', str_contains($ics, "DTSTART:20260912T060000Z\r\n"));
check('DTEND adds the duration', str_contains($ics, "DTEND:20260912T064500Z\r\n"));
check('SUMMARY names the service and the business',
    str_contains($ics, 'SUMMARY:Consultation — Bright Clinic'));
check('LOCATION comes from the profile', str_contains($ics, 'LOCATION:12 Main St\\, Lahore'));
check('the contact line is in the description', str_contains($ics, 'reschedule or cancel'));

check('LOCATION is omitted when there is no address',
    !str_contains(apptIcsBuild(appt(), opts(['business_address' => ''])), 'LOCATION'));

group('The card never carries the diary row');

check('no customer phone', !str_contains($ics, '923001234567'));
check('no notes', !str_contains($ics, 'quiet room'));

group('Identity: same event across revisions, new revision per change');

$again = apptIcsBuild(appt(), opts());
check('UID is stable across two builds',
    str_contains($ics, 'UID:appt-42@srv1931558.hstgr.cloud')
    && str_contains($again, 'UID:appt-42@srv1931558.hstgr.cloud'));

$cancel = apptIcsBuild(appt(['ics_sequence' => 2]), opts(['method' => 'CANCEL']));
check('a later revision carries its sequence', str_contains($cancel, "SEQUENCE:2\r\n"));
check('a cancel is a CANCEL method', str_contains($cancel, "METHOD:CANCEL\r\n"));
check('and a CANCELLED status', str_contains($cancel, "STATUS:CANCELLED\r\n"));
check('a publish is CONFIRMED', str_contains($ics, "STATUS:CONFIRMED\r\n"));

// --- Filename and caption ------------------------------------------------------

group('The filename and the WhatsApp caption');

equals('the filename is derived from the id', 'appointment-42.ics', apptIcsFilename(appt()));

// 06:00 UTC is 11:00 in Karachi, on a Saturday — the caption reads like every
// other appointment time the tenant sees.
$caption = apptIcsCaption(appt(), 'PUBLISH', 'Asia/Karachi');
check('the caption names service and local time',
    str_contains($caption, 'Consultation') && str_contains($caption, 'Sat 12 Sep 2026, 11:00'),
    $caption);
check('a cancel caption says so', str_contains(
    apptIcsCaption(appt(), 'CANCEL', 'Asia/Karachi'), 'Cancelled: Consultation'));

// ---------------------------------------------------------------------------
group('The native event payload mirrors the card');

// The same booking facts as the .ics, in the shape the backend's /event
// endpoint takes: epoch milliseconds, a cancelled flag, no phone or notes.
$payload = apptEventPayload(appt(), opts());
equals('name is service and business', 'Consultation — Bright Clinic', $payload['name']);
equals('startMs is the UTC instant in milliseconds',
    strtotime('2026-09-12 06:00:00 UTC') * 1000, $payload['startMs']);
equals('endMs adds the duration', $payload['startMs'] + 45 * 60 * 1000, $payload['endMs']);
check('not cancelled on PUBLISH', $payload['cancelled'] === false);
check('cancelled on CANCEL',
    apptEventPayload(appt(), opts(['method' => 'CANCEL']))['cancelled'] === true);
check('description carries the same lines as the .ics',
    str_contains($payload['description'], 'Service: Consultation')
    && str_contains($payload['description'], 'With: Bright Clinic')
    && str_contains($payload['description'], 'Customer: Aisha'));
// The event wire has no location field — a pin proto without coordinates
// renders a Null Island map — so the address is a description line instead,
// right after 'With:'.
check('the address is a Where line in the description',
    str_contains($payload['description'], "\nWhere: 12 Main St, Lahore\n")
    && !array_key_exists('locationName', $payload));
// The privacy rule is the card's: nothing the customer never consented to
// share appears in either form of the message.
check('no phone or notes anywhere in the payload',
    !str_contains(json_encode($payload), '923001234567')
    && !str_contains(json_encode($payload), 'quiet room'));

$noBusiness = apptEventPayload(appt(), opts(['business_name' => '', 'business_address' => '']));
equals('no business means the bare service name', 'Consultation', $noBusiness['name']);
check('and no Where line without an address',
    !str_contains($noBusiness['description'], 'Where:'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
