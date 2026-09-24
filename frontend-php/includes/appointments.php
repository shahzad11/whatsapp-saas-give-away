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

// A service is a name and a description. How long it takes is not its business:
// every appointment lasts one slot, and the slot length is the tenant's single
// setting on the Appointments tab.
//
// It used to carry its own 5–480 minute duration, which read well and worked
// badly. Two lengths described the same thing, so they could disagree: a
// 45-minute service on a half-hour diary was offered starts that ran into the
// next block, and a tenant who changed the slot length found their services
// still fragmenting the day. One number, in one place, is the whole fix.
//
// `appointment_services.duration_minutes` is left in the table and simply not
// written, like `chatbot_configs.greeting`: it is `NOT NULL DEFAULT 30`, so
// omitting it is valid, and a future per-service length needs no migration.
// `appointments.duration_minutes` is still written — that one is the record of
// how long a booking that already exists actually is, and every conflict check
// reads it.
function apptSaveService(mysqli $conn, $userId, $name, $description, $id = null) {
    $name = trim((string)$name);
    if ($name === '') return [false, 'A service needs a name.'];
    $description = mb_substr(trim((string)$description), 0, 255);

    if ($id) {
        $stmt = $conn->prepare("UPDATE appointment_services SET name = ?, description = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param('ssii', $name, $description, $id, $userId);
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

        $stmt = $conn->prepare("INSERT INTO appointment_services (user_id, name, description) VALUES (?, ?, ?)");
        $stmt->bind_param('iss', $userId, $name, $description);
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

// --- The slot grid ----------------------------------------------------------

// The block size the diary is divided into, when the tenant has not chosen one.
// Half an hour, which is also the default service length, so a fresh tenant's
// slots line up with their fresh service instead of being offered twice as often
// as the appointment lasts.
const APPT_DEFAULT_SLOT_MINUTES = 30;

// The tenant's block size, as a number of minutes.
//
// Read through here and nowhere else, so the form, the offered times, the
// booking check and the prompt cannot each fall back to a different grid — which
// is exactly how a bot comes to offer a time it then refuses to book.
//
// Any length between five minutes and eight hours is honoured, not only the ones
// the dropdown offers: the column can also hold a value from an earlier release
// or a hand-edit, and quietly rounding someone's diary to the nearest preset is
// worse than showing them the length they actually have.
function apptSlotMinutes(array $config) {
    $minutes = (int)($config['appointment_slot_minutes'] ?? APPT_DEFAULT_SLOT_MINUTES);
    return $minutes > 0 ? max(5, min(480, $minutes)) : APPT_DEFAULT_SLOT_MINUTES;
}

// The block sizes the form offers, as minutes.
//
// Labelled by apptHumanMinutes() for the same reason the reminder list is, so
// the dropdown, the prompt and any refusal all name the length the same way.
function apptSlotChoices() {
    $out = [];
    foreach ([15, 30, 45, 60, 90, 120, 180, 240, 480] as $minutes) {
        $out[$minutes] = apptHumanMinutes($minutes);
    }
    return $out;
}

// '09:00, 09:30, 10:00' — the first few starts of a day on this grid.
//
// For the sentence under the form's slot length, generated from the chosen
// length rather than written out, so the example cannot describe a grid the
// tenant is not on. Stops at midnight rather than wrapping: an eight-hour slot
// has two starts in a day and inventing a third as '01:00' would be a lie about
// a diary nobody keeps.
function apptSlotExampleTimes($slotMinutes, $openAt = '09:00', $count = 3) {
    $slot = max(5, (int)$slotMinutes);
    [$h, $m] = array_map('intval', explode(':', $openAt));
    $minute = $h * 60 + $m;

    $out = [];
    for ($i = 0; $i < max(1, (int)$count) && $minute < 24 * 60; $i++, $minute += $slot) {
        $out[] = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }
    return implode(', ', $out);
}

// Is $localStart one of the diary's block boundaries?
//
// Measured from the opening time of the window the appointment falls in, not
// from midnight: a business that opens at 09:30 works to the half hour, and one
// that opens at 09:10 has its slots at 09:10, 09:40 — the grid belongs to the
// tenant's day, not to the clock. This is the same walk apptFreeSlots() makes,
// asked as a question about one time, so an offer and a booking cannot disagree.
//
// Containment is re-checked here rather than assumed, because the alignment is
// only meaningful relative to the window the appointment is actually in: with
// two windows a day, a time on the morning's grid may be nowhere on the
// afternoon's.
function apptOnSlotGrid(array $availability, DateTime $localStart, $durationMinutes, $slotMinutes) {
    $slot = max(5, (int)$slotMinutes);
    $weekday = (int)$localStart->format('w');
    $startMin = ((int)$localStart->format('H')) * 60 + (int)$localStart->format('i');
    $endMin = $startMin + max(0, (int)$durationMinutes);

    foreach ($availability as $w) {
        if ((int)$w['weekday'] !== $weekday) continue;
        [$wsH, $wsM] = array_map('intval', explode(':', $w['start_time']));
        [$weH, $weM] = array_map('intval', explode(':', $w['end_time']));
        $open = $wsH * 60 + $wsM;
        if ($startMin < $open || $endMin > $weH * 60 + $weM) continue;
        if (($startMin - $open) % $slot === 0) return true;
    }
    return false;
}

// The slot maths, with no database in sight (#35).
//
// Everything that decides whether a time can be offered is here, as a pure
// function of a schedule, a list of taken windows and a clock: the tenant's
// opening hours, the service's duration, the minimum notice and the horizon.
// Separated from the queries for the same reason apptValidateWeek() is — the
// interesting cases are boundaries, closed days and timezone edges, and a test
// that needed a database to prove "17:00 is not offered for a 60-minute service
// that closes at 17:00" would be proving it against the wrong thing.
//
// $availability is the appointment_availability rows; $busy is a list of
// ['start' => DateTime, 'end' => DateTime] in the *same* timezone as $fromLocal.
// Returns DateTimes in that timezone, earliest first.
//
// Options: horizon_days, step_minutes, limit, per_day, earliest, latest
// (the last two DateTimes).
function apptFreeSlots(array $availability, array $busy, DateTime $fromLocal, $durationMinutes, array $opts = []) {
    if (!$availability) return [];

    $duration = max(5, (int)$durationMinutes);
    $horizon  = max(0, (int)($opts['horizon_days'] ?? 30));
    // Candidate starts step by the tenant's block size, from each opening time,
    // so the times offered sit against each other: a half-hour diary opening at
    // 09:00 offers 09:00, 09:30, 10:00 and nothing between them.
    //
    // This used to be a fixed quarter of an hour on the reasoning that people
    // say "quarter past" and that a coarser grid hides an opening left by a
    // cancellation. Both are true and neither survived contact with a real
    // diary: a business that works in half hours was offered 09:15, a customer
    // took it, and the 09:00 and 09:30 blocks either side of it were both gone
    // for a fifteen-minute gain. The grid a business actually works to is
    // something only that business knows, so it is theirs to set.
    $step     = max(5, (int)($opts['step_minutes'] ?? APPT_DEFAULT_SLOT_MINUTES));
    $limit    = max(1, (int)($opts['limit'] ?? 100));
    $perDay   = max(0, (int)($opts['per_day'] ?? 0));
    $earliest = ($opts['earliest'] ?? null) instanceof DateTime ? $opts['earliest'] : null;
    // The horizon in days is a whole number of days from *now*, so the last day
    // of the grid is only partly inside it. Without this, a bot asked at 09:00
    // could offer 16:00 on the thirtieth day and then refuse the booking it had
    // just offered, because apptValidateSlot() measures the same limit to the
    // hour. One cut-off, applied to both.
    $latest = ($opts['latest'] ?? null) instanceof DateTime ? $opts['latest'] : null;

    $byDay = apptWindowsByWeekday($availability);
    $out = [];

    for ($dayOffset = 0; $dayOffset <= $horizon; $dayOffset++) {
        $day = (clone $fromLocal)->modify('+' . $dayOffset . ' days');
        $weekday = (int)$day->format('w');
        // A day with no window is closed. That is the whole of "disabled day":
        // the absence of a row, never a zero-length one.
        if (empty($byDay[$weekday])) continue;

        $found = 0;
        foreach ($byDay[$weekday] as $w) {
            [$sH, $sM] = array_map('intval', explode(':', $w['start_time']));
            [$eH, $eM] = array_map('intval', explode(':', $w['end_time']));
            // The appointment must *finish* before closing, so the last start is
            // one duration back from the end of the window.
            $lastStart = ($eH * 60 + $eM) - $duration;

            for ($minute = $sH * 60 + $sM; $minute <= $lastStart; $minute += $step) {
                $candidate = (clone $day)->setTime(intdiv($minute, 60), $minute % 60, 0);
                if ($candidate < $fromLocal) continue;
                if ($earliest !== null && $candidate < $earliest) continue;
                // Past the far edge: every later candidate is too, on this day
                // and every day after it.
                if ($latest !== null && $candidate > $latest) return $out;

                $end = (clone $candidate)->modify('+' . $duration . ' minutes');
                if (apptOverlapsBusy($busy, $candidate, $end)) continue;

                $out[] = $candidate;
                if (count($out) >= $limit) return $out;
                // Offering a customer eight consecutive quarter-hours on one
                // morning is a wall of numbers, not a choice. Spreading the
                // suggestions across days is what makes the list readable.
                if ($perDay && ++$found >= $perDay) continue 3;
            }
        }
    }
    return $out;
}

function apptWindowsByWeekday(array $availability) {
    $byDay = [];
    foreach ($availability as $w) $byDay[(int)$w['weekday']][] = $w;
    foreach ($byDay as &$windows) {
        usort($windows, fn($a, $b) => strcmp($a['start_time'], $b['start_time']));
    }
    return $byDay;
}

// Half-open overlap: [start, end) against [busy start, busy end). Touching is
// not overlapping — a 10:00–10:30 booking leaves 10:30 free.
function apptOverlapsBusy(array $busy, DateTime $start, DateTime $end) {
    foreach ($busy as $b) {
        if ($start < $b['end'] && $end > $b['start']) return true;
    }
    return false;
}

// Live bookings that touch [$fromUtc, $toUtc), as local windows for apptFreeSlots.
//
// Read once for a whole run rather than a query per candidate slot: the grid is
// hundreds of candidates over a 30-day horizon, and asking the database about
// each one was both slow and a way for two candidates to see two different
// calendars.
function apptBusyWindows(mysqli $conn, $userId, DateTime $fromUtc, DateTime $toUtc, DateTimeZone $tz, $excludeId = null) {
    $sql = "SELECT scheduled_at, duration_minutes FROM appointments
            WHERE user_id = ? AND status = 'booked'
              AND scheduled_at < ?
              AND DATE_ADD(scheduled_at, INTERVAL duration_minutes MINUTE) > ?";
    $params = [$userId, $toUtc->format('Y-m-d H:i:s'), $fromUtc->format('Y-m-d H:i:s')];
    $types = 'iss';
    if ($excludeId) { $sql .= " AND id <> ?"; $params[] = (int)$excludeId; $types .= 'i'; }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $busy = [];
    foreach ($rows as $r) {
        $start = (new DateTime($r['scheduled_at'], new DateTimeZone('UTC')))->setTimezone($tz);
        $busy[] = [
            'start' => $start,
            'end' => (clone $start)->modify('+' . max(1, (int)$r['duration_minutes']) . ' minutes'),
        ];
    }
    return $busy;
}

// The real, bookable openings in the diary (#35).
//
// Everything the tenant configured, applied together and in one place: the
// enabled days and their windows, the slot length, the minimum notice, the
// horizon, and the bookings already in the diary. Callers get times they can
// hand to a customer without checking anything else.
//
// Not per service any more, because nothing about a service changes the answer:
// one length, one grid, one diary. The caller that used to ask this once per
// service now asks it once.
//
// Returns DateTimes in the tenant's timezone, earliest first.
// $opts['availability'] and $opts['busy'] let a caller read the schedule and the
// diary once instead of once per question — the reply path does exactly that.
function apptOpenSlots(mysqli $conn, $userId, array $config, DateTime $fromLocal, array $opts = []) {
    $availability = $opts['availability'] ?? apptAvailability($conn, $userId);
    if (!$availability) return [];

    $tz = $fromLocal->getTimezone();
    // The appointment is the slot: it starts on a boundary and ends on the next
    // one, so the step and the length are the same number by construction and
    // cannot drift apart.
    $duration = apptSlotMinutes($config);
    $horizon = max(1, (int)($config['appointment_horizon_days'] ?? 30));
    $lead = max(0, (int)($config['appointment_lead_minutes'] ?? 60));

    // The notice period is a wall-clock rule in the tenant's zone once it has
    // been converted, so the whole grid can be compared in one timezone.
    $nowUtc = new DateTime('now', new DateTimeZone('UTC'));
    $earliestLocal = (clone $nowUtc)->modify('+' . $lead . ' minutes')->setTimezone($tz);
    $latestLocal = (clone $nowUtc)->modify('+' . $horizon . ' days')->setTimezone($tz);

    if (isset($opts['busy'])) {
        $busy = $opts['busy'];
    } else {
        $fromUtc = (clone $fromLocal)->setTimezone(new DateTimeZone('UTC'));
        $toUtc = (clone $fromLocal)->modify('+' . ($horizon + 1) . ' days')->setTimezone(new DateTimeZone('UTC'));
        $busy = apptBusyWindows($conn, $userId, $fromUtc, $toUtc, $tz, $opts['exclude_id'] ?? null);
    }

    return apptFreeSlots($availability, $busy, $fromLocal, $duration, $opts + [
        'horizon_days' => $horizon,
        'step_minutes' => $duration,
        'earliest' => $earliestLocal,
        'latest' => $latestLocal,
    ]);
}

// The first moment the business is open and free, at or after $fromLocal (#25).
//
// "We are not open then" is true but unhelpful — the customer has to guess again,
// and a bot that makes someone guess three times is a bot they stop using.
//
// Returns a DateTime in the tenant's timezone, or null when there is genuinely
// nothing — no schedule at all, or a fully booked horizon.
function apptNextAvailable(mysqli $conn, $userId, array $config, DateTime $fromLocal, $timezone) {
    $slots = apptOpenSlots($conn, $userId, $config, $fromLocal, ['limit' => 1]);
    return $slots ? $slots[0] : null;
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
function apptValidateSlot(mysqli $conn, $userId, array $config, $localDateTime, $timezone, $excludeId = null) {
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
    // One length for every appointment: the slot the tenant configured. The
    // service used to carry its own, which meant this check, the offered times
    // and the conflict query could each be measuring a different number of
    // minutes for the same booking.
    $slotMinutes = apptSlotMinutes($config);

    $availability = apptAvailability($conn, $userId);
    if (!apptWithinAvailability($availability, $local, $slotMinutes)) {
        return [null, apptDayScheduleLabel($availability, (int)$local->format('w'))
            . apptSuggestionSuffix($conn, $userId, $config, $local, $timezone)];
    }

    // On the diary's grid, not merely inside opening hours. Without this the
    // whole setting is advisory: the times offered would step by the tenant's
    // block size and a customer who typed "quarter past" instead of picking one
    // of them would still be booked, leaving a stub either side of them that no
    // slot can use. Refused with the next real opening, like any other refusal.
    if (!apptOnSlotGrid($availability, $local, $slotMinutes, $slotMinutes)) {
        return [null, 'We book in ' . apptHumanMinutes($slotMinutes)
            . ' slots, so an appointment cannot start then.'
            . apptSuggestionSuffix($conn, $userId, $config, $local, $timezone)];
    }

    if (apptConflicts($conn, $userId, $utc, $slotMinutes, $excludeId)) {
        return [null, 'That slot is already taken.'
            . apptSuggestionSuffix($conn, $userId, $config, $local, $timezone)];
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
function apptSuggestionSuffix(mysqli $conn, $userId, array $config, DateTime $local, $timezone) {
    $next = apptNextAvailable($conn, $userId, $config, $local, $timezone);
    return $next === null
        ? ''
        : ' The next time we could fit you in is ' . $next->format('D j M, H:i') . ' — would that suit?';
}

// Free times as a customer reads them: '09:00, 09:15, 11:30'.
//
// #42 replaced the per-day grouping this used to do. Grouping existed because
// the model was handed several days at once; now every list is one date the
// customer asked about, so the date is said once in the sentence around it and
// repeating it on every line was noise.
function apptTimeList(array $slots) {
    return implode(', ', array_map(fn(DateTime $s) => $s->format('H:i'), $slots));
}

// --- One date, the whole diary (#42) ----------------------------------------

// The most times a single day can produce. A 24-hour window on a 15-minute grid
// is 96 starts, so this is a ceiling that a real schedule cannot reach — it
// exists so a crafted or absurd schedule cannot build an unbounded message, not
// to trim a day the way the old per-service sample did.
const APPT_DAY_SLOT_CAP = 96;

// The date a customer asked about, or why it cannot be used (#42).
//
// Returns [DateTime midnight-in-$timezone, null] or [null, reason]. Pure, with
// the clock injectable, because every interesting case here is a boundary: today
// versus yesterday, and the far edge of the booking window.
function apptResolveDate($date, $timezone, $horizonDays, ?DateTime $nowLocal = null) {
    try {
        $tz = new DateTimeZone($timezone ?: 'UTC');
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }

    // A model asked for a date will sometimes answer with a time as well
    // ("2026-09-15 15:00"). The date is the part being asked about, so the rest
    // is dropped rather than the whole thing refused.
    $date = trim((string)$date);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})\b/', $date, $m)) $date = $m[1];

    $day = DateTime::createFromFormat('Y-m-d H:i:s', $date . ' 00:00:00', $tz);
    // createFromFormat rolls a date that does not exist ("2026-02-31") into the
    // next month, so the round trip is what actually validates it.
    if (!$day || $day->format('Y-m-d') !== $date) return [null, 'no date'];

    $now = $nowLocal instanceof DateTime
        ? (clone $nowLocal)->setTimezone($tz)
        : (new DateTime('now', new DateTimeZone('UTC')))->setTimezone($tz);

    // Compared as calendar dates, not instants: "today" is a legitimate request
    // at 16:00, and the notice period — not this check — decides what is left of
    // it.
    if ($day->format('Y-m-d') < $now->format('Y-m-d')) return [null, 'past'];
    if ($day > (clone $now)->modify('+' . max(1, (int)$horizonDays) . ' days')) return [null, 'horizon'];

    return [$day, null];
}

// Every free start time on one day, earliest first (#42).
//
// The whole day, with nothing sampled away — which is the entire point. The old
// context offered a handful of soonest openings per service, so the same few
// times appeared under every service as if they were its timetable, and the date
// the customer actually asked about was usually not in the list at all.
//
// Pure, like apptFreeSlots() which does the walking: $opts carries `earliest`
// (the notice period) and `latest` (the booking window), so a day inside the
// window still cannot produce a time outside it.
function apptDayFreeSlots(array $availability, array $busy, DateTime $day, $durationMinutes, array $opts = []) {
    return apptFreeSlots($availability, $busy, (clone $day)->setTime(0, 0, 0), $durationMinutes, $opts + [
        'horizon_days' => 0,
        'limit' => APPT_DAY_SLOT_CAP,
        'per_day' => 0,
    ]);
}

// What the customer is told about one date, as a sentence (#42).
//
// Pure: the caller has already done the looking-up, so every branch here is
// wording. Keys: service, reason, asked, slots, availability, next_date,
// next_slots, horizon_days.
function apptAvailabilityMessage(array $a) {
    $horizon = max(1, (int)($a['horizon_days'] ?? 30));

    switch ((string)($a['reason'] ?? '')) {
        case 'no hours':
            return 'We have no opening hours set up, so I cannot check the diary — '
                . 'someone will follow this up with you.';
        case 'no date':
            return 'Which date would you like me to check?';
        case 'past':
            return 'That date has already passed. Which date would you like me to check?';
        case 'horizon':
            return 'That is further ahead than we take bookings (' . $horizon . ' days). '
                . 'Which nearer date suits you?';
    }

    $name = (string)($a['service'] ?? '');
    $asked = $a['asked'] ?? null;
    $slots = $a['slots'] ?? [];

    if ($slots) {
        return 'Free times for ' . $name . ' on ' . $slots[0]->format('D j M') . ': '
            . apptTimeList($slots) . '. Which of those would you like?';
    }

    // Nothing on that date. Why not is the useful half — "we are shut on
    // Sundays" and "that day is full" lead to different next questions.
    $availability = $a['availability'] ?? [];
    $weekday = $asked instanceof DateTime ? (int)$asked->format('w') : null;
    $line = ($weekday !== null && !apptOpenOnWeekday($availability, $weekday))
        ? apptDayScheduleLabel($availability, $weekday)
        : 'There is nothing free for ' . $name . ' on '
          . ($asked instanceof DateTime ? $asked->format('D j M') : 'that date') . '.';

    $nextDate = $a['next_date'] ?? null;
    if (!$nextDate instanceof DateTime) {
        return $line . ' I have nothing free between now and the end of our booking window, '
            . 'so someone will follow this up with you.';
    }
    return $line . ' The next day with space is ' . $nextDate->format('D j M') . ': '
        . apptTimeList($a['next_slots'] ?? []) . '. Would any of those suit?';
}

function apptOpenOnWeekday(array $availability, $weekday) {
    foreach ($availability as $w) if ((int)$w['weekday'] === (int)$weekday) return true;
    return false;
}

// The live diary, as the sentence the bot sends (#42).
//
// A fresh query every time it is called, and it is called for every availability
// request the model makes — including "check the diary again". Nothing here reads
// the conversation: the diary moves while people are typing, so what the bot said
// three messages ago is not evidence about anything.
//
// Two queries, whatever the answer: the schedule and the diary are read once and
// every calculation below is pure, so the requested date, the fallback date and
// its times cannot each be measured against a slightly different calendar.
function apptDateAvailabilityLine(mysqli $conn, $userId, array $config, array $service, $date, $timezone) {
    try {
        $tz = new DateTimeZone($timezone ?: 'UTC');
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }

    $horizon = max(1, (int)($config['appointment_horizon_days'] ?? 30));
    $lead = max(0, (int)($config['appointment_lead_minutes'] ?? 60));
    $duration = apptSlotMinutes($config);

    $availability = apptAvailability($conn, $userId);
    if (!$availability) return apptAvailabilityMessage(['reason' => 'no hours']);

    [$day, $why] = apptResolveDate($date, $timezone, $horizon);
    if ($why !== null) {
        return apptAvailabilityMessage(['reason' => $why, 'horizon_days' => $horizon]);
    }

    $nowUtc = new DateTime('now', new DateTimeZone('UTC'));
    $opts = [
        'step_minutes' => apptSlotMinutes($config),
        'earliest' => (clone $nowUtc)->modify('+' . $lead . ' minutes')->setTimezone($tz),
        'latest'   => (clone $nowUtc)->modify('+' . $horizon . ' days')->setTimezone($tz),
    ];

    // From whichever comes first — the day asked about may be today — to the end
    // of the booking window, because the fallback below may look past it.
    $fromUtc = min((clone $day)->setTimezone(new DateTimeZone('UTC')), $nowUtc);
    $toUtc = (clone $nowUtc)->modify('+' . ($horizon + 1) . ' days');
    $busy = apptBusyWindows($conn, $userId, $fromUtc, $toUtc, $tz);

    $slots = apptDayFreeSlots($availability, $busy, $day, $duration, $opts);

    // Only when the requested date has nothing: "we are full that day" on its own
    // makes the customer guess again, and they cannot see the diary either.
    $nextDate = null;
    $nextSlots = [];
    if (!$slots) {
        $next = apptFreeSlots($availability, $busy, (clone $day)->modify('+1 day'), $duration,
            $opts + ['horizon_days' => $horizon, 'limit' => 1]);
        if ($next) {
            $nextDate = $next[0];
            $nextSlots = apptDayFreeSlots($availability, $busy, $nextDate, $duration, $opts);
        }
    }

    return apptAvailabilityMessage([
        'service' => $service['name'],
        'asked' => $day,
        'slots' => $slots,
        'availability' => $availability,
        'next_date' => $nextDate,
        'next_slots' => $nextSlots,
        'horizon_days' => $horizon,
    ]);
}

// --- "Are you sure?" (#42) ---------------------------------------------------
//
// The prompt tells the model that nothing said earlier is evidence about the
// diary. Observed against a real model, that holds for two turns and then does
// not: asked "are you sure? check again", it says it is checking, does not send
// the availability line, and pastes the list from its own previous message. The
// times it quotes were true when they were written and may not be now.
//
// A prompt cannot fix that, because the instruction is already there and was
// already read. So the check is made in PHP, on the same principle as the
// handover phrase match: it costs nothing, it is deterministic, and it does not
// depend on the model choosing to co-operate.

// Narrow on purpose. Every phrase here is someone questioning availability, not
// merely mentioning it — a false positive would replace a perfectly good answer
// with a diary listing nobody asked for.
const APPT_RECHECK_PHRASES = [
    'check again', 'check the diary', 'check once more', 'recheck', 're-check',
    'double check', 'double-check', 'are you sure', 'is that right',
    'still free', 'still available', 'still open', 'has that changed',
    'has it changed', 'any other time', 'other times',
];

function apptRecheckPhrase($text) {
    $text = mb_strtolower(trim((string)$text));
    if ($text === '') return null;
    foreach (APPT_RECHECK_PHRASES as $phrase) {
        if (str_contains($text, $phrase)) return $phrase;
    }
    return null;
}

// The service and date of the last diary answer this bot gave, read back out of
// the conversation (#42).
//
// Parsing our own sentence, not the model's: the format is
// apptAvailabilityMessage()'s and nothing else produces it, which is what makes
// this reliable enough to act on. Only the bot's own messages are considered,
// newest first, and a message with no such answer in it is skipped.
//
// The written date has no year — 'Wed 16 Sep' — because a customer reading a
// diary does not need one. It is resolved forwards: the nearest matching date
// that is not in the past, which is the only reading that can be about a
// booking.
//
// Returns ['service' => string, 'date' => 'Y-m-d'] or null.
function apptQuotedRequestFromHistory(array $history, $timezone, ?DateTime $now = null) {
    try {
        $tz = new DateTimeZone($timezone ?: 'UTC');
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }
    $now = $now instanceof DateTime
        ? (clone $now)->setTimezone($tz)
        : (new DateTime('now', new DateTimeZone('UTC')))->setTimezone($tz);

    foreach (array_reverse($history) as $message) {
        if (empty($message['fromMe'])) continue;
        $text = (string)($message['text'] ?? '');

        if (!preg_match('/(?:Free times for|nothing free for) (.+?) on [A-Z][a-z]{2} \d{1,2} [A-Z][a-z]{2}/u', $text, $who)) {
            continue;
        }
        // The last date whose list was actually quoted — which is the fallback
        // date when the asked-about one was closed or full, and that is the day
        // the customer is answering about.
        if (!preg_match_all('/([A-Z][a-z]{2} \d{1,2} [A-Z][a-z]{2}):/u', $text, $days) || !$days[1]) {
            continue;
        }
        $written = end($days[1]);

        $date = DateTime::createFromFormat('D j M Y H:i:s', $written . ' ' . $now->format('Y') . ' 00:00:00', $tz);
        if (!$date) continue;
        // December read in January: a date months behind us was written about
        // last year and means the next one. A day of slack, so a conversation
        // that ran over midnight still reads as being about today.
        if ($date->format('Y-m-d') < (clone $now)->modify('-1 day')->format('Y-m-d')) {
            $date = DateTime::createFromFormat('D j M Y H:i:s',
                $written . ' ' . ((int)$now->format('Y') + 1) . ' 00:00:00', $tz);
            if (!$date) continue;
        }

        return ['service' => trim($who[1]), 'date' => $date->format('Y-m-d')];
    }
    return null;
}

// Drops every sentence that quotes a clock time (#42).
//
// Used only when the system is about to state the real times in the same
// message. Leaving the model's list in would put two lists in front of the
// customer, and the wrong one first — so the sentences that carry times go and
// the rest of what the model wrote ("let me check that for you") stays.
//
// Sentence-level rather than word-level because removing "10:00" from "10:00 is
// free" leaves a sentence that is worse than no sentence.
function apptStripQuotedTimes($text) {
    $kept = [];
    foreach (preg_split('/\R/u', (string)$text) as $line) {
        $parts = preg_split('/(?<=[.!?])\s+/u', $line);
        $keptParts = array_filter($parts, fn($p) => !preg_match('/\b\d{1,2}:\d{2}\b/', $p));
        $line = trim(implode(' ', $keptParts));
        if ($line !== '') $kept[] = $line;
    }
    return trim(implode("\n", $kept));
}

// 'Haircut, Colour, Beard trim' — every active service, for the question that
// starts a booking. Never a subset: a service the tenant configured and the bot
// never mentions is a service they cannot sell.
//
// The durations that used to be in brackets are gone with the per-service length
// itself. They also read as a promise the diary could not keep: "Colour (90
// minutes)" next to a half-hour grid told a customer something untrue about the
// slot they were about to be offered.
function apptServiceListLine(array $services) {
    return implode(', ', array_map(fn($s) => (string)$s['name'], $services));
}

// A refusal, with the follow-up prompt only when it does not already ask
// something. Without the test the customer got "…would that suit? Could you
// suggest another time?", which reads as the bot arguing with itself.
function apptRefusalLine($why) {
    $why = trim((string)$why);
    return str_ends_with($why, '?') ? $why : $why . ' Could you suggest another time?';
}

// #10: 0 means unlimited — "at the limit" only exists when the tenant set one.
// Pure so the boundary is testable without a diary.
function apptCustomerAtLimit(int $count, int $limit): bool {
    return $limit > 0 && $count >= $limit;
}

function apptHumanMinutes($minutes) {
    $minutes = (int)$minutes;
    if ($minutes % 1440 === 0) return ($minutes / 1440) . ' day' . ($minutes === 1440 ? '' : 's');
    if ($minutes % 60 === 0) return ($minutes / 60) . ' hour' . ($minutes === 60 ? '' : 's');
    return $minutes . ' minutes';
}

// Runs $fn with this tenant's diary held against every other writer (#35).
//
// Checking that a slot is free and writing the booking are two statements, and
// between them a second customer can take the same time — two people arriving
// for one chair, which is the single failure a booking system must not have.
// MySQL cannot express "no other appointment overlaps this range" as a unique
// index, so the mutual exclusion has to be explicit.
//
// Scoped to the tenant, not the instance, so one busy salon cannot slow another
// one down, and held for the two statements only. A lock that cannot be taken
// within a few seconds runs anyway rather than refusing: the check inside is the
// same one that ran before this existed, so the worst case is what the code
// already did, not a customer told "try again" for a slot that is free.
function apptWithTenantLock(mysqli $conn, $userId, callable $fn) {
    $lock = 'appt:' . (int)$userId;

    $stmt = $conn->prepare("SELECT GET_LOCK(?, 5)");
    $stmt->bind_param('s', $lock);
    $stmt->execute();
    $got = (int)($stmt->get_result()->fetch_row()[0] ?? 0) === 1;
    $stmt->close();

    try {
        return $fn();
    } finally {
        if ($got) {
            $stmt = $conn->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->bind_param('s', $lock);
            $stmt->execute();
            $stmt->close();
        }
    }
}

// Books a slot, having checked one last time that it is still free (#35).
// Returns [id, null] or [null, reason].
function apptBookSlot(mysqli $conn, $userId, array $config, array $service, $localDateTime, $timezone, array $data) {
    return apptWithTenantLock($conn, $userId, function () use ($conn, $userId, $config, $service, $localDateTime, $timezone, $data) {
        // Re-checked inside the lock, immediately before the write, against the
        // same rules as the first pass — opening hours, notice, horizon and the
        // diary. The earlier pass is what shaped the reply; this one is what
        // makes it true.
        [$utc, $why] = apptValidateSlot($conn, $userId, $config, $localDateTime, $timezone);
        if (!$utc) return [null, $why];

        // #10: one customer's chatbot bookings are capped, counted inside the
        // lock so two simultaneous "yes, book it" messages cannot both pass.
        // Manual bookings are deliberately not limited — a tenant typing into
        // their own diary knows what they are doing; this guards the customer
        // who keeps saying yes. Chat id is the primary key; the phone is the
        // fallback for the same customer reaching a different account.
        $maxUpcoming = (int)($config['appointment_max_upcoming'] ?? 1);
        if (($data['source'] ?? '') === 'chatbot' && $maxUpcoming > 0) {
            $chatId = (string)($data['chat_id'] ?? '');
            $phone = trim((string)($data['customer_phone'] ?? ''));
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS c, MIN(scheduled_at) AS next_at
                 FROM appointments
                 WHERE user_id = ? AND status = 'booked' AND scheduled_at >= UTC_TIMESTAMP()
                   AND (chat_id = ?" . ($phone !== '' ? " OR customer_phone = ?" : "") . ")"
            );
            if ($phone !== '') {
                $stmt->bind_param('iss', $userId, $chatId, $phone);
            } else {
                $stmt->bind_param('is', $userId, $chatId);
            }
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (apptCustomerAtLimit((int)($existing['c'] ?? 0), $maxUpcoming)) {
                // Ends with '?' so apptRefusalLine() adds nothing: the question
                // is already the next step the customer needs.
                return [null, 'You already have a booking on '
                    . formatUserDate($existing['next_at'], $timezone, 'D j M Y, H:i')
                    . '. Would you like to move it instead?'];
            }
        }

        // The length is written down on the row, not looked up later: it is how
        // long *this* booking is, and a tenant who changes the slot length must
        // not silently re-time the appointments already in the diary.
        return [apptCreate($conn, $userId, $data + [
            'service_id' => (int)($service['id'] ?? 0) ?: null,
            'service_name' => $service['name'],
            'duration_minutes' => apptSlotMinutes($config),
            'scheduled_at' => $utc->format('Y-m-d H:i:s'),
        ]), null];
    });
}

// The same last-moment re-check for a move. A reschedule takes the slot exactly
// as a new booking does, so it has to compete for it the same way.
// Returns [DateTime utc, null] or [null, reason].
function apptRescheduleSlot(mysqli $conn, $userId, array $config, $localDateTime, $timezone, $appointmentId) {
    return apptWithTenantLock($conn, $userId, function () use ($conn, $userId, $config, $localDateTime, $timezone, $appointmentId) {
        [$utc, $why] = apptValidateSlot($conn, $userId, $config, $localDateTime, $timezone, (int)$appointmentId);
        if (!$utc) return [null, $why];
        if (!apptReschedule($conn, $userId, (int)$appointmentId, $utc)) {
            return [null, 'I could not move that booking.'];
        }
        return [$utc, null];
    });
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

    // #47: a status change is a new revision of the calendar event — including
    // a reinstatement (cancelled → booked), which must update the customer's
    // card, not sit alongside it.
    if ($changed) apptBumpIcsSequence($conn, $userId, $id);

    // A cancelled appointment must not go on reminding anyone. `sending` is
    // included as well as `pending`: a row claimed a moment ago by a tick that is
    // still running would otherwise be the one reminder a cancellation misses.
    // The sender re-reads the appointment's status before it sends, so the two
    // cannot cross.
    if ($changed && $status !== 'booked') {
        $stmt = $conn->prepare(
            "UPDATE appointment_reminders SET status = 'skipped', claimed_at = NULL,
                    detail = 'appointment no longer booked'
             WHERE appointment_id = ? AND status IN ('pending', 'sending')"
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
        // #47: a new time is a new revision of the calendar event.
        apptBumpIcsSequence($conn, $userId, $id);

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

// --- How a status is written down (#33 §1, §12) ------------------------------
//
// The four stored values are `booked`, `completed`, `cancelled` and `no_show`,
// and the page was rendering them raw — a tenant was shown the string `no_show`
// inside a Bootstrap `bg-danger` rectangle. WA_STATUS_LABELS in functions.php
// solved exactly this for connection statuses in Phase 13; appointments simply
// never joined it, so the vocabulary is defined here in the same shape: a label
// and a badge class, one place each, so a status cannot render two ways.
//
// The class returns one of the semantic pill classes in style.css rather than a
// Bootstrap colour, because a status is a state and states get pills.
const APPT_STATUS_LABELS = [
    'booked'    => 'Booked',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'no_show'   => 'No-show',
];

function apptStatusLabel($status, $isPast = false) {
    $status = (string)$status;
    // The one case where the stored value is not the whole truth: a booking
    // whose time has passed is still `booked`, and "Booked" for something that
    // was yesterday tells a tenant nothing about the thing they have to decide
    // — whether the customer turned up.
    if ($status === 'booked' && $isPast) return 'Awaiting outcome';
    return APPT_STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status ?: 'unknown'));
}

function apptStatusClass($status, $isPast = false) {
    switch ((string)$status) {
        case 'booked':    return $isPast ? 'badge-warn' : 'badge-pending';
        case 'completed': return 'badge-ok';
        case 'no_show':   return 'badge-bad';
        case 'cancelled': return 'badge-neutral';
        // Cancelled is neutral, not danger: it is an ordinary ending that
        // someone chose, and colouring it red puts it next to the one status
        // that means a customer was let down.
        default:          return 'badge-neutral';
    }
}

// --- Reminders --------------------------------------------------------------

// The lead times a reminder is sent at, as minutes, longest first.
//
// Stored as a comma-separated string because there can be several ("a day
// before, and again an hour before"), and accepted as either that string or the
// array of checkbox values the form now posts (#36) — one parser, so a saved
// value and a submitted one cannot be read differently.
function apptReminderMinutes(array $config) {
    $raw = $config['reminder_minutes'] ?? '1440,60';
    $parts = is_array($raw) ? $raw : explode(',', (string)$raw);

    $out = [];
    foreach ($parts as $part) {
        $n = (int)trim((string)$part);
        if ($n > 0 && $n <= 20160) $out[$n] = $n;   // up to two weeks
    }
    rsort($out);
    return $out;
}

// The lead times the form offers, as minutes (#36).
//
// The field used to be a free-text list of raw minutes, so "Remind before" read
// `1440,60` — a number a tenant has no way to interpret as "a day, then an
// hour". These are the same values with their unit attached, and the labels come
// from apptHumanMinutes() so the list and every message that quotes a lead time
// say it the same way.
function apptReminderChoices() {
    $out = [];
    foreach ([15, 30, 60, 120, 240, 720, 1440, 2880, 10080] as $minutes) {
        $out[$minutes] = apptHumanMinutes($minutes);
    }
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
         ON DUPLICATE KEY UPDATE send_at = VALUES(send_at), status = 'pending', sent_at = NULL,
                                 claimed_at = NULL, attempts = 0, retry_after = NULL, detail = NULL"
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

// How late a reminder may be sent and still be worth sending (#39).
//
// A reminder quotes its own lead time — "your haircut is in 1 day" — so sending
// it hours after its moment does not just arrive late, it arrives *wrong*. After
// an outage long enough to matter, the honest outcome is to record that the
// reminder was missed rather than to tell a customer their appointment is
// tomorrow when it is in three hours.
//
// The allowance scales with the lead time, because "late" means different things
// for a 15-minute warning and a one-week one: half the lead time, never under a
// quarter of an hour (a restart must not lose anything) and never over two.
function apptReminderGraceMinutes($minutesBefore) {
    return max(15, min(120, intdiv(max(0, (int)$minutesBefore), 2)));
}

function apptReminderTooLate($minutesBefore, $lateByMinutes) {
    return (int)$lateByMinutes > apptReminderGraceMinutes($minutesBefore);
}

// Retries exist for the failure that goes away on its own: a WhatsApp socket
// mid-reconnect, a backend restart, a dropped database connection. They back off
// so a tenant whose account is properly broken is not hammered once a minute,
// and they stop, because a reminder that has failed eight times is not going to
// succeed on the ninth and the appointment is coming either way.
const APPT_REMINDER_MAX_ATTEMPTS = 8;

function apptReminderRetryDelayMinutes($attempts) {
    $attempts = max(1, (int)$attempts);
    return (int)min(30, 2 ** ($attempts - 1));
}

// Reminders that are due now, oldest first.
//
// `late_minutes` is computed by the database rather than in PHP on purpose: the
// two clocks are not guaranteed to agree, and every other decision here is made
// against UTC_TIMESTAMP().
function apptDueReminders(mysqli $conn, $limit = 50) {
    $limit = max(1, min(200, (int)$limit));
    $sql = "SELECT r.id AS reminder_id, r.minutes_before, r.attempts,
                   TIMESTAMPDIFF(MINUTE, r.send_at, UTC_TIMESTAMP()) AS late_minutes,
                   a.*, w.session_id
            FROM appointment_reminders r
            JOIN appointments a ON r.appointment_id = a.id
            LEFT JOIN wa_accounts w ON a.account_id = w.id
            WHERE r.status = 'pending' AND r.send_at <= UTC_TIMESTAMP()
              AND (r.retry_after IS NULL OR r.retry_after <= UTC_TIMESTAMP())
              AND a.status = 'booked' AND a.scheduled_at > UTC_TIMESTAMP()
            ORDER BY r.send_at ASC LIMIT " . $limit;
    $res = $conn->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

// Claims a reminder before sending it. The UPDATE ... WHERE status='pending' is
// the lock: two schedulers racing produce one winner, and the loser sends
// nothing rather than the customer getting the message twice.
//
// The claim parks the row in `sending`, not `sent`. Writing `sent` up front made
// the row a promise the sender had not kept yet, and a process killed between
// the claim and the WhatsApp call turned that into a reminder nobody would ever
// look for again. `sending` is a claim with an owner and a timestamp, so
// apptRecoverStuckReminders() can tell an in-flight send from an abandoned one.
function apptClaimReminder(mysqli $conn, $reminderId) {
    $stmt = $conn->prepare(
        "UPDATE appointment_reminders
         SET status = 'sending', claimed_at = UTC_TIMESTAMP(), attempts = attempts + 1, detail = NULL
         WHERE id = ? AND status = 'pending'"
    );
    $stmt->bind_param('i', $reminderId);
    $stmt->execute();
    $claimed = $stmt->affected_rows === 1;
    $stmt->close();
    return $claimed;
}

function apptReminderSent(mysqli $conn, $reminderId) {
    $stmt = $conn->prepare(
        "UPDATE appointment_reminders
         SET status = 'sent', sent_at = UTC_TIMESTAMP(), claimed_at = NULL, retry_after = NULL, detail = NULL
         WHERE id = ? AND status = 'sending'"
    );
    $stmt->bind_param('i', $reminderId);
    $stmt->execute();
    $stmt->close();
}

// Hands a failed send back to the next tick, or gives up once the attempts are
// spent. `sent_at` is cleared: the claim no longer sets it, but a row written by
// the previous sender may carry one, and a pending reminder with a send time is
// a row that reads as two different things at once.
function apptReminderRetryLater(mysqli $conn, $reminderId, $attempts, $detail) {
    $detail = mb_substr((string)$detail, 0, 255);

    if ((int)$attempts >= APPT_REMINDER_MAX_ATTEMPTS) {
        apptMarkReminder($conn, $reminderId, 'failed', $detail . ' (gave up after '
            . APPT_REMINDER_MAX_ATTEMPTS . ' attempts)');
        return false;
    }

    $delay = apptReminderRetryDelayMinutes($attempts);
    $stmt = $conn->prepare(
        "UPDATE appointment_reminders
         SET status = 'pending', claimed_at = NULL, sent_at = NULL, detail = ?,
             retry_after = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE)
         WHERE id = ?"
    );
    $stmt->bind_param('sii', $detail, $delay, $reminderId);
    $stmt->execute();
    $stmt->close();
    return true;
}

function apptMarkReminder(mysqli $conn, $reminderId, $status, $detail = null) {
    $detail = $detail === null ? null : mb_substr((string)$detail, 0, 255);
    $stmt = $conn->prepare(
        "UPDATE appointment_reminders SET status = ?, detail = ?, claimed_at = NULL WHERE id = ?"
    );
    $stmt->bind_param('ssi', $status, $detail, $reminderId);
    $stmt->execute();
    $stmt->close();
}

// Rows abandoned in `sending` by a sender that died mid-flight, put back so the
// next tick tries again. The window is generous compared with a send, which is
// one HTTP call to the backend, so a claim this old is not slow — it is orphaned.
//
// Returns the number of rows recovered, which is a number worth logging: in
// steady state it is zero, and anything else says the sender is being killed.
function apptRecoverStuckReminders(mysqli $conn, $staleMinutes = 5) {
    $staleMinutes = max(2, (int)$staleMinutes);

    // Spent attempts are not recovered forever, or a crash loop would retry the
    // same row until the appointment arrived.
    $stmt = $conn->prepare(
        "UPDATE appointment_reminders
         SET status = 'failed', claimed_at = NULL, detail = 'send interrupted repeatedly, gave up'
         WHERE status = 'sending' AND attempts >= ?
           AND claimed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)"
    );
    $max = APPT_REMINDER_MAX_ATTEMPTS;
    $stmt->bind_param('ii', $max, $staleMinutes);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare(
        "UPDATE appointment_reminders
         SET status = 'pending', claimed_at = NULL, sent_at = NULL, retry_after = NULL,
             detail = 'send interrupted, retrying'
         WHERE status = 'sending' AND claimed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)"
    );
    $stmt->bind_param('i', $staleMinutes);
    $stmt->execute();
    $recovered = $stmt->affected_rows;
    $stmt->close();
    return max(0, $recovered);
}

// Reminders whose appointment has already happened while they were still
// waiting. Nothing will ever send these, and leaving them `pending` is how they
// used to disappear: the due query stopped matching them and no row anywhere
// said a customer had not been reminded.
// `missed` and `skipped` are kept apart because they mean different things to
// whoever reads them later: missed is a failure of this system, skipped is a
// customer who cancelled. apptSetStatus() already skips reminders at the moment
// an appointment stops being booked, so the second statement only catches rows
// left behind by a cancellation that predates that rule.
function apptSweepMissedReminders(mysqli $conn) {
    $swept = 0;

    $conn->query(
        "UPDATE appointment_reminders r
         JOIN appointments a ON a.id = r.appointment_id
         SET r.status = 'missed', r.claimed_at = NULL,
             r.detail = 'the appointment passed before this reminder could be sent'
         WHERE r.status IN ('pending', 'sending')
           AND a.status = 'booked' AND a.scheduled_at <= UTC_TIMESTAMP()"
    );
    $swept += max(0, $conn->affected_rows);

    $conn->query(
        "UPDATE appointment_reminders r
         JOIN appointments a ON a.id = r.appointment_id
         SET r.status = 'skipped', r.claimed_at = NULL,
             r.detail = 'appointment no longer booked'
         WHERE r.status IN ('pending', 'sending') AND a.status <> 'booked'"
    );
    $swept += max(0, $conn->affected_rows);

    return $swept;
}

// What an operator needs to know without opening the database (#39): is the
// queue draining, and is anything stuck in it. Cheap enough to run on every
// tick — the table is one row per reminder per appointment, indexed on status.
function apptReminderHealth(mysqli $conn) {
    $sql = "SELECT
                SUM(r.status = 'pending') AS pending,
                SUM(r.status = 'sending') AS sending,
                SUM(r.status = 'pending' AND r.send_at <= UTC_TIMESTAMP()
                    AND (r.retry_after IS NULL OR r.retry_after <= UTC_TIMESTAMP())) AS due_now,
                SUM(r.status = 'pending' AND r.send_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)) AS overdue,
                SUM(r.status = 'missed' AND a.scheduled_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)) AS missed_24h,
                SUM(r.status = 'failed' AND a.scheduled_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)) AS failed_24h,
                SUM(r.status = 'sent' AND r.sent_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)) AS sent_24h
            FROM appointment_reminders r
            JOIN appointments a ON a.id = r.appointment_id";
    $row = ($res = $conn->query($sql)) ? $res->fetch_assoc() : null;
    return array_map('intval', $row ?: []);
}

