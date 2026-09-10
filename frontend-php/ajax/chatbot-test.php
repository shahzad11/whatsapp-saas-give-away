<?php
// Tenant test console. Runs the real reply pipeline against the tenant's saved
// configuration and returns what a customer would actually receive — but sends
// nothing to WhatsApp, and writes nothing, so a test can never reach a customer
// or leave a trace in anyone's calendar.
//
// It shares chatbotGenerateReply() *and now chatbotBuildContext()* with the live
// path. Sharing only the former was the bug behind #34: the pipeline was real,
// the context handed to it was not, so the console answered from a prompt that
// never mentioned the business's services, hours or bookings. A preview built
// from a second, simpler code path is worse than no preview, because it is
// believed.
//
// The rule this file is written to: **a test must have no side effects, even
// correct ones.** Everywhere the live path would *do* something — open a
// handover, page a colleague, write an appointment — this reports what would
// have happened and does nothing.
require_once dirname(__DIR__) . '/config/init.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (!csrfTokenValid($input['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid request. Reload the page.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$plan = getUserPlan($conn, $userId);
if (!planHasFeature($plan, 'chatbot')) {
    echo json_encode(['ok' => false, 'error' => 'The chatbot is not part of your plan.']);
    exit;
}

$message = trim((string)($input['message'] ?? ''));
if ($message === '') {
    echo json_encode(['ok' => false, 'error' => 'Type a message to test with.']);
    exit;
}
if (mb_strlen($message) > 2000) {
    echo json_encode(['ok' => false, 'error' => 'That test message is too long.']);
    exit;
}

// The plan levers are applied to the saved config, exactly as the live handler
// does it. Without this the console could show a handover section in the system
// prompt for a plan that cannot hand over — a prompt difference with no
// counterpart in production, which is the whole class of bug #34 is about.
$config = chatbotEffectiveConfig($conn, chatbotConfig($conn, $userId), $plan);

// --- The conversation so far -------------------------------------------------
//
// Sent by the browser, because the console has no WhatsApp thread to read from.
// That makes it untrusted input. Not a privilege problem — it is the tenant's
// own bot, their own prompt and their own allowance — but it is a *cost*
// problem: an unbounded history is an unbounded prompt. So it is clamped here,
// on three axes, rather than merely sliced later by chatbotBuildMessages().
//
// Shaped as the backend's own message format (fromMe / text) so it goes through
// the identical code path as a real thread's history.
const TEST_HISTORY_MAX_TURNS = 40;
const TEST_HISTORY_MAX_CHARS = 12000;

$history = [];
$historyChars = 0;
foreach (array_slice((array)($input['history'] ?? []), -TEST_HISTORY_MAX_TURNS) as $turn) {
    if (!is_array($turn)) continue;
    $text = trim((string)($turn['text'] ?? ''));
    if ($text === '') continue;
    $text = mb_substr($text, 0, 2000);

    $historyChars += mb_strlen($text);
    if ($historyChars > TEST_HISTORY_MAX_CHARS) break;

    $history[] = ['fromMe' => !empty($turn['fromMe']), 'text' => $text];
}

// --- Everything the live path checks before it spends money ------------------
//
// $notices collects every way this console differs from WhatsApp *right now*.
// The tenant is configuring a bot; "the answer you just saw would not have been
// sent at all" is the most useful thing this endpoint can tell them, and it was
// telling them none of it.
//
// Each notice carries a `scope`, and it matters:
//
//   'config' — a standing fact about the configuration. True of every message in
//              the conversation, so the page shows it **once**, pinned. The first
//              version tagged nothing, so "automatic replies are switched off"
//              was appended after every single turn and the transcript became a
//              column of identical yellow bars with the actual conversation
//              buried between them.
//   'turn'   — caused by the message just sent. Belongs next to that message and
//              nowhere else.
$notices = [];
$note = function ($scope, $level, $text) use (&$notices) {
    $notices[] = ['scope' => $scope, 'level' => $level, 'text' => $text];
};

// Not a refusal. A tenant switching the bot on for the first time will test it
// before flipping the switch, and refusing to answer until it is enabled makes
// the tool useless exactly when it is needed.
if (empty($config['is_enabled'])) {
    $note('config', 'warning',
        'Automatic replies are switched off, so a real customer message would get no answer at all.');
}

$timezone = getUserTimezone($conn, $userId);
if (!chatbotWithinHours($config, $timezone)) {
    $outside = trim((string)($config['outside_hours_message'] ?? ''));
    $note('config', 'warning',
        'It is outside your active hours. A real message right now would get '
        . ($outside !== '' ? 'your out-of-hours message, not an AI reply.' : 'no reply at all.'));
}

// Both allowances, in the live path's order and for the live path's reasons: a
// test costs a model call, and testing must not be a way to spend the
// platform's money after the tenant's allowance is gone.
[$quotaOk, , $limit] = checkMessageQuota($conn, $userId);
if (!$quotaOk) {
    echo json_encode(['ok' => false, 'error' => 'Monthly message limit reached (' . number_format($limit) . ').']);
    exit;
}

