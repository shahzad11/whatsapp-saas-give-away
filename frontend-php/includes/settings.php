<?php

// Instance-wide settings — the platform owner's single source of truth.
//
// Distinct from `user_settings`, which is per tenant. Where both exist (the
// timezone), the tenant value overrides and this provides the default, so a
// fresh tenant inherits the owner's choice instead of a hardcoded constant.
//
// Defaults live here rather than in the schema so a row that has never been
// written still resolves, and so the seed cannot drift from the code.

function appSettingDefaults() {
    return [
        // First market is Pakistan, so prices are PKR out of the box. The
        // admin can change it; nothing may hardcode a currency.
        'currency'             => 'PKR',
        'timezone'             => 'Asia/Karachi',
        'payment_instructions' => '',
    ];
}

// Settings that were environment variables first and are now admin-editable.
//
// They are NOT listed in appSettingDefaults(), deliberately: an absent row must
// fall through to the env constant, which stays the deployment-level default.
// Listing them here would mask the env value with a hardcoded one.
//
// These cannot be constants like ALLOW_REGISTRATION is, because config/app.php
// runs before config/database.php — there is no connection to read at the point
// the constants are defined. Hence accessor functions.

// Reads one override. Returns null when there is no connection or no row, so
// each caller can fall through to its env constant.
function overrideSetting(?mysqli $conn, $key) {
    $db = settingsConn($conn);
    if (!$db) return null;
    $value = appSetting($db, $key, null);
    return ($value === null || $value === '') ? null : $value;
}

function allowRegistration(?mysqli $conn = null) {
    $value = overrideSetting($conn, 'allow_registration');
    // Fails closed: no connection, no row, or an unreadable table all land on
    // the env value, which defaults to false. Open signup must never be the
    // result of a database problem.
    return $value === null ? ALLOW_REGISTRATION : $value === '1';
}

function defaultPlanCode(?mysqli $conn = null) {
    return overrideSetting($conn, 'default_plan_code') ?? DEFAULT_PLAN_CODE;
}

function loginMaxAttempts(?mysqli $conn = null) {
    $value = (int)overrideSetting($conn, 'login_max_attempts');
    // 0 would disable throttling altogether, which is never what a blank field
    // means — fall back to the configured default rather than to "unlimited".
    return $value > 0 ? $value : LOGIN_MAX_ATTEMPTS;
}

function loginLockoutMinutes(?mysqli $conn = null) {
    $value = (int)overrideSetting($conn, 'login_lockout_minutes');
    return $value > 0 ? $value : LOGIN_LOCKOUT_MINUTES;
}

function paymentInstructions(?mysqli $conn = null) {
    return (string)(overrideSetting($conn, 'payment_instructions') ?? '');
}

