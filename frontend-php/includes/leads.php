<?php

// Lead generation through SerpApi's Google Maps engine (issue #50).
//
// The platform owner's prospecting tool: search a category in an area, get back
// businesses with a phone number and a website (or conspicuously without one),
// and call them. Admin-only, everywhere — nothing here is reachable by a
// tenant, and `leads` has no user_id because these are not anybody's contacts.
//
// The rule this file is written to: **a credit is money, so nothing here
// spends one by accident.** There is no automatic pagination, no prefetch, no
// retry on a non-network failure and no "load the next page while you read this
// one". Every request is the direct result of a button an admin pressed, and
// the ledger records what it cost.
//
// The API key is encrypted at rest with the same libsodium secretbox everything
// else uses (includes/crypto.php) and is never rendered back into a form or
// into an error message — leadsScrubKey() runs over every vendor error before
// it is shown, exactly as llmScrubSecret() does for provider keys.

const SERPAPI_ENDPOINT = 'https://serpapi.com/search.json';

// One page of Google Maps results. SerpApi's own recommendation, and the number
// the "Load 20 more" button promises.
const LEADS_PAGE_SIZE = 20;

// The default search size, in metres. 5 km: a city district, which is what an
// admin prospecting one neighbourhood at a time actually wants. The form asks
// for kilometres because that is the unit a human reasons in; metres are what
// SerpApi's `m` takes, so metres are what is stored and passed around.
const LEADS_DEFAULT_RADIUS_M = 5000;

// The area every search falls back to when nothing was typed and nothing was
// configured. Never '': a blank `location` is the bug this constant exists to
// prevent — see leadsSearch().
const LEADS_DEFAULT_LOCATION = 'Lahore, Pakistan';

// --- Configuration ----------------------------------------------------------

// Defaults for the settings the admin can pre-set. They are not in
// appSettingDefaults() for the usual reason: an absent row must resolve here,
// not be masked by a hardcoded row.
function leadsSettings(?mysqli $conn = null) {
    $fallback = [
        'dial_code' => '92',
        'hl'        => 'en',
        'radius_m'  => LEADS_DEFAULT_RADIUS_M,
        'location'  => LEADS_DEFAULT_LOCATION,
    ];
    $db = settingsConn($conn);
    if (!$db) return $fallback;

    $radius = (int)(overrideSetting($db, 'serpapi_radius_m') ?? 0);
    $location = trim((string)(overrideSetting($db, 'serpapi_location') ?? ''));
    return [
        // Pakistan first, like the currency default. Used to turn a local phone
        // number into something wa.me will accept.
        'dial_code' => (string)(overrideSetting($db, 'serpapi_dial_code') ?? '92'),
        'hl'        => (string)(overrideSetting($db, 'serpapi_hl') ?? 'en'),
        'radius_m'  => $radius > 0 ? $radius : LEADS_DEFAULT_RADIUS_M,
        // Pre-fills the Area box and backs the search up when it is empty.
        'location'  => $location !== '' ? $location : LEADS_DEFAULT_LOCATION,
    ];
}

// Kilometres from the form to the metres SerpApi's `m` parameter takes.
//
// Clamped rather than rejected: the number box already restricts the range, and
// an admin who defeats it should get the nearest usable search, not a spent
// credit and an error. 1 km is the floor because a smaller map height returns
// almost nothing while still costing a credit; 200 km is the ceiling because
// beyond that "local business you can call" stops meaning anything.
const LEADS_MIN_RADIUS_KM = 1;
const LEADS_MAX_RADIUS_KM = 200;

function leadsRadiusKmToM($km) {
    $km = (float)$km;
    if ($km <= 0) return LEADS_DEFAULT_RADIUS_M;
    $km = max(LEADS_MIN_RADIUS_KM, min(LEADS_MAX_RADIUS_KM, $km));
    return (int)round($km * 1000);
}

// Metres back to whole kilometres, for painting a stored setting into the form.
function leadsRadiusMToKm($m) {
    $km = (int)round(((int)$m ?: LEADS_DEFAULT_RADIUS_M) / 1000);
    return max(LEADS_MIN_RADIUS_KM, min(LEADS_MAX_RADIUS_KM, $km));
}

