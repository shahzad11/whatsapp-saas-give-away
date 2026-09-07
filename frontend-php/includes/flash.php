<?php

// One place that renders a flash message (#33 §7, §18).
//
// Twelve pages each carried their own copy of the same
// "if ($msg = flash('success')) print an .alert" block, and they had already
// drifted: four were dismissible and eight were not, two
// rendered only 'success' and dropped 'error' entirely, and none of them
// rendered 'warning' or 'info' — so flash('warning', ...) from any handler was
// a message the tenant never saw. That is the real cost of the duplication, and
// it is why this is a function and not a copy-paste fix.
//
// It is called from the two layouts, not from the pages, so a page added later
// gets flash rendering by existing.
//
// **In-flow alerts are the fallback here, not the default.** An alert prepended
// into the content pushed the whole page down by its own height the instant a
// save finished, which moves the thing you were about to click. So the message
// is handed to the same toast container forms.js already uses for AJAX saves —
// a save now looks identical whichever path it took, which it did not before.

// The keys rendered, in the order a reader should see them: what went wrong
// first, then what to be careful of, then what worked.
const FLASH_KEYS = [
    'error'   => 'danger',
    'warning' => 'warning',
    'info'    => 'info',
    'success' => 'success',
];

// Drains every flash key into [['type' => ..., 'message' => ...], ...].
//
// Draining is the point: flash() unsets on read, so this must be called exactly
// once per request. Calling it from a layout guarantees that — a page cannot
// half-consume the queue and leave the rest to be shown on the next request,
// which is what happened when a page rendered 'success' and never read 'error'.
function drainFlash() {
    $out = [];
    foreach (FLASH_KEYS as $key => $variant) {
        $msg = flash($key);
        if ($msg === null || $msg === '') continue;
        $out[] = ['type' => $variant, 'message' => (string)$msg];
    }
    return $out;
}

// Emits the messages for the current request. Nothing at all when there are
// none — not an empty container, because an empty positioned div is still a div
// that can intercept a click.
function renderFlash() {
    $messages = drainFlash();
    if (!$messages) return;

    // A data island rather than an inline script: forms.js owns the toast
    // container and is loaded from the footer, so a <script> here would run
    // before waToast() exists.
    //
    // Two separate escapes, and both are load-bearing:
    //
    //  * The JSON flags handle what is *inside* a message — a message can carry
    //    a plan name, an email address or an SMTP server's own error string.
    //  * htmlspecialchars() handles the JSON document itself. JSON_HEX_QUOT only
    //    escapes quotes inside string *values*; the structural quotes around
    //    every key stay literal, so without this the attribute ends at the first
    //    `{"` and the payload is silently truncated to two characters. The
    //    browser decodes the entities back before the dataset is read.
    $json = json_encode($messages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo '<div id="waFlash" hidden data-flash="'
        . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '"></div>' . "\n";

    // Without JavaScript there are no toasts, and a confirmation that only
    // exists in a toast would simply not be delivered — so the alerts the pages
    // used to render are still here, for exactly the case they are needed in.
    echo "<noscript>\n";
    foreach ($messages as $m) {
        echo '    <div class="alert alert-' . $m['type'] . '">' . sanitize($m['message']) . "</div>\n";
    }
    echo "</noscript>\n";
}
