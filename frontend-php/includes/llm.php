<?php

// The LLM layer: everything that talks to a model vendor.
//
// Three vendors, three wire formats, one internal shape. Callers hand this file
// a list of messages and get back text — they never learn which vendor answered
// or what the key was.
//
// Keys never leave this file in plaintext. They are stored with the same
// libsodium secretbox used for the SMTP password (includes/crypto.php), are
// never rendered into a form, never returned by an AJAX endpoint, and never
// written to a log — including inside error messages, which is why
// llmScrubSecret() exists.

const LLM_CONTEXT = 'llm-key-v1';

// The vendors this instance knows how to speak to. Adding one means adding a
// case to llmChatRequest() — the catalogue is not a lookup table of URLs
// because the request and response shapes genuinely differ.
//
// console_url is where an admin actually gets a key. The admin page asked for an
// "API key" and left finding one as an exercise; an admin who has never used a
// model vendor cannot proceed from that, and guessing is how people end up
// pasting the wrong credential.
function llmProviderCatalogue() {
    return [
        'openai' => [
            'label'      => 'OpenAI',
            'base_url'   => 'https://api.openai.com/v1',
            'key_hint'   => 'sk-…',
            'console_url' => 'https://platform.openai.com/api-keys',
            'transcribe' => true,
            'models'     => [
                ['gpt-4o-mini', 'GPT-4o mini', 'chat'],
                ['gpt-4o', 'GPT-4o', 'chat'],
                ['whisper-1', 'Whisper (audio transcription)', 'transcribe'],
            ],
        ],
        'anthropic' => [
            'label'      => 'Anthropic',
            'base_url'   => 'https://api.anthropic.com/v1',
            'key_hint'   => 'sk-ant-…',
            'console_url' => 'https://console.anthropic.com/settings/keys',
            'transcribe' => false,
            'models'     => [
                ['claude-3-5-haiku-latest', 'Claude 3.5 Haiku', 'chat'],
                ['claude-sonnet-4-5', 'Claude Sonnet 4.5', 'chat'],
            ],
        ],
        'google' => [
            'label'      => 'Google Gemini',
            'base_url'   => 'https://generativelanguage.googleapis.com/v1beta',
            'key_hint'   => 'AIza…',
            'console_url' => 'https://aistudio.google.com/app/apikey',
            'transcribe' => false,
            'models'     => [
                ['gemini-2.0-flash', 'Gemini 2.0 Flash', 'chat'],
                ['gemini-2.5-pro', 'Gemini 2.5 Pro', 'chat'],
            ],
        ],
    ];
}

function llmProviderLabel($code) {
    return llmProviderCatalogue()[$code]['label'] ?? ucfirst((string)$code);
}

function llmIsKnownProvider($code) {
    return array_key_exists((string)$code, llmProviderCatalogue());
}

// --- Storage ----------------------------------------------------------------

function llmProviders(mysqli $conn) {
    $rows = $conn->query("SELECT * FROM llm_providers ORDER BY label ASC");
    return $rows ? $rows->fetch_all(MYSQLI_ASSOC) : [];
}

