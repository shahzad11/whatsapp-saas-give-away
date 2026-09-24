<?php
require_once dirname(__DIR__, 2) . '/config/init.php';

// The active-user guard (#3), plain-text flavour: this endpoint streams
// customer media, which a suspended tenant must not keep reading.
requireActiveUser(false);

$sessionId = $_GET['session_id'] ?? '';
$messageId = $_GET['message_id'] ?? '';

if (empty($sessionId) || empty($messageId)) {
    http_response_code(400);
    echo 'Missing session_id or message_id';
    exit;
}

[$accountId, $tenantId, $userId] = requireOwnedAccount($conn, $sessionId, false);

// Cloud media lives behind Meta's CDN, not the backend: the stored
// media_meta.cloud.mediaId resolves to the bytes, which are small enough that
// a buffered answer is fine (voice notes and images, not 166 MB videos).
if (waIsCloud($conn, $sessionId)) {
    $r = waFetchMediaBytes($conn, $sessionId, $messageId);
    if (empty($r['ok'])) {
        http_response_code(404);
        echo 'Media not available';
        exit;
    }
    // Disposition decided here, from an allow-list (#7): Meta's mime_type is
    // the sender's claim, and a "document" that is really HTML must download,
    // not render with our session.
    foreach (mediaResponseHeaders($r['mime'] ?? null, $r['filename'] ?? null) as $h) {
        header($h);
    }
    header('Content-Length: ' . strlen($r['bytes']));
    header('Cache-Control: private, max-age=3600');
    echo $r['bytes'];
    exit;
}

// The backend location is server infrastructure, not a user-tunable value.
// Using the BACKEND_URL constant keeps this consistent with callBackendApi()
// and prevents a user-supplied URL from turning this proxy into an SSRF hole.
$url = BACKEND_URL . '/api/v1/wa/sessions/' . urlencode($sessionId) . '/messages/' . urlencode($messageId) . '/media';

// This proxy **streams**. It used to buffer the whole response with
// CURLOPT_RETURNTRANSFER and then substr() it into two more copies — so a
// 166 MB video in the chat history exhausted the 256 MB memory limit and every
// request for it was a fatal error. Nothing is accumulated here now: headers are
// forwarded as they arrive and each body chunk is echoed straight out.
$headersSent = false;
$upstreamStatus = 0;
// Content-Type and Content-Disposition are deliberately NOT forwarded (#7):
// upstream hands back the sender's declared type, and trusting it was the
// stored-XSS hole. They are captured instead, and our own
// mediaResponseHeaders() answer is emitted when the header block ends.
$upstreamMime = null;
$upstreamFilename = null;
// Which upstream headers are safe and useful to pass through. Content-Range and
// Accept-Ranges are what let a browser seek inside a long video.
$forward = ['content-length', 'content-range', 'accept-ranges', 'last-modified', 'etag'];

$ch = curl_init($url);

$requestHeaders = backendHeaders($tenantId);
// A <video> element asks for byte ranges. Forwarding the header (and the 206 it
// produces) is the difference between seeking and re-downloading.
if (!empty($_SERVER['HTTP_RANGE'])) {
    $requestHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_TIMEOUT => 300,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTPHEADER => $requestHeaders,
    CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$headersSent, &$upstreamStatus, &$upstreamMime, &$upstreamFilename, $forward) {
        $len = strlen($header);
        $trimmed = trim($header);

        if (stripos($trimmed, 'HTTP/') === 0) {
            $parts = explode(' ', $trimmed);
            $upstreamStatus = (int)($parts[1] ?? 0);
            return $len;
        }
        if ($trimmed === '') {
            // End of the header block: the status is known, so commit it — and
            // now that upstream's declared type has been seen, answer with our
            // own allow-listed disposition headers instead of its (#7).
            if ($upstreamStatus > 0) {
                http_response_code($upstreamStatus);
                if ($upstreamStatus < 400) {
                    foreach (mediaResponseHeaders($upstreamMime, $upstreamFilename) as $h) {
                        header($h);
                    }
                }
            }
            $headersSent = true;
            return $len;
        }

        $colon = strpos($trimmed, ':');
        if ($colon === false) {
            return $len;
        }
        // An error response is JSON that this proxy replaces with plain text —
        // forwarding its Content-Type would mislabel the reply.
        if ($upstreamStatus >= 400) {
            return $len;
        }
        $name = strtolower(substr($trimmed, 0, $colon));
        $value = trim(substr($trimmed, $colon + 1));
        if ($name === 'content-type') {
            $upstreamMime = $value;
            return $len;
        }
        if ($name === 'content-disposition') {
            // The filename the backend sanitised survives as a hint only; our
            // own helper re-sanitises it before it reaches a header.
            if (preg_match('/filename="([^"]*)"/i', $value, $m)) {
                $upstreamFilename = $m[1];
            } elseif (preg_match('/filename=([^;\s]+)/i', $value, $m)) {
                $upstreamFilename = $m[1];
            }
            return $len;
        }
        if (in_array($name, $forward, true)) {
            header($trimmed);
        }
        return $len;
    },
    CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$upstreamStatus) {
        // An error body is JSON, not media. Swallow it and let the status speak.
        if ($upstreamStatus >= 400) {
            return strlen($chunk);
        }
        echo $chunk;
        // Long files: do not let the chunks pile up in PHP's output buffer.
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
        return strlen($chunk);
    },
]);

// Private, not public: this URL is only meaningful for the authenticated tenant.
header('Cache-Control: private, max-age=3600');

$okCurl = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($okCurl === false || $upstreamStatus === 0) {
    // Only safe to say so if nothing has been written yet.
    if (!headers_sent()) {
        http_response_code(502);
        echo 'Media not available';
    }
    if ($error) {
        error_log('Media proxy failed: ' . $error);
    }
    exit;
}

if ($upstreamStatus >= 400 && !headers_sent()) {
    http_response_code($upstreamStatus);
    echo 'Media not available';
}