function serpApiKey(?mysqli $conn = null) {
    $db = settingsConn($conn);
    if (!$db) return null;
    return decryptSecret(overrideSetting($db, 'serpapi_key_enc'));
}

function serpApiConfigured(?mysqli $conn = null) {
    $key = serpApiKey($conn);
    return is_string($key) && $key !== '';
}

// A key must never reach a screen or a log, including through an error string
// that quotes back the request. SerpApi keys are 64 hex characters; the generic
// pattern catches one we were not handed, in a nested message.
function leadsScrubKey($text, $key = null) {
    $text = (string)$text;
    if (is_string($key) && strlen($key) > 8) $text = str_replace($key, '[redacted]', $text);
    return preg_replace('/\b[0-9a-f]{40,}\b/i', '[redacted]', $text);
}

// --- Normalising what came back ---------------------------------------------

// A phone number as bare E.164 digits, for wa.me and for deduplication.
//
// Google prints numbers the way the country does — "(201) 238-0037",
// "042 111 000 111", "+92 300 1234567" — and none of those forms can be put in
// a wa.me link. Three cases, in order:
//
//   +...        already international; trust it, just strip the punctuation
//   0...        a national trunk prefix; drop the 0 and prepend the dial code
//   anything    assume national, prepend the dial code
//
// Returns '' when there is nothing usable, never a half-converted number: a
// wrong number in a calling list is worse than a missing one, because somebody
// will ring it.
function leadsPhoneDigits($raw, $dialCode = '92') {
    $raw = trim((string)$raw);
    if ($raw === '') return '';

    $dial = preg_replace('/\D+/', '', (string)$dialCode);
    $international = str_starts_with($raw, '+');
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') return '';

    if ($international) {
        // Nothing to prepend — the country code is already in there.
    } elseif (str_starts_with($digits, '0')) {
        $digits = $dial . ltrim($digits, '0');
    } elseif ($dial !== '' && str_starts_with($digits, $dial)) {
        // Already carries the dial code without a '+'. Prepending again would
        // produce "9292300...", which dials nowhere.
    } else {
        $digits = $dial . $digits;
    }

    // E.164 allows 15 digits at most, and anything under 8 is not a phone
    // number — it is an extension or a scrape artefact.
    $len = strlen($digits);
    return ($len >= 8 && $len <= 15) ? $digits : '';
}

// One SerpApi local result, in the shape the `leads` table stores.
//
// Every field is optional in the response and several are routinely absent: a
// business with no website has no `website` key at all, which is exactly the
// signal this tool is looking for. So everything is defaulted rather than
// assumed, and the absence of a website is recorded as NULL and rendered as a
// badge, not as an empty string that reads like a bug.
function leadsNormalise(array $r, $dialCode = '92') {
    $placeId = trim((string)($r['place_id'] ?? ''));
    if ($placeId === '') return null;        // without it there is no identity

    // `types` is the array, `type` the primary one. Stored as a short comma
    // list because it is only ever displayed, never queried.
    $types = $r['types'] ?? ($r['type'] ?? []);
    if (is_string($types)) $types = [$types];
    $types = implode(', ', array_slice(array_filter(array_map('strval', (array)$types)), 0, 4));

    $phone = trim((string)($r['phone'] ?? ''));
    $rating = isset($r['rating']) ? (float)$r['rating'] : null;
    $reviews = isset($r['reviews']) ? (int)$r['reviews'] : null;

    return [
        'place_id'        => mb_substr($placeId, 0, 128),
        'data_cid'        => mb_substr(trim((string)($r['data_cid'] ?? '')), 0, 64) ?: null,
        'title'           => mb_substr(trim((string)($r['title'] ?? 'Untitled')), 0, 255),
        'address'         => mb_substr(trim((string)($r['address'] ?? '')), 0, 500) ?: null,
        'phone'           => mb_substr($phone, 0, 64) ?: null,
        'phone_digits'    => leadsPhoneDigits($phone, $dialCode) ?: null,
        'website'         => mb_substr(trim((string)($r['website'] ?? '')), 0, 500) ?: null,
        // 0 is not a rating, it is "unrated", and storing it would make an
        // unrated business sort below a genuinely terrible one.
        'rating'          => ($rating !== null && $rating > 0) ? $rating : null,
        'reviews'         => $reviews !== null && $reviews >= 0 ? $reviews : null,
        'types'           => mb_substr($types, 0, 255) ?: null,
        'open_state'      => mb_substr(trim((string)($r['open_state'] ?? '')), 0, 120) ?: null,
        'operating_hours' => !empty($r['operating_hours']) && is_array($r['operating_hours'])
            ? json_encode($r['operating_hours']) : null,
        'latitude'        => isset($r['gps_coordinates']['latitude']) ? (float)$r['gps_coordinates']['latitude'] : null,
        'longitude'       => isset($r['gps_coordinates']['longitude']) ? (float)$r['gps_coordinates']['longitude'] : null,
        'thumbnail'       => mb_substr(trim((string)($r['thumbnail'] ?? '')), 0, 1000) ?: null,
    ];
}

