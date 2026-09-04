<?php
require_once __DIR__ . '/config/init.php';

if (isLoggedIn()) {
    redirect(APP_URL . '/dashboard.php');
} else {
    redirect(APP_URL . '/login.php');
}
