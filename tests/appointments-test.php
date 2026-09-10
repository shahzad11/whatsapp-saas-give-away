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
require_once __DIR__ . '/../frontend-php/includes/functions.php'; // formatUserDate()
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

group('Free times are written out for the customer');

equals('times joined, in order',
    '09:00, 11:30, 14:00',
    apptTimeList([at(MON . ' 09:00'), at(MON . ' 11:30'), at(MON . ' 14:00')]));
equals('nothing free is nothing written', '', apptTimeList([]));

// --- One date, the whole day (#42) -------------------------------------------
//
// The behaviour the issue was about: the customer names a service and a date,
// and what comes back is *every* free start time on that date — not the first
// three, not a per-service sample, and not something remembered from earlier in
// the conversation.

group('A whole day is offered, not a sample of it');

$fullDay = [window(1, '09:00', '17:00')];
$day = apptDayFreeSlots($fullDay, [], at(MON . ' 00:00'), 30);

equals('a 30-minute service gets every 15-minute start until it stops fitting',
    31, count($day));
equals('starting at opening', MON . ' 09:00', slotStrings($day)[0]);
equals('and ending one duration before closing', MON . ' 16:30', end($day)->format('Y-m-d H:i'));
check('the old three-a-day cap is gone', count($day) > 3);

group('Every service shares the day; only its duration differs');

// The heart of the bug: the same diary, so a long service is not given its own
// timetable — it simply runs out of room earlier.
$hourly = apptDayFreeSlots($fullDay, [], at(MON . ' 00:00'), 60);
$longer = apptDayFreeSlots($fullDay, [], at(MON . ' 00:00'), 90);

equals('an hour-long service still starts at opening', MON . ' 09:00', slotStrings($hourly)[0]);
equals('and its last start is an hour before closing',
    MON . ' 16:00', end($hourly)->format('Y-m-d H:i'));
equals('a 90-minute service uses the same grid, ending earlier still',
    MON . ' 15:30', end($longer)->format('Y-m-d H:i'));

group("Another service's booking blocks the slot all the same");

$lunchBooking = [['start' => at(MON . ' 12:00'), 'end' => at(MON . ' 13:00')]];
$times = slotStrings(apptDayFreeSlots($fullDay, $lunchBooking, at(MON . ' 00:00'), 60));

check('the booked hour is absent', !in_array(MON . ' 12:00', $times, true));
check('and so is every start that would run into it',
    !in_array(MON . ' 11:15', $times, true) && !in_array(MON . ' 11:30', $times, true));
check('11:00 still fits, because it finishes as the booking starts',
    in_array(MON . ' 11:00', $times, true));
check('13:00 is free again the moment the booking ends',
    in_array(MON . ' 13:00', $times, true));

group('A closed day and a full day are both simply empty');

equals('a closed date offers nothing', [], apptDayFreeSlots($fullDay, [], at(SUN . ' 00:00'), 30));
equals('and so does a date booked solid', [],
    apptDayFreeSlots($fullDay, [['start' => at(MON . ' 09:00'), 'end' => at(MON . ' 17:00')]],
        at(MON . ' 00:00'), 30));

group('The notice period and the booking window still bind a single day');

equals('nothing inside the minimum notice is offered',
    MON . ' 14:00',
    slotStrings(apptDayFreeSlots($fullDay, [], at(MON . ' 00:00'), 60, ['earliest' => at(MON . ' 13:50')]))[0]);
$capped = apptDayFreeSlots($fullDay, [], at(MON . ' 00:00'), 60, ['latest' => at(MON . ' 10:00')]);
equals('and nothing past the far edge of the window is either',
    MON . ' 10:00', end($capped)->format('Y-m-d H:i'));

group('A day is the tenant\'s own day, in their own zone');

foreach (['Asia/Karachi', 'Europe/London', 'America/New_York'] as $zone) {
    $local = apptDayFreeSlots([window(2, '09:00', '10:00')], [], at(TUE . ' 00:00', $zone), 60);
    equals("the Tuesday window is Tuesday 09:00 in {$zone}", [TUE . ' 09:00'], slotStrings($local));
}

// A booking stored in UTC, converted the way apptBusyWindows() converts it, must
// remove the local slot it actually occupies — 04:00 UTC is Karachi's 09:00.
$karachi = new DateTimeZone('Asia/Karachi');
$storedUtc = new DateTime(MON . ' 04:00', new DateTimeZone('UTC'));
$times = slotStrings(apptDayFreeSlots([window(1, '09:00', '11:00')], [[
    'start' => (clone $storedUtc)->setTimezone($karachi),
    'end'   => (clone $storedUtc)->modify('+1 hour')->setTimezone($karachi),
]], new DateTime(MON . ' 00:00', $karachi), 60));
equals('a booking made in UTC hides the right local hour', [MON . ' 10:00'], $times);

