<?php

require_once __DIR__ . '/countries.php';

// Tenant profile: the optional identity/contact detail behind an account.
//
// Stored in its own table rather than as more columns on `users`, because
// `users` is on the hot path — getCurrentUser() runs on every authenticated
// request via requireLogin(). Profile fields are sparse and read on three pages,
// so widening the row every request buys nothing. It also keeps authentication
// columns apart from user-supplied content.
//
// Every field is optional. Registration must stay a two-field form, so nothing
// here may be required, and every consumer has to tolerate NULL.

function profileFields() {
    return [
        'company_name', 'whatsapp_number', 'address_line1', 'address_line2',
        'city', 'state_region', 'postal_code', 'country',
        'contact_email', 'contact_whatsapp',
    ];
}

// Always returns a fully-keyed array, so callers and templates never have to
// test for a missing profile row — an account that predates this table reads as
// a profile with every field empty.
function getUserProfile(mysqli $conn, $userId) {
    $blank = array_fill_keys(profileFields(), null);
    $blank['contact_email'] = 1;
    $blank['contact_whatsapp'] = 0;

    $stmt = $conn->prepare(
        "SELECT company_name, whatsapp_number, address_line1, address_line2,
                city, state_region, postal_code, country,
                contact_email, contact_whatsapp
         FROM user_profiles WHERE user_id = ?"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? array_merge($blank, $row) : $blank;
}

function saveUserProfile(mysqli $conn, $userId, array $data) {
    $stmt = $conn->prepare(
        "INSERT INTO user_profiles
            (user_id, company_name, whatsapp_number, address_line1, address_line2,
             city, state_region, postal_code, country, contact_email, contact_whatsapp)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            company_name = VALUES(company_name),
            whatsapp_number = VALUES(whatsapp_number),
            address_line1 = VALUES(address_line1),
            address_line2 = VALUES(address_line2),
            city = VALUES(city),
            state_region = VALUES(state_region),
            postal_code = VALUES(postal_code),
            country = VALUES(country),
            contact_email = VALUES(contact_email),
            contact_whatsapp = VALUES(contact_whatsapp)"
    );
    $stmt->bind_param(
        'issssssssii',
        $userId,
        $data['company_name'],
        $data['whatsapp_number'],
        $data['address_line1'],
        $data['address_line2'],
        $data['city'],
        $data['state_region'],
        $data['postal_code'],
        $data['country'],
        $data['contact_email'],
        $data['contact_whatsapp']
    );
    $stmt->execute();
    $stmt->close();
}

// --- Phone numbers ----------------------------------------------------------

// WhatsApp identifies accounts by E.164 digits, and that is how wa_accounts
// already stores them, so normalise to bare digits and keep the '+' for display
// only. Users paste numbers with spaces, dashes, brackets and leading '00'.
function normalisePhone($raw) {
    $digits = preg_replace('/\D+/', '', (string)$raw);
    if ($digits === '') return null;
    // 00 is the international prefix in much of the world, including Pakistan;
    // E.164 has no room for it.
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }
    return $digits === '' ? null : $digits;
}

// E.164 caps the whole number at 15 digits; the shortest real ones are 8 with
// the country code. Anything outside that is a typo, not a phone number.
function isValidPhone($digits) {
    return (bool)preg_match('/^[1-9][0-9]{7,14}$/', (string)$digits);
}

function formatPhone($digits) {
    return $digits ? '+' . $digits : null;
}

// --- Display ----------------------------------------------------------------

// What to call this tenant on an invoice, in the admin console, or as a chatbot
// signature. The company name is the billable entity when it is set.
function tenantDisplayName(array $user, ?array $profile = null) {
    $company = trim((string)($profile['company_name'] ?? ''));
    return $company !== '' ? $company : ($user['name'] ?? '');
}

