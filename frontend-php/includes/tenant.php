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

// --- Creating a tenant (#48, #49) -------------------------------------------
//
// The only way a tenant comes into existence. register.php used to be the other
// one and is gone: this instance is invite-only, and an account that links
// somebody's WhatsApp is not something a stranger may create for themselves.
//
// It lives here rather than in admin/tenants.php because the rules — a unique
// address, a plan that exists, a password nobody knows yet, an audit row — are
// properties of *making a tenant*, not of the page that happens to ask. A
// second caller (a CLI seeder, an importer) must not be able to make one
// without them.

// How long an invitation is good for. Far longer than the one hour
// forgot-password.php uses, because the two are not the same event: a reset is
// something you asked for thirty seconds ago and are waiting on, an invitation
// is something that arrives unannounced in a business inbox and may not be read
// until Monday. Still bounded, because it sets a password.
const TENANT_INVITE_HOURS = 168;   // 7 days

// A password no one has, for an account that is about to be sent an invitation.
//
// The column is NOT NULL, and the account has to exist before the invitation
// can point at it, so *something* must be stored. This is 64 random bytes run
// through the normal hash: there is no plaintext anywhere, and nothing can
// authenticate as this account until the invitation is used.
function unusablePassword() {
    return password_hash(bin2hex(random_bytes(64)), PASSWORD_DEFAULT);
}

// A temporary password an admin can read down a phone line.
//
// Deliberately not a hex token: it gets transcribed by a human, so it avoids
// the characters that get misheard or mistyped (0/O, 1/l/I) and stays short
// enough to say out loud. It is single-use in practice — must_change_password
// forces it to be replaced at first login — so its job is to survive one
// transcription, not to resist offline attack.
function temporaryPassword() {
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    // 12 characters over a 55-character alphabet is ~69 bits, which is far more
    // than a value that must be changed at first login needs, and still costs
    // nothing to type.
    for ($i = 0; $i < 12; $i++) $out .= $alphabet[random_int(0, $max)];
    return $out;
}

