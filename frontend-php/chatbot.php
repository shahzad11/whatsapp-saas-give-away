<?php
// Gone to settings.php (#41). This is the bookmark, not the page.
//
// The whole chatbot configuration area moved when it stopped being only about
// the chatbot: it also owns appointments and human handover, and "Chatbot" was
// the wrong name on the door. Old links live on in bookmarks, in emails and in
// screenshots, so this stays as a redirect rather than being deleted.
//
// config/app.php only, not config/init.php: a redirect needs the app's URL and
// nothing else. There is no session to start, no database to open and no login
// to require — the destination enforces all three, and requiring one here would
// send an anonymous visitor to the login page and lose the URL they asked for.
require_once __DIR__ . '/config/app.php';

// The tab lives in the fragment, which never reaches the server. A browser
// reapplies the original fragment to a redirect target that has none, so the
// destination deliberately does not carry one and `chatbot.php#tab-appointments`
// still arrives on the Appointments tab. Query strings do reach us; they are
// forwarded verbatim.
$query = (string)($_SERVER['QUERY_STRING'] ?? '');
$target = APP_URL . '/settings.php' . ($query !== '' ? '?' . $query : '');

// 308 rather than 301 for anything that is not a GET. Both are permanent, but
// 301 lets a browser turn a POST into a GET — which for a tenant who still has
// the old page open in a tab would mean clicking Save and silently losing every
// edit on it.
$permanent = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? 301 : 308;

header('Location: ' . $target, true, $permanent);
exit;