// --- Talking to SerpApi -----------------------------------------------------

// One page. One credit. Never more than one of either.
//
// $params:
//   query       required search text ("dentist")
//   location    free-form area text ("Lahore, Pakistan")
//   lat, lng    a map pin (#52). Present together, they replace `location`
//               entirely — SerpApi cannot take both on one search.
//   radius_m    map height in metres, encoded as SerpApi's `m`
//   min_rating  2.0 – 4.5, or null
//   open_now    bool
//   next_url    a serpapi_pagination.next URL, for page 2 onwards
//
// Returns ['ok' => bool, 'error' => string, 'results' => [...raw...],
//          'next' => string|null, 'resolved_ll' => string|null,
//          'location' => string, 'radius_m' => int].
//
// `location` and `radius_m` come back because they are not always what went in:
// a blank area falls back to the configured default, and a followed `next` URL
// carries the previous page's area rather than the form's. The ledger and the
// `leads.source_location` column record what the search actually ran with, not
// what the browser happened to send.
//
// When next_url is given, every other parameter is ignored: that URL already
// carries the resolved coordinates, and rebuilding the query from the form
// would search a slightly different place on page two. This is why pagination
// follows `next` rather than incrementing `start` — a `location` text is
// resolved to an `ll` by SerpApi, and only the returned URL knows what it
// resolved to.
// Builds the SerpApi query for a first-page search (#52).
//
// Split out of leadsSearch() so the "where does this search happen" rule is
// testable without a database or a network. There are exactly two origins a
// search can have:
//
//   - **A map pin** (`lat` + `lng` both supplied): SerpApi's google_maps engine
//     takes `lat`/`lon`/`m` as the origin — and CANNOT combine them with
//     `location`, so when a pin is present it wins outright, even over a typed
//     area that was left filled in. The location label returned for the ledger
//     is "@lat,lng", which is also what leadsResolvedLl() emits, so a pin
//     search and a text search are distinguishable in `leads.source_location`.
//   - **A typed area**, else the configured default. Never nothing: without an
//     origin SerpApi sends the query to Google with no `location` at all, and
//     Google geolocates it from the IP address that asked — SerpApi's own
//     datacentre, in Northern Virginia. The search succeeds, returns twenty
//     real businesses, spends a credit, and every one of them is 11,000 km
//     from the admin who searched. It looks like working software, which is
//     why it went unnoticed: the Area box showed a grey "Lahore, Pakistan"
//     placeholder that reads exactly like a value.
//
// `m` (map height in metres) accompanies either origin — without `z` or `m`
// SerpApi rejects the search — and a radius is a distance an admin can reason
// about while a zoom level is not.
//
// Returns ['ok' => true, 'query' => [...], 'location' => string,
//          'radius_m' => int] or ['ok' => false, 'error' => string].
// `api_key` is deliberately absent from the query: it is appended by
// leadsSearch(), so this helper never handles the credential.
function leadsSearchQuery(array $params, array $settings) {
    $query = [
        'engine' => 'google_maps',
        'type'   => 'search',
        'q'      => (string)($params['query'] ?? ''),
        'hl'     => $settings['hl'],
    ];

    $radiusM = max(1, (int)($params['radius_m'] ?? 0) ?: (int)$settings['radius_m']);

    $lat = trim((string)($params['lat'] ?? ''));
    $lng = trim((string)($params['lng'] ?? ''));
    if ($lat !== '' && $lng !== '') {
        // A pin must be a real point on the planet; a dragged marker only ever
        // produces one, so anything else arrived by hand-editing the request.
        if (!is_numeric($lat) || !is_numeric($lng)
            || (float)$lat < -90 || (float)$lat > 90
            || (float)$lng < -180 || (float)$lng > 180) {
            return ['ok' => false, 'error' => 'The map pin has invalid coordinates.'];
        }
        // Six decimals is ~0.1 m — finer than a click can express, and matches
        // the precision leadsResolvedLl() sees echoed back.
        $lat = round((float)$lat, 6);
        $lng = round((float)$lng, 6);
        $query['lat'] = $lat;
        $query['lon'] = $lng;
        $query['m'] = $radiusM;
        $location = '@' . $lat . ',' . $lng;
    } else {
        // So: the typed area, else the configured default, and never nothing —
        // unless a pin took over above.
        $location = trim((string)($params['location'] ?? ''));
        if ($location === '') $location = trim((string)$settings['location']);
        if ($location === '') {
            return ['ok' => false, 'error' => 'Type an area or drop a pin on the map — without one Google guesses the '
                             . 'location and returns businesses from the wrong country. '
                             . 'No credit was used.'];
        }
        $query['location'] = $location;
        $query['m'] = $radiusM;
    }

    // Only the values SerpApi documents. A rating it does not recognise is
    // dropped rather than sent, because a rejected search still costs the
    // round trip and confuses the admin about which field was wrong.
    $minRating = (string)($params['min_rating'] ?? '');
    if (in_array($minRating, ['2.0', '2.5', '3.0', '3.5', '4.0', '4.5'], true)) {
        $query['min_rating'] = $minRating;
    }
    if (!empty($params['open_now'])) $query['open_state'] = 'now';

    return ['ok' => true, 'query' => $query, 'location' => $location, 'radius_m' => $radiusM];
}