// This one the console skipped entirely, so a tenant out of AI replies could
// still burn tokens from the test tab — the one limit that protects a real
// per-token bill was the one the console did not honour.
[$replyQuotaOk, , $replyLimit] = checkChatbotReplyQuota($conn, $userId, $config);
if (!$replyQuotaOk) {
    echo json_encode([
        'ok' => false,
        'error' => 'Monthly AI reply limit reached (' . number_format($replyLimit) . ').',
    ]);
    exit;
}

// --- Would this have been handed to a person instead? ------------------------
//
// On WhatsApp a matched phrase means the model is never called: the customer
// gets the acknowledgement and a colleague gets paged. So the console must show
// the acknowledgement, not a model reply — showing a model answer here would be
// the same lie in a new place.
//
// And it must page nobody. chatbotStartHandoff() opens a row, sends a WhatsApp
// message and notifies staff by SMS or email. A tenant typing "can I talk to a
// human" into their own test console must not summon their team, so none of it
// is called; the acknowledgement text is composed the same way it composes it.
$phrase = handoffPhraseMatch($config, $message);
if ($phrase !== null) {
    $ack = trim((string)($config['handoff_ack_message'] ?? ''));
    if ($ack === '') $ack = "Thanks — I'm passing you to a member of our team. They'll reply here shortly.";
    $share = handoffShareLine($conn, $config);
    if ($share !== '') $ack = trim($ack . "\n\n" . $share);

    chatbotLogEvent($conn, $userId, 'handoff', ['detail' => 'test console: matched "' . $phrase . '"']);

    $note('turn', 'info',
        'That matched your handover phrase "' . $phrase . '", so the AI was not asked. '
        . 'On WhatsApp this would open a handover and notify you — nothing was opened or sent here.');

    echo json_encode([
        'ok' => true,
        'kind' => 'handoff',
        'reply' => $ack,
        'model' => '',
        'latency_ms' => 0,
        'tokens' => 0,
        'notices' => $notices,
    ]);
    exit;
}

// --- The reply ---------------------------------------------------------------

// One assembly point, shared with the live handler (#34). $chatId is null: the
// console is not a WhatsApp chat, which means no "this customer already has a
// booking" line — correct, because that is what a new customer's first message
// sees.
$context = chatbotBuildContext($conn, $userId, $config, $timezone, null);

// Why the bot will say it cannot book.
//
// This is the notice that was missing, and its absence was the whole of the
// "I asked it to book an appointment and it refused" report. When the
// appointment context is null the prompt never mentions booking, so the model
// answers — correctly and unhelpfully — that it cannot book, and the tester has
// no way to tell that from a broken model.
//
// The reasons are checked in the same order chatbotAppointmentContext() checks
// them, because the first one that fails is the only one worth reporting. Only
// for a plan that includes appointments: on a plan without it, "booking is off"
// is not a misconfiguration, it is the plan.
if ($context['appointments'] === null && planHasFeature($plan, 'appointments')) {
    if (empty($config['appointments_enabled'])) {
        $note('config', 'warning',
            'This bot cannot book anything: appointment booking is switched off, so the prompt never '
            . 'mentions it. Turn it on under Settings → Appointments.');
    } elseif (!apptServices($conn, $userId, true)) {
        $note('config', 'warning',
            'This bot cannot book anything: booking is on but there is no active service to book.');
    }
} elseif ($context['appointments'] !== null && empty($context['appointments']['availability'])) {
    // The subtler one. Here booking *is* described to the model, and then the
    // prompt tells it there are no opening hours and it must offer a callback —
    // which looks identical to the cases above and has a different fix.
    $note('config', 'warning',
        'Booking is on, but no opening hours are saved, so the bot is told it cannot take a booking '
        . 'and should offer a callback instead. Set them under Settings → Appointments.');
} elseif ($context['appointments'] !== null && empty($context['appointments']['slots'])) {
    // #35: hours are set and the bot knows about booking, but nothing inside the
    // horizon is actually free — a full diary, or a minimum notice and horizon
    // that between them leave no room. The bot will correctly decline to offer a
    // time, which is indistinguishable from a broken bot without this line.
    $note('config', 'warning',
        'Booking is on and your opening hours are saved, but there is no free slot between now and '
        . 'the end of your booking window, so the bot has nothing to offer. Check the diary, the '
        . 'minimum notice and how far ahead you take bookings.');
}

$result = chatbotGenerateReply($conn, $userId, $config, $history, $message, $context);

$modelLabel = '';
if (!empty($result['model_id'])) {
    $model = llmModelById($conn, (int)$result['model_id']);
    $modelLabel = $model ? $model['provider_label'] . ' — ' . $model['label'] : '';
} elseif (!empty($config['byo_provider_code'])) {
    $modelLabel = llmProviderLabel($config['byo_provider_code']) . ' — ' . ($config['byo_model_code'] ?? '');
}

