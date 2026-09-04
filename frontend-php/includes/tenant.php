<?php

// One user == one tenant. `users.id` is the tenant key; `t<id>` is how it is
// expressed on the wire and on the backend's filesystem.
//
// This is the ONLY place a tenant id is produced. It is derived from the server
// side session and never from user input, so a request cannot act for another
// tenant by supplying a parameter.
function currentTenantId() {
    if (empty($_SESSION['user_id'])) return null;
    return 't' . (int)$_SESSION['user_id'];
}

function requireTenantId() {
    $tenantId = currentTenantId();
    if ($tenantId === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }
    return $tenantId;
}

// Resolves a session_id to the caller's own account row, or null.
//
// Every backend proxy must call this before forwarding. It is deliberately the
// only lookup available: there is no "find account by session_id" helper that
// omits the tenant, so the unsafe version cannot be reached by accident.
function findOwnedAccount(mysqli $conn, $sessionId, $userId) {
    if (!is_string($sessionId) || $sessionId === '') return null;

    $stmt = $conn->prepare(
        "SELECT id, session_id, label, status FROM wa_accounts WHERE session_id = ? AND user_id = ?"
    );
    $stmt->bind_param('si', $sessionId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

// Rejects the request unless the session belongs to the logged-in tenant.
// Returns [accountId, tenantId, userId].
function requireOwnedAccount(mysqli $conn, $sessionId, $jsonResponse = true) {
    if (!isLoggedIn()) {
        if ($jsonResponse) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        } else {
            http_response_code(401);
            echo 'Unauthorized';
        }
        exit;
    }

    $userId = (int)$_SESSION['user_id'];
    $account = findOwnedAccount($conn, $sessionId, $userId);

    if (!$account) {
        // Same response whether the session does not exist or belongs to
        // someone else — do not confirm the existence of other tenants' ids.
        if ($jsonResponse) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Account not found']);
        } else {
            http_response_code(404);
            echo 'Not found';
        }
        exit;
    }

    return [(int)$account['id'], 't' . $userId, $userId];
}

function logAudit(mysqli $conn, $action, $entity = null, $entityId = null, array $meta = []) {
    $userId = $_SESSION['user_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $metaJson = $meta ? json_encode($meta) : null;

    try {
        $stmt = $conn->prepare(
            "INSERT INTO audit_log (user_id, action, entity, entity_id, ip_address, user_agent, meta)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('issssss', $userId, $action, $entity, $entityId, $ip, $ua, $metaJson);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Auditing must never take the request down with it.
        error_log('audit_log write failed: ' . $e->getMessage());
    }
}