group('A day cannot produce an unbounded list');

$roundTheClock = [window(1, '00:00', '23:59')];
check('the cap holds even for an absurd schedule',
    count(apptDayFreeSlots($roundTheClock, [], at(MON . ' 00:00'), 5)) <= APPT_DAY_SLOT_CAP);

// --- Which date the customer meant (#42) -------------------------------------

group('The date a customer asked about');

$now = at(MON . ' 10:00');

[$resolved, $why] = apptResolveDate(TUE, 'Europe/London', 30, $now);
equals('a plain date resolves to midnight that day', TUE . ' 00:00', $resolved->format('Y-m-d H:i'));
equals('with no complaint', null, $why);

[$resolved, ] = apptResolveDate(TUE . ' 15:00', 'Europe/London', 30, $now);
equals('a date that arrived with a time still resolves to the day',
    TUE . ' 00:00', $resolved->format('Y-m-d H:i'));

[$resolved, $why] = apptResolveDate(MON, 'Europe/London', 30, $now);
equals('today is a legitimate date at 10am', MON . ' 00:00', $resolved->format('Y-m-d H:i'));

[, $why] = apptResolveDate('2026-09-13', 'Europe/London', 30, $now);
equals('yesterday is not', 'past', $why);

[, $why] = apptResolveDate('tomorrow', 'Europe/London', 30, $now);
equals('and neither is a word', 'no date', $why);

[, $why] = apptResolveDate('2026-02-31', 'Europe/London', 30, $now);
equals('a date that does not exist is refused rather than rolled into March',
    'no date', $why);

[, $why] = apptResolveDate('2026-09-16', 'Europe/London', 1, $now);
equals('past the booking window is refused', 'horizon', $why);

[$resolved, $why] = apptResolveDate('2026-09-15', 'Europe/London', 1, $now);
equals('the last day inside it is not', TUE . ' 00:00', $resolved->format('Y-m-d H:i'));

// --- What the customer is actually told (#42) ---------------------------------

group('The answer the bot sends back');

equals('every free time, with the date said once',
    'Free times for Haircut on Mon 14 Sep: 09:00, 09:15. Which of those would you like?',
    apptAvailabilityMessage([
        'service' => 'Haircut',
        'slots' => [at(MON . ' 09:00'), at(MON . ' 09:15')],
        'availability' => $fullDay,
        'asked' => at(MON . ' 00:00'),
    ]));

equals('a closed day says so, in the tenant\'s own hours, and offers the next one',
    'We are closed on Sundays. The next day with space is Mon 14 Sep: 09:00. Would any of those suit?',
    apptAvailabilityMessage([
        'service' => 'Haircut',
        'slots' => [],
        'availability' => $fullDay,
        'asked' => at(SUN . ' 00:00'),
        'next_date' => at(MON . ' 09:00'),
        'next_slots' => [at(MON . ' 09:00')],
    ]));

equals('a full day says it is full, not that it is closed',
    'There is nothing free for Haircut on Mon 14 Sep. The next day with space is Tue 15 Sep: 09:00. '
    . 'Would any of those suit?',
    apptAvailabilityMessage([
        'service' => 'Haircut',
        'slots' => [],
        'availability' => [window(1, '09:00', '17:00'), window(2, '09:00', '17:00')],
        'asked' => at(MON . ' 00:00'),
        'next_date' => at(TUE . ' 09:00'),
        'next_slots' => [at(TUE . ' 09:00')],
    ]));

check('an empty booking window never invents an alternative',
    str_contains(apptAvailabilityMessage([
        'service' => 'Haircut',
        'slots' => [],
        'availability' => $fullDay,
        'asked' => at(MON . ' 00:00'),
    ]), 'nothing free between now and the end of our booking window'));

group('And when the request itself cannot be used');

check('no date asks for one',
    apptAvailabilityMessage(['reason' => 'no date']) === 'Which date would you like me to check?');
check('a past date says so and asks again',
    str_contains(apptAvailabilityMessage(['reason' => 'past']), 'already passed'));
check('too far ahead quotes the window the tenant configured',
    str_contains(apptAvailabilityMessage(['reason' => 'horizon', 'horizon_days' => 14]), '(14 days)'));
check('no opening hours at all promises a person instead of a time',
    str_contains(apptAvailabilityMessage(['reason' => 'no hours']), 'someone will follow this up'));
