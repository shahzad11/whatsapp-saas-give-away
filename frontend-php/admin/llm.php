<?php
// LLM providers, the model catalogue, and which plans may use which model.
//
// Provider API keys are encrypted at rest (libsodium secretbox, see
// includes/crypto.php) and are NEVER rendered back into the form — the field
// shows a placeholder when a key is stored, and submitting it blank keeps the
// stored value. Nothing on this page, including a failed connection test, can
// print a key: llmScrubSecret() runs over every vendor error before it is shown.
require_once dirname(__DIR__) . '/includes/admin-init.php';

$catalogue = llmProviderCatalogue();
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('error', 'Invalid request.');
        redirect(APP_URL . '/admin/llm.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_provider') {
        $code = (string)($_POST['code'] ?? '');
        if (!llmIsKnownProvider($code)) {
            flash('error', 'Unknown provider.');
            redirect(APP_URL . '/admin/llm.php');
        }
        $key = (string)($_POST['api_key'] ?? '');
        if ($key !== '' && !cryptoSecretAvailable()) {
            flash('error', 'Cannot encrypt the API key: no instance secret is available. Set APP_SECRET_KEY.');
            redirect(APP_URL . '/admin/llm.php');
        }

        [$ok, $err] = llmSaveProvider(
            $conn, $code,
            $catalogue[$code]['label'],
            $key,
            $_POST['base_url'] ?? '',
            !empty($_POST['is_enabled'])
        );
        if (!$ok) {
            flash('error', $err);
            redirect(APP_URL . '/admin/llm.php');
        }

        // Seed the vendor's known models the first time a provider is saved, so
        // the admin has something to grant rather than a blank list.
        $provider = llmProviderByCode($conn, $code);
        $existing = llmModels($conn, null, false);
        $hasAny = false;
        foreach ($existing as $m) if ($m['provider_code'] === $code) { $hasAny = true; break; }
        $granted = 0;
        if (!$hasAny) {
            foreach ($catalogue[$code]['models'] as [$modelCode, $label, $kind]) {
                llmAddModel($conn, (int)$provider['id'], $modelCode, $label, $kind);
            }
            // Seeding the catalogue alone left the models granted to no plan,
            // so tenants still saw "No models are available on your plan yet"
            // and the fix was a checkbox matrix most admins never found. The
            // grant only ever adds, and only to plans that already have the
            // chatbot feature on — so it cannot hand the feature to a plan the
            // admin has not opted in.
            $granted = llmGrantChatModelsToChatbotPlans($conn, (int)$provider['id']);
        }

        // Never log the key itself — only that a key was replaced.
        logAudit($conn, 'llm.provider_saved', 'llm_provider', $code, [
            'enabled' => !empty($_POST['is_enabled']),
            'key_replaced' => $key !== '',
            'models_granted' => $granted,
        ]);
        $message = $catalogue[$code]['label'] . ' saved.';
        if ($granted > 0) {
            $message .= ' Its chat models are now available to every active plan with the AI chatbot feature on.';
        }
        // A provider saved as enabled with no key is inert: no Test button
        // appears and tenants see no models, with nothing on screen saying why.
        if (!empty($_POST['is_enabled']) && !llmProviderHasKey(llmProviderByCode($conn, $code))) {
            flash('error', 'This provider is enabled but has no API key — tenants cannot use it until you add one.');
        }
        flash('success', $message);
        redirect(APP_URL . '/admin/llm.php');
    }

    if ($action === 'test_provider') {
        $code = (string)($_POST['code'] ?? '');
        if (llmIsKnownProvider($code)) {
            // Pass the form's own values so the test reports on what the admin is
            // looking at, not on the stored row.
            [$ok, $message] = llmTestProvider($conn, $code, $_POST['api_key'] ?? null, $_POST['base_url'] ?? null);
            logAudit($conn, 'llm.provider_tested', 'llm_provider', $code, ['ok' => $ok]);
            flash($ok ? 'success' : 'error', $catalogue[$code]['label'] . ': ' . $message);
        }
        redirect(APP_URL . '/admin/llm.php');
    }

    if ($action === 'add_model') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $modelCode = trim((string)($_POST['model_code'] ?? ''));
        $label = trim((string)($_POST['model_label'] ?? '')) ?: $modelCode;
        $kind = ($_POST['model_kind'] ?? 'chat') === 'transcribe' ? 'transcribe' : 'chat';

        if ($providerId > 0 && $modelCode !== '' && strlen($modelCode) <= 100) {
            llmAddModel($conn, $providerId, $modelCode, $label, $kind);
            logAudit($conn, 'llm.model_added', 'llm_model', $modelCode, ['kind' => $kind]);
            flash('success', 'Model added.');
        } else {
            flash('error', 'Enter the model id exactly as the vendor documents it.');
        }
        redirect(APP_URL . '/admin/llm.php');
    }

    if ($action === 'toggle_model') {
        $modelId = (int)($_POST['model_id'] ?? 0);
        $enable = !empty($_POST['enable']);
        llmSetModelEnabled($conn, $modelId, $enable);
        // Every other action on this page logs. Disabling a model changes what
        // tenants can select and what the reply path will accept, so it belongs
        // in the audit trail just as much as adding or deleting one.
        logAudit($conn, 'llm.model_toggled', 'llm_model', (string)$modelId, ['enabled' => $enable]);
        redirect(APP_URL . '/admin/llm.php');
    }

    if ($action === 'delete_model') {
        // Deleting only removes it from the menu: chatbot_configs.model_id is
        // ON DELETE SET NULL, so a tenant using it falls back to "not
        // configured" rather than to someone else's model.
        llmDeleteModel($conn, (int)($_POST['model_id'] ?? 0));
        logAudit($conn, 'llm.model_deleted', 'llm_model', (string)($_POST['model_id'] ?? ''));
        flash('success', 'Model removed. Tenants using it will need to pick another.');
        redirect(APP_URL . '/admin/llm.php');
    }

    if ($action === 'save_access') {
        foreach (getActivePlans($conn) as $plan) {
            $selected = $_POST['plan_models'][$plan['id']] ?? [];
            llmSetPlanModels($conn, (int)$plan['id'], is_array($selected) ? $selected : []);
        }
        logAudit($conn, 'llm.plan_access_saved', 'plan_llm_models', null);
        flash('success', 'Plan access updated.');
        redirect(APP_URL . '/admin/llm.php');
    }

    if ($action === 'save_toggles') {
        setAppSetting($conn, 'llm_allow_byo_keys', !empty($_POST['llm_allow_byo_keys']) ? '1' : '0');
        setAppSetting($conn, 'llm_enable_audio', !empty($_POST['llm_enable_audio']) ? '1' : '0');
        setAppSetting($conn, 'llm_transcribe_model_id', (string)(int)($_POST['llm_transcribe_model_id'] ?? 0) ?: '');
        logAudit($conn, 'llm.toggles_saved', 'app_settings', null, [
            'byo' => !empty($_POST['llm_allow_byo_keys']),
            'audio' => !empty($_POST['llm_enable_audio']),
        ]);
        flash('success', 'Instance toggles updated.');
        redirect(APP_URL . '/admin/llm.php');
    }
}