function apptReminderText(array $appt, $whenLocal, $minutesBefore) {
    $lead = apptHumanMinutes($minutesBefore);
    $name = trim((string)($appt['customer_name'] ?? ''));
    $hello = $name !== '' ? "Hi {$name}, " : 'Hi, ';
    return $hello . "a reminder that your {$appt['service_name']} is in {$lead} — {$whenLocal}. "
        . 'Reply here if you need to change or cancel it.';
}

// --- Telling the customer what the tenant changed (#45) ---------------------
//
// A booking taken over WhatsApp is a promise made in a conversation, and until
// this existed a tenant could cancel or move one from the dashboard and the
// customer would hear nothing at all — they turned up at the old time, or for
// an appointment that no longer existed. The reminders were rescheduled
// correctly; the person was simply never told.
//
// The rules the delivery is held to are the reminders' rules, because they are
// the same problem: a message that must go out exactly once, whose sender can
// die halfway, and whose failure must never be reported to the tenant as a
// success. So a change is written down before it is sent (appointment_notifications),
// the row is claimed with a conditional UPDATE, and only a delivered message
// writes `sent`.

// A change is only ever sent once, so what identifies it has to be the change
// itself and not the moment the button was pressed. Two submissions of the same
// cancellation are the same instant cancelled; a genuine second move is a
// different pair of instants and is therefore a different message.
function apptNoticeFingerprint(array $change) {
    $kind = (string)($change['kind'] ?? '');
    $at = (string)($change['at'] ?? '');
    $was = (string)($change['was_at'] ?? '');
    return $was === '' ? $kind . ':' . $at : $kind . ':' . $was . '>' . $at;
}

