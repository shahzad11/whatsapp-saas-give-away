<?php
// Appointments booked over WhatsApp (#14), plus manual entry.
//
// Everything here is scoped to the logged-in tenant by user_id in the query
// itself, not by a check after the fact — an id from the URL is never trusted to
// belong to the caller.
require_once __DIR__ . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$plan = getUserPlan($conn, $userId);
$hasChatbot = planHasFeature($plan, 'chatbot');
// Booking over WhatsApp needs both: the chatbot is what talks to the customer,
// and `appointments` is the lever for the booking capability itself.
$hasAppointments = $hasChatbot && planHasFeature($plan, 'appointments');
$tz = getUserTimezone($conn, $userId);
$config = chatbotConfig($conn, $userId);

// Every handler below ends at formRespond(): JSON to the page's own fetch(), and
// the same flash-and-redirect as before to a plain form post — one code path, two
// audiences (see includes/ajax.php).
$self = APP_URL . '/appointments.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    formRequireCsrf($self);

    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if (in_array($action, ['completed', 'cancelled', 'no_show', 'booked'], true) && $id) {
        // A status change keeps the filters it was made under, or the tenant is
        // sent from "Booked" back to a list they were not looking at.
        $backTo = $self . '?' . http_build_query($_GET);
        // Read before the write: what the customer was promised is the row as it
        // stands now, and after apptSetStatus() the status it is changing *from*
        // is gone.
        $appt = apptById($conn, $userId, $id);
        // Already in that state. apptSetStatus() changes no row and so used to
        // report "that appointment could not be updated", which is the wrong
        // thing to tell someone whose double-click did exactly what they asked
        // — and under #45 it is the reply a duplicate submit gets, so it has to
        // be honest about the fact that nothing further happened.
        if ($appt && $appt['status'] === $action) {
            formRespond(true, 'That appointment is already '
                . mb_strtolower(apptStatusLabel($action)) . '.', $backTo);
        }
        if ($appt && apptSetStatus($conn, $userId, $id, $action)) {
            logAudit($conn, 'appointment.status_changed', 'appointment', (string)$id, ['status' => $action]);

            // #45: only the endings a customer would notice are announced.
            // "Done" and "No-show" are the tenant writing down what happened
            // after the fact and change nothing about what was promised, so
            // they send nothing.
            $prefix = 'Appointment updated.';
            $result = ['status' => 'none'];
            if ($action === 'cancelled') {
                $prefix = 'Appointment cancelled.';
                $result = apptNotifyCustomer($conn, $userId, $appt,
                    apptChange('cancelled', $appt['scheduled_at'], $tz));
            } elseif ($action === 'booked' && $appt['status'] === 'cancelled') {
                // Reopening is only news to someone who heard it was cancelled.
                $prefix = 'Appointment reopened.';
                if (apptNoticeWasSent($conn, $userId, $id, apptChange('cancelled', $appt['scheduled_at'], $tz))) {
                    $result = apptNotifyCustomer($conn, $userId, $appt,
                        apptChange('reinstated', $appt['scheduled_at'], $tz));
                }
            }
            if ($result['status'] !== 'none') {
                logAudit($conn, 'appointment.customer_notified', 'appointment', (string)$id,
                    ['change' => $action, 'result' => $result['status'], 'detail' => $result['detail']]);
            }

            [$message, $variant] = apptNoticeSummary($prefix, $result);
            formRespond(true, $message, $backTo, [], ['variant' => $variant]);
        }
        // apptSetStatus() only reports false for an id that is not this tenant's
        // or a status it does not know, and both used to redirect in silence.
        formRespond(false, 'That appointment could not be updated.', $backTo);
    }

    // #45: the retry behind "Tell customer". A failed WhatsApp send is recorded
    // rather than swallowed, so there is always something concrete to send
    // again — the message the customer was always going to get, not one rebuilt
    // against a diary that has moved on since.
    if ($action === 'notify_retry' && $id) {
        $backTo = $self . '?' . http_build_query($_GET);
        $result = apptRetryNotice($conn, $userId, $id);
        if ($result['status'] === 'none') {
            formRespond(false, 'There is nothing waiting to be sent for that appointment.', $backTo);
        }
        logAudit($conn, 'appointment.customer_notified', 'appointment', (string)$id,
            ['change' => 'retry', 'result' => $result['status'], 'detail' => $result['detail']]);

        [$message, $variant] = apptNoticeSummary('Tried again.', $result);
        formRespond($result['status'] !== 'failed', $message, $backTo, [], ['variant' => $variant]);
    }

    if ($action === 'reschedule' && $id) {
        $appt = apptById($conn, $userId, $id);
        $when = trim((string)($_POST['scheduled_local'] ?? ''));
        if (!$appt) {
            formRespond(false, 'That appointment could not be found.', $self, ['id' => 'Pick an appointment to move.']);
        }
        if ($when === '') {
            formRespond(false, 'Pick the new date and time.', $self, ['scheduled_local' => 'Required.']);
        }

        // The tenant is moving it by hand, so availability and lead time are
        // advisory — but a clash is still a clash, and double-booking a room
        // is the one thing the calendar exists to prevent.
        try {
            $local = new DateTime($when, new DateTimeZone($tz));
        } catch (Exception $e) {
            formRespond(false, 'That date and time could not be read.', $self,
                ['scheduled_local' => 'Use the date and time picker.']);
        }
        $utc = (clone $local)->setTimezone(new DateTimeZone('UTC'));

        // Already there. Said plainly rather than left to the move below, which
        // updates no row and so used to come back as "that clashes with another
        // booking" — and #45 makes the distinction matter for more than
        // wording: a form submitted twice must not tell the customer their
        // appointment was moved from a time to the same time.
        if ($appt['scheduled_at'] === $utc->format('Y-m-d H:i:s')) {
            formRespond(true, 'That appointment is already at that time.', $self);
        }

        // #35: the clash check and the move are held against every other writer
        // — the chatbot takes the same lock, so a customer cannot be given this
        // slot in the moment between the two statements here.
        $moved = apptWithTenantLock($conn, $userId, function () use ($conn, $userId, $utc, $appt, $id) {
            if (apptConflicts($conn, $userId, $utc, (int)$appt['duration_minutes'], $id)) return false;
            return apptReschedule($conn, $userId, $id, $utc);
        });
        if (!$moved) {
            formRespond(false, 'That clashes with another booking.', $self,
                ['scheduled_local' => 'Something else is already booked then.']);
        }

        logAudit($conn, 'appointment.rescheduled', 'appointment', (string)$id, ['via' => 'dashboard']);

        // #45: the customer is told both times, in the tenant's timezone. The
        // reminders were already rebuilt by apptReschedule(); the person the
        // appointment belongs to used to be the only party not informed.
        $result = apptNotifyCustomer($conn, $userId, $appt,
            apptChange('rescheduled', $utc->format('Y-m-d H:i:s'), $tz, $appt['scheduled_at']));
        if ($result['status'] !== 'none') {
            logAudit($conn, 'appointment.customer_notified', 'appointment', (string)$id,
                ['change' => 'rescheduled', 'result' => $result['status'], 'detail' => $result['detail']]);
        }

        [$message, $variant] = apptNoticeSummary('Appointment moved.', $result);
        formRespond(true, $message, $self, [], ['variant' => $variant]);
    }

    if ($action === 'create') {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $services = apptServices($conn, $userId, false);
        $service = null;
        foreach ($services as $s) if ((int)$s['id'] === $serviceId) $service = $s;

        $when = trim((string)($_POST['scheduled_local'] ?? ''));
        // Named separately now. "Pick a service and a time" left the tenant to
        // work out which of the two the page was complaining about, and with the
        // form in a modal there is no highlighted field to look at unless the
        // reply says which one.
        if (!$service) {
            formRespond(false, 'Pick a service.', $self, ['service_id' => 'Choose one of your services.']);
        }
        if ($when === '') {
            formRespond(false, 'Pick a date and time.', $self, ['scheduled_local' => 'Required.']);
        }
        try {
            $local = new DateTime($when, new DateTimeZone($tz));
        } catch (Exception $e) {
            formRespond(false, 'That date and time could not be read.', $self,
                ['scheduled_local' => 'Use the date and time picker.']);
        }
        $utc = (clone $local)->setTimezone(new DateTimeZone('UTC'));
        $phone = preg_replace('/\D+/', '', (string)($_POST['customer_phone'] ?? ''));

        // #35: same lock as the chatbot's booking path, for the same reason —
        // the clash check is only true for as long as nothing else can write.
        // One appointment is one slot, whichever service it is. Written onto the
        // row rather than looked up later, so changing the slot length never
        // re-times a booking that already exists.
        $slotMinutes = apptSlotMinutes($config);
        $newId = apptWithTenantLock($conn, $userId, function () use ($conn, $userId, $utc, $service, $phone, $slotMinutes) {
            if (apptConflicts($conn, $userId, $utc, $slotMinutes)) return null;
            return apptCreate($conn, $userId, [
                'account_id' => null,
                'service_id' => (int)$service['id'],
                'service_name' => $service['name'],
                'duration_minutes' => $slotMinutes,
                'customer_phone' => $phone ?: null,
                'customer_name' => mb_substr(trim((string)($_POST['customer_name'] ?? '')), 0, 120) ?: null,
                // A manual booking has no chat, so it cannot be reminded over
                // WhatsApp unless a phone maps to one. Recorded honestly rather
                // than pretending a chat exists.
                'chat_id' => $phone ? $phone . '@s.whatsapp.net' : null,
                'scheduled_at' => $utc->format('Y-m-d H:i:s'),
                'notes' => mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 500) ?: null,
                'source' => 'manual',
            ]);
        });
        if (!$newId) {
            formRespond(false, 'That clashes with another booking.', $self,
                ['scheduled_local' => 'Something else is already booked then.']);
        }

        logAudit($conn, 'appointment.booked', 'appointment', (string)$newId, ['via' => 'dashboard']);
        formRespond(true, 'Appointment added.', $self);
    }
}

