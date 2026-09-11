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
            $message = 'Settings saved. ' . $affected . ' plan(s) relabelled to ' . $currency . ' (amounts unchanged).';
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
                $warning = 'Careful: "' . $p['name'] . '" is an inactive plan. New sign-ups will be put on it, '
                    . 'and it is not shown to tenants as an option. Activate it on Plans if that is not intended.';
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

$pageTitle = 'Instance Settings';
require_once dirname(__DIR__) . '/includes/admin-header.php';
?>

<form method="POST" data-ajax>
    <?= csrfField() ?>

    <div class="card mb-4">
        <div class="card-header">Localisation</div>
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
                                Also relabel <?= (int)$repriceable ?> existing paid plan(s) to the new currency
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
                        Default for new tenants (<?= sanitize(timezoneOffsetLabel($current['timezone'])) ?>).
                        A tenant who sets their own keeps it.
                    </div>
                    <?= sErr('timezone') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">New tenants</div>
        <div class="card-body">
            <?php // The "Allow public sign-ups" switch was here, and is gone (#48).
                  //
                  // It is not merely unticked: register.php, the allow_registration
                  // row, the ALLOW_REGISTRATION constant and allowRegistration()
                  // were all removed, so there is nothing left for a switch to
                  // toggle. Tenants exist because an admin made one. ?>
            <div class="alert alert-secondary py-2 small">
                <i class="bi bi-shield-lock me-1"></i>
                This instance is <strong>invite-only</strong>. There is no public sign-up form —
                add tenants from <a href="<?= APP_URL ?>/admin/tenants.php">Tenants</a>, where you
                can email them a password-setup link or hand over a temporary password.
            </div>
            <div class="col-md-5 px-0">
                <label class="form-label">Default plan for new tenants</label>
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
                            This plan is <strong>inactive</strong>, so new sign-ups get a plan tenants are never
                            offered — and possibly one with no features.
                            <a href="<?= APP_URL ?>/admin/plans.php">Review plans</a>.
                        </span>
                    <?php else: ?>
                        Applied to every new sign-up. Manage what each plan includes on
                        <a href="<?= APP_URL ?>/admin/plans.php">Plans</a>.
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">Login Security</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Max failed attempts</label>
                    <input type="number" name="login_max_attempts" min="1" max="100"
                           class="form-control<?= sCls('login_max_attempts') ?>"
                           value="<?= (int)$current['login_max_attempts'] ?>">
                    <div class="form-text">Counted per email and per IP independently.</div>
                    <?= sErr('login_max_attempts') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Lockout window (minutes)</label>
                    <input type="number" name="login_lockout_minutes" min="1" max="1440"
                           class="form-control<?= sCls('login_lockout_minutes') ?>"
                           value="<?= (int)$current['login_lockout_minutes'] ?>">
                    <?= sErr('login_lockout_minutes') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">Handover Notifications</div>
        <div class="card-body">
            <div class="col-md-5 px-0">
                <label class="form-label">Fallback WhatsApp number</label>
                <input type="text" name="handoff_notify_number" inputmode="numeric"
                       class="form-control<?= sCls('handoff_notify_number') ?>"
                       value="<?= sanitize($current['handoff_notify_number']) ?>" placeholder="923001234567">
                <div class="form-text">
                    Alerted when a customer asks for a person and the tenant has set no number of
                    their own. Their value always wins. Country code, no leading zero, no spaces —
                    it is used as a WhatsApp address, not displayed.
                    <?php // Worth stating: the alert is a real WhatsApp message and is
                          // metered like one, against the tenant whose customer asked. ?>
                    Each alert is sent from the tenant's own linked account and counts as one of
                    their messages.
                </div>
                <?= sErr('handoff_notify_number') ?>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">Payment Instructions</div>
        <div class="card-body">
            <label class="form-label">Shown to tenants on their billing page</label>
            <textarea name="payment_instructions" rows="5" maxlength="5000"
                      class="form-control<?= sCls('payment_instructions') ?>"
                      placeholder="e.g. Bank transfer to Meezan Bank, account 1234-5678. Email the receipt to billing@example.com and we will activate your plan."><?= sanitize($current['payment_instructions']) ?></textarea>
            <div class="form-text">
                There is no payment gateway: tenants pay out of band and an admin records it.
                Leave blank to hide the section.
            </div>
            <?= sErr('payment_instructions') ?>
        </div>
    </div>

    <?php // #43. The route a tenant takes to ask about a plan. Every plan card on
          // their billing page shows whatever is switched on here, with the plan's
          // name already in the message — so an enquiry arrives saying which plan
          // it is about. ?>
    <div class="card mb-4">
        <div class="card-header">Sales Contact</div>
        <div class="card-body">
            <?php if (!$current['billing_contact_methods']): ?>
                <div class="alert alert-warning py-2 small">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>No contact method is configured.</strong>
                    Tenants who want a different plan are shown the payment instructions if you have
                    written any, and otherwise told to contact their administrator — with no address or
                    number to use. Tick a method below and fill in its detail.
                </div>
            <?php endif; ?>

            <label class="form-label">Methods tenants may use</label>
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
                        Tick as many as you actually watch. Each becomes a button on every plan the
                        tenant is not already on.
                    </div>
                </div>
                <div class="col-md-8">
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
                                A mailbox someone reads. It is never taken from your SMTP sender
                                address, which is usually a no-reply mailbox.
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

    <button type="submit" class="btn btn-primary">Save Settings</button>
</form>

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
</script>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
