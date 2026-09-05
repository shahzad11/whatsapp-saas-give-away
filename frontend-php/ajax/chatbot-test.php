<?php
// Tenant test console. Runs the real reply pipeline against the tenant's saved
// configuration and returns the text — but sends nothing to WhatsApp, so a test
// can never reach a customer.
//
// It deliberately shares chatbotGenerateReply() with the live path: a preview
// built from a second, simpler code path would eventually disagree with what
// customers actually get, which is worse than having no preview.
require_once dirname(__DIR__) . '/config/init.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($input['csrf_token'] ?? ''))) {
    echo json_encode(['ok' => false, 'error' => 'Invalid request. Reload the page.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$plan = getUserPlan($conn, $userId);
if (!planHasFeature($plan, 'chatbot')) {
    echo json_encode(['ok' => false, 'error' => 'The chatbot is not part of your plan.']);
    exit;
}

$message = trim((string)($input['message'] ?? ''));
if ($message === '') {
    echo json_encode(['ok' => false, 'error' => 'Type a message to test with.']);
    exit;
}
if (mb_strlen($message) > 2000) {
    echo json_encode(['ok' => false, 'error' => 'That test message is too long.']);
    exit;
}

// A test still costs a model call, so it is metered against the same monthly
// allowance. Testing must not be a way to spend the platform's money for free.
[$quotaOk, , $limit] = checkMessageQuota($conn, $userId);
if (!$quotaOk) {
    echo json_encode(['ok' => false, 'error' => 'Monthly message limit reached (' . number_format($limit) . ').']);
    exit;
}

$config = chatbotConfig($conn, $userId);
$profile = getUserProfile($conn, $userId);

$result = chatbotGenerateReply($conn, $userId, $config, [], $message, [
    'business_name' => $profile['company_name'] ?? '',
]);

$modelLabel = '';
if (!empty($result['model_id'])) {
    $model = llmModelById($conn, (int)$result['model_id']);
    $modelLabel = $model ? $model['provider_label'] . ' — ' . $model['label'] : '';
} elseif (!empty($config['byo_provider_code'])) {
    $modelLabel = llmProviderLabel($config['byo_provider_code']) . ' — ' . ($config['byo_model_code'] ?? '');
}

chatbotLogEvent($conn, $userId, $result['ok'] ? 'replied' : ($result['reason'] ?? 'llm_error'), [
    'detail' => $result['ok'] ? 'test console' : 'test console: ' . ($result['error'] ?? ''),
    'model_id' => $result['model_id'] ?? null,
    'prompt_tokens' => $result['usage']['prompt'] ?? null,
    'completion_tokens' => $result['usage']['completion'] ?? null,
    'latency_ms' => $result['latency_ms'] ?? null,
]);

if (!$result['ok']) {
    // The tenant owns this configuration, so they see the real reason — unlike a
    // customer, who only ever sees the fallback message.
    echo json_encode(['ok' => false, 'error' => $result['error'] ?: 'The model did not reply.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'reply' => $result['text'],
    'model' => $modelLabel,
    'latency_ms' => $result['latency_ms'] ?? 0,
    'tokens' => ($result['usage']['prompt'] ?? 0) + ($result['usage']['completion'] ?? 0),
]);
