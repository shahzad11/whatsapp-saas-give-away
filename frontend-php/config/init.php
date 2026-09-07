<?php
$basePath = dirname(__DIR__);

require_once $basePath . '/config/app.php';

// Harden the session cookie before it is ever issued. The site is served over
// HTTPS only, so the cookie must not be transmittable over plain HTTP, must not
// be readable from JavaScript, and must not be sent on cross-site requests.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => SESSION_SECURE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once $basePath . '/config/database.php';
require_once $basePath . '/includes/functions.php';
// After functions.php, whose flash()/redirect()/csrfTokenValid() it wraps.
require_once $basePath . '/includes/ajax.php';
// Also after functions.php: it drains the same flash() queue, from the layouts
// rather than from each page (#33).
require_once $basePath . '/includes/flash.php';
require_once $basePath . '/includes/auth.php';
require_once $basePath . '/includes/tenant.php';
// Loaded before plan.php: price formatting reads the instance-wide currency.
require_once $basePath . '/includes/settings.php';
// After settings.php, which owns overrideSetting(): branding is an admin
// override over the APP_NAME env default, read by every layout and every email.
require_once $basePath . '/includes/branding.php';
// crypto before mailer: the mailer decrypts the stored SMTP password.
require_once $basePath . '/includes/crypto.php';
require_once $basePath . '/includes/mailer.php';
require_once $basePath . '/includes/mail-templates.php';
require_once $basePath . '/includes/plan.php';
require_once $basePath . '/includes/billing.php';
require_once $basePath . '/includes/profile.php';
// llm before chatbot: the chatbot resolves models and decrypts provider keys
// through the LLM layer, and both need plan.php's feature flags above.
require_once $basePath . '/includes/llm.php';
// appointments before chatbot: the chatbot's booking prompt and its action
// handler are built from the appointment layer.
require_once $basePath . '/includes/appointments.php';
require_once $basePath . '/includes/handoff.php';
require_once $basePath . '/includes/chatbot.php';
