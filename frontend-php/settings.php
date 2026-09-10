<?php
// Tenant settings — everything the tenant configures about the thing that
// answers their WhatsApp: knowledge base, model, behaviour, appointments and
// human handover.
//
// This was chatbot.php until #41, and it is `settings.php` because a page that
// owns five unrelated-looking tabs is not "the chatbot page" any more. The route
// it took over belonged to a tenant settings page that had no settings left on
// it (#40) — timezone had moved to Profile and reconnection was never a choice —
// so nothing is lost by reusing the name, and `chatbot.php` still redirects here
// for anyone who bookmarked it.
//
// Not to be confused with admin/settings.php, which configures the *instance*.
// Nothing on this page crosses tenants.
//
// Gated on the plan's `chatbot` feature. The tenant's own API key, if they use
// one, is encrypted with the same secretbox as every other stored secret and is
// never rendered back into the form.
require_once __DIR__ . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$errors = [];
$plan = getUserPlan($conn, $userId);
$hasChatbot = planHasFeature($plan, 'chatbot');
$canByo = llmAllowByoKeys($conn) && planHasFeature($plan, 'llm_byok');
// Each sub-feature is its own plan lever now. Voice notes need all three to line
// up: the plan, the instance-wide admin toggle, and a configured model.
$canAppointments = planHasFeature($plan, 'appointments');
$canHandoff = planHasFeature($plan, 'handoff');
$audioAvailable = planHasFeature($plan, 'voice_transcription')
    && llmAudioEnabled($conn) && llmTranscribeModelId($conn);

$config = chatbotConfig($conn, $userId);
$availableModels = llmModelsForPlan($conn, (int)$plan['id']);

// Handover email is delivered by the instance's SMTP, which only an admin can
// configure. Read before the POST handler because the handler needs it too: the
// field is disabled when mail cannot be sent, and a disabled field submits
// nothing, which chatbotSaveConfig() would store as NULL.
$smtpReady = smtpConfigured($conn);

// Every handler below ends at formRespond(), which answers JSON to the page's
// own fetch() and flashes-and-redirects to a plain form post. One code path, two
// audiences — see includes/ajax.php.
$self = APP_URL . '/settings.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasChatbot) {
    formRequireCsrf($self);

    $action = $_POST['action'] ?? 'save';

    if ($action === 'save_service') {
        [$ok, $err] = apptSaveService(
            $conn, $userId,
            $_POST['service_name'] ?? '',
            $_POST['service_minutes'] ?? 30,
            $_POST['service_description'] ?? '',
            ($_POST['service_id'] ?? '') === '' ? null : (int)$_POST['service_id']
        );
        formRespond($ok, $ok ? 'Service saved.' : $err, $self, $ok ? [] : ['service_name' => $err]);
    }

    if ($action === 'toggle_service') {
        apptSetServiceActive($conn, $userId, (int)($_POST['service_id'] ?? 0), !empty($_POST['enable']));
        formRespond(true, 'Service updated.', $self);
    }

    if ($action === 'save_hours') {
        // #25: one row per weekday with an on/off toggle, and any number of
        // windows within a day. A day whose toggle is off contributes nothing,
        // which is how "closed" is stored — the absence of a window, not a
        // zero-length one.
        $windows = [];
        $enabledDays = array_map('intval', array_keys((array)($_POST['day_enabled'] ?? [])));
        foreach (($_POST['day'] ?? []) as $weekday => $rows) {
            if (!in_array((int)$weekday, $enabledDays, true)) continue;
            foreach (($rows['start'] ?? []) as $i => $start) {
                // A row the tenant added and left empty is not an error, it is a
                // row they did not fill in.
                $end = $rows['end'][$i] ?? '';
                if (trim((string)$start) === '' && trim((string)$end) === '') continue;
                $windows[] = ['weekday' => (int)$weekday, 'start' => $start, 'end' => $end];
            }
        }

        [$saved, $hourErrors] = apptSaveAvailability($conn, $userId, $windows);
        if (!$saved) {
            formRespond(false, 'Some opening hours could not be saved — see the highlighted rows.',
                $self, $hourErrors);
        }
        formRespond(true, 'Opening hours saved.', $self);
    }

    if ($action === 'clear_key') {
        chatbotClearByoKey($conn, $userId);
        formRespond(true, 'Your API key has been removed.', $self);
    }

    $input = $_POST;

    // A tenant may only select a model their plan was granted. Without this a
    // crafted POST could point at any model id in the instance — the reply path
    // re-checks too, but a rejected id should never be stored in the first place.
    $chosen = ($input['model_id'] ?? '') === '' ? null : (int)$input['model_id'];
    if ($chosen !== null && !in_array($chosen, array_map('intval', array_column($availableModels, 'id')), true)) {
        flash('error', 'That model is not available on your plan.');
        redirect($self);
    }

    if (!$canByo) {
        // Ignore any BYO fields that arrive when the option is not open to them.
        $input['byo_provider_code'] = null;
        $input['byo_model_code'] = null;
    }
    if (!$audioAvailable) {
        $input['transcribe_audio'] = 0;
    }
    // The handover email field is disabled while the instance cannot send mail,
    // and a disabled field submits nothing — which every absent field in
    // chatbotSaveConfig() writes as NULL. Carrying the stored value forward keeps
    // an address the tenant entered while mail was working from being erased by a
    // save they made for an unrelated reason.
    if (!$smtpReady) {
        $input['handoff_notify_email'] = $config['handoff_notify_email'];
    }
    // Same treatment for the two new levers: a crafted POST cannot switch on a
    // capability the plan withholds. Silently dropped rather than rejected, so a
    // tenant saving the rest of the form is not blocked by a field they cannot
    // even see.
    if (!$canAppointments) {
        $input['appointments_enabled'] = 0;
    }
    if (!$canHandoff) {
        $input['handoff_enabled'] = 0;
        $input['handoff_share_number'] = 0;
    }

    // #26. Refused rather than silently normalised: a number stored with a
    // leading zero or missing its country code is unreachable on WhatsApp, and
    // the failure is invisible — every alert goes nowhere and the tenant only
    // finds out when a customer complains they were never called back.
    if (handoffNormaliseNumber($input['handoff_notify_number'] ?? '') === false) {
        formRespond(false, 'The handover notification number is not a valid international number.', $self, [
            'handoff_notify_number' => 'Country code first, no leading zero, no spaces. e.g. 923001234567',
        ]);
    }

    // Turning the bot on without something to answer with would produce a
    // silent bot and a confused tenant.
    //
    // The message distinguishes the two causes, because they need different
    // people: "you have not picked one" is the tenant's job and the Model tab is
    // where they do it, while "there are none to pick" is the administrator's.
    // One message for both sent tenants looking for a control that was not there.
    $usingByo = $canByo && !empty($input['byo_provider_code']);
    if (!empty($input['is_enabled']) && $chosen === null && !$usingByo) {
        if (!$availableModels) {
            formRespond(false, isAdmin()
                ? 'No AI models are assigned to your plan yet. Set one up under Admin → AI / LLM, then come back.'
                : 'Your administrator has not set up any AI models yet, so the chatbot cannot be switched on. Please contact them.',
                $self);
        }
        formRespond(false, 'Go to the Model tab and choose an AI model first — the bot needs one to write replies.',
            $self, ['model_id' => 'Pick a model before switching the bot on.']);
    }

    // Appointments with nothing bookable is the same class of mistake: the toggle
    // saves, the tenant believes booking is live, and the bot can never take one
    // because there is no service to book. Refused rather than warned — the note
    // under the services list was already there and was missed.
    //
    // Only the off→on transition is refused. A tenant whose services were all
    // deactivated *after* enabling booking would otherwise be unable to save this
    // form at all, including the change that fixes it. That state gets a warning
    // next to the toggle instead.
    if (!empty($input['appointments_enabled']) && empty($config['appointments_enabled'])
        && !apptServices($conn, $userId, true)) {
        formRespond(false, 'Add at least one active service before switching appointment booking on — the bot has nothing to book without one.', $self);
    }

    chatbotSaveConfig($conn, $userId, $input);

    if ($usingByo) {
        [$ok, $err] = chatbotSaveByoKey($conn, $userId, $_POST['byo_api_key'] ?? '');
        if (!$ok) formRespond(false, $err, $self, ['byo_api_key' => $err]);

        $fresh = chatbotConfig($conn, $userId);
        if (!empty($input['is_enabled']) && empty($fresh['byo_api_key_encrypted'])) {
            formRespond(false, 'Enter your API key before switching the chatbot on.', $self,
                ['byo_api_key' => 'Required while the bot is on and set to your own key.']);
        }
    }

    logAudit($conn, 'chatbot.config_saved', 'chatbot_config', (string)$userId, [
        'enabled' => !empty($input['is_enabled']),
        'byo' => $usingByo,
    ]);
    formRespond(true, 'Chatbot settings saved.', $self);
}