function llmProviderByCode(mysqli $conn, $code) {
    $stmt = $conn->prepare("SELECT * FROM llm_providers WHERE code = ?");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// Saves a provider. A blank key means "leave the stored one alone" — the form
// never renders the existing key, so an empty field is the normal case when an
// admin edits anything else on the row.
function llmSaveProvider(mysqli $conn, $code, $label, $apiKey, $baseUrl, $isEnabled) {
    $existing = llmProviderByCode($conn, $code);
    $enabled = $isEnabled ? 1 : 0;
    $baseUrl = trim((string)$baseUrl) ?: null;

    $encrypted = null;
    if ($apiKey !== null && trim($apiKey) !== '') {
        $encrypted = encryptSecret(trim($apiKey), LLM_CONTEXT);
        // A null return means encryption is impossible. Storing the key in the
        // clear instead is never the fallback.
        if ($encrypted === null) return [false, 'Cannot encrypt the API key — APP_SECRET_KEY is missing or sodium is unavailable.'];
    }

    if ($existing) {
        if ($encrypted !== null) {
            $stmt = $conn->prepare("UPDATE llm_providers SET label = ?, api_key_encrypted = ?, base_url = ?, is_enabled = ? WHERE code = ?");
            $stmt->bind_param('sssis', $label, $encrypted, $baseUrl, $enabled, $code);
        } else {
            $stmt = $conn->prepare("UPDATE llm_providers SET label = ?, base_url = ?, is_enabled = ? WHERE code = ?");
            $stmt->bind_param('ssis', $label, $baseUrl, $enabled, $code);
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO llm_providers (code, label, api_key_encrypted, base_url, is_enabled) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssi', $code, $label, $encrypted, $baseUrl, $enabled);
    }
    $stmt->execute();
    $stmt->close();
    return [true, null];
}

function llmProviderHasKey($provider) {
    return !empty($provider['api_key_encrypted']);
}

function llmProviderKey($provider) {
    return decryptSecret($provider['api_key_encrypted'] ?? null, LLM_CONTEXT);
}

function llmProviderBaseUrl($provider) {
    $code = $provider['code'] ?? '';
    $configured = trim((string)($provider['base_url'] ?? ''));
    if ($configured !== '') return rtrim($configured, '/');
    return rtrim(llmProviderCatalogue()[$code]['base_url'] ?? '', '/');
}

function llmRecordTest(mysqli $conn, $code, $ok, $error = null) {
    $okFlag = $ok ? 1 : 0;
    $error = $error === null ? null : substr(llmScrubSecret($error), 0, 255);
    $stmt = $conn->prepare("UPDATE llm_providers SET last_tested_at = UTC_TIMESTAMP(), last_test_ok = ?, last_test_error = ? WHERE code = ?");
    $stmt->bind_param('iss', $okFlag, $error, $code);
    $stmt->execute();
    $stmt->close();
}

// --- Models -----------------------------------------------------------------

function llmModels(mysqli $conn, $kind = null, $onlyEnabled = false) {
    $sql = "SELECT m.*, p.code AS provider_code, p.label AS provider_label, p.is_enabled AS provider_enabled
            FROM llm_models m JOIN llm_providers p ON m.provider_id = p.id";
    $where = [];
    if ($kind !== null) $where[] = "m.kind = '" . ($kind === 'transcribe' ? 'transcribe' : 'chat') . "'";
    if ($onlyEnabled) $where[] = 'm.is_enabled = 1 AND p.is_enabled = 1';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY p.label ASC, m.sort_order ASC, m.label ASC';

    $rows = $conn->query($sql);
    return $rows ? $rows->fetch_all(MYSQLI_ASSOC) : [];
}

function llmModelById(mysqli $conn, $modelId) {
    $stmt = $conn->prepare(
        "SELECT m.*, p.code AS provider_code, p.label AS provider_label, p.is_enabled AS provider_enabled,
                p.api_key_encrypted, p.base_url
         FROM llm_models m JOIN llm_providers p ON m.provider_id = p.id WHERE m.id = ?"
    );
    $stmt->bind_param('i', $modelId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function llmAddModel(mysqli $conn, $providerId, $modelCode, $label, $kind) {
    $kind = $kind === 'transcribe' ? 'transcribe' : 'chat';
    $stmt = $conn->prepare(
        "INSERT INTO llm_models (provider_id, model_code, label, kind) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE label = VALUES(label), kind = VALUES(kind)"
    );
    $stmt->bind_param('isss', $providerId, $modelCode, $label, $kind);
    $stmt->execute();
    $stmt->close();
}

function llmSetModelEnabled(mysqli $conn, $modelId, $enabled) {
    $flag = $enabled ? 1 : 0;
    $stmt = $conn->prepare("UPDATE llm_models SET is_enabled = ? WHERE id = ?");
    $stmt->bind_param('ii', $flag, $modelId);
    $stmt->execute();
    $stmt->close();
}

function llmDeleteModel(mysqli $conn, $modelId) {
    $stmt = $conn->prepare("DELETE FROM llm_models WHERE id = ?");
    $stmt->bind_param('i', $modelId);
    $stmt->execute();
    $stmt->close();
}

// --- Per-plan access --------------------------------------------------------

function llmPlanModelIds(mysqli $conn, $planId) {
    $stmt = $conn->prepare("SELECT model_id FROM plan_llm_models WHERE plan_id = ?");
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ids = [];
    while ($row = $res->fetch_assoc()) $ids[] = (int)$row['model_id'];
    $stmt->close();
    return $ids;
}

function llmSetPlanModels(mysqli $conn, $planId, array $modelIds) {
    $stmt = $conn->prepare("DELETE FROM plan_llm_models WHERE plan_id = ?");
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $stmt->close();

    if (!$modelIds) return;
    $stmt = $conn->prepare("INSERT IGNORE INTO plan_llm_models (plan_id, model_id) VALUES (?, ?)");
    foreach ($modelIds as $id) {
        $id = (int)$id;
        $stmt->bind_param('ii', $planId, $id);
        $stmt->execute();
    }
    $stmt->close();
}

// Grants a provider's chat models to every active plan whose `chatbot` feature
// is on, and returns how many grants were created.
//
// Called when a provider's catalogue is first seeded. Without it the models
// exist but belong to no plan, so an admin who completes the two obvious steps
// — save a provider with a key, enable the chatbot feature on a plan — still
// leaves every tenant, themselves included, looking at "No models are available
// on your plan yet". The one step that fixes it is a checkbox matrix in a
// sidebar card on a page they have already left.
//
// INSERT IGNORE rather than llmSetPlanModels(): this may only ever add. The
// table's PRIMARY KEY (plan_id, model_id) makes an existing grant a no-op, so
// re-seeding cannot duplicate rows, and a matrix the admin curated by hand is
// never clobbered.
//
// Chat models only. A transcribe model is not selectable as a chatbot model and
// granting it would put a nonsense option in front of the tenant.
function llmGrantChatModelsToChatbotPlans(mysqli $conn, $providerId) {
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO plan_llm_models (plan_id, model_id)
         SELECT ?, id FROM llm_models WHERE provider_id = ? AND kind = 'chat'"
    );
    $providerId = (int)$providerId;
    $granted = 0;
    foreach (getActivePlans($conn) as $plan) {
        if (!planHasFeature($plan, 'chatbot')) continue;
        $planId = (int)$plan['id'];
        $stmt->bind_param('ii', $planId, $providerId);
        $stmt->execute();
        if ($stmt->affected_rows > 0) $granted += $stmt->affected_rows;
    }
    $stmt->close();
    return $granted;
}

// The models a given tenant may actually pick: enabled model, enabled provider,
// provider has a key, and the tenant's plan has been granted access.
function llmModelsForPlan(mysqli $conn, $planId) {
    $stmt = $conn->prepare(
        "SELECT m.id, m.model_code, m.label, m.kind, p.code AS provider_code, p.label AS provider_label
         FROM plan_llm_models pm
         JOIN llm_models m ON pm.model_id = m.id
         JOIN llm_providers p ON m.provider_id = p.id
         WHERE pm.plan_id = ? AND m.is_enabled = 1 AND p.is_enabled = 1
           AND p.api_key_encrypted IS NOT NULL AND m.kind = 'chat'
         ORDER BY p.label ASC, m.sort_order ASC, m.label ASC"
    );
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// --- Instance toggles -------------------------------------------------------

// Both are admin switches from #9. BYO defaults on per the owner's decision;
// audio defaults off because it costs money per message and needs a provider
// that can transcribe.
function llmAllowByoKeys(?mysqli $conn = null) {
    $value = overrideSetting($conn, 'llm_allow_byo_keys');
    return $value === null ? true : $value === '1';
}

function llmAudioEnabled(?mysqli $conn = null) {
    $value = overrideSetting($conn, 'llm_enable_audio');
    return $value === '1';
}

// The model used to turn a voice note into text. One instance-wide choice
// rather than a per-tenant one: it is the platform owner who pays for it.
function llmTranscribeModelId(?mysqli $conn = null) {
    $value = overrideSetting($conn, 'llm_transcribe_model_id');
    return $value === null ? null : (int)$value;
}

// --- Calling a model --------------------------------------------------------

// $messages is [['role' => 'system'|'user'|'assistant', 'content' => '...'], …].
// Returns ['ok' => bool, 'text' => string, 'error' => ?string, 'usage' => [...]].
//
// $auth is ['provider' => code, 'key' => plaintext, 'base_url' => ?string,
//           'model' => model_code] — resolved by the caller, because a tenant's
// own key and an admin provider key take different paths to get here.
function llmChat(array $auth, array $messages, array $options = []) {
    $started = microtime(true);
    $provider = $auth['provider'] ?? '';

    switch ($provider) {
        case 'openai':    $result = llmCallOpenAi($auth, $messages, $options); break;
        case 'anthropic': $result = llmCallAnthropic($auth, $messages, $options); break;
        case 'google':    $result = llmCallGoogle($auth, $messages, $options); break;
        default:          return ['ok' => false, 'error' => 'Unknown provider', 'text' => '', 'usage' => []];
    }

    $result['latency_ms'] = (int)round((microtime(true) - $started) * 1000);
    if (!empty($result['error'])) $result['error'] = llmScrubSecret($result['error'], $auth['key'] ?? null);
    return $result;
}

// A vendor error body can echo the request back, key included. Nothing derived
// from a response reaches a log or a screen without passing through here.
function llmScrubSecret($text, $key = null) {
    $text = (string)$text;
    if ($key !== null && $key !== '' && strlen($key) > 8) {
        $text = str_replace($key, '[redacted]', $text);
    }
    // Also catch keys we were not given — a nested error quoting a different one.
    return preg_replace('/\b(sk-[A-Za-z0-9_\-]{8,}|AIza[A-Za-z0-9_\-]{20,})\b/', '[redacted]', $text);
}

function llmHttpJson($url, array $headers, $payload, $timeout = 60) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        // A vendor endpoint is a fixed, known host. Never follow it elsewhere.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) return [0, null, $err ?: 'Request failed'];
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) return [$status, null, 'Provider returned a non-JSON response'];
    return [$status, $decoded, null];
}

function llmCallOpenAi(array $auth, array $messages, array $options) {
    $base = $auth['base_url'] ?: llmProviderCatalogue()['openai']['base_url'];
    [$status, $body, $err] = llmHttpJson(rtrim($base, '/') . '/chat/completions',
        ['Authorization: Bearer ' . $auth['key']],
        [
            'model' => $auth['model'],
            'messages' => $messages,
            'max_completion_tokens' => (int)($options['max_tokens'] ?? 400),
        ]);

    if ($err) return ['ok' => false, 'error' => $err, 'text' => '', 'usage' => []];
    if ($status !== 200) {
        return ['ok' => false, 'text' => '', 'usage' => [],
                'error' => 'OpenAI ' . $status . ': ' . ($body['error']['message'] ?? 'request rejected')];
    }

    $text = trim((string)($body['choices'][0]['message']['content'] ?? ''));
    return [
        'ok' => $text !== '',
        'text' => $text,
        'error' => $text === '' ? 'Empty completion' : null,
        'usage' => [
            'prompt' => (int)($body['usage']['prompt_tokens'] ?? 0),
            'completion' => (int)($body['usage']['completion_tokens'] ?? 0),
        ],
    ];
}

// Anthropic differs in three ways that matter: the system prompt is a top-level
// field rather than a message, max_tokens is required, and the reply is a list
// of content blocks.
function llmCallAnthropic(array $auth, array $messages, array $options) {
    $system = '';
    $turns = [];
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'system') {
            $system .= ($system === '' ? '' : "\n\n") . $m['content'];
            continue;
        }
        $turns[] = ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $m['content']];
    }
    if (!$turns) return ['ok' => false, 'error' => 'No conversation to send', 'text' => '', 'usage' => []];

    $base = $auth['base_url'] ?: llmProviderCatalogue()['anthropic']['base_url'];
    $payload = [
        'model' => $auth['model'],
        'max_tokens' => (int)($options['max_tokens'] ?? 400),
        'messages' => $turns,
    ];
    if ($system !== '') $payload['system'] = $system;

    [$status, $body, $err] = llmHttpJson(rtrim($base, '/') . '/messages',
        ['x-api-key: ' . $auth['key'], 'anthropic-version: 2023-06-01'], $payload);

    if ($err) return ['ok' => false, 'error' => $err, 'text' => '', 'usage' => []];
    if ($status !== 200) {
        return ['ok' => false, 'text' => '', 'usage' => [],
                'error' => 'Anthropic ' . $status . ': ' . ($body['error']['message'] ?? 'request rejected')];
    }

    $text = '';
    foreach ($body['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    $text = trim($text);
    return [
        'ok' => $text !== '',
        'text' => $text,
        'error' => $text === '' ? 'Empty completion' : null,
        'usage' => [
            'prompt' => (int)($body['usage']['input_tokens'] ?? 0),
            'completion' => (int)($body['usage']['output_tokens'] ?? 0),
        ],
    ];
}

// Gemini puts the key in the query string, calls turns "contents", the
// assistant "model", and the text "parts".
function llmCallGoogle(array $auth, array $messages, array $options) {
    $system = '';
    $contents = [];
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'system') {
            $system .= ($system === '' ? '' : "\n\n") . $m['content'];
            continue;
        }
        $contents[] = [
            'role' => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ];
    }
    if (!$contents) return ['ok' => false, 'error' => 'No conversation to send', 'text' => '', 'usage' => []];

    $base = $auth['base_url'] ?: llmProviderCatalogue()['google']['base_url'];
    $payload = [
        'contents' => $contents,
        'generationConfig' => ['maxOutputTokens' => (int)($options['max_tokens'] ?? 400)],
    ];
    if ($system !== '') $payload['systemInstruction'] = ['parts' => [['text' => $system]]];

    // The key goes in a header, not the query string: query strings end up in
    // access logs and proxy logs.
    $url = rtrim($base, '/') . '/models/' . rawurlencode($auth['model']) . ':generateContent';
    [$status, $body, $err] = llmHttpJson($url, ['x-goog-api-key: ' . $auth['key']], $payload);

    if ($err) return ['ok' => false, 'error' => $err, 'text' => '', 'usage' => []];
    if ($status !== 200) {
        return ['ok' => false, 'text' => '', 'usage' => [],
                'error' => 'Google ' . $status . ': ' . ($body['error']['message'] ?? 'request rejected')];
    }

    $text = '';
    foreach ($body['candidates'][0]['content']['parts'] ?? [] as $part) {
        $text .= $part['text'] ?? '';
    }
    $text = trim($text);
    return [
        'ok' => $text !== '',
        'text' => $text,
        'error' => $text === '' ? 'Empty completion' : null,
        'usage' => [
            'prompt' => (int)($body['usageMetadata']['promptTokenCount'] ?? 0),
            'completion' => (int)($body['usageMetadata']['candidatesTokenCount'] ?? 0),
        ],
    ];
}

