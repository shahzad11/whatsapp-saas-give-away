<?php
// How a tenant is told to contact sales (#43).
//
// Run with:  php tests/billing-contact-test.php
//
// The plan cards on billing.php are the only place in the app that asks a tenant
// to start a conversation with the owner, and they used to point at MAIL_FROM —
// the SMTP envelope sender, which on most instances is a no-reply mailbox. What
// replaced it is configuration, and configuration has edge cases: a method ticked
// with nothing behind it, a number that is not a number, a preferred method that
// was later switched off, a plan name with an ampersand in it.
//
// No database, like tests/appointments-test.php: billingContactFrom() is the rule
// and billingContactConfig() is the two lines that read the rows, so everything
// worth asserting is a pure function of what was saved.

require_once __DIR__ . '/../frontend-php/includes/functions.php';   // e164Digits(), sanitize()
require_once __DIR__ . '/../frontend-php/includes/billing.php';

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

// Everything filled in, as an admin who completed the form would leave it.
function saved(array $overrides = []) {
    return $overrides + [
        'methods' => 'email,whatsapp,phone',
        'email'   => 'sales@example.com',
        'phone'   => '923001234567',
        'label'   => '',
        'primary' => '',
    ];
}

$plan = ['name' => 'Growth', 'id' => 2];
$who = ['tenant' => 'Acme Salon', 'email' => 'owner@acme.test'];

// A link of one kind out of a configuration, or null.
function link_for(array $raw, $method, array $plan, array $who = []) {
    $config = billingContactFrom($raw);
    foreach (billingContactLinks(null, $plan, $who, $config) as $link) {
        if ($link['method'] === $method) return $link;
    }
    return null;
}

// --- One method at a time ----------------------------------------------------

group('Email');

$config = billingContactFrom(saved(['methods' => 'email']));
equals('only email is enabled', ['email'], $config['methods']);

$email = link_for(saved(['methods' => 'email']), 'email', $plan, $who);
check('the address is the one the admin saved, not the SMTP sender',
    str_starts_with($email['url'], 'mailto:sales@example.com?'));
check('the plan is named in the subject',
    str_contains($email['url'], 'subject=' . rawurlencode('Plan enquiry: Growth')));
check('and the tenant is identifiable from the body',
    str_contains($email['url'], rawurlencode('Acme Salon'))
    && str_contains($email['url'], rawurlencode('owner@acme.test')));

group('WhatsApp');

$wa = link_for(saved(['methods' => 'whatsapp']), 'whatsapp', $plan, $who);
equals('wa.me takes bare digits, with no plus',
    'https://wa.me/923001234567', substr($wa['url'], 0, strlen('https://wa.me/923001234567')));
check('the message is prefilled with the plan', str_contains($wa['url'], rawurlencode('Growth')));
check('and with who is asking', str_contains($wa['url'], rawurlencode('Acme Salon')));

group('Phone');

$phone = link_for(saved(['methods' => 'phone']), 'phone', $plan);
equals('tel: takes the international form, with the plus', 'tel:+923001234567', $phone['url']);

// --- Several at once ---------------------------------------------------------

group('Several methods, in the order the admin asked for');

equals('all three are offered', ['email', 'whatsapp', 'phone'],
    billingContactFrom(saved())['methods']);

$config = billingContactFrom(saved(['primary' => 'whatsapp']));
equals('the preferred method leads', 'whatsapp', $config['methods'][0]);
equals('and the rest keep their order', ['whatsapp', 'email', 'phone'], $config['methods']);
equals('with nothing lost', 3, count(billingContactLinks(null, $plan, [], $config)));

equals('an unticked method cannot be the preferred one',
    'email', billingContactFrom(saved(['methods' => 'email', 'primary' => 'phone']))['primary']);
equals('and the preference is corrected rather than obeyed',
    ['email'], billingContactFrom(saved(['methods' => 'email', 'primary' => 'phone']))['methods']);

// --- Values that cannot be honoured ------------------------------------------

group('A method with nothing behind it is not offered');

