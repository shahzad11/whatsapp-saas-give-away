<?php
// The admin console is admin-only, and public sign-up is gone (#48).
//
// Run with:  php tests/admin-access-test.php
//
// Two kinds of check live here, and the first kind is the point of the file.
//
// **Structural.** Every page under /admin must bootstrap through
// includes/admin-init.php, which is the file that calls requireAdmin(). That is
// not a convention a reviewer has to remember — it is asserted here, so adding
// an admin page that requires config/init.php directly fails the suite instead
// of shipping an unguarded route. The issue asks for exactly this: "add a
// regression test that fails if a new admin route omits it".
//
// **Absence.** register.php, ALLOW_REGISTRATION, allowRegistration() and the
// `allow_registration` setting must all stay gone. A feature removed for
// security reasons has a way of coming back, and the only cheap defence is a
// test that names it.
//
// No database and no HTTP: these are assertions about the source tree, which is
// what makes them run anywhere and in milliseconds. The behavioural half —
// "does an anonymous request actually get a 302" — is exercised against the
// real server in the deployment verification, because that one genuinely needs
// Apache, MySQL and a session.

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

// Comments out, code in. Several checks below assert that a name does not
// appear, and every one of those names is also *explained* in a comment
// somewhere — a removal is worth a note saying what was removed and why. Using
// token_get_all() rather than a regex because a `//` inside a string literal is
// not a comment, and this file's whole job is to be trusted.
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

// Every .php file directly under admin/, which is every admin route. Partials
// are handled separately below: they are not routes and must not be reachable
// at all.
function adminRoutes($app) {
    $found = glob($app . '/admin/*.php');
    sort($found);
    return $found;
}

// ---------------------------------------------------------------------------
group('Every admin route is guarded by admin-init.php');

$routes = adminRoutes($app);
check('there are admin routes to check at all', count($routes) > 0,
    'glob found nothing — has the directory moved?');

foreach ($routes as $file) {
    $name = 'admin/' . basename($file);
    $src = file_get_contents($file);

    // The guard has to be the bootstrap, so it runs before any POST handling or
    // query. Requiring it further down the file would still call requireAdmin()
    // eventually, but not before the code above it had already run.
    check("{$name} requires includes/admin-init.php",
        str_contains($src, "/includes/admin-init.php'"),
        'an admin page must bootstrap through admin-init.php, which calls requireAdmin()');

    // The specific mistake this catches: copying a tenant-facing page as the
    // starting point for a new admin one. config/init.php hands over $conn and
    // a session but guards nothing.
    check("{$name} does not bootstrap through config/init.php instead",
        !preg_match('#require(_once)?\s+.*/config/init\.php#', $src),
        'config/init.php gives a page $conn without gating it');
}

// ---------------------------------------------------------------------------
group('admin-init.php is the guard it is relied on to be');

$init = file_get_contents($app . '/includes/admin-init.php');
check('admin-init.php calls requireAdmin()', str_contains($init, 'requireAdmin()'));
check('admin-init.php loads the app bootstrap', str_contains($init, "/config/init.php'"));

$auth = file_get_contents($app . '/includes/auth.php');
// requireAdmin() must not be reachable by someone who is merely logged in, and
// must not decide anything from a session flag an attacker could set: it reads
// is_admin from the users row on every call.
check('requireAdmin() requires a login first', preg_match('/function requireAdmin\(\)\s*\{\s*requireLogin\(\);/', $auth) === 1);
check('isAdmin() reads is_admin from the database row, not the session',
    str_contains($auth, "(int)\$user['is_admin'] === 1")
    && !preg_match("/\\\$_SESSION\['is_admin'\]/", $auth));
check('requireLogin() re-checks status and is_active on every request',
    str_contains($auth, "\$user['status'] === 'suspended'") && str_contains($auth, "!\$user['is_active']"));

// ---------------------------------------------------------------------------
group('Partials are not routes');

