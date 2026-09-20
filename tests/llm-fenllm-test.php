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
equals('no transcription', false, $cat['fenllm']['transcribe']);
equals('default provider constant names it', 'fenllm', LLM_DEFAULT_PROVIDER);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
