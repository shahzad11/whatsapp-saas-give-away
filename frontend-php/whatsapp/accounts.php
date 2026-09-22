<?php
require_once dirname(__DIR__) . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];

// Both handlers end at formRespond(), which answers JSON to the page's own
// fetch() and flashes-and-redirects a plain form post — one code path, two
// audiences (see includes/ajax.php).
$self = APP_URL . '/whatsapp/accounts.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    formRequireCsrf($self);

    if ($_POST['action'] === 'delete') {
        $accountId = (int)($_POST['account_id'] ?? 0);
        $stmt = $conn->prepare("SELECT session_id, provider FROM wa_accounts WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $accountId, $userId);
        $stmt->execute();
        $acc = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // A row that is not there used to redirect with no message at all, which
        // looked like a successful removal. It also cannot stay silent on the
        // JSON path: a fetch() has nothing to read and nothing to say.
        if (!$acc) {
            formRespond(false, 'That account is no longer linked to your dashboard.', $self);
        }

        // A Cloud API account has no backend session to log out of — removing
        // the row is the whole delete.
        if (($acc['provider'] ?? 'baileys') !== 'cloud') {
            callBackendApi('POST', '/api/v1/wa/sessions/' . urlencode($acc['session_id']) . '/logout');
        }
        $stmt = $conn->prepare("DELETE FROM wa_accounts WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $accountId, $userId);
        $stmt->execute();
        $stmt->close();
        logAudit($conn, 'wa_account.unlink', 'wa_account', $acc['session_id']);
        formRespond(true, 'Account removed successfully.', $self);
    }

    if ($_POST['action'] === 'sync') {
        $stmt = $conn->prepare("SELECT * FROM wa_accounts WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Counted, not assumed. The flash used to say "synced" unconditionally,
        // so with the backend down every status stayed exactly as it was while
        // the page reported success — the one case where the button matters most.
        $synced = 0;
        $failed = 0;

        foreach ($accounts as $acc) {
            // A Cloud row's "status" is whether its stored credentials still
            // pass Meta's check — there is no session directory to ask.
            if (($acc['provider'] ?? 'baileys') === 'cloud') {
                $r = waVerifyCloudAccount($conn, $acc);
                $r['ok'] ? $synced++ : $failed++;
                continue;
            }
            $resp = callBackendApi('GET', '/api/v1/wa/sessions/' . urlencode($acc['session_id']) . '/status');
            if ($resp && ($resp['ok'] ?? false)) {
                waApplyBackendStatus($conn, $userId, $acc, $resp);
                $synced++;
            } else {
                $failed++;
            }
        }

        if ($failed === 0) {
            formRespond(true, $synced === 0
                ? 'No accounts to sync.'
                : 'Account statuses synced (' . $synced . ').', $self);
        } elseif ($synced === 0) {
            formRespond(false, 'None of your accounts could be synced — the WhatsApp service is not reachable.', $self);
        }
        // Reported as a failure even though some rows were written, because that
        // is what it is: the table is only partly up to date. A failed reply does
        // not redraw the page, so the message has to say the useful thing, which
        // is to try again.
        formRespond(false, $synced . ' synced, ' . $failed . ' could not be reached. Try again shortly.', $self);
    }
}

// Connected rows that never got their identity written back ask the backend
// for it once here, so a linked phone does not render as "-".
waRefreshAccountIdentity($conn, $userId);

$stmt = $conn->prepare("SELECT id, session_id, label, status, phone_number, push_name, connected_at, created_at, provider, cloud_last_error FROM wa_accounts WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = 'WhatsApp Accounts';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <p class="text-muted mb-0 small"><?= count($accounts) ?> account(s) linked</p>
    </div>
    <div class="d-flex gap-2">
        <?php // No data-confirm: syncing only reads the backend's view of each
              // session and writes it back into the table, so the worst outcome is
              // that nothing changes. ?>
        <form method="POST" class="d-inline" data-ajax>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sync">
            <button type="submit" class="btn btn-outline-primary btn-sm" data-busy-label="Syncing…"><i class="bi bi-arrow-clockwise me-1"></i>Sync All</button>
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
            <table class="table table-stack">
                <thead>
                    <tr>
                        <th>Label</th>
                        <th>Number</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $acc): ?>
                    <tr data-session="<?= sanitize($acc['session_id']) ?>">
                        <td class="fw-500" data-label="Label">
                            <?= sanitize($acc['label'] ?: 'Unnamed') ?>
                            <?php if (($acc['provider'] ?? 'baileys') === 'cloud'): ?>
                                <span class="badge bg-info-subtle text-info-emphasis ms-1">Cloud API</span>
                            <?php else: ?>
                                <span class="badge bg-light text-dark ms-1">QR link</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Number">
                            <?php if ($acc['push_name'] || $acc['phone_number']): ?>
                                <?php if ($acc['push_name']): ?>
                                    <div class="fw-500"><?= sanitize($acc['push_name']) ?></div>
                                <?php endif; ?>
                                <?php if ($acc['phone_number']): ?>
                                    <div class="text-muted small"><?= sanitize($acc['phone_number']) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <span class="badge-status <?= waStatusClass($acc['status']) ?>"><?= sanitize(waStatusLabel($acc['status'])) ?></span>
                            <?php if (($acc['provider'] ?? 'baileys') === 'cloud' && !empty($acc['cloud_last_error'])): ?>
                                <div class="x-small text-danger"><?= sanitize($acc['cloud_last_error']) ?></div>
                            <?php endif; ?>
                            <?php if ($acc['connected_at']): ?>
                                <div class="x-small text-muted">since <?= timeAgo($acc['connected_at']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small" data-label="Created"><?= timeAgo($acc['created_at']) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <?php // An account WhatsApp logged out, or one that gave up
                                      // retrying, cannot recover on its own. Before this the
                                      // only way back was Remove + Link again, which mints a
                                      // new session id and orphans the whole chat history.
                                      // Re-link keeps the id and re-pairs the phone. ?>
                                <?php // Deliberately not data-ajax: this posts to link.php,
                                      // which answers with the pairing page. It is a
                                      // navigation to somewhere the tenant has to look at a
                                      // QR code, not an in-place change to this table. ?>
                                <?php if (($acc['provider'] ?? 'baileys') === 'cloud'): ?>
                                <?php // Cloud accounts are not re-paired by QR; "re-link" for
                                      // them means editing the stored Meta credentials. ?>
                                <a href="<?= APP_URL ?>/whatsapp/connect-cloud.php?account=<?= (int)$acc['id'] ?>"
                                   class="btn btn-sm btn-outline-secondary" title="Edit Cloud API connection">
                                    <i class="bi bi-gear"></i>
                                </a>
                                <?php elseif (waStatusNeedsRelink($acc['status'])): ?>
                                <form method="POST" action="<?= APP_URL ?>/whatsapp/link.php" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="relink">
                                    <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Re-link this account">
                                        <i class="bi bi-qr-code me-1"></i>Re-link
                                    </button>
                                </form>
                                <?php endif; ?>
                                <a href="<?= APP_URL ?>/whatsapp/chats.php?account=<?= $acc['id'] ?>"
                                   class="btn btn-sm btn-outline-primary"
                                   title="Open this account's chats"
                                   aria-label="Open chats for <?= sanitize($acc['label'] ?: 'this account') ?>">
                                    <i class="bi bi-chat-dots"></i><span class="d-none d-md-inline ms-1">Chats</span>
                                </a>
                                <form method="POST" class="d-inline" data-ajax>
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                                    <?php // data-confirm replaces the form's inline confirm().
                                          // Both together would ask twice, and forms.js falls
                                          // back to window.confirm() when Bootstrap is not
                                          // there, so the guard is not lost. Unlinking is the
                                          // one genuinely destructive action on this page: it
                                          // logs the session out and deletes the row. ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove"
                                            data-busy-label="Removing…"
                                            data-confirm="Remove this account? Its chats and messages will be deleted from this dashboard. If you only need to reconnect it, use Re-link instead.">
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