// Returns the address as an array of non-empty lines, so callers choose the
// separator (newline for text, <br> for HTML) and empty fields never leave
// blank lines or dangling commas.
function addressLines(array $profile) {
    $lines = [];

    foreach (['address_line1', 'address_line2'] as $key) {
        $value = trim((string)($profile[$key] ?? ''));
        if ($value !== '') $lines[] = $value;
    }

    // "Karachi, Sindh 75500" — each part optional, so build then join.
    $locality = array_filter([
        trim((string)($profile['city'] ?? '')),
        trim((string)($profile['state_region'] ?? '')),
    ], fn($v) => $v !== '');
    $localityLine = implode(', ', $locality);

    $postal = trim((string)($profile['postal_code'] ?? ''));
    if ($postal !== '') {
        $localityLine = $localityLine === '' ? $postal : $localityLine . ' ' . $postal;
    }
    if ($localityLine !== '') $lines[] = $localityLine;

    $country = countryName($profile['country'] ?? null);
    if ($country) $lines[] = $country;

    return $lines;
}

function hasProfileDetail(array $profile) {
    foreach (['company_name', 'whatsapp_number', 'address_line1', 'city', 'country'] as $key) {
        if (trim((string)($profile[$key] ?? '')) !== '') return true;
    }
    return false;
}

// --- Validation -------------------------------------------------------------

// Returns [cleanData, errors]. Everything is optional, so a blank field is
// valid and stored as NULL; only a *present* value that cannot be right is an
// error. Trimmed-to-empty collapses to NULL so "  " does not count as an
// address.
function validateProfileInput(array $post) {
    $errors = [];
    $clean = [];

    $text = function ($key, $max) use ($post, &$errors) {
        $value = trim((string)($post[$key] ?? ''));
        if ($value === '') return null;
        if (mb_strlen($value) > $max) {
            $errors[$key] = 'This field is too long (max ' . $max . ' characters).';
            return null;
        }
        return $value;
    };

    $clean['company_name']  = $text('company_name', 150);
    $clean['address_line1'] = $text('address_line1', 200);
    $clean['address_line2'] = $text('address_line2', 200);
    $clean['city']          = $text('city', 100);
    $clean['state_region']  = $text('state_region', 100);

    $postal = trim((string)($post['postal_code'] ?? ''));
    if ($postal === '') {
        $clean['postal_code'] = null;
    } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 -]{1,11}$/', $postal)) {
        // Postal formats vary far too much to validate properly (Pakistan uses
        // 5 digits, the UK uses alphanumerics with a space). Reject only what no
        // format allows: punctuation and absurd length.
        $errors['postal_code'] = 'Enter a valid postal or ZIP code.';
        $clean['postal_code'] = null;
    } else {
        $clean['postal_code'] = strtoupper($postal);
    }

    $whatsapp = trim((string)($post['whatsapp_number'] ?? ''));
    if ($whatsapp === '') {
        $clean['whatsapp_number'] = null;
    } else {
        $digits = normalisePhone($whatsapp);
        if (!isValidPhone($digits)) {
            $errors['whatsapp_number'] = 'Enter the number in international format, e.g. +92 300 1234567.';
            $clean['whatsapp_number'] = null;
        } else {
            $clean['whatsapp_number'] = $digits;
        }
    }

    $country = strtoupper(trim((string)($post['country'] ?? '')));
    if ($country === '') {
        $clean['country'] = null;
    } elseif (!isValidCountry($country)) {
        $errors['country'] = 'Select a country from the list.';
        $clean['country'] = null;
    } else {
        $clean['country'] = $country;
    }

    // An address with a street but no city or country cannot be posted to, and
    // silently storing it means a support ticket later.
    if ($clean['address_line1'] !== null && $clean['city'] === null && $clean['country'] === null) {
        $errors['city'] = 'Add at least a city or a country for the address.';
    }

    $clean['contact_email']    = isset($post['contact_email']) ? 1 : 0;
    $clean['contact_whatsapp'] = isset($post['contact_whatsapp']) ? 1 : 0;

    return [$clean, $errors];
}
