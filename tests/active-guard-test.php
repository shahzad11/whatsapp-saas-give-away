<?php
// Every AJAX endpoint runs the active-user guard, not a bare session check (#3).
//
// Run with:  php tests/active-guard-test.php
//
// Structural assertions, no database and no HTTP — same style as
// admin-access-test.php. isLoggedIn() is "a session exists"; it says nothing
// about suspension, activation or whether the session was minted before a
// password change (#13). The guard that actually answers all three is
// requireActiveUser(), and this test fails if an endpoint stops calling it.

$root = dirname(__DIR__);
$app  = $root . '/frontend-php';

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

function group($title) {
    echo "\n{$title}\n";
}

// Comments out, code in — same approach as admin-access-test.php: a mention of
// isLoggedIn() inside a comment is documentation, not a gate.
function stripPhpComments($src) {
    $out = '';
    foreach (token_get_all($src) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
            $out .= $token[1];
            continue;
        }
        $out .= $token;
    }
    return $out;
}

// ---------------------------------------------------------------------------
group('Every file under whatsapp/ajax/ and ajax/ calls the active-user guard');

$dirs = [$app . '/whatsapp/ajax', $app . '/ajax'];
$files = [];
foreach ($dirs as $dir) {
    foreach (glob($dir . '/*.php') as $f) $files[] = $f;
}
sort($files);
check('there are ajax endpoints to check at all', count($files) > 0,
    'glob found nothing — have the directories moved?');

foreach ($files as $file) {
    $name = basename(dirname($file)) === 'ajax'
        ? basename(dirname(dirname($file))) . '/ajax/' . basename($file)
        : 'ajax/' . basename($file);
    $src = stripPhpComments(file_get_contents($file));

    $guarded = str_contains($src, 'requireActiveUserJson(')
        || str_contains($src, 'requireActiveUser(')
        || str_contains($src, 'requireOwnedAccount(');
    check("{$name} uses the active-user guard", $guarded,
        'a bare session check lets a suspended tenant keep calling it');

    check("{$name} does not gate on a bare isLoggedIn()",
        !preg_match('/if\s*\(\s*!?\s*isLoggedIn\s*\(/', $src),
        'isLoggedIn() as the gate is the hole this guard exists to close');
}

// ---------------------------------------------------------------------------
group('The guard itself lives where it is relied on to be');

$auth = stripPhpComments(file_get_contents($app . '/includes/auth.php'));
check('requireActiveUser() exists', str_contains($auth, 'function requireActiveUser('));
check('requireActiveUserJson() exists', str_contains($auth, 'function requireActiveUserJson('));
check('it rejects suspended and inactive users',
    str_contains($auth, "\$user['status'] === 'suspended'") && str_contains($auth, "!\$user['is_active']"));
check('it rejects a stale session_version',
    str_contains($auth, "session_version") && str_contains($auth, "logoutUser()"));
check('it 403s a temporary-password session toward set-password.php',
    str_contains($auth, 'must_change_password') && str_contains($auth, "set-password.php"));

$tenant = stripPhpComments(file_get_contents($app . '/includes/tenant.php'));
check('requireOwnedAccount() calls requireActiveUser()',
    preg_match('/function requireOwnedAccount\([^)]*\)\s*\{[^}]*requireActiveUser\(/s', $tenant) === 1);

// ---------------------------------------------------------------------------
group('The chatbot refuses suspended tenants');

$chatbot = stripPhpComments(file_get_contents($app . '/includes/chatbot.php'));
check('chatbotHandleInbound contains skipped_suspended',
    str_contains($chatbot, 'skipped_suspended'));
check('chatbotAccountForSession joins the users row',
    str_contains($chatbot, 'owner_status') && str_contains($chatbot, 'owner_active'));

$reminders = stripPhpComments(file_get_contents($app . '/internal/appointment-reminders.php'));
check('the reminder tick skips suspended owners',
    str_contains($reminders, "'customer account suspended'"));

// ---------------------------------------------------------------------------
echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