// What the customer reads. Pure, and the times arrive already rendered in the
// tenant's timezone by the caller — a customer is told "Tue 15 Sep, 14:00",
// never a UTC instant, and never an id or a status name.
//
// Returns '' for a change the customer would not notice, which is the whole of
// "internal-only updates send nothing": marking a booking done or a no-show
// says nothing about what was promised, so it produces no text and therefore no
// row and no message.
function apptNoticeText(array $appt, array $change) {
    $name = trim((string)($appt['customer_name'] ?? ''));
    $hello = $name !== '' ? "Hi {$name}, " : 'Hi, ';
    $service = trim((string)($appt['service_name'] ?? '')) ?: 'appointment';
    $when = (string)($change['when'] ?? '');
    $was = (string)($change['was'] ?? '');

    switch ((string)($change['kind'] ?? '')) {
        case 'cancelled':
            return $hello . "your {$service} on {$when} has been cancelled. "
                . 'Reply here if you would like to book another time.';
        case 'rescheduled':
            return $hello . "your {$service} has been moved from {$was} to {$when}. "
                . 'Reply here if the new time does not suit.';
        // A booking reopened after the customer was told it was cancelled. Only
        // ever reached when the cancellation actually went out — see
        // apptNoticeWasSent() — because telling someone their appointment is
        // back on when they never heard it was off is worse than silence.
        case 'reinstated':
            return $hello . "your {$service} on {$when} is going ahead after all. "
                . 'Reply here if that no longer suits you.';
    }
    return '';
}

