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

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HEADER => true,
    // Same auth as callBackendApi(); this handle is hand-rolled because it
    // streams binary rather than JSON.
    CURLOPT_HTTPHEADER => backendHeaders($tenantId),
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

if ($httpCode !== 200 || $response === false) {
    http_response_code($httpCode ?: 502);
    echo 'Media not available';
    exit;
}

$headers = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);

// Forward content-type and content-disposition headers
if (preg_match('/Content-Type:\s*(.+)/i', $headers, $m)) {
    header('Content-Type: ' . trim($m[1]));
}
if (preg_match('/Content-Disposition:\s*(.+)/i', $headers, $m)) {
    header('Content-Disposition: ' . trim($m[1]));
}

header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . strlen($body));
echo $body;
