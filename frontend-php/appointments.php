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
$tz = getUserTimezone($conn, $userId);
$config = chatbotConfig($conn, $userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('error', 'Invalid request.');
        redirect(APP_URL . '/appointments.php');
    }

    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if (in_array($action, ['completed', 'cancelled', 'no_show', 'booked'], true) && $id) {
        if (apptSetStatus($conn, $userId, $id, $action)) {
            logAudit($conn, 'appointment.status_changed', 'appointment', (string)$id, ['status' => $action]);
            flash('success', 'Appointment updated.');
        }
        redirect(APP_URL . '/appointments.php?' . http_build_query($_GET));
    }

    if ($action === 'reschedule' && $id) {
        $appt = apptById($conn, $userId, $id);
        $when = trim((string)($_POST['scheduled_local'] ?? ''));
        if ($appt && $when !== '') {
            // The tenant is moving it by hand, so availability and lead time are
            // advisory — but a clash is still a clash, and double-booking a room
            // is the one thing the calendar exists to prevent.
            try {
                $local = new DateTime($when, new DateTimeZone($tz));
                $utc = (clone $local)->setTimezone(new DateTimeZone('UTC'));
                $clash = apptConflicts($conn, $userId, $utc, (int)$appt['duration_minutes'], $id);
                if ($clash) {
                    flash('error', 'That clashes with another booking.');
                } else {
                    apptReschedule($conn, $userId, $id, $utc);
                    logAudit($conn, 'appointment.rescheduled', 'appointment', (string)$id, ['via' => 'dashboard']);
                    flash('success', 'Appointment moved.');
                }
            } catch (Exception $e) {
                flash('error', 'That date and time could not be read.');
            }
        }
        redirect(APP_URL . '/appointments.php');
    }

    if ($action === 'create') {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $services = apptServices($conn, $userId, false);
        $service = null;
        foreach ($services as $s) if ((int)$s['id'] === $serviceId) $service = $s;

        $when = trim((string)($_POST['scheduled_local'] ?? ''));
        if (!$service || $when === '') {
            flash('error', 'Pick a service and a time.');
            redirect(APP_URL . '/appointments.php');
        }
        try {
            $local = new DateTime($when, new DateTimeZone($tz));
        } catch (Exception $e) {
            flash('error', 'That date and time could not be read.');
            redirect(APP_URL . '/appointments.php');
        }
        $utc = (clone $local)->setTimezone(new DateTimeZone('UTC'));
        if (apptConflicts($conn, $userId, $utc, (int)$service['duration_minutes'])) {
            flash('error', 'That clashes with another booking.');
            redirect(APP_URL . '/appointments.php');
        }

        $phone = preg_replace('/\D+/', '', (string)($_POST['customer_phone'] ?? ''));
        $newId = apptCreate($conn, $userId, [
            'account_id' => null,
            'service_id' => (int)$service['id'],
            'service_name' => $service['name'],
            'duration_minutes' => (int)$service['duration_minutes'],
            'customer_phone' => $phone ?: null,
            'customer_name' => mb_substr(trim((string)($_POST['customer_name'] ?? '')), 0, 120) ?: null,
            // A manual booking has no chat, so it cannot be reminded over
            // WhatsApp unless a phone maps to one. Recorded honestly rather than
            // pretending a chat exists.
            'chat_id' => $phone ? $phone . '@s.whatsapp.net' : null,
            'scheduled_at' => $utc->format('Y-m-d H:i:s'),
            'notes' => mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 500) ?: null,
            'source' => 'manual',
        ]);
        logAudit($conn, 'appointment.booked', 'appointment', (string)$newId, ['via' => 'dashboard']);
        flash('success', 'Appointment added.');
        redirect(APP_URL . '/appointments.php');
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

$pageTitle = 'Appointments';
require_once __DIR__ . '/includes/header.php';
?>

<?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= sanitize($msg) ?></div><?php endif; ?>

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