$hasByoKey = !empty($config['byo_api_key_encrypted']);
$services = apptServices($conn, $userId, false);
$availability = apptAvailability($conn, $userId);
$hoursByDay = [];
foreach ($availability as $w) $hoursByDay[(int)$w['weekday']][] = $w;
// #25: a tenant who has never saved a schedule is shown the default week
// (Mon–Fri 09:00–17:00, weekends off) pre-filled rather than seven blank rows.
// The default is offered, never written on their behalf — see
// apptDefaultAvailability() for why that distinction matters.
$hasSchedule = $hoursByDay !== [];
if (!$hasSchedule) $hoursByDay = apptDefaultAvailability();
$events = $hasChatbot ? chatbotRecentEvents($conn, $userId, 15) : [];
$repliesThisMonth = usageCount($conn, $userId, 'chatbot_replies');
// Reported through the same helper the reply path uses, so the number a tenant
// reads here cannot disagree with the one that actually stops the bot — passing
// $config is what makes it account for a tenant on their own key.
[$replyQuotaOk, , $replyLimit] = checkChatbotReplyQuota($conn, $userId, $config);
$replyQuotaExhausted = !$replyQuotaOk;
$usingOwnKey = !empty($config['byo_provider_code']) && !empty($config['byo_api_key_encrypted']) && $canByo;
$tz = getUserTimezone($conn, $userId);

// #26. Two separate facts, and the Handover tab needs both: whether an admin
// fallback exists (so a tenant leaving the field blank can be told where alerts
// go), and which number would actually be used (so the "share it with the
// customer" switch is only offered when there is something to share).
$adminNotifyNumber = (string)(overrideSetting($conn, 'handoff_notify_number') ?? '');
$effectiveNotifyNumber = handoffNotifyNumber($conn, $config);

// The prerequisites this page used to be silent about. A tenant could configure
// every tab, switch the bot on, and get nothing — because there was no WhatsApp
// account for it to listen to, or none of them was connected. The page has to
// say so where the work happens, not leave it to be discovered.
$accountsLinked = countWaAccounts($conn, $userId);
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM wa_accounts WHERE user_id = ? AND status = 'connected'");
$stmt->bind_param('i', $userId);
$stmt->execute();
$accountsConnected = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$activeServiceCount = count(array_filter($services, fn($s) => (int)$s['is_active'] === 1));

// #34. Every gap that makes a correctly-working bot look broken.
//
// This list is the difference between "the AI is useless" and "the AI has not
// been told anything yet". Each entry is a real reason the prompt will refuse,
// stated as the refusal the tester will actually see, with the control that
// fixes it.
//
// The first version of this only covered the company name and the knowledge
// base, and that was not enough: asking "can you book me an appointment?" got a
// flat refusal with no explanation, because three *other* things gate booking
// and none of them was mentioned. A gap list that is missing the gap you just
// hit is worse than no list, so the booking prerequisites are here now — in the
// same order chatbotAppointmentContext() checks them, because the first one that
// fails is the one that matters.
$testProfile = getUserProfile($conn, $userId);
$testGaps = [];

if (trim((string)($testProfile['company_name'] ?? '')) === '') {
    $testGaps[] = ['html' =>
        'No <strong>company name</strong> on your profile, so the bot is told it is "replying on behalf of '
        . 'a business" and cannot tell a customer what you are called. '
        . '<a href="' . APP_URL . '/profile.php">Add it</a>.'];
}

if (trim((string)($config['knowledge_base'] ?? '')) === '') {
    $testGaps[] = ['html' =>
        'The <strong>knowledge base</strong> is empty, so the bot is instructed to answer nothing specific '
        . 'and to offer a callback instead. That is deliberate — it will not invent a price or a policy — '
        . 'but it means every specific question gets a refusal. Fill in the '
        . '<strong>Knowledge base</strong> tab.'];
}

// The booking chain. Only shown to a plan that actually includes appointments —
// on a plan without it, "booking is off" is not a gap, it is the plan.
if ($canAppointments) {
    if (empty($config['appointments_enabled'])) {
        // The one that caught this out. With the switch off the appointment
        // context is null, so the prompt never mentions booking at all and the
        // model correctly answers that it cannot book — which reads exactly like
        // a broken bot to whoever just asked it to book something.
        $testGaps[] = ['html' =>
            '<strong>Appointment booking is switched off</strong>, so the bot is never told it can book '
            . 'anything and will say it cannot. Turn it on under '
            . '<a href="#tab-appointments" data-bs-toggle="tab" data-bs-target="#tab-appointments">Appointments</a>.'];
    } elseif ($activeServiceCount === 0) {
        $testGaps[] = ['html' =>
            'Booking is on but there is <strong>no active service</strong> to book, so the bot is not told '
            . 'about booking at all. Add one under '
            . '<a href="#tab-appointments" data-bs-toggle="tab" data-bs-target="#tab-appointments">Appointments</a>.'];
    } elseif (!$hasSchedule) {
        // Distinct from the two above: here the prompt *does* describe booking,
        // and then tells the model there are no opening hours and it must offer a
        // callback instead. Same visible outcome, completely different cause.
        $testGaps[] = ['html' =>
            'Booking is on, but <strong>no opening hours are saved</strong>, so the bot is told it cannot '
            . 'take a booking and should offer a callback. Set them under '
            . '<a href="#tab-appointments" data-bs-toggle="tab" data-bs-target="#tab-appointments">Appointments</a>.'];
    }
}

