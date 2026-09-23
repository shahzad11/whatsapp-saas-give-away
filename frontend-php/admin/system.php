<?php
// System health and the audit log viewer.
//
// The audit log records *actions*, never content. Nothing on this page may
// surface a tenant's message text — that privacy boundary has to survive
// every future addition here.
require_once dirname(__DIR__) . '/includes/admin-init.php';

// Backend liveness. /health is the only unauthenticated backend route, and this
// is a short-timeout probe: the admin page must not hang for 30s when the
// backend is down, which is exactly when someone loads this page.
function backendHealth() {
    $ch = curl_init(BACKEND_URL . '/health');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $start = microtime(true);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return [
        'ok' => $code === 200,
        'code' => $code,
        'ms' => (int)round((microtime(true) - $start) * 1000),
        'error' => $err,
        'body' => is_string($body) ? substr($body, 0, 200) : '',
    ];
}

$health = backendHealth();

// Appointment reminders (#39).
//
// The scheduler is a timer inside the Node backend poking a PHP endpoint once a
// minute, which means there is nothing to look at: no cron log, no queue UI, and
// a stack that has silently stopped reminding anybody looks exactly like a stack
// with nobody to remind. The endpoint writes a heartbeat on every completed pass
// and the queue counts its own states, so both are readable here.
//
// Three ticks of grace before it is called stale — one slow minute is a slow
// minute, not an outage.
$reminderTickAt = (string)appSetting($conn, 'reminder_tick_at', '');
$reminderTickAgo = $reminderTickAt === '' ? null : max(0, time() - strtotime($reminderTickAt . ' UTC'));
$reminderStale = $reminderTickAgo === null || $reminderTickAgo > 180;
$reminderQueue = apptReminderHealth($conn);

$dbOk = true;
$dbVersion = '';
try {
    $row = $conn->query("SELECT VERSION() AS v")->fetch_assoc();
    $dbVersion = $row['v'] ?? '';
} catch (Throwable $e) {
    $dbOk = false;
}

// Accounts needing a human: logged out or failed sessions cannot recover on
// their own — they need a QR rescan.
$needsAttention = $conn->query(
    "SELECT wa.id, wa.session_id, wa.label, wa.status, wa.phone_number, wa.updated_at,
            u.id AS user_id, u.name AS tenant_name
     FROM wa_accounts wa JOIN users u ON wa.user_id = u.id
     WHERE wa.status IN ('logged_out','failed','disconnected')
     ORDER BY wa.updated_at DESC LIMIT 50"
)->fetch_all(MYSQLI_ASSOC);

$sessionCounts = $conn->query(
    "SELECT u.id, u.name, COUNT(wa.id) AS total,
            SUM(wa.status = 'connected') AS connected
     FROM users u JOIN wa_accounts wa ON wa.user_id = u.id
     GROUP BY u.id, u.name ORDER BY total DESC LIMIT 20"
)->fetch_all(MYSQLI_ASSOC);

$self = APP_URL . '/admin/system.php';

// --- Audit log, filterable ---
$fAction = trim($_GET['action'] ?? '');
$fUser = (int)($_GET['user'] ?? 0);
$fDays = (int)($_GET['days'] ?? 7);
if ($fDays < 1 || $fDays > 365) $fDays = 7;

$where = "FROM audit_log a LEFT JOIN users u ON a.user_id = u.id
          WHERE a.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)";
$types = 'i';
$args = [$fDays];

if ($fAction !== '') { $where .= ' AND a.action = ?'; $types .= 's'; $args[] = $fAction; }
if ($fUser > 0)      { $where .= ' AND a.user_id = ?'; $types .= 'i'; $args[] = $fUser; }

// Paged at 50: the old LIMIT 200 made the log the whole page, which is why the
// health cards above it sat nine thousand pixels down on a phone. The count
// runs the same WHERE so a filtered page number cannot lie about the total.
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$stmt = $conn->prepare("SELECT COUNT(*) " . $where);
$stmt->bind_param($types, ...$args);
$stmt->execute();
$auditTotal = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
$stmt->close();
$totalPages = max(1, (int)ceil($auditTotal / $perPage));
if ($page > $totalPages) $page = $totalPages;

$stmt = $conn->prepare(
    "SELECT a.id, a.action, a.entity, a.entity_id, a.ip_address, a.created_at, a.meta,
            u.name AS actor_name, u.email AS actor_email
     " . $where . " ORDER BY a.id DESC LIMIT ? OFFSET ?"
);
$stmt->bind_param($types . 'ii', ...[...$args, $perPage, ($page - 1) * $perPage]);
$stmt->execute();
$audit = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$actions = $conn->query(
    "SELECT action, COUNT(*) AS c FROM audit_log GROUP BY action ORDER BY action"
)->fetch_all(MYSQLI_ASSOC);

