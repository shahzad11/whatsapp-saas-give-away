<?php
require_once dirname(__DIR__, 2) . '/config/init.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$sessionId = $_GET['session_id'] ?? '';
if (empty($sessionId)) {
    echo json_encode(['ok' => false, 'error' => 'Missing session_id']);
    exit;
}

[$accountId, $tenantId, $userId] = requireOwnedAccount($conn, $sessionId);

$resp = callBackendApi('GET', '/api/v1/wa/sessions/' . urlencode($sessionId) . '/qr');

if ($resp && ($resp['ok'] ?? false) && ($resp['status'] ?? '') === 'connected') {
    $stmt = $conn->prepare("UPDATE wa_accounts SET status = 'connected' WHERE session_id = ? AND user_id = ?");
    $stmt->bind_param("si", $sessionId, $userId);
    $stmt->execute();
    $stmt->close();
}

echo json_encode($resp ?: ['ok' => false, 'error' => 'Backend error']);
