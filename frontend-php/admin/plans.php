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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('error', 'Invalid request.');
        redirect(APP_URL . '/admin/plans.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_active') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $stmt = $conn->prepare("SELECT code, is_active FROM plans WHERE id = ?");
        $stmt->bind_param('i', $planId);
        $stmt->execute();
        $plan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$plan) {
            flash('error', 'Plan not found.');
            redirect(APP_URL . '/admin/plans.php');
        }

        // Deactivating the plan new tenants are assigned would leave signup
        // unable to give anyone a plan, and quota checks with nothing to read.
        if ((int)$plan['is_active'] === 1 && $plan['code'] === defaultPlanCode($conn)) {
            flash('error', 'This is the default plan for new tenants. Choose a different default in Settings first.');
            redirect(APP_URL . '/admin/plans.php');
        }

        $stmt = $conn->prepare("UPDATE plans SET is_active = 1 - is_active WHERE id = ?");
        $stmt->bind_param('i', $planId);
        $stmt->execute();
        $stmt->close();
        logAudit($conn, 'admin.plan.toggle_active', 'plan', $planId);
        flash('success', 'Plan ' . ((int)$plan['is_active'] === 1 ? 'deactivated' : 'activated') . '.');
        redirect(APP_URL . '/admin/plans.php');
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

            logAudit($conn, $isNew ? 'admin.plan.create' : 'admin.plan.update', 'plan', $savedId, [
                'code' => $code, 'price_cents' => $priceMinor, 'currency' => $currency,
            ]);
            flash('success', 'Plan ' . ($isNew ? 'created' : 'updated') . '.');
            redirect(APP_URL . '/admin/plans.php');
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
        flash('error', 'Please correct the highlighted fields.');
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
        <a href="<?= APP_URL ?>/admin/plans.php#planForm" class="btn btn-sm btn-primary">
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
                            <a href="<?= APP_URL ?>/admin/plans.php?edit=<?= (int)$p['id'] ?>#planForm"
                               class="btn btn-sm btn-outline-primary">Edit</a>
                            <form method="POST" onsubmit="return confirm('<?= $p['is_active'] ? 'Deactivate' : 'Activate' ?> this plan?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary">
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

<div class="card" id="planForm">
    <div class="card-header"><?= $editing && !empty($editing['id']) ? 'Edit Plan' : 'New Plan' ?></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="plan_id" value="<?= (int)($editing['id'] ?? 0) ?>">

            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control<?= pCls('name') ?>"
                           value="<?= sanitize($editing['name'] ?? '') ?>" maxlength="100" required>
                    <?= pErr('name') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Code <span class="text-danger">*</span></label>
                    <input type="text" name="code" class="form-control<?= pCls('code') ?>"
                           value="<?= sanitize($editing['code'] ?? '') ?>" maxlength="40" required
                           placeholder="starter">
                    <div class="form-text">Used in config and URLs. Changing it breaks existing references.</div>
                    <?= pErr('code') ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Sort order</label>
                    <input type="number" name="sort_order" class="form-control"
                           value="<?= (int)($editing['sort_order'] ?? 0) ?>">
                </div>

                <div class="col-12">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control<?= pCls('description') ?>"
                           value="<?= sanitize($editing['description'] ?? '') ?>" maxlength="255">
                    <?= pErr('description') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Price</label>
                    <input type="text" name="price" class="form-control<?= pCls('price') ?>"
                           value="<?= sanitize(priceInputValue($editing)) ?>" placeholder="1500">
                    <div class="form-text">Major units. 0 shows as “Free”.</div>
                    <?= pErr('price') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Currency</label>
                    <select name="currency" class="form-select<?= pCls('currency') ?>">
                        <?php $selCur = $editing['currency'] ?? appCurrency($conn); ?>
                        <?php foreach (currencyChoices() as $code => $label): ?>
                            <option value="<?= $code ?>" <?= $selCur === $code ? 'selected' : '' ?>><?= sanitize($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= pErr('currency') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Billing period</label>
                    <select name="billing_period" class="form-select<?= pCls('billing_period') ?>">
                        <?php foreach (['month' => 'Monthly', 'year' => 'Yearly', 'none' => 'One-time'] as $v => $l): ?>
                            <option value="<?= $v ?>" <?= ($editing['billing_period'] ?? 'month') === $v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= pErr('billing_period') ?>
                </div>
            </div>

            <hr class="my-4">
            <h6 class="fw-600 mb-3">Limits <span class="text-muted small fw-normal">— blank = unlimited, 0 = none allowed</span></h6>
            <div class="row g-3">
                <?php
                $limits = [
                    'max_wa_accounts' => 'WhatsApp accounts',
                    'max_contacts' => 'Contacts',
                    'max_messages_per_month' => 'Messages / month',
                    'max_chatbot_replies' => 'AI replies / month',
                    'max_services' => 'Bookable services',
                ];
                foreach ($limits as $field => $label):
                    $val = $editing[$field] ?? null;
                ?>
                <div class="col-md-4">
                    <label class="form-label"><?= $label ?></label>
                    <input type="text" name="<?= $field ?>" class="form-control<?= pCls($field) ?>"
                           value="<?= $val === null ? '' : (int)$val ?>" placeholder="Unlimited">
                    <?= pErr($field) ?>
                </div>
                <?php endforeach; ?>
            </div>

            <hr class="my-4">
            <h6 class="fw-600 mb-3">Features</h6>
            <div class="row g-2">
                <?php $active = planFeatures($editing ?: []); ?>
                <?php foreach (planFeatureDefinitions() as $key => $def): ?>
                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="features[<?= $key ?>]"
                               id="feat_<?= $key ?>" <?= !empty($active[$key]) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="feat_<?= $key ?>"><?= sanitize($def['label']) ?></label>
                        <div class="form-text x-small"><?= sanitize($def['help']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <hr class="my-4">
            <div class="form-check form-switch mb-4">
                <input class="form-check-input" type="checkbox" name="is_active" id="isActive"
                       <?= ($editing === null || !empty($editing['is_active'])) ? 'checked' : '' ?>>
                <label class="form-check-label" for="isActive">Active (offered to tenants)</label>
            </div>

            <button type="submit" class="btn btn-primary">
                <?= $editing && !empty($editing['id']) ? 'Save Plan' : 'Create Plan' ?>
            </button>
            <?php if ($editing && !empty($editing['id'])): ?>
                <a href="<?= APP_URL ?>/admin/plans.php" class="btn btn-link">Cancel</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
