<?php
// Tenant detail. Operational metadata and the tenant's own profile only —
// message contents are NEVER surfaced to an admin, and that boundary must
// survive every future addition to this page.
require_once dirname(__DIR__) . '/includes/admin-init.php';

$targetId = (int)($_GET['id'] ?? 0);

$stmt = $conn->prepare(
    "SELECT u.*, p.name AS plan_name, p.code AS plan_code, p.price_cents, p.billing_period
     FROM users u LEFT JOIN plans p ON u.plan_id = p.id
     WHERE u.id = ?"
);
$stmt->bind_param('i', $targetId);
$stmt->execute();
$tenant = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tenant) {
    flash('error', 'Tenant not found.');
    redirect(APP_URL . '/admin/tenants.php');
}

$profile = getUserProfile($conn, $targetId);
$plan = getUserPlan($conn, $targetId);
$timezone = getUserSetting($conn, $targetId, 'timezone', appTimezone($conn));

$stmt = $conn->prepare(
    "SELECT label, status, phone_number, push_name, connected_at, created_at
     FROM wa_accounts WHERE user_id = ? ORDER BY created_at DESC"
);
$stmt->bind_param('i', $targetId);
$stmt->execute();
$accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$usage = [
    'WhatsApp Accounts' => [countWaAccounts($conn, $targetId), planLimit($plan, 'max_wa_accounts')],
    'Contacts'          => [countContacts($conn, $targetId), planLimit($plan, 'max_contacts')],
    'Messages Sent'     => [usageCount($conn, $targetId, 'messages_sent'), planLimit($plan, 'max_messages_per_month')],
];

// Recent activity for this tenant. Actions only — never content.
$stmt = $conn->prepare(
    "SELECT action, entity, entity_id, ip_address, created_at
     FROM audit_log WHERE user_id = ? ORDER BY id DESC LIMIT 20"
);
$stmt->bind_param('i', $targetId);
$stmt->execute();
$audit = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = 'Tenant — ' . tenantDisplayName($tenant, $profile);
require_once dirname(__DIR__) . '/includes/admin-header.php';
?>

<a href="<?= APP_URL ?>/admin/tenants.php" class="btn btn-sm btn-link text-decoration-none mb-3 px-0">
    <i class="bi bi-arrow-left me-1"></i>All tenants