// A model is not a "gap" in the same sense — it is a hard stop, and the endpoint
// returns a clear error rather than a bad answer — but it belongs in the same
// list because it is the first thing to check.
if (empty($config['model_id']) && empty($config['byo_model_code'])) {
    $testGaps[] = ['html' =>
        '<strong>No model is selected</strong>, so nothing can answer at all. Pick one under '
        . '<a href="#tab-model" data-bs-toggle="tab" data-bs-target="#tab-model">Model</a>.'];
}

$pageTitle = 'Settings';
require_once __DIR__ . '/includes/header.php';
?>

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

<?php // Stated once, at the top, before any of the settings below matter. ?>
<?php if ($accountsLinked === 0): ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <i class="bi bi-exclamation-triangle me-1"></i>
            <strong>No WhatsApp account is linked.</strong>
            The chatbot answers messages that arrive on a linked account — until you link one,
            nothing here has any effect.
        </div>
        <a href="<?= APP_URL ?>/whatsapp/link.php" class="btn btn-sm btn-warning">Link an account</a>
    </div>
<?php elseif ($accountsConnected === 0): ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <i class="bi bi-exclamation-triangle me-1"></i>
            <strong>None of your WhatsApp accounts is connected.</strong>
            The bot can only reply while an account is connected. Check its status and re-link it if needed.
        </div>
        <a href="<?= APP_URL ?>/whatsapp/accounts.php" class="btn btn-sm btn-warning">Check accounts</a>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <?php // data-ajax keeps the tenant on the tab they were editing (#25): a
              // plain submit reloads and lands back on Knowledge base, which
              // reads as the change having been discarded. Without JavaScript it
              // is exactly the form it always was. ?>
        <form method="post" id="chatbotForm" data-ajax data-ajax-reload="off">
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
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-appointments" type="button">Appointments</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-handoff" type="button">Handover</button></li>
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
                                <?php // "Contact the administrator" is a dead end when the viewer
                                      // *is* the administrator — which is the common case on a
                                      // fresh instance, where the admin is also the first tenant. ?>
                                <?php if (isAdmin()): ?>
                                    No models have been assigned to your plan yet.
                                    <a href="<?= APP_URL ?>/admin/llm.php">Set up a provider and assign models to plans</a>.
                                <?php else: ?>
                                    No models are available on your plan yet. Contact the administrator.
                                <?php endif; ?>
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
                        <?php // Submits clearKeyForm, which lives outside this form —
                              // see the note there. It must not be a submit button
                              // belonging to chatbotForm. ?>
                        <?php if ($hasByoKey): ?>
                            <button class="btn btn-outline-danger btn-sm mt-2" type="submit" form="clearKeyForm"
                                    <?php // data-confirm replaces the old inline confirm(). Both
                                          // together would ask twice; forms.js falls back to
                                          // window.confirm() when Bootstrap is unavailable, so the
                                          // guard is not lost. ?>
                                    data-confirm="Remove your stored API key? The bot will go back to using the model selected above.">
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

                        <?php // Shown even when unavailable, greyed out with the reason. Hiding
                              // it entirely meant a tenant could not tell the feature existed,
                              // let alone that someone else controls it — and the control
                              // appearing and disappearing between visits looked like a bug.
                              // The two reasons need different people, so they are named. ?>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="transcribe_audio" value="1"
                                       id="transcribe" <?= $config['transcribe_audio'] ? 'checked' : '' ?>
                                       <?= $audioAvailable ? '' : 'disabled' ?>>
                                <label class="form-check-label <?= $audioAvailable ? '' : 'text-muted' ?>" for="transcribe">
                                    Understand voice notes
                                </label>
                            </div>
                            <?php if ($audioAvailable): ?>
                                <div class="form-text">Incoming voice notes are transcribed, then answered as text.</div>
                            <?php elseif (!planHasFeature($plan, 'voice_transcription')): ?>
                                <div class="form-text">
                                    <i class="bi bi-lock me-1"></i>Not part of your plan.
                                    <a href="<?= APP_URL ?>/billing.php">See plans</a>.
                                </div>
                            <?php else: ?>
                                <div class="form-text">
                                    <i class="bi bi-lock me-1"></i>Voice note transcription is not switched on for this instance.
                                    <?php if (isAdmin()): ?>
                                        <a href="<?= APP_URL ?>/admin/llm.php">Enable it and pick a transcription model</a>.
                                    <?php else: ?>
                                        Contact the administrator.
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade p-3" id="tab-appointments">
                    <?php if (!$canAppointments): ?>
                        <div class="alert alert-warning small">
                            <i class="bi bi-lock me-1"></i>
                            Appointment booking is not part of your plan. The settings below are
                            disabled, and the bot will not offer to book anything.
                            <a href="<?= APP_URL ?>/billing.php">See plans</a>.
                        </div>
                    <?php endif; ?>
                    <?php // Next to the toggle, not only under the services table at the
                          // bottom of the page: that note existed and was still missed,
                          // because by then the switch had already been flipped. Saving is
                          // refused in the handler above — this says so before they try. ?>
                    <?php if ($canAppointments && $activeServiceCount === 0): ?>
                        <div class="alert alert-warning small">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            <?php if (!empty($config['appointments_enabled'])): ?>
                                Booking is on, but you have <strong>no active services</strong> — so the bot
                                cannot actually book anything.
                            <?php else: ?>
                                You have no active services yet, so booking cannot be switched on.
                            <?php endif; ?>
                            Add one under <strong>Services</strong> further down this page.
                        </div>
                    <?php endif; ?>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="appointments_enabled" value="1"
                               id="appointments_enabled" <?= !empty($config['appointments_enabled']) ? 'checked' : '' ?>
                               <?= $canAppointments ? '' : 'disabled' ?>>
                        <label class="form-check-label fw-500" for="appointments_enabled">
                            Let customers book appointments in the chat
                        </label>
                        <div class="form-text">
                            The bot may only <em>propose</em> a time. Whether a booking is real is decided here
                            against your calendar — so it cannot confirm a slot that is taken or a time you are closed.
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small">Minimum notice</label>
                            <div class="input-group input-group-sm">
                                <input type="number" name="appointment_lead_minutes" class="form-control" min="0" max="10080"
                                       value="<?= (int)($config['appointment_lead_minutes'] ?? 60) ?>">
                                <span class="input-group-text">minutes</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Book at most</label>
                            <div class="input-group input-group-sm">
                                <input type="number" name="appointment_horizon_days" class="form-control" min="1" max="365"
                                       value="<?= (int)($config['appointment_horizon_days'] ?? 30) ?>">
                                <span class="input-group-text">days ahead</span>
                            </div>
                        </div>
                        <?php // #36: the field used to be free text and showed `1440,60`, which
                              // tells a business owner nothing — not even whether the number was
                              // minutes or hours. Every option now carries its unit, and the list
                              // is the one apptReminderChoices() defines, so nothing invalid can
                              // be submitted in the first place.
                              //
                              // A value saved before this change that is not one of the presets
                              // (any minute count was accepted) is added to the list as its own
                              // checked option rather than dropped, so opening this tab cannot
                              // quietly delete a reminder the tenant is relying on.
                              $reminderChoices = apptReminderChoices();
                              $reminderSaved = apptReminderMinutes($config);
                              foreach ($reminderSaved as $m) {
                                  if (!isset($reminderChoices[$m])) $reminderChoices[$m] = apptHumanMinutes($m);
                              }
                              ksort($reminderChoices); ?>
                        <?php // Its own full-width row rather than the third column: nine
                              // labelled options do not fit in a third of the form, and
                              // wrapping them into a narrow stack was how the old field
                              // came to be one cryptic line in the first place. ?>
                        <div class="col-12">
                            <label class="form-label small d-block">Remind before</label>
                            <input type="hidden" name="reminder_minutes_present" value="1">
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($reminderChoices as $minutes => $label): ?>
                                    <div class="form-check form-check-inline me-0">
                                        <input class="form-check-input" type="checkbox" name="reminder_minutes[]"
                                               id="remind_<?= (int)$minutes ?>" value="<?= (int)$minutes ?>"
                                               <?= in_array((int)$minutes, $reminderSaved, true) ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="remind_<?= (int)$minutes ?>">
                                            <?= sanitize($label) ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">
                                Before the appointment. Tick more than one to remind twice; tick none for no reminders.
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small">Confirmation wording</label>
                            <input type="text" name="booking_confirmation" class="form-control form-control-sm"
                                   value="<?= sanitize($config['booking_confirmation'] ?? '') ?>"
                                   placeholder="Confirmed: {service} on {when}.">
                            <div class="form-text"><code>{service}</code> and <code>{when}</code> are filled in.</div>
                        </div>
                    </div>
                    <div class="alert alert-light border small">
                        Services and opening hours are saved separately — the two forms below act on their own.
                    </div>
                </div>

                <div class="tab-pane fade p-3" id="tab-handoff">
                    <?php if (!$canHandoff): ?>
                        <div class="alert alert-warning small">
                            <i class="bi bi-lock me-1"></i>
                            Human handover is not part of your plan. The settings below are disabled
                            and no new conversations will reach
                            <a href="<?= APP_URL ?>/live-chats.php">Live chats</a>.
                            <a href="<?= APP_URL ?>/billing.php">See plans</a>.
                        </div>
                    <?php endif; ?>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="handoff_enabled" value="1"
                               id="handoff_enabled" <?= !empty($config['handoff_enabled']) ? 'checked' : '' ?>
                               <?= $canHandoff ? '' : 'disabled' ?>>
                        <label class="form-check-label fw-500" for="handoff_enabled">
                            Let customers reach a person
                        </label>
                        <div class="form-text">
                            While a conversation is waiting or with an agent the bot stays <strong>silent</strong> in it.
                            It only starts answering again when you resolve the conversation in
                            <a href="<?= APP_URL ?>/live-chats.php">Live chats</a>.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Trigger phrases</label>
                        <input type="text" name="handoff_phrases" class="form-control form-control-sm"
                               value="<?= sanitize($config['handoff_phrases'] ?? '') ?>">
                        <div class="form-text">
                            Comma separated, matched before the model is even called — so this still works when the
                            model is down, which is exactly when people ask for a human. Single words match whole
                            words only, so "agent" does not fire on "management".
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small">What the customer is told</label>
                            <input type="text" name="handoff_ack_message" class="form-control form-control-sm"
                                   value="<?= sanitize($config['handoff_ack_message'] ?? '') ?>"
                                   placeholder="Thanks — I'm passing you to a member of our team. They'll reply here shortly.">
                        </div>
                        <div class="col-12">
                            <label class="form-label small">What they are told when you resolve it</label>
                            <input type="text" name="handoff_resume_message" class="form-control form-control-sm"
                                   value="<?= sanitize($config['handoff_resume_message'] ?? '') ?>"
                                   placeholder="Leave blank to say nothing">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Notify this WhatsApp number</label>
                            <input type="text" name="handoff_notify_number" inputmode="numeric"
                                   class="form-control form-control-sm<?= isset($errors['handoff_notify_number']) ? ' is-invalid' : '' ?>"
                                   value="<?= sanitize($config['handoff_notify_number'] ?? '') ?>"
                                   placeholder="923001234567">
                            <?php if (isset($errors['handoff_notify_number'])): ?>
                                <div class="invalid-feedback d-block"><?= sanitize($errors['handoff_notify_number']) ?></div>
                            <?php endif; ?>
                            <div class="form-text">
                                <?php // #26: this is deliberately NOT the linked account. Said here
                                      // because the obvious guess — "my WhatsApp number" — would have
                                      // the bot alerting the very phone it is running on. ?>
                                A colleague's phone, <strong>not</strong> your linked account — the bot never
                                answers messages from this number. Country code, no leading zero.
                                Each alert counts as one of your messages.
                                <?php if (trim((string)($config['handoff_notify_number'] ?? '')) === '' && $adminNotifyNumber !== ''): ?>
                                    <div class="text-muted x-small mt-1">
                                        Blank, so alerts currently go to the number your administrator set.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small <?= $smtpReady ? '' : 'text-muted' ?>">Notify this email</label>
                            <?php // Disabled, not merely footnoted, when the instance cannot send
                                  // mail. A tenant who types an address into a live-looking field
                                  // reasonably believes they will be emailed; the eight-word note
                                  // that used to sit under it did not stop that. ?>
                            <input type="email" name="handoff_notify_email" class="form-control form-control-sm"
                                   value="<?= sanitize($config['handoff_notify_email'] ?? '') ?>"
                                   <?= $smtpReady ? '' : 'disabled' ?>>
                            <?php if ($smtpReady): ?>
                                <div class="form-text">Emailed as soon as someone asks for a person.</div>
                            <?php else: ?>
                                <div class="form-text text-warning">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    This instance cannot send email yet, so no notification would arrive.
                                    <?php if (isAdmin()): ?>
                                        <a href="<?= APP_URL ?>/admin/email.php">Configure outgoing email</a>.
                                    <?php else: ?>
                                        Ask your administrator to configure outgoing email.
                                    <?php endif; ?>
                                    Use the WhatsApp number above in the meantime.
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php // #26: the number is staff contact detail, so putting it in front of
                              // a customer is an explicit choice, never a default. The switch is
                              // disabled when there is no number to give — an invitation to call a
                              // blank is worse than saying nothing. ?>
                        <?php $shareable = $effectiveNotifyNumber !== ''; ?>
                        <div class="col-12">
                            <hr class="my-2">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="handoff_share_number" value="1"
                                       id="handoff_share_number" <?= !empty($config['handoff_share_number']) ? 'checked' : '' ?>
                                       <?= $shareable && $canHandoff ? '' : 'disabled' ?>>
                                <label class="form-check-label <?= $shareable ? '' : 'text-muted' ?>" for="handoff_share_number">
                                    Give the customer this number when handing over
                                </label>
                            </div>
                            <?php if ($shareable): ?>
                                <div class="form-text">
                                    Added to the message above, so someone in a hurry can call instead of waiting.
                                </div>
                            <?php else: ?>
                                <div class="form-text">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Set a notification number first — there is nothing to share yet.
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label small">How to word it</label>
                            <input type="text" name="handoff_share_message" class="form-control form-control-sm"
                                   value="<?= sanitize($config['handoff_share_message'] ?? '') ?>"
                                   placeholder="You can also reach our team directly on {number}.">
                            <div class="form-text">
                                <code>{number}</code> is replaced with the number above in international form
                                <?php if ($shareable): ?>
                                    (<code>+<?= sanitize($effectiveNotifyNumber) ?></code>)<?php endif; ?>.
                                Leave blank for the default wording.
                            </div>
                        </div>
                    </div>
                </div>

                <?php // #34. The conversation itself lives in a modal now — a
                      // single-shot input that overwrote its own answer could not
                      // show a follow-up, and follow-ups ("yes, 3pm works") are
                      // exactly the behaviour worth testing. This tab is the
                      // launcher and the place that says what the test does and
                      // does not do. ?>
                <div class="tab-pane fade p-3" id="tab-test">
                    <p class="text-muted small mb-3">
                        Opens a conversation with your own bot. It runs the real pipeline against your
                        <strong>saved</strong> settings — same prompt, same business information, same services and
                        opening hours, same model, same limits — and sends nothing to WhatsApp. Nothing is booked
                        and nobody is notified, whatever the bot offers.
                    </p>

                    <?php // The gaps that make a correctly-working bot look broken, named
                          // here and again inside the modal. "The AI doesn't know our
                          // name" is almost always this. ?>
                    <?php if ($testGaps): ?>
                        <div class="alert alert-warning small">
                            <div class="fw-500 mb-1">
                                <i class="bi bi-exclamation-triangle me-1"></i>Worth fixing before you judge the answers
                            </div>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($testGaps as $gap): ?>
                                    <li><?= $gap['html'] ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <button class="btn btn-primary" type="button" id="openTestChat"
                            data-bs-toggle="modal" data-bs-target="#testChatModal">
                        <i class="bi bi-chat-dots me-1"></i>Test your chatbot
                    </button>
                </div>
            </div>

            <div class="mt-3">
                <button class="btn btn-primary" type="submit">Save settings</button>
            </div>
        </form>

        <?php // "Remove my key" posts through this form, not through chatbotForm.
              //
              // It used to be a `type="submit" name="action" value="clear_key"`
              // button inside chatbotForm, and being the *first* submit button in
              // that form made it the default one: pressing Enter in any field —
              // the API key box, the knowledge base — cleared the key and
              // redirected, discarding every unsaved edit on the page. A button
              // outside the form it submits (via the form= attribute) cannot
              // become chatbotForm's default. ?>
        <form method="post" id="clearKeyForm" class="d-none" data-ajax>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="clear_key">
        </form>

        <?php // Separate forms, not nested ones: nesting is invalid HTML and the
              // browser silently drops the inner form's fields. ?>
        <div class="card mt-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list-check me-2"></i>Services</span>
                <?php // The href is the anchor of the form below, so with JavaScript
                      // off this is still a working link to a real form (#24). ?>
                <a href="#serviceCard" class="btn btn-sm btn-primary"
                   data-modal-target="#serviceModal" data-modal-reset="on"
                   data-modal-title="Add a service" data-field-service-id=""
                   data-field-service-minutes="30">
                    <i class="bi bi-plus-lg me-1"></i>Add service
                </a>
            </div>
            <div class="card-body">
                <?php if ($services): ?>
                    <table class="table table-sm align-middle mb-0">
                        <tbody>
                        <?php foreach ($services as $s): ?>
                            <tr class="<?= $s['is_active'] ? '' : 'opacity-50' ?>">
                                <td>
                                    <div class="small fw-500"><?= sanitize($s['name']) ?></div>
                                    <?php if ($s['description']): ?>
                                        <div class="x-small text-muted"><?= sanitize($s['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted"><?= (int)$s['duration_minutes'] ?> min</td>
                                <td class="text-end">
                                    <div class="d-flex gap-1 justify-content-end">
                                        <?php // Editing was not possible at all before: apptSaveService()
                                              // has always taken an id, but nothing on the page ever sent
                                              // one, so a typo in a service name meant disabling it and
                                              // adding another. ?>
                                        <a href="#serviceCard" class="btn btn-sm btn-outline-primary"
                                           data-modal-target="#serviceModal"
                                           data-modal-title="Edit service"
                                           data-field-service-id="<?= (int)$s['id'] ?>"
                                           data-field-service-name="<?= sanitize($s['name']) ?>"
                                           data-field-service-minutes="<?= (int)$s['duration_minutes'] ?>"
                                           data-field-service-description="<?= sanitize((string)$s['description']) ?>">Edit</a>
                                        <form method="post" class="d-inline" data-ajax>
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_service">
                                            <input type="hidden" name="service_id" value="<?= (int)$s['id'] ?>">
                                            <input type="hidden" name="enable" value="<?= $s['is_active'] ? '0' : '1' ?>">
                                            <button class="btn btn-outline-secondary btn-sm" type="submit"
                                                <?php // Only disabling is confirmed. Enabling is not
                                                      // destructive, and a dialog on a harmless action
                                                      // teaches people to dismiss dialogs. ?>
                                                <?= $s['is_active'] ? 'data-confirm="Disable this service? The bot will stop offering it, and existing bookings are unaffected."' : '' ?>>
                                                <?= $s['is_active'] ? 'Disable' : 'Enable' ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-muted small mb-0">No services yet. The bot cannot take a booking without one.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header"><i class="bi bi-clock me-2"></i>Opening hours</div>
            <div class="card-body">
                <p class="text-muted small">
                    Bookings are only accepted inside these windows, and an appointment must
                    <em>finish</em> before you close. Times are <?= sanitize($tz) ?>.
                    <?php if (!$hasSchedule): ?>
                        <br><strong>Nothing saved yet</strong> — the week below is pre-filled with
                        Monday to Friday, 9 to 5. Adjust it and save, or save as it is.
                    <?php endif; ?>
                </p>
                <form method="post" data-ajax id="hoursForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_hours">
                    <?php foreach (apptWeekdayNames() as $num => $name):
                        $dayWindows = $hoursByDay[$num] ?? [];
                        $isOpen = $dayWindows !== [];
                        // A closed day still renders one blank row, hidden. The
                        // alternative — building the row in JS when the toggle is
                        // switched on — means the row markup exists twice.
                        if (!$isOpen) $dayWindows = [['start_time' => '09:00', 'end_time' => '17:00']];
                        ?>
                        <div class="border-bottom py-2" data-day-row="<?= $num ?>">
                            <div class="d-flex align-items-center justify-content-between">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" id="day_on_<?= $num ?>"
                                           name="day_enabled[<?= $num ?>]" value="1" <?= $isOpen ? 'checked' : '' ?>
                                           data-day-toggle="<?= $num ?>">
                                    <label class="form-check-label small fw-500" for="day_on_<?= $num ?>"><?= $name ?></label>
                                </div>
                                <button type="button" class="btn btn-link btn-sm p-0 x-small"
                                        data-add-slot="<?= $num ?>" <?= $isOpen ? '' : 'hidden' ?>>
                                    + another window
                                </button>
                            </div>
                            <div data-day-slots="<?= $num ?>" class="mt-2" <?= $isOpen ? '' : 'hidden' ?>>
                                <?php foreach ($dayWindows as $w): ?>
                                    <div class="row g-2 align-items-center mb-1" data-slot>
                                        <div class="col-5 col-md-4">
                                            <input type="time" class="form-control form-control-sm"
                                                   name="day[<?= $num ?>][start][]" value="<?= sanitize($w['start_time']) ?>">
                                        </div>
                                        <div class="col-5 col-md-4">
                                            <input type="time" class="form-control form-control-sm"
                                                   name="day[<?= $num ?>][end][]" value="<?= sanitize($w['end_time']) ?>">
                                        </div>
                                        <div class="col-2">
                                            <?php // Only ever removes a row from the form. The day is
                                                  // closed by its toggle, not by deleting every window,
                                                  // so this cannot leave an ambiguous state. ?>
                                            <button type="button" class="btn btn-link btn-sm text-danger p-0"
                                                    data-remove-slot title="Remove this window">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-text my-2">
                        Turn a day off to close it. Windows on the same day must not overlap, and an end
                        time must be after its start — a save that breaks either is refused rather than
                        silently dropping the row.
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit">Save hours</button>
                </form>
            </div>
        </div>

        <?php // The service form, rendered once as an ordinary card and promoted
              // into a modal by forms.js (#24).
              //
              // Not written as a literal `<div class="modal">`: Bootstrap's CSS
              // hides .modal whether or not its JavaScript ever runs, so a
              // hand-written modal is a form nobody without JavaScript can reach.
              // The shell mechanism leaves this a visible, working card in that
              // case — which is what the "Add service" link's href points at. ?>
        <div class="card mt-3" id="serviceCard" data-modal-shell="serviceModal"
             data-modal-title="Add a service">
            <div class="card-header">Add or edit a service</div>
            <div class="card-body">
                <form method="post" id="serviceForm" data-ajax>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_service">
                    <?php // Empty means "create". apptSaveService() applies the plan's
                          // service cap to a create and never to an edit, so a tenant
                          // over their limit after a downgrade can still fix a typo. ?>
                    <input type="hidden" name="service_id" value="">
                    <div class="row g-2">
                        <div class="col-md-5">
                            <label class="form-label small" for="svcName">Name</label>
                            <input type="text" id="svcName" name="service_name" class="form-control form-control-sm"
                                   placeholder="Consultation" maxlength="100" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small" for="svcMinutes">Length</label>
                            <div class="input-group input-group-sm">
                                <input type="number" id="svcMinutes" name="service_minutes" class="form-control"
                                       value="30" min="5" max="480">
                                <span class="input-group-text">min</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small" for="svcDesc">Description</label>
                            <input type="text" id="svcDesc" name="service_description"
                                   class="form-control form-control-sm" maxlength="255">
                        </div>
                    </div>
                    <div class="form-text mb-3">
                        The length drives the slot maths, so it has to be the real length. The
                        description is optional and the bot may repeat it to a customer.
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Save service</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header">This month</div>
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <span class="text-muted small">AI replies sent</span>
                    <span class="fw-600">
                        <?= number_format($repliesThisMonth) ?><?php if ($replyLimit !== null): ?>
                            <span class="text-muted fw-normal">/ <?= number_format($replyLimit) ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if ($replyLimit !== null): ?>
                    <?php $pct = $replyLimit > 0 ? min(100, (int)round($repliesThisMonth / $replyLimit * 100)) : 100; ?>
                    <div class="progress mt-2" style="height:6px;">
                        <div class="progress-bar bg-<?= $pct >= 100 ? 'danger' : ($pct >= 80 ? 'warning' : 'primary') ?>"
                             style="width: <?= $pct ?>%"></div>
                    </div>
                    <?php if ($replyQuotaExhausted): ?>
                        <div class="form-text text-danger">
                            You have used your AI replies for this month. The bot has stopped answering
                            and will resume next month — or <a href="<?= APP_URL ?>/billing.php">upgrade</a>.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="form-text">
                    <?php // Two allowances, and both apply. Saying so here is the only place a
                          // tenant can see why the bot stopped while messages remain. ?>
                    Each AI reply also counts as a message, so it uses your monthly message
                    allowance as well<?= $replyLimit === null ? '' : ' — whichever runs out first stops the bot' ?>.
                    <?php if ($usingOwnKey): ?>
                        Replies on your own API key are not counted against a plan limit.
                    <?php endif; ?>
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
                        <?php // The raw `detail` is internal text, so it moves to the row's
                              // tooltip and the hint takes its place — same information density,
                              // but the visible line now says what to do about it. ?>
                        <?php foreach ($events as $e):
                            $hint = chatbotOutcomeHint($e['outcome']); ?>
                            <li class="list-group-item py-2" <?= !empty($e['detail']) ? 'title="' . sanitize($e['detail']) . '"' : '' ?>>
                                <div class="d-flex justify-content-between align-items-start">
                                    <span class="small"><?= sanitize(chatbotOutcomeLabel($e['outcome'])) ?></span>
                                    <span class="x-small text-muted"><?= sanitize(convertToUserTz($e['created_at'], $tz)) ?></span>
                                </div>
                                <?php if ($hint !== ''): ?>
                                    <div class="x-small text-muted"><?= sanitize($hint) ?></div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php // #34. The test conversation.
      //
      // Written as a literal `.modal` here, unlike the service form above, and
      // the difference is the point: `data-modal-shell` exists so a *form* stays
      // reachable without JavaScript, because Bootstrap's CSS hides `.modal`
      // whether its JS runs or not. This is not a form. The whole feature is a
      // fetch() loop — there is nothing here for a browser without JavaScript to
      // fall back to, so hiding it from one is correct rather than a gap. The
      // launcher button is inside the same `$hasChatbot` branch, so a plan
      // without the chatbot never renders either. ?>
<div class="modal fade test-chat-modal" id="testChatModal" tabindex="-1"
     aria-labelledby="testChatTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <?php // A WhatsApp-style header rather than a page heading: the avatar and
                  // the status line are what tell you, at a glance, which bot you are
                  // talking to and whether it would really be answering. ?>
            <div class="modal-header test-chat-header">
                <div class="test-chat-identity">
                    <div class="test-chat-avatar"><i class="bi bi-robot"></i></div>
                    <div class="min-w-0">
                        <h5 class="modal-title" id="testChatTitle">
                            <?= sanitize(trim((string)($testProfile['company_name'] ?? '')) !== ''
                                ? $testProfile['company_name'] : 'Your chatbot') ?>
                        </h5>
                        <div class="test-chat-subtitle">
                            <?php if (!empty($config['is_enabled'])): ?>
                                <span class="test-chat-dot is-live"></span>Live · answers real customers
                            <?php else: ?>
                                <span class="test-chat-dot is-off"></span>Replies switched off · test only
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="test-chat-header-actions">
                    <button type="button" class="btn btn-sm btn-light" id="testChatReset"
                            title="Clear the conversation and start again">
                        <i class="bi bi-arrow-counterclockwise"></i><span class="d-none d-sm-inline ms-1">Start over</span>
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>

            <?php // Standing facts, pinned once.
                  //
                  // These used to be appended into the transcript after every turn,
                  // which turned a conversation into a column of identical yellow
                  // bars. A configuration problem does not change between messages,
                  // so it is stated once, above the conversation, and collapsed —
                  // the count is visible without the wall of text. ?>
            <?php $gapCount = count($testGaps); ?>
            <div class="test-chat-pinned" id="testChatPinned">
                <?php if ($gapCount): ?>
                    <details class="test-chat-gaps">
                        <summary>
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <?= $gapCount ?> thing<?= $gapCount === 1 ? '' : 's' ?> will limit what this bot can answer
                        </summary>
                        <ul>
                            <?php foreach ($testGaps as $gap): ?>
                                <li><?= $gap['html'] ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
                <div class="test-chat-note is-info d-none" id="testChatUnsaved">
                    <i class="bi bi-info-circle-fill"></i>
                    <span>Unsaved changes on this page — this conversation uses your <strong>last saved</strong> settings.</span>
                </div>
                <?php // Config-scope notices from the server land here, deduplicated. ?>
                <div id="testChatStanding"></div>
            </div>

            <div class="modal-body p-0">
                <?php // Reuses .msg-row / .chat-bubble / .bubble-content from the real
                      // conversation view, so a tenant recognises what they are looking
                      // at — the question this whole feature answers is "what will my
                      // customer see". ?>
                <div class="test-chat-thread" id="testChatThread" aria-live="polite" aria-atomic="false">
                    <div class="test-chat-empty" id="testChatPlaceholder">
                        <div class="test-chat-empty-icon"><i class="bi bi-chat-square-text"></i></div>
                        <p class="test-chat-empty-title">Talk to your bot the way a customer would</p>
                        <p class="test-chat-empty-body">
                            It answers from your saved settings and sends nothing to WhatsApp.
                            Nothing is booked and nobody is notified, whatever it offers.
                        </p>
                        <?php // Real starting points, not decoration: each one exercises a
                              // different half of the prompt — the knowledge base, the
                              // opening hours, and the booking instructions. ?>
                        <div class="test-chat-suggestions">
                            <button type="button" class="test-chat-chip" data-suggest="What do you offer?">What do you offer?</button>
                            <button type="button" class="test-chat-chip" data-suggest="What are your opening hours?">What are your opening hours?</button>
                            <?php if ($canAppointments): ?>
                                <button type="button" class="test-chat-chip" data-suggest="Can I book an appointment?">Can I book an appointment?</button>
                            <?php endif; ?>
                            <?php if (!empty($config['handoff_enabled'])): ?>
                                <button type="button" class="test-chat-chip" data-suggest="Can I speak to a person?">Can I speak to a person?</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer test-chat-footer">
                <div class="test-chat-composer">
                    <input type="text" class="form-control" id="testChatInput" autocomplete="off"
                           placeholder="Type a message…" aria-label="Test message">
                    <button class="btn-send" type="button" id="testChatSend" aria-label="Send">
                        <i class="bi bi-send-fill"></i>
                    </button>
                </div>
                <div class="test-chat-footnote">
                    Saved settings · times in <?= sanitize($tz) ?> · nothing is sent to WhatsApp
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Per-day opening hours (#25). Presentation only: the server decides what a
// closed day means (no windows for it) and validates every window, so a browser
// with JavaScript off still gets a usable form — the toggles are real checkboxes
// and the pre-rendered rows are real inputs.
(function () {
    var form = document.getElementById('hoursForm');
    if (!form) return;

    function setDayOpen(day, open) {
        form.querySelectorAll('[data-day-slots="' + day + '"], [data-add-slot="' + day + '"]')
            .forEach(function (el) { el.hidden = !open; });
    }

    form.addEventListener('change', function (e) {
        var toggle = e.target.closest('[data-day-toggle]');
        if (toggle) setDayOpen(toggle.dataset.dayToggle, toggle.checked);
    });

    form.addEventListener('click', function (e) {
        var add = e.target.closest('[data-add-slot]');
        if (add) {
            // Cloned from the day's own first row rather than built from a
            // template string: the field names carry the weekday index, and a
            // template would have to reproduce them correctly a second time.
            var slots = form.querySelector('[data-day-slots="' + add.dataset.addSlot + '"]');
            var copy = slots.querySelector('[data-slot]').cloneNode(true);
            copy.querySelectorAll('input').forEach(function (input) { input.value = ''; });
            copy.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
            slots.appendChild(copy);
            return;
        }

        var remove = e.target.closest('[data-remove-slot]');
        if (remove) {
            var row = remove.closest('[data-slot]');
            var day = row.parentNode;
            // The last row is emptied, not deleted: a day with no row at all
            // cannot be filled in again without reloading, and "closed" is the
            // toggle's job.
            if (day.querySelectorAll('[data-slot]').length > 1) row.remove();
            else row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
        }
    });
})();

/* The test conversation (#34).
 *
 * Was a single input and one output div that overwrote itself on every send, so
 * only the latest reply ever existed and every message was turn one. The
 * follow-ups are the interesting part — "yes, 3pm works", "the second one" — and
 * they were the exact thing that could not be tested.
 *
 * The transcript is therefore not decoration: it is the request payload. Each
 * send posts the whole conversation back as `history`, in the backend's own
 * message shape (fromMe / text), which is what makes multi-turn behaviour and
 * the `history_messages` setting genuinely testable. The server clamps it.
 */
(function () {
    var modal = document.getElementById('testChatModal');
    if (!modal) return;

    var thread   = document.getElementById('testChatThread');
    var input    = document.getElementById('testChatInput');
    var send     = document.getElementById('testChatSend');
    var reset    = document.getElementById('testChatReset');
    var blank    = document.getElementById('testChatPlaceholder');
    var unsaved  = document.getElementById('testChatUnsaved');
    var standing = document.getElementById('testChatStanding');
    var settingsForm = document.getElementById('chatbotForm');

    // Session-scoped: the transcript survives closing and reopening the dialog
    // within the page, and a reload starts fresh. Nothing is persisted
    // server-side — a test conversation is not a record of anything, and storing
    // it would put customer-shaped text in the database for nobody to read.
    var turns = [];
    var busy = false;
    // Config-scope notices already pinned, so the same standing fact is never
    // shown twice however many messages are sent.
    var seenStanding = Object.create(null);

    function scrollToEnd() { thread.scrollTop = thread.scrollHeight; }

    function clearEmptyState() {
        if (blank) { blank.remove(); blank = null; }
    }

    // Built with DOM nodes and textContent, never innerHTML — except for the
    // reply body, which goes through formatMessageText(), the same sanitising
    // formatter the real chat view uses (it escapes before it formats).
    function addBubble(text, outgoing) {
        clearEmptyState();

        var row = document.createElement('div');
        row.className = 'msg-row ' + (outgoing ? 'msg-out' : 'msg-in');

        var bubble = document.createElement('div');
        bubble.className = 'chat-bubble ' + (outgoing ? 'outgoing' : 'incoming');

        var content = document.createElement('div');
        content.className = 'bubble-content';
        content.innerHTML = formatMessageText(text);
        bubble.appendChild(content);

        row.appendChild(bubble);
        thread.appendChild(row);
        scrollToEnd();
        return bubble;
    }

    // Timing goes *inside* the bubble, bottom-right, where WhatsApp puts its own
    // timestamp. It used to be a separate block under the bubble, which read as
    // another message and doubled the vertical space every reply took.
    //
    // "1,300 tokens" means nothing to a business owner testing their own bot, so
    // the plain reading is the label and the numbers a developer or support needs
    // are behind a title attribute rather than an always-open <details>.
    function addMeta(bubble, data) {
        var ms = Number(data.latency_ms || 0);
        if (!ms && !data.model) return;

        var meta = document.createElement('div');
        meta.className = 'bubble-meta test-chat-meta';
        var speed = ms < 2000 ? 'quick' : (ms < 6000 ? 'normal' : 'slow');
        meta.textContent = (ms / 1000).toFixed(1) + 's · ' + speed;
        meta.title = (data.model || 'model not reported') + ' · ' + ms + ' ms · '
            + (data.tokens || 0) + ' tokens';
        bubble.appendChild(meta);
    }

    function noteNode(n, compact) {
        var level = n.level === 'warning' ? 'warn' : (n.level === 'success' ? 'ok' : 'info');
        var icon = level === 'warn' ? 'bi-exclamation-triangle-fill'
                 : (level === 'ok' ? 'bi-check-circle-fill' : 'bi-info-circle-fill');

        var el = document.createElement('div');
        el.className = 'test-chat-note is-' + level + (compact ? ' is-inline' : '');
        var i = document.createElement('i');
        i.className = 'bi ' + icon;
        var span = document.createElement('span');
        span.textContent = n.text;
        el.appendChild(i);
        el.appendChild(span);
        return el;
    }

    // 'config' notices are standing facts — true of every message — so they are
    // pinned above the conversation and deduplicated. 'turn' notices were caused
    // by the message just sent and belong beside it. Anything untagged is treated
    // as a turn notice, so an older server cannot silently pin something forever.
    function addNotices(list) {
        (list || []).forEach(function (n) {
            if (n.scope === 'config') {
                if (seenStanding[n.text]) return;
                seenStanding[n.text] = true;
                standing.appendChild(noteNode(n, false));
                return;
            }
            clearEmptyState();
            thread.appendChild(noteNode(n, true));
        });
        scrollToEnd();
    }

    function setBusy(state) {
        busy = state;
        send.disabled = state;
        input.disabled = state;
        send.innerHTML = state
            ? '<span class="spinner-border spinner-border-sm"></span>'
            : '<i class="bi bi-send-fill"></i>';
    }

    function addTyping() {
        clearEmptyState();
        var row = document.createElement('div');
        row.className = 'msg-row msg-in';
        row.id = 'testChatTyping';
        row.innerHTML = '<div class="chat-bubble incoming test-chat-typing">'
            + '<span></span><span></span><span></span></div>';
        thread.appendChild(row);
        scrollToEnd();
    }

    function removeTyping() {
        var t = document.getElementById('testChatTyping');
        if (t) t.remove();
    }

    function submit(text) {
        if (busy) return;
        text = (text !== undefined ? text : input.value).trim();
        if (!text) return;

        addBubble(text, true);
        input.value = '';
        setBusy(true);
        addTyping();

        // Posted *before* this turn is appended to `turns`: the endpoint adds the
        // message being answered itself, and sending it in the history as well
        // would show the model the same line twice.
        var payload = {
            csrf_token: '<?= csrfToken() ?>',
            message: text,
            history: turns.slice()
        };
        turns.push({ fromMe: false, text: text });

        fetch('<?= APP_URL ?>/ajax/chatbot-test.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                removeTyping();
                setBusy(false);
                input.focus();

                if (!data.ok) {
                    // The failed turn is dropped from the payload: there is no
                    // reply to pair it with, and leaving it in would make the next
                    // request look like the bot ignored a message.
                    turns.pop();
                    addNotices([{ scope: 'turn', level: 'warning', text: data.error || 'The test failed.' }]);
                    return;
                }

                var bubble = addBubble(data.reply, false);
                addMeta(bubble, data);
                addNotices(data.notices);
                // fromMe is the bot's own outgoing message, which is what the live
                // path's history looks like — chatbotBuildMessages() maps it to the
                // assistant role.
                turns.push({ fromMe: true, text: data.reply });
            })
            .catch(function () {
                removeTyping();
                setBusy(false);
                turns.pop();
                addNotices([{ scope: 'turn', level: 'warning', text: 'Could not reach the server. Try again.' }]);
            });
    }

    send.addEventListener('click', function () { submit(); });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); submit(); }
    });

    // The empty-state chips are real first messages, not placeholder text.
    thread.addEventListener('click', function (e) {
        var chip = e.target.closest('[data-suggest]');
        if (chip) submit(chip.dataset.suggest);
    });

    reset.addEventListener('click', function () {
        turns = [];
        thread.innerHTML = '';
        blank = null;
        // Standing notices are deliberately kept: clearing the conversation does
        // not fix the configuration, and re-hiding the reason the bot cannot book
        // would be actively misleading.
        addNotices([{ scope: 'turn', level: 'info', text: 'Conversation cleared. The bot has no memory of it.' }]);
        input.focus();
    });

    // The endpoint reads *saved* config, because it has to run what the live path
    // would run. The old tab said "Save your changes first" and left it at that,
    // which does not stop anyone testing stale settings and drawing the wrong
    // conclusion. This says which settings the conversation is actually using.
    var dirty = false;
    if (settingsForm) {
        settingsForm.addEventListener('input', function () { dirty = true; });
        settingsForm.addEventListener('change', function () { dirty = true; });
        // A successful AJAX save reloads nothing, so the flag has to be cleared
        // here or the warning would outlive the change it describes.
        settingsForm.addEventListener('submit', function () { dirty = false; });
    }

    modal.addEventListener('shown.bs.modal', function () {
        if (unsaved) unsaved.classList.toggle('d-none', !dirty);
        input.focus();
        scrollToEnd();
    });
})();
</script>

