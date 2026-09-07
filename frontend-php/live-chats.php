<?php
// Live chats — conversations the bot has handed to a person (#16).
//
// While a row here is 'waiting' or 'claimed' the chatbot stays silent in that
// conversation; see handoffOpenForChat() in the reply path. Resolving it is what
// gives the conversation back to the bot, which is why resolving is a deliberate
// action with its own button rather than something that happens on a timer.
require_once __DIR__ . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$plan = getUserPlan($conn, $userId);
$hasHandoff = planHasFeature($plan, 'chatbot') && planHasFeature($plan, 'handoff');
$config = chatbotConfig($conn, $userId);
$tz = getUserTimezone($conn, $userId);

$self = APP_URL . '/live-chats.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    formRequireCsrf($self);

    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $handoff = $id ? handoffById($conn, $userId, $id) : null;

    // Where a plain submit goes afterwards: the same view, with the same
    // conversation open. Every handler below ends at formRespond(), which answers
    // JSON to the page's own fetch() and flashes-and-redirects here otherwise —
    // one code path, two audiences (see includes/ajax.php).
    $backTo = $self . '?' . http_build_query(array_filter(['view' => $_GET['view'] ?? null, 'open' => $id]));

    if ($handoff) {
        if ($action === 'claim') {
            // Two agents can click at the same moment; exactly one wins.
            if (handoffClaim($conn, $userId, $id, $userId)) {
                logAudit($conn, 'handoff.claimed', 'chat_handoff', (string)$id);
                formRespond(true, 'You are handling this conversation.', $backTo);
            }
            formRespond(false, 'Someone else claimed it first.', $backTo);
        }

        if ($action === 'release') {
            handoffRelease($conn, $userId, $id);
            formRespond(true, 'Put back in the queue.', $backTo);
        }

        if ($action === 'notes') {
            handoffSaveNotes($conn, $userId, $id, $_POST['notes'] ?? '');
            formRespond(true, 'Note saved.', $backTo);
        }

        if ($action === 'resolve' || $action === 'abandon') {
            $status = $action === 'resolve' ? 'resolved' : 'abandoned';
            if (handoffClose($conn, $userId, $id, $status)) {
                logAudit($conn, 'handoff.' . $status, 'chat_handoff', (string)$id);

                // Tell the customer the bot is back, if the tenant wants that.
                // Only on resolve: an abandoned conversation gets no cheery
                // "how else can I help?".
                $resume = trim((string)($config['handoff_resume_message'] ?? ''));
                if ($status === 'resolved' && $resume !== '' && $handoff['chat_id']) {
                    $account = $handoff['account_id']
                        ? handoffAccountById($conn, $userId, (int)$handoff['account_id']) : null;
                    if ($account) {
                        chatbotSendReply($conn, $userId, 't' . $userId, $account['session_id'],
                            $handoff['chat_id'], $resume);
                    }
                }
                formRespond(true, $status === 'resolved' ? 'Resolved — the bot will answer again.' : 'Marked abandoned.', $backTo);
            }
            // handoffClose() only reports false when the row was already closed
            // by someone else, which used to redirect silently.
            formRespond(false, 'That conversation was already closed.', $backTo);
        }

        if ($action === 'reply') {
            $text = trim((string)($_POST['text'] ?? ''));
            $account = $handoff['account_id'] ? handoffAccountById($conn, $userId, (int)$handoff['account_id']) : null;

            if ($text === '') {
                formRespond(false, 'Nothing to send.', $backTo, ['text' => 'Type a message first.']);
            }
            if (!$account) {
                formRespond(false, 'The WhatsApp account for this conversation is no longer linked.', $backTo);
            }

            // An agent reply is a message and is metered like every other.
            [$quotaOk, , $limit] = checkMessageQuota($conn, $userId);
            if (!$quotaOk) {
                formRespond(false, 'Monthly message limit reached (' . number_format($limit) . ').', $backTo);
            }
            if (chatbotSendReply($conn, $userId, 't' . $userId, $account['session_id'], $handoff['chat_id'], $text)) {
                // Replying implies you are handling it.
                if ($handoff['status'] === 'waiting') handoffClaim($conn, $userId, $id, $userId);
                formRespond(true, 'Sent.', $backTo);
            }
            formRespond(false, 'The message could not be sent.', $backTo);
        }
    }

    // Only reachable now for an id that is not this tenant's, or an action this
    // page does not have: every handler above exits. It has to end at
    // formRespond() too, because a fetch() cannot follow the redirect that used
    // to be here — it would read the next page's HTML as JSON and fail.
    formRespond(false, 'That conversation could not be found.', $backTo);
}

$view = $_GET['view'] ?? 'open';
$queue = handoffQueue($conn, $userId, $view);
$counts = handoffCounts($conn, $userId);
$openId = (int)($_GET['open'] ?? 0);
$open = $openId ? handoffById($conn, $userId, $openId) : null;
$openAccount = ($open && $open['account_id']) ? handoffAccountById($conn, $userId, (int)$open['account_id']) : null;