check('and none of those ever names a time',
    !preg_match('/\d\d:\d\d/', implode(' ', array_map(
        fn($r) => apptAvailabilityMessage(['reason' => $r, 'horizon_days' => 30]),
        ['no hours', 'no date', 'past', 'horizon']))));

group('The diary, not the conversation, decides');

// What "check the diary again" has to mean. Nothing is remembered between these
// two calls: the second sees a booking the first did not, and answers differently
// for the same service on the same date.
$before = apptDayFreeSlots($fullDay, [], at(MON . ' 00:00'), 60);
$after  = apptDayFreeSlots($fullDay, [['start' => at(MON . ' 10:00'), 'end' => at(MON . ' 11:00')]],
    at(MON . ' 00:00'), 60);

check('10:00 was free on the first lookup', in_array(MON . ' 10:00', slotStrings($before), true));
check('and is gone on the second, taken while the customer was typing',
    !in_array(MON . ' 10:00', slotStrings($after), true));
// Seven starts touch a one-hour booking on a 15-minute grid for an hour-long
// service: 09:15 through 10:45. Everything else is untouched.
equals('and only the starts that overlap it are', count($before) - 7, count($after));

group('"Are you sure?" is recognised in PHP, not left to the model');

// Observed against a real model: told twice that its own earlier list is not
// evidence, it still says "let me check again" and pastes the old times. These
// are the phrases that make PHP re-ask regardless.
foreach (['can you check again please', 'Are you sure?', 'is 10:00 still free?',
          'please double-check that', 'has that changed?', 'any other times?'] as $said) {
    check('"' . $said . '" is a re-check', apptRecheckPhrase($said) !== null);
}
foreach (['I would like to book Tuesday', 'what are your opening hours?', 'thanks!', ''] as $said) {
    check('"' . $said . '" is not', apptRecheckPhrase($said) === null);
}

group('The service and date are read back out of the bot\'s own answer');

$asked = at(MON . ' 10:00');
$conversation = [
    ['fromMe' => false, 'text' => 'I want a haircut on Tuesday'],
    ['fromMe' => true,  'text' => 'Free times for Haircut on Tue 15 Sep: 09:00, 09:15. Which of those would you like?'],
    ['fromMe' => false, 'text' => 'are you sure 09:00 is free?'],
];
$found = apptQuotedRequestFromHistory($conversation, 'Europe/London', $asked);
equals('the service', 'Haircut', $found['service']);
equals('and the date, resolved forwards to a real one', '2026-09-15', $found['date']);

// The refusal wording carries two dates: the one asked about and the one whose
// times were actually listed. The customer is answering about the second.
$fallback = [['fromMe' => true, 'text' =>
    'There is nothing free for Colour on Mon 14 Sep. The next day with space is Tue 15 Sep: 09:00. Would any of those suit?']];
$found = apptQuotedRequestFromHistory($fallback, 'Europe/London', $asked);
equals('the service from a refusal', 'Colour', $found['service']);
equals('and the date whose times were quoted', '2026-09-15', $found['date']);

check('the customer\'s own words are never parsed as an answer',
    apptQuotedRequestFromHistory([
        ['fromMe' => false, 'text' => 'Free times for Haircut on Tue 15 Sep: 09:00'],
    ], 'Europe/London', $asked) === null);
check('a conversation with no diary answer in it yields nothing',
    apptQuotedRequestFromHistory([
        ['fromMe' => true, 'text' => 'We are open Monday to Friday.'],
    ], 'Europe/London', $asked) === null);
check('and the newest answer wins when there are several',
    apptQuotedRequestFromHistory([
        ['fromMe' => true, 'text' => 'Free times for Haircut on Tue 15 Sep: 09:00.'],
        ['fromMe' => true, 'text' => 'Free times for Colour on Wed 16 Sep: 10:00.'],
    ], 'Europe/London', $asked)['service'] === 'Colour');

// December read in January. The written date has no year because a customer
// reading a diary does not need one.
equals('a date that would be in the past is read as next year', '2027-01-05',
    apptQuotedRequestFromHistory([['fromMe' => true, 'text' => 'Free times for Haircut on Tue 5 Jan: 09:00.']],
        'Europe/London', at('2026-12-30 10:00'))['date']);

group('A stale list is removed when the real one is about to be stated');

equals('the sentence carrying times goes, the rest stays',
    'Let me check that for you.',
    apptStripQuotedTimes("Let me check that for you. We have 09:00, 10:00 and 11:00 free."));
equals('a message that is nothing but times leaves nothing', '',
    apptStripQuotedTimes('09:00, 09:15, 09:30.'));
