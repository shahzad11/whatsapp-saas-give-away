<?php
require_once dirname(__DIR__, 2) . '/config/init.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$sessionId = $_GET['session_id'] ?? '';
$messageId = $_GET['message_id'] ?? '';

if (empty($sessionId) || empty($messageId)) {
    http_response_code(400);
    echo 'Missing session_id or message_id';
    exit;
}

[$accountId, $tenantId, $userId] = requireOwnedAccount($conn, $sessionId, false);

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
// Which upstream headers are safe and useful to pass through. Content-Range and
// Accept-Ranges are what let a browser seek inside a long video.
$forward = ['content-type', 'content-disposition', 'content-length', 'content-range', 'accept-ranges', 'last-modified', 'etag'];

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
    CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$headersSent, &$upstreamStatus, $forward) {
        $len = strlen($header);
        $trimmed = trim($header);

        if (stripos($trimmed, 'HTTP/') === 0) {
            $parts = explode(' ', $trimmed);
            $upstreamStatus = (int)($parts[1] ?? 0);
            return $len;
        }
        if ($trimmed === '') {
            // End of the header block: the status is known, so commit it.
            if ($upstreamStatus > 0) {
                http_response_code($upstreamStatus);
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
