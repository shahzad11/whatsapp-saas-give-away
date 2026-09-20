<?php
require_once __DIR__ . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$user = getCurrentUser();
$tz = getUserTimezone($conn, $userId);
$plan = getUserPlan($conn, $userId);
$plans = getActivePlans($conn);
$profile = getUserProfile($conn, $userId);

$accountsUsed = countWaAccounts($conn, $userId);
$contactsUsed = countContacts($conn, $userId);
$messagesUsed = usageCount($conn, $userId, 'messages_sent');

$accountsLimit = planLimit($plan, 'max_wa_accounts');
$contactsLimit = planLimit($plan, 'max_contacts');
$messagesLimit = planLimit($plan, 'max_messages_per_month');

// The two new metered levers. AI replies are the one worth watching: they are the
// only usage that costs per token, and they are bounded separately from messages.
$hasChatbot = planHasFeature($plan, 'chatbot');
$hasAppointments = $hasChatbot && planHasFeature($plan, 'appointments');
$repliesUsed = usageCount($conn, $userId, 'chatbot_replies');
$repliesLimit = planLimit($plan, 'max_chatbot_replies');
$servicesUsed = countServices($conn, $userId);
$servicesLimit = planLimit($plan, 'max_services');

$stmt = $conn->prepare(
    "SELECT status, current_period_end FROM subscriptions
     WHERE user_id = ? ORDER BY id DESC LIMIT 1"
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$subscription = $stmt->get_result()->fetch_assoc();
$stmt->close();

$payments = getPayments($conn, $userId, 24);
$instructions = paymentInstructions($conn);

// #43: the contact route the admin configured, resolved once for the whole page
// — the plan cards below build a link per plan from it, and re-reading the
// settings inside that loop would be a query per card.
$contactConfig = billingContactConfig($conn);
// Who is asking. Both halves are optional, so an enquiry from a tenant who never
// filled in a profile still identifies them by the address they log in with.
$contactWho = [
    'tenant' => tenantDisplayName($user, $profile),
    'email'  => (string)($user['email'] ?? ''),
];

// The furthest date any logged payment covers.
$paidUntil = null;
foreach ($payments as $p) {
    if ($p['period_end'] && ($paidUntil === null || $p['period_end'] > $paidUntil)) {
        $paidUntil = $p['period_end'];
    }
}

// A null limit is unlimited, which has no meaningful percentage.
function usagePercent($used, $limit) {
    if ($limit === null || $limit <= 0) return 0;
    return min(100, (int)round(($used / $limit) * 100));
}

function usageBarClass($percent) {
    if ($percent >= 90) return 'bg-danger';
    if ($percent >= 70) return 'bg-warning';
    return 'bg-success';
}

$pageTitle = 'Billing & Usage';
require_once __DIR__ . '/includes/header.php';
?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-award"></i>Current plan</div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h4 class="mb-1"><?= sanitize($plan['name'] ?? 'None') ?></h4>
                        <p class="text-muted small mb-0"><?= sanitize($plan['description'] ?? '') ?></p>
                    </div>
                    <span class="badge bg-primary fs-6"><?= sanitize(formatPrice($plan ?: [])) ?></span>
                </div>

                <?php if ($subscription): ?>
                    <ul class="list-unstyled small text-muted mb-0">
                        <li><strong>Status:</strong> <?= sanitize(ucfirst($subscription['status'])) ?></li>
                        <?php if ($subscription['current_period_end']): ?>
                            <li><strong>Renews:</strong> <?= sanitize(formatUserDate($subscription['current_period_end'], $tz)) ?></li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>

                <hr>
                <div class="small">
                    <div class="text-muted mb-1">Billed to</div>
                    <div class="fw-500"><?= sanitize(tenantDisplayName($user, $profile)) ?></div>
                    <?php $lines = addressLines($profile); ?>
                    <?php if ($lines): ?>
                        <div class="text-muted"><?= implode('<br>', array_map('sanitize', $lines)) ?></div>
                    <?php else: ?>
                        <?php // Nudge rather than block: every profile field is optional. ?>
                        <div class="text-muted">
                            No billing address on file.
                            <a href="<?= APP_URL ?>/profile.php">Add one</a>.
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($paidUntil): ?>
                    <hr>
                    <div class="small">
                        <div class="text-muted mb-1">Paid through</div>
                        <?php // payments.period_end is a DATE — a calendar date, not
                              // an instant — so it is rendered as stored. Converting
                              // it to the tenant's zone would move a billing period
                              // onto the wrong day. ?>
                        <div class="fw-500"><?= sanitize(date('M j, Y', strtotime($paidUntil))) ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-speedometer2"></i>Usage this month</div>
            <div class="card-body">
                <?php
                $meters = [
                    ['WhatsApp Accounts', $accountsUsed, $accountsLimit],
                    ['Contacts',          $contactsUsed, $contactsLimit],
                    ['Messages Sent',     $messagesUsed, $messagesLimit],
                ];
                // Only shown to tenants whose plan includes the chatbot. On a plan
                // without it the meter would always read 0 of 0, which invites the
                // question "why is this here" rather than answering one.
                if ($hasChatbot) {
                    $meters[] = ['AI Replies', $repliesUsed, $repliesLimit];
                }
                if ($hasAppointments) {
                    $meters[] = ['Bookable Services', $servicesUsed, $servicesLimit];
                }
                foreach ($meters as [$label, $used, $limit]):
                    $pct = usagePercent($used, $limit);
                ?>
                <div class="mb-4">
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="fw-500"><?= sanitize($label) ?></span>
                        <span class="text-muted"><?= number_format($used) ?> / <?= sanitize(formatLimit($limit)) ?></span>
                    </div>
                    <?php // An unlimited allowance has no denominator, so it gets no bar.
                          // The old code drew one at a hard-coded 4% width, which reads as
                          // "almost nothing used out of something" — the opposite of what
                          // unlimited means, and it moved for no reason anyone could act on. ?>
                    <?php if ($limit === null): ?>
                        <div class="x-small text-muted">No limit on your plan.</div>
                    <?php else: ?>
                        <div class="progress" style="height:8px;">
                            <div class="progress-bar <?= usageBarClass($pct) ?>" style="width: <?= $pct ?>%"></div>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($instructions !== '' || $payments): ?>
<div class="row g-4 mt-1">
    <?php if ($instructions !== ''): ?>
    <div class="col-lg-5" id="howToPay">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-bank"></i>How to pay</div>
            <div class="card-body">
                <?php // Admin-authored plain text. nl2br over an escaped string, never
                      // raw HTML — an admin is trusted, but a stored-XSS foothold in a
                      // field every tenant renders is not a risk worth taking. ?>
                <div class="small text-muted"><?= nl2br(sanitize($instructions)) ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($payments): ?>
    <div class="col-lg-<?= $instructions !== '' ? '7' : '12' ?>">
        <div class="card table-card h-100">
            <div class="card-header"><i class="bi bi-receipt"></i>Payment history</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 table-stack">
                    <thead><tr><th>Date</th><th class="num">Amount</th><th>Plan</th><th>Period</th><th>Method</th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td class="small text-muted" data-label="Date"><?= sanitize(formatUserDate($p['created_at'], $tz)) ?></td>
                            <td class="small fw-500 num" data-label="Amount"><?= sanitize(formatMoney((int)$p['amount_minor'], $p['currency'])) ?></td>
                            <td class="small" data-label="Plan"><?= sanitize($p['plan_name'] ?? '—') ?></td>
                            <?php // Both are DATE columns, so no timezone conversion —
                                  // see "Paid through" above. ?>
                            <td class="small text-muted" data-label="Period">
                                <?= $p['period_start'] ? sanitize(date('M j', strtotime($p['period_start']))) . ' – ' . sanitize(date('M j, Y', strtotime($p['period_end']))) : '—' ?>
                            </td>
                            <td class="small" data-label="Method"><?= sanitize(paymentMethodLabel($p['method'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<h5 class="mt-5 mb-3">Available Plans</h5>
<?php
// On a paid plan, cheaper plans are a downgrade — and with no contact route a
// downgrade is a dead end, so they are not offered.
$currentPrice = (int)($plan['price_cents'] ?? 0);
$shownPlans = $plan && $currentPrice > 0
    ? array_values(array_filter($plans, fn($p) => (int)($p['price_cents'] ?? 0) >= $currentPrice))
    : $plans;
?>
<?php if (count($shownPlans) <= 1): ?>
    <p class="text-muted small">You are on the highest plan.</p>
<?php else: ?>
<div class="row g-4">
    <?php foreach ($shownPlans as $p): ?>
    <div class="col-md-4">
        <div class="card h-100 <?= ($plan && $p['id'] == $plan['id']) ? 'border-primary' : '' ?>">
            <div class="card-body d-flex flex-column">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="mb-0"><?= sanitize($p['name']) ?></h5>
                    <?php if ($plan && $p['id'] == $plan['id']): ?>
                        <span class="badge bg-primary">Current</span>
                    <?php endif; ?>
                </div>
                <div class="h3 mb-3"><?= sanitize(formatPrice($p)) ?></div>
                <p class="text-muted small"><?= sanitize($p['description']) ?></p>
                <ul class="list-unstyled small mb-4">
                    <li><i class="bi bi-check2 text-success me-2"></i><?= sanitize(formatLimit(planLimit($p, 'max_wa_accounts'))) ?> WhatsApp account(s)</li>
                    <li><i class="bi bi-check2 text-success me-2"></i><?= sanitize(formatLimit(planLimit($p, 'max_contacts'))) ?> contacts</li>
                    <li><i class="bi bi-check2 text-success me-2"></i><?= sanitize(formatLimit(planLimit($p, 'max_messages_per_month'))) ?> messages / month</li>
                    <?php // Only the AI-reply allowance is listed as a limit, and only when
                          // the plan can actually use it: an allowance on a plan with no
                          // chatbot is noise. Services are left out — a per-plan service
                          // count is not what anyone chooses a plan on. ?>
                    <?php if (planHasFeature($p, 'chatbot')): ?>
                        <li><i class="bi bi-check2 text-success me-2"></i><?= sanitize(formatLimit(planLimit($p, 'max_chatbot_replies'))) ?> AI replies / month</li>
                    <?php endif; ?>
                    <?php // Features, drawn from the same definition map the admin edits, so a
                          // new lever appears here without touching this template. Withheld
                          // ones are shown greyed rather than omitted: "what am I missing" is
                          // the question this card exists to answer. ?>
                    <?php foreach (planFeatureDefinitions() as $fKey => $fDef):
                        $on = planHasFeature($p, $fKey); ?>
                        <li class="<?= $on ? '' : 'text-muted' ?>">
                            <i class="bi <?= $on ? 'bi-check2 text-success' : 'bi-dash text-muted' ?> me-2"></i><?= sanitize($fDef['label']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="mt-auto">
                    <?php if ($plan && $p['id'] == $plan['id']): ?>
                        <button class="btn btn-outline-secondary w-100" disabled>Current Plan</button>
                    <?php else: ?>
                        <?php
                        // Billing is manual by design — no gateway, so no checkout
                        // button. What replaces it is the contact route the admin
                        // configured (#43), with this plan's name already in the
                        // subject or the prefilled message.
                        //
                        // The links are built per plan, not once: each one carries a
                        // different plan name. They are read from $contactConfig,
                        // which was resolved once above, so this loop costs no
                        // queries.
                        $links = billingContactLinks($conn, $p, $contactWho, $contactConfig);
                        ?>
                        <?php if ($links): ?>
                            <?php // The preferred method is the filled button. Any others are
                                  // outlined and share a row, so three methods do not turn a
                                  // plan card into a column of buttons. ?>
                            <a href="<?= sanitize($links[0]['url']) ?>" class="btn btn-primary w-100"
                               <?= $links[0]['method'] === 'whatsapp' ? 'target="_blank" rel="noopener"' : '' ?>>
                                <i class="bi <?= sanitize($links[0]['icon']) ?> me-1"></i><?= sanitize($contactConfig['label']) ?>
                            </a>
                            <?php if (count($links) > 1): ?>
                                <div class="d-flex gap-2 mt-2">
                                    <?php foreach (array_slice($links, 1) as $link): ?>
                                        <a href="<?= sanitize($link['url']) ?>" class="btn btn-outline-secondary btn-sm flex-fill"
                                           <?= $link['method'] === 'whatsapp' ? 'target="_blank" rel="noopener"' : '' ?>>
                                            <i class="bi <?= sanitize($link['icon']) ?> me-1"></i><?= sanitize($link['label']) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($instructions !== ''): ?>
                                <?php // The instructions stay reachable, as the step after
                                      // asking rather than instead of it: they say how to pay
                                      // for a plan, not how to be moved onto one. ?>
                                <div class="x-small text-muted text-center mt-2">
                                    Already agreed? <a href="#howToPay">See how to pay</a>.
                                </div>
                            <?php endif; ?>
                        <?php elseif ($instructions !== ''): ?>
                            <a href="#howToPay" class="btn btn-primary w-100">See how to pay</a>
                        <?php else: ?>
                            <?php // Nothing configured, so there is nothing to link to. A
                                  // button that opens a blank mail window (or the old
                                  // fallback: the instance's no-reply SMTP sender) is worse
                                  // than a sentence that tells the truth. ?>
                            <span class="text-muted small d-block text-center">
                                Ask your administrator to switch you
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
