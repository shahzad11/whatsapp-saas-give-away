<?php
require_once __DIR__ . '/env.php';

define('DB_HOST', env('MYSQL_HOST', 'mysql'));
define('DB_PORT', (int)env('MYSQL_PORT', 3306));
define('DB_USER', envRequired('MYSQL_USER'));
define('DB_PASS', envRequired('MYSQL_PASSWORD'));
define('DB_NAME', env('MYSQL_DATABASE', 'whatsapp_saas'));

// Throw instead of emitting warnings, so a connection or query failure cannot
// half-execute and leak SQL fragments into the page.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// The database container may still be starting on a cold `compose up`, even
// with a healthcheck gate. Retry briefly rather than serving a fatal error.
$conn = null;
$lastError = '';
for ($attempt = 1; $attempt <= 10; $attempt++) {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        break;
    } catch (mysqli_sql_exception $e) {
        $lastError = $e->getMessage();
        $conn = null;
        sleep(2);
    }
}

if (!$conn) {
    error_log('FATAL: database connection failed: ' . $lastError);
    http_response_code(503);
    // Never echo the driver message — it names the host, user and schema.
    exit('Service temporarily unavailable.');
}

$conn->set_charset('utf8mb4');
