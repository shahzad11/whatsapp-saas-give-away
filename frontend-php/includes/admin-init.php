<?php

// Single entry point for every page under /admin/.
//
// Admin pages require *this* instead of config/init.php, which makes the guard
// structural rather than a convention: the bootstrap that hands a page $conn is
// the same one that gates it, so a new admin page cannot be written that
// forgets requireAdmin(). Visibility of a nav link is never the control —
// requireAdmin() runs before any POST handling or query on every request.

require_once dirname(__DIR__) . '/config/init.php';

requireAdmin();

// Every admin page needs this to enforce the self-protection rules (an admin
// may not suspend or demote themselves — that leaves the instance
// unadministrable).
$adminId = (int)$_SESSION['user_id'];
