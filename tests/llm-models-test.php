<?php
// llmNormalizeVendorModels() / llmCatalogueModelsFor(): the pure half of the
// add-model picker — what each vendor's /models response becomes. No database
// or network, like llm-fenllm-test.php.
//
// Run with:  php tests/llm-models-test.php

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

function ids($list) {
    return array_map(function ($m) { return $m['id']; }, $list);
}

// --- OpenAI -------------------------------------------------------------------

group('OpenAI keeps chat/transcribe families and drops the rest');

$openaiBody = ['data' => [
    ['id' => 'gpt-4o-mini'],
    ['id' => 'o3'],
    ['id' => 'whisper-1'],
    ['id' => 'gpt-4o-transcribe'],
    ['id' => 'text-embedding-3-small'],
    ['id' => 'tts-1'],
    ['id' => 'dall-e-3'],
    ['id' => 'gpt-4o-realtime-preview'],
    ['id' => 'gpt-4o-audio-preview'],
    ['id' => 'omni-moderation-latest'],
    ['id' => 'gpt-4.1-mini'],
]];
$openai = llmNormalizeVendorModels('openai', $openaiBody);
equals('kept ids, sorted', ['gpt-4.1-mini', 'gpt-4o-mini', 'gpt-4o-transcribe', 'o3', 'whisper-1'],
    ids($openai));
equals('gpt-4o-mini keeps its catalogue label', 'GPT-4o mini',
    $openai[array_search('gpt-4o-mini', ids($openai))]['label']);
equals('whisper is transcribe', 'transcribe',
    $openai[array_search('whisper-1', ids($openai))]['kind']);
equals('gpt-4o-transcribe is transcribe', 'transcribe',
    $openai[array_search('gpt-4o-transcribe', ids($openai))]['kind']);
equals('unknown id prettified', 'GPT-4.1 mini',
    $openai[array_search('gpt-4.1-mini', ids($openai))]['label']);
equals('o3 prettified', 'O3',
    $openai[array_search('o3', ids($openai))]['label']);

// --- Anthropic ------------------------------------------------------------------

group('Anthropic labels come from display_name');

$anthropic = llmNormalizeVendorModels('anthropic', ['data' => [
    ['id' => 'claude-sonnet-4-5', 'display_name' => 'Claude Sonnet 4.5'],
    ['id' => 'claude-haiku', 'display_name' => ''],
]]);
$byId = [];
foreach ($anthropic as $m) $byId[$m['id']] = $m;
equals('display_name used', 'Claude Sonnet 4.5', $byId['claude-sonnet-4-5']['label']);
equals('empty display_name falls back to id', 'claude-haiku', $byId['claude-haiku']['label']);
equals('kind chat', 'chat', $byId['claude-sonnet-4-5']['kind']);

// --- Google ---------------------------------------------------------------------

group('Google strips models/ and keeps generateContent gemini only');

$google = llmNormalizeVendorModels('google', ['models' => [
    ['name' => 'models/gemini-2.5-pro', 'displayName' => 'Gemini 2.5 Pro',
     'supportedGenerationMethods' => ['generateContent']],
    ['name' => 'models/gemini-embedding-001', 'displayName' => 'Embedding',
     'supportedGenerationMethods' => ['embedContent']],
    ['name' => 'models/aqa', 'displayName' => 'AQA',
     'supportedGenerationMethods' => ['generateContent']],
]]);
equals('only the one model', ['gemini-2.5-pro'], ids($google));
equals('displayName label', 'Gemini 2.5 Pro', $google[0]['label']);

// --- FenLLM ---------------------------------------------------------------------

group('FenLLM labels come from the catalogue');

$fenllm = llmNormalizeVendorModels('fenllm', ['data' => [
    ['id' => 'max'], ['id' => 'basic'], ['id' => 'ultra'],
]]);
equals('sorted', ['basic', 'max', 'ultra'], ids($fenllm));
equals('catalogue label', 'FenLLM Max', $fenllm[1]['label']);
equals('unknown id labelled', 'FenLLM Ultra', $fenllm[2]['label']);
equals('kind chat', 'chat', $fenllm[0]['kind']);

// --- Shape rules shared by every provider ----------------------------------------

group('Dedupe, sort, cap and degenerate input');

$dup = llmNormalizeVendorModels('openai', ['data' => [
    ['id' => 'o3'], ['id' => 'o3'], ['id' => 'gpt-4o-mini'],
]]);
equals('deduped', ['gpt-4o-mini', 'o3'], ids($dup));
equals('non-array body', [], llmNormalizeVendorModels('openai', 'nope'));
equals('unknown provider', [], llmNormalizeVendorModels('acme', ['data' => [['id' => 'x']]]));

group('The catalogue fallback has the same shape');

$cat = llmCatalogueModelsFor('openai');
equals('openai catalogue count', 3, count($cat));
equals('first entry', ['id' => 'gpt-4o-mini', 'label' => 'GPT-4o mini', 'kind' => 'chat'], $cat[0]);
equals('whisper kind', 'transcribe', $cat[2]['kind']);
equals('unknown provider empty', [], llmCatalogueModelsFor('acme'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
