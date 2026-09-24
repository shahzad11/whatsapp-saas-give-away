<?php
// Instance settings — the platform owner's configuration, applied sitewide.
//
// Each of these was an environment variable first. A saved value here overrides
// the env; a blank/absent one falls back to it, so the deployment's .env stays
// the floor and clearing a field cannot leave the instance unconfigured.
require_once dirname(__DIR__) . '/includes/admin-init.php';

$errors = [];

$current = [
    'currency'              => appCurrency($conn),
    'timezone'              => appTimezone($conn),
    'default_plan_code'     => defaultPlanCode($conn),
    'login_max_attempts'    => loginMaxAttempts($conn),
    'login_lockout_minutes' => loginLockoutMinutes($conn),
    'chatbot_loop_max_replies' => chatbotLoopMaxReplies($conn),
    'billing_grace_days'    => billingGraceDays($conn),
    'audit_retention_days'  => auditRetentionDays($conn),
    'payment_instructions'  => paymentInstructions($conn),
    // #26. Blank is a legitimate value here — it simply means tenants who set no
    // number of their own get no WhatsApp alert — so this one is read raw rather
    // than through an accessor with a fallback.
    'handoff_notify_number' => (string)(overrideSetting($conn, 'handoff_notify_number') ?? ''),
    // #43. Read raw, not through billingContactConfig(): that helper drops
    // anything unusable so a tenant never sees a broken button, and a form that
    // silently dropped what the admin typed would give them no way to fix it.
    'billing_contact_methods' => array_filter(array_map('trim',
        explode(',', (string)(overrideSetting($conn, 'billing_contact_methods') ?? '')))),
    'billing_contact_email'   => (string)(overrideSetting($conn, 'billing_contact_email') ?? ''),
    'billing_contact_phone'   => (string)(overrideSetting($conn, 'billing_contact_phone') ?? ''),
    'billing_contact_label'   => (string)(overrideSetting($conn, 'billing_contact_label') ?? ''),
    'billing_contact_primary' => (string)(overrideSetting($conn, 'billing_contact_primary') ?? ''),
];

