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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('error', 'Invalid request.');
        redirect(APP_URL . '/admin/settings.php');
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

    $instructions = trim($_POST['payment_instructions'] ?? '');
    if (mb_strlen($instructions) > 5000) {
        $errors['payment_instructions'] = 'Too long (max 5000 characters).';
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
            flash('success', 'Settings saved. ' . $affected . ' plan(s) relabelled to ' . $currency . ' (amounts unchanged).');
        } else {
            flash('success', 'Settings saved.');
        }

        // An inactive plan as the signup default is accepted — a deliberately
        // hidden "internal" plan is a legitimate use — but it is never silent.
        // Every new tenant would land on a plan that is not offered anywhere and
        // may have no features, and nothing else on the instance would say why.
        foreach ($plans as $p) {
            if ($p['code'] === $planCode && !$p['is_active']) {
                flash('error', 'Careful: "' . $p['name'] . '" is an inactive plan. New sign-ups will be put on it, '
                    . 'and it is not shown to tenants as an option. Activate it on Plans if that is not intended.');
                break;
            }
        }

        redirect(APP_URL . '/admin/settings.php');
    }

    flash('error', 'Please correct the highlighted fields.');
    // Keep what was typed for redisplay.
    $current = [
        'currency' => $currency, 'timezone' => $timezone,
        'allow_registration' => $allowRegistration === '1',
        'default_plan_code' => $planCode,
        'login_max_attempts' => $maxAttempts, 'login_lockout_minutes' => $lockout,
        'payment_instructions' => $instructions,
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

<?php foreach (['success' => 'success', 'error' => 'danger'] as $key => $cls): ?>
    <?php if ($msg = flash($key)): ?>
        <div class="alert alert-<?= $cls ?> alert-dismissible fade show">
            <?= sanitize($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<form method="POST">
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