$filters = [
    'status' => $_GET['status'] ?? 'booked',
    'from' => $_GET['from'] ?? '',
    'to' => $_GET['to'] ?? '',
    'q' => trim((string)($_GET['q'] ?? '')),
    'order' => ($_GET['status'] ?? 'booked') === 'booked' ? 'asc' : 'desc',
    // From/To are dates the tenant typed in their own timezone; apptList()
    // needs the zone to turn them into UTC boundaries.
    'tz' => $tz,
];
$appointments = apptList($conn, $userId, $filters);
$counts = apptCounts($conn, $userId);
$services = apptServices($conn, $userId, true);

// #45: what the customer still has not been told, for the rows on this page.
// One query for the whole listing — a per-row lookup over 500 rows is 500
// queries for something almost always empty.
$notices = apptOutstandingNotices($conn, $userId, array_column($appointments, 'id'));

// The Move form's picker offers the bookings on screen that can still be moved.
// Built from the list already fetched rather than from a second query, so the
// picker and the table can never disagree about what exists — and a row with a
// Move button is always one the picker can select.
$movable = array_values(array_filter($appointments, fn($a) => $a['status'] === 'booked'));

$pageTitle = 'Appointments';
require_once __DIR__ . '/includes/header.php';
?>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['Upcoming', $counts['upcoming'] ?? 0, 'bi-calendar-check', 'primary'],
        ['Past due', $counts['overdue'] ?? 0, 'bi-exclamation-circle', 'warning'],
        ['Completed', $counts['completed'] ?? 0, 'bi-check2-circle', 'success'],
        ['Cancelled', $counts['cancelled'] ?? 0, 'bi-x-circle', 'secondary'],
    ] as [$label, $value, $icon, $colour]): ?>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <i class="bi <?= $icon ?> fs-3 text-<?= $colour ?>"></i>
                    <div>
                        <div class="fs-4 fw-600"><?= number_format($value) ?></div>
                        <div class="text-muted small"><?= $label ?></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php // The page itself is never withheld. Appointments already booked are real
      // commitments to real customers, so a tenant must always be able to see and
      // manage them — and add one by hand — even on a plan that can no longer take
      // new bookings over WhatsApp. Only the automatic intake is gated. ?>
