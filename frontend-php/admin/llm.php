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

$self = APP_URL . '/admin/llm.php';

// The FenLLM trial balance block, rendered from the stored state alone — the
// page never calls the vendor while drawing. It is a function rather than
// inline markup because the live-refresh endpoint answers with the same HTML:
// the JS swaps the whole #fenllm-balance element for whatever this returns.
// The hidden status span is the JS's scratch space for "checking…" / failure.
function fenllmBalancePanelHtml(array $state, ?string $signIn): string {
    $balance = $state['balance'] ?? null;
    $fetchedAt = $state['fetched_at'] ?? null;
    $error = $state['error'] ?? null;
    $errorAt = $state['error_at'] ?? null;
    // An error older than the last good fetch is history, not a problem.
    $errorCurrent = $error !== null && ($fetchedAt === null || $errorAt >= $fetchedAt);
    $rel = function ($ts) { return $ts ? timeAgo('@' . (int)$ts) : ''; };

    ob_start();
    ?>
    <div id="fenllm-balance">
        <?php if ($balance !== null): ?>
            <?php
            $fenllmTrial = $balance['trial'] ?? null;
            $canCall = !empty($balance['can_make_calls']);
            if (is_array($fenllmTrial)) {
                if (!empty($fenllmTrial['exhausted']))     $trialText = 'trial credit used up';
                elseif (!empty($fenllmTrial['expired']))   $trialText = 'trial expired';
                elseif (!empty($fenllmTrial['active']))    $trialText = 'trial active';
                else                                       $trialText = 'trial ended';
            } else {
                $trialText = null;
            }
            ?>
            <div class="d-flex align-items-center gap-2 flex-wrap small">
                <span class="badge bg-<?= $canCall ? 'success' : 'danger' ?>">
                    Balance <?= sanitize($balance['balance'] ?? 'unknown') ?>
                </span>
                <?php if ($trialText !== null): ?>
                    <span class="badge bg-<?= $canCall ? 'light text-dark' : 'danger' ?>">
                        <?= sanitize($trialText) ?>
                        <?= is_array($fenllmTrial) && isset($fenllmTrial['remaining']) ? ' — ' . sanitize($fenllmTrial['remaining']) . ' left' : '' ?>
                    </span>
                <?php endif; ?>
                <?php if ($signIn): ?>
                    <a href="<?= sanitize($signIn) ?>" target="_blank" rel="noopener" class="small">
                        Manage account / add card
                    </a>
                <?php endif; ?>
            </div>
            <?php if ($fetchedAt !== null): ?>
                <div class="small text-muted mt-1">Updated <?= sanitize($rel($fetchedAt)) ?></div>
            <?php endif; ?>
            <?php if ($errorCurrent): ?>
                <div class="small text-warning mt-1">
                    Couldn't refresh — showing the value from <?= sanitize($rel($fetchedAt)) ?>.
                    <?= sanitize($error) ?>
                </div>
            <?php endif; ?>
            <?php if (!$canCall): ?>
                <div class="alert alert-warning small mt-2 mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    This account cannot make calls — the trial credit is spent or expired.
                    <?php if ($signIn): ?>
                        <a href="<?= sanitize($signIn) ?>" target="_blank" rel="noopener">Sign in to add credit</a>,
                    <?php endif; ?>
                    or add a key from another provider below.
                </div>
            <?php endif; ?>
        <?php elseif ($errorCurrent): ?>
            <div class="small text-warning"><?= sanitize($error) ?></div>
        <?php else: ?>
            <div class="small text-muted">Checking balance…</div>
        <?php endif; ?>
        <span class="fenllm-live-status small text-muted d-none"><span class="spinner-border spinner-border-sm me-1"></span>Checking live balance…</span>
    </div>
    <?php
    return ob_get_clean();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    formRequireCsrf($self);

    $action = $_POST['action'] ?? '';

    if ($action === 'save_provider') {
        $code = (string)($_POST['code'] ?? '');
        if (!llmIsKnownProvider($code)) {
            formRespond(false, 'Unknown provider.', $self);
        }
        $key = (string)($_POST['api_key'] ?? '');
        if ($key !== '' && !cryptoSecretAvailable()) {
            // Attached to the field that was refused, so the AJAX path highlights
            // the key box rather than only explaining the instance's problem.
            formRespond(false, cryptoSecretMissingMessage(), $self,
                ['api_key' => 'This instance cannot encrypt secrets, so the key was not stored.']);
        }

        [$ok, $err] = llmSaveProvider(
            $conn, $code,
            $catalogue[$code]['label'],
            $key,
            $_POST['base_url'] ?? '',
            !empty($_POST['is_enabled'])
        );
        if (!$ok) {
            // llmSaveProvider() only ever fails on encrypting the key, so that is
            // the field to mark; the returned message is the one to show.
            formRespond(false, $err, $self, ['api_key' => 'The key was not saved.']);
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
        //
        // Deliberately still a flash() rather than part of the formRespond()
        // message: the save did succeed, and this is a second, differently
        // coloured warning that formRespond() has no room for. flash() keeps
        // 'error' and 'success' under separate keys, so it survives to be shown
        // by the redirect a plain submit follows and by the reload forms.js does
        // after a successful AJAX save — the admin sees it either way.
        if (!empty($_POST['is_enabled']) && !llmProviderHasKey(llmProviderByCode($conn, $code))) {
            flash('error', 'This provider is enabled but has no API key — customers cannot use it until you add one.');
        }
        formRespond(true, $message, $self);
    }

    if ($action === 'test_provider') {
        $code = (string)($_POST['code'] ?? '');
        if (!llmIsKnownProvider($code)) {
            formRespond(false, 'Unknown provider.', $self);
        }
        // Pass the form's own values so the test reports on what the admin is
        // looking at, not on the stored row.
        [$ok, $message] = llmTestProvider($conn, $code, $_POST['api_key'] ?? null, $_POST['base_url'] ?? null);
        logAudit($conn, 'llm.provider_tested', 'llm_provider', $code, ['ok' => $ok]);
        // The message carries the whole diagnostic — llmScrubSecret() has already
        // run over it — so a failure needs no page reload to be readable. A pass
        // reloads anyway, which is what refreshes the stored "Test passed" badge.
        formRespond($ok, $catalogue[$code]['label'] . ': ' . $message, $self);
    }

    if ($action === 'add_model') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $modelCode = trim((string)($_POST['model_code'] ?? ''));
        $label = trim((string)($_POST['model_label'] ?? '')) ?: $modelCode;
        $kind = ($_POST['model_kind'] ?? 'chat') === 'transcribe' ? 'transcribe' : 'chat';

        if ($providerId > 0 && $modelCode !== '' && strlen($modelCode) <= 100) {
            llmAddModel($conn, $providerId, $modelCode, $label, $kind);
            logAudit($conn, 'llm.model_added', 'llm_model', $modelCode, ['kind' => $kind]);
            formRespond(true, 'Model added.', $self);
        }
        // One condition, two possible culprits, so the field error is chosen from
        // which of them failed — marking the model id when a provider was never
        // selected would point at the wrong box.
        formRespond(false, 'Enter the model ID exactly as the vendor documents it.', $self,
            $providerId > 0
                ? ['model_code' => 'Required, max 100 characters.']
                : ['provider_id' => 'Choose the provider this model belongs to.']);
    }

    if ($action === 'toggle_model') {
        $modelId = (int)($_POST['model_id'] ?? 0);
        $enable = !empty($_POST['enable']);
        llmSetModelEnabled($conn, $modelId, $enable);
        // Every other action on this page logs. Disabling a model changes what
        // tenants can select and what the reply path will accept, so it belongs
        // in the audit trail just as much as adding or deleting one.
        logAudit($conn, 'llm.model_toggled', 'llm_model', (string)$modelId, ['enabled' => $enable]);
        // This action used to redirect silently, which was survivable when the
        // whole page redrew and the row visibly changed. An AJAX submit has only
        // the message to show for itself, so it now says what it did.
        formRespond(true, $enable ? 'Model enabled.' : 'Model disabled.', $self);
    }

    if ($action === 'delete_model') {
        // Deleting only removes it from the menu: chatbot_configs.model_id is
        // ON DELETE SET NULL, so a tenant using it falls back to "not
        // configured" rather than to someone else's model.
        llmDeleteModel($conn, (int)($_POST['model_id'] ?? 0));
        logAudit($conn, 'llm.model_deleted', 'llm_model', (string)($_POST['model_id'] ?? ''));
        formRespond(true, 'Model removed. Customers using it will need to pick another.', $self);
    }

    if ($action === 'save_access') {
        foreach (getActivePlans($conn) as $plan) {
            $selected = $_POST['plan_models'][$plan['id']] ?? [];
            llmSetPlanModels($conn, (int)$plan['id'], is_array($selected) ? $selected : []);
        }
        logAudit($conn, 'llm.plan_access_saved', 'plan_llm_models', null);
        formRespond(true, 'Plan access updated.', $self);
    }

    if ($action === 'fenllm_refresh_balance') {
        // Forces past the throttle — the admin just did something on the
        // FenLLM dashboard (or wants to see that the trial is really live) and
        // is asking for now, not for the last cached value.
        $state = llmFenLlmBalanceRefresh($conn, true);
        $balance = $state['balance'];
        if ($state['error'] !== null
            && ($state['fetched_at'] === null || $state['error_at'] >= $state['fetched_at'])) {
            formRespond(false, $state['error'], $self);
        }
        $summary = 'Balance ' . ($balance['balance'] ?? 'unknown');
        if (is_array($balance['trial'] ?? null)) {
            $summary .= ' — trial ' . (!empty($balance['trial']['active']) ? 'active' : 'ended')
                . ', ' . ($balance['trial']['remaining'] ?? '0') . ' remaining';
        }
        formRespond(true, 'FenLLM: ' . $summary . '.', $self);
    }

    if ($action === 'fenllm_balance_live') {
        // The background call the page's inline script makes on every open.
        // XHR only — a plain POST has no #fenllm-balance to swap, so it gets
        // the ordinary form answer instead of a fragment it cannot use.
        // Not audit-logged: it runs on every page load and would drown the log.
        if (!isXhrRequest()) {
            formRespond(false, 'This action is only available to the page itself.', $self);
        }
        // Released before the vendor call, so a slow FenLLM cannot hold the
        // admin's other requests behind this session's file lock.
        session_write_close();
        $state = llmFenLlmBalanceRefresh($conn, false);
        $signIn = llmFenLlmSignInUrl($conn) ?? ($state['balance']['sign_in_url'] ?? null);
        jsonOut(['ok' => true, 'html' => fenllmBalancePanelHtml($state, $signIn)]);
    }

    if ($action === 'fenllm_signup') {
        // The manual version of what bootstrap does at first boot — for an
        // install that had no partner secret, or one created before this
        // existed. The account is opened for the logged-in admin's email,
        // which is also the email the magic sign-in link is issued to.
        $secret = env('FENLLM_PARTNER_SECRET');
        if ($secret === null || $secret === '') {
            formRespond(false, 'FENLLM_PARTNER_SECRET is not set on this instance — paste a FenLLM API key into the card instead.', $self);
        }
        $me = getCurrentUser();
        [$ok, $message] = llmProvisionFenLlm($conn, $me['email'] ?? '', $me['name'] ?? '', $secret);
        // The ok flag only — never the key, and the message is already safe
        // (llmProvisionFenLlm composes it without secrets).
        logAudit($conn, 'llm.fenllm_provisioned', 'llm_provider', 'fenllm', ['ok' => $ok]);
        formRespond($ok, $message, $self);
    }

    if ($action === 'save_toggles') {
        // Voice-note transcription with no transcription model selected is a
        // switch that does nothing: every voice note silently goes unanswered,
        // and both the admin and the tenant see a feature that is "on". Refused,
        // not warned — there is exactly one field to fill in and it is right
        // under the toggle.
        $wantAudio = !empty($_POST['llm_enable_audio']);
        $transcribeId = (int)($_POST['llm_transcribe_model_id'] ?? 0);
        if ($wantAudio) {
            $valid = in_array($transcribeId, array_map('intval', array_column(llmTranscribeCandidates($conn, true), 'id')), true);
            if (!$valid) {
                formRespond(false, $transcribeId > 0
                    ? 'That transcription model is not available — pick an enabled one, or add a transcription model first.'
                    : 'Choose a transcription model before switching voice notes on — without one, voice notes are silently ignored.',
                    $self,
                    ['llm_transcribe_model_id' => 'Pick an enabled transcription model.']);
            }
        }

        setAppSetting($conn, 'llm_allow_byo_keys', !empty($_POST['llm_allow_byo_keys']) ? '1' : '0');
        setAppSetting($conn, 'llm_enable_audio', $wantAudio ? '1' : '0');
        setAppSetting($conn, 'llm_transcribe_model_id', (string)(int)($_POST['llm_transcribe_model_id'] ?? 0) ?: '');
        logAudit($conn, 'llm.toggles_saved', 'app_settings', null, [
            'byo' => !empty($_POST['llm_allow_byo_keys']),
            'audio' => !empty($_POST['llm_enable_audio']),
        ]);
        formRespond(true, 'Instance toggles updated.', $self);
    }

    // Only reached by a POST naming an action that does not exist: every branch
    // above exits. It answers through formRespond() rather than redirect()
    // because a 302 to an HTML page comes back to fetch() as something it cannot
    // parse, and the submit would then look like a network failure.
    formRespond(false, 'Unknown action.', $self);
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
$transcribeModels = llmTranscribeCandidates($conn, false);
$plans = getActivePlans($conn);
$planAccess = [];
foreach ($plans as $plan) {
    $planAccess[$plan['id']] = llmPlanModelIds($conn, (int)$plan['id']);
}

$pageTitle = 'AI providers & models';
require_once dirname(__DIR__) . '/includes/admin-header.php';
?>

<?php if (!cryptoSecretAvailable()): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <?= sanitize(cryptoSecretMissingMessage()) ?>
    </div>
<?php endif; ?>

<?php // What this page is for, in one paragraph, before any of the jargon below.
      // An admin arriving here for the first time was expected to already know
      // what a provider, a model id and a base URL are. ?>
<details class="alert alert-light border small mb-3">
    <summary class="fw-600">How this page works</summary>
    The chatbot cannot write a reply on its own — it asks an AI company (a <em>provider</em>) to do it,
    over the internet, using an account you hold with them. A free <strong>FenLLM</strong> trial account
    was opened automatically at install and is already configured and selected as the default — for most
    instances there is nothing to do here. To use another provider instead, there are three steps:
    add its <em>API key</em> below, decide which <em>models</em> may be used, and choose which
    <a href="<?= APP_URL ?>/admin/plans.php">plans</a> may use which model. Customers then pick from what
    their plan allows, on their own Chatbot page. You pay the provider directly for what is used.
</details>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-plug me-2"></i>Providers</div>
            <div class="card-body">
                <p class="text-muted small">
                    An <strong>API key</strong> is the password for your account with that company —
                    it is how they know the usage is yours to pay for. Keys are encrypted at rest and never
                    displayed again. Leave the key field blank to keep the stored one. Models from a provider without a
                    stored key are unavailable to customers. You only need one provider for the chatbot to work
                    — <strong>FenLLM</strong>, listed first, was pre-configured at install and is the
                    default model for customers who have not selected one.
                </p>
                <p class="form-text">
                    Base URL is optional — only change it if you route through a proxy or a compatible
                    service. Test connection tries the key typed in the card, or the stored one if the
                    field is blank — testing does not save.
                </p>

                <?php // One form per provider, each saving in place (#24). Not a modal:
                      // this is an edit-in-place row whose surrounding help text — where
                      // to get a key, what a base URL is for — is the reason an admin can
                      // fill it in at all, and a dialog would hide it. ?>
                <?php // FenLLM's extras are resolved once, before the loop: the
                      // balance state is read from app_settings only — the live
                      // call to the vendor happens in the background, after
                      // the page has already rendered. ?>
                <?php $fenllmState = null; ?>
                <?php // One accordion item per provider. No data-bs-parent:
                      // comparing two providers' settings is a real task, and a
                      // parented accordion would close one every time the other
                      // opened. Expanded when the provider is live (has a key or
                      // is enabled); a never-configured one stays out of the way. ?>
                <div class="accordion">
                <?php foreach ($providers as $code => $p): ?>
                <?php $accOpen = $p['has_key'] || !empty($p['row']['is_enabled']); ?>
                <div class="accordion-item" id="llm-acc-<?= sanitize($code) ?>">
                    <h2 class="accordion-header">
                        <button class="accordion-button<?= $accOpen ? '' : ' collapsed' ?>" type="button"
                                data-bs-toggle="collapse" data-bs-target="#llm-body-<?= sanitize($code) ?>"
                                aria-expanded="<?= $accOpen ? 'true' : 'false' ?>"
                                aria-controls="llm-body-<?= sanitize($code) ?>">
                            <span class="fw-500"><?= sanitize($p['meta']['label']) ?></span>
                            <span class="ms-2 d-flex gap-1">
                                <?php if ($p['has_key']): ?>
                                    <span class="badge bg-success">Key stored</span>
                                <?php endif; ?>
                                <?php if ($p['row'] && $p['row']['last_test_ok'] !== null): ?>
                                    <span class="badge bg-<?= $p['row']['last_test_ok'] ? 'success' : 'danger' ?>">
                                        <?= $p['row']['last_test_ok'] ? 'Test passed' : 'Test failed' ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        </button>
                    </h2>
                    <div id="llm-body-<?= sanitize($code) ?>" class="accordion-collapse collapse<?= $accOpen ? ' show' : '' ?>">
                    <div class="accordion-body">
                <form method="post" class="border rounded p-3 mb-3" data-ajax>
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
                            <?php if (!empty($p['meta']['console_url'])): ?>
                                <div class="form-text">
                                    Create an API key at
                                    <a href="<?= sanitize($p['meta']['console_url']) ?>" target="_blank" rel="noopener noreferrer">
                                        <?= sanitize(parse_url($p['meta']['console_url'], PHP_URL_HOST)) ?>
                                    </a>
                                    (you will need billing set up with them).
                                </div>
                            <?php endif; ?>
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

                    <div class="d-flex gap-2 mt-3 align-items-center flex-wrap">
                        <button class="btn btn-primary btn-sm" type="submit">Save</button>
                        <?php // Shown whenever the provider row exists, not only when a key is
                              // already stored: an admin pasting a first key needs to be able to
                              // check it before committing to it. A test needs a model to call,
                              // and models are seeded when the provider is first saved, which is
                              // why a never-saved provider still has no Test button. ?>
                        <?php if ($p['row']): ?>
                            <button class="btn btn-outline-secondary btn-sm" type="submit"
                                    name="action" value="test_provider">Test connection</button>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if ($code === 'fenllm'): ?>
                    <?php
                    // The auto-provisioned provider gets its own panel: the
                    // trial balance, the magic sign-in link (the account has no
                    // password — that link IS how the admin reaches it), and a
                    // way to create the account by hand when bootstrap did not.
                    if ($p['has_key'] && $fenllmState === null) {
                        $fenllmState = llmFenLlmBalanceState($conn);
                    }
                    $fenllmSignIn = llmFenLlmSignInUrl($conn)
                        ?? ($fenllmState['balance']['sign_in_url'] ?? null);
                    $fenllmStatus = overrideSetting($conn, 'fenllm_provision_status');
                    ?>
                    <div class="border rounded p-3 mb-3 bg-light">
                        <?php if ($p['has_key']): ?>
                            <p class="small text-muted mb-2">
                                Your FenLLM trial account was created during installation. Refresh the
                                balance below to check its available credit.
                            </p>
                            <?= fenllmBalancePanelHtml($fenllmState ?? llmFenLlmBalanceState(null), $fenllmSignIn) ?>
                            <form method="post" class="mt-2" data-ajax>
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="fenllm_refresh_balance">
                                <button class="btn btn-outline-secondary btn-sm" type="submit">Refresh balance</button>
                            </form>
                        <?php elseif ($fenllmStatus === 'account_exists'): ?>
                            <p class="small mb-2">
                                A FenLLM account already exists for this admin email — the trial key from
                                that account cannot be fetched again.
                                <?php if ($fenllmSignIn): ?>
                                    <a href="<?= sanitize($fenllmSignIn) ?>" target="_blank" rel="noopener">Sign in to your account</a>
                                    and paste its API key into the card above.
                                <?php endif; ?>
                            </p>
                        <?php elseif (env('FENLLM_PARTNER_SECRET')): ?>
                            <p class="small text-muted mb-2">
                                No trial account was created at install. Create one now — a free FenLLM
                                account is opened for your admin email and its key is stored here.
                            </p>
                            <form method="post" data-ajax>
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="fenllm_signup">
                                <button class="btn btn-primary btn-sm" type="submit">Create free trial account</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                    </div><?php // /.accordion-body ?>
                    </div><?php // /#llm-body-… ?>
                </div><?php // /.accordion-item ?>
                <?php endforeach; ?>
                </div><?php // /.accordion ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-cpu me-2"></i>Models</div>
            <div class="card-body">
                <p class="text-muted small">
                    A <strong>model</strong> is the particular AI a provider offers — they differ in
                    quality and in price per message. A <strong>model ID</strong> is the exact name the
                    vendor uses for one, like <code>gpt-4o-mini</code>. Saving a provider above adds its
                    well-known models here automatically; you only need this form for a model
                    the vendor released later. Models are listed explicitly, never fetched from the vendor:
                    a new and expensive model must never become selectable on its own.
                </p>

                <table class="table table-sm align-middle table-stack">
                    <thead><tr><th>Provider</th><th>Model</th><th>Kind</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php // A chat model with the 'audio' capability (FenLLM Max) is in
                          // both lists — it can chat AND transcribe — so the merge is
                          // deduped by id rather than concatenated. ?>
                    <?php $tableModels = [];
                    foreach (array_merge($chatModels, $transcribeModels) as $m) $tableModels[(int)$m['id']] = $m; ?>
                    <?php foreach ($tableModels as $m): ?>
                        <tr class="<?= $m['is_enabled'] ? '' : 'opacity-50' ?>">
                            <td class="small" data-label="Provider"><?= sanitize($m['provider_label']) ?></td>
                            <td class="cell-block" data-label="Model">
                                <div class="small fw-500"><?= sanitize($m['label']) ?></div>
                                <code class="x-small text-muted"><?= sanitize($m['model_code']) ?></code>
                            </td>
                            <td data-label="Kind"><span class="badge bg-light text-dark"><?= sanitize($m['kind'] === 'chat' && llmModelTranscribes($m) ? 'chat + audio' : $m['kind']) ?></span></td>
                            <td class="text-end">
                                <form method="post" class="d-inline" data-ajax>
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_model">
                                    <input type="hidden" name="model_id" value="<?= (int)$m['id'] ?>">
                                    <input type="hidden" name="enable" value="<?= $m['is_enabled'] ? '0' : '1' ?>">
                                    <?php // Only disabling is confirmed: it takes the model away from
                                          // every tenant currently using it. Enabling is additive, and a
                                          // dialog on a harmless action teaches people to dismiss dialogs. ?>
                                    <button class="btn btn-outline-secondary btn-sm" type="submit"
                                        <?= $m['is_enabled'] ? 'data-confirm="Disable this model? Customers using it will have to pick another."' : '' ?>>
                                        <?= $m['is_enabled'] ? 'Disable' : 'Enable' ?>
                                    </button>
                                </form>
                                <form method="post" class="d-inline" data-ajax>
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_model">
                                    <input type="hidden" name="model_id" value="<?= (int)$m['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm" type="submit"
                                            data-confirm="Remove this model? Customers using it will have to pick another.">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$chatModels && !$transcribeModels): ?>
                        <tr><td colspan="4" class="text-muted small">
                            No models yet. Add a provider above and press Save — its known models appear here.
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>

                <?php // Left inline rather than moved into a modal: it is a four-field
                      // create form sitting directly under the table it adds a row to, and
                      // the paragraph above it is what tells an admin what a model id even
                      // looks like. With data-ajax it no longer costs a page load (#24). ?>
                <form method="post" class="row g-2 align-items-end border-top pt-3" data-ajax>
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
                        <label class="form-label small">Model ID</label>
                        <input type="text" name="model_code" class="form-control form-control-sm"
                               placeholder="gpt-4o-mini" required>
                    </div>
                    <div class="col-md-2">
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
                    <div class="col-md-2">
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
                <form method="post" data-ajax>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_toggles">

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="llm_allow_byo_keys" value="1"
                               id="byo" <?= llmAllowByoKeys($conn) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="byo">Allow customers to use their own API key</label>
                        <div class="form-text">
                            Also called “bring your own keys”: the customer pays the AI provider directly.
                            Their plan must include <strong>Bring your own LLM key</strong>, over on
                            <a href="<?= APP_URL ?>/admin/plans.php">Plans</a>.
                            Their key is encrypted and never shown back to them either.
                        </div>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="llm_enable_audio" value="1"
                               id="audio" <?= llmAudioEnabled($conn) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="audio">Transcribe incoming voice notes</label>
                        <div class="form-text">
                            Off by default: every voice note becomes a paid transcription request.
                            Needs a transcription model below — saving without one is refused, because
                            the switch would otherwise be on while voice notes were silently ignored.
                            Customers must also switch it on for their own chatbot, and their plan needs the
                            <strong>Voice note understanding</strong> feature on
                            <a href="<?= APP_URL ?>/admin/plans.php">Plans</a>.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Transcription model</label>
                        <select name="llm_transcribe_model_id" class="form-select form-select-sm">
                            <option value="">— none —</option>
                            <?php foreach ($transcribeModels as $m): ?>
                                <?php // A disabled model cannot be used, and picking one here used to
                                      // look identical to picking a working one. ?>
                                <option value="<?= (int)$m['id'] ?>"
                                    <?= llmTranscribeModelId($conn) === (int)$m['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($m['provider_label'] . ' — ' . $m['label']) ?><?= $m['is_enabled'] && $m['provider_enabled'] ? '' : ' (disabled)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Paid for by the platform, so it is one instance-wide choice.
                            <?php if (!$transcribeModels): ?>
                                <span class="text-warning">No transcription model exists yet — add one with kind
                                <code>transcribe</code> under Models (OpenAI's <code>whisper-1</code> is seeded
                                automatically when you save OpenAI, and FenLLM Max is added automatically
                                once a FenLLM key is stored).</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <button class="btn btn-primary btn-sm" type="submit">Save AI settings</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Model access by plan</div>
            <div class="card-body">
                <p class="text-muted small">
                    Unchecked models are unavailable to that plan. Access changes take effect immediately.
                </p>
                <form method="post" data-ajax>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_access">
                    <?php // The matrix is a table now: a row per model, a column per plan.
                          // The checkboxes are byte-identical to the old per-plan lists —
                          // name, value, id and the checked test all unchanged — so a
                          // save posts exactly what it did before. The plan heading's
                          // chatbot-off warning keeps its link and its title. ?>
                    <?php if ($chatModels): ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col" class="small">Model</th>
                                    <?php foreach ($plans as $plan): ?>
                                        <th scope="col" class="small">
                                            <?= sanitize($plan['name']) ?>
                                            <?php // The badge stated a fact and left the admin to find where it
                                                  // is changed. It is changed on the Plans page, so it links there. ?>
                                            <?php if (!planHasFeature($plan, 'chatbot')): ?>
                                                <a href="<?= APP_URL ?>/admin/plans.php" class="badge bg-warning text-dark d-block text-decoration-none"
                                                   title="Granting models here has no effect until the AI chatbot feature is on for this plan. Click to change it.">
                                                    AI chatbot off for this plan — fix on Plans
                                                </a>
                                            <?php endif; ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($chatModels as $m): ?>
                                <tr>
                                    <th scope="row" class="small fw-500">
                                        <?= sanitize($m['provider_label'] . ' — ' . $m['label']) ?>
                                    </th>
                                    <?php foreach ($plans as $plan): ?>
                                        <td class="text-center">
                                            <input class="form-check-input" type="checkbox"
                                                   name="plan_models[<?= (int)$plan['id'] ?>][]" value="<?= (int)$m['id'] ?>"
                                                   id="pm-<?= (int)$plan['id'] ?>-<?= (int)$m['id'] ?>"
                                                   aria-label="<?= sanitize($plan['name'] . ' — ' . $m['provider_label'] . ' — ' . $m['label']) ?>"
                                                <?= in_array((int)$m['id'], $planAccess[$plan['id']], true) ? 'checked' : '' ?>>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                        <div class="text-muted small mb-3">No chat models yet.</div>
                    <?php endif; ?>
                    <button class="btn btn-primary btn-sm" type="submit">Save access</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php // The live balance check. The panel above rendered from app_settings
      // only; this swaps it for fresh HTML once the vendor has answered. It
      // exists only when there is a key to check with, and its failure leaves
      // the last good balance untouched. ?>
<?php if (!empty($providers['fenllm']['has_key'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var panel = document.getElementById('fenllm-balance');
    if (!panel) return;
    var status = panel.querySelector('.fenllm-live-status');
    if (status) status.classList.remove('d-none');

    var body = new FormData();
    body.append('action', 'fenllm_balance_live');
    body.append('csrf_token', window.waCsrfToken || '');

    var ctl = new AbortController();
    var timer = setTimeout(function () { ctl.abort(); }, 10000);

    fetch(window.location.href, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        signal: ctl.signal
    }).then(function (res) {
        return res.ok ? res.json() : null;
    }).then(function (json) {
        clearTimeout(timer);
        if (json && json.ok && json.html) {
            panel.outerHTML = json.html;
            return;
        }
        throw new Error('bad response');
    }).catch(function () {
        clearTimeout(timer);
        var s = document.querySelector('#fenllm-balance .fenllm-live-status');
        if (s) s.textContent = "Couldn't check the live balance.";
    });
});
</script>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
