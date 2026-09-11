<?php
// The plan create/edit form (#24).
//
// Extracted so one piece of markup serves three callers: the page's modal shell,
// the AJAX fragment that fills that modal with an existing plan's values, and
// the plain <noscript>-friendly form at the foot of admin/plans.php. Three copies
// of a form with 20 fields, five limits and a model grant matrix would have
// drifted on the first column added.
//
// Expects, from admin/plans.php: $editing, $chatModels, $grantedModelIds,
// $errors (via pErr()/pCls()), and priceInputValue().
//
// A partial is not a route. Apache denies this whole directory, but a fragment
// that renders admin markup must not depend on the web server being configured
// correctly to stay admin-only (#48) — so it also refuses to run unless a page
// that has already passed requireAdmin() included it. csrfField() only exists
// once config/init.php has run, which is the cheapest true test of that.
if (!function_exists('csrfField')) {
    http_response_code(404);
    exit;
}
?>
<form method="POST" data-ajax id="planForm">
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

    <?php if ($chatModels): ?>
    <hr class="my-4">
    <h6 class="fw-600 mb-1">AI models</h6>
    <p class="text-muted x-small">
        Which models tenants on this plan may choose in Settings → Model. The
        <strong>AI chatbot</strong> feature above must also be on. Writes the same table as
        <a href="<?= APP_URL ?>/admin/llm.php">AI / LLM</a>, so either page can be used.
    </p>
    <?php // Marks the section as rendered. The save handler will not touch
          // plan_llm_models without it — see the comment there. ?>
    <input type="hidden" name="llm_models_present" value="1">
    <div class="row g-2">
        <?php foreach ($chatModels as $m): ?>
        <div class="col-md-6">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="llm_models[]"
                       value="<?= (int)$m['id'] ?>" id="llmModel_<?= (int)$m['id'] ?>"
                       <?= in_array((int)$m['id'], $grantedModelIds, true) ? 'checked' : '' ?>>
                <label class="form-check-label" for="llmModel_<?= (int)$m['id'] ?>">
                    <?= sanitize($m['provider_label'] . ' — ' . $m['label']) ?>
                    <?php if (!$m['is_enabled'] || !$m['provider_enabled']): ?>
                        <span class="badge bg-secondary x-small">disabled</span>
                    <?php endif; ?>
                </label>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <?php // The section is hidden when there is nothing to grant, which left the
          // AI chatbot toggle above looking complete on its own. It is not: a plan
          // with the feature on and no model gives every tenant on it "no models
          // available on your plan". Say so, and link to where models come from. ?>
    <hr class="my-4">
    <h6 class="fw-600 mb-1">AI models</h6>
    <p class="text-muted small mb-0">
        No AI models exist on this instance yet, so switching <strong>AI chatbot</strong> on above
        will not give tenants a working bot — they will see "no models available on your plan".
        Add a provider on <a href="<?= APP_URL ?>/admin/llm.php">AI / LLM</a> first; its models then
        appear here to grant.
    </p>
    <?php endif; ?>

    <hr class="my-4">
    <div class="form-check form-switch mb-4">
        <input class="form-check-input" type="checkbox" name="is_active" id="isActive"
               <?= ($editing === null || !empty($editing['is_active'])) ? 'checked' : '' ?>>
        <label class="form-check-label" for="isActive">Active (offered to tenants)</label>
    </div>

    <button type="submit" class="btn btn-primary">
        <?= $editing && !empty($editing['id']) ? 'Save Plan' : 'Create Plan' ?>
    </button>
</form>