$plans = $conn->query("SELECT code, name, is_active FROM plans ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

// How many plan rows are priced in the currency we are moving away from. Shown
// on the form so the admin knows the blast radius before choosing.
function plansInCurrency(mysqli $conn, $code) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM plans WHERE currency = ? AND price_cents > 0");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

// Ends at formRespond(), so the page saves in place when JavaScript is on and
// redirects exactly as before when it is not. See includes/ajax.php.
$self = APP_URL . '/admin/settings.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    formRequireCsrf($self);

    // --- Lead generation (#50) ----------------------------------------------
    //
    // Its own form and its own actions, handled before the main save and
    // exiting through formRespond() like everything else. Kept apart because
    // the key is a secret: the instance settings form round-trips every one of
    // its values through the page, and an API key must never be one of them.
    $action = $_POST['action'] ?? '';

    if ($action === 'save_serpapi') {
        $serpErrors = [];

        // Blank means "keep what is stored", exactly as the LLM provider and
        // SMTP password fields work. Without that rule, opening the page and
        // pressing Save would wipe a working key, because the field cannot be
        // pre-filled with a secret.
        $newKey = trim((string)($_POST['serpapi_key'] ?? ''));
        if ($newKey !== '' && !cryptoSecretAvailable()) {
            formRespond(false, cryptoSecretMissingMessage(), $self,
                ['serpapi_key' => 'This instance cannot encrypt secrets, so the key was not stored.']);
        }

        $dial = preg_replace('/\D+/', '', (string)($_POST['serpapi_dial_code'] ?? ''));
        if ($dial === '' || strlen($dial) > 4) {
            $serpErrors['serpapi_dial_code'] = 'Digits only, e.g. 92 for Pakistan or 44 for the UK.';
        }

        $hl = strtolower(trim((string)($_POST['serpapi_hl'] ?? '')));
        if (!preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,3})?$/', $hl)) {
            $serpErrors['serpapi_hl'] = 'A language code like en, ur, or en-gb.';
        }

        // Kilometres in the form, metres in the column. The unit changed, the
        // stored key did not: `serpapi_radius_m` is still metres, so no migration
        // and no instance quietly re-reading 20000 as 20000 km.
        $radiusKm = (int)($_POST['serpapi_radius_km'] ?? 0);
        if ($radiusKm < LEADS_MIN_RADIUS_KM || $radiusKm > LEADS_MAX_RADIUS_KM) {
            // The floor is not fussiness: SerpApi accepts 1 metre, and a search
            // with a one-metre radius silently returns almost nothing while
            // still costing a credit.
            $serpErrors['serpapi_radius_km'] = 'Between ' . LEADS_MIN_RADIUS_KM
                . ' and ' . LEADS_MAX_RADIUS_KM . ' kilometres.';
        }
        $radius = leadsRadiusKmToM($radiusKm);

        // The area every lead search starts from. Required, because a blank one
        // is what makes Google answer from its own datacentre instead — the bug
        // that filled the leads table with businesses in Virginia.
        $serpLocation = trim((string)($_POST['serpapi_location'] ?? ''));
        if ($serpLocation === '' || mb_strlen($serpLocation) > 200) {
            $serpErrors['serpapi_location'] = 'An area like "Lahore, Pakistan". It cannot be empty.';
        }

        if ($serpErrors) {
            formRespond(false, 'Please correct the highlighted fields.', $self, $serpErrors);
        }

        if ($newKey !== '') {
            $encrypted = encryptSecret($newKey);
            if ($encrypted === null) {
                formRespond(false, cryptoSecretMissingMessage(), $self,
                    ['serpapi_key' => 'The key was not saved.']);
            }
            setAppSetting($conn, 'serpapi_key_enc', $encrypted);
        }
        setAppSetting($conn, 'serpapi_dial_code', $dial);
        setAppSetting($conn, 'serpapi_hl', $hl);
        setAppSetting($conn, 'serpapi_radius_m', (string)$radius);
        setAppSetting($conn, 'serpapi_location', $serpLocation);

        // Never the key itself — only that one was replaced.
        logAudit($conn, 'admin.serpapi.update', 'app_settings', null, [
            'key_replaced' => $newKey !== '', 'dial_code' => $dial, 'hl' => $hl,
            'radius_m' => $radius, 'location' => $serpLocation,
        ]);
        formRespond(true, 'Lead search settings saved.', $self);
    }

    if ($action === 'test_serpapi') {
        // The form's own value is tested when one was typed, so the result
        // describes the key the admin is looking at rather than the stored one.
        $candidate = trim((string)($_POST['serpapi_key'] ?? ''));
        [$ok, $message] = leadsTestKey($conn, $candidate !== '' ? $candidate : null);
        logAudit($conn, 'admin.serpapi.test', 'app_settings', null, ['ok' => $ok]);
        formRespond($ok, $message, $self);
    }

    $currency = strtoupper(trim($_POST['currency'] ?? ''));
    if (!isValidCurrency($currency) || !array_key_exists($currency, currencyFormats())) {
        $errors['currency'] = 'Select a currency from the list.';
    }

    $timezone = trim($_POST['timezone'] ?? '');
    if (!isValidTimezone($timezone)) {
        $errors['timezone'] = 'Select a timezone from the list.';
    }

    $planCode = trim($_POST['default_plan_code'] ?? '');
    $planCodes = array_column($plans, 'code');
    if (!in_array($planCode, $planCodes, true)) {
        $errors['default_plan_code'] = 'Select an existing plan.';
    }

    $maxAttempts = (int)($_POST['login_max_attempts'] ?? 0);
    if ($maxAttempts < 1 || $maxAttempts > 100) {
        // 0 would switch throttling off entirely. Refuse rather than quietly
        // accept a value that disables a brute-force control.
        $errors['login_max_attempts'] = 'Must be between 1 and 100.';
    }

    $lockout = (int)($_POST['login_lockout_minutes'] ?? 0);
    if ($lockout < 1 || $lockout > 1440) {
        $errors['login_lockout_minutes'] = 'Must be between 1 and 1440 minutes.';
    }

    $loopMax = (int)($_POST['chatbot_loop_max_replies'] ?? 0);
    if ($loopMax < 1 || $loopMax > 100) {
        // 0 would switch the guard off — and silence is the failure it exists
        // to prevent — so it is refused rather than stored.
        $errors['chatbot_loop_max_replies'] = 'Must be between 1 and 100.';
    }

    $graceDays = (int)($_POST['billing_grace_days'] ?? -1);
    if ($graceDays < 0 || $graceDays > 60) {
        $errors['billing_grace_days'] = 'Must be between 0 and 60 days.';
    }

    $retentionDays = (int)($_POST['audit_retention_days'] ?? 0);
    if ($retentionDays < 30 || $retentionDays > 3650) {
        $errors['audit_retention_days'] = 'Must be at least 30 days.';
    }

    $instructions = trim($_POST['payment_instructions'] ?? '');
    if (mb_strlen($instructions) > 5000) {
        $errors['payment_instructions'] = 'Too long (max 5000 characters).';
    }

    // #26: the instance-wide fallback for handover alerts. Normalised to bare
    // E.164 digits, the same as the tenant's own field, so the two are
    // interchangeable everywhere downstream.
    $notifyNumber = handoffNormaliseNumber($_POST['handoff_notify_number'] ?? '');
    if ($notifyNumber === false) {
        $errors['handoff_notify_number'] = 'Enter a full international number with the country code and no leading zero, e.g. 923001234567.';
        // Nothing is saved when there are errors, so this only affects what the
        // page shows back. It must be what the admin typed: replacing a refused
        // number with '' deletes the thing they were being asked to correct, so
        // the field reads as empty next to a message about a value they can no
        // longer see. $notifyNumber is only read for redisplay from here on.
        $notifyNumber = trim((string)($_POST['handoff_notify_number'] ?? ''));
    }

    // #43: how tenants ask about a plan. Each method is only saved once the
    // detail it needs is there — a WhatsApp button with no number behind it is a
    // dead button on every tenant's billing page, and the admin would have no way
    // to tell from this form that it was dead.
    $contactMethods = [];
    foreach ((array)($_POST['billing_contact_methods'] ?? []) as $method) {
        if (array_key_exists($method, billingContactMethodChoices())) $contactMethods[] = $method;
    }

    $contactEmail = trim($_POST['billing_contact_email'] ?? '');
    if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $errors['billing_contact_email'] = 'Enter a valid email address.';
    } elseif ($contactEmail === '' && in_array('email', $contactMethods, true)) {
        $errors['billing_contact_email'] = 'Add the sales address, or untick Email.';
    }

    $contactPhone = e164Digits($_POST['billing_contact_phone'] ?? '');
    if ($contactPhone === false) {
        $errors['billing_contact_phone'] = 'Enter a full international number with the country code and no leading zero, e.g. 923001234567.';
        // Kept as typed for the redisplay, like the handover number above: a
        // refused value the admin can still see is a value they can fix.
        $contactPhone = trim((string)($_POST['billing_contact_phone'] ?? ''));
    } elseif ($contactPhone === ''
        && (in_array('whatsapp', $contactMethods, true) || in_array('phone', $contactMethods, true))) {
        $errors['billing_contact_phone'] = 'Add the number, or untick WhatsApp and Phone.';
    }

    $contactLabel = trim($_POST['billing_contact_label'] ?? '');
    if (mb_strlen($contactLabel) > 60) {
        // A button label, not a sentence: anything longer wraps out of a plan card.
        $errors['billing_contact_label'] = 'Too long for a button (max 60 characters).';
    }

    // An unusable preference is corrected rather than refused: it is a
    // presentation order, and the admin's real intent — which methods are on — is
    // in the checkboxes.
    $contactPrimary = (string)($_POST['billing_contact_primary'] ?? '');
    if (!in_array($contactPrimary, $contactMethods, true)) $contactPrimary = $contactMethods[0] ?? '';

    if (!$errors) {
        $previousCurrency = $current['currency'];

        setAppSetting($conn, 'currency', $currency);
        setAppSetting($conn, 'timezone', $timezone);
        setAppSetting($conn, 'default_plan_code', $planCode);
        setAppSetting($conn, 'login_max_attempts', (string)$maxAttempts);
        setAppSetting($conn, 'login_lockout_minutes', (string)$lockout);
        setAppSetting($conn, 'chatbot_loop_max_replies', (string)$loopMax);
        setAppSetting($conn, 'billing_grace_days', (string)$graceDays);
        setAppSetting($conn, 'audit_retention_days', (string)$retentionDays);
        setAppSetting($conn, 'payment_instructions', $instructions);
        setAppSetting($conn, 'handoff_notify_number', $notifyNumber);
        setAppSetting($conn, 'billing_contact_methods', implode(',', $contactMethods));
        setAppSetting($conn, 'billing_contact_email', $contactEmail);
        setAppSetting($conn, 'billing_contact_phone', $contactPhone);
        setAppSetting($conn, 'billing_contact_label', $contactLabel);
        setAppSetting($conn, 'billing_contact_primary', $contactPrimary);

        logAudit($conn, 'admin.settings.update', 'app_settings', null, [
            'currency' => $currency,
            'timezone' => $timezone,
            'default_plan_code' => $planCode,
            // Which routes are open, never the address or the number: they are
            // the owner's own contact details and the audit log is read by
            // anyone with admin access.
            'billing_contact_methods' => $contactMethods ? implode(',', $contactMethods) : 'none',
        ]);

        // Repricing plans is a SEPARATE, opt-in action, never a side effect of
        // changing the display currency. A price is denominated in a currency;
        // rewriting the label without converting the amount would misstate what
        // tenants are charged (a $79 plan becoming "Rs. 79"). No exchange rate
        // is available here, so the only honest options are "leave it" or
        // "relabel, and say plainly that is what this does".
        if ($currency !== $previousCurrency && isset($_POST['relabel_plans'])) {
            $stmt = $conn->prepare("UPDATE plans SET currency = ? WHERE currency = ?");
            $stmt->bind_param('ss', $currency, $previousCurrency);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            logAudit($conn, 'admin.plans.relabel_currency', 'plans', null, [
                'from' => $previousCurrency, 'to' => $currency, 'rows' => $affected,
            ]);
            $message = 'Settings saved. ' . $affected . ' ' . ($affected === 1 ? 'plan' : 'plans') . ' relabelled to ' . $currency . ' (amounts unchanged).';
        } else {
            $message = 'Settings saved.';
        }

        // An inactive plan as the signup default is accepted — a deliberately
        // hidden "internal" plan is a legitimate use — but it is never silent.
        // Every new tenant would land on a plan that is not offered anywhere and
        // may have no features, and nothing else on the instance would say why.
        //
        // Flashed separately from the JSON reply on purpose: it is a warning
        // about a state the save has now left the instance in, and the AJAX path
        // carries it in the same sentence rather than losing it.
        foreach ($plans as $p) {
            if ($p['code'] === $planCode && !$p['is_active']) {
                $warning = 'Careful: "' . $p['name'] . '" is an inactive plan. New customers will be put on it, '
                    . 'and it is not shown to customers as an option. Activate it on Plans if that is not intended.';
                if (!isXhrRequest()) flash('error', $warning);
                else $message .= ' ' . $warning;
                break;
            }
        }

        formRespond(true, $message, $self);
    }

    // formErrors(), not formRespond(): the plain-form path must fall through to
    // the render below with these values still in the fields.
    formErrors('Please correct the highlighted fields.', $errors);

    // Keep what was typed for redisplay.
    $current = [
        'currency' => $currency, 'timezone' => $timezone,
        'default_plan_code' => $planCode,
        'login_max_attempts' => $maxAttempts, 'login_lockout_minutes' => $lockout,
        'chatbot_loop_max_replies' => $loopMax, 'billing_grace_days' => $graceDays,
        'audit_retention_days' => $retentionDays,
        'payment_instructions' => $instructions,
        'handoff_notify_number' => (string)$notifyNumber,
        'billing_contact_methods' => $contactMethods,
        'billing_contact_email' => $contactEmail,
        'billing_contact_phone' => (string)$contactPhone,
        'billing_contact_label' => $contactLabel,
        'billing_contact_primary' => $contactPrimary,
    ];
}

