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

// ALLOW_REGISTRATION was here. It is gone, not defaulted to false (#48).
//
// This instance is invite-only: tenants are created by an admin on
// admin/tenants.php and there is no public sign-up form, route or handler left
// to enable. A constant that still existed would be a switch with nothing
// behind it, and the next person to find it would reasonably assume flipping it
// reopened registration.

// Session cookies are only marked Secure when actually served over HTTPS,
// otherwise local HTTP development silently loses the session.
define('SESSION_SECURE', envBool('SESSION_SECURE', true));

// Composer attachment ceiling, matching WhatsApp Web's own limit for photos and
// video. Not env-tunable: the backend enforces the same 16 MB independently and
// php.ini's post_max_size (32M) has to stay above it plus base64 overhead, so
// three values would have to move together. Change all three or none.
define('MAX_ATTACHMENT_BYTES', 16 * 1024 * 1024);

define('DEFAULT_PLAN_CODE', env('DEFAULT_PLAN_CODE', 'free'));
define('LOGIN_MAX_ATTEMPTS', (int)env('LOGIN_MAX_ATTEMPTS', 8));
define('LOGIN_LOCKOUT_MINUTES', (int)env('LOGIN_LOCKOUT_MINUTES', 15));