foreach (glob($app . '/admin/partials/*.php') as $file) {
    $name = 'admin/partials/' . basename($file);
    $src = file_get_contents($file);
    // Defence in depth against the vhost: a fragment that renders admin markup
    // must refuse to execute when it was requested directly rather than
    // included by a page that already ran requireAdmin().
    check("{$name} refuses to run standalone",
        str_contains($src, "function_exists('csrfField')"),
        'a directly requested partial would render admin markup with no guard');
}

$vhost = file_get_contents($root . '/docker/php/vhost.conf');
check('the vhost denies partials directories',
    str_contains($vhost, 'partials') && preg_match('/DirectoryMatch.*partials/', $vhost) === 1);
check('the vhost still denies config, includes, sql and logs',
    preg_match('/DirectoryMatch "\^\/var\/www\/app\/\(config\|includes\|sql\|logs\)"/', $vhost) === 1);

// ---------------------------------------------------------------------------
group('Public registration is gone and cannot be switched back on');

check('register.php does not exist', !file_exists($app . '/register.php'));

// Not "is false" — absent. A constant that still existed would be a switch with
// nothing behind it, and the next person to find it would assume flipping it
// reopened sign-up.
$appConfig = file_get_contents($app . '/config/app.php');
check("ALLOW_REGISTRATION is not defined",
    !preg_match("/define\('ALLOW_REGISTRATION'/", $appConfig));

$settings = file_get_contents($app . '/includes/settings.php');
check('allowRegistration() no longer exists',
    !preg_match('/function allowRegistration/', $settings));

// Any *call* anywhere would now be a fatal, so this is also a plain correctness
// check on the removal having been finished.
$callers = [];
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app));
foreach ($iter as $f) {
    if ($f->getExtension() !== 'php') continue;
    $src = file_get_contents($f->getPathname());
    // Comments legitimately mention the name to explain the removal, so only
    // real call sites count.
    if (preg_match('/(?<!function )allowRegistration\s*\(/', preg_replace('~//.*~', '', $src))) {
        $callers[] = $f->getPathname();
    }
}
check('nothing calls allowRegistration()', $callers === [], implode(', ', $callers));

$schema = file_get_contents($app . '/sql/schema.sql');
check('schema.sql deletes the allow_registration setting row',
    str_contains($schema, "DELETE FROM app_settings WHERE setting_key = 'allow_registration'"),
    'a restored dump taken while sign-up was open would otherwise reinstate it');

// Comments stripped first. login.php legitimately explains *why* there is no
// sign-up link, and a test that cannot tell an explanation from a link would
// force the explanation to be deleted to stay green.
$login = stripPhpComments(file_get_contents($app . '/login.php'));
check('login.php offers no way to register',
    !str_contains($login, 'register.php')
    && !preg_match('/Create one|Sign up|Create account/i', $login));

$compose = file_get_contents($root . '/docker-compose.yml');
check('docker-compose.yml no longer passes ALLOW_REGISTRATION',
    !str_contains($compose, 'ALLOW_REGISTRATION'));

$install = file_get_contents($root . '/deploy/install.sh');
check('install.sh no longer writes ALLOW_REGISTRATION into .env',
    !preg_match('/^ALLOW_REGISTRATION=/m', $install));

// ---------------------------------------------------------------------------
group('Tenant creation is admin-only, validated and audited');

$tenant = file_get_contents($app . '/includes/tenant.php');
check('createTenant() exists in includes/tenant.php', str_contains($tenant, 'function createTenant('));
check('it audits the creation', str_contains($tenant, "'admin.user.create'"));
// The variable, not the string "temp_password" — 'admin.user.temp_password' is
// an audit *action name* and is exactly the row that should be written. What
// must never appear in a logAudit() call is $tempPassword, the plaintext.
check('it never writes the plaintext password to the audit log',
    !preg_match('/logAudit\((?:[^()]|\([^()]*\))*\$tempPassword/s', $tenant));
check('the temporary password is hashed before it is stored',
    str_contains($tenant, 'password_hash($tempPassword, PASSWORD_DEFAULT)'));