function sErr($key) {
    global $errors;
    return empty($errors[$key]) ? '' : '<div class="invalid-feedback d-block">' . sanitize($errors[$key]) . '</div>';
}
function sCls($key) {
    global $errors;
    return empty($errors[$key]) ? '' : ' is-invalid';
}

$repriceable = plansInCurrency($conn, $current['currency']);

// Read for the lead-search card below. The key itself is never read for
// display — only whether one exists, which is what the badge and the
// placeholder need.
$serp = leadsSettings($conn);
$serpConfigured = serpApiConfigured($conn);

$pageTitle = 'Settings';
require_once dirname(__DIR__) . '/includes/admin-header.php';
?>

<div class="row g-4">
    <div class="col-lg-3 d-none d-lg-block">
        <?php // Seven cards, one scroll. The nav mirrors them so a section is one
              // click from the top; it is hidden below lg where stacking it would
              // only lengthen the scroll it was added to shorten. ?>
        <nav class="settings-nav" aria-label="Settings sections">
            <div class="nav-section-title px-3 mb-1">On this page</div>
            <a href="#set-localisation">Regional settings</a>
            <a href="#set-tenants">New customers</a>
            <a href="#set-security">Login security</a>
            <a href="#set-handover">Handover notifications</a>
            <a href="#set-payment">Payment instructions</a>
            <a href="#set-sales">Plan enquiry contact</a>
            <a href="#set-leads">Lead search</a>
        </nav>
    </div>
    <div class="col-lg-9">

