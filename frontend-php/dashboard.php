<?php
require_once __DIR__ . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];

$totalAccounts = 0;
$connectedAccounts = 0;
$disconnectedAccounts = 0;

$stmt = $conn->prepare("SELECT status, COUNT(*) as cnt FROM wa_accounts WHERE user_id = ? GROUP BY status");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $totalAccounts += $row['cnt'];
    if ($row['status'] === 'connected') $connectedAccounts = $row['cnt'];
    if (in_array($row['status'], ['disconnected', 'logged_out'])) $disconnectedAccounts += $row['cnt'];
}
$stmt->close();

$stmt = $conn->prepare("SELECT id, session_id, label, status, phone_number, push_name, connected_at, created_at FROM wa_accounts WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->bind_param("i", $userId);
$stmt->execute();
$recentAccounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= sanitize($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= sanitize($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Total Accounts</div>
                    <div class="stat-value"><?= $totalAccounts ?></div>
                </div>
                <div class="stat-icon" style="background:var(--primary-light);color:var(--primary);">
                    <i class="bi bi-phone"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Connected</div>
                    <div class="stat-value"><?= $connectedAccounts ?></div>
                </div>
                <div class="stat-icon" style="background:var(--success-light);color:var(--success);">
                    <i class="bi bi-check-circle"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Disconnected</div>
                    <div class="stat-value"><?= $disconnectedAccounts ?></div>
                </div>
                <div class="stat-icon" style="background:var(--danger-light);color:var(--danger);">
                    <i class="bi bi-x-circle"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Quick Actions</div>
                    <a href="<?= APP_URL ?>/whatsapp/link.php" class="btn btn-sm btn-primary mt-2">
                        <i class="bi bi-plus-lg me-1"></i>Link Account
                    </a>
                </div>
                <div class="stat-icon" style="background:var(--warning-light);color:var(--warning);">
                    <i class="bi bi-lightning"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Recent WhatsApp Accounts</span>
        <a href="<?= APP_URL ?>/whatsapp/accounts.php" class="btn btn-sm btn-outline-primary">View All</a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recentAccounts)): ?>
            <div class="empty-state">
                <i class="bi bi-phone d-block"></i>
                <h5>No accounts yet</h5>
                <p>Link your first WhatsApp account to get started.</p>
                <a href="<?= APP_URL ?>/whatsapp/link.php" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i>Link Account
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive table-card">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Connected</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAccounts as $acc): ?>
                        <tr>
                            <td class="fw-500"><?= sanitize($acc['label'] ?: 'Unnamed') ?></td>
                            <td><?= sanitize($acc['phone_number'] ?: '-') ?></td>
                            <td><span class="badge-status badge-<?= $acc['status'] ?>"><?= $acc['status'] ?></span></td>
                            <td class="text-muted small"><?= $acc['connected_at'] ? timeAgo($acc['connected_at']) : '-' ?></td>
                            <td>
                                <a href="<?= APP_URL ?>/whatsapp/chats.php?account=<?= $acc['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-chat-dots"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