function leadsSearch(mysqli $conn, array $params) {
    $key = serpApiKey($conn);
    if (!is_string($key) || $key === '') {
        return ['ok' => false, 'error' => 'No SerpApi key is configured. Add one in Settings.',
                'results' => [], 'next' => null, 'resolved_ll' => null];
    }

    $settings = leadsSettings($conn);
    $nextUrl = trim((string)($params['next_url'] ?? ''));
    $location = '';
    $radiusM = 0;

    if ($nextUrl !== '') {
        // Only ever a URL SerpApi handed us on the previous page, and it is
        // checked rather than trusted: it arrives via the browser, and a
        // fetch() to an attacker-chosen host carrying our api_key would be a
        // credential leak (SSRF). The host and scheme are pinned.
        $parts = parse_url($nextUrl);
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'serpapi.com') {
            return ['ok' => false, 'error' => 'Refusing to follow a pagination link that is not SerpApi.',
                    'results' => [], 'next' => null, 'resolved_ll' => null];
        }
        // The next URL carries every search parameter but not the key.
        $url = $nextUrl . '&api_key=' . urlencode($key);
    } else {
        $built = leadsSearchQuery($params, $settings);
        if (!$built['ok']) {
            return ['ok' => false, 'results' => [], 'next' => null, 'resolved_ll' => null,
                    'error' => $built['error']];
        }
        $location = $built['location'];
        $radiusM = $built['radius_m'];
        // The key is appended here rather than inside leadsSearchQuery(), so the
        // pure helper — and anything that ever logs its output — never sees it.
        $built['query']['api_key'] = $key;

        $url = SERPAPI_ENDPOINT . '?' . http_build_query($built['query']);
    }

    [$status, $body, $err] = leadsHttpGet($url);

    if ($err !== null) {
        return ['ok' => false, 'error' => leadsScrubKey($err, $key),
                'results' => [], 'next' => null, 'resolved_ll' => null];
    }
    if ($status === 401) {
        return ['ok' => false, 'error' => 'SerpApi rejected the key (401). Check it in Settings.',
                'results' => [], 'next' => null, 'resolved_ll' => null];
    }
    if ($status !== 200 || !is_array($body)) {
        $message = is_array($body) ? (string)($body['error'] ?? '') : '';
        return ['ok' => false, 'results' => [], 'next' => null, 'resolved_ll' => null,
                'error' => leadsScrubKey('SerpApi ' . $status . ($message !== '' ? ': ' . $message : ''), $key)];
    }
    // A 200 with an `error` key is how SerpApi reports "no results found" and
    // several parameter problems, so it has to be read even on success.
    if (!empty($body['error'])) {
        return ['ok' => false, 'error' => leadsScrubKey((string)$body['error'], $key),
                'results' => [], 'next' => null, 'resolved_ll' => null];
    }

    $resolvedLl = leadsResolvedLl($body);

    return [
        'ok' => true,
        'error' => '',
        'results' => is_array($body['local_results'] ?? null) ? $body['local_results'] : [],
        // Scrubbed before it leaves: the next URL is handed to the browser and
        // must not carry the key even though we appended one to the request.
        'next' => leadsStripKey($body['serpapi_pagination']['next'] ?? null),
        'resolved_ll' => $resolvedLl !== null ? mb_substr($resolvedLl, 0, 64) : null,
        // SerpApi echoes the area it was asked for as `location_requested`, so a
        // followed pagination URL can say which area page two was of.
        'location' => leadsUsedLocation($body, $location),
        'radius_m' => (int)($body['search_parameters']['m'] ?? $radiusM),
    ];
}