$pageTitle = 'Live chats';
require_once __DIR__ . '/includes/header.php';
?>

<?php // The queue and its actions stay available even without the feature. A
      // conversation already waiting for a person is a customer already waiting,
      // and locking the page would strand them with no way to be answered or
      // resolved. Losing the lever stops *new* handoffs arriving — the reply path
      // is where that is enforced. ?>
<?php if (!$hasHandoff): ?>
    <div class="alert alert-warning">
        <i class="bi bi-lock me-1"></i>
        Human handover is not part of your plan, so no new conversations will arrive here.
        Anything already waiting is still listed below and can be handled and resolved.
        <a href="<?= APP_URL ?>/billing.php">See plans</a>.
    </div>
<?php elseif (empty($config['handoff_enabled'])): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-1"></i>
        Handover to a person is switched off, so nothing new will appear here.
        Turn it on under <a href="<?= APP_URL ?>/chatbot.php">Chatbot → Handover</a>.
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['Waiting', $counts['waiting'] ?? 0, 'bi-hourglass-split', 'warning'],
        ['With an agent', $counts['claimed'] ?? 0, 'bi-person-check', 'primary'],
        ['Resolved', $counts['resolved'] ?? 0, 'bi-check2-circle', 'success'],
        ['Abandoned', $counts['abandoned'] ?? 0, 'bi-slash-circle', 'secondary'],
    ] as [$label, $value, $icon, $colour]): ?>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
                <i class="bi <?= $icon ?> fs-3 text-<?= $colour ?>"></i>
                <div>
                    <div class="fs-4 fw-600"><?= number_format($value) ?></div>
                    <div class="text-muted small"><?= $label ?></div>
                </div>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="btn-group btn-group-sm mb-3">
    <?php foreach (['open' => 'Open', 'resolved' => 'Resolved', 'abandoned' => 'Abandoned', 'all' => 'All'] as $k => $v): ?>
        <a class="btn btn-outline-secondary <?= $view === $k ? 'active' : '' ?>"
           href="<?= APP_URL ?>/live-chats.php?view=<?= $k ?>"><?= $v ?></a>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-lg-<?= $open ? '5' : '12' ?>">
        <div class="card">
            <div class="card-header"><?= count($queue) ?> conversation<?= count($queue) === 1 ? '' : 's' ?></div>
            <div class="list-group list-group-flush">
                <?php if (!$queue): ?>
                    <div class="p-3 text-muted small">Nobody is waiting.</div>
                <?php endif; ?>
                <?php foreach ($queue as $h): ?>
                    <div class="list-group-item <?= $openId === (int)$h['id'] ? 'bg-light' : '' ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="min-w-0">
                                <div class="fw-500 small">
                                    <?= sanitize($h['customer_name'] ?: ($h['customer_phone'] ? '+' . $h['customer_phone'] : 'Unknown contact')) ?>
                                </div>
                                <?php if ($h['topic']): ?>
                                    <div class="x-small text-muted text-truncate"><?= sanitize($h['topic']) ?></div>
                                <?php endif; ?>
                                <div class="x-small text-muted">
                                    <?= sanitize($h['reason'] ?: '') ?>
                                </div>
                            </div>
                            <div class="text-end flex-shrink-0 ms-2">
                                <?php $badge = ['waiting' => 'warning', 'claimed' => 'primary', 'resolved' => 'success', 'abandoned' => 'secondary'][$h['status']]; ?>
                                <span class="badge bg-<?= $badge ?>"><?= sanitize(handoffStatuses()[$h['status']]) ?></span>
                                <?php if ($h['status'] === 'waiting'): ?>
                                    <div class="x-small text-danger mt-1">waiting <?= handoffWaitLabel($h['requested_at']) ?></div>
                                <?php elseif ($h['agent_name']): ?>
                                    <div class="x-small text-muted mt-1"><?= sanitize($h['agent_name']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mt-2 d-flex gap-1 flex-wrap">
                            <a class="btn btn-outline-primary btn-sm"
                               href="<?= APP_URL ?>/live-chats.php?view=<?= sanitize($view) ?>&open=<?= (int)$h['id'] ?>">Open</a>
                            <?php if ($h['status'] === 'waiting'): ?>
                                <?php // Claiming changes the queue for everyone, so this one
                                      // reloads after the AJAX submit — the badge, the
                                      // counters and the buttons all move. No data-confirm:
                                      // taking a conversation destroys nothing, and it can be
                                      // put straight back with "Put back in the queue". ?>
                                <form method="post" class="d-inline" data-ajax>
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="claim">
                                    <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                                    <button class="btn btn-primary btn-sm" type="submit">Claim</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if ($open): ?>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <?= sanitize($open['customer_name'] ?: ($open['customer_phone'] ? '+' . $open['customer_phone'] : 'Unknown contact')) ?>
                    <span class="badge bg-secondary ms-1"><?= sanitize(handoffStatuses()[$open['status']]) ?></span>
                </span>
                <span class="x-small text-muted">
                    Asked <?= sanitize(convertToUserTz($open['requested_at'], $tz)) ?>
                </span>
            </div>
            <div class="card-body">
                <?php if (!$openAccount): ?>
                    <div class="alert alert-warning small">
                        The WhatsApp account for this conversation is no longer linked, so it cannot be replied to here.
                    </div>
                <?php else: ?>
                    <div id="handoffThread" class="handoff-thread mb-3">
                        <div class="text-muted small">Loading the conversation…</div>
                    </div>

                    <?php if (in_array($open['status'], ['waiting', 'claimed'], true)): ?>
                        <?php // Reloads after the AJAX submit rather than leaving the page
                              // alone. The thread below is redrawn by its own poll every
                              // eight seconds, so the sent message would appear on its own —
                              // but the box would still hold the text that was just sent,
                              // which is how the same message gets sent twice. Sending can
                              // also claim the conversation, and that changes the buttons. ?>
                        <form method="post" class="mb-3" data-ajax>
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="reply">
                            <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
                            <div class="input-group">
                                <input type="text" name="text" class="form-control" placeholder="Reply as a person…" required>
                                <button class="btn btn-primary" type="submit">Send</button>
                            </div>
                            <div class="form-text">
                                Sent from the linked WhatsApp account and counted against your monthly messages.
                                The bot stays quiet until you resolve this.
                            </div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>

                <?php // data-ajax-reload="off": nothing else on the page depends on a
                      // note, and reloading would throw away the thread the agent has
                      // scrolled through and restart its poll for no reason. ?>
                <form method="post" class="mb-3" data-ajax data-ajax-reload="off">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="notes">
                    <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
                    <label class="form-label small">Internal notes <span class="text-muted">(never sent)</span></label>
                    <textarea name="notes" class="form-control form-control-sm" rows="3"><?= sanitize($open['notes'] ?? '') ?></textarea>
                    <button class="btn btn-outline-secondary btn-sm mt-2" type="submit">Save note</button>
                </form>

                <?php if (in_array($open['status'], ['waiting', 'claimed'], true)): ?>
                    <div class="d-flex gap-2 border-top pt-3">
                        <?php // Resolving is the intended ending, not a destructive one — it
                              // is what gives the conversation back to the bot — so it gets no
                              // dialog. All three reload, because each one moves the row
                              // between the queue's views and changes the counters. ?>
                        <form method="post" data-ajax>
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="resolve">
                            <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
                            <button class="btn btn-success btn-sm" type="submit">
                                <i class="bi bi-check2 me-1"></i>Resolve &amp; hand back to the bot
                            </button>
                        </form>
                        <?php if ($open['status'] === 'claimed'): ?>
                            <form method="post" data-ajax>
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="release">
                                <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
                                <button class="btn btn-outline-secondary btn-sm" type="submit">Put back in the queue</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" data-ajax>
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="abandon">
                            <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
                            <?php // Confirmed, because it is the one ending that leaves a real
                                  // customer unanswered: the conversation is closed, the bot
                                  // starts replying again, and nothing is sent to say so. ?>
                            <button class="btn btn-outline-danger btn-sm" type="submit"
                                    data-confirm="Give up on this conversation? It is closed without a reply and the bot takes it over again.">Abandon</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($open && $openAccount): ?>
<script>
    // Reuses the chat endpoints rather than a second message pipeline, so the
    // agent sees exactly what the Chats page would show.
    const handoffSession = '<?= sanitize($openAccount['session_id']) ?>';
    const handoffChat = <?= json_encode($open['chat_id']) ?>;

    function loadHandoffThread() {
        fetch('<?= APP_URL ?>/whatsapp/ajax/get-messages.php?session_id=' + encodeURIComponent(handoffSession)
              + '&chat_id=' + encodeURIComponent(handoffChat))
            .then(r => r.json())
            .then(data => {
                const box = document.getElementById('handoffThread');
                if (!box) return;
                if (!data.ok || !(data.messages || []).length) {
                    box.innerHTML = '<div class="text-muted small">No messages yet.</div>';
                    return;
                }
                box.innerHTML = data.messages.slice(-25).map(m =>
                    '<div class="msg-row ' + (m.fromMe ? 'msg-out' : 'msg-in') + '">'
                    + '<div class="chat-bubble ' + (m.fromMe ? 'outgoing' : 'incoming') + '">'
                    + '<div class="bubble-content">' + formatMessageText(m.text || '') + '</div>'
                    + '<div class="bubble-meta"><span class="bubble-time">' + formatMsgTime(m.time) + '</span></div>'
                    + '</div></div>'
                ).join('');
                box.scrollTop = box.scrollHeight;
            });
    }

    document.addEventListener('DOMContentLoaded', () => {
        loadHandoffThread();
        setInterval(loadHandoffThread, 8000);
    });
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
