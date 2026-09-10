<?php

// The chatbot: what it is allowed to answer, what it is told, and what it costs.
//
// The LLM call itself lives in includes/llm.php. This file owns the decisions —
// which are the part that must never be wrong, because every one of them is
// either a money question or a "did the bot just embarrass the tenant" question.

function chatbotConfig(mysqli $conn, $userId) {
    $stmt = $conn->prepare("SELECT * FROM chatbot_configs WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // A tenant who has never opened the tab still needs a coherent config, and
    // it must be the safe one: disabled, replying nowhere.
    return $row ?: chatbotDefaultConfig($userId);
}

function chatbotDefaultConfig($userId) {
    return [
        'user_id' => (int)$userId,
        'is_enabled' => 0,
        'model_id' => null,
        'byo_provider_code' => null,
        'byo_model_code' => null,
        'byo_api_key_encrypted' => null,
        'knowledge_base' => '',
        'greeting' => '',
        'fallback_message' => "Sorry, I can't answer that right now. Someone will get back to you shortly.",
        'tone' => 'professional',
        'max_tokens' => 400,
        'history_messages' => 10,
        'reply_to_groups' => 0,
        'reply_to_archived' => 0,
        'active_hours_start' => null,
        'active_hours_end' => null,
        'outside_hours_message' => '',
        'transcribe_audio' => 0,
        'appointments_enabled' => 0,
        'appointment_lead_minutes' => 60,
        'appointment_horizon_days' => 30,
        'reminder_minutes' => '1440,60',
        'booking_confirmation' => '',
        'handoff_enabled' => 0,
        'handoff_phrases' => 'agent,human,representative,talk to someone,speak to a person',
        'handoff_ack_message' => '',
        'handoff_resume_message' => '',
        'handoff_notify_number' => null,
        'handoff_notify_email' => null,
        'handoff_share_number' => 0,
        'handoff_share_message' => '',
    ];
}

// The tenant's saved switches, with anything their plan does not include forced
// off — one place rather than five.
//
// The prompt, the appointment context, the transcription step and the action
// handler all read these switches. Gating them one by one meant every future
// reader silently defaulted to "allowed", and worse, the prompt is built from
// the same config: with only the *write* gated, the bot would still be told it
// could offer a human, promise one to the customer, and then have the action
// refused — which is a worse outcome than never offering.
//
// Normalising at the top of the reply path makes plan and behaviour agree
// everywhere downstream. The individual checks further down are kept as defence
// in depth, for callers that did not come through here.
// $conn is needed for one thing only: resolving the handover notification number
// (#26), which may come from the tenant's own field or from the instance-wide
// admin fallback. It is resolved here, once, so both the reply path's
// "never answer the notification number" rule and the alert sender read the same
// value — computing it separately in each is how the bot ends up replying to the
// very phone it just alerted.
function chatbotEffectiveConfig(?mysqli $conn, array $config, $plan) {
    if (!planHasFeature($plan, 'appointments'))        $config['appointments_enabled'] = 0;
    if (!planHasFeature($plan, 'handoff'))             $config['handoff_enabled'] = 0;
    if (!planHasFeature($plan, 'voice_transcription')) $config['transcribe_audio'] = 0;

    $config['notify_number_effective'] = handoffNotifyNumber($conn, $config);
    return $config;
}

function chatbotToneChoices() {
    return [
        'professional' => 'Professional',
        'friendly'     => 'Friendly',
        'concise'      => 'Short and direct',
        'formal'       => 'Formal',
        'casual'       => 'Casual',
    ];
}

// Only known columns are written, and each is clamped: max_tokens and
// history_messages reach a paid API, so an unbounded value from a form is a
// billing incident waiting to happen.
function chatbotSaveConfig(mysqli $conn, $userId, array $in) {
    $enabled   = !empty($in['is_enabled']) ? 1 : 0;
    $modelId   = ($in['model_id'] ?? '') === '' ? null : (int)$in['model_id'];
    $byoCode   = ($in['byo_provider_code'] ?? '') === '' ? null : (string)$in['byo_provider_code'];
    $byoModel  = trim((string)($in['byo_model_code'] ?? '')) ?: null;
    $kb        = (string)($in['knowledge_base'] ?? '');
    $fallback  = (string)($in['fallback_message'] ?? '');
    $tone      = array_key_exists($in['tone'] ?? '', chatbotToneChoices()) ? $in['tone'] : 'professional';
    $maxTokens = max(64, min(2000, (int)($in['max_tokens'] ?? 400)));
    $history   = max(0, min(30, (int)($in['history_messages'] ?? 10)));
    $start     = chatbotValidTime($in['active_hours_start'] ?? null);
    $end       = chatbotValidTime($in['active_hours_end'] ?? null);
    $outside   = (string)($in['outside_hours_message'] ?? '');
    $transcribe = !empty($in['transcribe_audio']) ? 1 : 0;

    // Appointments (#14). Clamped for the same reason as max_tokens: these
    // numbers drive real behaviour and arrive from a form.
    $apptOn    = !empty($in['appointments_enabled']) ? 1 : 0;
    $lead      = max(0, min(10080, (int)($in['appointment_lead_minutes'] ?? 60)));
    $horizon   = max(1, min(365, (int)($in['appointment_horizon_days'] ?? 30)));
    // #36: the form posts a checkbox per lead time, and an unchecked box posts
    // nothing at all — so "no reminders" arrives as an absent field, exactly
    // like a caller that never knew about reminders. The hidden marker tells the
    // two apart: with it, an empty list means the tenant switched them all off;
    // without it, the stored value is left alone rather than silently defaulted
    // back to a day-and-an-hour they had deliberately removed.
    $remindersSent = array_key_exists('reminder_minutes', $in) || !empty($in['reminder_minutes_present']);
    $reminders = $remindersSent
        ? implode(',', apptReminderMinutes(['reminder_minutes' => $in['reminder_minutes'] ?? []]))
        : (string)(chatbotConfig($conn, $userId)['reminder_minutes'] ?? '1440,60');
    $confirm   = mb_substr((string)($in['booking_confirmation'] ?? ''), 0, 500);

    // Handoff (#16).
    $handoffOn  = !empty($in['handoff_enabled']) ? 1 : 0;
    $phrases    = mb_substr(trim((string)($in['handoff_phrases'] ?? '')), 0, 500);
    $ackMsg     = mb_substr((string)($in['handoff_ack_message'] ?? ''), 0, 500);
    $resumeMsg  = mb_substr((string)($in['handoff_resume_message'] ?? ''), 0, 500);
    // #26: normalised to bare E.164 digits, and a value that is not a valid
    // international number is stored as NULL rather than as something that looks
    // saved and can never be messaged. The page validates first and refuses the
    // save, so reaching the NULL here means a crafted POST.
    $notifyNum  = handoffNormaliseNumber($in['handoff_notify_number'] ?? '');
    $notifyNum  = (is_string($notifyNum) && $notifyNum !== '') ? $notifyNum : null;
    $notifyMail = trim((string)($in['handoff_notify_email'] ?? ''));
    if ($notifyMail !== '' && !filter_var($notifyMail, FILTER_VALIDATE_EMAIL)) $notifyMail = null;
    $notifyMail = $notifyMail ?: null;
    $shareNum   = !empty($in['handoff_share_number']) ? 1 : 0;
    $shareMsg   = mb_substr(trim((string)($in['handoff_share_message'] ?? '')), 0, 500);

    if ($byoCode !== null && !llmIsKnownProvider($byoCode)) $byoCode = null;

    // `greeting` is deliberately not written. The column exists but there is no
    // control for it and chatbotSystemPrompt() never reads it, so including it
    // meant every save blanked a column nothing could set — a write with no
    // reader on either side. It is left out rather than removed so a future
    // greeting feature needs no migration, like reply_to_groups.
    $stmt = $conn->prepare(
        "INSERT INTO chatbot_configs
           (user_id, is_enabled, model_id, byo_provider_code, byo_model_code, knowledge_base,
            fallback_message, tone, max_tokens, history_messages, active_hours_start, active_hours_end,
            outside_hours_message, transcribe_audio,
            appointments_enabled, appointment_lead_minutes, appointment_horizon_days,
            reminder_minutes, booking_confirmation,
            handoff_enabled, handoff_phrases, handoff_ack_message, handoff_resume_message,
            handoff_notify_number, handoff_notify_email,
            handoff_share_number, handoff_share_message)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            is_enabled = VALUES(is_enabled), model_id = VALUES(model_id),
            byo_provider_code = VALUES(byo_provider_code), byo_model_code = VALUES(byo_model_code),
            knowledge_base = VALUES(knowledge_base),
            fallback_message = VALUES(fallback_message), tone = VALUES(tone),
            max_tokens = VALUES(max_tokens), history_messages = VALUES(history_messages),
            active_hours_start = VALUES(active_hours_start), active_hours_end = VALUES(active_hours_end),
            outside_hours_message = VALUES(outside_hours_message), transcribe_audio = VALUES(transcribe_audio),
            appointments_enabled = VALUES(appointments_enabled),
            appointment_lead_minutes = VALUES(appointment_lead_minutes),
            appointment_horizon_days = VALUES(appointment_horizon_days),
            reminder_minutes = VALUES(reminder_minutes),
            booking_confirmation = VALUES(booking_confirmation),
            handoff_enabled = VALUES(handoff_enabled), handoff_phrases = VALUES(handoff_phrases),
            handoff_ack_message = VALUES(handoff_ack_message),
            handoff_resume_message = VALUES(handoff_resume_message),
            handoff_notify_number = VALUES(handoff_notify_number),
            handoff_notify_email = VALUES(handoff_notify_email),
            handoff_share_number = VALUES(handoff_share_number),
            handoff_share_message = VALUES(handoff_share_message)"
    );
    // The type string is derived from the values, not written by hand. This
    // statement binds 27 columns and the hand-written string had drifted by one
    // character, so `bind_param` threw `ArgumentCountError` and *every* save of
    // this form 500'd. Deriving it cannot drift when a column is added — which
    // is what let #26 add two more here without touching it.
    $params = [
        $userId, $enabled, $modelId, $byoCode, $byoModel, $kb,
        $fallback, $tone, $maxTokens, $history, $start, $end, $outside, $transcribe,
        $apptOn, $lead, $horizon, $reminders, $confirm,
        $handoffOn, $phrases, $ackMsg, $resumeMsg, $notifyNum, $notifyMail,
        $shareNum, $shareMsg,
    ];
    $types = '';
    foreach ($params as $p) $types .= is_int($p) ? 'i' : 's';

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
}

function chatbotValidTime($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : null;
}

// The tenant's own key. Blank means "keep what is stored" — the form never
// renders it back, so blank is the normal case on every other edit.
function chatbotSaveByoKey(mysqli $conn, $userId, $apiKey) {
    $apiKey = trim((string)$apiKey);
    if ($apiKey === '') return [true, null];

    $encrypted = encryptSecret($apiKey, LLM_CONTEXT);
    if ($encrypted === null) return [false, 'Cannot encrypt the key on this instance.'];

    $stmt = $conn->prepare("UPDATE chatbot_configs SET byo_api_key_encrypted = ? WHERE user_id = ?");
    $stmt->bind_param('si', $encrypted, $userId);
    $stmt->execute();
    $stmt->close();
    return [true, null];
}

// Clearing the key also clears the provider and model that went with it.
//
// Removing only the key left `byo_provider_code` set, and chatbotResolveModel()
// takes the BYO branch on that column alone — it then fails with "Your API key is
// missing" and never falls through to the platform model, so "Remove my key"
// silently stopped the bot replying at all. Dropping all three returns the tenant
// to whatever platform model they had selected, which is the only coherent
// meaning of "I am no longer using my own key".
function chatbotClearByoKey(mysqli $conn, $userId) {
    $stmt = $conn->prepare(
        "UPDATE chatbot_configs
            SET byo_api_key_encrypted = NULL, byo_provider_code = NULL, byo_model_code = NULL
          WHERE user_id = ?"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

// --- Who the bot may answer (#22) -------------------------------------------

// Returns null when the message may be answered, or a short reason code when it
// must not. The reason is recorded, so "the bot said nothing" is always
// explainable rather than a mystery.
//
// Order matters only for which reason gets logged; every rule is a hard stop.
function chatbotSkipReason(array $config, array $msg) {
    if (!empty($msg['fromMe']))                         return 'skipped_own_message';
    if (($msg['chatId'] ?? '') === 'status@broadcast')  return 'skipped_broadcast';

    // #22: never a group. Auto-replying into a group is noisy at best and
    // embarrassing at worst, and the tenant did not ask for an audience.
    $isGroup = !empty($msg['isGroup']) || str_ends_with((string)($msg['chatId'] ?? ''), '@g.us');
    if ($isGroup && empty($config['reply_to_groups']))  return 'skipped_group';

    // #22: an archived chat is one the tenant deliberately put aside. Replying
    // drags it back to the top of their list.
    if (!empty($msg['archived']) && empty($config['reply_to_archived'])) return 'skipped_archived';

    // The linked number talking to itself. Answering produces a loop.
    if (!empty($msg['isSelfChat']))                     return 'skipped_self_chat';

    // #26: the handover notification number is a colleague's phone, not a
    // customer's. The bot has just messaged it to say someone is waiting; if
    // that colleague replies "on it", the bot must not answer them — and worse,
    // an auto-reply there could match a handoff phrase and open a second
    // handoff for the alert thread itself. The resolved number is put on the
    // config by chatbotEffectiveConfig(), so the tenant's own field and the
    // admin fallback are both covered.
    $notify = (string)($config['notify_number_effective'] ?? '');
    if ($notify !== '' && (string)($msg['chatId'] ?? '') === $notify . '@s.whatsapp.net') {
        return 'skipped_notify_number';
    }

    // Newsletters and channels are broadcasts, not conversations.
    $chatId = (string)($msg['chatId'] ?? '');
    if (str_ends_with($chatId, '@newsletter'))          return 'skipped_newsletter';

    return null;
}

// "Business hours" is the tenant's own timezone, not the server's — a bot that
// goes quiet at the wrong 6pm is worse than one that never sleeps.
// An unset window means always on. A window that wraps midnight (22:00–06:00)
// is treated as spanning it, which is what a night shift means.
function chatbotWithinHours(array $config, $timezone, $now = null) {
    $start = $config['active_hours_start'] ?? null;
    $end = $config['active_hours_end'] ?? null;
    if (!$start || !$end || $start === $end) return true;

    try {
        $tz = new DateTimeZone($timezone ?: 'UTC');
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }
    $current = ($now instanceof DateTime ? clone $now : new DateTime('now', new DateTimeZone('UTC')));
    $current->setTimezone($tz);
    $hhmm = $current->format('H:i');

    return $start < $end
        ? ($hhmm >= $start && $hhmm < $end)
        : ($hhmm >= $start || $hhmm < $end);   // wraps midnight
}

// --- Which credentials to use -----------------------------------------------

// Resolves the config to a usable ['provider','key','base_url','model'] or an
// error. Every gate the admin controls is applied here, so no caller can reach
// a model the plan or the instance does not allow.
function chatbotResolveModel(mysqli $conn, $userId, array $config) {
    $plan = getUserPlan($conn, $userId);

    if (!planHasFeature($plan, 'chatbot')) {
        return [null, 'The current plan does not include the AI chatbot.'];
    }

    // A tenant's own key, if the admin allows it and the plan grants it.
    if (!empty($config['byo_provider_code'])) {
        if (!llmAllowByoKeys($conn))            return [null, 'Bring-your-own keys are disabled on this instance.'];
        if (!planHasFeature($plan, 'llm_byok')) return [null, 'The current plan does not include bring-your-own LLM keys.'];

        $key = decryptSecret($config['byo_api_key_encrypted'] ?? null, LLM_CONTEXT);
        if ($key === null) return [null, 'Your API key is missing or could not be read — re-enter it.'];
        if (empty($config['byo_model_code'])) return [null, 'Choose a model for your own API key.'];

        return [[
            'provider' => $config['byo_provider_code'],
            'key' => $key,
            'base_url' => null,
            'model' => $config['byo_model_code'],
            'model_id' => null,
            'byo' => true,
        ], null];
    }

    if (empty($config['model_id'])) return [null, 'No model selected.'];

    $model = llmModelById($conn, (int)$config['model_id']);
    if (!$model)                          return [null, 'The selected model no longer exists.'];
    if (empty($model['is_enabled']))      return [null, 'The selected model has been disabled.'];
    if (empty($model['provider_enabled'])) return [null, 'The provider for this model is disabled.'];

    // Plan access is re-checked at call time, not just at selection time: a
    // tenant downgraded after choosing a premium model must lose it immediately.
    $allowed = llmModelsForPlan($conn, (int)$plan['id']);
    $ids = array_column($allowed, 'id');
    if (!in_array((int)$model['id'], array_map('intval', $ids), true)) {
        return [null, 'The current plan does not include this model.'];
    }

    $key = decryptSecret($model['api_key_encrypted'] ?? null, LLM_CONTEXT);
    if ($key === null) return [null, 'The provider has no usable API key configured.'];

    return [[
        'provider' => $model['provider_code'],
        'key' => $key,
        'base_url' => llmProviderBaseUrl(['code' => $model['provider_code'], 'base_url' => $model['base_url']]),
        'model' => $model['model_code'],
        'model_id' => (int)$model['id'],
        'byo' => false,
    ], null];
}

// --- What the bot is told ---------------------------------------------------

// The system prompt. Assembled rather than templated because each part is a
// rule the tenant can see the consequences of: the knowledge base is theirs,
// the guard rails are ours.
function chatbotSystemPrompt(array $config, array $context = []) {
    $tone = chatbotToneChoices()[$config['tone'] ?? 'professional'] ?? 'Professional';
    $business = trim((string)($context['business_name'] ?? ''));

    $parts = [];
    $parts[] = "You are a WhatsApp assistant replying on behalf of "
        . ($business !== '' ? $business : 'a business') . '.';
    $parts[] = "Tone: {$tone}.";

    // WhatsApp is not a chat window: no markdown headings, no bullet walls, and
    // a long reply is a wall of text on a phone.
    $parts[] = "Write for WhatsApp: short paragraphs, no markdown headings or tables, "
        . "plain sentences. Keep replies under 90 words unless the customer asks for detail.";
    $parts[] = "Reply in the language the customer used.";

    // The single most important instruction. A booking bot that invents a price
    // or a policy costs the tenant real money.
    $parts[] = "Only state facts that appear in the business information below. "
        . "If you do not know something, say so plainly and offer to have a person follow up. "
        . "Never invent prices, availability, policies, addresses or phone numbers.";

    $kb = trim((string)($config['knowledge_base'] ?? ''));
    if ($kb !== '') {
        $parts[] = "--- Business information ---\n" . $kb;
    } else {
        $parts[] = "No business information has been provided, so you can only handle general "
            . "conversation and must offer to have a person follow up for anything specific.";
    }

    if (!empty($context['appointments'])) {
        $parts[] = chatbotBookingInstructions($context['appointments']);
    }

    if (!empty($config['handoff_enabled'])) {
        // The phrase list catches the obvious asks before the model is even
        // called; this catches the rest — frustration, a complaint, something
        // the knowledge base plainly does not cover.
        $parts[] = "--- Handing over to a person ---\n"
            . "If the customer asks for a human, is clearly frustrated, or wants something you "
            . "cannot answer from the business information, offer to pass them to a colleague. "
            . "If they accept, end your message with exactly:\n"
            . '   ' . APPT_ACTION_OPEN . ' {"action":"handoff"} ' . APPT_ACTION_CLOSE . "\n"
            . "Never mention that line. Do not promise a specific response time.";
    }

    return implode("\n\n", $parts);
}

// The booking half of the prompt (#14).
//
// The model is told it may *propose* and must emit a machine-readable line only
// once the customer has actually agreed. It is never told that emitting the line
// books anything — because it does not. PHP validates the proposal against the
// real calendar and can refuse it, so the wording deliberately avoids having the
// model promise a confirmation it cannot give.
function chatbotBookingInstructions(array $a) {
    $lines = [];
    $lines[] = "--- Appointments ---";
    $lines[] = "You can help customers book, reschedule and cancel appointments.";

    $lines[] = "Services offered (name — duration):";
    foreach ($a['services'] as $s) {
        $lines[] = '- ' . $s['name'] . ' — ' . (int)$s['duration_minutes'] . ' minutes'
            . (trim((string)($s['description'] ?? '')) !== '' ? ' (' . $s['description'] . ')' : '');
    }

    $lines[] = "Opening hours (times are " . $a['timezone'] . ", the business's local time):";
    if ($a['availability']) {
        $names = apptWeekdayNames();
        foreach ($a['availability'] as $w) {
            $lines[] = '- ' . $names[(int)$w['weekday']] . ' ' . $w['start_time'] . '–' . $w['end_time'];
        }
    } else {
        $lines[] = '- none configured, so you cannot take a booking; offer to have a person follow up.';
    }

    $lines[] = "Right now it is {$a['now_local']} ({$a['timezone']}). "
        . "Bookings need at least " . apptHumanMinutes($a['lead_minutes']) . " notice "
        . "and can be at most {$a['horizon_days']} days ahead.";

    // #35. The opening hours above say when the business is *open*; this says
    // what is actually free. Both are given because they answer different
    // questions — "are you open on Saturday?" is not "can I come at 10?".
    if (!empty($a['slots'])) {
        $lines[] = "Times currently free (already checked against the diary — "
            . "offer these and nothing else):";
        foreach ($a['slots'] as $serviceName => $dayLines) {
            $lines[] = $serviceName . ':';
            foreach ($dayLines as $dayLine) $lines[] = '  ' . $dayLine;
        }
        $lines[] = "That list is the soonest few openings, not the whole diary. If the customer "
            . "wants a different day, offer to check it rather than guessing.";
    } elseif (!empty($a['availability'])) {
        // Open, but nothing bookable inside the horizon. Saying so is the point:
        // without it the model reads the opening hours and invents a time.
        $lines[] = "There are no free slots at all in that period. Do not offer a time — say the "
            . "diary is full and offer to have someone follow up.";
    }

    if (!empty($a['existing'])) {
        $lines[] = "This customer already has a booking: {$a['existing']['service_name']} on {$a['existing']['when_local']}.";
    }

    $lines[] = "Rules you must follow:";
    $lines[] = "1. Only ever offer a time from the free list above, and never state that a booking is "
        . "confirmed on your own — the confirmation comes from the system. If the customer asks for a "
        . "time that is not on the list, do not agree to it: say you will check and offer the "
        . "nearest listed alternative.";
    $lines[] = "2. Before booking you need: which service, and a specific date and time. "
        . "Ask for whichever is missing. Ask for a name only if you do not already know it.";
    $lines[] = "3. When — and only when — the customer has clearly agreed to a specific service and time, "
        . "end your message with a line in exactly this form, and nothing after it:";
    $lines[] = '   ' . APPT_ACTION_OPEN . ' {"action":"book","service":"<service name>","datetime":"YYYY-MM-DD HH:MM","name":"<customer name or empty>"} ' . APPT_ACTION_CLOSE;
    $lines[] = "4. To cancel their existing booking, end with: "
        . APPT_ACTION_OPEN . ' {"action":"cancel"} ' . APPT_ACTION_CLOSE;
    $lines[] = "5. To move it, end with: "
        . APPT_ACTION_OPEN . ' {"action":"reschedule","datetime":"YYYY-MM-DD HH:MM"} ' . APPT_ACTION_CLOSE;
    $lines[] = "6. The time in that line is always the business's local time, 24-hour clock. "
        . "Never show that line's contents to the customer or mention that it exists.";
    $lines[] = "7. Write the human part of your message as if the booking is being submitted, "
        . "not as if it is already guaranteed.";

    return implode("\n", $lines);
}

// Pulls the action line out of a model reply.
// Returns [cleanTextForTheCustomer, actionArrayOrNull].
//
// Anything malformed is simply stripped: a customer must never see the protocol,
// even when the model gets it wrong.
function chatbotExtractAction($text) {
    $open = strpos($text, APPT_ACTION_OPEN);
    if ($open === false) return [trim($text), null];

    $close = strpos($text, APPT_ACTION_CLOSE, $open);
    $clean = trim(substr($text, 0, $open));

    if ($close === false) return [$clean, null];

    $json = trim(substr($text, $open + strlen(APPT_ACTION_OPEN), $close - $open - strlen(APPT_ACTION_OPEN)));
    $tail = trim(substr($text, $close + strlen(APPT_ACTION_CLOSE)));
    // A model that keeps writing after the action line still gets its prose shown.
    if ($tail !== '') $clean = trim($clean . "\n" . $tail);

    $decoded = json_decode($json, true);
    if (!is_array($decoded) || empty($decoded['action'])) return [$clean, null];

    return [$clean, $decoded];
}

// Builds the message list: system prompt, then the recent turns, oldest first.
// $history is the backend's message shape (fromMe, text, time).
function chatbotBuildMessages(array $config, array $history, $incomingText, array $context = []) {
    $messages = [['role' => 'system', 'content' => chatbotSystemPrompt($config, $context)]];

    $limit = max(0, (int)($config['history_messages'] ?? 10));
    if ($limit > 0) {
        $recent = array_slice($history, -$limit);
        foreach ($recent as $m) {
            $text = trim((string)($m['text'] ?? ''));
            if ($text === '') continue;
            $messages[] = [
                'role' => !empty($m['fromMe']) ? 'assistant' : 'user',
                'content' => $text,
            ];
        }
    }

    // The message being answered. It may already be the last history entry, in
    // which case adding it twice would make the model see a repeat.
    $last = end($messages);
    if (!$last || $last['role'] !== 'user' || trim($last['content']) !== trim($incomingText)) {
        $messages[] = ['role' => 'user', 'content' => $incomingText];
    }
    return $messages;
}

// --- Bookkeeping ------------------------------------------------------------

// Chats are correlated by hash. The outcome of a reply is operational data the
// admin console can read, and admins must never see who a tenant talks to.
function chatbotChatKey($chatId) {
    return hash('sha256', (string)$chatId);
}

function chatbotLogEvent(mysqli $conn, $userId, $outcome, array $extra = []) {
    $accountId = isset($extra['account_id']) ? (int)$extra['account_id'] : null;
    $chatKey   = isset($extra['chat_id']) ? chatbotChatKey($extra['chat_id']) : null;
    $detail    = isset($extra['detail']) ? substr((string)$extra['detail'], 0, 255) : null;
    $modelId   = isset($extra['model_id']) ? (int)$extra['model_id'] : null;
    $prompt    = isset($extra['prompt_tokens']) ? (int)$extra['prompt_tokens'] : null;
    $completion = isset($extra['completion_tokens']) ? (int)$extra['completion_tokens'] : null;
    $latency   = isset($extra['latency_ms']) ? (int)$extra['latency_ms'] : null;

    $stmt = $conn->prepare(
        "INSERT INTO chatbot_events (user_id, account_id, chat_key, outcome, detail, model_id,
                                     prompt_tokens, completion_tokens, latency_ms)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    $stmt->bind_param('iissssiii', $userId, $accountId, $chatKey, $outcome, $detail, $modelId,
        $prompt, $completion, $latency);
    $stmt->execute();
    $stmt->close();
}

function chatbotRecentEvents(mysqli $conn, $userId, $limit = 20) {
    $limit = max(1, min(100, (int)$limit));
    $stmt = $conn->prepare(
        "SELECT e.*, m.label AS model_label FROM chatbot_events e
         LEFT JOIN llm_models m ON e.model_id = m.id
         WHERE e.user_id = ? ORDER BY e.created_at DESC LIMIT " . $limit
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// --- The pipeline -----------------------------------------------------------

// Generates a reply without sending it. Shared by the live handler and the
// tenant's test console, so what the console shows is what a customer would
// actually get — a preview that used a different code path would be a lie.
//
// Returns ['ok'=>bool, 'text'=>string, 'error'=>?string, 'usage'=>[], 'latency_ms'=>int,
//          'model_id'=>?int].
function chatbotGenerateReply(mysqli $conn, $userId, array $config, array $history, $incomingText, array $context = []) {
    [$auth, $configError] = chatbotResolveModel($conn, $userId, $config);
    if ($auth === null) {
        return ['ok' => false, 'error' => $configError, 'text' => '', 'usage' => [], 'latency_ms' => 0, 'model_id' => null, 'reason' => 'config_error'];
    }

    $messages = chatbotBuildMessages($config, $history, $incomingText, $context);
    $result = llmChat($auth, $messages, ['max_tokens' => (int)($config['max_tokens'] ?? 400)]);
    $result['model_id'] = $auth['model_id'];
    if (!$result['ok'] && empty($result['reason'])) $result['reason'] = 'llm_error';
    return $result;
}

// Pulls the recent turns of a conversation from the backend. The chatbot needs
// context ("yes, 3pm works") and MySQL's copy is only as fresh as the last poll,
// so this asks the process that actually holds the thread.
function chatbotFetchHistory($sessionId, $chatId, $tenantId, $limit = 10) {
    if ($limit <= 0) return [];
    $resp = callBackendApi('GET', '/api/v1/wa/sessions/' . urlencode($sessionId)
        . '/chats/' . urlencode($chatId) . '/messages', null, $tenantId, 15);
    if (!$resp || empty($resp['ok']) || !is_array($resp['messages'] ?? null)) return [];

    // Text only: an image in the history has no words for the model to read,
    // and a media placeholder would just be noise in the prompt.
    $texts = array_values(array_filter($resp['messages'], function ($m) {
        return trim((string)($m['text'] ?? '')) !== '';
    }));
    return array_slice($texts, -($limit + 5));
}

// Fetches a voice note's bytes so it can be transcribed. Streams into memory on
// purpose — a voice note is small, and it goes straight to the vendor.
function chatbotFetchMedia($sessionId, $messageId, $tenantId) {
    $url = BACKEND_URL . '/api/v1/wa/sessions/' . urlencode($sessionId)
        . '/messages/' . urlencode($messageId) . '/media';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => backendHeaders($tenantId),
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($status === 200 && $body !== false && $body !== '') ? $body : null;
}

// Turns a voice note into text, if the admin enabled it, the tenant enabled it,
// and a transcription model is configured. Any "no" is silent and simply means
// the message has no text to answer.
function chatbotTranscribeInbound(mysqli $conn, $userId, array $config, $sessionId, $messageId, $tenantId) {
    // Three independent switches, all of which must be on: the plan's lever, the
    // instance-wide admin toggle, and the tenant's own preference. The plan is
    // checked first because it is the one that costs the platform money — a
    // transcription is a second paid vendor call on top of the reply.
    if (!planHasFeature(getUserPlan($conn, $userId), 'voice_transcription')) {
        return [null, 'voice notes are not part of this plan'];
    }
    if (empty($config['transcribe_audio']) || !llmAudioEnabled($conn)) return [null, 'audio disabled'];

    $modelId = llmTranscribeModelId($conn);
    if (!$modelId) return [null, 'no transcription model configured'];

    $model = llmModelById($conn, $modelId);
    if (!$model || $model['kind'] !== 'transcribe' || empty($model['is_enabled']) || empty($model['provider_enabled'])) {
        return [null, 'transcription model unavailable'];
    }
    $key = decryptSecret($model['api_key_encrypted'] ?? null, LLM_CONTEXT);
    if ($key === null) return [null, 'transcription provider has no usable key'];

    $audio = chatbotFetchMedia($sessionId, $messageId, $tenantId);
    if ($audio === null) return [null, 'could not fetch the audio'];

    $result = llmTranscribe([
        'provider' => $model['provider_code'],
        'key' => $key,
        'base_url' => llmProviderBaseUrl(['code' => $model['provider_code'], 'base_url' => $model['base_url']]),
        'model' => $model['model_code'],
    ], $audio, 'voice.ogg');

    return $result['ok'] ? [$result['text'], null] : [null, $result['error']];
}

// The whole live path for one inbound WhatsApp message.
//
// Every exit records why, because the failure mode of a chatbot is silence and
// silence with no explanation is unsupportable. Returns the outcome string.
function chatbotHandleInbound(mysqli $conn, array $msg) {
    $sessionId = (string)($msg['sessionId'] ?? '');
    $chatId    = (string)($msg['chatId'] ?? '');
    $messageId = (string)($msg['messageId'] ?? '');

    // The session must belong to a real tenant; this is also what maps the
    // backend's tenant id onto a user row.
    $account = chatbotAccountForSession($conn, $sessionId);
    if (!$account) return 'unknown_session';

    $userId = (int)$account['user_id'];
    $tenantId = 't' . $userId;
    // The plan is read here and applied to the config rather than trusted from
    // what the tenant last saved: a downgrade must bite on the very next
    // message, not the next time they happen to open the settings page.
    $plan = getUserPlan($conn, $userId);
    $config = chatbotEffectiveConfig($conn, chatbotConfig($conn, $userId), $plan);

    $log = function ($outcome, $extra = []) use ($conn, $userId, $account, $chatId) {
        chatbotLogEvent($conn, $userId, $outcome, $extra + [
            'account_id' => (int)$account['id'],
            'chat_id' => $chatId,
        ]);
        return $outcome;
    };

    if (empty($config['is_enabled'])) return 'skipped_disabled';   // not logged: would be every message

    $skip = chatbotSkipReason($config, $msg);
    if ($skip !== null) return $log($skip);

    // Read once. This used to be queried here and again when the context was
    // assembled, which was two round trips for one immutable answer — and two
    // chances for the hours check and the booking clock to disagree.
    $timezone = getUserTimezone($conn, $userId);

    // #16/#37. A conversation already with a person stays with that person, and
    // this is asked before *anything* the bot would otherwise do — not just
    // before the model call.
    //
    // It used to sit further down, after the out-of-hours branch, which meant a
    // customer being helped by a colleague at 18:01 got the bot cutting in with
    // "we are closed, we will reply tomorrow" over the top of a live agent. The
    // read receipt has the same problem: marking the thread read is the bot
    // touching a conversation it was told to leave alone, and it clears the
    // unread badge the agent is working from.
    //
    // So: one gate, first, covering replies, receipts, transcription and the
    // model. Recording that the customer said something again (handoffTouch) is
    // the exception, and it is not an action anyone can see — it is what keeps
    // the queue ordered by who has been waiting longest.
    $openHandoff = handoffOpenForChat($conn, $userId, $chatId);
    if ($openHandoff) {
        handoffTouch($conn, (int)$openHandoff['id']);
        return $log('skipped_handoff', ['detail' => $openHandoff['status']]);
    }

    if (!chatbotWithinHours($config, $timezone)) {
        // A configured out-of-hours message is still an answer, and it is free.
        $outside = trim((string)($config['outside_hours_message'] ?? ''));
        if ($outside !== '') {
            chatbotSendReply($conn, $userId, $tenantId, $sessionId, $chatId, $outside);
        }
        return $log('skipped_hours');
    }

    $text = trim((string)($msg['text'] ?? ''));
    $mediaType = (string)($msg['mediaType'] ?? 'text');

    if ($text === '' && ($mediaType === 'voice' || $mediaType === 'audio')) {
        [$transcript, $why] = chatbotTranscribeInbound($conn, $userId, $config, $sessionId, $messageId, $tenantId);
        if ($transcript !== null && trim($transcript) !== '') {
            $text = trim($transcript);
        } else {
            return $log('skipped_empty', ['detail' => 'audio: ' . ($why ?? 'no transcript')]);
        }
    }

    if ($text === '') return $log('skipped_empty', ['detail' => $mediaType]);

    // #16. Did they just ask for a person? Matched in PHP, not by the model: it
    // costs nothing and it still works when the model is down — which is exactly
    // when people ask for a human.
    //
    // Plan-gated, via the effective config above — unlike the open-handoff gate
    // above, which is not. That asymmetry is deliberate: an *already open*
    // handoff must keep silencing the bot even if the plan has since lost the
    // feature, or the bot would start talking over a live agent mid-conversation.
    // Losing the feature stops new handoffs; it does not abandon a customer
    // already waiting.
    $phrase = handoffPhraseMatch($config, $text);
    if ($phrase !== null) {
        return chatbotStartHandoff($conn, $userId, $tenantId, $config, [
            'account_id' => (int)$account['id'],
            'chat_id' => $chatId,
            'session_id' => $sessionId,
            'customer_phone' => chatbotPhoneFromJid($chatId),
            'reason' => 'asked for a person ("' . $phrase . '")',
            'topic' => $text,
        ], $log);
    }

    // Metered before the model is called: a reply that cannot be sent must not
    // be paid for either.
    [$quotaOk, , $limit] = checkMessageQuota($conn, $userId);
    if (!$quotaOk) return $log('quota', ['detail' => 'limit ' . $limit]);

    // The AI-reply allowance, checked separately and also before the vendor
    // call, because this is the limit that protects a real per-token bill. An AI
    // reply spends both allowances and the stricter one wins. Silent by design,
    // like every other refusal here: the tenant sees the reason in their
    // activity log, and the customer sees nothing rather than an apology for a
    // limit they cannot do anything about.
    [$replyQuotaOk, , $replyLimit] = checkChatbotReplyQuota($conn, $userId, $config);
    if (!$replyQuotaOk) return $log('quota_replies', ['detail' => 'AI reply limit ' . $replyLimit]);

    $history = chatbotFetchHistory($sessionId, $chatId, $tenantId, (int)($config['history_messages'] ?? 10));
    // #34: assembled by the one shared helper, so the tenant's test console is
    // reasoning about the same business, the same services and the same clock.
    $context = chatbotBuildContext($conn, $userId, $config, $timezone, $chatId);
    $appointments = $context['appointments'];

    $reply = chatbotGenerateReply($conn, $userId, $config, $history, $text, $context);

    if (!$reply['ok']) {
        $reason = $reply['reason'] ?? 'llm_error';

        // The fallback is for a model that *failed* — the customer asked a
        // question and deserves an acknowledgement rather than silence.
        //
        // It is emphatically NOT for a configuration or entitlement problem. A
        // tenant whose plan does not include the chatbot, or who has no model
        // selected, must send nothing at all: otherwise every inbound message
        // fires a WhatsApp reply and burns their message quota on a feature they
        // are not even using. The tenant sees the reason in their activity log;
        // the customer sees nothing, which is the correct behaviour for a bot
        // that was never really switched on.
        $fallback = trim((string)($config['fallback_message'] ?? ''));
        if ($reason === 'llm_error' && $fallback !== '') {
            chatbotSendReply($conn, $userId, $tenantId, $sessionId, $chatId, $fallback);
        }
        return $log($reason, [
            'detail' => $reply['error'] ?? 'unknown',
            'model_id' => $reply['model_id'] ?? null,
            'latency_ms' => $reply['latency_ms'] ?? null,
        ]);
    }

    // The model may have proposed a booking. It is stripped from the reply
    // before anything is sent — the customer must never see the protocol — and
    // then carried out (or refused) against the real calendar.
    [$replyText, $action] = chatbotExtractAction($reply['text']);
    $actionOutcome = null;
    // A handoff action needs no appointment context; a booking one does.
    if ($action !== null && ($appointments !== null || ($action['action'] ?? '') === 'handoff')) {
        $result = chatbotApplyAction($conn, $userId, $config, $action, [
            'timezone' => $appointments['timezone'] ?? $timezone,
            'account_id' => (int)$account['id'],
            'chat_id' => $chatId,
            'session_id' => $sessionId,
            'tenant_id' => $tenantId,
            'topic' => $text,
            'handoff_enabled' => !empty($config['handoff_enabled']),
            'customer_phone' => chatbotPhoneFromJid($chatId),
        ]);
        if ($result !== '') {
            $replyText = trim($replyText . "\n\n" . $result);
            $actionOutcome = $action['action'];
        }
    }
    if (trim($replyText) === '') $replyText = trim((string)($config['fallback_message'] ?? 'Thanks — someone will follow up.'));

    $sent = chatbotSendReply($conn, $userId, $tenantId, $sessionId, $chatId, $replyText);
    if (!$sent) {
        return $log('send_error', ['model_id' => $reply['model_id'] ?? null, 'detail' => 'backend refused the send']);
    }

    incrementUsage($conn, $userId, 'chatbot_replies');
    return $log('replied', [
        'detail' => $actionOutcome ? 'appointment ' . $actionOutcome : null,
        'model_id' => $reply['model_id'] ?? null,
        'prompt_tokens' => $reply['usage']['prompt'] ?? null,
        'completion_tokens' => $reply['usage']['completion'] ?? null,
        'latency_ms' => $reply['latency_ms'] ?? null,
    ]);
}

// --- What the model is told about this tenant (#34) -------------------------

// The single assembly point for the prompt's context array.
//
// This exists because there were two of them. The live handler built one and
// `ajax/chatbot-test.php` built a smaller one, and the smaller one was missing
// the entire appointments half — so the test console ran the real model against
// a prompt that never mentioned booking, and confidently showed the tenant a
// reply their customers would never receive. The comment at the top of that
// endpoint had the rule right ("a preview built from a second, simpler code path
// would eventually disagree with what customers actually get"); it was the call
// site that drifted.
//
// So: one function, called by both. A context key added here appears in both
// paths, which is the only arrangement that cannot drift again.
//
// $chatId is the one genuinely chat-specific input, and it is nullable: the test
// console has no WhatsApp chat, and passing null simply means
// chatbotAppointmentContext() reports no existing booking for "this customer"
// while still returning services, hours and the clock. That is the correct
// preview — it is what a brand-new customer's first message would see.
function chatbotBuildContext(mysqli $conn, $userId, array $config, $timezone = null, $chatId = null) {
    // Defaulted rather than required so a future caller cannot accidentally
    // build a context against UTC while the rest of the app uses the tenant's
    // zone. The live handler passes the value it already read.
    $timezone = $timezone ?: getUserTimezone($conn, $userId);
    $profile = getUserProfile($conn, $userId);

    return [
        'business_name' => $profile['company_name'] ?? '',
        'appointments'  => chatbotAppointmentContext($conn, $userId, $config, $timezone, $chatId),
        // Not read by chatbotSystemPrompt() — the appointment block carries its
        // own copy — but every caller needs it afterwards, for formatting a
        // booking's time or for telling the tenant which clock the console is
        // reasoning in. Returning it here is what stops each caller resolving
        // the timezone again and possibly differently.
        'timezone'      => $timezone,
    ];
}

// --- Appointments (#14) -----------------------------------------------------

// Everything the model needs to talk about bookings, or null when the tenant is
// not using the feature. Also what makes the prompt honest about "now": the
// model has no clock, so a customer saying "tomorrow at 3" is otherwise
// unanswerable.
function chatbotAppointmentContext(mysqli $conn, $userId, array $config, $timezone, $chatId = null) {
    // The plan's lever comes before the tenant's switch. Returning null here is
    // what keeps the bot honest: with no appointment context the prompt never
    // mentions booking, so the model cannot offer a slot the platform would then
    // have to refuse. Gating only the write would have let it promise and fail.
    if (!planHasFeature(getUserPlan($conn, $userId), 'appointments')) return null;
    if (empty($config['appointments_enabled'])) return null;

    $services = apptServices($conn, $userId, true);
    if (!$services) return null;

    try { $tz = new DateTimeZone($timezone ?: 'UTC'); } catch (Exception $e) { $tz = new DateTimeZone('UTC'); }
    $nowLocal = (new DateTime('now', new DateTimeZone('UTC')))->setTimezone($tz);

    $existing = null;
    if ($chatId !== null) {
        $row = apptNextForChat($conn, $userId, $chatId);
        if ($row) {
            $existing = [
                'id' => (int)$row['id'],
                'service_name' => $row['service_name'],
                'when_local' => (new DateTime($row['scheduled_at'], new DateTimeZone('UTC')))
                    ->setTimezone($tz)->format('D j M Y, H:i'),
            ];
        }
    }

    // #35: the real openings, per service, worked out here rather than left to
    // the model.
    //
    // Opening hours alone are not availability. Told only "Monday 09:00–17:00",
    // a model offers 10:00 on a Monday that has been fully booked since
    // Tuesday, the customer accepts, and the booking is then refused by
    // apptValidateSlot() — so the bot contradicts itself in consecutive
    // messages, which is worse than never having offered. These times are
    // filtered against the diary, the notice period and the horizon, so
    // anything the model quotes from this list was genuinely free when the
    // prompt was built (and is checked once more before it is confirmed).
    //
    // Capped deliberately: a handful of options spread over several days is a
    // choice a person can answer, and the whole list goes into a paid prompt on
    // every message.
    // The schedule and the diary are read once here and handed to every service,
    // so a tenant with six services costs two queries rather than twelve — and,
    // more importantly, all six answers are computed against the same calendar.
    $availability = apptAvailability($conn, $userId);
    $horizon = max(1, (int)($config['appointment_horizon_days'] ?? 30));
    $busy = $availability ? apptBusyWindows(
        $conn, $userId,
        (clone $nowLocal)->setTimezone(new DateTimeZone('UTC')),
        (clone $nowLocal)->modify('+' . ($horizon + 1) . ' days')->setTimezone(new DateTimeZone('UTC')),
        $tz
    ) : [];

    $slots = [];
    foreach (array_slice($services, 0, 6) as $s) {
        $free = apptOpenSlots($conn, $userId, $config, $s, clone $nowLocal, [
            'availability' => $availability,
            'busy' => $busy,
            'limit' => 8,
            'per_day' => 3,
        ]);
        if ($free) $slots[$s['name']] = apptSlotLines($free);
    }

    return [
        'services' => $services,
        'availability' => $availability,
        'slots' => $slots,
        'timezone' => $tz->getName(),
        'now_local' => $nowLocal->format('D j M Y, H:i'),
        'lead_minutes' => (int)($config['appointment_lead_minutes'] ?? 60),
        'horizon_days' => (int)($config['appointment_horizon_days'] ?? 30),
        'existing' => $existing,
    ];
}

// Carries out what the model proposed — or refuses it.
//
// Returns a short line to append to the reply, so the customer always learns the
// real outcome. The model's own prose is written as "submitting", which is why a
// refusal here reads as a correction rather than a contradiction.
function chatbotApplyAction(mysqli $conn, $userId, array $config, array $action, array $ctx) {
    $timezone = $ctx['timezone'];
    $tz = new DateTimeZone($timezone);
    $services = apptServices($conn, $userId, true);
    $plan = getUserPlan($conn, $userId);

    // The last line of defence for both levers. The model should never have been
    // able to emit these actions — no appointment context means no booking in the
    // prompt, and the handoff action is only described when handover is on — but
    // "the model should not" is not a control. A crafted or hallucinated action
    // marker reaches exactly this switch, so the plan is checked where the write
    // actually happens, not only where the prompt is built.
    $isBooking = in_array($action['action'] ?? '', ['book', 'reschedule', 'cancel'], true);
    if ($isBooking && !planHasFeature($plan, 'appointments')) return '';
    if (($action['action'] ?? '') === 'handoff' && !planHasFeature($plan, 'handoff')) return '';

    $fmt = function ($utcString) use ($tz) {
        return (new DateTime($utcString, new DateTimeZone('UTC')))->setTimezone($tz)->format('D j M Y, H:i');
    };

    switch ($action['action']) {
        case 'handoff':
            // The model has already told the customer in its own words, so the
            // only thing appended is the direct number the tenant chose to share
            // (#26) — the model is never told that number, because it would then
            // be free to quote it in conversations that are not a handover.
            if (empty($ctx['handoff_enabled'])) return '';
            [$id, $isNew] = handoffOpen($conn, $userId, [
                'account_id' => $ctx['account_id'] ?? null,
                'chat_id' => $ctx['chat_id'] ?? null,
                'customer_phone' => $ctx['customer_phone'] ?? null,
                'reason' => 'the assistant offered',
                'topic' => $ctx['topic'] ?? null,
            ]);
            if ($isNew) {
                $handoff = handoffById($conn, $userId, $id);
                if ($handoff) handoffNotify($conn, $userId, $config, $handoff, $ctx['session_id'] ?? null, $ctx['tenant_id'] ?? null);
                logAudit($conn, 'handoff.requested', 'chat_handoff', (string)$id, ['reason' => 'model'], $userId);
                return handoffShareLine($conn, $config);
            }
            return '';

        case 'book':
            $service = apptMatchService($services, $action['service'] ?? '');
            if (!$service) return "I could not match that to one of our services — could you say which one you would like?";

            // #35: validated and written under one per-tenant lock, so the slot
            // cannot be taken by another conversation between the two.
            [$id, $why] = apptBookSlot($conn, $userId, $config, $service, $action['datetime'] ?? '', $timezone, [
                'account_id' => $ctx['account_id'] ?? null,
                'customer_phone' => $ctx['customer_phone'] ?? null,
                'customer_name' => mb_substr(trim((string)($action['name'] ?? '')), 0, 120) ?: null,
                'chat_id' => $ctx['chat_id'] ?? null,
                'notes' => null,
                'source' => 'chatbot',
            ]);
            if (!$id) return apptRefusalLine($why);
            logAudit($conn, 'appointment.booked', 'appointment', (string)$id, ['via' => 'chatbot'], $userId);

            $booked = apptById($conn, $userId, $id);
            $confirm = trim((string)($config['booking_confirmation'] ?? ''));
            $when = $fmt($booked['scheduled_at']);
            return $confirm !== ''
                ? str_replace(['{service}', '{when}'], [$service['name'], $when], $confirm)
                : "Confirmed: {$service['name']} on {$when}.";

        case 'cancel':
            $existing = $ctx['chat_id'] ? apptNextForChat($conn, $userId, $ctx['chat_id']) : null;
            if (!$existing) return "I could not find a booking to cancel.";
            apptSetStatus($conn, $userId, (int)$existing['id'], 'cancelled');
            logAudit($conn, 'appointment.cancelled', 'appointment', (string)$existing['id'], ['via' => 'chatbot'], $userId);
            return "Cancelled: {$existing['service_name']} on " . $fmt($existing['scheduled_at']) . '.';

        case 'reschedule':
            $existing = $ctx['chat_id'] ? apptNextForChat($conn, $userId, $ctx['chat_id']) : null;
            if (!$existing) return "I could not find a booking to move.";

            $service = [
                'name' => $existing['service_name'],
                'duration_minutes' => (int)$existing['duration_minutes'],
            ];
            [$utc, $why] = apptRescheduleSlot($conn, $userId, $config, $service, $action['datetime'] ?? '',
                $timezone, (int)$existing['id']);
            if (!$utc) return apptRefusalLine($why);

            logAudit($conn, 'appointment.rescheduled', 'appointment', (string)$existing['id'], ['via' => 'chatbot'], $userId);
            return "Moved: {$existing['service_name']} is now " . $fmt($utc->format('Y-m-d H:i:s')) . '.';
    }

    return '';
}

// Sends through the same endpoint the composer uses, and meters it the same
// way. A bot reply is a message the tenant sent; it costs what a message costs.
//
// #37: a delivered reply also marks the conversation read. It is done here, not
// at the one call site that remembered to, because every path that answers a
// customer must leave the thread in the same state — an answered chat that
// still shows as unread sends the tenant to a conversation that needs nothing
// from them, which is exactly the noise the bot exists to remove.
//
// Deliberately *not* gated on anything else: the only way to reach this
// function is to have decided the bot may speak, and every one of those
// decisions — including the handover check — is made before the send.
function chatbotSendReply(mysqli $conn, $userId, $tenantId, $sessionId, $chatId, $text) {
    $resp = callBackendApi('POST', '/api/v1/wa/sessions/' . urlencode($sessionId)
        . '/chats/' . urlencode($chatId) . '/messages', ['text' => $text], $tenantId, 30);

    if (!$resp || empty($resp['ok'])) return false;
    incrementUsage($conn, $userId, 'messages_sent');
    chatbotMarkChatRead($tenantId, $sessionId, $chatId);
    return true;
}

// Best effort, and silent about it. The reply is already delivered; a read
// receipt that did not go through is cosmetic, and turning it into a failure
// would make the reply path report an error for something the customer will
// never notice. The short timeout is for the same reason — this must never be
// what makes an inbound message time out.
function chatbotMarkChatRead($tenantId, $sessionId, $chatId) {
    $resp = callBackendApi('POST', '/api/v1/wa/sessions/' . urlencode($sessionId)
        . '/chats/' . urlencode($chatId) . '/read', null, $tenantId, 10);
    return (bool)($resp['ok'] ?? false);
}

// Hands the conversation to a person: records it, tells the customer, and nudges
// the tenant. Used both by the phrase match and by the model's own handoff
// action, so the two routes cannot diverge.
function chatbotStartHandoff(mysqli $conn, $userId, $tenantId, array $config, array $data, callable $log) {
    [$id, $isNew] = handoffOpen($conn, $userId, $data);

    if ($isNew) {
        $ack = trim((string)($config['handoff_ack_message'] ?? ''));
        if ($ack === '') $ack = "Thanks — I'm passing you to a member of our team. They'll reply here shortly.";
        // #26: the direct number, only when the tenant opted in. Appended to the
        // acknowledgement rather than sent as a second message — two WhatsApp
        // messages in a row cost the tenant two from their allowance and read as
        // a bot stuttering.
        $share = handoffShareLine($conn, $config);
        if ($share !== '') $ack = trim($ack . "\n\n" . $share);
        chatbotSendReply($conn, $userId, $tenantId, $data['session_id'], $data['chat_id'], $ack);

        $handoff = handoffById($conn, $userId, $id);
        if ($handoff) {
            handoffNotify($conn, $userId, $config, $handoff, $data['session_id'], $tenantId);
        }
        logAudit($conn, 'handoff.requested', 'chat_handoff', (string)$id, ['reason' => $data['reason']], $userId);
    }

    return $log('handoff', ['detail' => $isNew ? 'opened' : 'already waiting']);
}

// A phone number only when the JID actually carries one. An @lid is an opaque
// identifier that merely looks like a number, and storing it as a customer's
// phone would put a fabricated number on an appointment.
function chatbotPhoneFromJid($jid) {
    if (!str_ends_with((string)$jid, '@s.whatsapp.net')) return null;
    $digits = preg_replace('/\D+/', '', explode('@', $jid)[0]);
    return $digits !== '' ? $digits : null;
}

function chatbotAccountForSession(mysqli $conn, $sessionId) {
    $stmt = $conn->prepare("SELECT id, user_id, session_id FROM wa_accounts WHERE session_id = ?");
    $stmt->bind_param('s', $sessionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function chatbotOutcomeLabel($outcome) {
    return [
        'replied'              => 'Replied',
        'skipped_group'        => 'Skipped — group chat',
        'skipped_archived'     => 'Skipped — archived chat',
        'skipped_own_message'  => 'Skipped — own message',
        'skipped_self_chat'    => 'Skipped — own number',
        'skipped_notify_number'=> 'Skipped — handover notification number',
        'skipped_broadcast'    => 'Skipped — status broadcast',
        'skipped_newsletter'   => 'Skipped — channel',
        'skipped_disabled'     => 'Skipped — chatbot off',
        'skipped_handoff'      => 'Silent — with a person',
        'handoff'              => 'Passed to a person',
        'skipped_hours'        => 'Outside active hours',
        'skipped_empty'        => 'Skipped — nothing to answer',
        'quota'                => 'Blocked — monthly message limit reached',
        'quota_replies'        => 'Blocked — monthly AI reply limit reached',
        'config_error'         => 'Not configured',
        'llm_error'            => 'Model error',
        'send_error'           => 'Could not send the reply',
    ][$outcome] ?? $outcome;
}

// What to do about it, in the tenant's terms.
//
// The activity list used to print the label and then the raw `detail` string
// underneath — internal text written for whoever debugs the reply path ("audio:
// no transcript", "limit 500", "test console: Choose a model for your own API
// key"). It told a business owner that something went wrong and nothing about
// what to change. The label says what happened; this says what to do, and an
// outcome that needs nothing returns ''.
//
// Detail is not dropped: chatbot.php keeps it as the row's tooltip, so support
// can still read it.
function chatbotOutcomeHint($outcome) {
    return [
        'config_error'      => 'The bot was not usable as configured. Check the Model tab — and your own API key if you use one.',
        'llm_error'         => 'The AI provider failed to answer. Your fallback message was sent instead, if you have one.',
        'send_error'        => 'The reply was written but WhatsApp would not take it. Check the account is still connected.',
        'quota'             => 'Your monthly message allowance is used up. It resets next month, or you can upgrade your plan.',
        'quota_replies'     => 'Your monthly AI reply allowance is used up. It resets next month, or you can upgrade your plan.',
        'skipped_hours'     => 'This came in outside your active hours.',
        'skipped_empty'     => 'There was nothing the bot could read — an attachment with no text, or a voice note it could not transcribe.',
        'skipped_handoff'   => 'A person is handling that conversation, so the bot stayed silent.',
        'handoff'           => 'Someone asked for a person. Open Live chats to reply.',
        'skipped_disabled'  => 'The chatbot was switched off when this arrived.',
        'skipped_notify_number' => 'That is the number your handover alerts go to, so the bot never answers it.',
    ][$outcome] ?? '';
}