<?php // Deep links to a single tab (#41).
      //
      // Bootstrap does not read the fragment on load, so `settings.php#tab-appointments`
      // used to land on the Knowledge base tab like every other visit — which made
      // the dashboard checklist's "Add a bookable service" link drop the tenant on
      // a page with no visible services and nothing explaining where they were.
      // Also what makes the chatbot.php redirect keep its destination: a browser
      // reapplies the original fragment when the redirect target has none.
      //
      // Both spellings are accepted. The links written before the tabs existed use
      // `#appointments`, and rewriting history in every stored URL is not possible.
      ?>
<script>
(function () {
    function openTabFromHash() {
        var hash = (window.location.hash || '').replace(/^#/, '');
        if (!hash) return;
        var id = hash.indexOf('tab-') === 0 ? hash : 'tab-' + hash;
        var pane = document.getElementById(id);
        if (!pane || !pane.classList.contains('tab-pane')) return;
        var trigger = document.querySelector('[data-bs-target="#' + id + '"]');
        if (trigger && window.bootstrap) {
            window.bootstrap.Tab.getOrCreateInstance(trigger).show();
            // The tab bar is above the fold; the browser has already tried to
            // scroll to a fragment it could not resolve as a heading.
            window.scrollTo(0, 0);
        }
    }

    openTabFromHash();
    window.addEventListener('hashchange', openTabFromHash);
})();
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