// The change, with both instants and both labels, ready for the two functions
// above. $tz is the tenant's timezone and is the only zone a customer is ever
// shown a time in.
function apptChange($kind, $atUtc, $timezone, $wasUtc = null) {
    $format = 'D j M Y, H:i';
    return [
        'kind' => (string)$kind,
        'at' => (string)$atUtc,
        'was_at' => $wasUtc === null ? '' : (string)$wasUtc,
        'when' => formatUserDate($atUtc, $timezone, $format),
        'was' => $wasUtc === null ? '' : formatUserDate($wasUtc, $timezone, $format),
    ];
}

// Claim, send, record — with every side effect injected (#45).
//
// The database, the WhatsApp call and the quota all arrive as closures, for the
// same reason the slot maths was separated from its queries: the interesting
// cases here are a duplicate submit, a send that fails and a booking with
// nobody to tell, and a test that needed a live MySQL and a live WhatsApp
// socket to state them would be testing the fixture.
//
// Returns ['status' => 'sent'|'duplicate'|'skipped'|'failed', 'detail' => string].
// Nothing here reports a success it did not have: a failed send leaves a
// `failed` row the tenant is shown and can retry, never a "customer notified".
function apptSendNotice(array $deps, $kind, $fingerprint, $text) {
    $noticeId = ($deps['claim'])($kind, $fingerprint, $text);
    // Somebody else owns this exact change: the same form submitted twice, a
    // retried POST, or the chatbot having already said it in its own reply.
    if (!$noticeId) return ['status' => 'duplicate', 'detail' => 'the customer had already been told'];

    $channel = ($deps['channel'])();
    if (!$channel) {
        $detail = 'no WhatsApp account or chat is linked to this booking';
        ($deps['mark'])($noticeId, 'skipped', $detail);
        return ['status' => 'skipped', 'detail' => $detail];
    }

    // A notification is a message and is metered like one, exactly as a
    // reminder is. Out of allowance is a failure the tenant has to see — the
    // appointment change stands either way.
    if (isset($deps['quota']) && !($deps['quota'])()) {
        $detail = 'the monthly message limit has been reached';
        ($deps['mark'])($noticeId, 'failed', $detail);
        return ['status' => 'failed', 'detail' => $detail];
    }

    try {
        $ok = ($deps['send'])($channel, $text);
        $detail = 'WhatsApp did not accept the message';
    } catch (Throwable $e) {
        $ok = false;
        $detail = 'the message could not be sent';
    }

    if (!$ok) {
        ($deps['mark'])($noticeId, 'failed', $detail);
        return ['status' => 'failed', 'detail' => $detail];
    }

    // A send closure may return a string instead of bare true — the calendar
    // sender uses it to record which form went out ('native event' vs 'ics
    // fallback'), so the notice row says what the customer actually received.
    ($deps['mark'])($noticeId, 'sent', is_string($ok) ? $ok : null);
    return ['status' => 'sent', 'detail' => ''];
}

