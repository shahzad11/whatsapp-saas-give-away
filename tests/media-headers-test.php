<?php
// mediaResponseHeaders() decides the disposition of customer media (#7),
// without a database.
//
// Run with:  php tests/media-headers-test.php
//
// The rule: only a fixed allow-list of render-safe types is served inline with
// its real Content-Type; everything else — HTML, SVG, JSON, anything — is a
// forced download as application/octet-stream, plus nosniff and a sandbox CSP.

$app = dirname(__DIR__) . '/frontend-php';
require_once $app . '/includes/functions.php';

$passed = 0;
$failed = 0;

function check($name, $condition, $detail = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   {$name}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

// Finds one emitted header line by name.
function headerLine(array $headers, $name) {
    foreach ($headers as $h) {
        if (stripos($h, $name . ':') === 0) return trim(substr($h, strlen($name) + 1));
    }
    return null;
}

// Refusals — everything that could carry script -----------------------------
foreach (['text/html', 'image/svg+xml', 'application/json', 'application/pdf', 'text/plain'] as $mime) {
    $h = mediaResponseHeaders($mime, 'doc.bin');
    check("$mime is forced to a download",
        headerLine($h, 'Content-Type') === 'application/octet-stream'
        && str_starts_with((string)headerLine($h, 'Content-Disposition'), 'attachment;'));
}

// Allow-list — render-safe types stay inline --------------------------------
check('image/png stays inline with its real type',
    headerLine(mediaResponseHeaders('image/png', 'a.png'), 'Content-Type') === 'image/png'
    && str_starts_with(headerLine(mediaResponseHeaders('image/png', 'a.png'), 'Content-Disposition'), 'inline;'));
check('a mime with parameters is judged on the base type',
    headerLine(mediaResponseHeaders('image/png; x=y', 'a.png'), 'Content-Type') === 'image/png');
check('audio/ogg stays inline',
    headerLine(mediaResponseHeaders('audio/ogg', 'v.ogg'), 'Content-Type') === 'audio/ogg');
check('missing mime is octet-stream, not empty',
    headerLine(mediaResponseHeaders(null, null), 'Content-Type') === 'application/octet-stream');

// Safety rails on every response ---------------------------------------------
$h = mediaResponseHeaders('image/png', 'a.png');
check('nosniff is always set', headerLine($h, 'X-Content-Type-Options') === 'nosniff');
check('the sandbox CSP is always set',
    headerLine($h, 'Content-Security-Policy') === "sandbox; default-src 'none'");

// The filename is sender-controlled too ---------------------------------------
$h = mediaResponseHeaders('image/png', '../../a"b.html');
$disp = headerLine($h, 'Content-Disposition');
check('a hostile filename cannot break out of the quotes',
    $disp !== null && substr_count($disp, '"') === 2);
check('a hostile filename loses its path and its quote',
    !str_contains($disp, '..') && !str_contains($disp, '/') && !str_contains($disp, '"a"b'));
check('an empty filename defaults to media',
    str_contains(headerLine(mediaResponseHeaders('application/zip', ''), 'Content-Disposition'), 'filename="media"'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
