<?php
// Plans / packages CRUD.
//
// Plans are never deleted, only deactivated: users.plan_id is a FK and
// subscriptions and payments both reference plans, so removing one would either
// fail or erase the record of what a tenant was charged for. Deactivating hides
// it from signup and the tenant-facing comparison while leaving history intact.
require_once dirname(__DIR__) . '/includes/admin-init.php';

$errors = [];
$editing = null;

// A blank limit means unlimited (NULL); 0 means "none allowed". Both are valid
// and they are NOT the same, so an empty string must not collapse to 0.
function parseLimit($raw, $field, array &$errors) {
    $value = trim((string)$raw);
    if ($value === '') return null;
    if (!preg_match('/^\d+$/', $value)) {
        $errors[$field] = 'Enter a whole number, or leave blank for unlimited.';
        return null;
    }
    return (int)$value;
}

$self = APP_URL . '/admin/plans.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    formRequireCsrf($self);

    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_active') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $stmt = $conn->prepare("SELECT code, is_active FROM plans WHERE id = ?");
        $stmt->bind_param('i', $planId);
        $stmt->execute();
        $plan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$plan) formRespond(false, 'Plan not found.', $self);

        // Deactivating the plan new tenants are assigned would leave signup
        // unable to give anyone a plan, and quota checks with nothing to read.
        if ((int)$plan['is_active'] === 1 && $plan['code'] === defaultPlanCode($conn)) {
            formRespond(false, 'This is the default plan for new tenants. Choose a different default in Settings first.', $self);
        }

        $stmt = $conn->prepare("UPDATE plans SET is_active = 1 - is_active WHERE id = ?");
        $stmt->bind_param('i', $planId);
        $stmt->execute();
        $stmt->close();
        logAudit($conn, 'admin.plan.toggle_active', 'plan', $planId);
        formRespond(true, 'Plan ' . ((int)$plan['is_active'] === 1 ? 'deactivated' : 'activated') . '.', $self);
    }

    if ($action === 'save') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $isNew = $planId === 0;

        $code = strtolower(trim($_POST['code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $period = $_POST['billing_period'] ?? 'month';
        $currency = strtoupper(trim($_POST['currency'] ?? appCurrency($conn)));
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (!preg_match('/^[a-z0-9_-]{2,40}$/', $code)) {
            $errors['code'] = 'Lowercase letters, numbers, dash and underscore only (2–40 chars).';
        } else {
            // The column is UNIQUE, so a duplicate would throw. Check first to
            // return a field error instead of a 500.
            $stmt = $conn->prepare("SELECT id FROM plans WHERE code = ? AND id != ?");
            $stmt->bind_param('si', $code, $planId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) $errors['code'] = 'That code is already in use.';
            $stmt->close();
        }

        if ($name === '' || mb_strlen($name) > 100) $errors['name'] = 'Required, max 100 characters.';
        if (mb_strlen($description) > 255) $errors['description'] = 'Max 255 characters.';
        if (!in_array($period, ['month', 'year', 'none'], true)) $errors['billing_period'] = 'Invalid billing period.';
        if (!isValidCurrency($currency) || !array_key_exists($currency, currencyFormats())) {
            $errors['currency'] = 'Select a currency from the list.';
        }

        $priceMinor = parseMoneyInput($_POST['price'] ?? '0', $currency);
        if ($priceMinor === null) {
            $errors['price'] = 'Enter an amount, e.g. 1500 or 1500.50.';
            $priceMinor = 0;
        }

        $maxAccounts = parseLimit($_POST['max_wa_accounts'] ?? '', 'max_wa_accounts', $errors);
        $maxContacts = parseLimit($_POST['max_contacts'] ?? '', 'max_contacts', $errors);
        $maxMessages = parseLimit($_POST['max_messages_per_month'] ?? '', 'max_messages_per_month', $errors);
        $maxReplies  = parseLimit($_POST['max_chatbot_replies'] ?? '', 'max_chatbot_replies', $errors);
        $maxServices = parseLimit($_POST['max_services'] ?? '', 'max_services', $errors);

        $features = encodePlanFeatures($_POST['features'] ?? []);

        if (!$errors) {
            // Types derived from the values rather than hand-written. This
            // statement now binds 14 (or 15) parameters, which is exactly the
            // length at which a positional string stops being reviewable — the
            // chatbot config upsert shipped a 27-character string for 26
            // variables and 500'd on every save. A NULL limit binds as 's',
            // which is correct: MySQL stores NULL regardless of declared type,
            // and NULL is how "unlimited" is represented.
            $params = [
                $code, $name, $description, $priceMinor, $currency, $period,
                $maxAccounts, $maxContacts, $maxMessages, $maxReplies, $maxServices,
                $features, $isActive, $sortOrder,
            ];

            if ($isNew) {
                $stmt = $conn->prepare(
                    "INSERT INTO plans (code, name, description, price_cents, currency, billing_period,
                                        max_wa_accounts, max_contacts, max_messages_per_month,
                                        max_chatbot_replies, max_services,
                                        features, is_active, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
            } else {
                $stmt = $conn->prepare(
                    "UPDATE plans SET code = ?, name = ?, description = ?, price_cents = ?, currency = ?,
                                      billing_period = ?, max_wa_accounts = ?, max_contacts = ?,
                                      max_messages_per_month = ?, max_chatbot_replies = ?,
                                      max_services = ?, features = ?, is_active = ?, sort_order = ?
                     WHERE id = ?"
                );
                $params[] = $planId;
            }

            $types = '';
            foreach ($params as $p) $types .= is_int($p) ? 'i' : 's';
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $savedId = $isNew ? $conn->insert_id : $planId;
            $stmt->close();

            // Model grants live in plan_llm_models and were previously only
            // reachable from a sidebar matrix on admin/llm.php — a page an
            // admin editing a plan has already left. Enabling the chatbot
            // feature here without granting a model there leaves every tenant
            // on the plan with "no models available", so both belong together.
            //
            // Gated on the hidden marker, not on the presence of llm_models:
            // an unticked list posts nothing, and so does a form that rendered
            // no list at all. Without the marker those two are indistinguishable
            // and a save from the latter would silently wipe grants made in the
            // matrix.
            if (!empty($_POST['llm_models_present'])) {
                // Intersected with the real chat-model ids rather than trusted:
                // the ids arrive from checkboxes, and a hand-built POST could
                // otherwise grant a transcribe model, which is not selectable
                // as a chatbot model and would just be a dead option.
                $chatModelIds = array_map(fn($m) => (int)$m['id'], llmModels($conn, 'chat', false));
                $picked = array_map('intval', (array)($_POST['llm_models'] ?? []));
                llmSetPlanModels($conn, $savedId, array_values(array_intersect($chatModelIds, $picked)));
            }

            logAudit($conn, $isNew ? 'admin.plan.create' : 'admin.plan.update', 'plan', $savedId, [
                'code' => $code, 'price_cents' => $priceMinor, 'currency' => $currency,
            ]);
            formRespond(true, 'Plan ' . ($isNew ? 'created' : 'updated') . '.', $self);
        }

        // Redisplay the form with what was typed.
        $editing = [
            'id' => $planId, 'code' => $code, 'name' => $name, 'description' => $description,
            'price_cents' => $priceMinor, 'currency' => $currency, 'billing_period' => $period,
            'max_wa_accounts' => $maxAccounts, 'max_contacts' => $maxContacts,
            'max_messages_per_month' => $maxMessages, 'max_chatbot_replies' => $maxReplies,
            'max_services' => $maxServices,
            'features' => $features, 'is_active' => $isActive, 'sort_order' => $sortOrder,
        ];
        // formErrors(), not formRespond(): the plain-form path falls through to
        // the render with what was typed still in the fields.
        formErrors('Please correct the highlighted fields.', $errors);
    }
}

// GET ?edit=<id> loads a plan into the form.
if ($editing === null && isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM plans WHERE id = ?");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editing = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$plans = $conn->query(
    "SELECT p.*,
            (SELECT COUNT(*) FROM users u WHERE u.plan_id = p.id) AS tenant_count
     FROM plans p ORDER BY p.sort_order, p.id"
)->fetch_all(MYSQLI_ASSOC);

$defaultCode = defaultPlanCode($conn);

// The chatbot feature toggle below is useless on its own — a plan also needs at
// least one granted model. Both are rendered here so an admin never has to know
// the matrix on admin/llm.php exists.
$chatModels = llmModels($conn, 'chat', false);
// On a redisplay after a validation error the ticks must survive, so they come
// from the POST rather than the database.
$grantedModelIds = ($_SERVER['REQUEST_METHOD'] === 'POST')
    ? array_map('intval', (array)($_POST['llm_models'] ?? []))
    : (($editing && !empty($editing['id'])) ? llmPlanModelIds($conn, (int)$editing['id']) : []);

// The populated form, on its own, for the modal to load (#24).
//
// Sent only in reply to our own fetch() — the X-Requested-With header is what
// distinguishes it — so a normal ?edit=N page load is unaffected and still
// renders the whole page. requireAdmin() has already run, from admin-init.php,
// before anything here.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isXhrRequest() && isset($_GET['edit'])) {
    require __DIR__ . '/partials/plan-form.php';
    exit;
}

function pErr($k) { global $errors; return empty($errors[$k]) ? '' : '<div class="invalid-feedback d-block">' . sanitize($errors[$k]) . '</div>'; }
function pCls($k) { global $errors; return empty($errors[$k]) ? '' : ' is-invalid'; }

// Major-unit string for the price input, from the stored minor units.
function priceInputValue($plan) {
    if (!$plan) return '';
    $decimals = currencyFormat($plan['currency'] ?? 'PKR')['decimals'];
    $minor = (int)($plan['price_cents'] ?? 0);
    if ($minor === 0) return '0';
    return rtrim(rtrim(number_format($minor / (10 ** $decimals), $decimals, '.', ''), '0'), '.');
}

$pageTitle = 'Plans';
require_once dirname(__DIR__) . '/includes/admin-header.php';
?>

<?php foreach (['success' => 'success', 'error' => 'danger'] as $key => $cls): ?>
    <?php if ($msg = flash($key)): ?>
        <div class="alert alert-<?= $cls ?> alert-dismissible fade show">
            <?= sanitize($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<div class="card table-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Packages</span>
        <?php // href is a real link to the same page's form, so this works with
              // JavaScript off; the data-* attributes upgrade it to the modal. ?>
        <a href="<?= APP_URL ?>/admin/plans.php#planShell" class="btn btn-sm btn-primary"
           data-modal-target="#planModal" data-modal-url="<?= APP_URL ?>/admin/plans.php?edit=0"
           data-modal-title="New plan">
            <i class="bi bi-plus-lg me-1"></i>New plan
        </a>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Plan</th><th>Price</th><th>WA</th><th>Contacts</th><th>Messages</th>
                    <th title="AI replies per month">Replies</th><th title="Bookable services">Services</th>
                    <th>Features</th><th>Tenants</th><th>Status</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($plans as $p): ?>
                <tr class="<?= $p['is_active'] ? '' : 'opacity-50' ?>">
                    <td>
                        <div class="fw-500">
                            <?= sanitize($p['name']) ?>
                            <?php if ($p['code'] === $defaultCode): ?>
                                <span class="badge bg-info ms-1" title="Assigned to new tenants">default</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted x-small"><code><?= sanitize($p['code']) ?></code></div>
                    </td>
                    <td class="small"><?= sanitize(formatPrice($p)) ?></td>
                    <td class="small"><?= sanitize(formatLimit(planLimit($p, 'max_wa_accounts'))) ?></td>
                    <td class="small"><?= sanitize(formatLimit(planLimit($p, 'max_contacts'))) ?></td>
                    <td class="small"><?= sanitize(formatLimit(planLimit($p, 'max_messages_per_month'))) ?></td>
                    <td class="small"><?= sanitize(formatLimit(planLimit($p, 'max_chatbot_replies'))) ?></td>
                    <td class="small"><?= sanitize(formatLimit(planLimit($p, 'max_services'))) ?></td>
                    <td>
                        <?php $on = array_keys(array_filter(planFeatures($p))); ?>
                        <?php if (!$on): ?><span class="text-muted x-small">—</span><?php endif; ?>
                        <?php foreach ($on as $key): ?>
                            <span class="badge bg-light text-dark x-small"><?= sanitize(planFeatureDefinitions()[$key]['label'] ?? $key) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td class="small"><?= number_format((int)$p['tenant_count']) ?></td>
                    <td>
                        <span class="badge bg-<?= $p['is_active'] ? 'success' : 'secondary' ?>">
                            <?= $p['is_active'] ? 'active' : 'inactive' ?>
                        </span>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="<?= APP_URL ?>/admin/plans.php?edit=<?= (int)$p['id'] ?>#planShell"
                               class="btn btn-sm btn-outline-primary"
                               data-modal-target="#planModal"
                               data-modal-url="<?= APP_URL ?>/admin/plans.php?edit=<?= (int)$p['id'] ?>"
                               data-modal-title="Edit <?= sanitize($p['name']) ?>">Edit</a>
                            <form method="POST" data-ajax>
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                                <?php // Only deactivating is confirmed: it removes the plan from
                                      // signup and from the tenant comparison. Activating is
                                      // additive, and a dialog on a harmless action teaches people
                                      // to dismiss dialogs. ?>
                                <button class="btn btn-sm btn-outline-secondary"
                                    <?php if ($p['is_active']): ?>
                                        data-confirm="Deactivate <?= sanitize($p['name']) ?>? It stops being offered to new tenants. The <?= number_format((int)$p['tenant_count']) ?> tenant(s) already on it keep it."
                                    <?php endif; ?>>
                                    <?= $p['is_active'] ? 'Disable' : 'Enable' ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body border-top">
        <p class="text-muted x-small mb-0">
            Plans are disabled, never deleted — tenants, subscriptions and payment records all
            reference them, and removing one would erase what a tenant was charged for.
            A blank limit means <strong>unlimited</strong>; <strong>0</strong> means none allowed.
        </p>
    </div>
</div>

<?php // Rendered exactly once, as a plain card, and promoted to a modal by
      // forms.js when JavaScript is available (#24).
      //
      // The alternative — a modal copy plus a <noscript> copy — puts the same
      // twenty field ids in the document twice, which silently breaks every
      // <label for> in whichever copy comes second. Rendering once and letting the
      // enhancement layer move the node keeps one source of truth, and with
      // JavaScript off this is the full-page editor it has always been.
      //
      // The form itself is filled by the server, never from data-* attributes on
      // the table rows: it has five limits, seven feature switches and a model
      // grant matrix, and reproducing that in markup would be a second
      // implementation of the form. Editing fetches the populated partial. ?>
<div class="card" id="planShell" data-modal-shell="planModal"
     data-modal-title="<?= $editing && !empty($editing['id']) ? 'Edit plan' : 'New plan' ?>"
     <?= ($editing && !empty($editing['id'])) ? 'data-modal-open="1"' : '' ?>>
    <div class="card-header"><?= $editing && !empty($editing['id']) ? 'Edit Plan' : 'New Plan' ?></div>
    <div class="card-body">
        <?php require __DIR__ . '/partials/plan-form.php'; ?>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
