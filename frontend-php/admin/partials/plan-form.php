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
<form method="POST" data-ajax id="planForm" class="plan-editor-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="plan_id" value="<?= (int)($editing['id'] ?? 0) ?>">

    <section class="plan-form-section">
        <div class="plan-form-section-head">
            <span class="plan-form-section-icon"><i class="bi bi-card-heading"></i></span>
            <div>
                <h6>Plan details</h6>
                <p>The name and internal identity shown across the platform.</p>
            </div>
        </div>
        <div class="plan-form-section-body">
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label" for="planName">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="planName" class="form-control<?= pCls('name') ?>"
                           value="<?= sanitize($editing['name'] ?? '') ?>" maxlength="100" required>
                    <?= pErr('name') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="planCode">Code <span class="text-danger">*</span></label>
                    <input type="text" name="code" id="planCode" class="form-control<?= pCls('code') ?>"
                           value="<?= sanitize($editing['code'] ?? '') ?>" maxlength="40" required
                           placeholder="starter">
                    <div class="form-text">Used internally and in URLs. Avoid changing it after the plan is in use.</div>
                    <?= pErr('code') ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="planSortOrder">Sort order</label>
                    <input type="number" name="sort_order" id="planSortOrder" class="form-control"
                           value="<?= (int)($editing['sort_order'] ?? 0) ?>">
                </div>

                <div class="col-12">
                    <label class="form-label" for="planDescription">Description</label>
                    <input type="text" name="description" id="planDescription" class="form-control<?= pCls('description') ?>"
                           value="<?= sanitize($editing['description'] ?? '') ?>" maxlength="255">
                    <?= pErr('description') ?>
                </div>
            </div>
        </div>
    </section>

    <section class="plan-form-section">
        <div class="plan-form-section-head">
            <span class="plan-form-section-icon"><i class="bi bi-cash-stack"></i></span>
            <div>
                <h6>Pricing</h6>
                <p>Set the amount and billing frequency.</p>
            </div>
        </div>
        <div class="plan-form-section-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="planPrice">Price</label>
                    <input type="text" name="price" id="planPrice" class="form-control<?= pCls('price') ?>"
                           value="<?= sanitize(priceInputValue($editing)) ?>" placeholder="1500">
                    <div class="form-text">Enter the amount in full currency units, for example 1,500. Enter 0 for a free plan.</div>
                    <?= pErr('price') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="planCurrency">Currency</label>
                    <select name="currency" id="planCurrency" class="form-select<?= pCls('currency') ?>">
                        <?php $selCur = $editing['currency'] ?? appCurrency($conn); ?>
                        <?php foreach (currencyChoices() as $code => $label): ?>
                            <option value="<?= $code ?>" <?= $selCur === $code ? 'selected' : '' ?>><?= sanitize($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= pErr('currency') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="planBillingPeriod">Billing period</label>
                    <select name="billing_period" id="planBillingPeriod" class="form-select<?= pCls('billing_period') ?>">
                        <?php foreach (['month' => 'Monthly', 'year' => 'Yearly', 'none' => 'One-time'] as $v => $l): ?>
                            <option value="<?= $v ?>" <?= ($editing['billing_period'] ?? 'month') === $v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= pErr('billing_period') ?>
                </div>
            </div>
        </div>
    </section>

    <section class="plan-form-section">
        <div class="plan-form-section-head">
            <span class="plan-form-section-icon"><i class="bi bi-speedometer2"></i></span>
            <div>
                <h6>Usage limits</h6>
                <p>Leave a field blank for unlimited access. Enter 0 to allow none.</p>
            </div>
        </div>
        <div class="plan-form-section-body">
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
                    <label class="form-label" for="plan_<?= $field ?>"><?= $label ?></label>
                    <input type="text" name="<?= $field ?>" id="plan_<?= $field ?>" class="form-control<?= pCls($field) ?>"
                           value="<?= $val === null ? '' : (int)$val ?>" placeholder="Unlimited">
                    <?= pErr($field) ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="plan-form-section">
        <div class="plan-form-section-head">
            <span class="plan-form-section-icon"><i class="bi bi-stars"></i></span>
            <div>
                <h6>Features</h6>
                <p>Choose the capabilities included for customers on this plan.</p>
            </div>
        </div>
        <div class="plan-form-section-body">
            <div class="row g-2">
                <?php $active = planFeatures($editing ?: []); ?>
                <?php foreach (planFeatureDefinitions() as $key => $def): ?>
                <div class="col-md-6">
                    <div class="form-check form-switch plan-option-card">
                        <input class="form-check-input" type="checkbox" name="features[<?= $key ?>]"
                               id="feat_<?= $key ?>" <?= !empty($active[$key]) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="feat_<?= $key ?>"><?= sanitize($def['label']) ?></label>
                        <div class="form-text x-small"><?= sanitize($def['help']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <?php if ($chatModels): ?>
    <section class="plan-form-section">
        <div class="plan-form-section-head">
            <span class="plan-form-section-icon"><i class="bi bi-cpu"></i></span>
            <div>
                <h6>AI models</h6>
                <p>
                    Choose which models customers on this plan can use in Settings → Model. The
                    <strong>AI chatbot</strong> feature above must also be enabled. Model access is shared with
                    <a href="<?= APP_URL ?>/admin/llm.php">AI &amp; models</a>, so changes made on either page stay in sync.
                </p>
            </div>
        </div>
        <div class="plan-form-section-body">
            <?php // Marks the section as rendered. The save handler will not touch
                  // plan_llm_models without it — see the comment there. ?>
            <input type="hidden" name="llm_models_present" value="1">
            <div class="row g-2">
                <?php foreach ($chatModels as $m): ?>
                <div class="col-md-6">
                    <div class="form-check plan-option-card plan-model-card">
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
        </div>
    </section>
    <?php else: ?>
    <?php // The section is hidden when there is nothing to grant, which left the
          // AI chatbot toggle above looking complete on its own. It is not: a plan
          // with the feature on and no model gives every tenant on it "no models
          // available on your plan". Say so, and link to where models come from. ?>
    <section class="plan-form-section">
        <div class="plan-form-section-head">
            <span class="plan-form-section-icon"><i class="bi bi-cpu"></i></span>
            <div>
                <h6>AI models</h6>
                <p>
                    No AI models exist on this app yet, so switching <strong>AI chatbot</strong> on above
                    will not give customers a working chatbot — they will see "no models available on your plan".
                    Add a provider on <a href="<?= APP_URL ?>/admin/llm.php">AI &amp; models</a> first; its models then
                    appear here to grant.
                </p>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <div class="plan-form-actions">
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" name="is_active" id="isActive"
                   <?= ($editing === null || !empty($editing['is_active'])) ? 'checked' : '' ?>>
            <label class="form-check-label" for="isActive">Available to customers</label>
            <div class="form-text">Turn this off to stop offering the plan to new customers. Existing subscriptions are unchanged.</div>
        </div>
        <button type="submit" class="btn btn-primary">
            <?= $editing && !empty($editing['id']) ? 'Save plan' : 'Create plan' ?>
        </button>
    </div>
</form>