// Validates and normalises what the admin typed. Split from the insert so the
// rules can be tested without a database, and so the form can be redisplayed
// with the same messages the AJAX path shows.
//
// Returns [$clean, $errors]. $errors is keyed by field name, which is the
// contract includes/ajax.php's formRespond() expects.
function validateTenantInput(array $input) {
    $errors = [];

    $name = trim((string)($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) {
        $errors['name'] = 'Required, max 100 characters.';
    }

    $email = strtolower(trim((string)($input['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    } elseif (mb_strlen($email) > 255) {
        // The column is VARCHAR(255); a longer address would be truncated into
        // a different address, which is worse than refusing it.
        $errors['email'] = 'Too long (max 255 characters).';
    }

    $company = trim((string)($input['company_name'] ?? ''));
    if (mb_strlen($company) > 150) {
        $errors['company_name'] = 'Too long (max 150 characters).';
    }

    $status = (string)($input['status'] ?? 'active');
    if (!in_array($status, ['active', 'suspended'], true)) {
        $errors['status'] = 'Choose active or suspended.';
    }

    $onboarding = (string)($input['onboarding'] ?? 'invite');
    if (!in_array($onboarding, ['invite', 'temp_password'], true)) {
        $errors['onboarding'] = 'Choose how this customer gets their first login.';
    }

    return [[
        'name' => $name,
        'email' => $email,
        'company_name' => $company,
        'status' => $status,
        'onboarding' => $onboarding,
        'plan_id' => (int)($input['plan_id'] ?? 0),
    ], $errors];
}

// Creates the tenant.
//
// Returns ['ok' => bool, 'errors' => [field => message], 'message' => string,
//          'user_id' => int|null, 'temp_password' => string|null,
//          'invited' => bool, 'invite_link' => string|null].
//
// temp_password is returned exactly once, to be shown exactly once, and is
// never stored in plaintext or written to the audit log.
function createTenant(mysqli $conn, array $input, $actingUserId = null) {
    [$clean, $errors] = validateTenantInput($input);

    // The unique index on users.email is the real guarantee; this check exists
    // to turn a duplicate into a field error instead of a 500, and it is
    // deliberately not the only protection — two admins submitting at once
    // would both pass it, and the insert below still has to survive that.
    if (empty($errors['email'])) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param('s', $clean['email']);
        $stmt->execute();
        $taken = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($taken) $errors['email'] = 'A customer with this email already exists.';
    }

    // Fall back to the instance default so a tenant always has a plan to read
    // quotas from — the same rule registration had.
    $planId = $clean['plan_id'] > 0 ? $clean['plan_id'] : null;
    if ($planId !== null && !getPlanById($conn, $planId)) {
        $errors['plan_id'] = 'That plan does not exist.';
        $planId = null;
    } elseif ($planId === null) {
        $defaultPlan = getPlanByCode($conn, defaultPlanCode($conn));
        $planId = $defaultPlan['id'] ?? null;
    }

    // An invitation that cannot be delivered is not an onboarding method. Said
    // as a field error rather than silently downgraded to a temporary password:
    // the admin chose a route, and quietly taking a different one leaves them
    // believing an email is on its way.
    if ($clean['onboarding'] === 'invite' && !smtpConfigured($conn)) {
        $errors['onboarding'] = 'This instance cannot send email, so an invitation cannot be delivered. '
            . 'Set a temporary password instead, or configure SMTP first.';
    }

    if ($errors) {
        // The message spells the problems out rather than saying "correct the
        // highlighted fields". A fetch() submit gets $errors and paints them
        // next to the inputs, but a plain form submit is answered with a
        // redirect and a flash — there are no highlighted fields on the page it
        // lands on, so a message that refers to them says nothing at all.
        return ['ok' => false, 'errors' => $errors, 'message' => implode(' ', $errors),
                'user_id' => null, 'temp_password' => null, 'invited' => false, 'invite_link' => null];
    }

    $invite = $clean['onboarding'] === 'invite';
    $tempPassword = $invite ? null : temporaryPassword();
    $hash = $invite ? unusablePassword() : password_hash($tempPassword, PASSWORD_DEFAULT);

    // is_active = 1 in both cases, and activation_token stays NULL.
    //
    // Activation exists to prove a stranger owns the address they typed. Nobody
    // typed this one but an admin, who is vouching for it, so there is nothing
    // to prove — and gating the account behind a second email would mean an
    // invited tenant needed two links to get in. What actually keeps the account
    // shut is that no usable password exists yet.
    $mustChange = $invite ? 0 : 1;
    $stmt = $conn->prepare(
        "INSERT INTO users (name, email, password, is_active, status, plan_id, must_change_password)
         VALUES (?, ?, ?, 1, ?, ?, ?)"
    );
    $stmt->bind_param('ssssii', $clean['name'], $clean['email'], $hash, $clean['status'], $planId, $mustChange);

    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        $stmt->close();
        // 1062 is the unique index doing its job — the race the check above
        // cannot close. Reported as the same field error, so the admin sees one
        // behaviour whichever path caught it.
        if ($e->getCode() === 1062) {
            return ['ok' => false, 'errors' => ['email' => 'A customer with this email already exists.'],
                    'message' => 'That email address is already taken.', 'user_id' => null,
                    'temp_password' => null, 'invited' => false, 'invite_link' => null];
        }
        throw $e;
    }
    $userId = $conn->insert_id;
    $stmt->close();

    if ($planId) assignPlan($conn, $userId, $planId);

    if ($clean['company_name'] !== '') {
        $stmt = $conn->prepare("INSERT INTO user_profiles (user_id, company_name) VALUES (?, ?)");
        $stmt->bind_param('is', $userId, $clean['company_name']);
        $stmt->execute();
        $stmt->close();
    }

    // Never the password, never the token — only that an account was made, by
    // whom, on what plan, and which onboarding route was taken. The audit log is
    // readable by every admin.
    logAudit($conn, 'admin.user.create', 'user', $userId, [
        'email' => $clean['email'],
        'plan_id' => $planId,
        'status' => $clean['status'],
        'onboarding' => $clean['onboarding'],
    ], $actingUserId);

    if (!$invite) {
        logAudit($conn, 'admin.user.temp_password', 'user', $userId, [], $actingUserId);
        return ['ok' => true, 'errors' => [], 'user_id' => $userId, 'temp_password' => $tempPassword,
                'invited' => false, 'invite_link' => null,
                'message' => 'Customer created. Give them the temporary password below — it is shown once.'];
    }

    [$sent, $link] = sendTenantInvite($conn, $userId, $clean['name'], $clean['email']);
    logAudit($conn, 'admin.user.invite_sent', 'user', $userId, ['delivered' => $sent], $actingUserId);

    // The tenant exists either way. A mail failure must not lose the account —
    // that would leave the address taken and nothing to show for it — but the
    // admin has to be told the invitation did not go, because otherwise they
    // wait for somebody who was never contacted.
    return ['ok' => true, 'errors' => [], 'user_id' => $userId, 'temp_password' => null,
            'invited' => true, 'invite_link' => $sent ? null : $link,
            'message' => $sent
                ? 'Customer created and an invitation was emailed to ' . $clean['email'] . '.'
                : 'Customer created, but the invitation email could not be sent. Copy the link below to them.'];
}

// Issues a single-use, expiring password-setup link and emails it.
//
// Reuses reset_token / reset_expires, and therefore reset-password.php, rather
// than inventing a parallel invitation token. That page already clears the
// token on use and refuses an expired one, so "single use" and "expires" are
// properties of code that is exercised by the forgot-password flow on every
// instance — not of a second implementation that would only ever run here.
//
// Returns [$sent, $link].
function sendTenantInvite(mysqli $conn, $userId, $name, $email) {
    $token = generateToken();

    // The expiry is computed by MySQL, not by PHP.
    //
    // reset-password.php accepts a token while `reset_expires > NOW()`, so the
    // deadline has to be written on the same clock it will be read against.
    // Both containers happen to run UTC today, which is exactly the kind of
    // agreement that holds until someone sets a timezone on one of them — and
    // the failure would be a week-long invitation that expired on arrival, or
    // one that outlived its window.
    $hours = TENANT_INVITE_HOURS;
    $stmt = $conn->prepare(
        "UPDATE users SET reset_token = ?, reset_expires = DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id = ?"
    );
    $stmt->bind_param('sii', $token, $hours, $userId);
    $stmt->execute();
    $stmt->close();

    $link = APP_URL . '/reset-password.php?token=' . $token;
    [$html, $text] = mailTenantInvite($name, $link, TENANT_INVITE_HOURS);
    $sent = sendEmail($email, 'Set up your account', $html, $text);
    if (!$sent) error_log("Tenant invitation email could not be sent to {$email}");

    return [$sent, $link];
}

// How many accounts can currently reach the admin console.
//
// Suspended and unactivated admins are excluded, because neither can log in —
// counting them would let the last usable admin be demoted on the strength of
// an account nobody can sign into.
function activeAdminCount(mysqli $conn) {
    $row = $conn->query(
        "SELECT COUNT(*) FROM users WHERE is_admin = 1 AND status = 'active' AND is_active = 1"
    )->fetch_row();
    return (int)($row[0] ?? 0);
}

// Would this action leave the instance with no one who can administer it?
//
// The self-checks in admin/tenants.php already stop an admin acting on their own
// account, which makes zero admins unreachable *today*. This states the
// invariant directly anyway, so a future action that is not self-scoped — a bulk
// edit, an importer, a CLI tool — cannot quietly break it.
function wouldOrphanInstance(mysqli $conn, $targetId) {
    $targetId = (int)$targetId;
    $stmt = $conn->prepare("SELECT is_admin, status, is_active FROM users WHERE id = ?");
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) return false;
    // Only removing a *currently usable* admin can orphan anything.
    if ((int)$row['is_admin'] !== 1 || $row['status'] !== 'active' || (int)$row['is_active'] !== 1) {
        return false;
    }
    return activeAdminCount($conn) <= 1;
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
        // Public sign-up was removed in #48, but these three stay in the map:
        // the rows they label are still in the audit log, and a history that
        // stops being readable because a feature was retired is not a history.
        'register'                     => 'Account registered (public sign-up, since removed)',
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
        'admin.user.create'             => 'Customer created by an administrator',
        'admin.user.invite_sent'        => 'Password-setup invitation sent',
        'admin.user.temp_password'      => 'Temporary password issued',
        'admin.user.suspend'            => 'Customer suspended',
        'admin.user.activate'           => 'Customer reactivated',
        'admin.user.change_plan'        => "Customer’s plan changed",
        'admin.user.toggle_admin'       => 'Admin rights granted / revoked',
        'admin.serpapi.update'          => 'Lead search settings updated',
        'admin.serpapi.test'            => 'SerpApi key tested (1 credit)',
        'admin.leads.search'            => 'Lead search run (1 credit)',
        'admin.leads.export'            => 'Leads exported to CSV',
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