// --- Transcription ----------------------------------------------------------

// Voice notes are common on WhatsApp, so a bot that ignores them looks broken.
// OpenAI's is the only transcription API wired up; the admin picks the model.
function llmTranscribe(array $auth, $audioBytes, $filename = 'audio.ogg') {
    if (($auth['provider'] ?? '') !== 'openai') {
        return ['ok' => false, 'error' => 'Transcription needs an OpenAI-compatible provider', 'text' => ''];
    }

    $base = $auth['base_url'] ?: llmProviderCatalogue()['openai']['base_url'];
    $tmp = tempnam(sys_get_temp_dir(), 'wa-audio-');
    file_put_contents($tmp, $audioBytes);

    try {
        $ch = curl_init(rtrim($base, '/') . '/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $auth['key']],
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile($tmp, 'audio/ogg', $filename),
                'model' => $auth['model'],
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
    } finally {
        @unlink($tmp);
    }

    if ($body === false) return ['ok' => false, 'error' => llmScrubSecret($err ?: 'Request failed', $auth['key']), 'text' => ''];
    $decoded = json_decode($body, true);
    if ($status !== 200 || !is_array($decoded)) {
        return ['ok' => false, 'text' => '',
                'error' => llmScrubSecret('Transcription ' . $status . ': ' . ($decoded['error']['message'] ?? 'failed'), $auth['key'])];
    }

    $text = trim((string)($decoded['text'] ?? ''));
    return ['ok' => $text !== '', 'text' => $text, 'error' => $text === '' ? 'Empty transcription' : null];
}

// --- Connection test --------------------------------------------------------

// Sends the cheapest possible real request. A key that parses but is revoked
// only reveals itself by being used, so this genuinely calls the vendor.
//
// $candidateKey / $candidateBaseUrl let the admin test what is *typed in the
// form* rather than what is stored. Without them "Test connection" always tested
// the stored row, so pasting a replacement key and clicking Test reported on the
// old key — a green tick for a key that was about to be overwritten, or a red
// cross for a good key that had not been saved yet. Nothing is written here: a
// test must not have the side effect of saving an untested credential.
function llmTestProvider(mysqli $conn, $providerCode, $candidateKey = null, $candidateBaseUrl = null) {
    $provider = llmProviderByCode($conn, $providerCode);
    if (!$provider) return [false, 'Provider not configured'];

    $candidateKey = trim((string)$candidateKey);
    $key = $candidateKey !== '' ? $candidateKey : llmProviderKey($provider);
    if ($key === null) {
        return [false, llmProviderHasKey($provider)
            ? 'Stored key cannot be decrypted — re-enter it (the instance secret may have changed).'
            : 'No API key saved yet.'];
    }

    $models = llmModels($conn, 'chat', false);
    $model = null;
    foreach ($models as $m) {
        if ($m['provider_code'] === $providerCode) { $model = $m['model_code']; break; }
    }
    if ($model === null) return [false, 'Add at least one chat model for this provider first.'];

    $candidateBaseUrl = trim((string)$candidateBaseUrl);
    $result = llmChat([
        'provider' => $providerCode,
        'key' => $key,
        'base_url' => $candidateBaseUrl !== '' ? $candidateBaseUrl : llmProviderBaseUrl($provider),
        'model' => $model,
    ], [['role' => 'user', 'content' => 'Reply with the single word: ok']], ['max_tokens' => 16]);

    llmRecordTest($conn, $providerCode, $result['ok'], $result['error'] ?? null);

    $tested = $candidateKey !== '' ? ' (unsaved key — Save to keep it)' : '';
    return [$result['ok'], $result['ok']
        ? 'Replied in ' . $result['latency_ms'] . ' ms' . $tested
        : $result['error'] . $tested];
}
