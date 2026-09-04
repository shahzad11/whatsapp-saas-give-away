<?php
require_once dirname(__DIR__) . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) {
        flash('error', 'Invalid request.');
        redirect(APP_URL . '/whatsapp/accounts.php');
    }

    if ($_POST['action'] === 'delete') {
        $accountId = (int)($_POST['account_id'] ?? 0);
        $stmt = $conn->prepare("SELECT session_id FROM wa_accounts WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $accountId, $userId);
        $stmt->execute();
        $acc = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($acc) {
            callBackendApi('POST', '/api/v1/wa/sessions/' . urlencode($acc['session_id']) . '/logout');
            $stmt = $conn->prepare("DELETE FROM wa_accounts WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $accountId, $userId);
            $stmt->execute();
            $stmt->close();
            logAudit($conn, 'wa_account.unlink', 'wa_account', $acc['session_id']);
            flash('success', 'Account removed successfully.');
        }
        redirect(APP_URL . '/whatsapp/accounts.php');
    }

    if ($_POST['action'] === 'sync') {
        $stmt = $conn->prepare("SELECT id, session_id FROM wa_accounts WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($accounts as $acc) {
            $resp = callBackendApi('GET', '/api/v1/wa/sessions/' . urlencode($acc['session_id']) . '/status');
            if ($resp && ($resp['ok'] ?? false)) {
                $status = $resp['status'] ?? 'disconnected';
                $phone = $resp['user']['id'] ?? null;
                $pushName = $resp['user']['name'] ?? null;
                $connAt = $resp['connectedAt'] ?? null;
                if ($connAt) {
                    $connAt = date('Y-m-d H:i:s', strtotime($connAt));
                }

                if ($phone) {
                    $phone = explode(':', $phone)[0] ?? $phone;
                }

                $stmt2 = $conn->prepare("UPDATE wa_accounts SET status = ?, phone_number = ?, push_name = ?, connected_at = ? WHERE id = ? AND user_id = ?");
                $stmt2->bind_param("ssssii", $status, $phone, $pushName, $connAt, $acc['id'], $userId);
                $stmt2->execute();
                $stmt2->close();
            }
        }
        flash('success', 'Account statuses synced.');
        redirect(APP_URL . '/whatsapp/accounts.php');
    }
}

$stmt = $conn->prepare("SELECT id, session_id, label, status, phone_number, push_name, connected_at, created_at FROM wa_accounts WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = 'WhatsApp Accounts';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= sanitize($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= sanitize($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <p class="text-muted mb-0 small"><?= count($accounts) ?> account(s) linked</p>
    </div>
    <div class="d-flex gap-2">
        <form method="POST" class="d-inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sync">
            <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-clockwise me-1"></i>Sync All</button>
        </form>
        <a href="<?= APP_URL ?>/whatsapp/link.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Link Account</a>
    </div>
</div>

<?php if (empty($accounts)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="bi bi-phone d-block"></i>
                <h5>No WhatsApp accounts linked</h5>
                <p>Link your WhatsApp accounts to manage them from this dashboard.</p>
                <a href="<?= APP_URL ?>/whatsapp/link.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Link Account</a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card table-card">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Label</th>
                        <th>Phone Number</th>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Connected</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $acc): ?>
                    <tr data-session="<?= sanitize($acc['session_id']) ?>">
                        <td class="fw-500"><?= sanitize($acc['label'] ?: 'Unnamed') ?></td>
                        <td><?= sanitize($acc['phone_number'] ?: '-') ?></td>
                        <td><?= sanitize($acc['push_name'] ?: '-') ?></td>
                        <td><span class="badge-status badge-<?= $acc['status'] ?>"><?= $acc['status'] ?></span></td>
                        <td class="text-muted small"><?= $acc['connected_at'] ? timeAgo($acc['connected_at']) : '-' ?></td>
                        <td class="text-muted small"><?= timeAgo($acc['created_at']) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= APP_URL ?>/whatsapp/chats.php?account=<?= $acc['id'] ?>" class="btn btn-sm btn-outline-primary" title="Chats">
                                    <i class="bi bi-chat-dots"></i>
                                </a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Remove this account?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
