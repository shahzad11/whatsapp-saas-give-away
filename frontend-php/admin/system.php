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
    <div class="col-md-6">
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
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <i class="bi bi-database fs-2 text-<?= $dbOk ? 'success' : 'danger' ?>"></i>
                <div>
                    <div class="fw-600">MySQL</div>
                    <div class="small text-muted"><?= $dbOk ? 'Connected — ' . sanitize($dbVersion) : 'Unavailable' ?></div>
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
                    <thead><tr><th>Tenant</th><th>Account</th><th>Status</th><th>Since</th></tr></thead>
                    <tbody>
                    <?php if (!$needsAttention): ?>
                        <tr><td colspan="4" class="text-muted small">Every linked account is connected.</td></tr>
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
                                <span class="badge bg-<?= $a['status'] === 'logged_out' || $a['status'] === 'failed' ? 'danger' : 'warning' ?>">
                                    <?= sanitize($a['status']) ?>
                                </span>
                            </td>
                            <td class="small text-muted"><?= sanitize(timeAgo($a['updated_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-body border-top">
                <p class="text-muted x-small mb-0">
                    <code>logged_out</code> and <code>failed</code> cannot recover on their own —
                    they need the tenant to rescan a QR code.
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
            <select name="action" class="form-select form-select-sm" style="width:190px">
                <option value="">All actions</option>
                <?php foreach ($actions as $a): ?>
                    <option value="<?= sanitize($a['action']) ?>" <?= $fAction === $a['action'] ? 'selected' : '' ?>>
                        <?= sanitize($a['action']) ?> (<?= (int)$a['c'] ?>)
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
                    <td class="small"><code><?= sanitize($row['action']) ?></code></td>
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
