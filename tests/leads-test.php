<?php
// Lead generation: phone normalisation and response parsing (#50).
//
// Run with:  php tests/leads-test.php
//
// No database and no network. includes/leads.php is function definitions only,
// and the two things worth testing here are pure:
//
//   **leadsPhoneDigits()** decides what goes in a wa.me link. Getting it wrong
//   does not produce an error — it produces a working link to the wrong person,
//   which somebody then messages. Google prints numbers in the local format of
//   whatever country the business is in, so every one of those formats is
//   stated here as a case.
//
//   **leadsNormalise()** reads a SerpApi result. Almost every field is
//   optional and several are routinely absent — a business with no website has
//   no `website` key at all, which is the single most valuable signal this tool
//   produces. The fixtures below are the shapes SerpApi's own documentation
//   gives, trimmed.
//
// The HTTP call itself is not tested here: it needs a key and a credit. It is
// exercised by the "Test key" button, which is the honest place for it.

require_once __DIR__ . '/../frontend-php/includes/leads.php';

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
group('A national number gets the configured dial code');

equals('Pakistani mobile with a trunk 0', '923001234567', leadsPhoneDigits('0300 1234567', '92'));
equals('landline with a trunk 0 and dashes', '924235881000', leadsPhoneDigits('042-3588-1000', '92'));
equals('parentheses and spaces, as Google prints them', '924235881000', leadsPhoneDigits('(042) 3588 1000', '92'));
equals('no trunk prefix at all', '923001234567', leadsPhoneDigits('3001234567', '92'));

// ---------------------------------------------------------------------------
group('An international number is left alone');

equals('already +92', '923001234567', leadsPhoneDigits('+92 300 1234567', '92'));
// The important one: a US number found by a UK-configured instance must not be
// given a UK dial code on top of its own.
equals('a +1 number is not re-prefixed with the instance dial code',
    '12012380037', leadsPhoneDigits('+1 201-238-0037', '44'));
equals('the US format Google prints, without a plus', '12012380037', leadsPhoneDigits('(201) 238-0037', '1'));

// Already carries the dial code but no plus — prefixing again would produce
// 9292300..., which dials nowhere.
equals('a bare number already starting with the dial code is not doubled',
    '923001234567', leadsPhoneDigits('92 300 1234567', '92'));

// ---------------------------------------------------------------------------
group('Anything that is not a phone number is refused, not half-converted');

equals('empty', '', leadsPhoneDigits('', '92'));
equals('whitespace only', '', leadsPhoneDigits('   ', '92'));
equals('no digits at all', '', leadsPhoneDigits('call us!', '92'));
// A wrong number in a calling list is worse than a missing one, because
// somebody rings it.
equals('too short to be a number even with the dial code', '', leadsPhoneDigits('123', '9'));
equals('too long for E.164', '', leadsPhoneDigits('+1234567890123456789', '92'));

// ---------------------------------------------------------------------------
group('A full SerpApi result is read correctly');

// Trimmed from SerpApi's own documented google_maps response.
$full = [
    'position' => 1,
    'title' => 'Shambhu Coffee & Gelato',
    'place_id' => 'ChIJC8vfEbJRwokRxWSkJ-0WeAo',
    'data_id' => '0x89c251b2:0xa7816ed2',
    'data_cid' => '75437814',
    'gps_coordinates' => ['latitude' => 40.716051, 'longitude' => -74.0647086],
    'rating' => 5,
    'reviews' => 26,
    'type' => 'Coffee shop',
    'types' => ['Coffee shop', 'Gelato shop'],
    'address' => '667 Grand St Unit B, Jersey City, NJ 07304',
    'open_state' => 'Closes soon · 10 PM · Opens 6 AM Tue',
    'operating_hours' => ['monday' => '6 AM–10 PM', 'sunday' => '7 AM–10 PM'],
    'phone' => '(201) 238-0037',
    'website' => 'https://example.com/shambhu',
    'thumbnail' => 'https://lh3.googleusercontent.com/gps-cs-s/abc',
];

