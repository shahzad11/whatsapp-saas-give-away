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

// --- Audit log, filterable ---
$fAction = trim($_GET['action'] ?? '');
$fUser = (int)($_GET['user'] ?? 0);
$fDays = (int)($_GET['days'] ?? 7);
if ($fDays < 1 || $fDays > 365) $fDays = 7;

$sql = "SELECT a.id, a.action, a.entity, a.entity_id, a.ip_address, a.created_at, a.meta,
               u.name AS actor_name, u.email AS actor_email
        FROM audit_log a LEFT JOIN users u ON a.user_id = u.id
        WHERE a.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)";
$types = 'i';
$args = [$fDays];

if ($fAction !== '') { $sql .= ' AND a.action = ?'; $types .= 's'; $args[] = $fAction; }
if ($fUser > 0)      { $sql .= ' AND a.user_id = ?'; $types .= 'i'; $args[] = $fUser; }
$sql .= ' ORDER BY a.id DESC LIMIT 200';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$args);
$stmt->execute();
$audit = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$actions = $conn->query(
    "SELECT action, COUNT(*) AS c FROM audit_log GROUP BY action ORDER BY action"
)->fetch_all(MYSQLI_ASSOC);

$actors = $conn->query(
    "SELECT DISTINCT u.id, u.name FROM audit_log a JOIN users u ON a.user_id = u.id ORDER BY u.name"
)->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'System & Audit';
require_once dirname(__DIR__) . '/includes/admin-header.php';
?>

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
                            <strong>What to do:</strong> check <code>docker compose logs mysql --since 10m</code> on the server.
                            You are seeing this page at all, so the connection failed after login.
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
                            The scheduler is alive, so these are failing to send — usually a tenant's WhatsApp
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
            <div class="card-header">Accounts Needing Attention</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Tenant</th><th>Account</th><th>Status</th><th>Since</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$needsAttention): ?>
                        <tr><td colspan="5" class="text-muted small">Every linked account is connected.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($needsAttention as $a): ?>
                        <tr>
                            <td class="small">
                                <a href="<?= APP_URL ?>/admin/tenant.php?id=<?= (int)$a['user_id'] ?>" class="text-decoration-none">
                                    <?= sanitize($a['tenant_name']) ?>
                                </a>
                            </td>
                            <td class="small">
                                <?= sanitize($a['label'] ?: '—') ?>
                                <?php if ($a['phone_number']): ?>
                                    <span class="text-muted x-small d-block"><?= sanitize(formatPhone($a['phone_number'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge-status <?= waStatusClass($a['status']) ?>" title="<?= sanitize($a['status']) ?>">
                                    <?= sanitize(waStatusLabel($a['status'])) ?>
                                </span>
                                <?php if ($hint = waStatusHint($a['status'])): ?>
                                    <div class="x-small text-muted"><?= sanitize($hint) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= sanitize(timeAgo($a['updated_at'])) ?></td>
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
                                       href="<?= APP_URL ?>/admin/tenant.php?id=<?= (int)$a['user_id'] ?>">Tenant</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-body border-top">
                <p class="text-muted x-small mb-0">
                    <strong>What to do:</strong> "Logged out" and "Connection failed" cannot recover on their
                    own. The tenant has to open <em>WhatsApp accounts</em> and press <strong>Re-link</strong>,
                    then scan the QR code with that phone — their chats and messages are kept.
                    "Disconnected" is retried automatically and usually needs nothing.
                </p>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card table-card h-100">
            <div class="card-header">Linked Accounts per Tenant</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Tenant</th><th>Connected</th><th>Total</th></tr></thead>
                    <tbody>
                    <?php if (!$sessionCounts): ?>
                        <tr><td colspan="3" class="text-muted small">No accounts linked yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($sessionCounts as $s): ?>
                        <tr>
                            <td class="small"><a href="<?= APP_URL ?>/admin/tenant.php?id=<?= (int)$s['id'] ?>" class="text-decoration-none"><?= sanitize($s['name']) ?></a></td>
                            <td class="small"><?= (int)$s['connected'] ?></td>
                            <td class="small"><?= (int)$s['total'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card table-card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>Audit Log</span>
        <form method="GET" class="d-flex gap-2 flex-wrap">
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
        <table class="table align-middle mb-0">
            <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>IP</th><th>Detail</th></tr></thead>
            <tbody>
            <?php if (!$audit): ?>
                <tr><td colspan="6" class="text-muted small">Nothing logged in this window.</td></tr>
            <?php endif; ?>
            <?php foreach ($audit as $row): ?>
                <tr>
                    <td class="small text-muted" title="<?= sanitize($row['created_at']) ?>"><?= sanitize(timeAgo($row['created_at'])) ?></td>
                    <td class="small"><?= sanitize($row['actor_name'] ?? 'system') ?></td>
                    <?php // The identifier is what you filter and grep by, so it stays — as the
                          // tooltip. The column itself now reads as a sentence. ?>
                    <td class="small" title="<?= sanitize($row['action']) ?>"><?= sanitize(auditActionLabel($row['action'])) ?></td>
                    <td class="small text-muted"><?= sanitize(trim(($row['entity'] ?? '') . ' ' . ($row['entity_id'] ?? ''))) ?: '—' ?></td>
                    <td class="small text-muted"><?= sanitize($row['ip_address'] ?? '—') ?></td>
                    <td class="x-small text-muted" style="max-width:280px">
                        <?php // meta is action metadata (plan ids, amounts) — never message content. ?>
                        <?= $row['meta'] ? sanitize(mb_strimwidth((string)$row['meta'], 0, 120, '…')) : '' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body border-top">
        <p class="text-muted x-small mb-0">
            Showing up to 200 entries. The audit log records actions and their metadata only —
            tenant message contents are never exposed here.
        </p>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
