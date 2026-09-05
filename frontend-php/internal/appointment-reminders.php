<?php
// Sends appointment reminders that have come due (#14).
//
// Called on a timer by the Node backend, which is the only thing in the stack
// that runs continuously — there is no cron in the frontend image and adding one
// would mean a second scheduler to reason about. Authenticated with the same
// shared secret as the chatbot webhook.
//
// Safe to call as often as you like: each reminder row is claimed with a
// conditional UPDATE before anything is sent, so overlapping runs cannot make a
// customer's phone buzz twice.
require_once dirname(__DIR__) . '/config/init.php';

header('Content-Type: application/json');

$provided = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
if ($provided === '' || !hash_equals(BACKEND_API_KEY, $provided)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

set_time_limit(120);

$due = apptDueReminders($conn, 50);
$sent = 0;
$failed = 0;
$skipped = 0;

foreach ($due as $row) {
    $reminderId = (int)$row['reminder_id'];

    // Claim first. If another run already took it, do nothing at all.
    if (!apptClaimReminder($conn, $reminderId)) { $skipped++; continue; }

    if (empty($row['session_id']) || empty($row['chat_id'])) {
        apptMarkReminder($conn, $reminderId, 'skipped', 'no linked account or chat for this appointment');
        $skipped++;
        continue;
    }

    $userId = (int)$row['user_id'];

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
    // allowance the reminder is dropped rather than queued forever.
    [$quotaOk] = checkMessageQuota($conn, $userId);
    if (!$quotaOk) {
        apptMarkReminder($conn, $reminderId, 'failed', 'monthly message limit reached');
        $failed++;
        continue;
    }

    $tz = getUserTimezone($conn, $userId);
    $whenLocal = convertToUserTz($row['scheduled_at'], $tz);
    $whenLabel = date('D j M Y, H:i', strtotime($whenLocal));

    $text = apptReminderText($row, $whenLabel, (int)$row['minutes_before']);
    $ok = chatbotSendReply($conn, $userId, 't' . $userId, $row['session_id'], $row['chat_id'], $text);

    if ($ok) {
        $sent++;
        logAudit($conn, 'appointment.reminded', 'appointment', (string)$row['id'],
            ['minutes_before' => (int)$row['minutes_before']], $userId);
    } else {
        // Put it back: the appointment has not happened yet, so the next run
        // should try again rather than silently give up.
        apptMarkReminder($conn, $reminderId, 'pending', 'send failed, will retry');
        $failed++;
    }
}

echo json_encode(['ok' => true, 'due' => count($due), 'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped]);
