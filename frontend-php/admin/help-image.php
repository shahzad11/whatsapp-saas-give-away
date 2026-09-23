<?php
require_once dirname(__DIR__) . '/includes/admin-init.php';

$allowed = [
    'admin-overview.png',
    'admin-customers.png',
    'admin-customer-detail.png',
    'admin-plans.png',
    'admin-payments.png',
    'admin-leads.png',
    'admin-settings.png',
    'admin-branding.png',
    'admin-email.png',
    'admin-llm.png',
    'admin-system-health.png',
    'admin-system-audit.png',
];

$file = $_GET['f'] ?? '';
if (!in_array($file, $allowed, true)) {
    http_response_code(404);
    exit;
}

$path = dirname(__DIR__) . '/includes/admin-help-images/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/png');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Disposition: inline; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
