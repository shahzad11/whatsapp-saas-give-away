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
    'allow_registration'    => allowRegistration($conn),
    'default_plan_code'     => defaultPlanCode($conn),
    'login_max_attempts'    => loginMaxAttempts($conn),
    'login_lockout_minutes' => loginLockoutMinutes($conn),
    'payment_instructions'  => paymentInstructions($conn),
    // #26. Blank is a legitimate value here — it simply means tenants who set no
    // number of their own get no WhatsApp alert — so this one is read raw rather
    // than through an accessor with a fallback.
    'handoff_notify_number' => (string)(overrideSetting($conn, 'handoff_notify_number') ?? ''),
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
        $notifyNumber = '';
    }

    $allowRegistration = isset($_POST['allow_registration']) ? '1' : '0';

    if (!$errors) {
        $previousCurrency = $current['currency'];

        setAppSetting($conn, 'currency', $currency);
        setAppSetting($conn, 'timezone', $timezone);
        setAppSetting($conn, 'allow_registration', $allowRegistration);
        setAppSetting($conn, 'default_plan_code', $planCode);
        setAppSetting($conn, 'login_max_attempts', (string)$maxAttempts);
        setAppSetting($conn, 'login_lockout_minutes', (string)$lockout);
        setAppSetting($conn, 'payment_instructions', $instructions);
        setAppSetting($conn, 'handoff_notify_number', $notifyNumber);

        logAudit($conn, 'admin.settings.update', 'app_settings', null, [
            'currency' => $currency,
            'timezone' => $timezone,
            'allow_registration' => $allowRegistration,
            'default_plan_code' => $planCode,
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
        'allow_registration' => $allowRegistration === '1',
        'default_plan_code' => $planCode,
        'login_max_attempts' => $maxAttempts, 'login_lockout_minutes' => $lockout,
        'payment_instructions' => $instructions,
        'handoff_notify_number' => (string)$notifyNumber,
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
        <div class="card-header">Registration</div>
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="allow_registration" id="allowRegistration"
                       <?= $current['allow_registration'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="allowRegistration">Allow public sign-ups</label>
                <div class="form-text">
                    Off by default. With no payment wall in front of it, open signup is a liability.
                </div>
                <?php // The one combination that produces a dead end, and neither page
                      // said so: sign-up is open, and the instance cannot send the
                      // activation email the new account needs. Every registration then
                      // succeeds and every new tenant is permanently stuck on "check
                      // your email" — with the failure visible only in the container
                      // log. There is no local mail transport to fall back on, by
                      // design (see Email / SMTP), so this has to be said here. ?>
                <?php if ($current['allow_registration'] && !smtpConfigured($conn)): ?>
                    <div class="alert alert-danger py-2 mt-2 mb-0 small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong>Sign-ups are open but this instance cannot send email.</strong>
                        Every new account needs an activation link, so nobody who registers will be
                        able to log in. Either
                        <a href="<?= APP_URL ?>/admin/email.php">configure outgoing email</a>
                        or turn sign-ups off until you have.
                    </div>
                <?php endif; ?>
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
