<?php
require_once dirname(__DIR__, 2) . '/config/init.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

// This endpoint sends a WhatsApp message, so it is the last place that should
// take an unauthenticated-in-intent request. `session.cookie_samesite = "Lax"`
// stops a cross-site POST from carrying the session cookie, but that is a
// browser-side mitigation and a Content-Type the preflight ignores (a form with
// enctype="text/plain" can produce a body that json_decode accepts). Every other
// mutating endpoint here checks the token; this one did not.
if (!csrfTokenValid($input['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid request. Reload the page and try again.']);
    exit;
}

$sessionId = $input['session_id'] ?? '';
$chatId = $input['chat_id'] ?? '';
$text = trim($input['text'] ?? '');

if (empty($sessionId) || empty($chatId) || empty($text)) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

[$accountId, $tenantId, $userId] = requireOwnedAccount($conn, $sessionId);

// Metered: enforce the plan's monthly send limit before touching the backend.
[$quotaOk, $used, $limit] = checkMessageQuota($conn, $userId);
if (!$quotaOk) {
    echo json_encode([
        'ok' => false,
        'error' => 'Monthly message limit reached (' . number_format($limit) . '). Upgrade your plan to send more.',
        'quotaExceeded' => true,
    ]);
    exit;
}

$resp = waSendText($conn, $sessionId, $chatId, $text, null, 30);

// Only count sends that actually left the building.
if ($resp && !empty($resp['ok'])) {
    incrementUsage($conn, $userId, 'messages_sent');
}

echo json_encode($resp ?: ['ok' => false, 'error' => 'Backend error']);
