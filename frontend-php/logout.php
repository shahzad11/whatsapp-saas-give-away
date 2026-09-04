<?php
require_once __DIR__ . '/config/init.php';
logoutUser();
redirect(APP_URL . '/login.php');