$actors = $conn->query(
    "SELECT DISTINCT u.id, u.name FROM audit_log a JOIN users u ON a.user_id = u.id ORDER BY u.name"
)->fetch_all(MYSQLI_ASSOC);

// The page is two jobs — "is it up" and "what happened" — that used to stack
// into one nine-thousand-pixel scroll. They are tabs now. The audit tab is the
// default whenever the URL carries one of its own parameters, so a filter
// submit or a shared link opens on the log, not on the health cards.
$auditTab = isset($_GET['action']) || isset($_GET['user']) || isset($_GET['days']) || isset($_GET['page']);

$pageTitle = 'System & Audit';
require_once dirname(__DIR__) . '/includes/admin-header.php';
?>

<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
        <button class="nav-link<?= $auditTab ? '' : ' active' ?>" id="tab-health-btn" type="button"
                role="tab" aria-selected="<?= $auditTab ? 'false' : 'true' ?>" aria-controls="tab-health"
                data-bs-toggle="tab" data-bs-target="#tab-health">Health</button>
    </li>
    <li class="nav-item">
        <button class="nav-link<?= $auditTab ? ' active' : '' ?>" id="tab-audit-btn" type="button"
                role="tab" aria-selected="<?= $auditTab ? 'true' : 'false' ?>" aria-controls="tab-audit"
                data-bs-toggle="tab" data-bs-target="#tab-audit">Audit log</button>
    </li>
</ul>

<div class="tab-content">
<div class="tab-pane fade<?= $auditTab ? '' : ' show active' ?>" id="tab-health"
     role="tabpanel" aria-labelledby="tab-health-btn" tabindex="0">