// How the tenant is told what the customer was told. Pure, and it never
// dresses a failure up as a success: the two outcomes the tenant has to act on
// come back as 'warning' so the page shows them in the colour of something
// unfinished.
//
// Returns [message, flash variant].
function apptNoticeSummary($prefix, array $result) {
    switch ((string)($result['status'] ?? '')) {
        case 'sent':
            return [$prefix . ' The customer has been told on WhatsApp.', 'success'];
        case 'duplicate':
            return [$prefix . ' The customer had already been told.', 'success'];
        case 'skipped':
            return [$prefix . ' No WhatsApp chat is linked to this booking, so the customer could not be told.',
                'warning'];
        case 'failed':
            return [$prefix . ' The customer was NOT told — ' . ($result['detail'] ?? 'the message did not go out')
                . '. Use "Tell customer" on the booking to try again.', 'warning'];
    }
    return [$prefix, 'success'];
}

// A claim that is also the de-duplication (#45).
//
// The unique key on (appointment_id, fingerprint) means the insert is the
// claim: the first submission of a change creates the row and owns it, and a
// second one finds it already there. `failed` is re-claimable because a retry
// is the point of recording a failure, and a `sending` row older than the
// window below was abandoned by a process that died — anything else (`sent`,
// `skipped`, a live `sending`) belongs to somebody and is left alone.
//
// Returns the notice id when this caller owns the send, or null.
//
// The order of the assignments below is load-bearing. MySQL evaluates them left
// to right against the row as it is being changed, so `status` is written last:
// set first, it would rewrite the very column the other five conditions are
// testing, and a re-claimed row would keep its old attempt count and its stale
// failure reason. `claimed_at` goes immediately before it for the same reason —
// the one condition that can still read it correctly is the last one, and by
// then the answer no longer depends on it.
const APPT_NOTICE_STALE_MINUTES = 5;

