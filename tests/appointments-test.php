<?php
// Opening hours, end to end, without a database (#38).
//
// Run with:  php tests/appointments-test.php
//
// These cover the part of booking that decides what a customer is offered and
// what a booking is refused for: which days are open, where a window's edges
// are, what a taken slot blocks, and what all of that means in the tenant's own
// timezone. They are the rules a tenant configures on the Appointments tab, so a
// change that quietly disconnects the form from the calendar fails here rather
// than in someone's diary.
//
// No framework and no database on purpose. The functions under test were
// separated from their queries precisely so the interesting cases — boundaries,
// closed days, DST — could be stated as data, and a suite that needed a live
// MySQL to assert "17:00 is not offered for an hour-long service that closes at
// 17:00" would be testing the fixture as much as the rule.
//
// This directory is not copied into either Docker image: it is developer tooling
// and has no business on a server.

// Both files are function definitions only, so they load without a database, a
// session or any of the app's configuration. Nothing below calls anything that
// needs one.
require_once __DIR__ . '/../frontend-php/includes/appointments.php';
require_once __DIR__ . '/../frontend-php/includes/chatbot.php';   // chatbotValidTime()

$passed = 0;
$failed = 0;

function check($name, $condition, $detail = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   {$name}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function equals($name, $expected, $actual) {
    check($name, $expected === $actual,
        'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function group($title) {
    echo "\n{$title}\n";
}

// A weekday's windows in the shape apptAvailability() returns.
function window($weekday, $start, $end) {
    return ['weekday' => $weekday, 'start_time' => $start, 'end_time' => $end];
}

function at($string, $tz = 'Europe/London') {
    return new DateTime($string, new DateTimeZone($tz));
}

// Slots as 'Y-m-d H:i', which is what every assertion below is actually about.
function slotStrings(array $slots) {
    return array_map(fn(DateTime $d) => $d->format('Y-m-d H:i'), $slots);
}

// 2026-09-14 is a Monday, which keeps every fixture below readable.
const MON = '2026-09-14';
const TUE = '2026-09-15';
const SAT = '2026-09-19';
const SUN = '2026-09-20';

// --- Enabled days -----------------------------------------------------------

group('An enabled day offers slots inside its window');

$week = [window(1, '09:00', '12:00')];
$slots = apptFreeSlots($week, [], at(MON . ' 00:00'), 60, ['horizon_days' => 0]);

equals('the first slot is the opening time', MON . ' 09:00', slotStrings($slots)[0] ?? null);
equals('slots step by 15 minutes', MON . ' 09:15', slotStrings($slots)[1] ?? null);
equals('the last slot ends exactly at closing', MON . ' 11:00', end($slots)->format('Y-m-d H:i'));

group('Two windows on one day are both offered, and the gap between them is not');

$split = [window(1, '09:00', '10:00'), window(1, '14:00', '15:00')];
$slots = slotStrings(apptFreeSlots($split, [], at(MON . ' 00:00'), 60, ['horizon_days' => 0]));
equals('one slot per window', [MON . ' 09:00', MON . ' 14:00'], $slots);

group('Windows are read in time order however they are stored');

$reversed = [window(1, '14:00', '15:00'), window(1, '09:00', '10:00')];
$slots = slotStrings(apptFreeSlots($reversed, [], at(MON . ' 00:00'), 60, ['horizon_days' => 0]));
equals('the morning window still comes first', [MON . ' 09:00', MON . ' 14:00'], $slots);

// --- Disabled days ----------------------------------------------------------

group('A day with no window is closed');

$weekdaysOnly = [
    window(1, '09:00', '17:00'), window(2, '09:00', '17:00'), window(3, '09:00', '17:00'),
    window(4, '09:00', '17:00'), window(5, '09:00', '17:00'),
];

$saturday = apptFreeSlots($weekdaysOnly, [], at(SAT . ' 00:00'), 60, ['horizon_days' => 1]);
check('nothing is offered on a closed Saturday or Sunday', $saturday === [],
    'got ' . count($saturday) . ' slot(s): ' . implode(', ', array_slice(slotStrings($saturday), 0, 3)));

$fromFriday = apptFreeSlots($weekdaysOnly, [], at('2026-09-18 16:30'), 60, [
    'horizon_days' => 3, 'per_day' => 1,
]);
equals('a Friday evening enquiry is offered the following Monday',
    ['2026-09-21 09:00'], slotStrings($fromFriday));

check('an empty schedule offers nothing at all',
    apptFreeSlots([], [], at(MON . ' 00:00'), 60) === []);

check('a day is closed by the absence of a window, never a zero-length one',
    apptFreeSlots([window(0, '09:00', '09:00')], [], at(SUN . ' 00:00'), 30, ['horizon_days' => 0]) === []);

// --- Boundaries -------------------------------------------------------------

group('An appointment must finish before closing, not merely start before it');

$window = [window(1, '09:00', '10:00')];

equals('a 60-minute service gets exactly one slot in a 60-minute window',
    [MON . ' 09:00'],
    slotStrings(apptFreeSlots($window, [], at(MON . ' 00:00'), 60, ['horizon_days' => 0])));

equals('a 90-minute service does not fit at all',
    [],
    slotStrings(apptFreeSlots($window, [], at(MON . ' 00:00'), 90, ['horizon_days' => 0])));

equals('a 30-minute service fits three times',
    [MON . ' 09:00', MON . ' 09:15', MON . ' 09:30'],
    slotStrings(apptFreeSlots($window, [], at(MON . ' 00:00'), 30, ['horizon_days' => 0])));

group('The same rule, as apptWithinAvailability() sees it');

$hours = [window(1, '09:00', '17:00')];
check('16:00 is inside a window closing at 17:00 for an hour',
    apptWithinAvailability($hours, at(MON . ' 16:00'), 60));
check('16:15 is not, because it would overrun',
    !apptWithinAvailability($hours, at(MON . ' 16:15'), 60));
check('09:00 exactly is inside',
    apptWithinAvailability($hours, at(MON . ' 09:00'), 60));
check('08:59 is not',
    !apptWithinAvailability($hours, at(MON . ' 08:59'), 60));
check('a Sunday is refused against a Monday-only schedule',
    !apptWithinAvailability($hours, at(SUN . ' 10:00'), 60));
check('no schedule at all refuses everything',
    !apptWithinAvailability([], at(MON . ' 10:00'), 60));

group('Times already past, and the minimum notice');

$slots = slotStrings(apptFreeSlots($hours, [], at(MON . ' 15:40'), 60, ['horizon_days' => 0]));
equals('nothing before the moment asked about is offered', MON . ' 15:45', $slots[0] ?? null);

$slots = slotStrings(apptFreeSlots($hours, [], at(MON . ' 09:00'), 60, [
    'horizon_days' => 0,
    'earliest' => at(MON . ' 11:20'),
]));
equals('the minimum notice pushes the first slot past it', MON . ' 11:30', $slots[0] ?? null);

// --- Bookings already taken -------------------------------------------------

group('A booked slot is not offered twice');

$busy = [[
    'start' => at(MON . ' 09:00'),
    'end'   => at(MON . ' 10:00'),
]];
$slots = slotStrings(apptFreeSlots([window(1, '09:00', '11:00')], $busy, at(MON . ' 00:00'), 60, ['horizon_days' => 0]));
equals('the taken hour and every start that overlaps it are gone',
    [MON . ' 10:00'], $slots);

check('a slot that merely touches a booking is still free',
    apptOverlapsBusy($busy, at(MON . ' 10:00'), at(MON . ' 11:00')) === false);
check('a slot that starts inside a booking is not',
    apptOverlapsBusy($busy, at(MON . ' 09:30'), at(MON . ' 10:30')));
check('a slot that ends inside a booking is not',
    apptOverlapsBusy($busy, at(MON . ' 08:30'), at(MON . ' 09:30')));
check('a slot that swallows a booking whole is not',
    apptOverlapsBusy($busy, at(MON . ' 08:00'), at(MON . ' 12:00')));

group('A fully booked day falls through to the next open one');

$full = [[
    'start' => at(MON . ' 09:00'),
    'end'   => at(MON . ' 17:00'),
]];
$slots = slotStrings(apptFreeSlots(
    [window(1, '09:00', '17:00'), window(2, '09:00', '17:00')],
    $full, at(MON . ' 00:00'), 60, ['horizon_days' => 1, 'per_day' => 1]
));
equals('Monday is skipped entirely', [TUE . ' 09:00'], $slots);

// --- The horizon and the caps ------------------------------------------------

group('The horizon and the offer caps');

$everyDay = [];
foreach (range(0, 6) as $d) $everyDay[] = window($d, '09:00', '17:00');

$slots = apptFreeSlots($everyDay, [], at(MON . ' 00:00'), 60, ['horizon_days' => 2, 'per_day' => 1]);
equals('the horizon is inclusive of its last day', 3, count($slots));
equals('and it stops there', '2026-09-16 09:00', end($slots)->format('Y-m-d H:i'));

// The far edge is an instant, not a date: "at most 30 days ahead" measured to
// the hour, so an offer cannot be made that the booking check would then refuse.
$mornings = [];
foreach (range(0, 6) as $d) $mornings[] = window($d, '09:00', '11:00');

equals('nothing past the end of the booking window is offered',
    [MON . ' 09:00', MON . ' 09:15', MON . ' 09:30', MON . ' 09:45', MON . ' 10:00',
     TUE . ' 09:00', TUE . ' 09:15', TUE . ' 09:30'],
    slotStrings(apptFreeSlots($mornings, [], at(MON . ' 00:00'), 60, [
        'horizon_days' => 30,
        'latest' => at(TUE . ' 09:30'),
    ])));

$slots = apptFreeSlots($everyDay, [], at(MON . ' 00:00'), 60, ['limit' => 5, 'per_day' => 2]);
equals('the total cap is honoured', 5, count($slots));
equals('and the per-day cap spreads the offer across days',
    [MON . ' 09:00', MON . ' 09:15', TUE . ' 09:00', TUE . ' 09:15', '2026-09-16 09:00'],
    slotStrings($slots));

// --- Timezones ---------------------------------------------------------------

group('Opening hours are wall-clock time in the tenant\'s own zone');

// The same instant, offered to a tenant in Karachi (UTC+5) and one in London
// (UTC+1 in September). Both open 09:00–10:00 on the Monday, and both must be
// offered their own 09:00 — the whole point of storing hours as local time.
foreach (['Asia/Karachi', 'Europe/London', 'America/New_York'] as $zone) {
    $slots = slotStrings(apptFreeSlots(
        [window(1, '09:00', '10:00')], [], at(MON . ' 00:00', $zone), 60, ['horizon_days' => 0]
    ));
    equals("09:00 local in {$zone}", [MON . ' 09:00'], $slots);
}

group('A booking made in one zone blocks the right local slot');

// 04:00 UTC is 09:00 in Karachi. Stored in UTC, converted the way
// apptBusyWindows() converts it, it must remove Karachi's 09:00 — not 04:00.
$karachi = new DateTimeZone('Asia/Karachi');
$storedUtc = new DateTime(MON . ' 04:00', new DateTimeZone('UTC'));
$busy = [[
    'start' => (clone $storedUtc)->setTimezone($karachi),
    'end'   => (clone $storedUtc)->modify('+1 hour')->setTimezone($karachi),
]];

equals('the converted booking hides the tenant\'s 09:00 and nothing else',
    [MON . ' 10:00'],
    slotStrings(apptFreeSlots([window(1, '09:00', '11:00')], $busy,
        new DateTime(MON . ' 00:00', $karachi), 60, ['horizon_days' => 0])));

group('Slots stay on the clock across a daylight-saving change');

// London puts its clocks forward at 01:00 on 2026-03-29. A grid walked in UTC
// would drift an hour after it; walked in local time, 09:00 stays 09:00.
$acrossDst = apptFreeSlots($everyDay, [], at('2026-03-27 00:00'), 60, [
    'horizon_days' => 4, 'per_day' => 1,
]);
equals('every day still opens at 09:00 local',
    ['2026-03-27 09:00', '2026-03-28 09:00', '2026-03-29 09:00', '2026-03-30 09:00', '2026-03-31 09:00'],
    slotStrings($acrossDst));

// --- What the tenant is allowed to save --------------------------------------

group('A week that cannot be saved');

[$clean, $errors] = apptValidateWeek([
    ['weekday' => 1, 'start' => '09:00', 'end' => '17:00'],
]);
equals('a plain day is accepted', [], $errors);
equals('and comes back normalised', 1, count($clean));

[, $errors] = apptValidateWeek([['weekday' => 1, 'start' => '17:00', 'end' => '09:00']]);
check('an end before its start is refused', isset($errors['day[1][start][]']));

[, $errors] = apptValidateWeek([['weekday' => 1, 'start' => '09:00', 'end' => '09:00']]);
check('a zero-length window is refused', isset($errors['day[1][start][]']));

[, $errors] = apptValidateWeek([['weekday' => 2, 'start' => '25:00', 'end' => '26:00']]);
check('a time that is not a time is refused', isset($errors['day[2][start][]']));

[, $errors] = apptValidateWeek([['weekday' => 3, 'start' => '09:00', 'end' => '']]);
check('a half-filled window is refused', isset($errors['day[3][start][]']));

[, $errors] = apptValidateWeek([
    ['weekday' => 4, 'start' => '09:00', 'end' => '12:00'],
    ['weekday' => 4, 'start' => '11:00', 'end' => '15:00'],
]);
check('two windows that overlap on one day are refused', isset($errors['day[4][start][]']));

[$clean, $errors] = apptValidateWeek([
    ['weekday' => 4, 'start' => '09:00', 'end' => '12:00'],
    ['weekday' => 4, 'start' => '12:00', 'end' => '15:00'],
]);
equals('two that merely touch are fine', [], $errors);
equals('and both are kept', 2, count($clean));

[$clean, ] = apptValidateWeek([['weekday' => 9, 'start' => '09:00', 'end' => '17:00']]);
equals('a weekday that does not exist is dropped, not saved', [], $clean);

group('A saved week reaches the slot maths unchanged');

// The round trip the tab actually performs: what apptValidateWeek() accepts is
// written as appointment_availability rows, and those rows are what
// apptFreeSlots() reads. This is the join the issue was about — a form that
// saves and a calculation that ignores it look identical from the outside.
[$clean, ] = apptValidateWeek([
    ['weekday' => 1, 'start' => '09:00', 'end' => '10:00'],
    ['weekday' => 6, 'start' => '10:00', 'end' => '11:00'],
]);
$stored = array_map(fn($w) => window($w['weekday'], $w['start'], $w['end']), $clean);

equals('the saved Monday and Saturday are exactly what is offered',
    [MON . ' 09:00', SAT . ' 10:00'],
    slotStrings(apptFreeSlots($stored, [], at(MON . ' 00:00'), 60, ['horizon_days' => 6, 'per_day' => 1])));

// --- Reminder lead times (#36) -----------------------------------------------

group('Reminder lead times read the same however they arrive');

equals('a stored string', [1440, 60], apptReminderMinutes(['reminder_minutes' => '1440,60']));
equals('the form\'s checkbox array', [1440, 60], apptReminderMinutes(['reminder_minutes' => ['60', '1440']]));
equals('nothing ticked means no reminders', [], apptReminderMinutes(['reminder_minutes' => []]));
equals('duplicates collapse', [60], apptReminderMinutes(['reminder_minutes' => '60,60,60']));
equals('junk and out-of-range values are dropped',
    [1440], apptReminderMinutes(['reminder_minutes' => '1440,0,-5,99999,abc']));

group('And they are always shown with their unit');

equals('minutes', '30 minutes', apptHumanMinutes(30));
equals('an hour', '1 hour', apptHumanMinutes(60));
equals('hours', '12 hours', apptHumanMinutes(720));
equals('a day', '1 day', apptHumanMinutes(1440));
equals('days', '2 days', apptHumanMinutes(2880));
check('every offered choice has a label', array_filter(apptReminderChoices()) === apptReminderChoices());
check('and every one of them survives being saved and read back',
    apptReminderMinutes(['reminder_minutes' => array_keys(apptReminderChoices())])
        === array_reverse(array_keys(apptReminderChoices())));

// --- When a reminder is too late to be worth sending (#39) --------------------

group('A reminder may be late, but not so late that it lies');

// The message quotes its own lead time ("in 1 day"), so the window scales with
// it. These are the numbers the sender decides on after an outage.
equals('a quarter-hour warning gets the floor, not half of nothing',
    15, apptReminderGraceMinutes(15));
equals('an hour before allows half an hour', 30, apptReminderGraceMinutes(60));
equals('a day before allows the two-hour ceiling', 120, apptReminderGraceMinutes(1440));
equals('a week before is still capped at two hours', 120, apptReminderGraceMinutes(10080));
equals('a nonsense lead time still has a floor', 15, apptReminderGraceMinutes(-5));

check('a restart that delayed the tick by a minute still sends',
    !apptReminderTooLate(60, 1));
check('and so does one that delayed it by the whole window',
    !apptReminderTooLate(60, 30));
check('a minute past the window does not',
    apptReminderTooLate(60, 31));
check('a day-before reminder sent twenty hours late is refused',
    apptReminderTooLate(1440, 1200));
check('the hour-before reminder for the same appointment is judged separately',
    !apptReminderTooLate(60, 5));
check('a reminder sent on time is never too late',
    !apptReminderTooLate(15, 0));

group('A failing send backs off, then gives up');

equals('the first retry is a minute away', 1, apptReminderRetryDelayMinutes(1));
equals('then two', 2, apptReminderRetryDelayMinutes(2));
equals('then four', 4, apptReminderRetryDelayMinutes(3));
equals('and it flattens out rather than growing forever',
    30, apptReminderRetryDelayMinutes(APPT_REMINDER_MAX_ATTEMPTS));
check('every delay is at least a minute and at most half an hour',
    (function () {
        foreach (range(0, APPT_REMINDER_MAX_ATTEMPTS + 5) as $n) {
            $d = apptReminderRetryDelayMinutes($n);
            if ($d < 1 || $d > 30) return false;
        }
        return true;
    })());
check('the retries are spent inside a couple of hours, not left running all day',
    (function () {
        $total = 0;
        foreach (range(1, APPT_REMINDER_MAX_ATTEMPTS) as $n) {
            $total += apptReminderRetryDelayMinutes($n);
        }
        return $total > 30 && $total < 180;
    })());

// --- How a free list is written out ------------------------------------------

group('Free slots are grouped by day for the prompt');

$lines = apptSlotLines([
    at(MON . ' 09:00'), at(MON . ' 11:30'), at(TUE . ' 14:00'),
]);
equals('one line per day, times joined',
    ['Mon 14 Sep: 09:00, 11:30', 'Tue 15 Sep: 14:00'], $lines);
equals('nothing free is no lines', [], apptSlotLines([]));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