// The coordinates the search actually ran at.
//
// This is the thing a `location` text search cannot be repeated without, and
// finding it is fiddlier than it looks. Verified against live responses:
//
//   - Passing `location=Lahore, Pakistan` does **not** produce an `ll` in
//     `search_parameters`. SerpApi echoes `location_requested` and
//     `location_used` instead, and the resolved coordinates appear only inside
//     `search_metadata.google_maps_url`, in Google's own `@lat,lng,zoom` form —
//     not as an `ll=` query parameter.
//   - Passing `ll` directly, and following a `serpapi_pagination.next` URL,
//     *do* echo `ll` in `search_parameters`.
//
// So both shapes are read, `ll` first. An earlier version looked only for
// `ll=` in the URL, which matched neither, and every ledger row recorded NULL.
function leadsResolvedLl(array $body) {
    if (!empty($body['search_parameters']['ll'])) {
        return mb_substr((string)$body['search_parameters']['ll'], 0, 64);
    }

    $url = (string)($body['search_metadata']['google_maps_url'] ?? '');
    // @31.5546061,74.3571581,20000.0m  or  @40.745,-74.008,14z
    if (preg_match('/@(-?\d+\.?\d*),(-?\d+\.?\d*),([\d.]+[a-z])/i', $url, $m)) {
        return mb_substr('@' . $m[1] . ',' . $m[2] . ',' . $m[3], 0, 64);
    }
    return null;
}

// The area a search is recorded under — what `leads.source_location` and the
// ledger's `location` store. `location_requested` covers typed-area searches
// and their followed pages, but a pin search's `next` URL echoes neither it
// nor the form's (empty) location; what it does echo is the `lat`/`lon` the
// page ran at, so the '@lat,lng' label is rebuilt from them rather than
// recording NULL.
function leadsUsedLocation(array $body, string $fallback): string {
    $sp = $body['search_parameters'] ?? [];
    if (is_array($sp)) {
        $requested = trim((string)($sp['location_requested'] ?? ''));
        if ($requested !== '') return mb_substr($requested, 0, 200);
        if (isset($sp['lat'], $sp['lon'])) {
            return mb_substr('@' . (string)(float)$sp['lat'] . ',' . (string)(float)$sp['lon'], 0, 200);
        }
    }
    return mb_substr($fallback, 0, 200);
}

// Removes api_key from a URL before it is sent to the browser. SerpApi does not
// include it in `serpapi_pagination.next`, but this is the one place a mistake
// would publish the key to every admin's page source, so it is not left to
// their behaviour staying the same.
function leadsStripKey($url) {
    if (!is_string($url) || $url === '') return null;
    return preg_replace('/([?&])api_key=[^&]*(&|$)/', '$1', $url);
}

