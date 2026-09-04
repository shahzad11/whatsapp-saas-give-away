<?php
// Configuration comes from the process environment, never from a file inside
// the DocumentRoot. Under Docker these are supplied by compose; the old
// hardcoded credentials in config/database.php are gone.
//
// An optional .env file is read for local development only. It is looked for
// one level ABOVE the application root so it can never be served over HTTP,
// even if every other protection fails.

function loadDotEnvOnce() {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    $path = dirname(__DIR__, 2) . '/.env';
    if (!is_readable($path)) return;

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Strip one layer of matching quotes.
        $len = strlen($value);
        if ($len >= 2 && (($value[0] === '"' && $value[$len - 1] === '"') || ($value[0] === "'" && $value[$len - 1] === "'"))) {
            $value = substr($value, 1, -1);
        }

        // Real environment variables always win over the file.
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

function env($key, $default = null) {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

function envBool($key, $default = false) {
    $value = env($key);
    if ($value === null) return $default;
    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

// Secrets have no safe default. Guessing one produces an app that boots and is
// silently insecure, which is worse than one that refuses to start.
function envRequired($key) {
    $value = env($key);
    if ($value === null) {
        http_response_code(500);
        error_log("FATAL: required environment variable $key is not set");
        exit('Server misconfigured.');
    }
    return $value;
}

loadDotEnvOnce();
