<?php

// The LLM layer: everything that talks to a model vendor.
//
// Four vendors, three wire formats, one internal shape. Callers hand this file
// a list of messages and get back text — they never learn which vendor answered
// or what the key was. FenLLM is the owner's own OpenAI-compatible router: a
// trial account is provisioned automatically at install, so it is listed first
// and is the default model for every tenant who has not picked one.
//
// Keys never leave this file in plaintext. They are stored with the same
// libsodium secretbox used for the SMTP password (includes/crypto.php), are
// never rendered into a form, never returned by an AJAX endpoint, and never
// written to a log — including inside error messages, which is why
// llmScrubSecret() exists.

const LLM_CONTEXT = 'llm-key-v1';

// The provider this instance prefers when a tenant has not chosen one. It is
// also the provider docker/bootstrap.php auto-provisions a trial account with
// at install, so LLM_DEFAULT_PROVIDER must name a catalogue entry.
const LLM_DEFAULT_PROVIDER = 'fenllm';

// FenLLM's two endpoints outside the OpenAI-compatible base URL: the partner
// API that opens a trial account (authenticated by a partner secret, used once
// per install), and the account API that reports the remaining trial credit.
const LLM_FENLLM_SIGNUP_URL  = 'https://app.fenllm.com/api/partner/signups';
const LLM_FENLLM_BALANCE_URL = 'https://app.fenllm.com/api/v1/account/balance';

// How often the background balance check on the admin page may hit the vendor.
// Failures count too — a down FenLLM must not be hammered once per page open.
const LLM_FENLLM_BALANCE_THROTTLE = 30;

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
        // Listed first because it is pre-configured: a trial account and its
        // key are created automatically at install (llmProvisionFenLlm), so for
        // most instances this is the only provider that ever needs a key.
        'fenllm' => [
            'label'      => 'FenLLM',
            'base_url'   => 'https://api.fenllm.com/v1',
            'key_hint'   => 'apl_live_…',
            'console_url' => 'https://app.fenllm.com',
            'transcribe' => true,
            // The fourth element, when present, is a capabilities list: 'audio'
            // marks a chat model that also accepts input_audio content parts,
            // which is how FenLLM Max transcribes voice notes (it has no
            // /audio/transcriptions endpoint — /chat/completions only).
            'models'     => [
                ['basic', 'FenLLM Basic', 'chat'],
                ['pro',   'FenLLM Pro',   'chat'],
                ['max',   'FenLLM Max',   'chat', ['audio']],
            ],
        ],
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

// Can this llm_models row turn audio into text? Two ways: the row's kind is
// 'transcribe' (a dedicated endpoint model like whisper-1), or the catalogue
// lists the model with an 'audio' capability (a chat model that also takes
// input_audio parts, like FenLLM Max). Needs provider_code and model_code on
// the row — llmModels() and llmModelById() both return them.
function llmModelTranscribes(array $model) {
    if (($model['kind'] ?? '') === 'transcribe') return true;
    $provider = $model['provider_code'] ?? '';
    $code = $model['model_code'] ?? '';
    foreach (llmProviderCatalogue()[$provider]['models'] ?? [] as $entry) {
        if ($entry[0] === $code && in_array('audio', $entry[3] ?? [], true)) return true;
    }
    return false;
}

