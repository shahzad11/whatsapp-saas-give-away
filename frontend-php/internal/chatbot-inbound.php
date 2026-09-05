<?php
// Called by the Node backend when a live message arrives. Not a user-facing
// endpoint: there is no session, and the caller proves itself with the same
// shared secret the frontend uses in the other direction.
//
// Everything this endpoint decides — plan, config, group/archived rules, quota,
// which model, what it costs — lives in includes/chatbot.php, so the rules exist
// in exactly one language and cannot drift between PHP and Node.
require_once dirname(__DIR__) . '/config/init.php';

header('Content-Type: application/json');

// Constant-time compare: this is an authentication check, and the header is
// attacker-controlled.
$provided = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
if ($provided === '' || !hash_equals(BACKEND_API_KEY, $provided)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

foreach (['sessionId', 'chatId', 'messageId'] as $required) {
    if (($input[$required] ?? '') === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing ' . $required]);
        exit;
    }
}

// The reply involves a model call and a send, either of which can take seconds.
// Node does not wait for us (it fires and forgets), but PHP's own ceiling still
// applies to this process.
set_time_limit(180);
ignore_user_abort(true);

$outcome = chatbotHandleInbound($conn, [
    'sessionId'  => (string)$input['sessionId'],
    'chatId'     => (string)$input['chatId'],
    'messageId'  => (string)$input['messageId'],
    'text'       => (string)($input['text'] ?? ''),
    'mediaType'  => (string)($input['mediaType'] ?? 'text'),
    'fromMe'     => !empty($input['fromMe']),
    'isGroup'    => !empty($input['isGroup']),
    'archived'   => !empty($input['archived']),
    'isSelfChat' => !empty($input['isSelfChat']),
]);

echo json_encode(['ok' => true, 'outcome' => $outcome]);
