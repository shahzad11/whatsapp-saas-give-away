<?php
require_once dirname(__DIR__) . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$accountId = (int)($_GET['account'] ?? 0);

$stmt = $conn->prepare("SELECT id, session_id, label, status, phone_number, push_name FROM wa_accounts WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$selectedAccount = null;
if ($accountId) {
    foreach ($accounts as $acc) {
        if ($acc['id'] === $accountId) {
            $selectedAccount = $acc;
            break;
        }
    }
}

if (!$selectedAccount && !empty($accounts)) {
    $selectedAccount = $accounts[0];
    $accountId = $selectedAccount['id'];
}

$pageTitle = 'Chats';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<?php if (empty($accounts)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="bi bi-chat-dots d-block"></i>
                <h5>No accounts linked</h5>
                <p>Link a WhatsApp account first to view chats.</p>
                <a href="<?= APP_URL ?>/whatsapp/link.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Link Account</a>
            </div>
        </div>
    </div>
<?php else: ?>

<?php if (count($accounts) > 1): ?>
<div class="mb-3">
    <select class="form-select form-select-sm" style="max-width:300px;" onchange="switchAccount(this.value)" id="accountSelect">
        <?php foreach ($accounts as $acc): ?>
            <option value="<?= $acc['id'] ?>" data-session="<?= sanitize($acc['session_id']) ?>"
                <?= $acc['id'] === $accountId ? 'selected' : '' ?>>
                <?= sanitize($acc['label'] ?: 'Unnamed') ?> (<?= sanitize($acc['phone_number'] ?: $acc['session_id']) ?>)
            </option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>

<div id="syncStatusBar" class="sync-status-bar" style="display:none;">
    <div class="sync-status-inner">
        <div class="spinner-border spinner-border-sm me-2"></div>
        <span id="syncStatusText">Syncing messages...</span>
    </div>
</div>

<div class="chat-wrapper">
    <div class="chat-sidebar">
        <div class="chat-sidebar-header">
            <div class="chat-search-wrap">
                <i class="bi bi-search"></i>
                <input type="text" placeholder="Search" id="chatSearch" onkeyup="filterChats(this.value)">
            </div>
        </div>
        <div class="chat-list" id="chatList">
            <div class="p-3 text-center text-muted small">
                <div class="spinner-border spinner-border-sm me-1"></div>Loading chats...
            </div>
        </div>
    </div>
    <div class="chat-main" id="chatMain">
        <div class="chat-empty">
            <div class="text-center">
                <i class="bi bi-chat-dots d-block"></i>
                <p>Select a chat to view messages</p>
            </div>
        </div>
    </div>
</div>

<script>
    const selectedSessionId = '<?= sanitize($selectedAccount['session_id'] ?? '') ?>';

    function switchAccount(accountId) {
        window.location.href = 'chats.php?account=' + accountId;
    }

    // Filters only the rows currently rendered, so a search inside the Archived
    // view stays inside it rather than silently matching unarchived chats.
    function filterChats(query) {
        const items = document.querySelectorAll('.chat-list-item');
        query = query.toLowerCase();
        items.forEach(item => {
            const name = item.querySelector('.chat-name')?.textContent?.toLowerCase() || '';
            const preview = item.querySelector('.chat-preview')?.textContent?.toLowerCase() || '';
            item.style.display = (name.includes(query) || preview.includes(query)) ? '' : 'none';
        });
    }

    let syncPollInterval = null;

    function checkSyncStatus(sessionId) {
        fetch(`ajax/get-status.php?session_id=${sessionId}`)
            .then(r => r.json())
            .then(data => {
                const bar = document.getElementById('syncStatusBar');
                const text = document.getElementById('syncStatusText');
                if (!bar || !data.ok) return;

                const sync = data.syncInfo;
                if (sync && sync.syncing) {
                    bar.style.display = '';
                    text.textContent = `Syncing messages... ${sync.totalChats} chats, ${sync.totalMessages} messages (batch #${sync.syncBatches})`;
                } else {
                    if (bar.style.display !== 'none') {
                        text.textContent = `Sync complete — ${sync ? sync.totalChats : 0} chats, ${sync ? sync.totalMessages : 0} messages`;
                        bar.classList.add('sync-done');
                        setTimeout(() => { bar.style.display = 'none'; bar.classList.remove('sync-done'); }, 4000);
                        loadChats(sessionId);
                    }
                    if (syncPollInterval) {
                        clearInterval(syncPollInterval);
                        syncPollInterval = null;
                    }
                }
            });
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (selectedSessionId) {
            loadChats(selectedSessionId);
            setInterval(() => loadChats(selectedSessionId), 10000);
            checkSyncStatus(selectedSessionId);
            syncPollInterval = setInterval(() => checkSyncStatus(selectedSessionId), 5000);
        }
    });
</script>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
