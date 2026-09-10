<?php
// Sends appointment reminders that have come due (#14, hardened in #39).
//
// Called on a timer by the Node backend, which is the only thing in the stack
// that runs continuously — there is no cron in the frontend image and adding one
// would mean a second scheduler to reason about. Authenticated with the same
// shared secret as the chatbot webhook.
//
// Safe to call as often as you like, and from as many instances as you like:
// each reminder row is claimed with a conditional UPDATE before anything is
// sent, so overlapping runs produce one winner per reminder and the losers send
// nothing. Every state transition is a single statement against the row, so the
// worst a killed tick can do is leave a claim behind — which the next tick
// recovers.
//
// The response is the health signal as well as the result. See
// docs in technical-doc.md ("Appointment reminders") for what each counter means
// and how to verify the loop in production.
require_once dirname(__DIR__) . '/config/init.php';

header('Content-Type: application/json');

$provided = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
if ($provided === '' || !hash_equals(BACKEND_API_KEY, $provided)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

set_time_limit(120);
// The caller gives up after 45 seconds. Without this, its abort would kill this
// process somewhere in the middle of the loop — most likely between a claim and
// its send, which is the one moment that costs a reminder. Finishing the batch
// and reporting into the log is strictly better; the caller has already stopped
// listening either way.
ignore_user_abort(true);

// Leave the caller's timeout a wide margin: the loop stops accepting new work
// well before the abort, and whatever is left is simply due again next minute.
const TICK_BUDGET_SECONDS = 30;
$startedAt = microtime(true);

$sent = 0;
$failed = 0;
$skipped = 0;
$missed = 0;
$retrying = 0;
$deferred = 0;
$due = [];

// The whole pass is wrapped because the caller is a machine that only sees a
// status code. A bare 500 from a database that has not run the current
// migrations yet — a frontend container up before MySQL finished applying
// schema.sql — is indistinguishable from any other outage, and the answer is in
// the message. Reported rather than swallowed: a tick that could not run must
// not look like a tick with nothing to do.
try {

    // Housekeeping first, so the batch below sees a truthful table.
    //
    // Recovery before selection: a reminder orphaned by the previous tick is due
    // *now*, and making it wait another minute for no reason is the difference
    // between "a restart delayed a reminder" and "a restart lost one".
    $recovered = apptRecoverStuckReminders($conn);
    $missedSwept = apptSweepMissedReminders($conn);

    $due = apptDueReminders($conn, 50);

    foreach ($due as $row) {
        $reminderId = (int)$row['reminder_id'];

        if (microtime(true) - $startedAt > TICK_BUDGET_SECONDS) {
            // Not an error, and deliberately not claimed: these rows are untouched
            // and still due, so the next tick picks them up from the front of the
            // queue. Counted so a permanently oversubscribed instance is visible
            // rather than merely slow.
            $deferred = count($due) - ($sent + $failed + $skipped + $missed + $retrying);
            break;
        }

        // Claim first. If another run already took it, do nothing at all.
        if (!apptClaimReminder($conn, $reminderId)) { $skipped++; continue; }

        $attempts = (int)$row['attempts'] + 1;
        $userId = (int)$row['user_id'];

        // Re-read the appointment now the row is ours. The batch was selected up to
        // a few seconds ago and a tenant may have cancelled or moved the appointment
        // in between; a reminder for a cancelled booking is worse than a late one.
        $appt = apptById($conn, $userId, (int)$row['id']);
        if (!$appt || $appt['status'] !== 'booked') {
            apptMarkReminder($conn, $reminderId, 'skipped', 'appointment no longer booked');
            $skipped++;
            continue;
        }
        if (strtotime($appt['scheduled_at'] . ' UTC') <= time()) {
            apptMarkReminder($conn, $reminderId, 'missed', 'the appointment passed before this reminder could be sent');
            $missed++;
            continue;
        }
        // A rescheduled appointment has had its reminders rebuilt against the new
        // time, so a claim held against the old one must not go out.
        if ($appt['scheduled_at'] !== $row['scheduled_at']) {
            apptMarkReminder($conn, $reminderId, 'skipped', 'appointment was rescheduled; reminders rebuilt');
            $skipped++;
            continue;
        }

        // Too late to be true. The message names its own lead time, so one sent far
        // outside that window tells the customer something that is no longer the
        // case. Recorded as missed rather than dropped: "nobody was reminded" has to
        // be a state an operator can count.
        if (apptReminderTooLate((int)$row['minutes_before'], (int)$row['late_minutes'])) {
            apptMarkReminder($conn, $reminderId, 'missed',
                'due ' . (int)$row['late_minutes'] . ' minutes ago, outside the '
                . apptReminderGraceMinutes((int)$row['minutes_before']) . '-minute window');
            $missed++;
            continue;
        }

        if (empty($row['session_id']) || empty($row['chat_id'])) {
            apptMarkReminder($conn, $reminderId, 'skipped', 'no linked account or chat for this appointment');
            $skipped++;
            continue;
        }

        // Deliberately *not* gated on the `appointments` plan feature.
        //
        // A queued reminder belongs to an appointment that was already accepted — a
        // real commitment to a real customer who is expecting to be reminded.
        // Dropping it because the tenant's plan changed in the meantime would punish
        // the customer for a billing decision they know nothing about. Losing the
        // lever stops *new* bookings being taken; it does not silently abandon the
        // ones already on the calendar. The same reasoning keeps an already-open
        // handoff silencing the bot in chatbotHandleInbound().
        //
        // A reminder is a message and is metered like one. When the tenant is out of
        // allowance the reminder is dropped rather than queued forever: the
        // allowance resets on a billing boundary, not in the next few minutes, so
        // retrying would burn every attempt on the same answer.
        [$quotaOk] = checkMessageQuota($conn, $userId);
        if (!$quotaOk) {
            apptMarkReminder($conn, $reminderId, 'failed', 'monthly message limit reached');
            $failed++;
            continue;
        }

        $tz = getUserTimezone($conn, $userId);
        $whenLabel = formatUserDate($row['scheduled_at'], $tz, 'D j M Y, H:i');

        $text = apptReminderText($row, $whenLabel, (int)$row['minutes_before']);

        // A send is one HTTP call to the backend and can throw as well as return
        // false. Either way the row must not be left holding a claim.
        try {
            $ok = chatbotSendReply($conn, $userId, 't' . $userId, $row['session_id'], $row['chat_id'], $text);
            $error = 'send failed';
        } catch (Throwable $e) {
            $ok = false;
            $error = 'send error: ' . $e->getMessage();
            error_log("Appointment reminder {$reminderId} threw: " . $e->getMessage());
        }

        if ($ok) {
            apptReminderSent($conn, $reminderId);
            $sent++;
            logAudit($conn, 'appointment.reminded', 'appointment', (string)$row['id'],
                ['minutes_before' => (int)$row['minutes_before'], 'attempts' => $attempts], $userId);
            continue;
        }

        // Hand it back with a backoff. The appointment has not happened yet, so the
        // next run should try again — but a tenant whose WhatsApp account is properly
        // logged out gets a widening gap rather than sixty attempts an hour, and the
        // row gives up eventually instead of retrying until the appointment.
        if (apptReminderRetryLater($conn, $reminderId, $attempts, $error)) {
            $retrying++;
        } else {
            $failed++;
        }
    }

} catch (Throwable $e) {
    error_log('appointment reminder pass failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
        // Whatever the pass managed before it broke, so a partial run is not
        // reported as no run at all.
        'sent'  => $sent,
        'failed' => $failed,
    ]);
    exit;
}

