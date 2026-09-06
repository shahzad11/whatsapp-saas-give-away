<?php

// Appointment booking (#14).
//
// The model is only allowed to *propose* a booking. Everything that decides
// whether one is real — does the service exist, is the business open then, is
// the slot already taken, is it in the past — happens here, in code, against the
// database. An LLM will confidently offer 3am on a closed Sunday, and a customer
// who receives "confirmed" for a slot that does not exist is a worse outcome
// than no booking at all.

// The line a model appends to its reply when the customer has agreed. Chosen to
// be something no human types and no model emits by accident, and it is always
// stripped before the reply reaches WhatsApp.
const APPT_ACTION_OPEN = '<<<APPT';
const APPT_ACTION_CLOSE = '>>>';

// --- Services ---------------------------------------------------------------

function apptServices(mysqli $conn, $userId, $onlyActive = true) {
    $sql = "SELECT * FROM appointment_services WHERE user_id = ?"
         . ($onlyActive ? " AND is_active = 1" : "")
         . " ORDER BY sort_order ASC, name ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function apptSaveService(mysqli $conn, $userId, $name, $minutes, $description, $id = null) {
    $name = trim((string)$name);
    if ($name === '') return [false, 'A service needs a name.'];
    // A duration drives slot maths; zero or a day-long "appointment" is a
    // mistake that would silently break the calendar.
    $minutes = (int)$minutes;
    if ($minutes < 5 || $minutes > 480) return [false, 'Duration must be between 5 and 480 minutes.'];
    $description = mb_substr(trim((string)$description), 0, 255);

    if ($id) {
        $stmt = $conn->prepare("UPDATE appointment_services SET name = ?, duration_minutes = ?, description = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param('sisii', $name, $minutes, $description, $id, $userId);
    } else {
        // The plan's service cap applies to *creating* one, never to editing an
        // existing one. A tenant who drops to a smaller plan is already over the
        // limit through no action of their own; blocking edits too would leave
        // them unable to correct their own data, which is a punishment rather
        // than a limit. Existing services keep working — only growth stops.
        [$serviceOk, $serviceUsed, $serviceLimit] = checkServiceQuota($conn, $userId);
        if (!$serviceOk) {
            return [false, $serviceLimit === 0
                ? 'Your plan does not include bookable services.'
                : 'Your plan allows ' . $serviceLimit . ' service'
                  . ($serviceLimit === 1 ? '' : 's') . ' and you have ' . $serviceUsed . '.'];
        }

        $stmt = $conn->prepare("INSERT INTO appointment_services (user_id, name, duration_minutes, description) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('isis', $userId, $name, $minutes, $description);
    }
    $stmt->execute();
    $stmt->close();
    return [true, null];
}

function apptSetServiceActive(mysqli $conn, $userId, $id, $active) {
    $flag = $active ? 1 : 0;
    $stmt = $conn->prepare("UPDATE appointment_services SET is_active = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param('iii', $flag, $id, $userId);
    $stmt->execute();
    $stmt->close();
}

// Matches a service by name the way a customer would say it — exact first, then
// case-insensitive, then a contains match. Returns null rather than guessing
// between two plausible services.
function apptMatchService(array $services, $name) {
    $name = trim(mb_strtolower((string)$name));
    if ($name === '') return null;

    foreach ($services as $s) if (mb_strtolower($s['name']) === $name) return $s;

    $partial = [];
    foreach ($services as $s) {
        $candidate = mb_strtolower($s['name']);
        if (str_contains($candidate, $name) || str_contains($name, $candidate)) $partial[] = $s;
    }
    return count($partial) === 1 ? $partial[0] : null;
}

// --- Availability -----------------------------------------------------------

function apptAvailability(mysqli $conn, $userId) {
    $stmt = $conn->prepare("SELECT * FROM appointment_availability WHERE user_id = ? ORDER BY weekday ASC, start_time ASC");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Validates a whole week and writes it, or writes nothing (#25).
//
// Returns [ok, errors] keyed by the form field, so a bad row is pointed at
// instead of vanishing. This used to `continue` past anything invalid, which
// meant a typo silently produced a day that looked configured in the form and
// was closed in the database — the worst of both, because the tenant had no way
// to tell.
//
// All-or-nothing on purpose: a half-saved week is a schedule nobody intended,
// and the failure would be discovered by a customer being turned away.
function apptSaveAvailability(mysqli $conn, $userId, array $windows) {
    [$clean, $errors] = apptValidateWeek($windows);
    if ($errors) return [false, $errors];

    $stmt = $conn->prepare("DELETE FROM appointment_availability WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO appointment_availability (user_id, weekday, start_time, end_time) VALUES (?, ?, ?, ?)");
    foreach ($clean as $w) {
        $stmt->bind_param('iiss', $userId, $w['weekday'], $w['start'], $w['end']);
        $stmt->execute();
    }
    $stmt->close();

    return [true, []];
}

// The rules, with no database in sight: returns [acceptedWindows, errors].
//
// Separate from the write so the validation is testable on its own — the
// interesting cases are all refusals, and a test that had to reach a database to
// prove "this week is rejected" would be proving it against the wrong thing.
function apptValidateWeek(array $windows) {
    $errors = [];
    $clean = [];

    foreach ($windows as $w) {
        $day = (int)($w['weekday'] ?? -1);
        $field = 'day[' . $day . '][start][]';

        if ($day < 0 || $day > 6) continue;         // not a weekday: a crafted POST

        $start = chatbotValidTime($w['start'] ?? '');
        $end = chatbotValidTime($w['end'] ?? '');

        if (!$start || !$end) {
            $errors[$field] = 'Both a start and an end time are needed.';
            continue;
        }
        // A window that ends before it starts is not a night shift here — the
        // business day is a day, and the slot maths reads it as negative.
        if ($start >= $end) {
            $errors[$field] = 'The end time must be after the start time.';
            continue;
        }
        $clean[] = ['weekday' => $day, 'start' => $start, 'end' => $end];
    }

    // Overlaps within a day. Two windows that overlap do not break the slot
    // maths — apptWithinAvailability() only needs one match — but they are
    // always a mistake, and they make the schedule unreadable to the tenant and
    // to the model, which is handed this list verbatim.
    foreach (apptGroupByWeekday($clean) as $day => $dayWindows) {
        usort($dayWindows, fn($a, $b) => strcmp($a['start'], $b['start']));
        for ($i = 1; $i < count($dayWindows); $i++) {
            if ($dayWindows[$i]['start'] < $dayWindows[$i - 1]['end']) {
                $errors['day[' . $day . '][start][]'] = 'These times overlap another window on the same day.';
                break;
            }
        }
    }

    return [$clean, $errors];
}

function apptGroupByWeekday(array $windows) {
    $byDay = [];
    foreach ($windows as $w) {
        $byDay[(int)($w['weekday'] ?? 0)][] = $w;
    }
    return $byDay;
}

// Monday to Friday, 09:00–17:00, weekends closed (#25).
//
// Shown as the pre-filled state of a tenant who has never saved a schedule —
// *not* written to the database on their behalf. Seeding rows would mean a tenant
// who deliberately wants Saturdays only has to first delete a week they never
// asked for, and it would make "no schedule configured" indistinguishable from
// "the default schedule", which the booking prompt has to tell apart.
function apptDefaultAvailability() {
    $week = [];
    foreach ([1, 2, 3, 4, 5] as $weekday) {
        $week[$weekday] = [['start_time' => '09:00', 'end_time' => '17:00']];
    }
    return $week;
}

function apptWeekdayNames() {
    return [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
}

// Is [start, start+duration) inside one open window on that weekday?
// $localStart is a DateTime already in the tenant's timezone.
function apptWithinAvailability(array $availability, DateTime $localStart, $durationMinutes) {
    if (!$availability) return false;

    $weekday = (int)$localStart->format('w');
    $startMin = ((int)$localStart->format('H')) * 60 + (int)$localStart->format('i');
    $endMin = $startMin + (int)$durationMinutes;

    foreach ($availability as $w) {
        if ((int)$w['weekday'] !== $weekday) continue;
        [$wsH, $wsM] = array_map('intval', explode(':', $w['start_time']));
        [$weH, $weM] = array_map('intval', explode(':', $w['end_time']));
        // The appointment must finish before closing, not merely start before it.
        if ($startMin >= $wsH * 60 + $wsM && $endMin <= $weH * 60 + $weM) return true;
    }
    return false;
}

// The first moment the business is open and free, at or after $fromLocal (#25).
//
// "We are not open then" is true but unhelpful — the customer has to guess again,
// and a bot that makes someone guess three times is a bot they stop using. This
// walks forward a day at a time to the horizon, so the suggestion is always a
// real slot: inside a window, long enough for the service to finish before
// closing, not already booked, and past the minimum notice.
//
// Returns a DateTime in the tenant's timezone, or null when there is genuinely
// nothing — no schedule at all, or a fully booked horizon.
function apptNextAvailable(mysqli $conn, $userId, array $config, array $service, DateTime $fromLocal, $timezone) {
    $availability = apptAvailability($conn, $userId);
    if (!$availability) return null;

    $duration = max(5, (int)$service['duration_minutes']);
    $horizon = max(1, (int)($config['appointment_horizon_days'] ?? 30));
    $byDay = [];
    foreach ($availability as $w) $byDay[(int)$w['weekday']][] = $w;

    $lead = max(0, (int)($config['appointment_lead_minutes'] ?? 60));
    $earliestUtc = (new DateTime('now', new DateTimeZone('UTC')))->modify('+' . $lead . ' minutes');

    // Candidate starts are on a 15-minute grid: it is what people actually say
    // ("half two", "quarter past"), and stepping by the service duration would
    // miss a free slot that starts between two notional ones.
    $step = 15;
    $cursor = (clone $fromLocal);

    for ($dayOffset = 0; $dayOffset <= $horizon; $dayOffset++) {
        $day = (clone $cursor)->modify('+' . $dayOffset . ' days');
        $weekday = (int)$day->format('w');
        if (empty($byDay[$weekday])) continue;

        $windows = $byDay[$weekday];
        usort($windows, fn($a, $b) => strcmp($a['start_time'], $b['start_time']));

        foreach ($windows as $w) {
            [$sH, $sM] = array_map('intval', explode(':', $w['start_time']));
            [$eH, $eM] = array_map('intval', explode(':', $w['end_time']));
            $lastStart = ($eH * 60 + $eM) - $duration;

            for ($minute = $sH * 60 + $sM; $minute <= $lastStart; $minute += $step) {
                $candidate = (clone $day)->setTime(intdiv($minute, 60), $minute % 60, 0);
                // Only the very first day can be partly in the past.
                if ($dayOffset === 0 && $candidate < $fromLocal) continue;

                $utc = (clone $candidate)->setTimezone(new DateTimeZone('UTC'));
                if ($utc < $earliestUtc) continue;
                if (apptConflicts($conn, $userId, $utc, $duration)) continue;

                return $candidate;
            }
        }
    }
    return null;
}

// "We are closed on Sunday." / "We are open Monday 09:00–17:00."
// The schedule in the tenant's own words, for the refusal message.
function apptDayScheduleLabel(array $availability, $weekday) {
    $names = apptWeekdayNames();
    $windows = array_values(array_filter($availability, fn($w) => (int)$w['weekday'] === (int)$weekday));
    if (!$windows) return 'We are closed on ' . $names[(int)$weekday] . 's.';

    usort($windows, fn($a, $b) => strcmp($a['start_time'], $b['start_time']));
    $parts = array_map(fn($w) => $w['start_time'] . '–' . $w['end_time'], $windows);
    return 'On ' . $names[(int)$weekday] . 's we are open ' . implode(' and ', $parts) . '.';
}

// --- Booking ----------------------------------------------------------------

function apptConflicts(mysqli $conn, $userId, DateTime $startUtc, $durationMinutes, $excludeId = null) {
    $start = $startUtc->format('Y-m-d H:i:s');
    $end = (clone $startUtc)->modify('+' . (int)$durationMinutes . ' minutes')->format('Y-m-d H:i:s');

    // Overlap test: an existing booking clashes when it starts before this one
    // ends and ends after this one starts. Only live bookings can clash —
    // cancelled ones free the slot.
    $sql = "SELECT id, service_name, scheduled_at FROM appointments
            WHERE user_id = ? AND status = 'booked'
              AND scheduled_at < ?
              AND DATE_ADD(scheduled_at, INTERVAL duration_minutes MINUTE) > ?";
    $params = [$userId, $end, $start];
    $types = 'iss';
    if ($excludeId) { $sql .= " AND id <> ?"; $params[] = $excludeId; $types .= 'i'; }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Validates a proposed booking and returns [DateTime utc, null] or [null, reason].
// $localDateTime is 'YYYY-MM-DD HH:MM' as the customer and the tenant think of it.
function apptValidateSlot(mysqli $conn, $userId, array $config, array $service, $localDateTime, $timezone, $excludeId = null) {
    try {
        $tz = new DateTimeZone($timezone ?: 'UTC');
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }

    $local = DateTime::createFromFormat('Y-m-d H:i', trim((string)$localDateTime), $tz);
    if (!$local) return [null, 'I could not understand that date and time.'];
    // createFromFormat is forgiving: "2026-02-31 10:00" silently rolls into
    // March. Reject anything that did not survive the round trip.
    if ($local->format('Y-m-d H:i') !== trim((string)$localDateTime)) {
        return [null, 'That date does not exist.'];
    }

    $utc = (clone $local)->setTimezone(new DateTimeZone('UTC'));
    $now = new DateTime('now', new DateTimeZone('UTC'));

    $lead = max(0, (int)($config['appointment_lead_minutes'] ?? 60));
    $earliest = (clone $now)->modify('+' . $lead . ' minutes');
    if ($utc < $earliest) {
        return [null, $lead > 0
            ? 'That is too soon — bookings need at least ' . apptHumanMinutes($lead) . ' notice.'
            : 'That time has already passed.'];
    }

    $horizon = max(1, (int)($config['appointment_horizon_days'] ?? 30));
    if ($utc > (clone $now)->modify('+' . $horizon . ' days')) {
        return [null, 'That is further ahead than we take bookings (' . $horizon . ' days).'];
    }

    // #25: a refusal now says *why* and offers the next real opening. The
    // suggestion is computed here, against the calendar, for the same reason the
    // booking itself is: the model cannot see the schedule, so anything it
    // offered on its own would be a guess it presented as fact.
    $availability = apptAvailability($conn, $userId);
    if (!apptWithinAvailability($availability, $local, (int)$service['duration_minutes'])) {
        return [null, apptDayScheduleLabel($availability, (int)$local->format('w'))
            . apptSuggestionSuffix($conn, $userId, $config, $service, $local, $timezone)];
    }

    if (apptConflicts($conn, $userId, $utc, (int)$service['duration_minutes'], $excludeId)) {
        return [null, 'That slot is already taken.'
            . apptSuggestionSuffix($conn, $userId, $config, $service, $local, $timezone)];
    }

    return [$utc, null];
}

// ' The next time we could fit you in is Tue 9 Sep, 09:00 — would that suit?'
// or '' when there is nothing to offer.
//
// A leading space so callers can concatenate it onto a sentence, and empty
// rather than an apology when the diary is genuinely full — the caller's own
// message already covers that. Ending in a question is what lets the reply path
// tell a refusal that already asks something from one that still needs the
// "could you suggest another time?" prompt appended.
function apptSuggestionSuffix(mysqli $conn, $userId, array $config, array $service, DateTime $local, $timezone) {
    $next = apptNextAvailable($conn, $userId, $config, $service, $local, $timezone);
    return $next === null
        ? ''
        : ' The next time we could fit you in is ' . $next->format('D j M, H:i') . ' — would that suit?';
}

// A refusal, with the follow-up prompt only when it does not already ask
// something. Without the test the customer got "…would that suit? Could you
// suggest another time?", which reads as the bot arguing with itself.
function apptRefusalLine($why) {
    $why = trim((string)$why);
    return str_ends_with($why, '?') ? $why : $why . ' Could you suggest another time?';
}

function apptHumanMinutes($minutes) {
    $minutes = (int)$minutes;
    if ($minutes % 1440 === 0) return ($minutes / 1440) . ' day' . ($minutes === 1440 ? '' : 's');
    if ($minutes % 60 === 0) return ($minutes / 60) . ' hour' . ($minutes === 60 ? '' : 's');
    return $minutes . ' minutes';
}

function apptCreate(mysqli $conn, $userId, array $data) {
    $stmt = $conn->prepare(
        "INSERT INTO appointments
           (user_id, account_id, service_id, service_name, duration_minutes, customer_phone,
            customer_name, chat_id, scheduled_at, notes, source)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    );
    $accountId = $data['account_id'] ?? null;
    $serviceId = $data['service_id'] ?? null;
    $stmt->bind_param(
        'iiisissssss',
        $userId, $accountId, $serviceId, $data['service_name'], $data['duration_minutes'],
        $data['customer_phone'], $data['customer_name'], $data['chat_id'],
        $data['scheduled_at'], $data['notes'], $data['source']
    );
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();

    apptScheduleReminders($conn, $id);
    return $id;
}

function apptById(mysqli $conn, $userId, $id) {
    $stmt = $conn->prepare("SELECT * FROM appointments WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// The customer's live booking in this chat — what "cancel my appointment" means
// when they do not say which one.
function apptNextForChat(mysqli $conn, $userId, $chatId) {
    $stmt = $conn->prepare(
        "SELECT * FROM appointments
         WHERE user_id = ? AND chat_id = ? AND status = 'booked' AND scheduled_at >= UTC_TIMESTAMP()
         ORDER BY scheduled_at ASC LIMIT 1"
    );
    $stmt->bind_param('is', $userId, $chatId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function apptSetStatus(mysqli $conn, $userId, $id, $status) {
    if (!in_array($status, ['booked', 'completed', 'cancelled', 'no_show'], true)) return false;
    $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param('sii', $status, $id, $userId);
    $stmt->execute();
    $changed = $stmt->affected_rows > 0;
    $stmt->close();

    // A cancelled appointment must not go on reminding anyone.
    if ($changed && $status !== 'booked') {
        $stmt = $conn->prepare(
            "UPDATE appointment_reminders SET status = 'skipped', detail = 'appointment no longer booked'
             WHERE appointment_id = ? AND status = 'pending'"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }
    return $changed;
}

function apptReschedule(mysqli $conn, $userId, $id, DateTime $utc) {
    $when = $utc->format('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE appointments SET scheduled_at = ?, status = 'booked' WHERE id = ? AND user_id = ?");
    $stmt->bind_param('sii', $when, $id, $userId);
    $stmt->execute();
    $changed = $stmt->affected_rows > 0;
    $stmt->close();

    if ($changed) {
        // The old reminders were computed from the old time; drop and rebuild.
        $stmt = $conn->prepare("DELETE FROM appointment_reminders WHERE appointment_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        apptScheduleReminders($conn, $id);
    }
    return $changed;
}

// 'YYYY-MM-DD' in $timezone + a wall-clock time -> 'Y-m-d H:i:s' in UTC.
// A malformed date falls back to the raw value so a bad query string filters
// oddly rather than throwing on a page load.
function apptLocalDateBoundaryToUtc($date, $timezone, $time) {
    try {
        $dt = new DateTime($date . ' ' . $time, new DateTimeZone($timezone ?: 'UTC'));
    } catch (Exception $e) {
        return $date . ' ' . $time;
    }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function apptList(mysqli $conn, $userId, array $filters = []) {
    $sql = "SELECT * FROM appointments WHERE user_id = ?";
    $types = 'i';
    $params = [$userId];

    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $sql .= " AND status = ?"; $types .= 's'; $params[] = $filters['status'];
    }
    // The From/To inputs are dates in the tenant's timezone, but scheduled_at is
    // UTC. Comparing them directly put the boundary in the wrong place by the
    // tenant's offset — in Asia/Karachi (UTC+5) a "From today" filter silently
    // included five hours of yesterday evening and cut off today's last five
    // hours. The boundary is converted, not the column, so the index still works.
    $tz = $filters['tz'] ?? 'UTC';
    if (!empty($filters['from'])) {
        $sql .= " AND scheduled_at >= ?"; $types .= 's';
        $params[] = apptLocalDateBoundaryToUtc($filters['from'], $tz, '00:00:00');
    }
    if (!empty($filters['to'])) {
        $sql .= " AND scheduled_at <= ?"; $types .= 's';
        $params[] = apptLocalDateBoundaryToUtc($filters['to'], $tz, '23:59:59');
    }
    if (!empty($filters['q'])) {
        $sql .= " AND (customer_name LIKE ? OR customer_phone LIKE ? OR service_name LIKE ?)";
        $types .= 'sss';
        $like = '%' . $filters['q'] . '%';
        array_push($params, $like, $like, $like);
    }
    $sql .= " ORDER BY scheduled_at " . (($filters['order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC') . " LIMIT 500";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function apptCounts(mysqli $conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT
            SUM(status = 'booked' AND scheduled_at >= UTC_TIMESTAMP()) AS upcoming,
            SUM(status = 'booked' AND scheduled_at <  UTC_TIMESTAMP()) AS overdue,
            SUM(status = 'completed') AS completed,
            SUM(status = 'cancelled') AS cancelled
         FROM appointments WHERE user_id = ?"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return array_map('intval', $row ?: []);
}

// --- Reminders --------------------------------------------------------------

function apptReminderMinutes(array $config) {
    $raw = (string)($config['reminder_minutes'] ?? '1440,60');
    $out = [];
    foreach (explode(',', $raw) as $part) {
        $n = (int)trim($part);
        if ($n > 0 && $n <= 20160) $out[$n] = $n;   // up to two weeks
    }
    rsort($out);
    return $out;
}

// Rows are written when the appointment is made, not searched for later: a
// scheduler that has to work out what to send is a scheduler that sends twice.
function apptScheduleReminders(mysqli $conn, $appointmentId) {
    $stmt = $conn->prepare("SELECT a.*, c.reminder_minutes FROM appointments a
                            LEFT JOIN chatbot_configs c ON c.user_id = a.user_id WHERE a.id = ?");
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$appt || $appt['status'] !== 'booked') return;

    $minutes = apptReminderMinutes(['reminder_minutes' => $appt['reminder_minutes'] ?? '1440,60']);
    if (!$minutes) return;

    $stmt = $conn->prepare(
        "INSERT INTO appointment_reminders (appointment_id, minutes_before, send_at)
         VALUES (?, ?, DATE_SUB(?, INTERVAL ? MINUTE))
         ON DUPLICATE KEY UPDATE send_at = VALUES(send_at), status = 'pending', sent_at = NULL"
    );
    foreach ($minutes as $m) {
        $stmt->bind_param('iisi', $appointmentId, $m, $appt['scheduled_at'], $m);
        $stmt->execute();
    }
    $stmt->close();

    // A reminder whose moment already passed when the booking was made (someone
    // books for two hours' time with a 24h reminder configured) is skipped, not
    // fired immediately.
    $stmt = $conn->prepare(
        "UPDATE appointment_reminders SET status = 'skipped', detail = 'booked after this reminder was due'
         WHERE appointment_id = ? AND status = 'pending' AND send_at <= UTC_TIMESTAMP()"
    );
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $stmt->close();
}

function apptDueReminders(mysqli $conn, $limit = 50) {
    $limit = max(1, min(200, (int)$limit));
    $sql = "SELECT r.id AS reminder_id, r.minutes_before, a.*, w.session_id
            FROM appointment_reminders r
            JOIN appointments a ON r.appointment_id = a.id
            LEFT JOIN wa_accounts w ON a.account_id = w.id
            WHERE r.status = 'pending' AND r.send_at <= UTC_TIMESTAMP()
              AND a.status = 'booked' AND a.scheduled_at > UTC_TIMESTAMP()
            ORDER BY r.send_at ASC LIMIT " . $limit;
    $res = $conn->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

// Claims a reminder before sending it. The UPDATE ... WHERE status='pending' is
// the lock: two schedulers racing produce one winner, and the loser sends
// nothing rather than the customer getting the message twice.
function apptClaimReminder(mysqli $conn, $reminderId) {
    $stmt = $conn->prepare("UPDATE appointment_reminders SET status = 'sent', sent_at = UTC_TIMESTAMP()
                            WHERE id = ? AND status = 'pending'");
    $stmt->bind_param('i', $reminderId);
    $stmt->execute();
    $claimed = $stmt->affected_rows === 1;
    $stmt->close();
    return $claimed;
}

function apptMarkReminder(mysqli $conn, $reminderId, $status, $detail = null) {
    $detail = $detail === null ? null : mb_substr((string)$detail, 0, 255);
    $stmt = $conn->prepare("UPDATE appointment_reminders SET status = ?, detail = ? WHERE id = ?");
    $stmt->bind_param('ssi', $status, $detail, $reminderId);
    $stmt->execute();
    $stmt->close();
}

function apptReminderText(array $appt, $whenLocal, $minutesBefore) {
    $lead = apptHumanMinutes($minutesBefore);
    $name = trim((string)($appt['customer_name'] ?? ''));
    $hello = $name !== '' ? "Hi {$name}, " : 'Hi, ';
    return $hello . "a reminder that your {$appt['service_name']} is in {$lead} — {$whenLocal}. "
        . 'Reply here if you need to change or cancel it.';
}