equals('a message with no times is untouched',
    'Which service would you like?', apptStripQuotedTimes('Which service would you like?'));
equals('line by line, so a list on its own line goes without taking the prose',
    "Sure, one moment.\nI will confirm shortly.",
    apptStripQuotedTimes("Sure, one moment.\nFree: 09:00, 10:00\nI will confirm shortly."));

group('Choosing a service comes first, and every service is offered');

$services = [
    ['name' => 'Haircut', 'duration_minutes' => 30],
    ['name' => 'Colour', 'duration_minutes' => 90],
    ['name' => 'Beard trim', 'duration_minutes' => 15],
];
equals('all of them, with their durations',
    'Haircut (30 minutes), Colour (90 minutes), Beard trim (15 minutes)',
    apptServiceListLine($services));
equals('one service reads as one', 'Haircut (30 minutes)', apptServiceListLine([$services[0]]));

// --- Telling the customer what the tenant changed (#45) ----------------------
//
// The delivery takes its database, its WhatsApp call and its quota as closures,
// so every case below is stated as data: a duplicate submit, a send that fails,
// a booking with nobody to tell. What is being proved is that the tenant is
// never told a customer was notified when they were not.

group('A cancellation and a move are said in the tenant timezone, with no internals');

$appt = ['id' => 7, 'service_name' => 'Haircut', 'customer_name' => 'Sara',
         'chat_id' => '923001234567@s.whatsapp.net', 'account_id' => 3,
         'scheduled_at' => '2026-09-15 09:00:00'];

// 09:00 UTC is 14:00 in Karachi: the customer is shown their own clock, which
// is the tenant's, and never the stored instant.
$cancelled = apptChange('cancelled', '2026-09-15 09:00:00', 'Asia/Karachi');
equals('a cancellation names the service and the local time',
    'Hi Sara, your Haircut on Tue 15 Sep 2026, 14:00 has been cancelled. '
    . 'Reply here if you would like to book another time.',
    apptNoticeText($appt, $cancelled));

$moved = apptChange('rescheduled', '2026-09-16 11:30:00', 'Asia/Karachi', '2026-09-15 09:00:00');
equals('a move states both times, old first',
    'Hi Sara, your Haircut has been moved from Tue 15 Sep 2026, 14:00 to '
    . 'Wed 16 Sep 2026, 16:30. Reply here if the new time does not suit.',
    apptNoticeText($appt, $moved));

equals('a booking with no name still reads as a sentence',
    'Hi, your Haircut on Tue 15 Sep 2026, 14:00 has been cancelled. '
    . 'Reply here if you would like to book another time.',
    apptNoticeText(['service_name' => 'Haircut', 'customer_name' => null], $cancelled));

check('no internal id, status or timezone name reaches the customer',
    !preg_match('/\b(id|user_id|chat_id|booked|no_show|UTC)\b/i', apptNoticeText($appt, $moved)));

group('An internal-only update says nothing at all');

foreach (['completed', 'no_show', ''] as $kind) {
    equals("'{$kind}' produces no message", '', apptNoticeText($appt, ['kind' => $kind, 'when' => 'whenever']));
}

group('A change is identified by what changed, not by when the button was pressed');

equals('the same cancellation twice is one fingerprint',
    apptNoticeFingerprint($cancelled),
    apptNoticeFingerprint(apptChange('cancelled', '2026-09-15 09:00:00', 'Europe/London')));
check('a move carries both instants, so a second, different move is a second message',
    apptNoticeFingerprint($moved) !== apptNoticeFingerprint(
        apptChange('rescheduled', '2026-09-17 11:30:00', 'Asia/Karachi', '2026-09-16 11:30:00')));
check('a cancellation and a move of the same booking are never confused',
    apptNoticeFingerprint($cancelled) !== apptNoticeFingerprint($moved));

// A fake for the whole side-effecting half. $sent is what actually went to
// WhatsApp; $marks is what the row was left saying, which is the half a tenant
// and an auditor read later.
function fakeDeps(array $opts = []) {
    $state = ['sent' => [], 'marks' => [], 'claims' => 0];
    $deps = [
        'claim' => function ($kind, $fingerprint, $body) use (&$state, $opts) {
            $state['claims']++;
            // A fingerprint already taken is a change somebody else is sending.
            if (in_array($fingerprint, $opts['taken'] ?? [], true)) return null;
            $state['body'] = $body;
            return 99;
        },
        'mark' => function ($id, $status, $detail) use (&$state) {
            $state['marks'][] = [$status, $detail];
        },
        'channel' => fn() => ($opts['channel'] ?? true)
            ? ['session_id' => 't1-abc', 'chat_id' => '923001234567@s.whatsapp.net'] : null,
        'quota' => fn() => $opts['quota'] ?? true,
        'send' => function (array $channel, $text) use (&$state, $opts) {
            if (!empty($opts['throws'])) throw new RuntimeException('socket closed');
            $state['sent'][] = [$channel['chat_id'], $text];
            return $opts['sends'] ?? true;
        },
    ];
    return [$deps, function () use (&$state) { return $state; }];
}