$providers = [];
foreach ($catalogue as $code => $meta) {
    $row = llmProviderByCode($conn, $code);
    $providers[$code] = [
        'meta' => $meta,
        'row' => $row,
        'has_key' => $row ? llmProviderHasKey($row) : false,
    ];
}

$chatModels = llmModels($conn, 'chat', false);
$transcribeModels = llmModels($conn, 'transcribe', false);
$plans = getActivePlans($conn);
$planAccess = [];
foreach ($plans as $plan) {
    $planAccess[$plan['id']] = llmPlanModelIds($conn, (int)$plan['id']);
}

$pageTitle = 'AI / LLM';
require_once dirname(__DIR__) . '/includes/admin-header.php';
?>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= sanitize($msg) ?></div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
    <div class="alert alert-danger"><?= sanitize($msg) ?></div>
<?php endif; ?>

<?php if (!cryptoSecretAvailable()): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        No instance secret is available, so API keys cannot be encrypted and therefore cannot be
        saved. Set <code>APP_SECRET_KEY</code> in the environment first.
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-plug me-2"></i>Providers</div>
            <div class="card-body">
                <p class="text-muted small">
                    Keys are encrypted at rest and never displayed again. Leave the key field blank to
                    keep the stored one. A provider with no key cannot be selected by any tenant.
                </p>

                <?php foreach ($providers as $code => $p): ?>
                <form method="post" class="border rounded p-3 mb-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_provider">
                    <input type="hidden" name="code" value="<?= sanitize($code) ?>">

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0"><?= sanitize($p['meta']['label']) ?></h6>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($p['row'] && $p['row']['last_test_ok'] !== null): ?>
                                <span class="badge bg-<?= $p['row']['last_test_ok'] ? 'success' : 'danger' ?>">
                                    <?= $p['row']['last_test_ok'] ? 'Test passed' : 'Test failed' ?>
                                </span>
                            <?php endif; ?>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="is_enabled" value="1"
                                       id="en-<?= sanitize($code) ?>"
                                    <?= ($p['row']['is_enabled'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="en-<?= sanitize($code) ?>">Enabled</label>
                            </div>
                        </div>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small">API key</label>
                            <input type="password" name="api_key" class="form-control form-control-sm"
                                   autocomplete="new-password"
                                   placeholder="<?= $p['has_key'] ? 'Stored — leave blank to keep' : sanitize($p['meta']['key_hint']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Base URL <span class="text-muted">(optional)</span></label>
                            <input type="text" name="base_url" class="form-control form-control-sm"
                                   value="<?= sanitize($p['row']['base_url'] ?? '') ?>"
                                   placeholder="<?= sanitize($p['meta']['base_url']) ?>">
                        </div>
                    </div>

                    <?php if (!empty($p['row']['last_test_error'])): ?>
                        <div class="small text-danger mt-2"><?= sanitize($p['row']['last_test_error']) ?></div>
                    <?php endif; ?>

                    <div class="d-flex gap-2 mt-3">
                        <button class="btn btn-primary btn-sm" type="submit">Save</button>
                        <?php if ($p['has_key']): ?>
                            <button class="btn btn-outline-secondary btn-sm" type="submit"
                                    name="action" value="test_provider">Test connection</button>
                        <?php endif; ?>
                    </div>
                </form>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-cpu me-2"></i>Models</div>
            <div class="card-body">
                <p class="text-muted small">
                    Models are listed explicitly, never fetched from the vendor: a new and expensive
                    model must never become selectable on its own.
                </p>

                <table class="table table-sm align-middle">
                    <thead><tr><th>Provider</th><th>Model</th><th>Kind</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach (array_merge($chatModels, $transcribeModels) as $m): ?>
                        <tr class="<?= $m['is_enabled'] ? '' : 'opacity-50' ?>">
                            <td class="small"><?= sanitize($m['provider_label']) ?></td>
                            <td>
                                <div class="small fw-500"><?= sanitize($m['label']) ?></div>
                                <code class="x-small text-muted"><?= sanitize($m['model_code']) ?></code>
                            </td>
                            <td><span class="badge bg-light text-dark"><?= sanitize($m['kind']) ?></span></td>
                            <td class="text-end">
                                <form method="post" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_model">
                                    <input type="hidden" name="model_id" value="<?= (int)$m['id'] ?>">
                                    <input type="hidden" name="enable" value="<?= $m['is_enabled'] ? '0' : '1' ?>">
                                    <button class="btn btn-outline-secondary btn-sm" type="submit">
                                        <?= $m['is_enabled'] ? 'Disable' : 'Enable' ?>
                                    </button>
                                </form>
                                <form method="post" class="d-inline"
                                      onsubmit="return confirm('Remove this model? Tenants using it will have to pick another.')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_model">
                                    <input type="hidden" name="model_id" value="<?= (int)$m['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm" type="submit">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$chatModels && !$transcribeModels): ?>
                        <tr><td colspan="4" class="text-muted small">Save a provider above to seed its known models.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>

                <form method="post" class="row g-2 align-items-end border-top pt-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_model">
                    <div class="col-md-3">
                        <label class="form-label small">Provider</label>
                        <select name="provider_id" class="form-select form-select-sm" required>
                            <?php foreach ($providers as $code => $p): if (!$p['row']) continue; ?>
                                <option value="<?= (int)$p['row']['id'] ?>"><?= sanitize($p['meta']['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Model id</label>
                        <input type="text" name="model_code" class="form-control form-control-sm"
                               placeholder="gpt-4o-mini" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Label</label>
                        <input type="text" name="model_label" class="form-control form-control-sm" placeholder="GPT-4o mini">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Kind</label>
                        <select name="model_kind" class="form-select form-select-sm">
                            <option value="chat">chat</option>
                            <option value="transcribe">transcribe</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button class="btn btn-primary btn-sm w-100" type="submit">Add</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-toggles me-2"></i>Instance toggles</div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_toggles">

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="llm_allow_byo_keys" value="1"
                               id="byo" <?= llmAllowByoKeys($conn) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="byo">Allow tenants to use their own API key</label>
                        <div class="form-text">
                            A tenant still needs the <strong>Bring your own LLM key</strong> plan feature.
                            Their key is encrypted and never shown back to them either.
                        </div>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="llm_enable_audio" value="1"
                               id="audio" <?= llmAudioEnabled($conn) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="audio">Transcribe incoming voice notes</label>
                        <div class="form-text">
                            Off by default: every voice note becomes a paid transcription request.
                            Tenants must also switch it on for their own bot.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Transcription model</label>
                        <select name="llm_transcribe_model_id" class="form-select form-select-sm">
                            <option value="">— none —</option>
                            <?php foreach ($transcribeModels as $m): ?>
                                <option value="<?= (int)$m['id'] ?>"
                                    <?= llmTranscribeModelId($conn) === (int)$m['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($m['provider_label'] . ' — ' . $m['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Paid for by the platform, so it is one instance-wide choice.</div>
                    </div>

                    <button class="btn btn-primary btn-sm" type="submit">Save toggles</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Which plans get which model</div>
            <div class="card-body">
                <p class="text-muted small">
                    No row means no access. A model a plan has not been granted cannot be selected —
                    and is re-checked when a reply is generated, so a downgrade takes effect at once.
                </p>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_access">
                    <?php foreach ($plans as $plan): ?>
                        <div class="mb-3">
                            <div class="fw-500 small mb-1">
                                <?= sanitize($plan['name']) ?>
                                <?php if (!planHasFeature($plan, 'chatbot')): ?>
                                    <span class="badge bg-warning text-dark ms-1">chatbot feature off</span>
                                <?php endif; ?>
                            </div>
                            <?php foreach ($chatModels as $m): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           name="plan_models[<?= (int)$plan['id'] ?>][]" value="<?= (int)$m['id'] ?>"
                                           id="pm-<?= (int)$plan['id'] ?>-<?= (int)$m['id'] ?>"
                                        <?= in_array((int)$m['id'], $planAccess[$plan['id']], true) ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="pm-<?= (int)$plan['id'] ?>-<?= (int)$m['id'] ?>">
                                        <?= sanitize($m['provider_label'] . ' — ' . $m['label']) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$chatModels): ?>
                                <div class="text-muted small">No chat models yet.</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <button class="btn btn-primary btn-sm" type="submit">Save access</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