equals('email ticked with no address', [],
    billingContactFrom(saved(['methods' => 'email', 'email' => '']))['methods']);
equals('email ticked with an address that is not one', [],
    billingContactFrom(saved(['methods' => 'email', 'email' => 'sales at example dot com']))['methods']);
equals('WhatsApp and phone ticked with no number', [],
    billingContactFrom(saved(['methods' => 'whatsapp,phone', 'phone' => '']))['methods']);

// The E.164 rule, which is the handover number's rule (#26) — a local number with
// a trunk prefix cannot be corrected without knowing the country, so it is
// refused rather than guessed at and turned into an unreachable button.
equals('a local number with a leading zero is refused', [],
    billingContactFrom(saved(['methods' => 'phone', 'phone' => '03001234567']))['methods']);
equals('so is one too short to be international', [],
    billingContactFrom(saved(['methods' => 'phone', 'phone' => '12345']))['methods']);
equals('a number written with spaces and a plus is still fine', '923001234567',
    billingContactFrom(saved(['phone' => '+92 300 1234567']))['phone']);

group('The email survives a broken number, and the number a broken address');

$mixed = billingContactFrom(saved(['methods' => 'email,whatsapp', 'phone' => '0300']));
equals('one bad value does not take the good one with it', ['email'], $mixed['methods']);
equals('and the bad one is not stored as something half-usable', '', $mixed['phone']);

group('Nothing configured at all');

$none = billingContactFrom(['methods' => '', 'email' => '', 'phone' => '', 'label' => '', 'primary' => '']);
equals('no methods', [], $none['methods']);
equals('no preferred method to speak of', '', $none['primary']);
equals('and no buttons to render', [], billingContactLinks(null, $plan, [], $none));
equals('a missing configuration reads exactly like an empty one', [],
    billingContactFrom([])['methods']);

// --- The button's label -------------------------------------------------------

group('The label');

equals('blank falls back to the built-in wording',
    billingContactDefaultLabel(), billingContactFrom(saved())['label']);
equals('and an admin\'s own wording is kept',
    'Talk to us about upgrading',
    billingContactFrom(saved(['label' => '  Talk to us about upgrading  ']))['label']);

// The label is admin-authored text rendered on every tenant's billing page, so
// the page escapes it. Asserted here because the *combination* is the control:
// the config layer stores what was typed, and nothing reaches HTML unescaped.
$hostile = billingContactFrom(saved(['label' => '"><script>alert(1)</script>']));
equals('a hostile label is stored verbatim, not silently mangled',
    '"><script>alert(1)</script>', $hostile['label']);
check('and cannot escape an attribute once rendered',
    !str_contains(sanitize($hostile['label']), '<script>')
    && !str_contains(sanitize($hostile['label']), '">'));

// --- Plan names ---------------------------------------------------------------

group('A plan name goes into a URL, so it is encoded');

$awkward = ['name' => 'Pro & Team #2', 'id' => 3];

$email = link_for(saved(['methods' => 'email']), 'email', $awkward);
check('the ampersand cannot start a new query parameter',
    str_contains($email['url'], rawurlencode('Pro & Team #2')));
check('and the fragment marker cannot truncate the message',
    !str_contains($email['url'], '#2'));

$wa = link_for(saved(['methods' => 'whatsapp']), 'whatsapp', $awkward);
check('the same holds for the prefilled WhatsApp text',
    str_contains($wa['url'], rawurlencode('Pro & Team #2')) && !str_contains($wa['url'], '#2'));

// A plan card with no name is not a thing the app can produce, but a URL that
// says "I would like to move to the plan" is still better than one that breaks.
$nameless = link_for(saved(['methods' => 'email']), 'email', ['name' => '']);
check('a nameless plan still produces a working link',
    str_starts_with($nameless['url'], 'mailto:sales@example.com?subject='));

group('Every rendered URL survives being escaped');

foreach (billingContactLinks(null, $plan, $who, billingContactFrom(saved())) as $link) {
    check('the ' . $link['method'] . ' link is a plain URL with no markup in it',
        !preg_match('/[<>"\']/', $link['url']), $link['url']);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
