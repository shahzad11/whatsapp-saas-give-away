<?php

// Housekeeping (#29): log and queue tables grow forever if nothing trims them,
// and the failure mode of an unbounded table is a full disk discovered by the
// tenant, not by us.
//
// Runs off the reminder tick — the only regular task runner in the stack —
// throttled to once per 24h via the `maintenance_run_at` app setting. Each
// table gets one bounded DELETE per run (LIMIT 5000): a table that is days
// behind drains over several runs rather than locking itself for one long one,
// which matters because this tick shares a process with reminder sends.
//
// Returns ['ran' => bool, 'deleted' => table => count].

function maintenanceCleanup(mysqli $conn): array {
    $last = (string)appSetting($conn, 'maintenance_run_at', '');
    if ($last !== '' && time() - strtotime($last . ' UTC') < 86400) {
        return ['ran' => false, 'deleted' => []];
    }
    // Stamped before the work so overlapping ticks cannot double-run a pass;
    // a crashed run is simply a day late.
    setAppSetting($conn, 'maintenance_run_at', gmdate('Y-m-d H:i:s'));

    $deleted = [];
    $sweep = function (string $table, string $sql, string $types = '', ...$args) use ($conn, &$deleted) {
        $stmt = $conn->prepare($sql);
        if ($types !== '') $stmt->bind_param($types, ...$args);
        $stmt->execute();
        $deleted[$table] = $stmt->affected_rows;
        $stmt->close();
    };

    // Throttle rows are only consulted inside their window, so a month is far
    // more than they need to live.
    $sweep('login_attempts',
        "DELETE FROM login_attempts WHERE created_at < UTC_TIMESTAMP() - INTERVAL 30 DAY LIMIT 5000");

    // Per-message outcomes; useful for a support question, not for history.
    $sweep('chatbot_events',
        "DELETE FROM chatbot_events WHERE created_at < UTC_TIMESTAMP() - INTERVAL 90 DAY LIMIT 5000");

    $auditDays = auditRetentionDays($conn);
    $sweep('audit_log',
        "DELETE FROM audit_log WHERE created_at < UTC_TIMESTAMP() - INTERVAL ? DAY LIMIT 5000",
        'i', $auditDays);

    // Claimed deferred replies are finished work — the unclaimed ones are kept
    // regardless of age because dropping one would swallow a customer's reply.
    $sweep('chatbot_pending_replies',
        "DELETE FROM chatbot_pending_replies
         WHERE claimed_at IS NOT NULL AND claimed_at < UTC_TIMESTAMP() - INTERVAL 7 DAY
         LIMIT 5000");

    // Terminal notices only: 'sending' rows are mid-flight claims the reminder
    // tick can still recover, and created_at is when the notice was written —
    // a failed-then-resent row ages by its first attempt, which is fine.
    $sweep('appointment_notifications',
        "DELETE FROM appointment_notifications
         WHERE status IN ('sent','failed','skipped')
           AND created_at < UTC_TIMESTAMP() - INTERVAL 90 DAY
         LIMIT 5000");

    return ['ran' => true, 'deleted' => $deleted];
}
