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
$adminEmail = env('ADMIN_EMAIL');
$adminPass  = env('ADMIN_PASSWORD');
$adminName  = env('ADMIN_NAME', 'Administrator');

if (!$adminEmail || !$adminPass) {
    fwrite(STDOUT, "[bootstrap] ADMIN_EMAIL/ADMIN_PASSWORD not set — skipping admin creation\n");
    exit(0);
}

$stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
$stmt->bind_param('s', $adminEmail);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    // Do NOT reset the password on every boot — that would let anyone who can
    // read the compose env silently take over an account whose password the
    // owner has since changed. Only ensure the account can still administer.
    $stmt = $conn->prepare("UPDATE users SET is_admin = 1, is_active = 1, status = 'active' WHERE id = ?");
    $stmt->bind_param('i', $existing['id']);
    $stmt->execute();
    $stmt->close();
    fwrite(STDOUT, "[bootstrap] admin {$adminEmail} already exists — ensured active\n");
    exit(0);
}

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

fwrite(STDOUT, "[bootstrap] created admin {$adminEmail} (tenant t{$newId})\n");
