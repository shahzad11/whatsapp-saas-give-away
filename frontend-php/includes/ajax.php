<?php

// One reply, two audiences (#24, #25).
//
// Every form on this instance still works with JavaScript switched off. The
// handlers were all written the same way — validate, flash(), redirect() — and
// converting them to AJAX by giving each one a second code path would mean two
// implementations of the same rules, which is exactly how a validation check
// ends up enforced in one of them and not the other.
//
// So the handlers keep their single path and end at formRespond(). When the
// request came from fetch() it answers JSON and the page updates in place; when
// it came from a plain form submit it flashes and redirects, byte for byte what
// it did before. Server-side validation, CSRF and audit logging are untouched
// either way — they run before this function is ever reached.

// Did this request come from our own fetch() wrapper?
//
// The custom header is the test, not Accept: a header a form submit cannot set
// is what makes the distinction reliable. It is also why it is safe to key
// behaviour on — a cross-site form post cannot add it, so it can never turn a
// redirect into a JSON body that discloses something.
//
// Named for the header rather than for JSON because two kinds of reply hang off
// it: a JSON result from a form handler, and an HTML fragment for a modal that
// loads its form populated (admin/plans.php).
function isXhrRequest() {
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function jsonOut(array $payload, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

// The single exit for a form handler.
//
// $errors is the same per-field array the pages already build for their sErr()/
// pErr() helpers, so an AJAX submit shows the identical messages next to the
// identical fields.
//
// $redirect is where a non-JS submit goes. On the JSON path it is passed through
// rather than followed: some saves genuinely need a new page (a created tenant),
// most just need the listing redrawn, and the caller is the only thing that
// knows which.
function formRespond($ok, $message, $redirect, array $errors = [], array $extra = []) {
    if (isXhrRequest()) {
        jsonOut($extra + [
            'ok' => (bool)$ok,
            'message' => (string)$message,
            'errors' => $errors,
        ]);
    }

    flash($ok ? 'success' : 'error', $message);
    redirect($redirect);
}

// A validation failure on a page that re-renders its own form with what was
// typed.
//
// formRespond() would redirect the non-JS path, and a redirect throws away
// everything in the form — which is precisely the behaviour those pages were
// written to avoid. So on the JSON path this answers and exits, and on the plain
// path it flashes and *returns*, leaving the page to fall through to its normal
// render. The distinction is why there are two functions rather than a flag: the
// caller has to know that this one comes back.
function formErrors($message, array $errors = []) {
    if (isXhrRequest()) {
        jsonOut(['ok' => false, 'message' => (string)$message, 'errors' => $errors]);
    }
    flash('error', $message);
}

// A CSRF failure is a hard stop in both worlds. Kept here so no handler has to
// remember that the JSON path must not fall through to redirect().
function formRequireCsrf($redirect) {
    if (csrfTokenValid($_POST['csrf_token'] ?? '')) return;

    if (isXhrRequest()) {
        // 403, not 419. "419 Page Expired" is a framework convention, not a
        // registered HTTP status, and Apache does not recognise it: it replaced
        // the response with a 500, so an expired token looked to the browser like
        // the server had crashed. The JSON body was correct all along — only the
        // status was being rewritten underneath it.
        jsonOut(['ok' => false, 'message' => 'Your session expired. Reload the page and try again.', 'errors' => []], 403);
    }
    flash('error', 'Invalid request.');
    redirect($redirect);
}
