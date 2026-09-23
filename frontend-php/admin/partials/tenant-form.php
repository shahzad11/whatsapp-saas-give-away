<?php
// The "add a tenant" form (#48, #49).
//
// Extracted for the same reason as plan-form.php: it is rendered once, as a
// plain card at the foot of admin/tenants.php, and forms.js promotes that card
// into a modal. Rendering a modal copy and a <noscript> copy would put every
// field id in the document twice and silently break the <label for> pairs of
// whichever came second.
//
// Expects, from admin/tenants.php: $plans and $canEmail.
//
// No per-field error markup: admin/tenants.php answers every action through
// formRespond(), which redirects the plain path, so a rejected create never
// returns to this form. forms.js paints the field errors from the JSON reply on
// the path that stays on the page.
//
// Not a route — see plan-form.php for why this check is here as well as in the
// vhost.
if (!function_exists('csrfField')) {
    http_response_code(404);
    exit;
}
?>
<form method="POST" data-ajax data-ajax-reload="off" id="tenantForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create">

    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="tenantName">Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="tenantName" class="form-control"
                   maxlength="100" required placeholder="Jane Doe">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="tenantEmail">Email <span class="text-danger">*</span></label>
            <input type="email" name="email" id="tenantEmail" class="form-control"
                   maxlength="255" required placeholder="jane@example.com">
            <div class="form-text">The customer uses this address to sign in. It must be unique and accessible to them.</div>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="tenantCompany">Company</label>
            <input type="text" name="company_name" id="tenantCompany"
                   class="form-control" maxlength="150" placeholder="Optional">
            <div class="form-text">Used as the customer’s display name in admin lists.</div>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="tenantPlan">Plan</label>
            <select name="plan_id" id="tenantPlan" class="form-select">
                <?php // Value 0 is a real choice, not a placeholder: createTenant()
                      // resolves it to the instance default, which is the setting
                      // that exists precisely so this does not have to be decided
                      // per tenant. ?>
                <option value="0">Default plan</option>
                <?php foreach ($plans as $p): ?>
                    <option value="<?= (int)$p['id'] ?>">
                        <?= sanitize($p['name']) ?> — <?= sanitize(formatPrice($p)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="tenantStatus">Status</label>
            <select name="status" id="tenantStatus" class="form-select">
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
            </select>
            <div class="form-text">Suspended customers cannot sign in until they are reactivated.</div>
        </div>
    </div>

    <hr class="my-4">

    <?php // How they get in for the first time. Two options, and the better one
          // is disabled rather than hidden when SMTP is not configured: hiding it
          // would leave an admin wondering why a documented feature is missing,
          // and the note names the page that fixes it. ?>
    <fieldset>
        <legend class="form-label mb-2">First login</legend>

        <div class="form-check mb-2">
            <input class="form-check-input" type="radio" name="onboarding" id="onboardInvite"
                   value="invite" <?= $canEmail ? 'checked' : 'disabled' ?>>
            <label class="form-check-label" for="onboardInvite">
                Email a password-setup link
                <span class="text-muted small d-block">
                    Sends a single-use link that expires after 7 days. The customer chooses
                    their own password.
                </span>
            </label>
        </div>

        <div class="form-check">
            <input class="form-check-input" type="radio" name="onboarding" id="onboardTemp"
                   value="temp_password" <?= $canEmail ? '' : 'checked' ?>>
            <label class="form-check-label" for="onboardTemp">
                Set a temporary password now
                <span class="text-muted small d-block">
                    The temporary password is shown once so you can share it securely with the
                    customer. They must replace it before using the account.
                </span>
            </label>
        </div>

        <?php if (!$canEmail): ?>
            <div class="alert alert-warning py-2 mt-3 mb-0 small">
                <i class="bi bi-exclamation-triangle me-1"></i>
                This instance cannot send email, so an invitation cannot be delivered.
                <a href="<?= APP_URL ?>/admin/email.php">Configure outgoing email</a>
                to use password-setup links.
            </div>
        <?php endif; ?>
    </fieldset>

    <div class="mt-4">
        <button type="submit" class="btn btn-primary" data-busy-label="Creating…">Create customer</button>
    </div>
</form>
