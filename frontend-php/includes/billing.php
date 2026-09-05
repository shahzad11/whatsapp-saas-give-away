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
