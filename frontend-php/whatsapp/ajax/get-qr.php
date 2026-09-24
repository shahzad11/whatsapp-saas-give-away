<?php
require_once dirname(__DIR__, 2) . '/config/init.php';

header('Content-Type: application/json');

// A session cookie proves a login happened; suspension, deactivation and
// password changes must bite on the next request, so the check is the active
// user guard, not the bare session flag (#3).
requireActiveUserJson();

$sessionId = $_GET['session_id'] ?? '';
if (empty($sessionId)) {
    echo json_encode(['ok' => false, 'error' => 'Missing session_id']);
    exit;
}

[$accountId, $tenantId, $userId] = requireOwnedAccount($conn, $sessionId);

// A Cloud API number has no QR code to show — asking the backend for one
// would only produce a confusing error.
if (waIsCloud($conn, $sessionId)) {
    echo json_encode(['ok' => false, 'error' => 'This account is connected through the Cloud API and has no QR code.']);
    exit;
}

$resp = callBackendApi('GET', '/api/v1/wa/sessions/' . urlencode($sessionId) . '/qr');

if ($resp && ($resp['ok'] ?? false) && ($resp['status'] ?? '') === 'connected') {
    $stmt = $conn->prepare("UPDATE wa_accounts SET status = 'connected' WHERE session_id = ? AND user_id = ?");
    $stmt->bind_param("si", $sessionId, $userId);
    $stmt->execute();
    $stmt->close();
}

echo json_encode($resp ?: ['ok' => false, 'error' => 'Backend error']);
