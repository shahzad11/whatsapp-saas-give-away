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
group('The radius is kilometres on screen and metres on the wire');

equals('5 km is 5000 m', 5000, leadsRadiusKmToM(5));
equals('the default when nothing was typed', LEADS_DEFAULT_RADIUS_M, leadsRadiusKmToM(0));
equals('and the default is 5 km', 5000, LEADS_DEFAULT_RADIUS_M);
// Clamped, not rejected: an admin who defeats the number box should get the
// nearest usable search, not a spent credit and an error.
equals('below the floor is clamped up', 1000, leadsRadiusKmToM(0.2));
equals('above the ceiling is clamped down', 200000, leadsRadiusKmToM(5000));
equals('a negative falls back to the default', LEADS_DEFAULT_RADIUS_M, leadsRadiusKmToM(-8));

equals('metres come back as whole kilometres', 20, leadsRadiusMToKm(20000));
equals('the stored default reads as 5', 5, leadsRadiusMToKm(LEADS_DEFAULT_RADIUS_M));
equals('an absent setting still reads as the default', 5, leadsRadiusMToKm(0));
check('the round trip is lossless for whole kilometres',
    leadsRadiusMToKm(leadsRadiusKmToM(37)) === 37);

// ---------------------------------------------------------------------------
group('A search without an area is refused, not sent');

// The regression this whole change exists for. A blank `location` does not fail
// at SerpApi: it is passed to Google with no origin, Google geolocates the
// request to SerpApi's datacentre in Northern Virginia, and twenty American
// businesses come back looking exactly like a working search. 138 rows in the
// live leads table arrived that way, all of them useless, all of them paid for.
$leads = file_get_contents(dirname(__DIR__) . '/frontend-php/includes/leads.php');
check('leadsSearch() only ever sends `location` set',
    // The `m` line must not be reachable without a non-empty location, so the
    // assignment sits after the guard rather than inside an if ($location !== '').
    !str_contains($leads, "if (\$location !== '') {"));
check('a blank area falls back to the configured default',
    str_contains($leads, "if (\$location === '') \$location = trim((string)\$settings['location']);"));
check('and the search returns before the HTTP call when there is still none',
    strpos($leads, 'No credit was used.') < strpos($leads, 'leadsHttpGet($url)'));
check('the default area is never the empty string', LEADS_DEFAULT_LOCATION !== '');

$ajax = file_get_contents(dirname(__DIR__) . '/frontend-php/ajax/leads-search.php');
check('the endpoint refuses a blank area before spending a credit',
    strpos($ajax, "\$location === '' && \$nextUrl === ''") < strpos($ajax, '$result = leadsSearch('));
check('it accepts kilometres from the form', str_contains($ajax, 'leadsRadiusKmToM($input[\'radius_km\'])'));
check('and still accepts metres, so a cached page does not silently change radius',
    str_contains($ajax, "\$input['radius_m']"));

$page = file_get_contents(dirname(__DIR__) . '/frontend-php/admin/leads.php');
check('the Area box carries a value, not just a placeholder',
    str_contains($page, 'value="<?= sanitize($defaultArea) ?>"'));
check('the radius field is labelled in kilometres', str_contains($page, 'Radius (km)'));
check('the browser refuses an empty area too',
    str_contains($page, "locationInput.value.trim() === ''"));

// ---------------------------------------------------------------------------
group('A lead records which search found it');

check('the upsert keeps source_query and source_location current',
    str_contains($leads, 'source_query = COALESCE(VALUES(source_query), source_query)')
    && str_contains($leads, 'source_location = COALESCE(VALUES(source_location), source_location)'));
check('the ledger records the area the search ran with, not the one sent',
    str_contains($ajax, '$usedLocation = (string)($result[\'location\'] ?? $location);'));
check('the saved-leads table has a Category and an Area column',
    str_contains($page, '<th>Category</th>') && str_contains($page, '<th>Search area</th>'));
check('and the CSV export carries both',
    str_contains($page, "'Category searched', 'Area searched'"));
check('both are filterable', str_contains($page, 'name="category"') && str_contains($page, 'name="area"'));

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
// #3/#21: the gate is the active-user guard now — a *suspended* admin must be
// refused too, which isLoggedIn() could never do.
check('the AJAX search endpoint uses the active-user guard', str_contains($ajax, 'requireActiveUserJson('));
check('the AJAX search endpoint checks the admin flag on the verified user row',
    str_contains($ajax, "(int)\$user['is_admin'] !== 1"));
check('and answers 403 rather than redirecting', str_contains($ajax, 'http_response_code(403)'));
check('it verifies the CSRF token', str_contains($ajax, 'csrfTokenValid'));
check('the guards run before the search does',
    strpos($ajax, "is_admin'] !== 1") < strpos($ajax, 'leadsSearch('));

// The schema must not grow a tenant column by accident: the moment `leads` has
// a user_id, somebody will scope a tenant page to it.
$schema = file_get_contents($app . '/sql/schema.sql');
preg_match('/CREATE TABLE IF NOT EXISTS leads \((.*?)\n\) ENGINE/s', $schema, $m);
check('the leads table exists in the schema', !empty($m[1]));
check('and has no user_id column', !empty($m[1]) && !preg_match('/\buser_id\b/', $m[1]));
check('place_id is unique, so a repeated search does not duplicate rows',
    !empty($m[1]) && str_contains($m[1], 'UNIQUE KEY unique_place (place_id)'));
