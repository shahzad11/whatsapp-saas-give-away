<?php
// The booking-claim guard and the reply-delay setting, without a database
// (#46, #51).
//
// Run with:  php tests/chatbot-guard-test.php
//
// #46 is the "the bot said confirmed and nothing was booked" incident: the
// model claimed a booking in plain prose without emitting the action line, and
// answered "is it booked?" from chat history with a weekday/date pair that did
// not agree with itself. The helpers under test are deliberately pure — they
// judge text, and they answer from a row passed in — so the failure mode they
// exist to catch can be replayed here as data.
//
// No framework and no database, like appointments-test.php.

require_once __DIR__ . '/../frontend-php/includes/functions.php';
require_once __DIR__ . '/../frontend-php/includes/appointments.php';
require_once __DIR__ . '/../frontend-php/includes/chatbot.php';

$passed = 0;
$failed = 0;

function check($name, $condition, $detail = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   {$name}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function equals($name, $expected, $actual) {
    check($name, $expected === $actual,
        'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function group($title) {
    echo "\n{$title}\n";
}

// --- #46: does the prose claim a booking exists? -----------------------------

group('A sentence that asserts a booking is a claim');

foreach ([
    'Done! Your appointment is confirmed.',
    "I've booked you in for 11:00.",
    "You're all set for Saturday.",
    'Your booking is confirmed for Sat 11 Sep 2026 at 11:00.',
    'Great choice — I have scheduled it for you. It is booked.',
    'Your slot is reserved.',
    'Done',
    'All done',
    'All set',
] as $text) {
    check("claims: {$text}", chatbotClaimsBooking($text));
}

group('Negations, conditions and future promises are not claims');

foreach ([
    'Your appointment is not yet booked.',
    'Once you confirm, I will book it.',
    'Shall I book that for you?',
    'Please confirm the time.',
    "I'll confirm your appointment for 11:00 — one moment please.",
    'I will confirm once the system answers.',
    'We cannot book that day.',
    "It isn't confirmed yet.",
    'Would you like me to book it?',
    // A bare "done" mid-sentence is ordinary prose, not a claim.
    'The consultation is done in person.',
    'Well done, see you then.',
] as $text) {
    check("not a claim: {$text}", !chatbotClaimsBooking($text));
}

// --- #46: is the customer asking whether a booking exists? -------------------

group('A status question is recognised');

foreach ([
    'is my appointment booked?',
    'did you book it',
    'can you check the system',
    'is it confirmed?',
    'have you booked my slot',
    'when is my appointment?',
] as $text) {
    check("asks: {$text}", chatbotAsksBookingStatus($text));
}

group('Agreement is not a status question');

foreach ([
    'confirm it',
    'yes please confirm',
    'book it for 11',
    // An availability question, not a status question.
    'can you check the calendar for Monday',
] as $text) {
    check("not a status question: {$text}", !chatbotAsksBookingStatus($text));
}

// --- #46: the claim sentences go, the rest stays ------------------------------

group('Stripping keeps the honest prose');

$stripped = chatbotStripBookingClaims('Great choice! Done! Your appointment is confirmed. See you then.');
check('keeps the greeting', str_contains($stripped, 'Great choice!'));
check('keeps the sign-off', str_contains($stripped, 'See you then.'));
check('drops the claim', !str_contains($stripped, 'confirmed'));

// --- #46: the line the truth check appends ------------------------------------

group('The truth line reads the booking, or says there is none');

$row = ['service_name' => 'Consultation', 'scheduled_at' => '2026-09-12 06:00:00'];
$line = chatbotBookingTruthLine($row, 'Asia/Karachi', []);
check('names the service', str_contains($line, 'Consultation'));
// 06:00 UTC is 11:00 in Karachi, and 12 Sep 2026 is a Saturday — the pair is
// generated from one timestamp, so it cannot disagree with itself the way the
// model's did.
check('weekday and date come from the stored instant',
    str_contains($line, 'Sat 12 Sep 2026, 11:00'), $line);

check('no booking says so plainly',
    str_contains(chatbotBookingTruthLine(null, 'Asia/Karachi', []), 'Nothing is booked yet'));

// --- #46: the live repro, replayed -------------------------------------------
//
// The sequence that produced the incident: the model wrote "I'll confirm your
// appointment …" (a hedge, not a claim) and then "Done! Your appointment is
// confirmed." (a claim), with no action line and no row in the diary. What the
// customer must now receive is the corrected composition — claim sentences
// stripped, truth appended — built from the same helpers the handler calls.

group('The live repro ends with the truth, not the claim');

$modelProse = "I'll confirm your appointment for Saturday at 11:00 — one moment please. "
    . 'Done! Your appointment is confirmed.';
// What the handler does when nothing was applied and no row exists.
$final = trim(chatbotStripBookingClaims($modelProse)
    . "\n\n" . chatbotBookingTruthLine(null, 'Asia/Karachi', []));

check('the customer is told nothing was booked', str_contains($final, 'Nothing is booked yet'));
check('and is never told it was confirmed', !str_contains($final, 'is confirmed'));
check('the hedged sentence is kept — it was true', str_contains($final, 'one moment please'));

// --- #51: the reply-delay choices ---------------------------------------------

group('The delay dropdown offers the documented range');

$choices = chatbotReplyDelayChoices();
check('the default is one of the choices', isset($choices[300]));
equals('the shortest wait is one second', 1, array_key_first($choices));
equals('the longest is an hour', 3600, array_key_last($choices));

group('A stored value is normalised, never trusted');

equals('the default passes through', 300, chatbotNormaliseReplyDelay(300));
equals('as a string too', 60, chatbotNormaliseReplyDelay('60'));
equals('a value the list does not offer becomes the default', 300, chatbotNormaliseReplyDelay(42));
equals('zero is not a way to disable it through a crafted POST', 300, chatbotNormaliseReplyDelay(0));
equals('nor is a missing field', 300, chatbotNormaliseReplyDelay(null));
equals('and something huge is not a way to park replies for days', 300, chatbotNormaliseReplyDelay(999999));

// --- #9: the loop guard -------------------------------------------------------

group('The reply window caps how much the bot can say');

$now = time();
$utc = fn($agoSeconds) => gmdate('Y-m-d H:i:s', $now - $agoSeconds);

equals('the 6th reply inside 10 minutes is refused',
    'replies 5/10min',
    chatbotLoopVerdict(['window_started_at' => $utc(300), 'window_count' => 5], $now, 5));
equals('one under the cap still answers',
    null,
    chatbotLoopVerdict(['window_started_at' => $utc(300), 'window_count' => 4], $now, 5));
equals('the window resets after 10 minutes',
    null,
    chatbotLoopVerdict(['window_started_at' => $utc(601), 'window_count' => 12], $now, 5));
equals('an admin who raises the cap gets more replies',
    null,
    chatbotLoopVerdict(['window_started_at' => $utc(300), 'window_count' => 5], $now, 8));
equals('an empty state never refuses',
    null,
    chatbotLoopVerdict([], $now, 5));

group('Replies arriving faster than a person can type are a loop');

equals('three machine-speed inbounds trip the guard',
    'machine-speed replies',
    chatbotLoopVerdict(['fast_streak' => 3], $now, 5));
equals('a 2-second answer extends the streak',
    1, chatbotFastStreakNext($utc(2), 0, $now));
equals('and a third consecutive one reaches the limit',
    3, chatbotFastStreakNext($utc(1), 2, $now));
equals('a human-paced answer resets the streak',
    0, chatbotFastStreakNext($utc(30), 2, $now));
equals('no bot send means nothing to be fast against',
    0, chatbotFastStreakNext(null, 5, $now));

group('The out-of-hours message goes once per 12 hours');

check('due when never sent', chatbotHoursMessageDue(null, $now));
check('not due an hour after the last one', !chatbotHoursMessageDue($utc(3600), $now));
check('due again after 12 hours', chatbotHoursMessageDue($utc(43201), $now));

// --- Customer language: resolution and normalisation ---------------------------

group('Voice language resolves explicit, phone, then timezone');

equals('explicit setting wins over the phone', 'hi',
    chatbotResolveVoiceLanguage(['voice_language' => 'hi'], '923214293060', 'Asia/Karachi'));
equals('auto + +92 customer', 'ur',
    chatbotResolveVoiceLanguage(['voice_language' => 'auto'], '923214293060', null));
equals('auto + +91 customer', 'hi',
    chatbotResolveVoiceLanguage(['voice_language' => 'auto'], '919812345678', null));
equals('auto + no phone + Karachi', 'ur',
    chatbotResolveVoiceLanguage(['voice_language' => 'auto'], null, 'Asia/Karachi'));
equals('auto + no phone + Kolkata', 'hi',
    chatbotResolveVoiceLanguage(['voice_language' => 'auto'], null, 'Asia/Kolkata'));
equals('auto + foreign number + London', null,
    chatbotResolveVoiceLanguage(['voice_language' => 'auto'], '447700900000', 'Europe/London'));
equals('unknown stored value normalises to auto', 'auto',
    chatbotNormaliseVoiceLanguage('xx'));

group('The bot has one name, never the model’s');

$promptBiz = chatbotSystemPrompt([], ['business_name' => 'Acme Clinic']);
check('named, with the business', str_contains($promptBiz, 'Your name is WhatsApp Assistant')
    && str_contains($promptBiz, 'the assistant of Acme Clinic'));
$promptAnon = chatbotSystemPrompt([]);
check('named, without the business', str_contains($promptAnon, 'Your name is WhatsApp Assistant')
    && str_contains($promptAnon, 'the assistant of this business'));
check('model identity denied', str_contains($promptAnon, 'Never say you are FenLLM'));

group('The reply prompt carries the script rule');

$promptUr = chatbotSystemPrompt([], ['language' => 'ur', 'from_voice' => false]);
check('ur language -> Urdu script rule', str_contains($promptUr, 'Urdu script'));
check('ur rule follows the message', str_contains($promptUr, 'if they write English, reply in English'));
check('ur rule dropped the old blanket wording', !str_contains($promptUr, 'Customers here speak Urdu'));
$promptHi = chatbotSystemPrompt([], ['language' => 'hi', 'from_voice' => false]);
check('hi rule follows the message', str_contains($promptHi, 'if they write English, reply in English'));
check('hi rule dropped the old blanket wording', !str_contains($promptHi, 'Customers here speak Hindi'));
$promptVoice = chatbotSystemPrompt([], ['language' => 'ur', 'from_voice' => true]);
check('ur + voice note mentions it', str_contains($promptVoice, 'voice note'));
$promptNone = chatbotSystemPrompt([], ['language' => null, 'from_voice' => false]);
check('no language -> no script rule', !str_contains($promptNone, 'Urdu script')
    && !str_contains($promptNone, 'voice note'));

// --- Markdown -> WhatsApp formatting -------------------------------------------

group('Model Markdown becomes WhatsApp formatting');

equals('**x**', '*Paid Courses:*', chatbotWhatsAppFormat('**Paid Courses:**'));
equals('__x__', '*x*', chatbotWhatsAppFormat('__x__'));
equals('***x***', '*x*', chatbotWhatsAppFormat('***x***'));
equals('~~x~~', '~old~', chatbotWhatsAppFormat('~~old~~'));
equals('## **Fees**', '*Fees*', chatbotWhatsAppFormat('## **Fees**'));
equals('# Title', '*Title*', chatbotWhatsAppFormat('# Title'));
equals('link', 'Apply: https://a.b/c', chatbotWhatsAppFormat('[Apply](https://a.b/c)'));
equals('link with url label', 'https://a.b/c', chatbotWhatsAppFormat('[https://a.b/c](https://a.b/c)'));
equals('spaced asterisks are arithmetic', '2 ** 3', chatbotWhatsAppFormat('2 ** 3'));
equals('single *bold* kept', '*bold*', chatbotWhatsAppFormat('*bold*'));
equals('_it_ kept', '_it_', chatbotWhatsAppFormat('_it_'));
equals('lists untouched', "- item\n* item\n1. item",
    chatbotWhatsAppFormat("- item\n* item\n1. item"));
equals('urdu bold', '*اداری کورسز:*', chatbotWhatsAppFormat('**اداری کورسز:**'));
equals('code fence untouched', "``` **x** ```", chatbotWhatsAppFormat("``` **x** ```"));
equals('3+ newlines collapse', "a\n\nb", chatbotWhatsAppFormat("a\n\n\n\nb"));
equals('hr removed to an empty line', "a\n\nb", chatbotWhatsAppFormat("a\n---\nb"));

check('system prompt prescribes WhatsApp formatting',
    str_contains(chatbotSystemPrompt([]), 'single asterisks'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