// Every model that can transcribe, for the admin dropdown and its validation.
// Replaces filtering by kind alone, which would hide a capable chat model.
function llmTranscribeCandidates(mysqli $conn, $onlyEnabled = false) {
    return array_values(array_filter(
        llmModels($conn, null, $onlyEnabled),
        'llmModelTranscribes'
    ));
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
// is on — or to every active plan when $onlyChatbotPlans is false — and returns
// how many grants were created.
//
// The false form exists for the auto-provisioned provider (FenLLM): its model
// is the instance default a tenant falls back to, and that fallback is resolved
// through chatbotResolveModel(), which still checks the `chatbot` feature at
// call time — so granting it to a plan without the feature hands nothing out.
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
function llmGrantChatModelsToChatbotPlans(mysqli $conn, $providerId, $onlyChatbotPlans = true) {
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO plan_llm_models (plan_id, model_id)
         SELECT ?, id FROM llm_models WHERE provider_id = ? AND kind = 'chat'"
    );
    $providerId = (int)$providerId;
    $granted = 0;
    foreach (getActivePlans($conn) as $plan) {
        if ($onlyChatbotPlans && !planHasFeature($plan, 'chatbot')) continue;
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

// The model a tenant gets when they have not chosen one: the first model their
// plan can use that belongs to the instance-provisioned provider (FenLLM).
// Returns the llmModelsForPlan() row, or null when no such model exists — a
// tenant is then in the same "pick a model" state they were always in.
function llmDefaultModelForPlan(mysqli $conn, $planId) {
    foreach (llmModelsForPlan($conn, $planId) as $model) {
        if ($model['provider_code'] === LLM_DEFAULT_PROVIDER) return $model;
    }
    return null;
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
        case 'fenllm':    $result = llmCallFenLlm($auth, $messages, $options); break;
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
    return preg_replace('/\b(sk-[A-Za-z0-9_\-]{8,}|apl_live_[A-Za-z0-9_\-]{8,}|AIza[A-Za-z0-9_\-]{20,})\b/', '[redacted]', $text);
}

function llmHttpJson($url, array $headers, $payload, $timeout = 60, $method = 'POST', $connectTimeout = null) {
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        // A vendor endpoint is a fixed, known host. Never follow it elsewhere.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    ];
    if ($method === 'GET') {
        $options[CURLOPT_HTTPGET] = true;
    } else {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    if ($connectTimeout !== null) {
        $options[CURLOPT_CONNECTTIMEOUT] = $connectTimeout;
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) return [0, null, $err ?: 'Request failed'];
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) return [$status, null, 'Provider returned a non-JSON response'];
    return [$status, $decoded, null];
}

// FenLLM's gateway answers errors in two shapes of its own — `error` as a plain
// string code with the human text in a sibling `message`, or `error` as an
// object with code/message — plus Laravel's `{message, errors}` validation
// shape from the app side. One formatter for all three, so a caller never has
// to know which layer answered.
function llmFenLlmErrorMessage($status, $body) {
    $code = null;
    $message = null;
    if (is_array($body)) {
        $error = $body['error'] ?? null;
        if (is_string($error)) {
            $code = $error;
            $message = $body['message'] ?? null;
        } elseif (is_array($error)) {
            $code = $error['code'] ?? null;
            $message = $error['message'] ?? null;
        }
        if ($message === null) $message = $body['message'] ?? null;
    }
    $suffix = trim((string)($message ?? 'request rejected'));
    return 'FenLLM ' . (int)$status
        . ($code !== null && $code !== '' ? ' (' . $code . ')' : '')
        . ': ' . $suffix;
}

// FenLLM speaks the OpenAI wire format on its own base URL. The only deliberate
// difference from llmCallOpenAi is `max_tokens` rather than `max_completion_
// tokens` — the gateway accepts both, and the older field is what every other
// OpenAI-compatible router understands.
function llmCallFenLlm(array $auth, array $messages, array $options) {
    $base = $auth['base_url'] ?: llmProviderCatalogue()['fenllm']['base_url'];
    [$status, $body, $err] = llmHttpJson(rtrim($base, '/') . '/chat/completions',
        ['Authorization: Bearer ' . $auth['key']],
        [
            'model' => $auth['model'],
            'messages' => $messages,
            'max_tokens' => (int)($options['max_tokens'] ?? 400),
        ]);

    if ($err) return ['ok' => false, 'error' => $err, 'text' => '', 'usage' => []];
    if ($status !== 200) {
        // 402 messages carry a top-up link the admin needs verbatim — pass the
        // gateway's own sentence through rather than paraphrasing it.
        return ['ok' => false, 'text' => '', 'usage' => [],
                'error' => llmFenLlmErrorMessage($status, $body)];
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

// --- FenLLM account provisioning -------------------------------------------
//
// The owner runs FenLLM, so every install opens a free trial account on it
// automatically: the container's first boot posts the admin's email to the
// partner signups endpoint and stores the returned key, encrypted, as the
// fenllm provider row. The key is returned exactly once — it is never
// retrievable again — so this flow writes it before anything else can fail.

// POSTs the partner signup. Returns [status, decoded body|null, curl error].
function llmFenLlmSignup($email, $name, $secret) {
    return llmHttpJson(LLM_FENLLM_SIGNUP_URL,
        ['X-Partner-Secret: ' . $secret],
        ['email' => $email, 'name' => $name],
        20);
}

// GETs the trial balance for a stored key. Returns [status, decoded body|null,
// curl error]. The response's `error` here is an OBJECT — unlike the chat
// gateway's string — which is exactly why llmFenLlmErrorMessage() reads both.
function llmFenLlmBalance($apiKey, $timeout = 6, $connectTimeout = 3) {
    return llmHttpJson(LLM_FENLLM_BALANCE_URL,
        ['Authorization: Bearer ' . $apiKey],
        null, $timeout, 'GET', $connectTimeout);
}

// --- FenLLM balance state ---------------------------------------------------
//
// The balance lives in app_settings as a small state object spread across four
// rows: the last good body, when it was fetched, the last failure's message,
// and when that failure happened. A failure never overwrites the last good
// balance — the panel keeps showing it with a "couldn't refresh" note instead
// of going blank every time the vendor hiccups.

// Is the stored state stale enough to justify another call? Both timestamps
// count, so a run of failures is throttled exactly like a run of successes.
function llmFenLlmBalanceNeedsRefresh(?int $fetchedAt, ?int $errorAt, int $now, int $throttle = LLM_FENLLM_BALANCE_THROTTLE): bool {
    $latest = max($fetchedAt ?? 0, $errorAt ?? 0);
    if ($latest <= 0) return true;
    return ($now - $latest) >= $throttle;
}

// One sentence for whatever went wrong, with the common cases said plainly:
// an unreachable host is not a bad key, and a rejected key has a fix the admin
// can act on.
function llmFenLlmBalanceErrorText(int $status, ?array $body, ?string $curlErr): string {
    if ($curlErr !== null && $curlErr !== '') {
        return llmScrubSecret('Could not reach FenLLM: ' . $curlErr);
    }
    if ($status === 401 || $status === 403) {
        return llmScrubSecret("FenLLM rejected the stored API key ({$status}) — paste a new key or sign in to your account.");
    }
    return llmScrubSecret(llmFenLlmErrorMessage($status, $body));
}

// Folds one attempt's outcome into the stored state. Success replaces the
// balance and clears the error; any failure updates only the error half.
function llmFenLlmBalanceMerge(array $state, int $status, ?array $body, ?string $curlErr, int $now): array {
    if ($curlErr === null && $status === 200 && is_array($body)) {
        return [
            'balance'    => $body,
            'fetched_at' => $now,
            'error'      => null,
            'error_at'   => null,
        ];
    }
    $state['error'] = llmFenLlmBalanceErrorText($status, $body, $curlErr);
    $state['error_at'] = $now;
    return $state;
}

// Reads the stored state — no network. Empty strings read back as null, which
// is also how the error keys are cleared below.
function llmFenLlmBalanceState(?mysqli $conn): array {
    $balance = json_decode((string)(overrideSetting($conn, 'fenllm_balance_json') ?? ''), true);
    return [
        'balance'    => is_array($balance) ? $balance : null,
        'fetched_at' => ($at = overrideSetting($conn, 'fenllm_balance_at')) !== null ? (int)$at : null,
        'error'      => overrideSetting($conn, 'fenllm_balance_error'),
        'error_at'   => ($eat = overrideSetting($conn, 'fenllm_balance_error_at')) !== null ? (int)$eat : null,
    ];
}

// Refreshes the balance subject to the throttle (skipped entirely by $force,
// which is what the Refresh button is for). Returns the current state either
// way; when there is no key to call with, the stored state is all there is.
function llmFenLlmBalanceRefresh(mysqli $conn, bool $force = false): array {
    $state = llmFenLlmBalanceState($conn);
    $now = time();
    if (!$force && !llmFenLlmBalanceNeedsRefresh($state['fetched_at'], $state['error_at'], $now)) {
        return $state;
    }

    $provider = llmProviderByCode($conn, 'fenllm');
    if (!$provider || !llmProviderHasKey($provider)) return $state;
    $key = llmProviderKey($provider);
    if ($key === null) return $state;

    [$status, $body, $err] = llmFenLlmBalance($key);
    $state = llmFenLlmBalanceMerge($state, $status, is_array($body) ? $body : null, $err, $now);

    setAppSetting($conn, 'fenllm_balance_json', $state['balance'] !== null ? json_encode($state['balance']) : '');
    setAppSetting($conn, 'fenllm_balance_at', $state['fetched_at'] !== null ? (string)$state['fetched_at'] : '');
    setAppSetting($conn, 'fenllm_balance_error', $state['error'] ?? '');
    setAppSetting($conn, 'fenllm_balance_error_at', $state['error_at'] !== null ? (string)$state['error_at'] : '');
    return $state;
}

// The magic link into the FenLLM dashboard (customers have no password — this
// is how they reach their account). Stored at provisioning; null if it never
// was.
function llmFenLlmSignInUrl(?mysqli $conn) {
    return overrideSetting($conn, 'fenllm_sign_in_url');
}

// Opens the trial account and wires it up. Returns [ok, admin-facing message].
//
// Safe to call on every boot: the first guard is "does a fenllm row with a key
// already exist", so a successful run makes every later call a no-op, and a
// 409 — the email already has an account — is recorded rather than retried,
// because the key from that earlier signup can never be fetched again.
function llmProvisionFenLlm(mysqli $conn, $email, $name, $secret) {
    $existing = llmProviderByCode($conn, 'fenllm');
    if ($existing && llmProviderHasKey($existing)) {
        return [true, 'FenLLM is already provisioned on this instance.'];
    }

    [$status, $body, $err] = llmFenLlmSignup($email, $name, $secret);

    if ($err) {
        setAppSetting($conn, 'fenllm_provision_status', 'error');
        return [false, 'FenLLM signup request failed: ' . $err];
    }

    if ($status === 201 && is_array($body)) {
        $apiKey = trim((string)($body['api_key'] ?? ''));
        if ($apiKey === '') {
            setAppSetting($conn, 'fenllm_provision_status', 'error');
            return [false, 'FenLLM returned no API key — nothing was stored.'];
        }
        // Encrypting must succeed before the key is saved; the plaintext never
        // touches the database either way.
        if (encryptSecret($apiKey, LLM_CONTEXT) === null) {
            setAppSetting($conn, 'fenllm_provision_status', 'error');
            return [false, cryptoSecretMissingMessage()];
        }

        [$saved, $saveErr] = llmSaveProvider($conn, 'fenllm', 'FenLLM', $apiKey,
            $body['base_url'] ?? null, true);
        if (!$saved) {
            setAppSetting($conn, 'fenllm_provision_status', 'error');
            return [false, $saveErr ?: 'The FenLLM key could not be stored.'];
        }

        $provider = llmProviderByCode($conn, 'fenllm');
        $modelCode = trim((string)($body['model'] ?? 'basic')) ?: 'basic';
        llmAddModel($conn, (int)$provider['id'], $modelCode,
            'FenLLM ' . ucfirst($modelCode), 'chat');
        // Every active plan, not only chatbot-enabled ones: this model is the
        // instance default, and the feature check still applies at call time.
        $granted = llmGrantChatModelsToChatbotPlans($conn, (int)$provider['id'], false);
        // Whatever the signup returned for `model`, the rest of the catalogue
        // (pro, max) is filled in here so a fresh install ships all three and
        // gets FenLLM Max as the transcription default.
        llmEnsureFenLlmCatalogue($conn);

        if (!empty($body['sign_in_url'])) {
            setAppSetting($conn, 'fenllm_sign_in_url', (string)$body['sign_in_url']);
        }
        setAppSetting($conn, 'fenllm_trial_json', json_encode($body['trial'] ?? null));
        setAppSetting($conn, 'fenllm_provision_status', 'done');
        return [true, 'FenLLM trial account created and enabled — granted to '
            . $granted . ' plan' . ($granted === 1 ? '' : 's') . '.'];
    }

    if ($status === 409) {
        // The email already has a FenLLM account. The signup's key is gone
        // forever, so the admin's only route is the magic sign-in link — store
        // it and say so, and never POST this again.
        if (is_array($body) && !empty($body['sign_in_url'])) {
            setAppSetting($conn, 'fenllm_sign_in_url', (string)$body['sign_in_url']);
        }
        setAppSetting($conn, 'fenllm_provision_status', 'account_exists');
        $signIn = is_array($body) ? ($body['sign_in_url'] ?? '') : '';
        return [false, 'A FenLLM account already exists for ' . $email
            . '. Sign in at ' . ($signIn !== '' ? $signIn : 'https://app.fenllm.com')
            . ' and paste its API key into the FenLLM card below.'];
    }

    setAppSetting($conn, 'fenllm_provision_status', 'error');
    return [false, llmFenLlmErrorMessage($status, $body)];
}

// Brings an already-provisioned instance's FenLLM model rows up to the full
// catalogue. Provisioning originally seeded only the one model the signup
// response named, so instances installed before pro/max existed never got
// them; this runs on every boot and is a no-op once they are there.
//
// Returns [addedCount, transcribeDefaultSet]. Only newly added chat models are
// granted — to every active plan, like the provisioned model — because an
// admin may have un-granted an existing one deliberately and re-granting would
// undo that. Existing rows are never written through llmAddModel either: its
// ON DUPLICATE KEY clause would overwrite an admin-edited label.
function llmEnsureFenLlmCatalogue(mysqli $conn) {
    $provider = llmProviderByCode($conn, 'fenllm');
    if (!$provider || !llmProviderHasKey($provider)) return [0, false];
    $providerId = (int)$provider['id'];

    $stmt = $conn->prepare("SELECT id, model_code FROM llm_models WHERE provider_id = ?");
    $stmt->bind_param('i', $providerId);
    $stmt->execute();
    $existing = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $existing[$row['model_code']] = (int)$row['id'];
    $stmt->close();

    $added = 0;
    $newChatIds = [];
    foreach (llmProviderCatalogue()['fenllm']['models'] as [$modelCode, $label, $kind]) {
        if (isset($existing[$modelCode])) continue;
        llmAddModel($conn, $providerId, $modelCode, $label, $kind);
        $added++;

        // insert_id is not trustworthy through ON DUPLICATE KEY UPDATE — look
        // the row up instead.
        $stmt = $conn->prepare("SELECT id FROM llm_models WHERE provider_id = ? AND model_code = ?");
        $stmt->bind_param('is', $providerId, $modelCode);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $newId = $row ? (int)$row['id'] : null;
        if ($newId !== null) {
            $existing[$modelCode] = $newId;
            if ($kind === 'chat') $newChatIds[] = $newId;
        }
    }

    if ($newChatIds) {
        $grant = $conn->prepare("INSERT IGNORE INTO plan_llm_models (plan_id, model_id) VALUES (?, ?)");
        foreach (getActivePlans($conn) as $plan) {
            $planId = (int)$plan['id'];
            foreach ($newChatIds as $modelId) {
                $grant->bind_param('ii', $planId, $modelId);
                $grant->execute();
            }
        }
        $grant->close();
    }

    // The transcription default is applied exactly once per instance: the
    // owner wants FenLLM Max as the shipped default, but the flag keeps a
    // later boot from stomping a choice the admin has since made.
    $defaultSet = false;
    if (overrideSetting($conn, 'fenllm_transcribe_default_applied') !== '1' && isset($existing['max'])) {
        setAppSetting($conn, 'llm_transcribe_model_id', (string)$existing['max']);
        setAppSetting($conn, 'fenllm_transcribe_default_applied', '1');
        $defaultSet = true;
    }

    return [$added, $defaultSet];
}

// --- Transcription ----------------------------------------------------------

// Voice notes are common on WhatsApp, so a bot that ignores them looks broken.
// Two providers can transcribe: OpenAI via its dedicated multipart endpoint,
// and FenLLM via chat completions with an input_audio part (its gateway serves
// no /audio/transcriptions). The admin picks the model.
function llmTranscribe(array $auth, $audioBytes, $filename = 'audio.ogg') {
    switch ($auth['provider'] ?? '') {
        case 'openai': return llmTranscribeOpenAi($auth, $audioBytes, $filename);
        case 'fenllm': return llmTranscribeFenLlm($auth, $audioBytes, $filename);
        default:
            return ['ok' => false, 'error' => 'Transcription needs an OpenAI-compatible provider', 'text' => ''];
    }
}

// The request body for a FenLLM transcription: an ordinary chat completion
// whose user message carries the audio as a base64 input_audio part. $model is
// the model_code (transcription needs a model with the 'audio' capability —
// today that is max).
function llmFenLlmTranscribePayload($model, $audioBytes, $filename) {
    // FenLLM accepts mp3 and wav only — no ogg. Callers must convert first
    // (waTranscodeAudioForTranscription); anything else gets null and the
    // caller refuses rather than burning a request that the gateway rejects.
    $ext = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
    $format = in_array($ext, ['mp3', 'wav'], true) ? $ext : null;
    if ($format === null) return null;

    return [
        'model' => $model,
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'Transcribe this voice message verbatim, in its original language. Reply with the transcript only — no preamble, no quotes, no notes.'],
                ['type' => 'input_audio', 'input_audio' => ['data' => base64_encode($audioBytes), 'format' => $format]],
            ],
        ]],
        'max_tokens' => 1000,
    ];
}

function llmTranscribeFenLlm(array $auth, $audioBytes, $filename) {
    $payload = llmFenLlmTranscribePayload($auth['model'], $audioBytes, $filename);
    if ($payload === null) {
        return ['ok' => false, 'text' => '',
                'error' => 'FenLLM transcription needs mp3 or wav audio — the voice note was not converted.'];
    }
    $base = $auth['base_url'] ?: llmProviderCatalogue()['fenllm']['base_url'];
    [$status, $body, $err] = llmHttpJson(rtrim($base, '/') . '/chat/completions',
        ['Authorization: Bearer ' . $auth['key']],
        $payload,
        120);

    if ($err) return ['ok' => false, 'error' => llmScrubSecret($err, $auth['key']), 'text' => ''];
    if ($status !== 200) {
        return ['ok' => false, 'text' => '',
                'error' => llmScrubSecret(llmFenLlmErrorMessage($status, $body), $auth['key'])];
    }

    $text = trim((string)($body['choices'][0]['message']['content'] ?? ''));
    return ['ok' => $text !== '', 'text' => $text, 'error' => $text === '' ? 'Empty transcription' : null];
}

function llmTranscribeOpenAi(array $auth, $audioBytes, $filename) {
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
