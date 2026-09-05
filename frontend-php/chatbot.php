<?php
// Tenant chatbot configuration.
//
// Gated on the plan's `chatbot` feature. The tenant's own API key, if they use
// one, is encrypted with the same secretbox as every other stored secret and is
// never rendered back into the form.
require_once __DIR__ . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$plan = getUserPlan($conn, $userId);
$hasChatbot = planHasFeature($plan, 'chatbot');
$canByo = llmAllowByoKeys($conn) && planHasFeature($plan, 'llm_byok');
$audioAvailable = llmAudioEnabled($conn) && llmTranscribeModelId($conn);

$config = chatbotConfig($conn, $userId);
$availableModels = llmModelsForPlan($conn, (int)$plan['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasChatbot) {
    if (!verifyCsrf()) {
        flash('error', 'Invalid request.');
        redirect(APP_URL . '/chatbot.php');
    }

    $action = $_POST['action'] ?? 'save';

    if ($action === 'clear_key') {
        chatbotClearByoKey($conn, $userId);
        flash('success', 'Your API key has been removed.');
        redirect(APP_URL . '/chatbot.php');
    }

    $input = $_POST;

    // A tenant may only select a model their plan was granted. Without this a
    // crafted POST could point at any model id in the instance — the reply path
    // re-checks too, but a rejected id should never be stored in the first place.
    $chosen = ($input['model_id'] ?? '') === '' ? null : (int)$input['model_id'];
    if ($chosen !== null && !in_array($chosen, array_map('intval', array_column($availableModels, 'id')), true)) {
        flash('error', 'That model is not available on your plan.');
        redirect(APP_URL . '/chatbot.php');
    }

    if (!$canByo) {
        // Ignore any BYO fields that arrive when the option is not open to them.
        $input['byo_provider_code'] = null;
        $input['byo_model_code'] = null;
    }
    if (!$audioAvailable) {
        $input['transcribe_audio'] = 0;
    }

    // Turning the bot on without something to answer with would produce a
    // silent bot and a confused tenant.
    $usingByo = $canByo && !empty($input['byo_provider_code']);
    if (!empty($input['is_enabled']) && $chosen === null && !$usingByo) {
        flash('error', 'Choose a model before switching the chatbot on.');
        redirect(APP_URL . '/chatbot.php');
    }

    chatbotSaveConfig($conn, $userId, $input);

    if ($usingByo) {
        [$ok, $err] = chatbotSaveByoKey($conn, $userId, $_POST['byo_api_key'] ?? '');
        if (!$ok) {
            flash('error', $err);
            redirect(APP_URL . '/chatbot.php');
        }
        $fresh = chatbotConfig($conn, $userId);
        if (!empty($input['is_enabled']) && empty($fresh['byo_api_key_encrypted'])) {
            flash('error', 'Enter your API key before switching the chatbot on.');
            redirect(APP_URL . '/chatbot.php');
        }
    }

    logAudit($conn, 'chatbot.config_saved', 'chatbot_config', (string)$userId, [
        'enabled' => !empty($input['is_enabled']),
        'byo' => $usingByo,
    ]);
    flash('success', 'Chatbot settings saved.');
    redirect(APP_URL . '/chatbot.php');
}

$hasByoKey = !empty($config['byo_api_key_encrypted']);
$events = $hasChatbot ? chatbotRecentEvents($conn, $userId, 15) : [];
$repliesThisMonth = usageCount($conn, $userId, 'chatbot_replies');
$tz = getUserTimezone($conn, $userId);

$pageTitle = 'Chatbot';
require_once __DIR__ . '/includes/header.php';
?>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= sanitize($msg) ?></div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
    <div class="alert alert-danger"><?= sanitize($msg) ?></div>
<?php endif; ?>

<?php if (!$hasChatbot): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="bi bi-robot d-block"></i>
                <h5>The AI chatbot is not part of your plan</h5>
                <p>Your current plan (<?= sanitize($plan['name'] ?? 'none') ?>) does not include the chatbot.</p>
                <a href="<?= APP_URL ?>/billing.php" class="btn btn-primary btn-sm">See plans</a>
            </div>
        </div>
    </div>
<?php else: ?>

<div class="row g-3">
    <div class="col-lg-8">
        <form method="post" id="chatbotForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">

            <div class="card mb-3">
                <div class="card-body">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_enabled" value="1" id="is_enabled"
                            <?= $config['is_enabled'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-500" for="is_enabled">
                            Reply automatically with AI
                        </label>
                    </div>
                    <div class="form-text">
                        <i class="bi bi-shield-check me-1"></i>
                        The bot only ever answers <strong>one-to-one chats</strong>. It never replies in
                        group chats, never in archived chats, and never to your own number.
                    </div>
                </div>
            </div>

            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-kb" type="button">Knowledge base</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-model" type="button">Model</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-behaviour" type="button">Behaviour</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-test" type="button">Test</button></li>
            </ul>

            <div class="tab-content card border-top-0">
                <div class="tab-pane fade show active p-3" id="tab-kb">
                    <label class="form-label">What the bot knows about your business</label>
                    <textarea name="knowledge_base" class="form-control" rows="14"
                              placeholder="Opening hours, services and prices, address, delivery areas, refund policy, what to say if someone asks for the owner…"><?= sanitize($config['knowledge_base'] ?? '') ?></textarea>
                    <div class="form-text">
                        The bot is instructed to answer <strong>only</strong> from this text and to say it does not
                        know otherwise. Anything missing here is something it will refuse to answer — which is
                        deliberate: an invented price or policy is worse than no answer.
                    </div>
                </div>

                <div class="tab-pane fade p-3" id="tab-model">
                    <div class="mb-3">
                        <label class="form-label">Model</label>
                        <select name="model_id" class="form-select">
                            <option value="">— none selected —</option>
                            <?php foreach ($availableModels as $m): ?>
                                <option value="<?= (int)$m['id'] ?>" <?= (int)($config['model_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($m['provider_label'] . ' — ' . $m['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$availableModels): ?>
                            <div class="form-text text-warning">
                                No models are available on your plan yet. Contact the administrator.
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($canByo): ?>
                        <hr>
                        <h6 class="small text-uppercase text-muted">Use your own API key</h6>
                        <p class="form-text mt-0">
                            Optional. When set, your key is used instead of the platform's and billing for
                            model usage is yours. Your key is encrypted and never shown again.
                        </p>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label small">Provider</label>
                                <select name="byo_provider_code" class="form-select form-select-sm">
                                    <option value="">— not using my own key —</option>
                                    <?php foreach (llmProviderCatalogue() as $code => $meta): ?>
                                        <option value="<?= sanitize($code) ?>" <?= ($config['byo_provider_code'] ?? '') === $code ? 'selected' : '' ?>>
                                            <?= sanitize($meta['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">Model id</label>
                                <input type="text" name="byo_model_code" class="form-control form-control-sm"
                                       value="<?= sanitize($config['byo_model_code'] ?? '') ?>" placeholder="gpt-4o-mini">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">API key</label>
                                <input type="password" name="byo_api_key" class="form-control form-control-sm"
                                       autocomplete="new-password"
                                       placeholder="<?= $hasByoKey ? 'Stored — leave blank to keep' : 'sk-…' ?>">
                            </div>
                        </div>
                        <?php if ($hasByoKey): ?>
                            <button class="btn btn-outline-danger btn-sm mt-2" type="submit" name="action" value="clear_key">
                                Remove my key
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="tab-pane fade p-3" id="tab-behaviour">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Tone</label>
                            <select name="tone" class="form-select">
                                <?php foreach (chatbotToneChoices() as $key => $label): ?>
                                    <option value="<?= sanitize($key) ?>" <?= ($config['tone'] ?? '') === $key ? 'selected' : '' ?>>
                                        <?= sanitize($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Reply length cap</label>
                            <input type="number" name="max_tokens" class="form-control" min="64" max="2000"
                                   value="<?= (int)($config['max_tokens'] ?? 400) ?>">
                            <div class="form-text">Tokens. ~400 is a short WhatsApp reply.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Context messages</label>
                            <input type="number" name="history_messages" class="form-control" min="0" max="30"
                                   value="<?= (int)($config['history_messages'] ?? 10) ?>">
                            <div class="form-text">Earlier turns sent with each reply.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">If the bot cannot answer</label>
                            <input type="text" name="fallback_message" class="form-control"
                                   value="<?= sanitize($config['fallback_message'] ?? '') ?>"
                                   placeholder="Sorry, I can't answer that right now. Someone will get back to you shortly.">
                            <div class="form-text">
                                Sent when the model fails or is misconfigured. The customer never sees a technical error.
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Active from</label>
                            <input type="time" name="active_hours_start" class="form-control"
                                   value="<?= sanitize($config['active_hours_start'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Active until</label>
                            <input type="time" name="active_hours_end" class="form-control"
                                   value="<?= sanitize($config['active_hours_end'] ?? '') ?>">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-text mb-2">
                                Your timezone: <strong><?= sanitize($tz) ?></strong>. Leave both blank for 24/7.
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Outside those hours, send</label>
                            <input type="text" name="outside_hours_message" class="form-control"
                                   value="<?= sanitize($config['outside_hours_message'] ?? '') ?>"
                                   placeholder="Leave blank to stay silent outside hours">
                        </div>

                        <?php if ($audioAvailable): ?>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="transcribe_audio" value="1"
                                       id="transcribe" <?= $config['transcribe_audio'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="transcribe">Understand voice notes</label>
                            </div>
                            <div class="form-text">Incoming voice notes are transcribed, then answered as text.</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="tab-pane fade p-3" id="tab-test">
                    <p class="text-muted small">
                        Runs the real pipeline with your saved settings — same prompt, same model, same limits —
                        but sends nothing to WhatsApp. Save your changes first.
                    </p>
                    <div class="input-group">
                        <input type="text" class="form-control" id="testMessage"
                               placeholder="Ask what a customer would ask…">
                        <button class="btn btn-primary" type="button" onclick="runChatbotTest()">Test</button>
                    </div>
                    <div id="testOutput" class="mt-3"></div>
                </div>
            </div>

            <div class="mt-3">
                <button class="btn btn-primary" type="submit">Save settings</button>
            </div>
        </form>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header">This month</div>
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <span class="text-muted small">AI replies sent</span>
                    <span class="fw-600"><?= number_format($repliesThisMonth) ?></span>
                </div>
                <div class="form-text">
                    Each AI reply is a message and counts towards your monthly message limit.
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Recent activity</div>
            <div class="card-body p-0">
                <?php if (!$events): ?>
                    <div class="p-3 text-muted small">Nothing yet.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($events as $e): ?>
                            <li class="list-group-item py-2">
                                <div class="d-flex justify-content-between align-items-start">
                                    <span class="small"><?= sanitize(chatbotOutcomeLabel($e['outcome'])) ?></span>
                                    <span class="x-small text-muted"><?= sanitize(convertToUserTz($e['created_at'], $tz)) ?></span>
                                </div>
                                <?php if (!empty($e['detail'])): ?>
                                    <div class="x-small text-muted"><?= sanitize($e['detail']) ?></div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    function runChatbotTest() {
        const input = document.getElementById('testMessage');
        const out = document.getElementById('testOutput');
        const text = input.value.trim();
        if (!text) return;

        out.innerHTML = '<div class="text-muted small"><span class="spinner-border spinner-border-sm me-2"></span>Asking the model…</div>';

        fetch('<?= APP_URL ?>/ajax/chatbot-test.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: '<?= csrfToken() ?>', message: text })
        })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) {
                    out.innerHTML = '<div class="alert alert-danger py-2 small mb-0">' + escapeHtml(data.error || 'Test failed') + '</div>';
                    return;
                }
                out.innerHTML =
                    '<div class="chat-bubble incoming d-inline-block p-2 mb-2"><div class="bubble-content">'
                    + formatMessageText(data.reply) + '</div></div>'
                    + '<div class="x-small text-muted">' + escapeHtml(data.model || '') + ' · '
                    + escapeHtml(String(data.latency_ms || 0)) + ' ms · '
                    + escapeHtml(String(data.tokens || 0)) + ' tokens</div>';
            })
            .catch(() => {
                out.innerHTML = '<div class="alert alert-danger py-2 small mb-0">Test failed</div>';
            });
    }
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
