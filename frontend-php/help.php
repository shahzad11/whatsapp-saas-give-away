<?php
require_once __DIR__ . '/config/init.php';
requireLogin();

function helpFigure(string $file, string $alt, string $captionHtml): void {
    $src = APP_URL . '/assets/images/help/' . $file;
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
require_once __DIR__ . '/includes/header.php';
?>

<div class="help-hero">
    <h2 class="help-hero-title">Help Center</h2>
    <p class="help-hero-text">
        A guided tour of your dashboard — from linking your first WhatsApp number to
        teaching the bot, taking over live chats and booking appointments. Every section
        shows the real screen and links straight to it.
    </p>
</div>

<div class="help-quick">
    <a class="help-quick-card" href="<?= APP_URL ?>/whatsapp/link.php">
        <i class="bi bi-qr-code"></i>
        <span class="help-quick-title">Link WhatsApp</span>
        <span class="help-quick-sub">Scan a QR code, about a minute</span>
    </a>
    <a class="help-quick-card" href="<?= APP_URL ?>/settings.php#tab-kb">
        <i class="bi bi-journal-text"></i>
        <span class="help-quick-title">Teach the bot</span>
        <span class="help-quick-sub">Paste what it should know</span>
    </a>
    <a class="help-quick-card" href="<?= APP_URL ?>/settings.php#tab-test">
        <i class="bi bi-chat-square-text"></i>
        <span class="help-quick-title">Test the bot</span>
        <span class="help-quick-sub">Try it before customers do</span>
    </a>
    <a class="help-quick-card" href="<?= APP_URL ?>/live-chats.php">
        <i class="bi bi-headset"></i>
        <span class="help-quick-title">Live chats</span>
        <span class="help-quick-sub">Reply to customers yourself</span>
    </a>
</div>

<div class="help-layout">
    <nav class="help-nav" aria-label="Guide chapters">
        <a href="#help-start">1. First login &amp; dashboard</a>
        <a href="#help-link">2. Link a WhatsApp account</a>
        <a href="#help-cloud">3. Cloud API (optional)</a>
        <a href="#help-chats">4. Chats &amp; contacts</a>
        <a href="#help-bot">5. Bot settings</a>
        <a href="#help-live">6. Handover &amp; live chats</a>
        <a href="#help-appts">7. Appointments</a>
        <a href="#help-billing">8. Billing &amp; usage</a>
        <a href="#help-profile">9. Profile</a>
        <a href="#help-trouble">Troubleshooting</a>
    </nav>

    <div class="help-body">

        <section class="help-section" id="help-start">
            <h3><span class="help-num">1</span> First login &amp; dashboard</h3>
            <p>You get in one of two ways, depending on how your account was set up:</p>
            <ol class="help-steps">
                <li><strong>Setup link by email</strong> — open the link you were sent, choose your own password, then sign in.</li>
                <li><strong>Temporary password</strong> — sign in with the password you were given; you will be asked to replace it with your own before the dashboard opens.</li>
            </ol>
            <p>
                Either way you land on the <a href="<?= APP_URL ?>/dashboard.php">Dashboard</a>.
                Until everything is set up it shows a <strong>Getting started</strong> checklist —
                each step links to the page that completes it, and you can hide the card any time.
                Below it, four tiles summarise your account: connected accounts, messages this month,
                live chats waiting for a human, and upcoming appointments. If something needs
                attention — a disconnected number or a waiting customer — a banner tells you and
                links to the fix.
            </p>
            <?php helpFigure('dashboard.png',
                'Dashboard of a new account: the Getting started checklist, four stat tiles at zero and a No accounts yet empty state.',
                'The dashboard on a fresh account: getting-started checklist, live metrics and the empty state. <a href="' . APP_URL . '/dashboard.php">Open dashboard</a>'); ?>
        </section>

        <section class="help-section" id="help-link">
            <h3><span class="help-num">2</span> Link a WhatsApp account</h3>
            <p>The bot can only answer numbers you have linked. Linking one takes about a minute:</p>
            <ol class="help-steps">
                <li>Go to <a href="<?= APP_URL ?>/whatsapp/accounts.php">Accounts</a> and click <strong>Link account</strong>.</li>
                <li>Give it a label like <em>Sales</em> or <em>Support</em> (optional, helps later), then click <strong>Generate QR code</strong>.</li>
                <li>On the phone that holds that WhatsApp number, open <strong>WhatsApp → Settings → Linked Devices → Link a Device</strong> — this uses WhatsApp's own <a href="https://faq.whatsapp.com/1317564962315842/?cms_platform=android" target="_blank" rel="noopener noreferrer">linked devices</a> feature, the same way WhatsApp Web works.</li>
                <li>Point the camera at the QR code on screen — only ever scan a QR code you just generated yourself, never one someone sends you. The badge flips to <strong>Connected</strong> when it pairs.</li>
            </ol>
            <?php helpFigure('link-account.png',
                'Link a New WhatsApp Account form with an optional Account Label field and a Generate QR code button.',
                'Label the account, generate the QR code, scan it with the phone. <a href="' . APP_URL . '/whatsapp/link.php">Link an account</a>'); ?>
            <?php helpFigure('accounts.png',
                'WhatsApp Accounts page before linking: a No WhatsApp accounts linked empty state with a Link account button.',
                'Accounts before the first link. Once linked, every number appears here with its status — a dropped connection can be fixed with <strong>Re-link</strong>, which keeps your chat history. <a href="' . APP_URL . '/whatsapp/accounts.php">Open accounts</a>'); ?>
            <div class="help-note">
                <i class="bi bi-info-circle"></i>
                <div>QR links stay paired until you remove them here or unlink the device on the phone. <strong>Remove</strong> deletes the account's chats from the dashboard — use <strong>Re-link</strong> instead if you only need to reconnect. This QR pairing is separate from Meta's official Cloud API in the next section — you do not need both.</div>
            </div>
        </section>

        <section class="help-section" id="help-cloud">
            <h3><span class="help-num">3</span> Cloud API — official Meta connection <span class="help-tag">plan-gated</span></h3>
            <p>
                Instead of a QR link you can connect Meta's official <strong>WhatsApp Business
                Platform (Cloud API)</strong>. It is only available on plans that include it — if yours
                does not, the link page shows <em>Not included in your plan</em> and
                <a href="<?= APP_URL ?>/billing.php">Billing &amp; Usage</a> lists plans that do.
            </p>
            <ol class="help-steps">
                <li>In <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener noreferrer">Meta for Developers — My apps</a>, create an app with the <strong>Connect with customers through WhatsApp</strong> use case.</li>
                <li>Copy the <strong>Phone number id</strong> (under "From" on API Setup) — this is a numeric ID Meta assigns, not your public phone number — and, optionally, the <strong>WhatsApp Business Account id</strong>.</li>
                <li>In <a href="https://business.facebook.com/latest/settings" target="_blank" rel="noopener noreferrer">Meta Business settings</a>, create a <strong>System user</strong>, grant it access to the WhatsApp assets, and generate a token with the WhatsApp permissions. Use that token — the short-lived token on the API Setup page expires. Also copy your <strong>App secret</strong> (App settings → Basic) and keep it private.</li>
                <li>Paste them into <a href="<?= APP_URL ?>/whatsapp/connect-cloud.php">Connect Cloud API</a> and click <strong>Verify and connect</strong> — the details are checked with Meta before anything is saved, and the token is stored encrypted and never shown again.</li>
                <li>After the connection saves here, copy the <strong>Callback URL</strong> and <strong>Verify token</strong> it shows into your Meta app's webhook settings and subscribe to <strong>messages</strong> — replies cannot arrive until Meta has both values.</li>
            </ol>
            <div class="help-note">
                <i class="bi bi-info-circle"></i>
                <div>
                    <ul class="help-steps">
                        <li>While your Meta app is in development mode it can only message test numbers you register; reaching customers requires going live, which may involve Meta's approval and display-number requirements — check Meta's console for what yours needs.</li>
                        <li>Meta enforces a 24-hour service window: free-form replies only work within 24 hours of the customer's last message, after which approved templates are required.</li>
                        <li>Meta bills Cloud API usage separately from your plan here — non-template replies inside the 24-hour window are free, while template charges depend on category, recipient country and service-window rules; see <a href="https://developers.facebook.com/docs/whatsapp/pricing/updates-to-pricing/" target="_blank" rel="noopener noreferrer">Meta's pricing documentation</a>.</li>
                        <li>If a key is ever exposed, rotate it in Meta first, then update it here — never paste tokens into chats or emails.</li>
                    </ul>
                </div>
            </div>
            <?php helpFigure('connect-cloud.png',
                'Connect Cloud API form with fields for label, phone number id, WhatsApp Business Account id, access token and app secret.',
                'The Cloud API form — the right-hand panel explains where each value is found in Meta. <a href="' . APP_URL . '/whatsapp/connect-cloud.php">Open Cloud API setup</a>'); ?>
        </section>

        <section class="help-section" id="help-chats">
            <h3><span class="help-num">4</span> Chats &amp; contacts</h3>
            <p>
                <a href="<?= APP_URL ?>/whatsapp/chats.php">Chats</a> is a WhatsApp-style inbox for a
                linked account. Pick a conversation on the left to read or reply on the right; the
                search box filters the list. With more than one account, a selector at the top switches
                between them. Right after linking, a progress bar shows the history still syncing.
            </p>
            <?php helpFigure('chats.png',
                'Chats page before any account is linked, showing a Link account prompt.',
                'Chats asks you to link an account first; afterwards it becomes a two-pane inbox — conversations on the left, messages and a reply box on the right. <a href="' . APP_URL . '/whatsapp/chats.php">Open chats</a>'); ?>
            <p>
                <a href="<?= APP_URL ?>/whatsapp/contacts.php">Contacts</a> lists everyone who has
                messaged you, filterable by account and type (individuals or groups), with their last
                message and activity. If your plan includes CSV export, an <strong>Export CSV</strong>
                button downloads the list for your records.
            </p>
            <?php helpFigure('contacts.png',
                'Contacts page with no contacts yet, showing a Link account prompt.',
                'Contacts before the first message arrives; once populated it can be filtered by account and type. <a href="' . APP_URL . '/whatsapp/contacts.php">Open contacts</a>'); ?>
        </section>

        <section class="help-section" id="help-bot">
            <h3><span class="help-num">5</span> Bot settings</h3>
            <p>
                <a href="<?= APP_URL ?>/settings.php">Bot settings</a> is where the assistant is taught
                and tuned, across six tabs. The <strong>Bot active</strong> toggle in the header switches
                auto-replies on or off entirely. Changes apply only after you click
                <strong>Save settings</strong>.
            </p>

            <h4 class="help-sub">Knowledge base</h4>
            <ol class="help-steps">
                <li>Open the <a href="<?= APP_URL ?>/settings.php#tab-kb">Knowledge base</a> tab.</li>
                <li>Write what the bot may say in plain language — opening hours, services and prices, address, delivery areas, refund policy.</li>
                <li>Click <strong>Save settings</strong>.</li>
            </ol>
            <?php helpFigure('settings-kb.png',
                'Bot settings Knowledge base tab with an empty text area for what the bot should know about the business.',
                '<a href="' . APP_URL . '/settings.php#tab-kb">Open the Knowledge base tab</a>'); ?>

            <h4 class="help-sub">Model</h4>
            <ol class="help-steps">
                <li>Open the <a href="<?= APP_URL ?>/settings.php#tab-model">Model</a> tab and pick a model — models granted by your administrator need no key from you.</li>
                <li>If your plan allows bring-your-own keys you can instead enter your own provider, API key and the exact <strong>Model id</strong> the vendor uses; usage is then billed directly by that provider, and the key is stored securely and never shown again.</li>
                <li>Click <strong>Save settings</strong>.</li>
            </ol>
            <p>
                API keys are created on each provider's own dashboard — model IDs and billing are
                vendor-specific, and key or billing questions belong on the provider's console:
            </p>
            <ul class="help-steps">
                <?php foreach (llmProviderCatalogue() as $prov): ?>
                <li><strong><?= sanitize($prov['label']) ?></strong> — <a href="<?= sanitize($prov['console_url']) ?>" target="_blank" rel="noopener noreferrer"><?= sanitize(ltrim(parse_url($prov['console_url'], PHP_URL_HOST) . parse_url($prov['console_url'], PHP_URL_PATH), '/')) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <?php helpFigure('settings-model.png',
                'Bot settings Model tab with the model selector and the optional bring-your-own API key fields.',
                '<a href="' . APP_URL . '/settings.php#tab-model">Open the Model tab</a>'); ?>

            <h4 class="help-sub">Behaviour</h4>
            <ol class="help-steps">
                <li>Open the <a href="<?= APP_URL ?>/settings.php#tab-behaviour">Behaviour</a> tab.</li>
                <li>Set the tone, reply delay and reply length cap, and how many recent messages of context the bot reads.</li>
                <li>Write what it should say when it cannot answer, and optionally set active hours with an outside-hours message.</li>
                <li>Click <strong>Save settings</strong>.</li>
            </ol>
            <?php helpFigure('settings-behaviour.png',
                'Bot settings Behaviour tab with tone, reply delay, reply length cap, context messages, fallback and active-hours controls.',
                '<a href="' . APP_URL . '/settings.php#tab-behaviour">Open the Behaviour tab</a>'); ?>

            <h4 class="help-sub">Appointments</h4>
            <ol class="help-steps">
                <li>Open the <a href="<?= APP_URL ?>/settings.php#tab-appointments">Appointments</a> tab.</li>
                <li>Add a service first (name, description, duration) and click <strong>Save service</strong>.</li>
                <li>Set your opening hours and click <strong>Save hours</strong> — that is a separate save.</li>
                <li>Set slot length, minimum notice, daily cap, reminder timing and the confirmation wording, then click <strong>Save settings</strong>.</li>
            </ol>
            <?php helpFigure('settings-appointments.png',
                'Bot settings Appointments tab with slot length, minimum notice, daily cap, reminder timing and confirmation wording, plus services and opening hours.',
                '<a href="' . APP_URL . '/settings.php#tab-appointments">Open the Appointments tab</a>'); ?>

            <h4 class="help-sub">Handover</h4>
            <ol class="help-steps">
                <li>Open the <a href="<?= APP_URL ?>/settings.php#tab-handoff">Handover</a> tab.</li>
                <li>List the trigger phrases that should pass a chat to a human.</li>
                <li>Write what the customer is told, and optionally what they are told when you resolve the chat.</li>
                <li>Add a WhatsApp number or email to be notified, then click <strong>Save settings</strong>. Email notifications only work if your administrator has configured sending email — if unsure, use a WhatsApp number or ask them.</li>
            </ol>
            <?php helpFigure('settings-handoff.png',
                'Bot settings Handover tab with trigger phrases, customer-facing messages and WhatsApp or email notification fields.',
                '<a href="' . APP_URL . '/settings.php#tab-handoff">Open the Handover tab</a>'); ?>

            <h4 class="help-sub">Test</h4>
            <ol class="help-steps">
                <li>Open the <a href="<?= APP_URL ?>/settings.php#tab-test">Test</a> tab and click <strong>Test bot</strong>.</li>
                <li>Chat with the bot in the window that opens — a safe sandbox: nothing here reaches WhatsApp or your customers.</li>
                <li>Use the reset button to start the conversation over.</li>
            </ol>
            <?php helpFigure('settings-test.png',
                'Bot settings Test tab with a Test bot button that opens a chat preview modal.',
                '<a href="' . APP_URL . '/settings.php#tab-test">Open the Test tab</a>'); ?>
        </section>

        <section class="help-section" id="help-live">
            <h3><span class="help-num">6</span> Handover &amp; live chats</h3>
            <p>
                When a customer uses a handover phrase (or the bot decides it cannot help), the
                conversation lands in <a href="<?= APP_URL ?>/live-chats.php">Live chats</a>. The
                sidebar shows a counter whenever anyone is waiting.
            </p>
            <ol class="help-steps">
                <li>Open <a href="<?= APP_URL ?>/live-chats.php">Live chats</a> — filter by <strong>Open</strong>, <strong>Resolved</strong>, <strong>Abandoned</strong> or <strong>All</strong>.</li>
                <li>Click <strong>Claim</strong> to take a waiting conversation, or <strong>Open</strong> to view one.</li>
                <li>Reply in the message box. <strong>Internal notes</strong> are saved for your team and never sent to the customer.</li>
                <li><strong>Resolve</strong> returns the chat to the bot; <strong>Put back in the queue</strong> releases it for someone else.</li>
            </ol>
            <?php helpFigure('live-chats.png',
                'Live chats page with an empty queue and Open, Resolved, Abandoned and All view filters.',
                'The live chats queue while empty; waiting conversations appear here with <strong>Open</strong> and <strong>Claim</strong> actions. <a href="' . APP_URL . '/live-chats.php">Open live chats</a>'); ?>
        </section>

        <section class="help-section" id="help-appts">
            <h3><span class="help-num">7</span> Appointments</h3>
            <p>
                Everything the bot books lands in <a href="<?= APP_URL ?>/appointments.php">Appointments</a>,
                in your timezone.
            </p>
            <ol class="help-steps">
                <li>Check the counters, then filter the table by status or date and <strong>Filter</strong>.</li>
                <li>Add a booking yourself with <strong>Add manually</strong> — service, time, customer and phone.</li>
                <li>Reschedule with <strong>Move an appointment</strong>; use each row's menu to mark it completed, cancelled or a no-show.</li>
                <li><strong>Tell customer</strong> on a booking sends the update over WhatsApp.</li>
            </ol>
            <?php helpFigure('appointments.png',
                'Appointments page with zero counters, an empty bookings table and the Add manually form.',
                'Appointments with nothing booked yet — the table fills in as the bot (or you, via Add manually) creates bookings. <a href="' . APP_URL . '/appointments.php">Open appointments</a>'); ?>
        </section>

        <section class="help-section" id="help-billing">
            <h3><span class="help-num">8</span> Billing &amp; usage</h3>
            <ol class="help-steps">
                <li>Open <a href="<?= APP_URL ?>/billing.php">Billing &amp; Usage</a> — the first card shows your current plan and renewal.</li>
                <li>Check <strong>Usage this month</strong>: messages, AI replies and accounts against your plan's limits.</li>
                <li>If shown for your account, follow the <strong>How to pay</strong> instructions exactly as your administrator wrote them — payments are made manually, there is no checkout here. <strong>Payment history</strong> lists the payments logged on your account; it is not a source of invoices or receipts.</li>
                <li>Compare the available plans below. If an upgrade is offered, its contact button opens the email, WhatsApp or phone channel your administrator configured — write and send the request yourself; the administrator then applies the plan change manually. Any quota wall or locked feature elsewhere links here.</li>
            </ol>
            <?php helpFigure('billing.png',
                'Billing and Usage page for a demo tenant with a Pro plan card, zero usage meters and no available upgrade.',
                'See your current plan and monthly usage. Payment information appears here when available. <a href="' . APP_URL . '/billing.php">Open billing</a>'); ?>
        </section>

        <section class="help-section" id="help-profile">
            <h3><span class="help-num">9</span> Profile</h3>
            <ol class="help-steps">
                <li>Open the avatar menu (top right) and choose <a href="<?= APP_URL ?>/profile.php">Profile</a>.</li>
                <li>Update your name, email, company details and timezone — the company name is what the bot uses as its identity, and the timezone drives appointment times and active hours.</li>
                <li>Click <strong>Save changes</strong>.</li>
                <li>To change your password, use the separate <strong>Change Password</strong> card — it asks for your current password.</li>
            </ol>
            <?php helpFigure('profile.png',
                'Profile page with the contact card, profile information form including timezone, and the change password form.',
                'Personal details, timezone and password. <a href="' . APP_URL . '/profile.php">Open profile</a>'); ?>
        </section>

        <section class="help-section" id="help-trouble">
            <h3><i class="bi bi-wrench-adjustable-circle me-1"></i> Troubleshooting</h3>
            <ul class="help-trouble">
                <li><strong>The QR code will not scan or expired</strong> — QR codes rotate; reload the link page for a fresh one, and make sure the phone has internet.</li>
                <li><strong>Account shows disconnected</strong> — open <a href="<?= APP_URL ?>/whatsapp/accounts.php">Accounts</a>, click <strong>Sync all</strong>; if it stays down use <strong>Re-link</strong> (keeps history) rather than Remove.</li>
                <li><strong>The bot stopped replying</strong> — check the <strong>Bot active</strong> toggle on <a href="<?= APP_URL ?>/settings.php">Bot settings</a>, and that the account is still connected.</li>
                <li><strong>The bot gives wrong answers</strong> — update the <a href="<?= APP_URL ?>/settings.php#tab-kb">Knowledge base</a>, save, then check it in the <a href="<?= APP_URL ?>/settings.php#tab-test">Test</a> tab.</li>
                <li><strong>Customers ask for a person and nothing happens</strong> — add that phrase under <a href="<?= APP_URL ?>/settings.php#tab-handoff">Handover triggers</a> and set a notification number or email.</li>
                <li><strong>A feature says "not included in your plan"</strong> — it is plan-gated; <a href="<?= APP_URL ?>/billing.php">Billing &amp; Usage</a> shows which plans include it.</li>
                <li><strong>No models are available in the Model tab</strong> — your administrator grants models to your plan; ask them to enable one for you.</li>
                <li><strong>Cloud API connection fails to verify or messages do not arrive</strong> — check the token, phone number id and webhook values in your <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener noreferrer">Meta app</a> first, then correct them on the <a href="<?= APP_URL ?>/whatsapp/connect-cloud.php">Connect Cloud API</a> form.</li>
                <li><strong>Handover emails are not arriving</strong> — email sending depends on the administrator's mail setup; switch the notification to a WhatsApp number or ask them to check it.</li>
            </ul>
        </section>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