$health = apptReminderHealth($conn);
$elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);

// A heartbeat an operator can read without shell access, and the only record of
// a tick that did nothing — which is almost every tick, and is exactly what
// "the scheduler is alive" looks like. Written last so it means "a full pass
// completed", not "a pass started".
setAppSetting($conn, 'reminder_tick_at', gmdate('Y-m-d H:i:s'));

// error_log, not a silent success: sending, failing and giving up are all things
// somebody will have to explain later. A tick with nothing to do says nothing.
if ($sent || $failed || $missed || $retrying || $recovered || $missedSwept) {
    error_log(sprintf(
        'appointment reminders: sent=%d failed=%d retrying=%d missed=%d skipped=%d '
        . 'recovered=%d swept=%d deferred=%d in %dms',
        $sent, $failed, $retrying, $missed, $skipped, $recovered, $missedSwept, $deferred, $elapsedMs
    ));
}

echo json_encode([
    'ok'         => true,
    'due'        => count($due),
    'sent'       => $sent,
    'failed'     => $failed,
    'retrying'   => $retrying,
    'missed'     => $missed,
    'skipped'    => $skipped,
    'recovered'  => $recovered,
    'swept'      => $missedSwept,
    'deferred'   => $deferred,
    'elapsed_ms' => $elapsedMs,
    'health'     => $health,
]);
