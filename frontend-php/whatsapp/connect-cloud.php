<?php
require_once dirname(__DIR__) . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$plan = getUserPlan($conn, $userId);
$error = '';

// Two modes on one page: ?account=ID edits an existing Cloud row, no
// parameter creates one. The QR equivalent of "edit" does not exist —
// re-pairing there means a new QR scan, whereas a Cloud account is repaired
// by replacing its stored credentials.
$account = null;
$accountId = (int)($_GET['account'] ?? 0);
if ($accountId > 0) {
    $stmt = $conn->prepare("SELECT * FROM wa_accounts WHERE id = ? AND user_id = ? AND provider = 'cloud'");
    $stmt->bind_param('ii', $accountId, $userId);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$account) {
        flash('error', 'That Cloud API account could not be found.');
        redirect(APP_URL . '/whatsapp/accounts.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Invalid request.';
    } elseif (($_POST['action'] ?? '') === 'rotate_verify' && $account) {
        // A new verify token for the Meta handshake — the old one may have
        // been pasted somewhere it should not have been.
        $stmt = $conn->prepare("UPDATE wa_accounts SET cloud_verify_token = ? WHERE id = ? AND user_id = ?");
        $newToken = cloudNewKey();
        $stmt->bind_param('sii', $newToken, $account['id'], $userId);
        $stmt->execute();
        $stmt->close();
        logAudit($conn, 'wa_account.cloud_rotate_verify', 'wa_account', $account['session_id']);
        redirect(APP_URL . '/whatsapp/connect-cloud.php?account=' . (int)$account['id'] . '&saved=1');
    } elseif (planHasFeature($plan, 'cloud_api')) {
        $label = trim($_POST['label'] ?? '');
        $pnid = preg_replace('/\D+/', '', (string)($_POST['cloud_phone_number_id'] ?? ''));
        $waba = preg_replace('/\D+/', '', (string)($_POST['cloud_waba_id'] ?? ''));
        // Optional column: an empty string would store '' rather than NULL.
        $wabaDb = $waba !== '' ? $waba : null;
        $token = trim((string)($_POST['access_token'] ?? ''));
        $secret = trim((string)($_POST['app_secret'] ?? ''));

        if (strlen($label) > 100) $label = substr($label, 0, 100);

        if (strlen($pnid) < 5 || strlen($pnid) > 32) {
            $error = 'The phone number id is the numeric id from your Meta app (5–32 digits).';
        } elseif (!$account && $token === '') {
            $error = 'The access token is required.';
        } elseif (!$account && $secret === '') {
            $error = 'The app secret is required.';
        } elseif ($secret !== '' && !preg_match('/^[\x21-\x7e]{16,128}$/', $secret)) {
            $error = 'The app secret should be the 32-character hex string from your Meta app.';
        }

        if ($error === '') {
            $tokenEnc = $token !== '' ? encryptSecret($token, WA_CLOUD_CONTEXT) : null;
            $secretEnc = $secret !== '' ? encryptSecret($secret, WA_CLOUD_CONTEXT) : null;
            if (($token !== '' && $tokenEnc === null) || ($secret !== '' && $secretEnc === null)) {
                $error = cryptoSecretMissingMessage();
            }
        }

        // Verify against Meta *before* writing anything: storing credentials
        // Meta rejects would leave a "connected" account that can neither
        // send nor receive.
        if ($error === '') {
            $checkToken = $token !== '' ? $token : null;
            if ($checkToken === null) {
                $checkToken = decryptSecret($account['cloud_access_token_encrypted'] ?? null, WA_CLOUD_CONTEXT);
            }
            if ($checkToken === null) {
                $error = 'The stored access token can no longer be read — re-enter it.';
            } else {
                $checkPnid = $pnid !== '' ? $pnid : (string)$account['cloud_phone_number_id'];
                $v = cloudVerifyCredentials($checkPnid, $checkToken);
                if (!$v['ok']) {
                    $error = 'Meta rejected these credentials: ' . $v['error'];
                }
            }
        }

        if ($error === '') {
            if ($account) {
                // Edit: blank token/secret keep the stored ones.
                try {
                    if ($tokenEnc !== null && $secretEnc !== null) {
                        $stmt = $conn->prepare("UPDATE wa_accounts SET label = ?, cloud_phone_number_id = ?, cloud_waba_id = ?,
                            cloud_access_token_encrypted = ?, cloud_app_secret_encrypted = ?,
                            status = 'connected', phone_number = ?, push_name = ?, cloud_last_error = NULL
                            WHERE id = ? AND user_id = ?");
                        $stmt->bind_param('sssssssii', $label, $pnid, $wabaDb, $tokenEnc, $secretEnc, $v['phone'], $v['name'], $account['id'], $userId);
                    } elseif ($tokenEnc !== null) {
                        $stmt = $conn->prepare("UPDATE wa_accounts SET label = ?, cloud_phone_number_id = ?, cloud_waba_id = ?,
                            cloud_access_token_encrypted = ?,
                            status = 'connected', phone_number = ?, push_name = ?, cloud_last_error = NULL
                            WHERE id = ? AND user_id = ?");
                        $stmt->bind_param('ssssssii', $label, $pnid, $wabaDb, $tokenEnc, $v['phone'], $v['name'], $account['id'], $userId);
                    } elseif ($secretEnc !== null) {
                        $stmt = $conn->prepare("UPDATE wa_accounts SET label = ?, cloud_phone_number_id = ?, cloud_waba_id = ?,
                            cloud_app_secret_encrypted = ?,
                            status = 'connected', phone_number = ?, push_name = ?, cloud_last_error = NULL
                            WHERE id = ? AND user_id = ?");
                        $stmt->bind_param('ssssssii', $label, $pnid, $wabaDb, $secretEnc, $v['phone'], $v['name'], $account['id'], $userId);
                    } else {
                        $stmt = $conn->prepare("UPDATE wa_accounts SET label = ?, cloud_phone_number_id = ?, cloud_waba_id = ?,
                            status = 'connected', phone_number = ?, push_name = ?, cloud_last_error = NULL
                            WHERE id = ? AND user_id = ?");
                        $stmt->bind_param('sssssii', $label, $pnid, $wabaDb, $v['phone'], $v['name'], $account['id'], $userId);
                    }
                    $stmt->execute();
                    $stmt->close();
                    logAudit($conn, 'wa_account.cloud_update', 'wa_account', $account['session_id']);
                    redirect(APP_URL . '/whatsapp/connect-cloud.php?account=' . (int)$account['id'] . '&saved=1');
                } catch (mysqli_sql_exception $e) {
                    // uniq_wa_cloud_pnid: the edit pointed this account at a
                    // phone number id another row already owns.
                    $error = 'That phone number id is already connected.';
                }
            } else {
                // Deliberately quota-checked here rather than only at page
                // render: the form could have been opened while under the
                // limit and submitted after hitting it. The edit path above
                // is not checked, for the same reason re-link is not — an
                // existing account is already counted.
                [$quotaOk, , $quotaLimit] = checkWaAccountQuota($conn, $userId);
                if (!$quotaOk) {
                    $error = 'Your ' . htmlspecialchars($plan['name'] ?? 'current') . ' plan allows '
                           . formatLimit($quotaLimit) . ' WhatsApp account(s). Upgrade to connect more.';
                } else {
                    $sessionId = cloudNewSessionId();
                    $webhookKey = cloudNewKey();
                    $verifyToken = cloudNewKey();
                    try {
                        $stmt = $conn->prepare("INSERT INTO wa_accounts
                            (user_id, session_id, label, status, phone_number, push_name, connected_at,
                             provider, cloud_phone_number_id, cloud_waba_id,
                             cloud_access_token_encrypted, cloud_app_secret_encrypted,
                             cloud_webhook_key, cloud_verify_token)
                            VALUES (?, ?, ?, 'connected', ?, ?, UTC_TIMESTAMP(), 'cloud', ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param('issssssssss', $userId, $sessionId, $label, $v['phone'], $v['name'],
                            $pnid, $wabaDb, $tokenEnc, $secretEnc, $webhookKey, $verifyToken);
                        $stmt->execute();
                        $newId = $conn->insert_id;
                        $stmt->close();
                        logAudit($conn, 'wa_account.cloud_connect', 'wa_account', $sessionId, ['label' => $label]);
                        redirect(APP_URL . '/whatsapp/connect-cloud.php?account=' . (int)$newId . '&saved=1');
                    } catch (mysqli_sql_exception $e) {
                        // uniq_wa_cloud_pnid: one phone number id, one account.
                        $error = 'That phone number id is already connected.';
                    }
                }
            }
        }
    }
}

$pageTitle = $account ? 'Cloud API Connection' : 'Connect Cloud API';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= sanitize($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        Saved.<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!planHasFeature($plan, 'cloud_api')): ?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Connect an official Cloud API number</div>
            <div class="card-body">
                <div class="alert alert-warning mb-0">
                    Your plan does not include the Meta Cloud API connection.
                    <a href="<?= APP_URL ?>/billing.php" class="alert-link">Upgrade your plan</a> to use it.
                </div>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><?= $account ? 'Edit Cloud API connection' : 'Connect an official Cloud API number' ?></div>
            <div class="card-body">
                <?php // autocomplete="off" on the form plus "new-password" on the
                      // secret fields: browsers otherwise fill the login email into
                      // the first text field and a saved password into the token. ?>
                <form method="POST" autocomplete="off">
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label">Account Label</label>
                        <input type="text" name="label" class="form-control" maxlength="100" autocomplete="off"
                               placeholder="e.g. Main shop line"
                               value="<?= sanitize($_POST['label'] ?? ($account['label'] ?? '')) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone number id</label>
                        <input type="text" name="cloud_phone_number_id" class="form-control" required
                               inputmode="numeric" autocomplete="off" placeholder="e.g. 123456789012345"
                               value="<?= sanitize($_POST['cloud_phone_number_id'] ?? ($account['cloud_phone_number_id'] ?? '')) ?>">
                        <div class="form-text">The numeric id shown under the “From” number on Meta’s API Setup page — not the phone number itself. See step 3 on the right.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">WhatsApp Business Account id <span class="text-muted">(optional)</span></label>
                        <input type="text" name="cloud_waba_id" class="form-control" inputmode="numeric" autocomplete="off"
                               value="<?= sanitize($_POST['cloud_waba_id'] ?? ($account['cloud_waba_id'] ?? '')) ?>">
                        <div class="form-text">Shown at the top of the API Setup page. See step 2.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Access token <?= $account ? '<span class="text-muted">(leave blank to keep the stored one)</span>' : '' ?></label>
                        <input type="password" name="access_token" class="form-control" autocomplete="new-password" <?= $account ? '' : 'required' ?>>
                        <div class="form-text">A permanent System User token (step 5), not the temporary one from API Setup. Stored encrypted and never shown again.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">App secret <?= $account ? '<span class="text-muted">(leave blank to keep the stored one)</span>' : '' ?></label>
                        <input type="password" name="app_secret" class="form-control" autocomplete="new-password" <?= $account ? '' : 'required' ?>>
                        <div class="form-text">From App settings → Basic (step 4). Verifies that webhook deliveries really come from Meta. Also stored encrypted and never shown again.</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-cloud me-2"></i><?= $account ? 'Save and verify' : 'Verify and connect' ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
    <?php if ($account): ?>
        <div class="card">
            <div class="card-header">Step 6 · Webhook settings for your Meta app</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Callback URL</label>
                    <div class="input-group">
                        <input type="text" class="form-control" readonly
                               value="<?= sanitize(APP_URL . '/webhooks/meta.php?key=' . $account['cloud_webhook_key']) ?>"
                               id="cbUrl">
                        <button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('cbUrl').value)">Copy</button>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Verify token</label>
                    <div class="input-group">
                        <input type="text" class="form-control" readonly
                               value="<?= sanitize($account['cloud_verify_token']) ?>" id="vToken">
                        <button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('vToken').value)">Copy</button>
                    </div>
                </div>
                <form method="POST" class="mb-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="rotate_verify">
                    <button type="submit" class="btn btn-sm btn-outline-warning">Regenerate verify token</button>
                </form>
                <h6 class="fw-600">In the Meta dashboard:</h6>
                <ol class="small text-muted mb-3">
                    <li>Open <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">Meta for Developers</a> → your app → <strong>Use cases → Customize → Configuration</strong> (older apps: <strong>WhatsApp → Configuration</strong>).</li>
                    <li>Under <strong>Webhook</strong> click <strong>Edit</strong>, paste the Callback URL and Verify token above, then click <strong>Verify and save</strong>.</li>
                    <li>Click <strong>Manage</strong> next to Webhook fields and subscribe to <code>messages</code>.</li>
                    <li>Send a WhatsApp message from an allowed number to this business number — it appears under <a href="<?= APP_URL ?>/whatsapp/chats.php">Chats</a> and the bot replies.</li>
                </ol>
                <h6 class="fw-600">Good to know</h6>
                <ul class="small text-muted mb-0">
                    <li>While the Meta app is in <strong>development mode</strong>, only the numbers on its “To” allowed list (max 5) can be messaged.</li>
                    <li>Meta only allows free-form replies within <strong>24 hours</strong> of the customer’s last message; proactive messages outside that window need an approved template.</li>
                    <li>If you reset the app secret or the token in Meta, paste the new value in the form on the left — the stored ones are never shown again.</li>
                </ul>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-header">Where to find these in Meta</div>
            <div class="card-body">
                <ol class="small text-muted mb-3 ps-3">
                    <li class="mb-2">
                        <strong>Create the app.</strong> Go to <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">developers.facebook.com/apps</a> → <strong>Create App</strong> → name + email → use case <strong>Connect with customers through WhatsApp</strong> → pick or create a Business portfolio → <strong>Create app</strong>.
                    </li>
                    <li class="mb-2">
                        <strong>WhatsApp Business Account id.</strong> On the Quickstart page click <strong>Start using the API</strong>. On the <strong>API Setup</strong> page connect (or create) a WhatsApp Business account — its id is shown at the top. Optional here.
                    </li>
                    <li class="mb-2">
                        <strong>Phone number id.</strong> Same API Setup page: under <strong>From</strong>, a free test number is pre-created. Its <strong>Phone number ID</strong> (a 15-digit number) is shown under it — copy that, not the phone number. To use your own number choose <strong>Add phone number</strong> and complete the SMS/call verification first.
                        <div class="mt-1">Also add your own WhatsApp number under <strong>To</strong> — in development mode only those numbers can be messaged — send the test message and <strong>reply to it from your phone</strong>.</div>
                    </li>
                    <li class="mb-2">
                        <strong>App secret.</strong> Left sidebar → <strong>App settings → Basic</strong> → <strong>App secret → Show</strong>. A 32-character string.
                    </li>
                    <li class="mb-2">
                        <strong>Permanent access token.</strong> The token on API Setup expires in 24 hours — do not use it. Instead open <a href="https://business.facebook.com/latest/settings" target="_blank" rel="noopener">Business settings</a> → <strong>Users → System users → Add</strong> (role Admin) → <strong>Assign assets</strong>: your app (<em>Manage app</em>) and your WhatsApp account (<em>Manage WhatsApp Business accounts</em>) → <strong>Generate token</strong> → expiry <strong>Never</strong> → permissions <code>whatsapp_business_messaging</code>, <code>whatsapp_business_management</code>, <code>business_management</code>. Copy it straight away; Meta never shows it again.
                    </li>
                    <li>
                        <strong>Webhook.</strong> Fill in the form and click <strong>Verify and connect</strong>. The Callback URL and Verify token for Meta’s webhook are generated on save and shown on the next page, together with the remaining steps.
                    </li>
                </ol>
                <p class="x-small text-muted mb-0">Your credentials are checked against Meta before anything is stored, and are kept encrypted.</p>
            </div>
        </div>
    <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
