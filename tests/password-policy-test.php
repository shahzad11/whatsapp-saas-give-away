<?php
// The shared password policy (#24), without a database.
//
// Run with:  php tests/password-policy-test.php
//
// passwordProblem() is pure — it reads a bundled blocklist file, not the
// database — so the rule every password form enforces can be exercised here.

$app = dirname(__DIR__) . '/frontend-php';
require_once $app . '/includes/auth.php';

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

$ctx = ['email' => 'sadia@example.com', 'name' => 'Sadia Khan'];

// Rejections ---------------------------------------------------------------
check('under 10 characters is refused',
    passwordProblem('N0t!short', $ctx) !== null);
check('over 72 bytes is refused (bcrypt truncates)',
    passwordProblem(str_repeat('a9', 37), $ctx) !== null);
check('a blocklisted password is refused',
    passwordProblem('basketball', $ctx) !== null);
check('blocklist matching is case-insensitive',
    passwordProblem('BASKETBALL', $ctx) !== null);
check('containing the email local part is refused',
    passwordProblem('Sadia9xQ7w!', $ctx) !== null);
check('containing the name is refused',
    passwordProblem('khan9xQ7wzz', $ctx) !== null);

// Acceptances --------------------------------------------------------------
check('a good passphrase passes',
    passwordProblem('copper-meadow-lantern-72', $ctx) === null);
check('a name shorter than 4 chars does not trigger the contains rule',
    passwordProblem('bo9xQ7wzzkT!', ['email' => 'bo@example.com', 'name' => 'Bo']) === null);
check('empty context does not fatal',
    passwordProblem('copper-meadow-lantern-72', []) === null);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
