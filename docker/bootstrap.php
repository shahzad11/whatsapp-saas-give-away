<?php
/**
 * Runs once per container start, before Apache.
 *
 * Applies the schema and ensures a bootstrap admin exists. Doing this in PHP
 * rather than via the MySQL image's /docker-entrypoint-initdb.d means it also
 * works against an external/managed database, and it re-runs safely on every
 * boot (every statement in schema.sql is IF NOT EXISTS / ON DUPLICATE KEY).
 */

$appRoot = getenv('APP_ROOT') ?: '/var/www/app';
require_once $appRoot . '/config/env.php';

$host = env('MYSQL_HOST', 'mysql');
$port = (int)env('MYSQL_PORT', 3306);
$user = env('MYSQL_USER');
$pass = env('MYSQL_PASSWORD');
$name = env('MYSQL_DATABASE', 'whatsapp_saas');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// MySQL accepts TCP connections a little before it is ready to serve. Retry
// rather than crash-looping the container.
$conn = null;
for ($i = 1; $i <= 30; $i++) {
    try {
        $conn = new mysqli($host, $user, $pass, $name, $port);
        break;
    } catch (mysqli_sql_exception $e) {
        fwrite(STDERR, "[bootstrap] waiting for database ($i/30): {$e->getMessage()}\n");
        sleep(2);
    }
}

if (!$conn) {
    fwrite(STDERR, "[bootstrap] FATAL: could not connect to database\n");
    exit(1);
}

$conn->set_charset('utf8mb4');

// --- Schema ---------------------------------------------------------------
$schemaPath = $appRoot . '/sql/schema.sql';
if (!is_readable($schemaPath)) {
    fwrite(STDERR, "[bootstrap] FATAL: schema not found at $schemaPath\n");
    exit(1);
}

$sql = file_get_contents($schemaPath);
if ($conn->multi_query($sql)) {
    // multi_query only reports errors as results are consumed.
    do {
        if ($res = $conn->store_result()) $res->free();
    } while ($conn->more_results() && $conn->next_result());
}
if ($conn->errno) {
    fwrite(STDERR, "[bootstrap] FATAL: schema failed: {$conn->error}\n");
    exit(1);
}
fwrite(STDOUT, "[bootstrap] schema applied\n");

// --- Bootstrap admin ------------------------------------------------------
//
// Runs once per install, not once per boot (#4). The old code promoted any
// account whose email matched ADMIN_EMAIL on every container start — so a
// tenant who could set their email to that address (email changes were
// unverified then) became an admin on the next restart, and an admin who
// changed their own address got a ghost replacement with the .env password.
//
// The rule is now: an instance with no admin at all gets exactly one, created
// from the env credentials. An instance that already has an admin is left
// alone — ADMIN_EMAIL is a seed, not a back door, and it must never promote
// an account that happens to carry the address. `bootstrap_admin_done` in
// app_settings records the outcome so even the "no admin" check stops running
// once the instance has been stood up.
$adminEmail = env('ADMIN_EMAIL');
$adminPass  = env('ADMIN_PASSWORD');
$adminName  = env('ADMIN_NAME', 'Administrator');

// Plain SQL rather than setAppSetting(): includes/settings.php is only loaded
// further down, and this step must not depend on it.
$doneRow = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'bootstrap_admin_done'")
    ->fetch_assoc();
$done = $doneRow['setting_value'] ?? null;

