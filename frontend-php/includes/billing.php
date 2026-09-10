<?php

// Manual billing. There is deliberately no payment gateway anywhere in this
// codebase: tenants pay out of band and an admin records what was received.
//
// Amounts are minor units (paisa for PKR, cents for USD) and the currency is
// stored per payment, because a payment records what actually arrived. Changing
// the instance currency later must never rewrite history.

function recordPayment(mysqli $conn, array $data) {
    $stmt = $conn->prepare(
        "INSERT INTO payments
            (user_id, plan_id, amount_minor, currency, period_start, period_end,
             method, reference, note, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        'iiissssssi',
        $data['user_id'], $data['plan_id'], $data['amount_minor'], $data['currency'],
        $data['period_start'], $data['period_end'], $data['method'],
        $data['reference'], $data['note'], $data['created_by']
    );
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return $id;
}

function getPayments(mysqli $conn, $userId = null, $limit = 100, $search = '') {
    $limit = max(1, min(500, (int)$limit));

    $sql = "SELECT p.*, u.name AS tenant_name, u.email AS tenant_email,
                   pl.name AS plan_name, a.name AS logged_by
            FROM payments p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN plans pl ON p.plan_id = pl.id
            LEFT JOIN users a ON p.created_by = a.id";
    $where = [];
    $types = '';
    $args = [];

    if ($userId !== null) {
        $where[] = 'p.user_id = ?';
        $types .= 'i';
        $args[] = (int)$userId;
    }
    if ($search !== '') {
        $where[] = '(u.name LIKE ? OR u.email LIKE ? OR p.reference LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'sss';
        $args[] = $like; $args[] = $like; $args[] = $like;
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    // LIMIT is interpolated, not bound — it is clamped to an int above, and
    // MySQL will not accept a placeholder here in every version.
    $sql .= ' ORDER BY p.created_at DESC, p.id DESC LIMIT ' . $limit;

    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Revenue for a calendar month, split by currency.
//
// Deliberately NOT summed into one figure: mixing currencies without a rate
// produces a meaningless number, and a meaningless number on a dashboard gets
// believed. Callers render one row per currency.
function paymentTotals(mysqli $conn, $periodYm = null) {
    $periodYm = $periodYm ?? gmdate('Y-m');
    $stmt = $conn->prepare(
        "SELECT currency, SUM(amount_minor) AS total, COUNT(*) AS payments
         FROM payments
         WHERE DATE_FORMAT(created_at, '%Y-%m') = ?
         GROUP BY currency ORDER BY total DESC"
    );
    $stmt->bind_param('s', $periodYm);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Paid subscriptions whose period has ended, or ends within $days.
//
// These are *flagged*, never auto-downgraded. An automatic downgrade would cut
// off a paying customer whose payment simply has not been keyed in yet — the
// failure mode of a manual billing process. Losing access is far more damaging
// than a few days of unpaid service, so a human decides.
function lapsingSubscriptions(mysqli $conn, $days = 7) {
    $days = (int)$days;
    $stmt = $conn->prepare(
        "SELECT s.id, s.user_id, s.current_period_end, s.status,
                u.name, u.email, u.status AS user_status,
                pl.name AS plan_name, pl.price_cents,
                (SELECT MAX(pay.period_end) FROM payments pay WHERE pay.user_id = s.user_id) AS paid_until
         FROM subscriptions s
         JOIN users u ON s.user_id = u.id
         JOIN plans pl ON s.plan_id = pl.id
         WHERE pl.price_cents > 0
           AND s.status IN ('active','trialing','past_due')
           AND s.current_period_end IS NOT NULL
           AND s.current_period_end <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY)
           -- Suppress rows already covered by a logged payment.
           AND NOT EXISTS (
               SELECT 1 FROM payments pay
               WHERE pay.user_id = s.user_id
                 AND pay.period_end IS NOT NULL
                 AND pay.period_end >= s.current_period_end
           )
         ORDER BY s.current_period_end ASC"
    );
    $stmt->bind_param('i', $days);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Extends (or creates) the subscription window a logged payment covers, and
// moves the tenant onto the plan they paid for.
function applyPaymentToSubscription(mysqli $conn, $userId, $planId, $periodStart, $periodEnd) {
    if (!$planId) return;

    $stmt = $conn->prepare("UPDATE users SET plan_id = ? WHERE id = ?");
    $stmt->bind_param('ii', $planId, $userId);
    $stmt->execute();
    $stmt->close();

    // Reuse the tenant's live subscription row if there is one, so plan history
    // stays a history rather than accumulating a row per payment.
    $stmt = $conn->prepare(
        "SELECT id FROM subscriptions WHERE user_id = ? AND status IN ('active','trialing','past_due')
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $stmt = $conn->prepare(
            "UPDATE subscriptions
             SET plan_id = ?, status = 'active', current_period_start = ?, current_period_end = ?
             WHERE id = ?"
        );
        $stmt->bind_param('issi', $planId, $periodStart, $periodEnd, $existing['id']);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO subscriptions (user_id, plan_id, status, current_period_start, current_period_end)
             VALUES (?, ?, 'active', ?, ?)"
        );
        $stmt->bind_param('iiss', $userId, $planId, $periodStart, $periodEnd);
    }
    $stmt->execute();
    $stmt->close();
}

// --- How a tenant contacts sales (#43) ---------------------------------------
//
// The plan cards on billing.php need a way to say "I want this plan", and there
// is no checkout to send anyone to — billing is manual by design. It used to fall
// back to `mailto:MAIL_FROM`, which is wrong in a way that is easy to miss:
// MAIL_FROM is the SMTP envelope sender, chosen so mail is deliverable
// (`no-reply@…` on most instances). Pointing every upgrade enquiry at it sends
// tenants to an unread mailbox and the owner never learns they asked.
//
// So the contact route is configuration: which methods are offered, what address
// and number they use, and what the button says. Stored in `app_settings`
// alongside the payment instructions rather than in a new table — it is one
// instance-wide record of five short values, which is exactly what that table is.

function billingContactMethodChoices() {
    return [
        'email'    => 'Email',
        'whatsapp' => 'WhatsApp',
        'phone'    => 'Phone call',
    ];
}

function billingContactDefaultLabel() {
    return 'Contact us to switch';
}

// The saved configuration, with anything unusable removed.
//
// A method is only *enabled* if the detail it needs is present and valid: an
// enabled WhatsApp button with no number is a button that opens nothing, and the
// admin ticking a box is not the same thing as the instance being able to honour
// it. Validating on the way out as well as on the way in matters because these
// rows can also be written by an older save, a restored backup, or by hand.
//
// Returns ['methods' => [...], 'email' => '', 'phone' => '', 'label' => '',
//          'primary' => ''] — `methods` in display order, the preferred method
// first, and empty when nothing is configured.
function billingContactConfig(?mysqli $conn = null) {
    $db = settingsConn($conn);
    $read = fn($key) => $db ? (string)(overrideSetting($db, $key) ?? '') : '';

    return billingContactFrom([
        'methods' => $read('billing_contact_methods'),
        'email'   => $read('billing_contact_email'),
        'phone'   => $read('billing_contact_phone'),
        'label'   => $read('billing_contact_label'),
        'primary' => $read('billing_contact_primary'),
    ]);
}

// The rules, with no database in sight — the same split as apptValidateWeek(),
// and for the same reason: every interesting case is a refusal (a method with no
// address behind it, a number that is not a number, a preference for a method
// that is switched off) and a test that needed a live instance to state them
// would be testing the fixture.
function billingContactFrom(array $raw) {
    $email = trim((string)($raw['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';

    // Bare E.164 digits, the same rule as the handover number, so the '+' is
    // added where it is displayed and left off where it is not (wa.me takes
    // digits only).
    $phone = e164Digits($raw['phone'] ?? '');
    if (!is_string($phone)) $phone = '';

    $requested = array_filter(array_map('trim', explode(',', (string)($raw['methods'] ?? ''))));

    $methods = [];
    foreach (array_keys(billingContactMethodChoices()) as $method) {
        if (!in_array($method, $requested, true)) continue;
        if ($method === 'email' && $email === '') continue;
        if (($method === 'whatsapp' || $method === 'phone') && $phone === '') continue;
        $methods[] = $method;
    }

    // The preferred method leads, because it is the one the admin wants used and
    // the first button is the one people press. It is only honoured while it is
    // still one of the enabled ones.
    $primary = (string)($raw['primary'] ?? '');
    if (in_array($primary, $methods, true)) {
        $methods = array_merge([$primary], array_values(array_diff($methods, [$primary])));
    } else {
        $primary = $methods[0] ?? '';
    }

    $label = trim((string)($raw['label'] ?? ''));
    if ($label === '') $label = billingContactDefaultLabel();

    return [
        'methods' => $methods,
        'email'   => $email,
        'phone'   => $phone,
        'label'   => $label,
        'primary' => $primary,
    ];
}

// The buttons for one plan card: [['method','label','url','icon'], ...].
//
// Every link carries the plan, because "someone asked about a plan" is not an
// enquiry anyone can act on — the owner has to know which one. The plan name is
// encoded for the context it lands in (a mailto query, a wa.me query) rather than
// pasted in, so an ampersand or a '#' in a plan name cannot truncate the message.
//
// $ctx carries the asking tenant: ['tenant' => display name, 'email' => login
// address]. Both are optional — the WhatsApp text simply says less without them —
// because a profile is optional everywhere else in this app too.
//
// The URLs are *not* escaped here. Escaping is the template's job and doing it in
// both places double-encodes; what happens here is the thing escaping cannot do,
// which is refuse a value that is not an address or a number at all.
function billingContactLinks(?mysqli $conn, array $plan, array $ctx = [], ?array $config = null) {
    $config = $config ?? billingContactConfig($conn);
    if (!$config['methods']) return [];

    $planName = trim((string)($plan['name'] ?? ''));
    $who = trim((string)($ctx['tenant'] ?? ''));
    $account = trim((string)($ctx['email'] ?? ''));

    $subject = 'Plan enquiry: ' . ($planName !== '' ? $planName : 'my subscription');
    $body = 'Hello,' . "\n\n"
        . 'I would like to move to the ' . ($planName !== '' ? $planName : 'following') . ' plan.'
        . ($who !== '' ? "\n\nAccount: " . $who : '')
        . ($account !== '' ? "\nSign-in email: " . $account : '')
        . "\n";

    $labels = billingContactMethodChoices();
    $out = [];
    foreach ($config['methods'] as $method) {
        switch ($method) {
            case 'email':
                $out[] = [
                    'method' => 'email',
                    'label'  => $labels['email'],
                    'icon'   => 'bi-envelope',
                    'url'    => 'mailto:' . $config['email']
                        . '?subject=' . rawurlencode($subject)
                        . '&body=' . rawurlencode($body),
                ];
                break;
            case 'whatsapp':
                // wa.me wants digits with no '+' and the message as one query
                // parameter; a newline survives urlencoding, so the prefilled
                // chat reads as a message rather than one long line.
                $out[] = [
                    'method' => 'whatsapp',
                    'label'  => $labels['whatsapp'],
                    'icon'   => 'bi-whatsapp',
                    'url'    => 'https://wa.me/' . $config['phone'] . '?text=' . rawurlencode($body),
                ];
                break;
            case 'phone':
                $out[] = [
                    'method' => 'phone',
                    'label'  => $labels['phone'],
                    'icon'   => 'bi-telephone',
                    // tel: takes the international form *with* the plus — that is
                    // what tells a dialler the number is not local.
                    'url'    => 'tel:+' . $config['phone'],
                ];
                break;
        }
    }
    return $out;
}

function paymentMethods() {
    return [
        'bank_transfer' => 'Bank transfer',
        'cash'          => 'Cash',
        'easypaisa'     => 'Easypaisa',
        'jazzcash'      => 'JazzCash',
        'card'          => 'Card (offline)',
        'cheque'        => 'Cheque',
        'other'         => 'Other',
    ];
}

function paymentMethodLabel($code) {
    return paymentMethods()[$code] ?? ($code ?: '—');
}

// Parses a human-entered major-unit amount ("1,500" / "1500.50") into minor
// units for the given currency. Returns null when it is not a usable amount.
//
// Uses string arithmetic via round() on a scaled float only after stripping
// separators; the scale comes from the currency's own exponent, so a 3-decimal
// dinar and a 0-decimal yen both land correctly.
function parseMoneyInput($raw, $currency) {
    $clean = str_replace([',', ' ', "\u{00A0}"], '', trim((string)$raw));
    if ($clean === '' || !preg_match('/^\d+(\.\d+)?$/', $clean)) return null;

    $decimals = currencyFormat($currency)['decimals'];
    $minor = (int)round(((float)$clean) * (10 ** $decimals));
    return $minor >= 0 ? $minor : null;
}