<?php if (!$hasAppointments || empty($config['appointments_enabled'])): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-1"></i>
        WhatsApp booking is switched off, so nothing new will arrive here automatically.
        <?php if (!$hasChatbot): ?>
            It needs a plan that includes the AI chatbot.
        <?php elseif (!planHasFeature($plan, 'appointments')): ?>
            Appointment booking is not part of your plan —
            <a href="<?= APP_URL ?>/billing.php">see plans</a>.
        <?php else: ?>
            Turn it on under <a href="<?= APP_URL ?>/settings.php#tab-appointments">Settings → Appointments</a>.
        <?php endif; ?>
        You can still add appointments by hand below.
    </div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small">Status</label>
                <select name="status" class="form-select form-select-sm" onchange="this.form.requestSubmit()">
                    <?php // From the same map the badges use, so the filter and the
                          // column can never disagree about what a status is called. ?>
                    <?php foreach (APPT_STATUS_LABELS + ['all' => 'All'] as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?= sanitize($filters['from']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?= sanitize($filters['to']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small">Search</label>
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Name, phone or service"
                       value="<?= sanitize($filters['q']) ?>">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary btn-sm flex-fill" type="submit">Filter</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= APP_URL ?>/appointments.php">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><?= count($appointments) ?> appointment<?= count($appointments) === 1 ? '' : 's' ?></span>
        <div class="d-flex align-items-center gap-3">
            <span class="text-muted small">Times shown in <?= sanitize($tz) ?></span>
            <?php // The href is the anchor of the real form at the bottom of the page,
                  // so with JavaScript off this is a link that still gets the tenant to
                  // a working form rather than a button that does nothing. Withheld
                  // entirely when there is no service to book: the card below then holds
                  // the explanation instead of a form, and a link to an explanation
                  // dressed up as "Add by hand" is a dead end. ?>
            <?php if ($services): ?>
                <a href="#apptForm" class="btn btn-sm btn-primary"
                   data-modal-target="#apptCreateModal" data-modal-reset="on"
                   data-modal-title="Add an appointment">
                    <i class="bi bi-plus-lg me-1"></i>Add by hand
                </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead><tr><th>When</th><th>Service</th><th>Customer</th><th>Source</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php if (!$appointments): ?>
                <tr class="is-note"><td colspan="6" class="text-muted small p-3">Nothing matches those filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($appointments as $a):
                $localWhen = convertToUserTz($a['scheduled_at'], $tz);
                $isPast = strtotime($a['scheduled_at'] . ' UTC') < time();
            ?>
                <tr>
                    <td data-label="When">
                        <div class="small fw-500"><?= sanitize(date('D j M Y', strtotime($localWhen))) ?></div>
                        <div class="x-small text-muted"><?= sanitize(date('H:i', strtotime($localWhen))) ?>
                            · <?= (int)$a['duration_minutes'] ?> min</div>
                    </td>
                    <td class="small" data-label="Service"><?= sanitize($a['service_name']) ?></td>
                    <td class="small" data-label="Customer">
                        <?= sanitize($a['customer_name'] ?: '—') ?>
                        <?php if ($a['customer_phone']): ?>
                            <div class="x-small text-muted">+<?= sanitize($a['customer_phone']) ?></div>
                        <?php endif; ?>
                    </td>
                    <?php // Where the booking came from labels the row rather than
                          // describing its state, so it is a tag, not a pill (#33 §7). ?>
                    <td data-label="Source"><span class="badge-tag"><?= sanitize($a['source']) ?></span></td>
                    <td data-label="Status">
                        <?php // The vocabulary lives in includes/appointments.php. This
                              // used to print the stored value, so a tenant was shown the
                              // string "no_show". ?>
                        <span class="badge-status <?= apptStatusClass($a['status'], $isPast) ?>">
                            <?= sanitize(apptStatusLabel($a['status'], $isPast)) ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <?php if ($a['status'] === 'booked'): ?>
                            <?php // Moving one was possible on the server from the day the
                                  // reschedule handler was written, and impossible from the
                                  // page — nothing ever sent it an id or a time, so a booking
                                  // in the wrong slot had to be cancelled and re-entered,
                                  // which loses the note and the audit trail. The href is the
                                  // anchor of the real form below, so this is still a working
                                  // link with JavaScript off. ?>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"
                                        data-bs-popper-config='{"strategy":"fixed"}'
                                        aria-label="Actions for this appointment">Actions</button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <a class="dropdown-item" href="#apptMoveForm"
                                           data-modal-target="#apptMoveModal" data-modal-title="Move appointment"
                                           data-field-id="<?= (int)$a['id'] ?>"
                                           data-field-scheduled-local="<?= sanitize(date('Y-m-d\TH:i', strtotime($localWhen))) ?>">Move</a>
                                    </li>
                                    <?php // Only the two endings a customer would notice are confirmed.
                                          // "Done" is the ordinary outcome and reversible with Reopen,
                                          // so a dialog on it would only teach people to dismiss
                                          // dialogs. data-confirm replaces an inline confirm() rather
                                          // than joining one — both would ask twice. ?>
                                    <li>
                                        <form method="post" data-ajax>
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="completed">
                                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                            <button class="dropdown-item" type="submit">Done</button>
                                        </form>
                                    </li>
                                    <li>
                                        <form method="post" data-ajax>
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="no_show">
                                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                            <button class="dropdown-item text-warning" type="submit"
                                                data-confirm="Mark this customer as a no-show? Their reminders stop and the slot is freed.">No-show</button>
                                        </form>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <?php // #45: the customer *is* told now, so the dialog
                                              // says so — a tenant deciding whether to cancel
                                              // is deciding whether to send that message. ?>
                                        <form method="post" data-ajax>
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="cancelled">
                                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                            <button class="dropdown-item text-danger" type="submit"
                                                data-confirm="Cancel this appointment? The customer is told on WhatsApp and their reminders stop.">Cancel</button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        <?php else: ?>
                            <?php // Not confirmed: reopening puts a booking back the way it
                                  // was, and the clash check runs on the next move anyway. ?>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"
                                        data-bs-popper-config='{"strategy":"fixed"}'
                                        aria-label="Actions for this appointment">Actions</button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <form method="post" data-ajax>
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="booked">
                                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                            <button class="dropdown-item" type="submit">Reopen</button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (!empty($a['notes'])): ?>
                    <tr class="is-note"><td colspan="6" class="x-small text-muted pt-0">Note: <?= sanitize($a['notes']) ?></td></tr>
                <?php endif; ?>
                <?php // #45: a change the customer was not told about is said on
                      // the row it belongs to, not only in the toast that has
                      // long since gone. A failed send gets the retry; a booking
                      // with no WhatsApp chat gets the plain reason, because
                      // there is nothing to try again.
                      $notice = $notices[(int)$a['id']] ?? null; ?>
                <?php if ($notice): ?>
                    <tr class="table-warning is-note">
                        <td colspan="6" class="x-small pt-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            <?php if ($notice['status'] === 'failed'): ?>
                                The customer was not told this booking was
                                <?= sanitize($notice['kind']) ?> —
                                <?= sanitize($notice['detail'] ?: 'the message did not go out') ?>.
                                <form method="post" class="d-inline ms-2" data-ajax>
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="notify_retry">
                                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                    <button class="btn btn-outline-primary btn-sm" type="submit">Tell customer</button>
                                </form>
                            <?php else: ?>
                                This booking has no WhatsApp chat linked to it, so the customer
                                could not be told it was <?= sanitize($notice['kind']) ?>.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php // Rendered once, as an ordinary card, and moved into a Bootstrap modal by
      // forms.js (data-modal-shell). Two things this avoids: rendering the form
      // twice — a modal copy plus a <noscript> copy — puts every field id in the
      // document twice and silently breaks the <label for> pairs of whichever copy
      // comes second; and a form written directly inside a .modal is a form a
      // browser with JavaScript off cannot reach at all, because Bootstrap's CSS
      // hides it and nothing is left to show it. One render, moved, leaves the
      // no-JS case as the plain card at the bottom of the page it always was.
      //
      // Only promoted when it holds a form: with no service defined this card holds
      // the note that says so, and moving that into a modal nothing opens would
      // delete the explanation from the page. ?>
<div class="card" <?= $services ? 'data-modal-shell="apptCreateModal" data-modal-title="Add an appointment"' : '' ?>>
    <div class="card-header"><i class="bi bi-plus-circle me-2"></i>Add by hand</div>
    <div class="card-body">
        <?php if (!$services): ?>
            <p class="text-muted small mb-0">
                Define at least one service first, under <a href="<?= APP_URL ?>/settings.php#tab-appointments">Settings → Appointments</a>.
            </p>
        <?php else: ?>
        <?php // Deliberately not held to the bot's rules. The bot must refuse a
              // slot outside opening hours, inside the minimum notice or beyond
              // the booking horizon, because a customer is asking for it. You are
              // the owner: squeezing someone in after closing is a normal thing
              // to want, so only double-booking is refused here. Saying so means
              // the looser behaviour reads as intent rather than as a missing
              // check — the rules are stated in Settings → Appointments. ?>
        <div class="alert alert-light border small py-2">
            <i class="bi bi-info-circle me-1"></i>
            Booked by hand, so your opening hours, minimum notice and booking horizon
            do not apply — only a clash with an existing appointment is refused.
        </div>
        <form method="post" id="apptForm" class="row g-2 align-items-end" data-ajax>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="col-md-3">
                <label class="form-label small" for="apptService">Service</label>
                <select name="service_id" id="apptService" class="form-select form-select-sm" required>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"><?= sanitize($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="apptWhen">When (<?= sanitize($tz) ?>)</label>
                <input type="datetime-local" name="scheduled_local" id="apptWhen" class="form-control form-control-sm" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="apptCustomer">Customer</label>
                <input type="text" name="customer_name" id="apptCustomer" class="form-control form-control-sm" maxlength="120">
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="apptPhone">Phone</label>
                <input type="text" name="customer_phone" id="apptPhone" class="form-control form-control-sm" placeholder="923001234567">
            </div>
            <?php // The note the handler has always stored and the form never offered,
                  // so a booking taken over the phone had nowhere to record what it was
                  // about. It shows under the row in the table. ?>
            <div class="col-md-9">
                <label class="form-label small" for="apptNotes">Note <span class="text-muted">(internal)</span></label>
                <input type="text" name="notes" id="apptNotes" class="form-control form-control-sm" maxlength="500">
            </div>
            <div class="col-md-3">
                <?php // No data-confirm: adding a booking is additive, and a clash is
                      // refused by the server rather than argued about in a dialog. ?>
                <button class="btn btn-primary btn-sm w-100" type="submit">Add</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($movable): ?>
<?php // The same one-render-then-promote arrangement as the card above. The
      // appointment is chosen from a real <select> rather than from a hidden id,
      // which is what lets the form work on its own: a tenant with JavaScript off
      // follows the Move link to this card, picks the booking and the new time.
      // With JavaScript, the Move button on a row fills both fields in. ?>
<div class="card mt-3" data-modal-shell="apptMoveModal" data-modal-title="Move appointment">
    <div class="card-header"><i class="bi bi-arrow-left-right me-2"></i>Move an appointment</div>
    <div class="card-body">
        <div class="alert alert-light border small py-2">
            <i class="bi bi-info-circle me-1"></i>
            Moving one by hand is held to the same single rule as adding one: only a clash
            with another booking is refused. Its reminders are rescheduled with it, and the
            customer is told the old and new times on WhatsApp.
        </div>
        <form method="post" id="apptMoveForm" class="row g-2 align-items-end" data-ajax>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reschedule">
            <div class="col-md-6">
                <label class="form-label small" for="apptMoveId">Appointment</label>
                <select name="id" id="apptMoveId" class="form-select form-select-sm" required>
                    <?php foreach ($movable as $m):
                        $moveWhen = convertToUserTz($m['scheduled_at'], $tz); ?>
                        <option value="<?= (int)$m['id'] ?>">
                            <?= sanitize(date('D j M H:i', strtotime($moveWhen))) ?>
                            — <?= sanitize($m['service_name']) ?><?= $m['customer_name'] ? ' · ' . sanitize($m['customer_name']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small" for="apptMoveWhen">New time (<?= sanitize($tz) ?>)</label>
                <input type="datetime-local" name="scheduled_local" id="apptMoveWhen" class="form-control form-control-sm" required>
            </div>
            <div class="col-md-2">
                <?php // Not confirmed: a move is an edit, the old slot is not lost, and
                      // the clash check is what protects the calendar. ?>
                <button class="btn btn-primary btn-sm w-100" type="submit">Move</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