function leadsHttpGet($url, $timeout = 30) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        // A fixed, known host. Never follow it somewhere else — a redirect
        // would carry the api_key in the query string with it.
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) return [0, null, $err ?: 'Request failed'];
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) return [$status, null, 'SerpApi returned a non-JSON response'];
    return [$status, $decoded, null];
}

// Spends one credit to find out whether the key works.
//
// Deliberately a real search, because that is the only thing that proves a key
// is both valid and in credit — SerpApi has no free "verify" endpoint, and a
// key that authenticates but has run out of searches would pass a cheaper check
// and fail the first real one. The button says it costs a credit.
function leadsTestKey(mysqli $conn, $candidateKey = null) {
    $key = ($candidateKey !== null && $candidateKey !== '') ? $candidateKey : serpApiKey($conn);
    if (!is_string($key) || $key === '') return [false, 'No key to test.'];

    // Run against the configured area rather than a hardcoded one, so the test
    // proves the area works as well as the key: an area SerpApi cannot resolve
    // fails here, once, instead of on every real search afterwards.
    $settings = leadsSettings($conn);
    $url = SERPAPI_ENDPOINT . '?' . http_build_query([
        'engine' => 'google_maps', 'type' => 'search', 'q' => 'coffee',
        'location' => $settings['location'], 'm' => 5000,
        'hl' => $settings['hl'], 'api_key' => $key,
    ]);
    [$status, $body, $err] = leadsHttpGet($url);

    if ($err !== null) return [false, leadsScrubKey($err, $key)];
    if ($status === 401) return [false, 'Key rejected (401). Check that it was pasted in full.'];
    if ($status !== 200) {
        return [false, leadsScrubKey('SerpApi returned ' . $status
            . (is_array($body) && !empty($body['error']) ? ': ' . $body['error'] : ''), $key)];
    }
    if (!empty($body['error'])) return [false, leadsScrubKey((string)$body['error'], $key)];

    $count = count($body['local_results'] ?? []);
    return [true, 'Key works — the test search returned ' . $count . ' result(s). One credit was used.'];
}

// --- Storing what came back -------------------------------------------------