check('an invited account gets a password nobody holds',
    str_contains($tenant, 'function unusablePassword()')
    && str_contains($tenant, 'random_bytes(64)'));
check('the invitation expiry is computed by MySQL, on the clock it is read against',
    str_contains($tenant, 'DATE_ADD(NOW(), INTERVAL ? HOUR)'));
check('the last usable admin is protected', str_contains($tenant, 'function wouldOrphanInstance('));

$tenantsPage = file_get_contents($app . '/admin/tenants.php');
check('the create action is CSRF-protected like every other action',
    str_contains($tenantsPage, 'formRequireCsrf($self)'));
check('the create action calls the shared helper rather than inlining an INSERT',
    str_contains($tenantsPage, 'createTenant($conn')
    && !preg_match('/INSERT INTO users/', $tenantsPage));
check('suspend and toggle_admin are refused when they would orphan the instance',
    preg_match("/wouldOrphanInstance\(\\\$conn, \\\$targetId\)/", $tenantsPage) === 1);
check('an admin still cannot change their own access',
    str_contains($tenantsPage, '$targetId === $adminId'));
check('a one-time secret is passed in the session, never in the URL',
    str_contains($tenantsPage, "\$_SESSION['admin_tenant_created']")
    && !preg_match('/temp_password=/', $tenantsPage));

// ---------------------------------------------------------------------------
group('A temporary password buys exactly one login');

check('requireLogin() enforces the password change',
    str_contains($auth, 'requirePasswordChanged($user)'));
check('the forced-change page and logout are the only exemptions',
    preg_match("/in_array\(\\\$page, \['set-password', 'logout'\], true\)/", $auth) === 1);
check('set-password.php exists', file_exists($app . '/set-password.php'));

$setPassword = file_get_contents($app . '/set-password.php');
check('it proves the person at the keyboard holds the temporary password',
    str_contains($setPassword, 'password_verify($current'));
check('it refuses reusing the temporary password as the new one',
    str_contains($setPassword, '$new === $current'));
check('it clears the flag and rotates the session',
    str_contains($setPassword, 'must_change_password = 0')
    && str_contains($setPassword, 'session_regenerate_id(true)'));

// Every other page that can set a password must clear the flag too, or a tenant
// who took a different route stays locked in a redirect loop.
foreach (['reset-password.php', 'profile.php'] as $page) {
    check("{$page} clears must_change_password when a password is set",
        str_contains(file_get_contents($app . '/' . $page), 'must_change_password = 0'));
}

$schemaHasColumn = str_contains($schema, "COLUMN_NAME = 'must_change_password'")
    && str_contains($schema, 'ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0');
check('the column is added idempotently and defaults to 0 for existing accounts', $schemaHasColumn);

group('The admin help-image route serves only its allowlist');

$helpImage = file_exists($app . '/admin/help-image.php')
    ? file_get_contents($app . '/admin/help-image.php') : '';
check('admin/help-image.php exists', $helpImage !== '',
    'the guide references it — a missing route is a broken image');
if ($helpImage !== '') {
    check('filenames come from a fixed allowlist, not the query string',
        str_contains($helpImage, 'in_array($file, $allowed, true)'),
        'reading $path directly from the request would allow traversal');
    check('the served path is built inside includes/admin-help-images',
        str_contains($helpImage, "admin-help-images"),
        'the directory the vhost denies is what keeps these files off the public web');
    check('unknown names are a 404, not an error with content',
        str_contains($helpImage, 'http_response_code(404)'));
    check('responses are marked private and non-sniffable',
        str_contains($helpImage, 'X-Content-Type-Options: nosniff')
        && str_contains($helpImage, 'Cache-Control: private, no-store'));
}
check('guide screenshots are not in the publicly served assets tree',
    !is_dir($app . '/assets/images/admin-help') && !glob($app . '/assets/**/admin-*.png'),
    'anything under assets/ is downloadable without a session');

// ---------------------------------------------------------------------------
echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
