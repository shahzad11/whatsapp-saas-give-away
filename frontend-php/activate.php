<?php
require_once __DIR__ . '/config/init.php';

$token = $_GET['token'] ?? '';

if (empty($token)) {
    flash('error', 'Invalid activation link.');
    redirect(APP_URL . '/login.php');
}

$stmt = $conn->prepare("SELECT id FROM users WHERE activation_token = ? AND is_active = 0");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    flash('error', 'Activation link is invalid or account is already active.');
    redirect(APP_URL . '/login.php');
}

$stmt = $conn->prepare("UPDATE users SET is_active = 1, activation_token = NULL WHERE id = ?");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$stmt->close();

flash('success', 'Account activated successfully! You can now sign in.');
redirect(APP_URL . '/login.php');
