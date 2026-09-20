<?php
// One page of lead results, one SerpApi credit (#50).
//
// This endpoint exists so "Load 20 more" does not reload the whole page, and so
// the cost model is visible in the code: it fetches exactly one page per call,
// never loops, and never prefetches. If an admin wants a hundred results they
// press the button five times and the ledger records five credits.
//
// It lives in ajax/ rather than under admin/, which means it does NOT get the
// structural guard from includes/admin-init.php — so it does the same job
// explicitly and immediately, before reading a single parameter. The regression
// test in tests/admin-access-test.php checks this file specifically for that
// reason: it is the one lead route the admin-init rule cannot cover.
require_once dirname(__DIR__) . '/config/init.php';

header('Content-Type: application/json');

// requireAdmin() would redirect, and a 302 to an HTML page is useless to
// fetch(). The checks are the same ones it makes, answered as JSON.
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}
if (!isAdmin()) {
    // Identical for "not an admin" whether or not the feature exists — nothing
    // here confirms to a tenant what lives behind it.
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

// Loaded by hand: this file bootstraps through config/init.php, which
// deliberately does not carry the admin-only lead layer.
require_once dirname(__DIR__) . '/includes/leads.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (!csrfTokenValid($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.']);
    exit;
}

$adminId = (int)$_SESSION['user_id'];
$settings = leadsSettings($conn);

$query    = trim((string)($input['query'] ?? ''));
$location = trim((string)($input['location'] ?? ''));
$nextUrl  = trim((string)($input['next_url'] ?? ''));
// The map pin (#52). Passed through as strings; leadsSearchQuery() owns the
// numeric and range checks, exactly as it owns the empty-area one.
$pinLat = trim((string)($input['lat'] ?? ''));
$pinLng = trim((string)($input['lng'] ?? ''));
$hasPin = $pinLat !== '' && $pinLng !== '';

// A blank query would search for nothing and still cost a credit.
if ($query === '' && $nextUrl === '') {
    echo json_encode(['ok' => false, 'error' => 'Choose a category, or type what to search for.']);
    exit;
}
// Neither would a blank area — worse, that one *succeeds*: Google falls back to
// geolocating SerpApi's datacentre and returns twenty businesses in Virginia.
// Refused here as well as in leadsSearch() so the credit is never spent, and the
// browser is told which field is wrong rather than being shown foreign leads.
// A dropped pin is itself an origin, so it satisfies this guard on its own.
if ($location === '' && $nextUrl === '' && !$hasPin) {
    echo json_encode(['ok' => false, 'error' => 'Type an area to search in or drop a pin on the map.']);
    exit;
}

// The form asks for kilometres because that is the unit a human reasons in;
// SerpApi's `m` is metres. `radius_m` is still accepted so an older cached copy
// of the page keeps working rather than silently searching a 5 km default.
$radiusM = isset($input['radius_km'])
    ? leadsRadiusKmToM($input['radius_km'])
    : ((int)($input['radius_m'] ?? 0) ?: (int)$settings['radius_m']);

$params = [
    'query'      => $query,
    'location'   => $location,
    'radius_m'   => $radiusM,
    'min_rating' => (string)($input['min_rating'] ?? ''),
    'open_now'   => !empty($input['open_now']),
    'next_url'   => $nextUrl,
    'lat'        => $pinLat,
    'lng'        => $pinLng,
];

$result = leadsSearch($conn, $params);

if (!$result['ok']) {
    // Still a 200: the request was well-formed and authorised, and the failure
    // is SerpApi's answer, which the page renders as a message rather than as a
    // broken request.
    echo json_encode(['ok' => false, 'error' => $result['error']]);
    exit;
}

$normalised = [];
foreach ($result['results'] as $raw) {
    $row = leadsNormalise((array)$raw, $settings['dial_code']);
    if ($row !== null) $normalised[] = $row;
}

// The area and radius the search *ran with*, which is not always the area and
// radius the browser sent: page two carries the previous page's, and a blank one
// falls back to the configured default. Recording the request rather than the
// search is how 138 leads ended up in the table with no area against them at all.
$usedLocation = (string)($result['location'] ?? $location);
$usedRadiusM  = (int)($result['radius_m'] ?? $params['radius_m']);

[$new, $seen, $rows] = leadsUpsert($conn, $normalised, $query ?: null, $usedLocation ?: null);

// Written whether or not anything was found — a credit spent on nothing is
// exactly the spend worth being able to see later.
leadsRecordSearch($conn, [
    'query' => $query, 'location' => $usedLocation, 'resolved_ll' => $result['resolved_ll'],
    'radius_m' => $usedRadiusM, 'min_rating' => $params['min_rating'],
    'open_now' => $params['open_now'], 'pages_fetched' => 1, 'credits_used' => 1,
    'results_count' => count($rows), 'new_count' => $new, 'seen_count' => $seen,
    'created_by' => $adminId,
]);

// The audit log records that money was spent and by whom. Never the key.
logAudit($conn, 'admin.leads.search', 'lead_search', null, [
    'query' => $query, 'location' => $usedLocation, 'results' => count($rows),
    'new' => $new, 'credits' => 1,
]);

// Each row is shaped for rendering here rather than in JavaScript, so the
// wa.me rule and the "no website" rule live in PHP with the rest of them.
$payload = array_map(function ($r) use ($query, $usedLocation) {
    return [
        // Carried on every row so the results table can say which search each
        // one belongs to, exactly as the saved-leads table below it does.
        'source_query'    => $query,
        'source_location' => $usedLocation,
        'place_id'  => $r['place_id'],
        'title'     => $r['title'],
        'address'   => $r['address'],
        'phone'     => $r['phone'],
        'wa_link'   => leadWhatsappLink($r),
        'website'   => $r['website'],
        'rating'    => $r['rating'],
        'reviews'   => $r['reviews'],
        'types'     => $r['types'],
        'open_state' => $r['open_state'],
        'thumbnail' => $r['thumbnail'],
        'maps_url'  => 'https://www.google.com/maps/place/?q=place_id:' . rawurlencode($r['place_id']),
        'is_new'    => (bool)$r['is_new'],
    ];
}, $rows);

echo json_encode([
    'ok' => true,
    'results' => $payload,
    'new_count' => $new,
    'seen_count' => $seen,
    // Echoed so the page can state the search it actually ran — the area is the
    // one thing an admin cannot tell from the results themselves until they have
    // already read twenty addresses in the wrong country.
    'query' => $query,
    'location' => $usedLocation,
    'radius_km' => leadsRadiusMToKm($usedRadiusM),
    // Absent when SerpApi has no further page, which is what disables the
    // "Load 20 more" button rather than letting it spend a credit on nothing.
    'next_url' => $result['next'],
    // Echoed so the map can pan to where a typed-area search actually resolved
    // (#52); '@lat,lng' when the pin did the searching.
    'resolved_ll' => $result['resolved_ll'],
]);
