<?php
require_once dirname(__DIR__, 2) . '/config/init.php';

header('Content-Type: application/json');

function mediaFail($error, $extra = []) {
    echo json_encode(['ok' => false, 'error' => $error] + $extra);
    exit;
}

if (!isLoggedIn()) {
    mediaFail('Unauthorized');
}

// A body larger than post_max_size is discarded by PHP before this script runs:
// $_POST and $_FILES come back empty and the only clue is Content-Length. Without
// this branch an oversized upload would report "No file received", which sends
// the user looking for the wrong problem.
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
    mediaFail('Attachment is too large for the server to accept (limit '
        . (int)(MAX_ATTACHMENT_BYTES / 1048576) . ' MB).');
}

// Unlike the JSON endpoints, this one is a multipart POST — which a cross-site
// form can produce without any preflight. The token is what makes that fail.
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    mediaFail('Invalid request. Reload the page and try again.');
}

$sessionId = $_POST['session_id'] ?? '';
$chatId = $_POST['chat_id'] ?? '';
$kind = $_POST['kind'] ?? '';
$caption = trim($_POST['caption'] ?? '');

if ($sessionId === '' || $chatId === '') {
    mediaFail('Missing required fields');
}

// Mirrors UPLOAD_KINDS in the backend. 'voice' is an audio message with the
// push-to-talk flag, which is the only thing that makes it render as a voice
// note rather than an attached sound file.
$allowedKinds = ['image', 'video', 'audio', 'voice', 'document'];
if (!in_array($kind, $allowedKinds, true)) {
    mediaFail('Unsupported attachment type');
}

if (!isset($_FILES['file'])) {
    mediaFail('No file received');
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'Attachment is larger than the server allows.',
        UPLOAD_ERR_FORM_SIZE => 'Attachment is larger than the form allows.',
        UPLOAD_ERR_PARTIAL => 'Upload was interrupted. Try again.',
        UPLOAD_ERR_NO_FILE => 'No file received',
        UPLOAD_ERR_NO_TMP_DIR => 'Server cannot store the upload.',
        UPLOAD_ERR_CANT_WRITE => 'Server cannot store the upload.',
        UPLOAD_ERR_EXTENSION => 'Upload rejected by the server.',
    ];
    mediaFail($messages[$file['error']] ?? 'Upload failed');
}

// Guards against a forged multipart part that names a file the process happens
// to be able to read (/etc/passwd, the .env) instead of an uploaded one.
if (!is_uploaded_file($file['tmp_name'])) {
    mediaFail('Upload failed');
}

$size = (int)($file['size'] ?? 0);
if ($size <= 0) {
    mediaFail('Attachment is empty');
}
if ($size > MAX_ATTACHMENT_BYTES) {
    mediaFail('Attachment exceeds the ' . (int)(MAX_ATTACHMENT_BYTES / 1048576) . ' MB limit.');
}

// The browser's Content-Type is a claim by the client. Sniff the real one: it
// decides which WhatsApp message type the recipient's phone renders.
$mime = null;
if (function_exists('finfo_open') && ($finfo = finfo_open(FILEINFO_MIME_TYPE))) {
    $mime = finfo_file($finfo, $file['tmp_name']) ?: null;
    finfo_close($finfo);
}
$mime = $mime ?: 'application/octet-stream';

// A picker can be pointed at anything, so the kind and the content have to
// agree — otherwise an "image" send produces an imageMessage the recipient
// cannot open. Documents are deliberately unconstrained; that is the point.
$prefixes = ['image' => 'image/', 'video' => 'video/', 'audio' => 'audio/', 'voice' => 'audio/'];
if (isset($prefixes[$kind]) && !str_starts_with($mime, $prefixes[$kind])) {
    // Browser recordings are a known exception: Chrome labels a MediaRecorder
    // blob video/webm even when it holds only an audio track, and finfo agrees
    // with the container, not the contents.
    $webmVoice = ($kind === 'voice' || $kind === 'audio') && $mime === 'video/webm';
    if (!$webmVoice) {
        $labels = ['image' => 'an image', 'video' => 'a video', 'audio' => 'an audio file', 'voice' => 'an audio recording'];
        mediaFail('That file is not ' . $labels[$kind] . '.');
    }
    $mime = 'audio/webm';
}

[$accountId, $tenantId, $userId] = requireOwnedAccount($conn, $sessionId);

// Metered exactly like a text send: an attachment is a message. Checked before
// the upload leaves for the backend so a send that cannot be counted never
// happens.
[$quotaOk, $used, $limit] = checkMessageQuota($conn, $userId);
if (!$quotaOk) {
    mediaFail('Monthly message limit reached (' . number_format($limit) . '). Upgrade your plan to send more.',
        ['quotaExceeded' => true]);
}

$data = file_get_contents($file['tmp_name']);
if ($data === false || $data === '') {
    mediaFail('Attachment could not be read');
}

// basename() strips any directory component a client put in the filename; the
// value is echoed back to recipients, never used as a path here.
$filename = basename((string)($file['name'] ?? ''));

// Uploading 16 MB to WhatsApp's media servers can outlast the default 60s
// ceiling; on Unix the curl wait itself is not counted against it, but the
// base64 encode above is.
set_time_limit(180);

$resp = callBackendApi('POST', '/api/v1/wa/sessions/' . urlencode($sessionId)
    . '/chats/' . urlencode($chatId) . '/media', [
    'kind' => $kind,
    'data' => base64_encode($data),
    'mimetype' => $mime,
    'fileName' => $filename !== '' ? $filename : null,
    'caption' => $caption,
], null, 150);

// Only a send that actually left the building costs the tenant a message.
if ($resp && !empty($resp['ok'])) {
    incrementUsage($conn, $userId, 'messages_sent');
}

echo json_encode($resp ?: ['ok' => false, 'error' => 'Backend error']);