if ($done !== null) {
    fwrite(STDOUT, "[bootstrap] admin bootstrap already done (user {$done}) — skipping\n");
} else {
    $adminCount = (int)($conn->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetch_row()[0] ?? 0);

    if ($adminCount >= 1) {
        // Existing deployment from before this marker existed: record who the
        // first admin is so the check never runs again.
        $firstAdmin = (int)($conn->query("SELECT MIN(id) FROM users WHERE is_admin = 1")->fetch_row()[0] ?? 0);
        $stmt = $conn->prepare(
            "INSERT INTO app_settings (setting_key, setting_value) VALUES ('bootstrap_admin_done', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $firstAdminStr = (string)$firstAdmin;
        $stmt->bind_param('s', $firstAdminStr);
        $stmt->execute();
        $stmt->close();
        fwrite(STDOUT, "[bootstrap] {$adminCount} admin(s) already exist — marked done, no promotion\n");
    } elseif (!$adminEmail || !$adminPass) {
        fwrite(STDOUT, "[bootstrap] no admins and ADMIN_EMAIL/ADMIN_PASSWORD not set — skipping admin creation\n");
    } else {
        // A user holding ADMIN_EMAIL but no admin rights must NOT be promoted:
        // the address is an input the tenant could have chosen, so treating it
        // as proof of ownership is exactly the hole being closed. Logged loudly
        // because it means the intended admin address is taken by someone else.
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param('s', $adminEmail);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            fwrite(STDERR, "[bootstrap] WARNING: no admin exists, but a user already has ADMIN_EMAIL "
                . "({$adminEmail}) — NOT promoting it. Grant admin rights from an existing admin "
                . "account or fix the row by hand.\n");
        } else {
            $planRow = $conn->query("SELECT id FROM plans ORDER BY sort_order DESC LIMIT 1")->fetch_assoc();
            $planId = $planRow['id'] ?? null;
            $hash = password_hash($adminPass, PASSWORD_DEFAULT);

            $stmt = $conn->prepare(
                "INSERT INTO users (name, email, password, is_active, is_admin, status, plan_id)
                 VALUES (?, ?, ?, 1, 1, 'active', ?)"
            );
            $stmt->bind_param('sssi', $adminName, $adminEmail, $hash, $planId);
            $stmt->execute();
            $newId = $conn->insert_id;
            $stmt->close();

            $stmt = $conn->prepare(
                "INSERT INTO subscriptions (user_id, plan_id, status, current_period_start, current_period_end)
                 VALUES (?, ?, 'active', UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 YEAR))"
            );
            $stmt->bind_param('ii', $newId, $planId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "INSERT INTO app_settings (setting_key, setting_value) VALUES ('bootstrap_admin_done', ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $newIdStr = (string)$newId;
            $stmt->bind_param('s', $newIdStr);
            $stmt->execute();
            $stmt->close();

            fwrite(STDOUT, "[bootstrap] created admin {$adminEmail} (tenant t{$newId})\n");
        }
    }
}

// --- FenLLM trial account ---------------------------------------------------
//
// Opens a free trial account on the owner's LLM router so a fresh install can
// answer chat messages with zero configuration. The returned API key is shown
// exactly once by the signup endpoint, so it is stored (encrypted) immediately.
//
// Deliberately non-fatal: a network blip or a 503 must not keep Apache down.
// fenllm_provision_status records the outcome — 'done' and 'account_exists'
// are terminal (a 409 must never be re-POSTed), anything else retries on the
// next boot. The secret and the API key are never printed.
$fenllmSecret = env('FENLLM_PARTNER_SECRET');
if (!$adminEmail) {
    fwrite(STDOUT, "[bootstrap] ADMIN_EMAIL not set — skipping FenLLM provisioning\n");
} else {
    // Pulled in whenever the admin email is set — the catalogue sync below
    // needs them even on instances with no partner secret. config/app.php
    // requires BACKEND_API_KEY (which the container always has), and
    // config/init.php is avoided on purpose — it starts a session, which is
    // meaningless on the CLI.
    require_once $appRoot . '/config/app.php';
    require_once $appRoot . '/includes/functions.php';
    require_once $appRoot . '/includes/settings.php';
    require_once $appRoot . '/includes/crypto.php';
    require_once $appRoot . '/includes/plan.php';
    require_once $appRoot . '/includes/llm.php';

    if (!$fenllmSecret) {
        fwrite(STDOUT, "[bootstrap] FENLLM_PARTNER_SECRET not set — skipping FenLLM provisioning\n");
    } else {
        $status = appSetting($conn, 'fenllm_provision_status', null);
        if ($status === 'done' || $status === 'account_exists') {
            fwrite(STDOUT, "[bootstrap] FenLLM already provisioned ({$status}) — skipping\n");
        } else {
            try {
                [$ok, $message] = llmProvisionFenLlm($conn, $adminEmail, $adminName, $fenllmSecret);
                fwrite($ok ? STDOUT : STDERR, "[bootstrap] FenLLM: {$message}\n");
            } catch (Throwable $e) {
                fwrite(STDERR, "[bootstrap] FenLLM provisioning failed: {$e->getMessage()}\n");
            }
        }
    }

    // Adds any catalogue models an existing install is missing (pro, max) and
    // sets FenLLM Pro as the transcription default — once, so an admin's later
    // choice is never overwritten. A no-op once everything is in place.
    try {
        [$added, $defaultSet] = llmEnsureFenLlmCatalogue($conn);
        if ($added === 0 && !$defaultSet) {
            fwrite(STDOUT, "[bootstrap] FenLLM catalogue up to date\n");
        } else {
            if ($added > 0) fwrite(STDOUT, "[bootstrap] FenLLM catalogue: added {$added} model(s)\n");
            if ($defaultSet) fwrite(STDOUT, "[bootstrap] transcription default set to FenLLM Pro\n");
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "[bootstrap] FenLLM catalogue sync failed: {$e->getMessage()}\n");
    }
}
