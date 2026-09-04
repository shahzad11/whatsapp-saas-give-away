<?php
require_once __DIR__ . '/env.php';

define('APP_NAME', env('APP_NAME', 'WhatsApp Linked'));
define('APP_HOST', env('APP_HOST', 'localhost'));
define('APP_URL', rtrim(env('APP_URL', 'https://' . APP_HOST), '/'));
define('APP_VERSION', '2.0.0');

// Node backend. Reached over the internal Docker network only — its port is
// never published to the host. Still authenticated: see BACKEND_API_KEY.
define('BACKEND_URL', rtrim(env('BACKEND_URL', 'http://backend:3001'), '/'));

// Shared secret proving to the backend that a request came from this frontend.
// Must match BACKEND_API_KEY in the backend container.
define('BACKEND_API_KEY', envRequired('BACKEND_API_KEY'));

define('MAIL_FROM', env('MAIL_FROM', 'noreply@' . APP_HOST));
define('MAIL_FROM_NAME', env('MAIL_FROM_NAME', APP_NAME));

define('DEV_MODE', envBool('DEV_MODE', false));

// Open signup is a liability on a SaaS with no payment wall in front of it.
// Off by default; flip deliberately per environment.
define('ALLOW_REGISTRATION', envBool('ALLOW_REGISTRATION', false));

// Session cookies are only marked Secure when actually served over HTTPS,
// otherwise local HTTP development silently loses the session.
define('SESSION_SECURE', envBool('SESSION_SECURE', true));

define('DEFAULT_PLAN_CODE', env('DEFAULT_PLAN_CODE', 'free'));
define('LOGIN_MAX_ATTEMPTS', (int)env('LOGIN_MAX_ATTEMPTS', 8));
define('LOGIN_LOCKOUT_MINUTES', (int)env('LOGIN_LOCKOUT_MINUTES', 15));
