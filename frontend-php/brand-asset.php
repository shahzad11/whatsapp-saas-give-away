<?php
// Serves the admin-uploaded logo or favicon (#23).
//
// Public on purpose: the login, register and password-reset pages carry the
// branding and nobody is authenticated there. What it will serve is narrow by
// construction — two fixed keys, and a MIME type that was sniffed from the
// file's own bytes at upload time and matched against an allow-list, so this
// endpoint never echoes a Content-Type that came from a client.
require_once __DIR__ . '/config/init.php';

$kind = (string)($_GET['kind'] ?? 'logo');
if (!in_array($kind, brandAssetKinds(), true)) {
    http_response_code(404);
    exit;
}

$asset = brandAssetContent($conn, $kind);
if (!$asset) {
    http_response_code(404);
    exit;
}

// Immutable, because the URL carries the row's mtime as `v=`. Without the
// version parameter this would be wrong; with it, a replaced logo is a different
// URL and appears at once.
header('Content-Type: ' . $asset['mime_type']);
header('Content-Length: ' . strlen($asset['content']));
header('Cache-Control: public, max-age=31536000, immutable');
// An uploaded image is served from our own origin, so it must never be sniffed
// into something executable, and it must never be framed.
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline');
echo $asset['content'];