$n = leadsNormalise($full, '1');
equals('title', 'Shambhu Coffee & Gelato', $n['title']);
equals('place_id is the identity', 'ChIJC8vfEbJRwokRxWSkJ-0WeAo', $n['place_id']);
equals('data_cid', '75437814', $n['data_cid']);
equals('address', '667 Grand St Unit B, Jersey City, NJ 07304', $n['address']);
equals('phone is kept as Google printed it', '(201) 238-0037', $n['phone']);
equals('and also as dialable digits', '12012380037', $n['phone_digits']);
equals('website', 'https://example.com/shambhu', $n['website']);
equals('rating', 5.0, $n['rating']);
equals('reviews', 26, $n['reviews']);
equals('types are flattened for display', 'Coffee shop, Gelato shop', $n['types']);
equals('latitude', 40.716051, $n['latitude']);
equals('longitude', -74.0647086, $n['longitude']);
check('operating hours are stored as JSON',
    is_string($n['operating_hours']) && json_decode($n['operating_hours'], true)['monday'] === '6 AM–10 PM');

// ---------------------------------------------------------------------------
group('A sparse result does not invent anything');

// The case the whole tool exists for: a business with no website. SerpApi omits
// the key entirely rather than sending an empty string.
$sparse = ['title' => 'Corner Barber', 'place_id' => 'ChIJsparse'];
$s = leadsNormalise($sparse, '92');

equals('title survives', 'Corner Barber', $s['title']);
check('no website is NULL, not an empty string', $s['website'] === null);
check('no phone is NULL', $s['phone'] === null && $s['phone_digits'] === null);
check('no address is NULL', $s['address'] === null);
check('no coordinates are NULL', $s['latitude'] === null && $s['longitude'] === null);
check('no operating hours are NULL', $s['operating_hours'] === null);

// 0 is "unrated", not a rating. Storing it would sort an unrated business below
// a genuinely terrible one.
$unrated = leadsNormalise(['title' => 'X', 'place_id' => 'p', 'rating' => 0], '92');
check('a zero rating is stored as NULL, not 0.0', $unrated['rating'] === null);

// Without a place_id there is no identity and the row cannot be deduplicated,
// so it is dropped rather than inserted as a duplicate on every search.
check('a result with no place_id is rejected', leadsNormalise(['title' => 'Ghost'], '92') === null);

// ---------------------------------------------------------------------------
group('Long values are truncated to what the columns hold');

$long = leadsNormalise([
    'place_id' => 'p',
    'title'   => str_repeat('a', 400),      // VARCHAR(255)
    'address' => str_repeat('b', 900),      // VARCHAR(500)
    'website' => 'https://x.test/' . str_repeat('c', 900),
], '92');
check('title fits its column', mb_strlen($long['title']) === 255);
check('address fits its column', mb_strlen($long['address']) === 500);
check('website fits its column', mb_strlen($long['website']) === 500);

// ---------------------------------------------------------------------------
group('The API key never leaves the server');

$key = str_repeat('a1b2', 16);   // 64 hex chars, the shape of a SerpApi key
check('a key quoted in an error is redacted',
    !str_contains(leadsScrubKey('failed for api_key=' . $key, $key), $key));
check('a key we were not handed is redacted too',
    !str_contains(leadsScrubKey('nested: ' . str_repeat('f0', 32)), str_repeat('f0', 32)));
equals('a message with no key in it is untouched',
    'SerpApi 429: run out of searches',
    leadsScrubKey('SerpApi 429: run out of searches', $key));

// The pagination URL is handed to the browser, so it must never carry a key.
equals('api_key is stripped from a pagination URL',
    'https://serpapi.com/search.json?engine=google_maps&start=20',
    leadsStripKey('https://serpapi.com/search.json?engine=google_maps&api_key=' . $key . '&start=20'));
check('a URL with no key is left usable',
    leadsStripKey('https://serpapi.com/search.json?engine=google_maps&start=20')
        === 'https://serpapi.com/search.json?engine=google_maps&start=20');
check('nothing in, nothing out', leadsStripKey(null) === null && leadsStripKey('') === null);

// ---------------------------------------------------------------------------
group('The coordinates a search actually ran at are recovered');

