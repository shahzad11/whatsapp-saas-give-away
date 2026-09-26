<?php
// The FenLLM helpers that are pure — error formatting, key scrubbing, the
// provider catalogue order — without a database or a network.
//
// Run with:  php tests/llm-fenllm-test.php
//
// FenLLM is the only vendor with three different error shapes (the gateway's
// string code, the account API's error object, and Laravel validation bodies
// from the app side), so those are pinned down here where a regression is a
// test failure rather than a silent "request rejected" on the admin page.
//
// No framework and no database, like chatbot-guard-test.php. llm.php needs
// nothing at include time beyond the constants these tests exercise — every
// mysqli/crypto dependency is inside function bodies, typed but never called
// here.

require_once __DIR__ . '/../frontend-php/includes/llm.php';

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

function equals($name, $expected, $actual) {
    check($name, $expected === $actual,
        'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function group($title) {
    echo "\n{$title}\n";
}

// --- Error formatting: the gateway's string shape ----------------------------

group('Gateway errors carry a string code and a sibling message');

equals('string shape', 'FenLLM 402 (trial_exhausted): Your trial credit is used up.',
    llmFenLlmErrorMessage(402, ['error' => 'trial_exhausted', 'message' => 'Your trial credit is used up.']));

equals('429 code survives', 'FenLLM 429 (rate_limited): Slow down.',
    llmFenLlmErrorMessage(429, ['error' => 'rate_limited', 'message' => 'Slow down.']));

// --- Error formatting: the object shape --------------------------------------

group('Account-API errors carry an error object');

equals('object shape', 'FenLLM 401 (invalid_key): Key is revoked.',
    llmFenLlmErrorMessage(401, ['error' => ['code' => 'invalid_key', 'message' => 'Key is revoked.']]));

// --- Error formatting: Laravel validation -------------------------------------

group('Laravel bodies have only a top-level message');

equals('laravel shape', 'FenLLM 422: The email field is required.',
    llmFenLlmErrorMessage(422, ['message' => 'The email field is required.', 'errors' => ['email' => ['required']]]));

group('Degenerate bodies still produce a sentence');

equals('null body', 'FenLLM 503: request rejected',
    llmFenLlmErrorMessage(503, null));
equals('empty array', 'FenLLM 500: request rejected',
    llmFenLlmErrorMessage(500, []));

// --- Scrubbing ----------------------------------------------------------------

group('A FenLLM key is scrubbed whether or not it was handed over');

$key = 'apl_live_AbDDkBQ4uAYJw3kY6OHZtcAJUdxtQZ0G';
equals('passed as $key', 'FenLLM 401 (invalid_key): key [redacted] rejected',
    llmScrubSecret("FenLLM 401 (invalid_key): key {$key} rejected", $key));

equals('matched by the regex alone', 'echoed [redacted] back',
    llmScrubSecret("echoed {$key} back"));

equals('other key shapes still redact', 'a [redacted] and [redacted]',
    llmScrubSecret('a sk-abc123456789 and AIza' . str_repeat('x', 30)));

// --- Catalogue ----------------------------------------------------------------

group('FenLLM is listed first and points at its own API');

$cat = llmProviderCatalogue();
equals('first provider is fenllm', 'fenllm', array_key_first($cat));
equals('base url', 'https://api.fenllm.com/v1', $cat['fenllm']['base_url']);
equals('transcribes via chat completions', true, $cat['fenllm']['transcribe']);
equals('default provider constant names it', 'fenllm', LLM_DEFAULT_PROVIDER);
equals('model codes in order', ['basic', 'pro', 'max'],
    array_map(function ($m) { return $m[0]; }, $cat['fenllm']['models']));

// --- Transcription capability ------------------------------------------------

group('A model transcribes by kind or by catalogue audio capability');

check('fenllm max (chat + audio)', llmModelTranscribes(
    ['provider_code' => 'fenllm', 'model_code' => 'max', 'kind' => 'chat']));
check('fenllm pro (chat + audio)', llmModelTranscribes(
    ['provider_code' => 'fenllm', 'model_code' => 'pro', 'kind' => 'chat']));
check('fenllm basic does not', !llmModelTranscribes(
    ['provider_code' => 'fenllm', 'model_code' => 'basic', 'kind' => 'chat']));
check('openai whisper (kind transcribe)', llmModelTranscribes(
    ['provider_code' => 'openai', 'model_code' => 'whisper-1', 'kind' => 'transcribe']));

// --- FenLLM transcription payload ---------------------------------------------

group('The FenLLM transcription payload is a chat completion with input_audio');

// FenLLM accepts mp3/wav only; anything else must have been converted first,
// so the payload builder refuses rather than sends audio the gateway rejects.
$payload = llmFenLlmTranscribePayload('max', 'abc', 'voice.mp3');
equals('model', 'max', $payload['model']);
equals('audio part type', 'input_audio', $payload['messages'][0]['content'][1]['type']);
equals('mp3 format', 'mp3', $payload['messages'][0]['content'][1]['input_audio']['format']);
equals('audio is base64', base64_encode('abc'), $payload['messages'][0]['content'][1]['input_audio']['data']);
equals('streams', true, $payload['stream']);
equals('wav format', 'wav', llmFenLlmTranscribePayload('max', 'a', 'x.wav')['messages'][0]['content'][1]['input_audio']['format']);
equals('ogg is refused', null, llmFenLlmTranscribePayload('max', 'a', 'x.ogg'));
equals('unknown extension is refused', null, llmFenLlmTranscribePayload('max', 'a', 'x.bin'));

// --- Balance refresh throttling ----------------------------------------------

group('The balance is re-fetched only after the throttle window');

equals('never fetched', true,
    llmFenLlmBalanceNeedsRefresh(null, null, 1000));
equals('fetched 10s ago', false,
    llmFenLlmBalanceNeedsRefresh(990, null, 1000));
equals('fetched 31s ago', true,
    llmFenLlmBalanceNeedsRefresh(969, null, 1000));
equals('a fresh error throttles too', false,
    llmFenLlmBalanceNeedsRefresh(900, 995, 1000));
equals('exactly 30s is stale', true,
    llmFenLlmBalanceNeedsRefresh(970, null, 1000));

// --- Balance error text -------------------------------------------------------

group('Balance failures are one readable sentence, scrubbed');

equals('curl error', 'Could not reach FenLLM: timed out',
    llmFenLlmBalanceErrorText(0, null, 'timed out'));
equals('401 names the fix',
    'FenLLM rejected the stored API key (401) — paste a new key or sign in to your account.',
    llmFenLlmBalanceErrorText(401, ['error' => ['code' => 'invalid_key', 'message' => 'x']], null));
equals('403 same wording',
    'FenLLM rejected the stored API key (403) — paste a new key or sign in to your account.',
    llmFenLlmBalanceErrorText(403, null, null));
equals('402 uses the vendor formatter', 'FenLLM 402 (trial_exhausted): Top up.',
    llmFenLlmBalanceErrorText(402, ['error' => 'trial_exhausted', 'message' => 'Top up.'], null));
equals('a key inside a curl error is scrubbed',
    'Could not reach FenLLM: auth [redacted] failed',
    llmFenLlmBalanceErrorText(0, null, "auth {$key} failed"));

// --- Balance merge ------------------------------------------------------------

group('A failed refresh never loses the last good balance');

$prev = ['balance' => ['balance' => 'USD 5.00'], 'fetched_at' => 500,
         'error' => 'old', 'error_at' => 400];

$merged = llmFenLlmBalanceMerge($prev, 200, ['balance' => 'USD 6.00'], null, 1000);
equals('success replaces balance', ['balance' => 'USD 6.00'], $merged['balance']);
equals('success stamps fetched_at', 1000, $merged['fetched_at']);
equals('success clears error', null, $merged['error']);
equals('success clears error_at', null, $merged['error_at']);

foreach ([['curl err', 0, null, 'timed out'],
          ['http 500', 500, null, null],
          ['200 null body', 200, null, null]] as [$label, $status, $body, $curlErr]) {
    $merged = llmFenLlmBalanceMerge($prev, $status, $body, $curlErr, 1000);
    equals("{$label}: balance kept", ['balance' => 'USD 5.00'], $merged['balance']);
    equals("{$label}: fetched_at kept", 500, $merged['fetched_at']);
    check("{$label}: error set", is_string($merged['error']) && $merged['error'] !== '');
    equals("{$label}: error_at stamped", 1000, $merged['error_at']);
}

$merged = llmFenLlmBalanceMerge(
    ['balance' => null, 'fetched_at' => null, 'error' => null, 'error_at' => null],
    0, null, 'timed out', 1000);
equals('failure with no prior balance stays null', null, $merged['balance']);
equals('failure with no prior fetched_at stays null', null, $merged['fetched_at']);
equals('error recorded', 'Could not reach FenLLM: timed out', $merged['error']);

// --- Stream parsing -------------------------------------------------------------

group('A FenLLM streamed reply parses into text');

// Role-only first delta, content split across several chunks, [DONE] marker.
$sse = "data: {\"choices\":[{\"delta\":{\"role\":\"assistant\"}}]}\n"
     . "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n"
     . "data: {\"choices\":[{\"delta\":{\"content\":\" there\"}}]}\n"
     . "data: {\"choices\":[{\"delta\":{\"content\":\"!\"}}]}\n"
     . "data: [DONE]\n";
equals('joined text', ['text' => 'Hello there!', 'error' => null],
    llmFenLlmParseStream($sse));

equals('CRLF endings', ['text' => 'hi', 'error' => null],
    llmFenLlmParseStream("data: {\"choices\":[{\"delta\":{\"content\":\"hi\"}}]}\r\ndata: [DONE]\r\n"));

$errorChunk = llmFenLlmParseStream(
    "data: {\"error\":{\"message\":\"audio too long\"}}\ndata: [DONE]\n");
equals('error chunk surfaces', 'audio too long', $errorChunk['error']);
equals('error chunk leaves text empty', '', $errorChunk['text']);

$plain = llmFenLlmParseStream(
    '{"choices":[{"message":{"content":"transcript here"}}]}');
equals('non-stream JSON reply', ['text' => 'transcript here', 'error' => null], $plain);

$plainErr = llmFenLlmParseStream('{"error":{"message":"key rejected"}}');
equals('non-stream error body', 'key rejected', $plainErr['error']);

equals('garbage is empty, not a crash', ['text' => '', 'error' => null],
    llmFenLlmParseStream('not json at all'));
equals('empty input', ['text' => '', 'error' => null], llmFenLlmParseStream(''));

// --- Transcription prompt per customer language --------------------------------

group('The transcription prompt pins the script');

check('ur prompt says Urdu script',
    str_contains(llmTranscribePrompt('ur'), 'Urdu script'));
check('ur prompt forbids Devanagari',
    str_contains(llmTranscribePrompt('ur'), 'never in Devanagari'));
check('hi prompt says Devanagari',
    str_contains(llmTranscribePrompt('hi'), 'Devanagari'));
check('no language keeps both hints',
    str_contains(llmTranscribePrompt(null), 'Urdu script')
    && str_contains(llmTranscribePrompt(null), 'Devanagari'));

$prompted = llmFenLlmTranscribePayload('pro', 'abc', 'voice.mp3', 'ur');
equals('payload carries the ur prompt', llmTranscribePrompt('ur'),
    $prompted['messages'][0]['content'][0]['text']);
equals('payload without language still builds', llmTranscribePrompt(null),
    llmFenLlmTranscribePayload('pro', 'abc', 'voice.mp3')['messages'][0]['content'][0]['text']);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