// One query per request, then served from memory. Called from price formatting,
// which runs once per plan card, so it must not be per-call.
function appSettings(mysqli $conn, $refresh = false) {
    static $cache = null;
    if ($cache !== null && !$refresh) return $cache;

    $cache = appSettingDefaults();

    // A request can arrive before the table exists (first boot, or an older
    // database mid-upgrade). Falling back to defaults is strictly better than
    // a fatal on every page.
    try {
        $result = $conn->query("SELECT setting_key, setting_value FROM app_settings");
        while ($result && $row = $result->fetch_assoc()) {
            if ($row['setting_value'] !== null && $row['setting_value'] !== '') {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        }
        if ($result) $result->free();
    } catch (mysqli_sql_exception $e) {
        error_log('app_settings unavailable, using defaults: ' . $e->getMessage());
    }

    return $cache;
}

function appSetting(mysqli $conn, $key, $default = null) {
    $settings = appSettings($conn);
    if (array_key_exists($key, $settings)) return $settings[$key];
    return $default;
}

function setAppSetting(mysqli $conn, $key, $value) {
    $stmt = $conn->prepare(
        "INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();

    appSettings($conn, true);
}

// Display helpers are called from templates that do not carry $conn around
// (formatPrice($plan) has no connection argument), so fall back to the global
// one. Resolved here rather than with `global $conn` inside each function, which
// would shadow the parameter of the same name.
function settingsConn(?mysqli $conn = null) {
    if ($conn !== null) return $conn;
    return $GLOBALS['conn'] ?? null;
}

function appCurrency(?mysqli $conn = null) {
    $db = settingsConn($conn);
    if (!$db) return appSettingDefaults()['currency'];

    $code = strtoupper((string)appSetting($db, 'currency', 'PKR'));
    return isValidCurrency($code) ? $code : appSettingDefaults()['currency'];
}

function appTimezone(?mysqli $conn = null) {
    $db = settingsConn($conn);
    if (!$db) return appSettingDefaults()['timezone'];

    $tz = (string)appSetting($db, 'timezone', 'Asia/Karachi');
    return isValidTimezone($tz) ? $tz : appSettingDefaults()['timezone'];
}

// --- First-run setup checklist ----------------------------------------------
//
// A fresh instance needs six things done before anything works, in roughly this
// order, and none of them was surfaced anywhere: the admin console opened on an
// all-zero metrics page and the tenant dashboard on "link an account", which is
// step five. An admin was expected to discover the other five by exploring a
// sidebar.
//
// Each step reports its own completion from the real state rather than from a
// "wizard finished" flag, so a step that is later undone (SMTP cleared, the only
// provider disabled) shows as outstanding again. `done` is therefore always
// truthful, which is why the card can be dismissed without lying: dismissing
// hides the reminder, it does not mark anything complete.
//
// Every query is wrapped: this runs on the first page an admin sees, possibly on
// a database mid-upgrade, and a missing table must not fatal the overview page.
function instanceSetupSteps(mysqli $conn) {
    $count = function ($sql) use ($conn) {
        try {
            $row = $conn->query($sql)->fetch_row();
            return (int)($row[0] ?? 0);
        } catch (Throwable $e) {
            error_log('setup checklist query failed: ' . $e->getMessage());
            return 0;
        }
    };

    $providerWithKey = $count(
        "SELECT COUNT(*) FROM llm_providers WHERE is_enabled = 1 AND api_key_encrypted IS NOT NULL AND api_key_encrypted <> ''"
    );

    // A plan with the chatbot feature but no granted model is the trap this step
    // exists to catch, so both halves are required to call it done.
    $planReady = 0;
    try {
        $rows = $conn->query("SELECT id, features FROM plans WHERE is_active = 1")->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $p) {
            if (!planHasFeature($p, 'chatbot')) continue;
            if (llmPlanModelIds($conn, (int)$p['id'])) { $planReady = 1; break; }
        }
    } catch (Throwable $e) {
        error_log('setup checklist plan check failed: ' . $e->getMessage());
    }

    return [
        [
            'label' => 'Configure outgoing email',
            'why'   => 'Activation, password reset and reminder emails are silently undeliverable without it.',
            'url'   => APP_URL . '/admin/email.php',
            'done'  => smtpConfigured($conn),
        ],
        [
            'label' => 'Add an AI provider key',
            'why'   => 'The chatbot cannot write a reply without an account at an AI provider.',
            'url'   => APP_URL . '/admin/llm.php',
            'done'  => $providerWithKey > 0,
        ],
        [
            'label' => 'Turn the chatbot on for a plan and grant it a model',
            'why'   => 'A plan with the feature but no granted model shows tenants "no models available".',
            'url'   => APP_URL . '/admin/plans.php',
            'done'  => $planReady > 0,
        ],
        [
            'label' => 'Check currency, timezone and sign-up policy',
            'why'   => 'Prices, every timestamp and whether strangers may register all come from here.',
            'url'   => APP_URL . '/admin/settings.php',
            // Saving the page once writes both rows; until then the instance is
            // running on the built-in defaults, which may be the wrong country.
            'done'  => overrideSetting($conn, 'currency') !== null && overrideSetting($conn, 'timezone') !== null,
        ],
        [
            'label' => 'Link a WhatsApp account',
            'why'   => 'Nothing can be sent or received until a phone is paired.',
            'url'   => APP_URL . '/whatsapp/link.php',
            'done'  => $count("SELECT COUNT(*) FROM wa_accounts WHERE status = 'connected'") > 0,
        ],
        [
            'label' => 'Set up a chatbot',
            'why'   => 'Give the bot a knowledge base, pick a model, and switch it on.',
            'url'   => APP_URL . '/chatbot.php',
            'done'  => $count("SELECT COUNT(*) FROM chatbot_configs WHERE is_enabled = 1") > 0,
        ],
    ];
}

function instanceSetupDismissed(mysqli $conn) {
    return (string)appSetting($conn, 'setup_checklist_dismissed', '') === '1';
}

// --- Per-tenant settings ----------------------------------------------------
// Moved here from settings.php so profile.php can reach them too. Two pages
// defining their own copies would drift.

function getUserSetting(mysqli $conn, $userId, $key, $default = '') {
    $stmt = $conn->prepare("SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = ?");
    $stmt->bind_param('is', $userId, $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['setting_value'] : $default;
}

function setUserSetting(mysqli $conn, $userId, $key, $value) {
    $stmt = $conn->prepare(
        "INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->bind_param('iss', $userId, $key, $value);
    $stmt->execute();
    $stmt->close();
}

// --- Validation -------------------------------------------------------------

function isValidTimezone($tz) {
    return in_array($tz, DateTimeZone::listIdentifiers(), true);
}

// Every PHP timezone, grouped by region for an <optgroup> select.
//
// PHP's own identifier list is the whitelist — a value is only accepted if it
// appears in it, so no arbitrary string can reach `new DateTimeZone()`. The
// previous 14-entry hand-picked list was safe but left tenants outside those
// regions unable to pick their own zone.
//
// Built from DateTimeZone, not intl: the frontend image installs only mysqli.
function timezoneChoices() {
    static $grouped = null;
    if ($grouped !== null) return $grouped;

    $grouped = [];
    foreach (DateTimeZone::listIdentifiers() as $id) {
        $parts = explode('/', $id, 2);
        $region = count($parts) === 2 ? $parts[0] : 'Other';
        // "Asia/Karachi" -> "Karachi"; "America/Argentina/Salta" -> "Argentina — Salta"
        $label = count($parts) === 2 ? str_replace(['_', '/'], [' ', ' — '], $parts[1]) : $id;
        $grouped[$region][$id] = $label;
    }
    ksort($grouped);
    return $grouped;
}

// "UTC+05:00" — the offset a tenant recognises, rather than the abbreviation,
// which is ambiguous (IST is India, Ireland and Israel).
function timezoneOffsetLabel($tz) {
    if (!isValidTimezone($tz)) return '';
    try {
        $offset = (new DateTimeZone($tz))->getOffset(new DateTime('now', new DateTimeZone('UTC')));
    } catch (Exception $e) {
        return '';
    }
    $sign = $offset < 0 ? '-' : '+';
    $offset = abs($offset);
    return sprintf('UTC%s%02d:%02d', $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60));
}

// ISO 4217 is a fixed list, but shipping all 180 codes to validate against is
// noise. A well-formed three-letter code that we know how to render is enough;
// anything else is rejected so it cannot reach a price label.
function isValidCurrency($code) {
    return (bool)preg_match('/^[A-Z]{3}$/', (string)$code);
}

// --- Money ------------------------------------------------------------------

// Symbol, minor-unit digits, and whether the symbol leads.
//
// `decimals` is the ISO 4217 exponent and is NOT always 2. Getting it wrong
// misstates a price by orders of magnitude, so the exceptions are explicit:
//   0 — JPY, KRW, VND, CLP, ISK, UGX, RWF (no minor unit at all)
//   3 — BHD, IQD, JOD, KWD, LYD, OMR, TND (thousandths: 1 KWD = 1000 fils)
// A currency not listed still renders, just with the bare ISO code and an
// assumed exponent of 2 — which is why anything unusual belongs in this table.
function currencyFormats() {
    return [
        // Primary markets
        'PKR' => ['symbol' => 'Rs.', 'name' => 'Pakistani Rupee',      'decimals' => 2, 'prefix' => true],
        'AED' => ['symbol' => 'AED', 'name' => 'UAE Dirham',           'decimals' => 2, 'prefix' => true],
        'SAR' => ['symbol' => 'SAR', 'name' => 'Saudi Riyal',          'decimals' => 2, 'prefix' => true],
        'QAR' => ['symbol' => 'QAR', 'name' => 'Qatari Riyal',         'decimals' => 2, 'prefix' => true],
        'INR' => ['symbol' => '₹',   'name' => 'Indian Rupee',         'decimals' => 2, 'prefix' => true],
        'BDT' => ['symbol' => 'Tk',  'name' => 'Bangladeshi Taka',     'decimals' => 2, 'prefix' => true],
        'LKR' => ['symbol' => 'Rs',  'name' => 'Sri Lankan Rupee',     'decimals' => 2, 'prefix' => true],
        // Majors
        'USD' => ['symbol' => '$',   'name' => 'US Dollar',            'decimals' => 2, 'prefix' => true],
        'EUR' => ['symbol' => '€',   'name' => 'Euro',                 'decimals' => 2, 'prefix' => true],
        'GBP' => ['symbol' => '£',   'name' => 'Pound Sterling',       'decimals' => 2, 'prefix' => true],
        'CAD' => ['symbol' => 'CA$', 'name' => 'Canadian Dollar',      'decimals' => 2, 'prefix' => true],
        'AUD' => ['symbol' => 'A$',  'name' => 'Australian Dollar',    'decimals' => 2, 'prefix' => true],
        'CHF' => ['symbol' => 'CHF', 'name' => 'Swiss Franc',          'decimals' => 2, 'prefix' => true],
        'CNY' => ['symbol' => 'CN¥', 'name' => 'Chinese Yuan',         'decimals' => 2, 'prefix' => true],
        'SGD' => ['symbol' => 'S$',  'name' => 'Singapore Dollar',     'decimals' => 2, 'prefix' => true],
        'MYR' => ['symbol' => 'RM',  'name' => 'Malaysian Ringgit',    'decimals' => 2, 'prefix' => true],
        'TRY' => ['symbol' => '₺',   'name' => 'Turkish Lira',         'decimals' => 2, 'prefix' => true],
        'ZAR' => ['symbol' => 'R',   'name' => 'South African Rand',   'decimals' => 2, 'prefix' => true],
        'NGN' => ['symbol' => '₦',   'name' => 'Nigerian Naira',       'decimals' => 2, 'prefix' => true],
        'EGP' => ['symbol' => 'EGP', 'name' => 'Egyptian Pound',       'decimals' => 2, 'prefix' => true],
        'IDR' => ['symbol' => 'Rp',  'name' => 'Indonesian Rupiah',    'decimals' => 2, 'prefix' => true],
        'PHP' => ['symbol' => '₱',   'name' => 'Philippine Peso',      'decimals' => 2, 'prefix' => true],
        'THB' => ['symbol' => '฿',   'name' => 'Thai Baht',            'decimals' => 2, 'prefix' => true],
        'BRL' => ['symbol' => 'R$',  'name' => 'Brazilian Real',       'decimals' => 2, 'prefix' => true],
        // Zero-decimal
        'JPY' => ['symbol' => '¥',   'name' => 'Japanese Yen',         'decimals' => 0, 'prefix' => true],
        'KRW' => ['symbol' => '₩',   'name' => 'South Korean Won',     'decimals' => 0, 'prefix' => true],
        'VND' => ['symbol' => '₫',   'name' => 'Vietnamese Dong',      'decimals' => 0, 'prefix' => true],
        // Three-decimal
        'KWD' => ['symbol' => 'KWD', 'name' => 'Kuwaiti Dinar',        'decimals' => 3, 'prefix' => true],
        'BHD' => ['symbol' => 'BHD', 'name' => 'Bahraini Dinar',       'decimals' => 3, 'prefix' => true],
        'OMR' => ['symbol' => 'OMR', 'name' => 'Omani Rial',           'decimals' => 3, 'prefix' => true],
        'JOD' => ['symbol' => 'JOD', 'name' => 'Jordanian Dinar',      'decimals' => 3, 'prefix' => true],
        'TND' => ['symbol' => 'TND', 'name' => 'Tunisian Dinar',       'decimals' => 3, 'prefix' => true],
    ];
}

// Sorted "PKR — Pakistani Rupee" labels for the admin currency select.
function currencyChoices() {
    $out = [];
    foreach (currencyFormats() as $code => $fmt) {
        $out[$code] = $code . ' — ' . $fmt['name'];
    }
    asort($out, SORT_STRING);
    return $out;
}

function currencyFormat($code) {
    $code = strtoupper((string)$code);
    $formats = currencyFormats();
    return $formats[$code] ?? ['symbol' => $code, 'decimals' => 2, 'prefix' => true];
}

// $minorUnits is the stored integer (paisa, cents). Whole amounts drop the
// fractional part — "Rs. 1,500" reads as a price, "Rs. 1,500.00" reads as an
// invoice line.
function formatMoney($minorUnits, $currency = null, ?mysqli $conn = null) {
    $currency = $currency ?: appCurrency($conn);
    $fmt = currencyFormat($currency);

    $divisor = 10 ** $fmt['decimals'];
    $amount = $fmt['decimals'] > 0 ? $minorUnits / $divisor : $minorUnits;

    $decimals = ($fmt['decimals'] > 0 && fmod($amount, 1) != 0.0) ? $fmt['decimals'] : 0;
    $formatted = number_format($amount, $decimals);

    // Word-like symbols need a separating space ("Rs. 1,500", "AED 1,500");
    // glyphs do not ("$19", "€19").
    $symbol = $fmt['symbol'];
    $gap = preg_match('/[\p{L}.]$/u', $symbol) ? ' ' : '';

    return $fmt['prefix']
        ? $symbol . $gap . $formatted
        : $formatted . ' ' . $symbol;
}