function apptClaimNotice(mysqli $conn, $userId, $appointmentId, $kind, $fingerprint, $body, $channel = 'dashboard') {
    $reclaimable = "(status = 'failed' OR (status = 'sending'
                     AND claimed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL "
                     . APPT_NOTICE_STALE_MINUTES . " MINUTE)))";

    $stmt = $conn->prepare(
        "INSERT INTO appointment_notifications
            (appointment_id, user_id, kind, fingerprint, body, channel, status, attempts, claimed_at)
         VALUES (?, ?, ?, ?, ?, ?, 'sending', 1, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
            id         = LAST_INSERT_ID(id),
            detail     = IF({$reclaimable}, NULL, detail),
            body       = IF({$reclaimable}, VALUES(body), body),
            attempts   = IF({$reclaimable}, attempts + 1, attempts),
            claimed_at = IF({$reclaimable}, UTC_TIMESTAMP(), claimed_at),
            status     = IF({$reclaimable}, 'sending', status)"
    );
    $stmt->bind_param('iissss', $appointmentId, $userId, $kind, $fingerprint, $body, $channel);
    $stmt->execute();
    // 1 = inserted, 2 = an existing row this caller just re-claimed, 0 = a row
    // nothing changed about, which is somebody else's send.
    $owned = $stmt->affected_rows !== 0;
    $id = $conn->insert_id;
    $stmt->close();

    return $owned ? (int)$id : null;
}

function apptMarkNotice(mysqli $conn, $noticeId, $status, $detail = null) {
    $detail = $detail === null ? null : mb_substr((string)$detail, 0, 255);
    $stmt = $conn->prepare(
        "UPDATE appointment_notifications
            SET status = ?, detail = ?, claimed_at = NULL,
                sent_at = IF(? = 'sent', UTC_TIMESTAMP(), sent_at)
          WHERE id = ?"
    );
    $stmt->bind_param('sssi', $status, $detail, $status, $noticeId);
    $stmt->execute();
    $stmt->close();
}

// The account and chat a notification goes through: the ones the booking was
// made in, and no others. A manual booking has no linked account, so there is
// nothing to send through and nothing is invented — the row is recorded as
// skipped and the tenant is told plainly.
function apptNoticeChannel(mysqli $conn, $userId, array $appt) {
    $chatId = trim((string)($appt['chat_id'] ?? ''));
    $accountId = (int)($appt['account_id'] ?? 0);
    if ($chatId === '' || !$accountId) return null;

    $stmt = $conn->prepare("SELECT session_id FROM wa_accounts WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $accountId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $sessionId = trim((string)($row['session_id'] ?? ''));
    return $sessionId === '' ? null : ['session_id' => $sessionId, 'chat_id' => $chatId];
}

// The real dependencies, in the shape apptSendNotice() expects.
function apptNoticeDeps(mysqli $conn, $userId, array $appt, $channelName = 'dashboard') {
    return [
        'claim' => fn($kind, $fingerprint, $body) => apptClaimNotice($conn, $userId, (int)$appt['id'],
            $kind, $fingerprint, $body, $channelName),
        'mark' => fn($noticeId, $status, $detail) => apptMarkNotice($conn, $noticeId, $status, $detail),
        'channel' => fn() => apptNoticeChannel($conn, $userId, $appt),
        'quota' => function () use ($conn, $userId) { [$ok] = checkMessageQuota($conn, $userId); return $ok; },
        'send' => fn(array $channel, $text) => chatbotSendReply($conn, $userId, 't' . $userId,
            $channel['session_id'], $channel['chat_id'], $text),
    ];
}

// Tells the customer about one change a tenant made. The single entry point the
// dashboard uses.
function apptNotifyCustomer(mysqli $conn, $userId, array $appt, array $change) {
    $text = apptNoticeText($appt, $change);
    // Nothing a customer would notice: no message, and no row pretending there
    // was one to send.
    if ($text === '') return ['status' => 'none', 'detail' => ''];

    return apptSendNotice(apptNoticeDeps($conn, $userId, $appt),
        (string)$change['kind'], apptNoticeFingerprint($change), $text);
}

// The chatbot has already told the customer in its own reply, so the change is
// recorded as delivered rather than sent again. This is what makes a
// chatbot-driven cancellation invisible to the dashboard's notifier: the
// fingerprint is taken, so a tenant who then presses Cancel on the same booking
// cannot produce a second message about the same change.
function apptRecordNoticeSent(mysqli $conn, $userId, $appointmentId, array $change, $body, $channel = 'chatbot') {
    $kind = (string)($change['kind'] ?? '');
    $fingerprint = apptNoticeFingerprint($change);
    $stmt = $conn->prepare(
        "INSERT INTO appointment_notifications
            (appointment_id, user_id, kind, fingerprint, body, channel, status, attempts, sent_at)
         VALUES (?, ?, ?, ?, ?, ?, 'sent', 1, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
    );
    $body = mb_substr((string)$body, 0, 2000);
    $stmt->bind_param('iissss', $appointmentId, $userId, $kind, $fingerprint, $body, $channel);
    $stmt->execute();
    $stmt->close();
}

// Did this exact change reach the customer? Asked before a reinstatement, so a
// booking reopened after a cancellation nobody heard about stays silent.
function apptNoticeWasSent(mysqli $conn, $userId, $appointmentId, array $change) {
    $fingerprint = apptNoticeFingerprint($change);
    $stmt = $conn->prepare(
        "SELECT 1 FROM appointment_notifications
          WHERE appointment_id = ? AND user_id = ? AND fingerprint = ? AND status = 'sent' LIMIT 1"
    );
    $stmt->bind_param('iis', $appointmentId, $userId, $fingerprint);
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $found;
}

// Everything the tenant still has to know about, keyed by appointment: a
// message that failed and can be retried, and one that was never possible
// because the booking has no WhatsApp chat. Read for a whole page in one query
// — the listing shows up to 500 rows.
function apptOutstandingNotices(mysqli $conn, $userId, array $appointmentIds) {
    $ids = array_values(array_unique(array_map('intval', $appointmentIds)));
    if (!$ids) return [];

    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare(
        "SELECT appointment_id, kind, status, detail, attempts
           FROM appointment_notifications
          WHERE user_id = ? AND status IN ('failed', 'skipped') AND appointment_id IN ({$in})
          ORDER BY id ASC"
    );
    $stmt->bind_param('i' . str_repeat('i', count($ids)), $userId, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Last one wins: the most recent unfinished thing is what the tenant is
    // being asked about.
    $byAppointment = [];
    foreach ($rows as $row) $byAppointment[(int)$row['appointment_id']] = $row;
    return $byAppointment;
}

// The retry the tenant is offered when a send failed. It re-sends what the
// customer was always going to be told — the stored body — rather than a
// sentence rebuilt against a diary that has moved on since.
function apptRetryNotice(mysqli $conn, $userId, $appointmentId) {
    $stmt = $conn->prepare(
        "SELECT * FROM appointment_notifications
          WHERE appointment_id = ? AND user_id = ? AND status = 'failed'
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param('ii', $appointmentId, $userId);
    $stmt->execute();
    $notice = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $appt = apptById($conn, $userId, $appointmentId);
    if (!$notice || !$appt) return ['status' => 'none', 'detail' => 'there is nothing waiting to be sent'];

    // #47: a calendar card is a document, not a text — re-sending its stored
    // body would deliver the caption alone with nothing attached. The method
    // lives in the fingerprint ('ics:PUBLISH:3'), and the card is rebuilt so the
    // .ics the customer gets reflects the diary as it stands now.
    if (str_starts_with((string)$notice['kind'], 'calendar_')) {
        $party = substr((string)$notice['kind'], strlen('calendar_'));
        $method = explode(':', (string)$notice['fingerprint'])[1] ?? 'PUBLISH';
        $results = apptSendCalendarCards($conn, $userId, $appt, $method, 'retry', $party);
        return $results[$party] ?? ['status' => 'none', 'detail' => 'there is nothing waiting to be sent'];
    }

    return apptSendNotice(apptNoticeDeps($conn, $userId, $appt),
        (string)$notice['kind'], (string)$notice['fingerprint'], (string)$notice['body']);
}