// An empty lead_areas table is what left the Area box with nothing but a grey
// placeholder to suggest what belongs in it.
check('the schema seeds lead_areas, so the Area box has something to offer',
    str_contains($schema, 'INSERT IGNORE INTO lead_areas')
    && str_contains($schema, "'Lahore, Pakistan'"));

// One credit per request, and no loop that could spend several.
$leads = file_get_contents($app . '/includes/leads.php');
check('leadsSearch() pins the pagination host against SSRF',
    str_contains($leads, "!== 'serpapi.com'") && str_contains($leads, "!== 'https'"));
check('curl never follows a redirect with the key in the query string',
    str_contains($leads, 'CURLOPT_FOLLOWLOCATION => false'));

// ---------------------------------------------------------------------------
group('leadsSearchQuery() builds the origin a search runs with (#52)');

// A settings array shaped exactly like leadsSettings() returns.
$leadsSettings = ['dial_code' => '92', 'hl' => 'en', 'radius_m' => 5000,
                  'location' => 'Lahore, Pakistan'];

// A pin replaces the area outright: SerpApi's google_maps engine cannot take
// `lat`/`lon` together with `location`, so the query must carry one or the
// other, never both.
$pin = leadsSearchQuery(['query' => 'dentist', 'lat' => '31.5204', 'lng' => '74.3587',
                         'radius_m' => 20000], $leadsSettings);
check('a pin builds ok', $pin['ok'] === true);
equals('lat goes to SerpApi as lat', 31.5204, $pin['query']['lat']);
equals('lng goes as lon', 74.3587, $pin['query']['lon']);
equals('the radius is m in metres', 20000, $pin['query']['m']);
check('and no location text is sent with it', !isset($pin['query']['location']));
// The '@lat,lng' label is what the ledger and leads.source_location record —
// the same shape leadsResolvedLl() recovers from a response.
equals('the recorded origin is the @-label', '@31.5204,74.3587', $pin['location']);

// No pin: the typed area goes out as `location`, exactly as before.
$typed = leadsSearchQuery(['query' => 'dentist', 'location' => 'Gujranwala',
                           'radius_m' => 10000], $leadsSettings);
check('a typed area builds ok', $typed['ok'] === true);
equals('it is sent as location', 'Gujranwala', $typed['query']['location']);
check('and still carries m', $typed['query']['m'] === 10000);
check('no pin means no lat/lon', !isset($typed['query']['lat']) && !isset($typed['query']['lon']));

// A coordinate outside the planet is a hand-edited request, not a map click.
$bad = leadsSearchQuery(['query' => 'dentist', 'lat' => '95', 'lng' => '74.35'], $leadsSettings);
check('latitude 95 is refused', $bad['ok'] === false);
equals('with the pin error', 'The map pin has invalid coordinates.', $bad['error']);
check('longitude beyond 180 is refused too',
    leadsSearchQuery(['query' => 'x', 'lat' => '30', 'lng' => '181'], $leadsSettings)['ok'] === false);
check('a non-numeric pin is refused',
    leadsSearchQuery(['query' => 'x', 'lat' => 'abc', 'lng' => '74'], $leadsSettings)['ok'] === false);
// Half a pin is no pin: it falls back to the typed area rather than guessing.
$half = leadsSearchQuery(['query' => 'dentist', 'lat' => '31.5', 'lng' => '',
                          'location' => 'Lahore'], $leadsSettings);
check('a lone lat falls back to the typed area',
    $half['ok'] === true && ($half['query']['location'] ?? '') === 'Lahore' && !isset($half['query']['lat']));

// The pin wins over a typed area still sitting in the box — the input is only
// disabled in the browser, and a request can carry both regardless.
$both = leadsSearchQuery(['query' => 'dentist', 'location' => 'Gujranwala',
                          'lat' => '31.5', 'lng' => '74.3'], $leadsSettings);
check('a pin beats the typed area',
    $both['ok'] === true && isset($both['query']['lat']) && !isset($both['query']['location']));

// Nothing anywhere — no pin, no typed area, no configured default — is the
// Virginia-datacentre bug, refused before a credit exists to be spent.
$none = leadsSearchQuery(['query' => 'dentist'],
    array_merge($leadsSettings, ['location' => '']));
check('no origin at all is refused', $none['ok'] === false);
check('and the message offers the pin as the other way in',
    str_contains($none['error'], 'pin on the map') && str_contains($none['error'], 'No credit was used'));

// ---------------------------------------------------------------------------
group('The recorded area survives a followed pagination URL');

// A typed-area search: SerpApi echoes location_requested, which wins.
equals('location_requested is recorded as-is',
    'Karachi, Pakistan',
    leadsUsedLocation(['search_parameters' => ['location_requested' => 'Karachi, Pakistan']], ''));

// A pin search's page two: no location_requested and no form location, but the
// response still echoes the lat/lon it ran at — the '@lat,lng' label is
// rebuilt from them rather than recorded as NULL.
equals('a pin page falls back to @lat,lon',
    '@31.5,74.3',
    leadsUsedLocation(['search_parameters' => ['lat' => 31.5, 'lon' => 74.3]], ''));

// Nothing in the response at all: the form's own location is the last resort.
equals('an empty body keeps the fallback',
    'Lahore, Pakistan',
    leadsUsedLocation([], 'Lahore, Pakistan'));

// When both are present the echo wins — it is what SerpApi actually ran.
equals('location_requested beats lat/lon',
    'Karachi, Pakistan',
    leadsUsedLocation(['search_parameters' => [
        'location_requested' => 'Karachi, Pakistan', 'lat' => 31.5, 'lon' => 74.3]], ''));

// ---------------------------------------------------------------------------
echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
