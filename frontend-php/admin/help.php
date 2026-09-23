<?php
require_once dirname(__DIR__) . '/includes/admin-init.php';

function adminHelpFigure(string $file, string $alt, string $captionHtml): void {
    $src = APP_URL . '/admin/help-image.php?f=' . rawurlencode($file);
    ?>
    <figure class="help-figure">
        <a href="<?= sanitize($src) ?>" target="_blank" rel="noopener" class="help-figure-link"
           aria-label="Open full-size screenshot: <?= sanitize($alt) ?>">
            <img src="<?= sanitize($src) ?>" alt="<?= sanitize($alt) ?>" loading="lazy" decoding="async">
            <span class="help-zoom" aria-hidden="true"><i class="bi bi-zoom-in"></i> Tap image to enlarge</span>
        </a>
        <figcaption><?= $captionHtml ?></figcaption>
    </figure>
    <?php
}

$pageTitle = 'Help Center';
require_once dirname(__DIR__) . '/includes/admin-header.php';
?>

<div class="help-hero">
    <h2 class="help-hero-title">Admin Help Center</h2>
    <p class="help-hero-text">
        A tour of the admin console — setting the instance up, creating customers, plans and
        payments, prospecting, and the instance-wide configuration. Some actions here affect
        other people's accounts or cost money; warnings mark those. All screenshots below show
        illustrative, redacted example data — not your real numbers.
    </p>
</div>

<div class="help-quick">
    <a class="help-quick-card" href="<?= APP_URL ?>/admin/tenants.php">
        <i class="bi bi-person-plus"></i>
        <span class="help-quick-title">Add a customer</span>
        <span class="help-quick-sub">Invite link or temporary password</span>
    </a>
    <a class="help-quick-card" href="<?= APP_URL ?>/admin/plans.php">
        <i class="bi bi-box-seam"></i>
        <span class="help-quick-title">Edit plans</span>
        <span class="help-quick-sub">Prices, quotas and features</span>
    </a>
    <a class="help-quick-card" href="<?= APP_URL ?>/admin/llm.php">
        <i class="bi bi-robot"></i>
        <span class="help-quick-title">AI &amp; models</span>
        <span class="help-quick-sub">Providers and access</span>
    </a>
    <a class="help-quick-card" href="<?= APP_URL ?>/admin/system.php">
        <i class="bi bi-activity"></i>
        <span class="help-quick-title">System &amp; audit</span>
        <span class="help-quick-sub">Health checks and history</span>
    </a>
</div>

