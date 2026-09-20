<?php
// #47: the tenant's own copy of the calendar card, as a download. The WhatsApp
// card can fail or have nowhere to go — a manual booking on an account with no
// linked number sends nothing — so this is the fallback that always works.
require_once __DIR__ . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);

$appt = $id ? apptById($conn, $userId, $id) : null;
if (!$appt) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$profile = getUserProfile($conn, $userId);
$address = implode(', ', array_filter(array_map('trim', [
    $profile['address_line1'] ?? '', $profile['address_line2'] ?? '',
    $profile['city'] ?? '', $profile['state_region'] ?? '', $profile['postal_code'] ?? '',
])));

// A cancelled booking downloads as a CANCEL event: opening it removes the
// entry, which is the only honest thing that card can mean.
$ics = apptIcsBuild($appt, [
    'method' => ($appt['status'] ?? '') === 'cancelled' ? 'CANCEL' : 'PUBLISH',
    'business_name' => (string)($profile['company_name'] ?? ''),
    'business_address' => $address,
    'host' => parse_url(APP_URL, PHP_URL_HOST) ?: 'whatsapp-bot',
    'now_utc' => gmdate('Y-m-d H:i:s'),
    'contact_line' => 'Reply on WhatsApp to reschedule or cancel.',
]);

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . apptIcsFilename($appt) . '"');
echo $ics;