<?php if (!$hasChatbot || empty($config['appointments_enabled'])): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-1"></i>
        WhatsApp booking is switched off, so nothing new will arrive here automatically.
        <?php if ($hasChatbot): ?>
            Turn it on under <a href="<?= APP_URL ?>/chatbot.php">Chatbot → Appointments</a>.
        <?php else: ?>
            It needs a plan that includes the AI chatbot.
        <?php endif; ?>
        You can still add appointments by hand below.
    </div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <?php foreach (['booked' => 'Booked', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no_show' => 'No-show', 'all' => 'All'] as $k => $v): ?>
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
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><?= count($appointments) ?> appointment<?= count($appointments) === 1 ? '' : 's' ?></span>
        <span class="text-muted small">Times shown in <?= sanitize($tz) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>When</th><th>Service</th><th>Customer</th><th>Source</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php if (!$appointments): ?>
                <tr><td colspan="6" class="text-muted small p-3">Nothing matches those filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($appointments as $a):
                $localWhen = convertToUserTz($a['scheduled_at'], $tz);
                $isPast = strtotime($a['scheduled_at'] . ' UTC') < time();
            ?>
                <tr>
                    <td>
                        <div class="small fw-500"><?= sanitize(date('D j M Y', strtotime($localWhen))) ?></div>
                        <div class="x-small text-muted"><?= sanitize(date('H:i', strtotime($localWhen))) ?>
                            · <?= (int)$a['duration_minutes'] ?> min</div>
                    </td>
                    <td class="small"><?= sanitize($a['service_name']) ?></td>
                    <td class="small">
                        <?= sanitize($a['customer_name'] ?: '—') ?>
                        <?php if ($a['customer_phone']): ?>
                            <div class="x-small text-muted">+<?= sanitize($a['customer_phone']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge bg-light text-dark"><?= sanitize($a['source']) ?></span></td>
                    <td>
                        <?php
                        $badge = ['booked' => $isPast ? 'warning' : 'primary', 'completed' => 'success',
                                  'cancelled' => 'secondary', 'no_show' => 'danger'][$a['status']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $badge ?>"><?= sanitize($a['status']) ?></span>
                    </td>
                    <td class="text-end">
                        <?php if ($a['status'] === 'booked'): ?>
                            <?php foreach ([['completed', 'Done', 'success'], ['no_show', 'No-show', 'warning'], ['cancelled', 'Cancel', 'danger']] as [$act, $label, $colour]): ?>
                                <form method="post" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="<?= $act ?>">
                                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                    <button class="btn btn-outline-<?= $colour ?> btn-sm" type="submit"><?= $label ?></button>
                                </form>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="booked">
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <button class="btn btn-outline-secondary btn-sm" type="submit">Reopen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (!empty($a['notes'])): ?>
                    <tr><td colspan="6" class="x-small text-muted pt-0">Note: <?= sanitize($a['notes']) ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-plus-circle me-2"></i>Add by hand</div>
    <div class="card-body">
        <?php if (!$services): ?>
            <p class="text-muted small mb-0">
                Define at least one service first, under <a href="<?= APP_URL ?>/chatbot.php">Chatbot → Appointments</a>.
            </p>
        <?php else: ?>
        <?php // Deliberately not held to the bot's rules. The bot must refuse a
              // slot outside opening hours, inside the minimum notice or beyond
              // the booking horizon, because a customer is asking for it. You are
              // the owner: squeezing someone in after closing is a normal thing
              // to want, so only double-booking is refused here. Saying so means
              // the looser behaviour reads as intent rather than as a missing
              // check — the rules are stated in Chatbot → Appointments. ?>
        <div class="alert alert-light border small py-2">
            <i class="bi bi-info-circle me-1"></i>
            Booked by hand, so your opening hours, minimum notice and booking horizon
            do not apply — only a clash with an existing appointment is refused.
        </div>
        <form method="post" class="row g-2 align-items-end">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="col-md-3">
                <label class="form-label small">Service</label>
                <select name="service_id" class="form-select form-select-sm" required>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"><?= sanitize($s['name']) ?> (<?= (int)$s['duration_minutes'] ?> min)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">When (<?= sanitize($tz) ?>)</label>
                <input type="datetime-local" name="scheduled_local" class="form-control form-control-sm" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Customer</label>
                <input type="text" name="customer_name" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Phone</label>
                <input type="text" name="customer_phone" class="form-control form-control-sm" placeholder="923001234567">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary btn-sm w-100" type="submit">Add</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