group('A delivered message is recorded as sent, once');

[$deps, $read] = fakeDeps();
$result = apptSendNotice($deps, 'cancelled', 'cancelled:2026-09-15 09:00:00', 'Hi Sara, …');
equals('the tenant is told it went', 'sent', $result['status']);
equals('exactly one message', 1, count($read()['sent']));
equals('through the chat the booking was made in',
    '923001234567@s.whatsapp.net', $read()['sent'][0][0]);
equals('and the row says sent', [['sent', null]], $read()['marks']);

group('A form submitted twice does not message the customer twice');

[$deps, $read] = fakeDeps(['taken' => ['cancelled:2026-09-15 09:00:00']]);
$result = apptSendNotice($deps, 'cancelled', 'cancelled:2026-09-15 09:00:00', 'Hi Sara, …');
equals('the second submit is a duplicate', 'duplicate', $result['status']);
equals('and sends nothing', 0, count($read()['sent']));

group('A chatbot change the customer has already heard about is not repeated');

// The chatbot writes its own reply down as delivered, so the fingerprint is
// taken by the time a tenant presses Cancel on the same booking.
[$deps, $read] = fakeDeps(['taken' => ['cancelled:2026-09-15 09:00:00']]);
equals('the dashboard stays quiet', 0,
    count($read()['sent']) + (apptSendNotice($deps, 'cancelled', 'cancelled:2026-09-15 09:00:00', 'x')['status'] === 'duplicate' ? 0 : 1));

group('A send that fails is never reported as a customer who was told');

[$deps, $read] = fakeDeps(['sends' => false]);
$result = apptSendNotice($deps, 'cancelled', 'cancelled:x', 'Hi Sara, …');
equals('the tenant is told it failed', 'failed', $result['status']);
equals('the row is left retryable', 'failed', $read()['marks'][0][0]);
check('with a reason attached', ($read()['marks'][0][1] ?? '') !== '');

[$deps, $read] = fakeDeps(['throws' => true]);
equals('a thrown send is a failure, not a crash', 'failed',
    apptSendNotice($deps, 'cancelled', 'cancelled:x', 'Hi Sara, …')['status']);

group('A booking with nobody to tell is recorded honestly and left editable');

[$deps, $read] = fakeDeps(['channel' => false]);
$result = apptSendNotice($deps, 'cancelled', 'cancelled:x', 'Hi Sara, …');
equals('skipped, not failed — there is nothing to retry', 'skipped', $result['status']);
equals('nothing was sent', 0, count($read()['sent']));
equals('and the row says why', 'skipped', $read()['marks'][0][0]);

group('Out of allowance is a failure the tenant sees, not a silent drop');

[$deps, $read] = fakeDeps(['quota' => false]);
$result = apptSendNotice($deps, 'cancelled', 'cancelled:x', 'Hi Sara, …');
equals('failed', 'failed', $result['status']);
equals('and no message was attempted', 0, count($read()['sent']));

group('What the tenant reads never overstates what the customer got');

[$msg, $variant] = apptNoticeSummary('Appointment cancelled.', ['status' => 'sent']);
equals('a delivered message is a plain success', 'success', $variant);
check('and says so', str_contains($msg, 'has been told'));

[$msg, $variant] = apptNoticeSummary('Appointment cancelled.', ['status' => 'failed', 'detail' => 'WhatsApp did not accept the message']);
equals('a failure is a warning, not a success', 'warning', $variant);
check('the cancellation itself still stands', str_starts_with($msg, 'Appointment cancelled.'));
check('the tenant is told the customer was not told', str_contains($msg, 'NOT told'));
check('and how to try again', str_contains($msg, 'Tell customer'));

[$msg, $variant] = apptNoticeSummary('Appointment cancelled.', ['status' => 'skipped', 'detail' => 'no chat']);
equals('no linked chat is a warning too', 'warning', $variant);
check('and says plainly that nothing was sent', str_contains($msg, 'could not be told'));

[$msg, $variant] = apptNoticeSummary('Appointment updated.', ['status' => 'none']);
equals('an internal-only change is reported as it always was', 'Appointment updated.', $msg);
equals('success', 'success', $variant);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