chatbotLogEvent($conn, $userId, $result['ok'] ? 'replied' : ($result['reason'] ?? 'llm_error'), [
    'detail' => $result['ok'] ? 'test console' : 'test console: ' . ($result['error'] ?? ''),
    'model_id' => $result['model_id'] ?? null,
    'prompt_tokens' => $result['usage']['prompt'] ?? null,
    'completion_tokens' => $result['usage']['completion'] ?? null,
    'latency_ms' => $result['latency_ms'] ?? null,
]);

if (!$result['ok']) {
    // The tenant owns this configuration, so they see the real reason — unlike a
    // customer, who only ever sees the fallback message.
    echo json_encode(['ok' => false, 'error' => $result['error'] ?: 'The model did not reply.']);
    exit;
}

// The counter this endpoint always claimed to honour.
//
// It checked both allowances and incremented neither, so "testing must not be a
// way to spend the platform's money for free" — the comment that has been at the
// top of this file since it was written — was not actually true of the code
// under it. A tenant who had sent nothing could run an unbounded number of paid
// model calls from this tab, because the gate was reading a counter that testing
// never moved.
//
// Only `chatbot_replies`, and deliberately not `messages_sent`: the AI-reply
// counter exists to bound per-token vendor spend, which a test genuinely incurs,
// while `messages_sent` counts messages that reached a customer, and this one
// did not. Charged after the call succeeds, matching the live path — a model
// that errored cost nothing to bill for.
incrementUsage($conn, $userId, 'chatbot_replies');

// --- The action line, previewed and not carried out --------------------------
//
// The model may end its reply with a machine-readable booking or handover line.
// The console used to print the raw text, so a tenant testing "can I book
// Thursday at 3?" was shown `<<<APPT {"action":"book",…} >>>` — the protocol the
// customer is never meant to see. Stripping it is what the live path does before
// anything is sent.
//
// What the live path does *next* is carry the action out. Here it is only
// described. The booking case is checked against the real calendar with
// apptValidateSlot(), which is read-only, so the tenant learns whether the slot
// would have been accepted or refused — and why — without an appointment
// appearing in their diary because they were trying the bot out.
[$replyText, $action] = chatbotExtractAction($result['text']);
$appointments = $context['appointments'];

if ($action !== null) {
    $kind = (string)($action['action'] ?? '');

    if ($kind === 'handoff') {
        $note('turn', 'info',
            'The assistant offered to pass the customer to a person. On WhatsApp this would '
            . 'open a handover and notify you — nothing was opened or sent here.');
    } elseif ($appointments === null) {
        // The prompt never mentioned booking, so the model invented the marker.
        // The live path drops it silently; the tenant should know it happened,
        // because it usually means the knowledge base is describing a service
        // the appointment feature is not switched on for.
        $note('turn', 'warning',
            'The assistant tried to book something, but appointments are not switched on, '
            . 'so nothing would have happened on WhatsApp either.');
    } elseif ($kind === 'book') {
        $service = apptMatchService(apptServices($conn, $userId, true), $action['service'] ?? '');
        if (!$service) {
            $note('turn', 'warning',
                'The assistant proposed a service name that does not match any of yours ('
                . (string)($action['service'] ?? '—') . '), so the booking would have been refused.');
        } else {
            [$utc, $why] = apptValidateSlot(
                $conn, $userId, $config, $service,
                $action['datetime'] ?? '', $appointments['timezone']
            );
            $note('turn', $utc ? 'success' : 'warning', $utc
                ? 'The assistant proposed ' . $service['name'] . ' at '
                  . (string)($action['datetime'] ?? '') . ' (' . $appointments['timezone'] . '). '
                  . 'That slot is free, so a real message would have booked it. Nothing was booked here.'
                : 'The assistant proposed ' . $service['name'] . ' at '
                  . (string)($action['datetime'] ?? '') . ', which your calendar would refuse: ' . $why);
        }
    } else {
        // reschedule / cancel both need an existing booking, which a console
        // session by definition does not have.
        $note('turn', 'info',
            'The assistant tried to ' . $kind . ' a booking. The test console is not a real '
            . 'customer, so it has no existing appointment to act on.');
    }
}

// Mirrors the live path's last resort: a reply that is nothing but an action line
// leaves no words for the customer.
if (trim($replyText) === '') {
    $replyText = trim((string)($config['fallback_message'] ?? 'Thanks — someone will follow up.'));
}

echo json_encode([
    'ok' => true,
    'kind' => 'reply',
    'reply' => $replyText,
    'model' => $modelLabel,
    'latency_ms' => $result['latency_ms'] ?? 0,
    'tokens' => ($result['usage']['prompt'] ?? 0) + ($result['usage']['completion'] ?? 0),
    'notices' => $notices,
]);
