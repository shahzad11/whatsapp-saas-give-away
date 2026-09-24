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

$resp = waSessionStatus($conn, $sessionId);
echo json_encode($resp ?: ['ok' => false, 'error' => 'Backend error']);