// These two fixtures are copied from real responses, because the first one is
// exactly what the original implementation got wrong: a `location` text search
// echoes no `ll` at all, and looking for one recorded NULL in every ledger row.
equals('a location-text search resolves from the maps URL, not from ll',
    '@31.5546061,74.3571581,20000.0m',
    leadsResolvedLl([
        'search_parameters' => [
            'q' => 'dentist',
            'location_requested' => 'Lahore, Pakistan',
            'location_used' => 'Lahore,Punjab,Pakistan',
            'm' => 20000,
        ],
        'search_metadata' => [
            'google_maps_url' => 'https://www.google.com/maps/search/dentist/'
                . '@31.5546061,74.3571581,20000.0m/data=!3m1!4b1',
        ],
    ]));

equals('an explicit ll (and a followed next page) is used directly',
    '@40.7455096,-74.0083012,14z',
    leadsResolvedLl(['search_parameters' => ['ll' => '@40.7455096,-74.0083012,14z']]));

equals('a zoom-style maps URL with a negative longitude',
    '@40.745,-74.008,14z',
    leadsResolvedLl(['search_metadata' =>
        ['google_maps_url' => 'https://www.google.com/maps/search/x/@40.745,-74.008,14z/data=!3m1']]));

check('nothing to find is NULL, not a broken string', leadsResolvedLl([]) === null);
check('a maps URL with no coordinates is NULL',
    leadsResolvedLl(['search_metadata' => ['google_maps_url' => 'https://www.google.com/maps']]) === null);

// ---------------------------------------------------------------------------
group('wa.me links are only built from a number we trust');

equals('a good number becomes a wa.me link',
    'https://wa.me/923001234567', leadWhatsappLink(['phone_digits' => '923001234567']));
equals('a refused number produces no link at all', '', leadWhatsappLink(['phone_digits' => '']));
equals('a missing number produces no link at all', '', leadWhatsappLink([]));

// ---------------------------------------------------------------------------
group('Leads are admin-only by construction');

$app = dirname(__DIR__) . '/frontend-php';

// leads.php is loaded from admin-init.php, not config/init.php, so the
// functions do not exist on a tenant request at all.
$init = file_get_contents($app . '/config/init.php');
check('config/init.php does not load the lead layer', !str_contains($init, 'leads.php'));
$adminInit = file_get_contents($app . '/includes/admin-init.php');
check('admin-init.php does', str_contains($adminInit, "/leads.php'"));

// admin/leads.php is covered by the admin-init rule in admin-access-test.php.
// ajax/leads-search.php is not — it lives outside /admin, so it is the one lead
// route that rule cannot see, and it is checked here instead.
$ajax = file_get_contents($app . '/ajax/leads-search.php');
check('the AJAX search endpoint checks isLoggedIn()', str_contains($ajax, 'isLoggedIn()'));
check('the AJAX search endpoint checks isAdmin()', str_contains($ajax, '!isAdmin()'));
check('and answers 403 rather than redirecting', str_contains($ajax, 'http_response_code(403)'));
check('it verifies the CSRF token', str_contains($ajax, 'csrfTokenValid'));
check('the guards run before the search does',
    strpos($ajax, '!isAdmin()') < strpos($ajax, 'leadsSearch('));

// The schema must not grow a tenant column by accident: the moment `leads` has
// a user_id, somebody will scope a tenant page to it.
$schema = file_get_contents($app . '/sql/schema.sql');
preg_match('/CREATE TABLE IF NOT EXISTS leads \((.*?)\n\) ENGINE/s', $schema, $m);
check('the leads table exists in the schema', !empty($m[1]));
check('and has no user_id column', !empty($m[1]) && !preg_match('/\buser_id\b/', $m[1]));
check('place_id is unique, so a repeated search does not duplicate rows',
    !empty($m[1]) && str_contains($m[1], 'UNIQUE KEY unique_place (place_id)'));

// One credit per request, and no loop that could spend several.
$leads = file_get_contents($app . '/includes/leads.php');
check('leadsSearch() pins the pagination host against SSRF',
    str_contains($leads, "!== 'serpapi.com'") && str_contains($leads, "!== 'https'"));
check('curl never follows a redirect with the key in the query string',
    str_contains($leads, 'CURLOPT_FOLLOWLOCATION => false'));

// ---------------------------------------------------------------------------
echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