<div class="help-layout">
    <nav class="help-nav" aria-label="Guide chapters">
        <a href="#help-overview">1. Overview</a>
        <a href="#help-customers">2. Customers</a>
        <a href="#help-plans">3. Plans</a>
        <a href="#help-payments">4. Payments</a>
        <a href="#help-leads">5. Leads</a>
        <a href="#help-settings">6. Settings</a>
        <a href="#help-branding">7. Branding</a>
        <a href="#help-email">8. Email / SMTP</a>
        <a href="#help-llm">9. AI &amp; models</a>
        <a href="#help-system">10. System &amp; audit</a>
    </nav>

    <div class="help-body">

        <section class="help-section" id="help-overview">
            <h3><span class="help-num">1</span> Overview</h3>
            <p>
                <a href="<?= APP_URL ?>/admin/index.php">Overview</a> is the landing page. Until the
                instance is configured it shows a <strong>Getting started</strong> checklist — each
                step links to the page that completes it, checked against live configuration.
                Below it are platform health, engagement, quota and payment summaries.
            </p>
            <ol class="help-steps">
                <li>Work through the checklist top to bottom — it disappears when nothing is left.</li>
                <li>Scan the health and engagement cards each visit; anything failing links to the page that fixes it.</li>
            </ol>
            <?php adminHelpFigure('admin-overview.png',
                'Admin Overview page with platform health and metrics cards (redacted example).',
                'The admin landing page on a configured instance; on a fresh install the Getting started checklist appears above these metrics. <a href="' . APP_URL . '/admin/index.php">Open overview</a>'); ?>
        </section>

        <section class="help-section" id="help-customers">
            <h3><span class="help-num">2</span> Customers</h3>
            <p>
                <a href="<?= APP_URL ?>/admin/tenants.php">Customers</a> lists every customer with
                search, status and plan filters.
            </p>
            <ol class="help-steps">
                <li>Click <strong>New customer</strong>, fill in name, email, company, plan and status.</li>
                <li>Choose the first login: <strong>Email a password-setup link</strong> (they pick their own password — this sends a real email and only works if SMTP is configured) or <strong>Set a temporary password now</strong> (shown exactly once — copy it before leaving the page; they must replace it on first login). If email is not set up yet, always use the temporary password.</li>
                <li>Open a customer from the list to see their detail: usage, accounts and activity. From the list you can also change their plan, suspend or reactivate them.</li>
            </ol>
            <?php adminHelpFigure('admin-customers.png',
                'Customers list with filters, plan controls and suspend actions. (redacted example)',
                'The customer list: filter, change plan, suspend. <a href="' . APP_URL . '/admin/tenants.php">Open customers</a>'); ?>
            <?php adminHelpFigure('admin-customer-detail.png',
                'Customer detail page showing account info, plan, usage counters and WhatsApp accounts. (redacted example)',
                'A customer detail page — usage this month and their linked accounts. <a href="' . APP_URL . '/admin/tenants.php">Open customers</a>'); ?>
            <div class="help-note">
                <i class="bi bi-exclamation-triangle"></i>
                <div><strong>Suspending</strong> a customer blocks their sign-in immediately. You cannot suspend or demote your own admin account — the instance always keeps at least one administrator. On a new instance, configure <a href="<?= APP_URL ?>/admin/email.php">Email / SMTP</a> before inviting customers, or use temporary passwords until it works.</div>
            </div>
        </section>

        <section class="help-section" id="help-plans">
            <h3><span class="help-num">3</span> Plans</h3>
            <ol class="help-steps">
                <li>Open <a href="<?= APP_URL ?>/admin/plans.php">Plans</a> and click <strong>New plan</strong>, or <strong>Edit</strong> on an existing plan to open its form.</li>
                <li>In the plan form, set the price and the quotas — messages, AI replies, WhatsApp accounts, contacts.</li>
                <li>Toggle the feature levers (Cloud API, media sending, CSV export, bring-your-own key) and tick which AI models the plan may use, then save.</li>
            </ol>
            <p>Plans are deactivated rather than deleted, because customer accounts reference them — customers already on a deactivated plan keep working on it. A model only reaches customers when all three hold: an enabled provider with a saved key, an enabled model, and a grant on their plan here — testing the provider is a recommended diagnostic, not a hard gate. The default plan chosen in <a href="<?= APP_URL ?>/admin/settings.php">Settings</a> applies only to customers created afterwards.</p>
            <?php adminHelpFigure('admin-plans.png',
                'Plans page listing the available plans with prices and customer counts (redacted example).',
                'Plans control what every customer on them can do. <a href="' . APP_URL . '/admin/plans.php">Open plans</a>'); ?>
        </section>

        <section class="help-section" id="help-payments">
            <h3><span class="help-num">4</span> Payments</h3>
            <p>
                <a href="<?= APP_URL ?>/admin/payments.php">Payments</a> is a manual ledger — there is
                no payment gateway and nothing charges a customer automatically. You agree payment
                details with customers yourself (communicate them through the payment instructions in
                <a href="<?= APP_URL ?>/admin/settings.php">Settings</a>), then record what arrived here.
            </p>
            <ol class="help-steps">
                <li>Click <strong>Log a payment</strong> to open the form: pick the customer, amount, method and the period it covers.</li>
                <li>Applying a plan from a payment can extend the customer's subscription dates.</li>
                <li>Renewal reminders can be sent from here — <strong>they send a real email to the customer</strong>, so only use them intentionally.</li>
            </ol>
            <?php adminHelpFigure('admin-payments.png',
                'Payments page: the payment list with search and the link to the log form (redacted example).',
                'The payments ledger — a record of payments you logged, not official bank receipts. <a href="' . APP_URL . '/admin/payments.php">Open payments</a>'); ?>
        </section>

        <section class="help-section" id="help-leads">
            <h3><span class="help-num">5</span> Leads</h3>
            <p>
                <a href="<?= APP_URL ?>/admin/leads.php">Leads</a> is your own prospecting list —
                nothing here is tenant-facing.
            </p>
            <ol class="help-steps">
                <li>Search by category, location, radius and minimum rating to pull businesses from Google Maps via <a href="https://serpapi.com/" target="_blank" rel="noopener noreferrer">SerpApi</a> — the API key is configured in <a href="<?= APP_URL ?>/admin/settings.php">Settings → Lead search</a>. Use an exact area name for useful results.</li>
                <li>Save promising results to the list; filter by the saved-leads filters and export them to CSV.</li>
            </ol>
            <div class="help-note">
                <i class="bi bi-exclamation-triangle"></i>
                <div>Successful searches — including a successful <strong>Test key</strong> in Settings — generally use <strong>one SerpApi credit per results page</strong> (cached or failed calls may not count). Check your SerpApi account for the balance and exact terms — it is SerpApi that bills you, not Google — so search deliberately.</div>
            </div>
            <?php adminHelpFigure('admin-leads.png',
                'Leads page with the search form and the saved-leads table. (redacted example)',
                'Prospecting: search spends API credits, saved leads export to CSV. <a href="' . APP_URL . '/admin/leads.php">Open leads</a>'); ?>
        </section>

        <section class="help-section" id="help-settings">
            <h3><span class="help-num">6</span> Settings</h3>
            <ol class="help-steps">
                <li>Open <a href="<?= APP_URL ?>/admin/settings.php">Settings</a>.</li>
                <li>Work through the cards: regional settings (currency, timezone), the default plan for new customers, login security, handover notifications, payment instructions and plan enquiry contact.</li>
                <li>Click <strong>Save settings</strong> for those. <strong>Lead search</strong> is separate — it has its own <strong>Save lead settings</strong>, and <strong>Test key</strong> runs a real search and may use one SerpApi credit.</li>
            </ol>
            <?php adminHelpFigure('admin-settings.png',
                'Settings page with instance default plan, currency, timezone and contact fields. (redacted example)',
                'Instance-wide defaults. <a href="' . APP_URL . '/admin/settings.php">Open settings</a>'); ?>
        </section>

        <section class="help-section" id="help-branding">
            <h3><span class="help-num">7</span> Branding</h3>
            <p>
                <a href="<?= APP_URL ?>/admin/branding.php">Branding</a> controls the name, logo and
                favicon shown across the tenant app, sign-in and email pages. Upload your own marks
                and the stock branding is replaced everywhere. If you reference an externally hosted
                logo URL instead of uploading, every visitor's browser fetches it from that third-party
                host — upload the file if you do not want that dependency.
            </p>
            <?php adminHelpFigure('admin-branding.png',
                'Branding page with name, logo and favicon controls. (redacted example)',
                'One place for the product name and marks. <a href="' . APP_URL . '/admin/branding.php">Open branding</a>'); ?>
        </section>

        <section class="help-section" id="help-email">
            <h3><span class="help-num">8</span> Email / SMTP</h3>
            <ol class="help-steps">
                <li>Open <a href="<?= APP_URL ?>/admin/email.php">Email / SMTP</a> and enter your mail server details.</li>
                <li><strong>Save &amp; test connection</strong> verifies the credentials without sending anything — use it first.</li>
                <li><strong>Save &amp; send test</strong> delivers a real email to the address you enter — use a mailbox you control.</li>
            </ol>
            <p>Without working SMTP, password-setup invitations, resets and renewal reminders cannot be delivered.</p>
            <div class="help-note">
                <i class="bi bi-info-circle"></i>
                <div>
                    <ul class="help-steps">
                        <li>The host, port, username and password come from whichever email provider you choose — this app uses password-based SMTP over TLS/STARTTLS, not OAuth2.</li>
                        <li>Gmail may need an <a href="https://support.google.com/mail/answer/185833" target="_blank" rel="noopener noreferrer">app password</a>: if your account offers app passwords, Google requires 2-Step Verification to create one — some account types cannot use them at all.</li>
                        <li>Outlook.com <a href="https://support.microsoft.com/en-us/outlook/pop-imap-and-smtp-settings-for-outlook-com" target="_blank" rel="noopener noreferrer">requires OAuth2</a>, so a basic password will not work there — use an SMTP relay that supports password auth instead. Account or billing problems belong to the email provider.</li>
                    </ul>
                </div>
            </div>
            <?php adminHelpFigure('admin-email.png',
                'Email SMTP settings page with server fields and verify/test controls. (redacted example)',
                'Verify the connection before sending a test. <a href="' . APP_URL . '/admin/email.php">Open email settings</a>'); ?>
        </section>

        <section class="help-section" id="help-llm">
            <h3><span class="help-num">9</span> AI &amp; models</h3>
            <ol class="help-steps">
                <li>Open <a href="<?= APP_URL ?>/admin/llm.php">AI &amp; models</a>.</li>
                <li>Add a provider with its API key and base URL if needed, then <strong>test</strong> it — the test makes a real API call that may use a little of the provider's balance, and a failed test suggests models using that provider may fail, so check its account/key and the provider's status.</li>
                <li>Add models, then set <strong>Model access by plan</strong> to control which plans may use each one — a customer only sees a model when both exist: a working provider and a grant on their plan.</li>
            </ol>
            <p>
                Provider keys are stored encrypted and are never shown again after saving. Keys and
                credit balances live on each provider's own console — on eligible installs FenLLM is
                provisioned automatically, so check whether a key is already in place before adding one:
            </p>
            <ul class="help-steps">
                <?php foreach (llmProviderCatalogue() as $prov): ?>
                <li><strong><?= sanitize($prov['label']) ?></strong> — <a href="<?= sanitize($prov['console_url']) ?>" target="_blank" rel="noopener noreferrer"><?= sanitize(ltrim(parse_url($prov['console_url'], PHP_URL_HOST) . parse_url($prov['console_url'], PHP_URL_PATH), '/')) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <?php adminHelpFigure('admin-llm.png',
                'AI and models page with provider configuration and model list. (redacted example)',
                'Providers, keys and the models customers can pick. <a href="' . APP_URL . '/admin/llm.php">Open AI &amp; models</a>'); ?>
        </section>

        <section class="help-section" id="help-system">
            <h3><span class="help-num">10</span> System &amp; audit</h3>
            <ol class="help-steps">
                <li>Open <a href="<?= APP_URL ?>/admin/system.php">System &amp; audit</a>.</li>
                <li>The health view reports the services and configuration the app depends on.</li>
                <li>The audit tab lists administrative actions — who did what and when — for the whole instance.</li>
                <li>If a provider feature fails (AI replies, email, lead search), check whether the health view blames this platform before assuming a code bug — provider-side problems belong on that vendor's console. Rotate external keys at the vendor first, then update them here.</li>
            </ol>
            <?php adminHelpFigure('admin-system-health.png',
                'System and audit page showing instance health checks (redacted example).',
                'Instance health at a glance. <a href="' . APP_URL . '/admin/system.php">Open system</a>'); ?>
            <?php adminHelpFigure('admin-system-audit.png',
                'Audit tab listing recorded administrative actions (redacted example).',
                'The audit trail for administrative actions. <a href="' . APP_URL . '/admin/system.php?days=30#tab-audit">Open the audit tab</a>'); ?>
            <p>
                <strong>Back to app</strong> in the sidebar returns to the tenant dashboard. Your own
                profile and password live under the tenant app's avatar menu, not here.
            </p>
        </section>

    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