<form method="POST" data-ajax>
    <?= csrfField() ?>

    <div class="card mb-4" id="set-localisation">
        <div class="card-header">Regional settings</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Currency</label>
                    <select name="currency" id="currencySelect" class="form-select<?= sCls('currency') ?>"
                            data-original="<?= sanitize($current['currency']) ?>">
                        <?php foreach (currencyChoices() as $code => $label): ?>
                            <option value="<?= $code ?>" <?= $current['currency'] === $code ? 'selected' : '' ?>>
                                <?= sanitize($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">
                        Used for plan prices and billing. Example:
                        <strong><?= sanitize(formatMoney(150000, $current['currency'])) ?></strong>
                    </div>
                    <?= sErr('currency') ?>

                    <?php // Only meaningful when the currency actually changes, so it is
                          // hidden until then by the script at the foot of this page. ?>
                    <div id="relabelWrap" class="alert alert-warning mt-3 mb-0 py-2 d-none">
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" name="relabel_plans" id="relabelPlans">
                            <label class="form-check-label small fw-500" for="relabelPlans">
                                Also relabel <?= (int)$repriceable ?> existing paid <?= (int)$repriceable === 1 ? 'plan' : 'plans' ?> to the new currency
                            </label>
                        </div>
                        <div class="x-small mb-0">
                            This changes the <em>label</em> only — the amounts are not converted, because
                            no exchange rate is available. Leave unchecked to keep existing plans priced
                            in <?= sanitize($current['currency']) ?> and set the new currency for future ones.
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Timezone</label>
                    <select name="timezone" class="form-select<?= sCls('timezone') ?>">
                        <?php foreach (timezoneChoices() as $region => $zones): ?>
                            <optgroup label="<?= sanitize($region) ?>">
                            <?php foreach ($zones as $tz => $label): ?>
                                <option value="<?= sanitize($tz) ?>" <?= $current['timezone'] === $tz ? 'selected' : '' ?>>
                                    <?= sanitize($label) ?>
                                </option>
                            <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">
                        Default for new customers (<?= sanitize(timezoneOffsetLabel($current['timezone'])) ?>).
                        A customer who chooses their own timezone keeps it.
                    </div>
                    <?= sErr('timezone') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4" id="set-tenants">
        <div class="card-header">New customers</div>
        <div class="card-body">
            <?php // The "Allow public sign-ups" switch was here, and is gone (#48).
                  //
                  // It is not merely unticked: register.php, the allow_registration
                  // row, the ALLOW_REGISTRATION constant and allowRegistration()
                  // were all removed, so there is nothing left for a switch to
                  // toggle. Tenants exist because an admin made one. ?>
            <div class="alert alert-secondary py-2 small">
                <i class="bi bi-shield-lock me-1"></i>
                This app is <strong>invite-only</strong>. There is no public registration —
                add customers from <a href="<?= APP_URL ?>/admin/tenants.php">Customers</a>, where you
                can email a password-setup link or hand over a temporary password.
            </div>
            <div class="col-md-5 px-0">
                <label class="form-label">Default plan for new customers</label>
                <select name="default_plan_code" class="form-select<?= sCls('default_plan_code') ?>">
                    <?php foreach ($plans as $p): ?>
                        <option value="<?= sanitize($p['code']) ?>" <?= $current['default_plan_code'] === $p['code'] ? 'selected' : '' ?>>
                            <?= sanitize($p['name']) ?><?= $p['is_active'] ? '' : ' (inactive)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?= sErr('default_plan_code') ?>
                <?php
                // Named here rather than only on save: an instance can already be
                // in this state from an earlier save or from DEFAULT_PLAN_CODE in
                // the env pointing at a plan since deactivated.
                $defaultPlanRow = null;
                foreach ($plans as $p) {
                    if ($p['code'] === $current['default_plan_code']) { $defaultPlanRow = $p; break; }
                }
                ?>
                <div class="form-text">
                    <?php if ($defaultPlanRow && !$defaultPlanRow['is_active']): ?>
                        <span class="text-danger">
                            This plan is <strong>inactive</strong>, so new customers will be assigned a plan
                            that is not offered as an option and may have no features.
                            <a href="<?= APP_URL ?>/admin/plans.php">Review plans</a>.
                        </span>
                    <?php else: ?>
                        Assigned to every customer created by an administrator. Manage plan contents on
                        <a href="<?= APP_URL ?>/admin/plans.php">Plans</a>.
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4" id="set-security">
        <div class="card-header">Login security</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Failed attempts per email before the delay starts</label>
                    <input type="number" name="login_max_attempts" min="1" max="100"
                           class="form-control<?= sCls('login_max_attempts') ?>"
                           value="<?= (int)$current['login_max_attempts'] ?>">
                    <div class="form-text">Past this, each attempt must wait a little longer (2s, 4s, 8s…
                        up to 60s) — a slowdown, not a lockout, so nobody can freeze a customer
                        out by mistyping their email. Browsers that have signed in before are exempt.</div>
                    <?= sErr('login_max_attempts') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Window + IP block (minutes)</label>
                    <input type="number" name="login_lockout_minutes" min="1" max="1440"
                           class="form-control<?= sCls('login_lockout_minutes') ?>"
                           value="<?= (int)$current['login_lockout_minutes'] ?>">
                    <div class="form-text">How long failures are counted for, and how long one source
                        IP is blocked once it floods (30 failures in the window, minimum 10).</div>
                    <?= sErr('login_lockout_minutes') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4" id="set-handover">
        <div class="card-header">Handover notifications</div>
        <div class="card-body">
            <div class="col-md-5 px-0">
                <label class="form-label">Fallback WhatsApp number</label>
                <input type="text" name="handoff_notify_number" inputmode="numeric"
                       class="form-control<?= sCls('handoff_notify_number') ?>"
                       value="<?= sanitize($current['handoff_notify_number']) ?>" placeholder="923001234567">
                <div class="form-text">
                    Used when a customer asks for a person and the account owner has not set a
                    notification number. The account owner’s number takes priority. Enter digits in
                    international format, including the country code, with no leading zero or spaces.
                    <?php // Worth stating: the alert is a real WhatsApp message and is
                          // metered like one, against the tenant whose customer asked. ?>
                    Each alert is sent from the customer’s linked account and counts as one message.
                </div>
                <?= sErr('handoff_notify_number') ?>
            </div>
        </div>
    </div>

    <div class="card mb-4" id="set-automation">
        <div class="card-header">Automation &amp; housekeeping</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Chatbot replies per chat (10 min)</label>
                    <input type="number" name="chatbot_loop_max_replies" min="1" max="100"
                           class="form-control<?= sCls('chatbot_loop_max_replies') ?>"
                           value="<?= (int)$current['chatbot_loop_max_replies'] ?>">
                    <div class="form-text">The loop guard silences a conversation after this many
                        bot replies inside 10 minutes — the shape of two bots talking to each
                        other. It also trips on replies arriving faster than a person can type.</div>
                    <?= sErr('chatbot_loop_max_replies') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Billing grace period (days)</label>
                    <input type="number" name="billing_grace_days" min="0" max="60"
                           class="form-control<?= sCls('billing_grace_days') ?>"
                           value="<?= (int)$current['billing_grace_days'] ?>">
                    <div class="form-text">A paid plan that lapses is marked past due for this
                        many days, then the customer is moved to a free plan automatically.
                        0 expires immediately.</div>
                    <?= sErr('billing_grace_days') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Audit log retention (days)</label>
                    <input type="number" name="audit_retention_days" min="30" max="3650"
                           class="form-control<?= sCls('audit_retention_days') ?>"
                           value="<?= (int)$current['audit_retention_days'] ?>">
                    <div class="form-text">Audit rows older than this are removed by the daily
                        cleanup. Minimum 30 — the log is what an incident investigation reads.</div>
                    <?= sErr('audit_retention_days') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4" id="set-payment">
        <div class="card-header">Payment instructions</div>
        <div class="card-body">
            <label class="form-label">Shown to customers on their billing page</label>
            <textarea name="payment_instructions" rows="5" maxlength="5000"
                      class="form-control<?= sCls('payment_instructions') ?>"
                      placeholder="e.g. Bank transfer to Meezan Bank, account 1234-5678. Email the receipt to billing@example.com and we will activate your plan."><?= sanitize($current['payment_instructions']) ?></textarea>
            <div class="form-text">
                Payments are collected outside this app and recorded manually by an administrator.
                Leave blank to hide this section.
            </div>
            <?= sErr('payment_instructions') ?>
        </div>
    </div>

    <?php // #43. The route a tenant takes to ask about a plan. Every plan card on
          // their billing page shows whatever is switched on here, with the plan's
          // name already in the message — so an enquiry arrives saying which plan
          // it is about. ?>
    <div class="card mb-4" id="set-sales">
        <div class="card-header">Plan enquiry contact</div>
        <div class="card-body">
            <?php if (!$current['billing_contact_methods']): ?>
                <div class="alert alert-warning py-2 small">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Customers currently have no direct way to request a plan change.
                    Select at least one contact method below.
                </div>
            <?php endif; ?>

            <label class="form-label">Methods customers may use</label>
            <div class="row g-3">
                <div class="col-md-4">
                    <?php foreach (billingContactMethodChoices() as $code => $label): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="billing_contact_methods[]"
                                   value="<?= sanitize($code) ?>" id="contactMethod_<?= sanitize($code) ?>"
                                   <?= in_array($code, $current['billing_contact_methods'], true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="contactMethod_<?= sanitize($code) ?>">
                                <?= sanitize($label) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-text">
                        Select every channel your team actively monitors. Each becomes a button on
                        plans the customer is not already using.
                    </div>
                </div>
                <div class="col-md-8" id="salesContactDetails">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Sales email address</label>
                            <input type="email" name="billing_contact_email"
                                   class="form-control<?= sCls('billing_contact_email') ?>"
                                   value="<?= sanitize($current['billing_contact_email']) ?>"
                                   placeholder="sales@example.com">
                            <?php // Said plainly because the old behaviour did exactly this: the
                                  // plan buttons pointed at the SMTP sender address, which on most
                                  // instances is a no-reply mailbox nobody reads. ?>
                            <div class="form-text">
                                Use a monitored mailbox. This is kept separate from the SMTP sender
                                address, which may be a no-reply address.
                            </div>
                            <?= sErr('billing_contact_email') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">WhatsApp / phone number</label>
                            <input type="text" name="billing_contact_phone" inputmode="numeric"
                                   class="form-control<?= sCls('billing_contact_phone') ?>"
                                   value="<?= sanitize($current['billing_contact_phone']) ?>"
                                   placeholder="923001234567">
                            <div class="form-text">
                                International format: country code, no leading zero, no spaces. Used
                                for both the WhatsApp chat and the phone link.
                            </div>
                            <?= sErr('billing_contact_phone') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Preferred method</label>
                            <select name="billing_contact_primary" class="form-select">
                                <?php foreach (billingContactMethodChoices() as $code => $label): ?>
                                    <option value="<?= sanitize($code) ?>"
                                        <?= $current['billing_contact_primary'] === $code ? 'selected' : '' ?>>
                                        <?= sanitize($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Shown first, as the main button.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Button label</label>
                            <input type="text" name="billing_contact_label" maxlength="60"
                                   class="form-control<?= sCls('billing_contact_label') ?>"
                                   value="<?= sanitize($current['billing_contact_label']) ?>"
                                   placeholder="<?= sanitize(billingContactDefaultLabel()) ?>">
                            <div class="form-text">
                                Leave blank for “<?= sanitize(billingContactDefaultLabel()) ?>”.
                            </div>
                            <?= sErr('billing_contact_label') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="sticky-save">
        <button type="submit" class="btn btn-primary">Save settings</button>
    </div>
</form>

<?php // Lead generation (#50). A separate form, deliberately.
      //
      // The settings form above round-trips every value it holds through the
      // page on each render. An API key must never be one of those: it is
      // stored encrypted, it is never rendered back, and a blank field means
      // "keep what is there" — the same contract as the SMTP password and the
      // LLM provider keys. Keeping it in its own form is what makes that rule
      // impossible to break by adding a field to the wrong place. ?>
<div class="card mb-4 mt-4" id="set-leads">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Lead search (SerpApi)</span>
        <?php if ($serpConfigured): ?>
            <span class="badge bg-success">Key stored</span>
        <?php else: ?>
            <span class="badge bg-secondary">Not configured</span>
        <?php endif; ?>
    </div>
    <form method="POST" data-ajax>
        <?= csrfField() ?>
        <div class="card-body">
            <p class="text-muted small">
                Powers <a href="<?= APP_URL ?>/admin/leads.php">Leads</a>, which finds local
                businesses through Google Maps so you can call them. Get a key at
                <a href="https://serpapi.com/" target="_blank" rel="noopener">serpapi.com</a>.
                <strong>Every page of results costs one search credit.</strong>
            </p>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="serpapiKey">API key</label>
                    <input type="password" name="serpapi_key" id="serpapiKey"
                           class="form-control<?= sCls('serpapi_key') ?>" autocomplete="off"
                           placeholder="<?= $serpConfigured ? 'Stored — leave blank to keep it' : 'Paste your SerpApi key' ?>">
                    <div class="form-text">
                        Encrypted at rest and never shown again. Leave blank to keep the stored key.
                    </div>
                    <?= sErr('serpapi_key') ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="serpapiDial">Dial code</label>
                    <div class="input-group">
                        <span class="input-group-text">+</span>
                        <input type="text" name="serpapi_dial_code" id="serpapiDial"
                               class="form-control<?= sCls('serpapi_dial_code') ?>" maxlength="4"
                               value="<?= sanitize($serp['dial_code']) ?>">
                    </div>
                    <div class="form-text">For wa.me links.</div>
                    <?= sErr('serpapi_dial_code') ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="serpapiHl">Language</label>
                    <input type="text" name="serpapi_hl" id="serpapiHl"
                           class="form-control<?= sCls('serpapi_hl') ?>" maxlength="6"
                           value="<?= sanitize($serp['hl']) ?>">
                    <div class="form-text">e.g. <code>en</code></div>
                    <?= sErr('serpapi_hl') ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="serpapiRadius">Radius (km)</label>
                    <input type="number" name="serpapi_radius_km" id="serpapiRadius"
                           class="form-control<?= sCls('serpapi_radius_km') ?>"
                           min="<?= LEADS_MIN_RADIUS_KM ?>" max="<?= LEADS_MAX_RADIUS_KM ?>" step="1"
                           value="<?= leadsRadiusMToKm($serp['radius_m']) ?>">
                    <div class="form-text">Default search size.</div>
                    <?= sErr('serpapi_radius_km') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="serpapiLocation">Default area</label>
                    <input type="text" name="serpapi_location" id="serpapiLocation"
                           class="form-control<?= sCls('serpapi_location') ?>" maxlength="200" required
                           value="<?= sanitize($serp['location']) ?>">
                    <div class="form-text">
                        Pre-fills the Area box on Leads, and backs up a search that arrives without one.
                        <strong>Never leave it blank</strong> — a search with no area is answered from
                        wherever Google thinks the request came from, which is SerpApi's datacentre,
                        not your city.
                    </div>
                    <?= sErr('serpapi_location') ?>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex gap-2 align-items-center">
            <button type="submit" name="action" value="save_serpapi" class="btn btn-primary">
                Save lead settings
            </button>
            <?php // Confirmed, because it is the only button on this page that
                  // spends the owner's money. There is no cheaper way to prove a
                  // key both authenticates and still has credit. ?>
            <button type="submit" name="action" value="test_serpapi"
                    class="btn btn-outline-secondary" data-busy-label="Testing…"
                    data-confirm="Run a test search? This uses one SerpApi credit."
                    <?= $serpConfigured ? '' : 'disabled' ?>>
                Test key
            </button>
            <span class="text-muted x-small">A test search costs one credit.</span>
        </div>
    </form>
</div>

    </div><?php // /.col-lg-9 ?>
</div><?php // /.row ?>

<script>
// Reveal the relabel opt-in only when the currency actually changes. Server-side
// it is ignored unless the currency differs, so this is presentation only.
(function () {
    var select = document.getElementById('currencySelect');
    var wrap = document.getElementById('relabelWrap');
    var box = document.getElementById('relabelPlans');
    if (!select || !wrap) return;
    select.addEventListener('change', function () {
        var changed = select.value !== select.dataset.original;
        wrap.classList.toggle('d-none', !changed);
        if (!changed && box) box.checked = false;
    });
})();

// The sales-contact detail fields only matter when a contact method is ticked,
// so the column is hidden until one is. Without JS it stays visible.
(function () {
    var details = document.getElementById('salesContactDetails');
    var boxes = document.querySelectorAll('input[name="billing_contact_methods[]"]');
    if (!details || !boxes.length) return;
    function sync() {
        var any = false;
        boxes.forEach(function (b) { if (b.checked) any = true; });
        details.classList.toggle('d-none', !any);
    }
    boxes.forEach(function (b) { b.addEventListener('change', sync); });
    sync();
})();
</script>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