// Insert new businesses, refresh the ones already known.
//
// `first_seen_at` is never updated and `last_seen_at` always is: together they
// are what makes "new" mean something on the results page. Everything else is
// overwritten, because Google's copy is newer than ours — a clinic that has
// since added a website should stop showing as one without.
//
// Returns [$newCount, $seenCount, $rowsWithFlag] where each row carries an
// `is_new` flag for rendering.
function leadsUpsert(mysqli $conn, array $normalised, $sourceQuery = null, $sourceLocation = null) {
    if (!$normalised) return [0, 0, []];

    // Which of these we already had, asked once rather than once per row.
    $ids = array_column($normalised, 'place_id');
    $known = [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT place_id FROM leads WHERE place_id IN ($placeholders)");
    $stmt->bind_param(str_repeat('s', count($ids)), ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $known[$row['place_id']] = true;
    $stmt->close();

    $sql = "INSERT INTO leads
                (place_id, data_cid, title, address, phone, phone_digits, website, rating,
                 reviews, types, open_state, operating_hours, latitude, longitude, thumbnail,
                 source_query, source_location, first_seen_at, last_seen_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                data_cid = VALUES(data_cid), title = VALUES(title), address = VALUES(address),
                phone = VALUES(phone), phone_digits = VALUES(phone_digits),
                website = VALUES(website), rating = VALUES(rating), reviews = VALUES(reviews),
                types = VALUES(types), open_state = VALUES(open_state),
                operating_hours = VALUES(operating_hours), latitude = VALUES(latitude),
                longitude = VALUES(longitude), thumbnail = VALUES(thumbnail),
                -- Moved forward with last_seen_at, not frozen with first_seen_at:
                -- these two columns answer \"which search put this in front of me\",
                -- and the search the admin just ran is the one they are reading
                -- the table after. COALESCE so a page-2 row that arrived without
                -- context cannot blank a column that already had some.
                source_query = COALESCE(VALUES(source_query), source_query),
                source_location = COALESCE(VALUES(source_location), source_location),
                last_seen_at = NOW()";
    $stmt = $conn->prepare($sql);

    $new = 0;
    $seen = 0;
    $out = [];
    foreach ($normalised as $r) {
        $isNew = !isset($known[$r['place_id']]);
        $isNew ? $new++ : $seen++;

        // Types derived from the values rather than a hand-written positional
        // string: this binds 17 parameters, which is well past the length at
        // which a literal 'ssssss…' stops being reviewable. A NULL binds as 's',
        // which is correct — MySQL stores NULL regardless of declared type.
        $params = [
            $r['place_id'], $r['data_cid'], $r['title'], $r['address'], $r['phone'],
            $r['phone_digits'], $r['website'], $r['rating'], $r['reviews'], $r['types'],
            $r['open_state'], $r['operating_hours'], $r['latitude'], $r['longitude'],
            $r['thumbnail'], $sourceQuery, $sourceLocation,
        ];
        $types = '';
        foreach ($params as $p) $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $out[] = $r + ['is_new' => $isNew];
    }
    $stmt->close();

    return [$new, $seen, $out];
}

// The ledger row for one page fetched. Written whether or not anything was
// found: a search that cost a credit and returned nothing is exactly the kind
// of spend worth being able to see.
function leadsRecordSearch(mysqli $conn, array $row) {
    $stmt = $conn->prepare(
        "INSERT INTO lead_searches
            (query, location, resolved_ll, radius_m, min_rating, open_now,
             pages_fetched, credits_used, results_count, new_count, seen_count, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $minRating = ($row['min_rating'] ?? '') !== '' ? (float)$row['min_rating'] : null;
    $params = [
        (string)($row['query'] ?? ''),
        ($row['location'] ?? '') !== '' ? (string)$row['location'] : null,
        $row['resolved_ll'] ?? null,
        (int)($row['radius_m'] ?? 0) ?: null,
        $minRating,
        !empty($row['open_now']) ? 1 : 0,
        (int)($row['pages_fetched'] ?? 1),
        (int)($row['credits_used'] ?? 1),
        (int)($row['results_count'] ?? 0),
        (int)($row['new_count'] ?? 0),
        (int)($row['seen_count'] ?? 0),
        (int)($row['created_by'] ?? 0) ?: null,
    ];
    $types = '';
    foreach ($params as $p) $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
}

// --- Reading them back ------------------------------------------------------

function leadCategories(mysqli $conn, $onlyActive = true) {
    $sql = "SELECT * FROM lead_categories" . ($onlyActive ? " WHERE is_active = 1" : "")
         . " ORDER BY sort_order, label";
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function leadAreas(mysqli $conn, $onlyActive = true) {
    $sql = "SELECT * FROM lead_areas" . ($onlyActive ? " WHERE is_active = 1" : "")
         . " ORDER BY sort_order, label";
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

// The distinct categories and areas that actually produced saved leads.
//
// Read off `leads` rather than off `lead_categories` / `lead_areas` on purpose:
// the filter must offer what is in the table, including a free-typed query that
// was never a saved category, and must not offer a category nothing was ever
// found under. Two columns, one query, so the page makes one round trip.
function leadsSourceValues(mysqli $conn) {
    $out = ['categories' => [], 'areas' => [],
            'unrecorded' => ['categories' => 0, 'areas' => 0]];
    $res = $conn->query(
        "SELECT 'c' AS kind, source_query AS value, COUNT(*) AS n FROM leads
          WHERE source_query IS NOT NULL AND source_query <> '' GROUP BY source_query
         UNION ALL
         SELECT 'a' AS kind, source_location AS value, COUNT(*) AS n FROM leads
          WHERE source_location IS NOT NULL AND source_location <> '' GROUP BY source_location
         UNION ALL
         SELECT 'cn' AS kind, '-' AS value, COUNT(*) AS n FROM leads
          WHERE source_query IS NULL OR source_query = ''
         UNION ALL
         SELECT 'an' AS kind, '-' AS value, COUNT(*) AS n FROM leads
          WHERE source_location IS NULL OR source_location = ''
         ORDER BY kind, value"
    );
    while ($row = $res->fetch_assoc()) {
        // 'cn'/'an' are the NULL-column counts behind each "Not recorded"
        // filter option, not values to list.
        if ($row['kind'] === 'cn') { $out['unrecorded']['categories'] = (int)$row['n']; continue; }
        if ($row['kind'] === 'an') { $out['unrecorded']['areas'] = (int)$row['n']; continue; }
        $out[$row['kind'] === 'c' ? 'categories' : 'areas'][] =
            ['value' => $row['value'], 'n' => (int)$row['n']];
    }
    return $out;
}

// The saved leads, filtered. Used by both the table and the CSV export, so the
// two can never show different rows — which is the whole reason it is one
// function taking the same $filters the query string carries.
// The WHERE clause shared by leadsList() and leadsCount(), built once so the
// two can never disagree about which rows a filter set selects. Returns the
// clause without the WHERE keyword, plus the bind types and args.
function leadsWhere(array $filters): array {
    $where = [];
    $types = '';
    $args = [];

    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        // source_query and source_location are searched too, so typing "dentist"
        // or "Lahore" finds the leads a search for that turned up even when the
        // words appear nowhere in the business's own name or address.
        $where[] = '(title LIKE ? OR address LIKE ? OR types LIKE ?'
                 . ' OR source_query LIKE ? OR source_location LIKE ?)';
        $like = '%' . $q . '%';
        $types .= 'sssss';
        array_push($args, $like, $like, $like, $like, $like);
    }
    // Exact-match origin filters, fed by the dropdowns leadsSourceValues() fills.
    foreach (['category' => 'source_query', 'area' => 'source_location'] as $filter => $column) {
        $value = trim((string)($filters[$filter] ?? ''));
        if ($value === '') continue;
        if ($value === '-') {           // "not recorded" — the pre-fix rows
            $where[] = "($column IS NULL OR $column = '')";
            continue;
        }
        $where[] = "$column = ?";
        $types .= 's';
        $args[] = $value;
    }
    // 'no_website' is the interesting one and the reason this tool exists: a
    // business with no website is the easiest sale for a WhatsApp bot.
    if (($filters['website'] ?? '') === 'no') {
        $where[] = "(website IS NULL OR website = '')";
    } elseif (($filters['website'] ?? '') === 'yes') {
        $where[] = "(website IS NOT NULL AND website <> '')";
    }
    if (($filters['phone'] ?? '') === 'yes') {
        $where[] = "(phone_digits IS NOT NULL AND phone_digits <> '')";
    }
    if (($filters['new'] ?? '') === 'yes') {
        // "New" in the list sense: first seen in the last 24 hours.
        $where[] = 'first_seen_at > DATE_SUB(NOW(), INTERVAL 1 DAY)';
    }
    $minRating = (float)($filters['min_rating'] ?? 0);
    if ($minRating > 0) {
        $where[] = 'rating >= ?';
        $types .= 'd';
        $args[] = $minRating;
    }
    return [implode(' AND ', $where), $types, $args];
}

function leadsCount(mysqli $conn, array $filters = []): int {
    [$whereSql, $types, $args] = leadsWhere($filters);
    $sql = 'SELECT COUNT(*) FROM leads' . ($whereSql !== '' ? ' WHERE ' . $whereSql : '');
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return (int)($row[0] ?? 0);
}

function leadsList(mysqli $conn, array $filters = [], $limit = 500, $offset = 0) {
    [$whereSql, $types, $args] = leadsWhere($filters);
    $sql = 'SELECT * FROM leads' . ($whereSql !== '' ? ' WHERE ' . $whereSql : '');

    $sorts = [
        'recent'  => 'last_seen_at DESC',
        'new'     => 'first_seen_at DESC',
        'rating'  => 'rating IS NULL, rating DESC, reviews DESC',
        'reviews' => 'reviews IS NULL, reviews DESC',
        'name'    => 'title ASC',
    ];
    $sql .= ' ORDER BY ' . ($sorts[$filters['sort'] ?? 'recent'] ?? $sorts['recent']);
    $sql .= ' LIMIT ' . max(1, (int)$limit);
    if ((int)$offset > 0) $sql .= ' OFFSET ' . (int)$offset;

    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// A wa.me link, or '' when there is no usable number. Never a link to a number
// we had to guess at — leadsPhoneDigits() already refused those.
function leadWhatsappLink(array $lead) {
    $digits = (string)($lead['phone_digits'] ?? '');
    return $digits === '' ? '' : 'https://wa.me/' . $digits;
}
