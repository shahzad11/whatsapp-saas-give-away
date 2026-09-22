<?php
// Cloud API pure helpers: webhook signature, payload normalisation, error map
// (Phase 27).
//
// Run with:  php tests/wa-cloud-test.php
//
// No database and no network. includes/wa-cloud.php is loaded on its own —
// decryptSecret() is only reached inside cloudCreds() at runtime, so the file
// must not require crypto.php or a connection at load time; if that ever
// changes this test fails to load rather than silently skipping.
//
// The fixtures below are the shapes Meta's webhook documentation gives. The
// normaliser is the edge where Meta's field names become the internal media
// types the Baileys backend produces — getting 'voice' or the chat id format
// wrong is not an error anywhere, it is a message that renders wrong or a
// reply addressed to a chat that does not exist.

require_once __DIR__ . '/../frontend-php/includes/wa-cloud.php';

$passed = 0;
$failed = 0;

function check($name, $condition, $detail = '') {
    global $passed, $failed;
    if ($condition) { $passed++; echo "  ok   {$name}\n"; return; }
    $failed++;
    echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function equals($name, $expected, $actual) {
    check($name, $expected === $actual,
        'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function group($title) { echo "\n{$title}\n"; }

// ---------------------------------------------------------------------------
group('Webhook signature (cloudVerifySignature)');

$body = '{"object":"whatsapp_business_account"}';
$secret = 'myappsecret';
$sig = 'sha256=' . hash_hmac('sha256', $body, $secret);

check('a valid signature verifies', cloudVerifySignature($body, $sig, $secret));
check('a wrong secret does not', !cloudVerifySignature($body, $sig, 'other'));
check('a header without the sha256= prefix does not', !cloudVerifySignature($body, hash_hmac('sha256', $body, $secret), $secret));
check('an empty header does not', !cloudVerifySignature($body, '', $secret));

// ---------------------------------------------------------------------------
group('cloudNormaliseMessage — text and ids');

$n = cloudNormaliseMessage([
    'id' => 'wamid.ABC', 'from' => '923001234567', 'timestamp' => '1700000000',
    'type' => 'text', 'text' => ['body' => 'hello there'],
], ['923001234567' => 'Ayesha']);

equals('chat id is a WhatsApp JID', '923001234567@s.whatsapp.net', $n['chatId']);
equals('phone is digits', '923001234567', $n['phone']);
equals('sender name from contacts', 'Ayesha', $n['senderName']);
equals('text body', 'hello there', $n['text']);
equals('media type text', 'text', $n['mediaType']);
equals('timestamp is UTC datetime', gmdate('Y-m-d H:i:s', 1700000000), $n['timestamp']);
check('no id → null', cloudNormaliseMessage(['from' => '1', 'type' => 'text']) === null);
check('no from → null', cloudNormaliseMessage(['id' => 'x', 'type' => 'text']) === null);

// ---------------------------------------------------------------------------
group('cloudNormaliseMessage — media types');

$n = cloudNormaliseMessage([
    'id' => 'w1', 'from' => '15551234567', 'timestamp' => '1700000000',
    'type' => 'image', 'image' => ['id' => 'MID1', 'mime_type' => 'image/jpeg', 'caption' => 'look'],
]);
equals('image type', 'image', $n['mediaType']);
equals('image caption becomes text', 'look', $n['text']);
equals('image mime', 'image/jpeg', $n['mediaMime']);
equals('cloud media id stored', 'MID1', $n['cloudMediaId']);
equals('media id also in media_meta', 'MID1', $n['mediaMeta']['cloud']['mediaId']);

$n = cloudNormaliseMessage([
    'id' => 'w2', 'from' => '15551234567', 'timestamp' => '1700000000',
    'type' => 'audio', 'audio' => ['id' => 'MID2', 'mime_type' => 'audio/ogg', 'voice' => true],
]);
equals('voice=true is a voice note', 'voice', $n['mediaType']);

$n = cloudNormaliseMessage([
    'id' => 'w3', 'from' => '15551234567', 'timestamp' => '1700000000',
    'type' => 'audio', 'audio' => ['id' => 'MID3', 'mime_type' => 'audio/mpeg', 'voice' => false],
]);
equals('voice=false is plain audio', 'audio', $n['mediaType']);

$n = cloudNormaliseMessage([
    'id' => 'w4', 'from' => '15551234567', 'timestamp' => '1700000000',
    'type' => 'document', 'document' => ['id' => 'MID4', 'mime_type' => 'application/pdf', 'filename' => 'quote.pdf'],
]);
equals('document filename kept', 'quote.pdf', $n['mediaFilename']);

$n = cloudNormaliseMessage([
    'id' => 'w5', 'from' => '15551234567', 'timestamp' => '1700000000',
    'type' => 'location', 'location' => ['latitude' => '24.86', 'longitude' => '67.0', 'name' => 'Clifton', 'address' => 'Karachi'],
]);
equals('location type', 'location', $n['mediaType']);
equals('latitude is a float', 24.86, $n['mediaMeta']['locationInfo']['latitude']);
equals('longitude is a float', 67.0, $n['mediaMeta']['locationInfo']['longitude']);

$n = cloudNormaliseMessage([
    'id' => 'w6', 'from' => '15551234567', 'timestamp' => '1700000000',
    'type' => 'contacts',
    'contacts' => [['name' => ['formatted_name' => 'Bilal Khan'], 'phones' => [['phone' => '+92 300 1112223']]]],
]);
equals('contacts type', 'contact', $n['mediaType']);
equals('contact display name', 'Bilal Khan', $n['mediaMeta']['contactInfo'][0]['displayName']);
check('vcard carries the number',
    str_contains($n['mediaMeta']['contactInfo'][0]['vcard'], 'TEL:+92 300 1112223'));

$n = cloudNormaliseMessage([
    'id' => 'w7', 'from' => '15551234567', 'timestamp' => '1700000000',
    'type' => 'interactive', 'interactive' => ['button_reply' => ['title' => 'Yes, book it']],
]);
equals('interactive button reply becomes text', 'Yes, book it', $n['text']);

$n = cloudNormaliseMessage([
    'id' => 'w8', 'from' => '15551234567', 'timestamp' => '1700000000',
    'type' => 'reaction', 'reaction' => ['emoji' => '👍'],
]);
equals('a reaction is stored as empty text', '', $n['text']);
equals('a reaction has media type text', 'text', $n['mediaType']);

// ---------------------------------------------------------------------------
group('cloudExtractInbound — routing by phone_number_id');

$payload = [
    'entry' => [[
        'changes' => [
            [
                'field' => 'messages',
                'value' => [
                    'metadata' => ['phone_number_id' => '111'],
                    'contacts' => [['wa_id' => '923001234567', 'profile' => ['name' => 'Ayesha']]],
                    'messages' => [
                        ['id' => 'm1', 'from' => '923001234567', 'timestamp' => '1700000000',
                         'type' => 'text', 'text' => ['body' => 'hi']],
                    ],
                    'statuses' => [['id' => 'x', 'status' => 'delivered'], ['id' => 'y', 'status' => 'read']],
                ],
            ],
            [
                'field' => 'messages',
                'value' => [
                    'metadata' => ['phone_number_id' => '999'],
                    'messages' => [['id' => 'm2', 'from' => '1', 'timestamp' => '1', 'type' => 'text', 'text' => ['body' => 'other']]],
                ],
            ],
        ],
    ]],
];

$in = cloudExtractInbound($payload, '111');
equals('only this number\'s messages', 1, count($in['messages']));
equals('statuses counted', 2, $in['statuses']);
equals('other phone_number_id counted as ignored', 1, $in['ignored']);
equals('sender name resolved through contacts', 'Ayesha', $in['messages'][0]['senderName']);

// ---------------------------------------------------------------------------
group('cloudErrorMessage');

equals('24-hour window', 'Outside the 24-hour customer service window — the customer must message first (templates are not supported yet).', cloudErrorMessage(131047, 'x'));
equals('bad token', 'The access token is invalid or expired. Update it under Accounts → Edit.', cloudErrorMessage(190, 'x'));
equals('unknown code passes the raw message through', 'raw text', cloudErrorMessage(99999, 'raw text'));

// ---------------------------------------------------------------------------
group('id and key generation');

check('session id is UUIDv4', (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', cloudNewSessionId()));
check('webhook key is 48 hex chars', (bool)preg_match('/^[0-9a-f]{48}$/', cloudNewKey()));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