</a>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header">Account</div>
            <div class="card-body">
                <dl class="row small mb-0">
                    <dt class="col-5 text-muted fw-normal">Name</dt>
                    <dd class="col-7"><?= sanitize($tenant['name']) ?></dd>
                    <dt class="col-5 text-muted fw-normal">Email</dt>
                    <dd class="col-7"><?= sanitize($tenant['email']) ?></dd>
                    <dt class="col-5 text-muted fw-normal">Tenant id</dt>
                    <dd class="col-7"><code>t<?= (int)$tenant['id'] ?></code></dd>
                    <dt class="col-5 text-muted fw-normal">Status</dt>
                    <dd class="col-7">
                        <?php if (!$tenant['is_active']): ?>
                            <span class="badge bg-secondary">unactivated</span>
                        <?php elseif ($tenant['status'] === 'suspended'): ?>
                            <span class="badge bg-danger">suspended</span>
                        <?php else: ?>
                            <span class="badge bg-success">active</span>
                        <?php endif; ?>
                        <?php if ($tenant['is_admin']): ?><span class="badge bg-dark">admin</span><?php endif; ?>
                    </dd>
                    <dt class="col-5 text-muted fw-normal">Plan</dt>
                    <dd class="col-7"><?= sanitize($tenant['plan_name'] ?? '—') ?>
                        <span class="text-muted">(<?= sanitize(formatPrice($tenant)) ?>)</span></dd>
                    <dt class="col-5 text-muted fw-normal">Timezone</dt>
                    <dd class="col-7"><?= sanitize($timezone) ?> <span class="text-muted"><?= sanitize(timezoneOffsetLabel($timezone)) ?></span></dd>
                    <dt class="col-5 text-muted fw-normal">Registered</dt>
                    <dd class="col-7"><?= date('M j, Y', strtotime($tenant['created_at'])) ?></dd>
                    <dt class="col-5 text-muted fw-normal">Last login</dt>
                    <dd class="col-7"><?= $tenant['last_login_at'] ? sanitize(timeAgo($tenant['last_login_at'])) : '—' ?></dd>
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Profile</div>
            <div class="card-body">
                <?php if (!hasProfileDetail($profile)): ?>
                    <p class="text-muted small mb-0">This tenant has not filled in their profile.</p>
                <?php else: ?>
                    <dl class="row small mb-0">
                        <?php if ($profile['company_name']): ?>
                            <dt class="col-5 text-muted fw-normal">Company</dt>
                            <dd class="col-7"><?= sanitize($profile['company_name']) ?></dd>
                        <?php endif; ?>
                        <?php if ($profile['whatsapp_number']): ?>
                            <dt class="col-5 text-muted fw-normal">WhatsApp</dt>
                            <dd class="col-7"><?= sanitize(formatPhone($profile['whatsapp_number'])) ?></dd>
                        <?php endif; ?>
                        <?php $lines = addressLines($profile); ?>
                        <?php if ($lines): ?>
                            <dt class="col-5 text-muted fw-normal">Address</dt>
                            <dd class="col-7"><?= implode('<br>', array_map('sanitize', $lines)) ?></dd>
                        <?php endif; ?>
                        <dt class="col-5 text-muted fw-normal">Contact by</dt>
                        <dd class="col-7">
                            <?php
                            $prefs = array_filter([
                                (int)$profile['contact_email'] === 1 ? 'Email' : null,
                                (int)$profile['contact_whatsapp'] === 1 ? 'WhatsApp' : null,
                            ]);
                            echo $prefs ? sanitize(implode(', ', $prefs)) : '<span class="text-muted">No contact permitted</span>';
                            ?>
                        </dd>
                    </dl>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header">Usage This Month</div>
            <div class="card-body">
                <?php foreach ($usage as $label => [$used, $limit]): ?>
                    <div class="d-flex justify-content-between small mb-2">
                        <span class="fw-500"><?= sanitize($label) ?></span>
                        <span class="text-muted"><?= number_format($used) ?> / <?= sanitize(formatLimit($limit)) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card mb-4 table-card">
            <div class="card-header">WhatsApp Accounts</div>
            <?php if (!$accounts): ?>
                <div class="card-body"><p class="text-muted small mb-0">No linked accounts.</p></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Label</th><th>Number</th><th>Status</th><th>Connected</th></tr></thead>
                    <tbody>
                    <?php foreach ($accounts as $a): ?>
                        <tr>
                            <td class="small"><?= sanitize($a['label'] ?: '—') ?></td>
                            <td class="small"><?= $a['phone_number'] ? sanitize(formatPhone($a['phone_number'])) : '—' ?></td>
                            <td><span class="badge bg-<?= $a['status'] === 'connected' ? 'success' : 'secondary' ?>"><?= sanitize($a['status']) ?></span></td>
                            <td class="small text-muted"><?= $a['connected_at'] ? sanitize(timeAgo($a['connected_at'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <div class="card table-card">
            <div class="card-header">Recent Activity</div>
            <?php if (!$audit): ?>
                <div class="card-body"><p class="text-muted small mb-0">Nothing logged yet.</p></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Action</th><th>Entity</th><th>IP</th><th>When</th></tr></thead>
                    <tbody>
                    <?php foreach ($audit as $row): ?>
                        <tr>
                            <td class="small"><code><?= sanitize($row['action']) ?></code></td>
                            <td class="small text-muted"><?= sanitize(trim(($row['entity'] ?? '') . ' ' . ($row['entity_id'] ?? ''))) ?: '—' ?></td>
                            <td class="small text-muted"><?= sanitize($row['ip_address'] ?? '—') ?></td>
                            <td class="small text-muted"><?= sanitize(timeAgo($row['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