<div class="row g-3 mb-4">
    <div class="col-lg-4 col-md-6">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <i class="bi bi-hdd-network fs-2 text-<?= $health['ok'] ? 'success' : 'danger' ?>"></i>
                <div>
                    <div class="fw-600">Node backend</div>
                    <div class="small text-muted">
                        <?php if ($health['ok']): ?>
                            Healthy — HTTP <?= (int)$health['code'] ?> in <?= (int)$health['ms'] ?> ms
                        <?php else: ?>
                            Unreachable<?= $health['code'] ? ' — HTTP ' . (int)$health['code'] : '' ?>
                            <?= $health['error'] ? '(' . sanitize($health['error']) . ')' : '' ?>
                        <?php endif; ?>
                    </div>
                    <div class="x-small text-muted"><?= sanitize(BACKEND_URL) ?>/health</div>
                    <?php // A red cross and a curl error is a diagnosis with no prescription. The
                          // backend is a container in this stack, so the next step is always the
                          // same two commands, and no tenant WhatsApp session works until it runs. ?>
                    <?php if (!$health['ok']): ?>
                        <div class="x-small text-danger mt-1">
                            <strong>What to do:</strong> no WhatsApp account can send or receive while this is down.
                            On the server, run <code>docker compose ps</code> and <code>docker compose logs backend --since 10m</code>
                            in the deployment directory; <code>docker compose up -d backend</code> restarts it.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4 col-md-6">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <i class="bi bi-database fs-2 text-<?= $dbOk ? 'success' : 'danger' ?>"></i>
                <div>
                    <div class="fw-600">MySQL</div>
                    <div class="small text-muted"><?= $dbOk ? 'Connected — ' . sanitize($dbVersion) : 'Unavailable' ?></div>
                    <?php if (!$dbOk): ?>
                        <div class="x-small text-danger mt-1">
                            The initial connection succeeded, but a later database check failed.
                            Review the MySQL container logs.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4 col-md-6">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <?php // Amber, not red, when the queue is behind: reminders being late is a
                      // different problem from the scheduler being dead, and they need
                      // different answers. ?>
                <?php $reminderClass = $reminderStale ? 'danger' : (($reminderQueue['overdue'] ?? 0) > 0 ? 'warning' : 'success'); ?>
                <i class="bi bi-alarm fs-2 text-<?= $reminderClass ?>"></i>
                <div>
                    <div class="fw-600">Appointment reminders</div>
                    <div class="small text-muted">
                        <?php if ($reminderTickAt === ''): ?>
                            Never run — no completed pass has been recorded
                        <?php elseif ($reminderStale): ?>
                            Last pass <?= sanitize(timeAgo($reminderTickAt)) ?> — expected every minute
                        <?php else: ?>
                            Running — last pass <?= (int)$reminderTickAgo ?>s ago
                        <?php endif; ?>
                    </div>
                    <div class="x-small text-muted">
                        <?= (int)($reminderQueue['pending'] ?? 0) ?> waiting ·
                        <?= (int)($reminderQueue['sent_24h'] ?? 0) ?> sent in 24h
                        <?php if (($reminderQueue['sending'] ?? 0) > 0): ?>
                            · <?= (int)$reminderQueue['sending'] ?> in flight
                        <?php endif; ?>
                    </div>
                    <?php if (($reminderQueue['missed_24h'] ?? 0) > 0 || ($reminderQueue['failed_24h'] ?? 0) > 0): ?>
                        <div class="x-small text-warning mt-1">
                            <?= (int)$reminderQueue['missed_24h'] ?> missed and
                            <?= (int)$reminderQueue['failed_24h'] ?> failed in the last 24h.
                        </div>
                    <?php endif; ?>
                    <?php // The failure this card exists for. The timer lives in the backend
                          // process, so a backend that is up but was started without
                          // BACKEND_API_KEY, or that cannot reach the frontend container by
                          // name, leaves every reminder queued and nothing anywhere says so. ?>
                    <?php if ($reminderStale): ?>
                        <div class="x-small text-danger mt-1">
                            <strong>What to do:</strong> the timer runs inside the Node backend. Check
                            <code>docker compose logs backend --since 10m | grep -i reminder</code> on the server —
                            a key mismatch or an unreachable frontend is logged there. Queued reminders are not
                            lost while it is down, but any whose moment passes are recorded as missed.
                        </div>
                    <?php elseif (($reminderQueue['overdue'] ?? 0) > 0): ?>
                        <div class="x-small text-warning mt-1">
                            <strong><?= (int)$reminderQueue['overdue'] ?> more than 15 minutes overdue.</strong>
                            The scheduler is alive, so these are failing to send — usually a customer's WhatsApp
                            account needing a re-link, or a monthly message allowance already spent.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card table-card h-100">
            <div class="card-header">Accounts needing attention</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 table-stack">
                    <thead><tr><th>Customer</th><th>Account</th><th>Status</th><th>Since</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$needsAttention): ?>
                        <tr><td colspan="5" class="text-muted small">Every linked account is connected.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($needsAttention as $a): ?>
                        <tr>
                            <td class="small" data-label="Customer">
                                <a href="<?= APP_URL ?>/admin/tenant.php?id=<?= (int)$a['user_id'] ?>" class="text-decoration-none">
                                    <?= sanitize($a['tenant_name']) ?>
                                </a>
                            </td>
                            <td class="small cell-block" data-label="Account">
                                <?= sanitize($a['label'] ?: '—') ?>
                                <?php if ($a['phone_number']): ?>
                                    <span class="text-muted x-small d-block"><?= sanitize(formatPhone($a['phone_number'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Status">
                                <span class="badge-status <?= waStatusClass($a['status']) ?>" title="<?= sanitize($a['status']) ?>">
                                    <?= sanitize(waStatusLabel($a['status'])) ?>
                                </span>
                                <?php if ($hint = waStatusHint($a['status'])): ?>
                                    <div class="x-small text-muted"><?= sanitize($hint) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted" data-label="Since"><?= sanitize(timeAgo($a['updated_at'])) ?></td>
                            <td class="text-end">
                                <?php // Re-linking means scanning a QR code with the phone that owns
                                      // the number, so an admin genuinely cannot do it for someone
                                      // else — offering them a button would be a lie. The action is
                                      // offered only for the admin's own accounts (on a fresh
                                      // instance the admin usually *is* the first tenant), and the
                                      // rest get the tenant page, which is where you contact them. ?>
                                <?php if ((int)$a['user_id'] === (int)($_SESSION['user_id'] ?? 0) && waStatusNeedsRelink($a['status'])): ?>
                                    <form method="POST" action="<?= APP_URL ?>/whatsapp/link.php" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="relink">
                                        <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                                        <button class="btn btn-sm btn-outline-warning" type="submit">Re-link</button>
                                    </form>
                                <?php else: ?>
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?= APP_URL ?>/admin/tenant.php?id=<?= (int)$a['user_id'] ?>">Customer</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-body border-top">
                <p class="text-muted x-small mb-0">
                    “Logged out” and “Connection failed” require the customer to relink the account
                    and scan a new QR code. “Disconnected” accounts are retried automatically;
                    investigate only if the status does not recover.
                </p>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card table-card h-100">
            <div class="card-header">Linked accounts by customer</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 table-stack">
                    <thead><tr><th>Customer</th><th>Connected</th><th>Total</th></tr></thead>
                    <tbody>
                    <?php if (!$sessionCounts): ?>
                        <tr><td colspan="3" class="text-muted small">No accounts linked yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($sessionCounts as $s): ?>
                        <tr>
                            <td class="small" data-label="Customer"><a href="<?= APP_URL ?>/admin/tenant.php?id=<?= (int)$s['id'] ?>" class="text-decoration-none"><?= sanitize($s['name']) ?></a></td>
                            <td class="small" data-label="Connected"><?= (int)$s['connected'] ?></td>
                            <td class="small" data-label="Total"><?= (int)$s['total'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</div><?php // /#tab-health ?>
<div class="tab-pane fade<?= $auditTab ? ' show active' : '' ?>" id="tab-audit"
     role="tabpanel" aria-labelledby="tab-audit-btn" tabindex="0">

<div class="card table-card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>Audit Log</span>
        <?php // The fragment in the action keeps a filter submit on this tab;
              // where a browser drops it, stickyTabs() remembering the active
              // tab covers the gap. ?>
        <form method="GET" action="<?= APP_URL ?>/admin/system.php#tab-audit" class="d-flex gap-2 flex-wrap">
            <select name="action" class="form-select form-select-sm" style="width:240px">
                <option value="">All actions</option>
                <?php foreach ($actions as $a): ?>
                    <option value="<?= sanitize($a['action']) ?>" <?= $fAction === $a['action'] ? 'selected' : '' ?>
                            title="<?= sanitize($a['action']) ?>">
                        <?= sanitize(auditActionLabel($a['action'])) ?> (<?= (int)$a['c'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="user" class="form-select form-select-sm" style="width:170px">
                <option value="0">All actors</option>
                <?php foreach ($actors as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= $fUser === (int)$a['id'] ? 'selected' : '' ?>><?= sanitize($a['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="days" class="form-select form-select-sm" style="width:130px">
                <?php foreach ([1 => 'Last 24h', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last year'] as $d => $l): ?>
                    <option value="<?= $d ?>" <?= $fDays === $d ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>IP</th><th>Detail</th></tr></thead>
            <tbody>
            <?php if (!$audit): ?>
                <tr><td colspan="6" class="text-muted small">Nothing logged in this window.</td></tr>
            <?php endif; ?>
            <?php foreach ($audit as $row): ?>
                <tr>
                    <td class="small text-muted" data-label="When" title="<?= sanitize($row['created_at']) ?>"><?= sanitize(timeAgo($row['created_at'])) ?></td>
                    <td class="small" data-label="Actor"><?= sanitize($row['actor_name'] ?? 'system') ?></td>
                    <?php // The identifier is what you filter and grep by, so it stays — as the
                          // tooltip. The column itself now reads as a sentence. ?>
                    <td class="small" data-label="Action" title="<?= sanitize($row['action']) ?>"><?= sanitize(auditActionLabel($row['action'])) ?></td>
                    <td class="small text-muted" data-label="Entity"><?= sanitize(trim(($row['entity'] ?? '') . ' ' . ($row['entity_id'] ?? ''))) ?: '—' ?></td>
                    <td class="small text-muted" data-label="IP"><?= sanitize($row['ip_address'] ?? '—') ?></td>
                    <td class="x-small text-muted" data-label="Detail" style="max-width:280px">
                        <?php // meta is action metadata (plan ids, amounts) — never message content. ?>
                        <?= $row['meta'] ? sanitize(mb_strimwidth((string)$row['meta'], 0, 120, '…')) : '' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <?php // Same Prev/Next as leads.php, carrying the filters forward so a page
          // change cannot silently drop the action/actor/window being read. ?>
    <nav class="card-body border-top d-flex justify-content-between align-items-center" aria-label="Audit log pages">
        <?php $auditQuery = ['action' => $fAction, 'user' => $fUser, 'days' => $fDays]; ?>
        <?php if ($page > 1): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= $self ?>?<?= sanitize(http_build_query($auditQuery + ['page' => $page - 1])) ?>#tab-audit">&laquo; Previous</a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
        <span class="text-muted small">Page <?= (int)$page ?> of <?= number_format($totalPages) ?></span>
        <?php if ($page < $totalPages): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= $self ?>?<?= sanitize(http_build_query($auditQuery + ['page' => $page + 1])) ?>#tab-audit">Next &raquo;</a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
    <div class="card-body border-top">
        <p class="text-muted x-small mb-0">
            Showing page <?= (int)$page ?> of <?= number_format($totalPages) ?> — <?= (int)$perPage ?> entries
            per page, <?= number_format($auditTotal) ?> matching. The audit log records actions and their
            metadata only — customer message contents are never exposed here.
        </p>
    </div>
</div>

</div><?php // /#tab-audit ?>
</div><?php // /.tab-content ?>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
