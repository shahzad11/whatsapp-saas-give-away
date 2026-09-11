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

// Lead generation (#50) is loaded here rather than in config/init.php because
// it is the only subsystem that is genuinely admin-only: `leads` has no
// user_id, nothing tenant-facing reads it, and there is no path from a tenant
// page to any of it. Loading it from the admin bootstrap keeps that true by
// construction — a tenant page cannot call leadsSearch() by accident, because
// the function does not exist on that request.
require_once __DIR__ . '/leads.php';

// Every admin page needs this to enforce the self-protection rules (an admin
// may not suspend or demote themselves — that leaves the instance
// unadministrable).
$adminId = (int)$_SESSION['user_id'];
