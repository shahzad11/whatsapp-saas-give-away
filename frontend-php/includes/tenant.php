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

// $actingUserId is for the paths that have no session: the chatbot webhook acts
// for a tenant on WhatsApp's behalf, and an unattributed audit row is not much
// of an audit row.
function logAudit(mysqli $conn, $action, $entity = null, $entityId = null, array $meta = [], $actingUserId = null) {
    $userId = $actingUserId !== null ? (int)$actingUserId : ($_SESSION['user_id'] ?? null);
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

// A readable name for an audit action.
//
// The stored strings are dotted identifiers — `admin.smtp.update`,
// `llm.provider_saved` — which are the right thing to *store*: they are stable,
// filterable, and never change under a translation. They are the wrong thing to
// read a log in, which is what the admin console does with them.
//
// Unknown actions degrade to a de-punctuated version of the identifier rather
// than to nothing, so an action added later is still legible here before anyone
// remembers to add it to the map. The raw string stays visible as a tooltip on
// the page, because it is what you filter and grep by.
function auditActionLabel($action) {
    $map = [
        'login'                        => 'Signed in',
        'register'                     => 'Account registered',
        'register.auto_activated'       => 'Account auto-activated (no email configured)',
        'register.activation_resent'    => 'Activation email resent',
        'profile.update'                => 'Profile updated',
        'profile.password_change'       => 'Password changed',
        'wa_account.link'               => 'WhatsApp account linked',
        'wa_account.relink'             => 'WhatsApp account re-linked',
        'wa_account.unlink'             => 'WhatsApp account removed',
        'chatbot.config_saved'          => 'Chatbot settings saved',
        'handoff.requested'             => 'Handover requested',
        'handoff.claimed'               => 'Handover claimed by an agent',
        'handoff.resolved'              => 'Handover resolved',
        'handoff.abandoned'             => 'Handover abandoned',
        'appointment.booked'            => 'Appointment booked',
        'appointment.cancelled'         => 'Appointment cancelled',
        'appointment.rescheduled'       => 'Appointment rescheduled',
        'appointment.reminded'          => 'Appointment reminder sent',
        'appointment.status_changed'    => 'Appointment status changed',
        'admin.settings.update'         => 'Instance settings updated',
        'admin.plans.relabel_currency'  => 'Plan prices relabelled to a new currency',
        'admin.plan.create'             => 'Plan created',
        'admin.plan.update'             => 'Plan updated',
        'admin.plan.toggle_active'      => 'Plan activated / deactivated',
        'admin.setup.dismissed'         => 'Getting-started checklist hidden',
        'admin.smtp.update'             => 'Email settings updated',
        'admin.smtp.test'               => 'Test email sent',
        'admin.user.suspend'            => 'Tenant suspended',
        'admin.user.activate'           => 'Tenant reactivated',
        'admin.user.change_plan'        => "Tenant's plan changed",
        'admin.user.toggle_admin'       => 'Admin rights granted / revoked',
        'admin.payment.record'          => 'Payment recorded',
        'admin.payment.apply_plan'      => 'Plan applied after payment',
        'admin.payment.reminder_sent'   => 'Renewal reminder sent',
        'llm.provider_saved'            => 'AI provider saved',
        'llm.provider_tested'           => 'AI provider connection tested',
        'llm.model_added'               => 'AI model added',
        'llm.model_toggled'             => 'AI model enabled / disabled',
        'llm.model_deleted'             => 'AI model removed',
        'llm.plan_access_saved'         => 'Which plans may use which model',
        'llm.toggles_saved'             => 'AI instance toggles saved',
    ];

    $action = (string)$action;
    if (isset($map[$action])) return $map[$action];
    return ucfirst(str_replace(['.', '_'], [' — ', ' '], $action));
}
