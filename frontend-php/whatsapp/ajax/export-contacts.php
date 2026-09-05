<?php
require_once dirname(__DIR__, 2) . '/config/init.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$userId = (int)$_SESSION['user_id'];

// The plan's `csv_export` lever. This endpoint is the gate; hiding the button on
// contacts.php is only presentation, and a bookmarked URL would bypass it.
if (!planHasFeature(getUserPlan($conn, $userId), 'csv_export')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'CSV export is not part of your plan.';
    exit;
}

$userTz = getUserTimezone($conn, $userId);

// --- Query params (same as contacts.php) ---
$search        = trim($_GET['search'] ?? '');
$accountFilter = (int)($_GET['account'] ?? 0);
$typeFilter    = $_GET['type'] ?? '';

// --- Build WHERE clause ---
$where  = "WHERE wa.user_id = ?";
$params = [$userId];
$types  = "i";

if ($search !== '') {
    $where .= " AND (c.contact_name LIKE ? OR c.chat_id LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

if ($accountFilter > 0) {
    $where .= " AND c.account_id = ?";
    $params[] = $accountFilter;
    $types .= "i";
}

if ($typeFilter === 'individual') {
    $where .= " AND c.is_group = 0";
} elseif ($typeFilter === 'group') {
    $where .= " AND c.is_group = 1";
}

// --- Fetch all matching contacts ---
$sql = "SELECT c.contact_name, c.chat_id, c.phone_number, c.is_group, c.last_message, c.last_message_time,
               wa.label as account_label, wa.phone_number as account_phone
        FROM wa_contacts c
        JOIN wa_accounts wa ON c.account_id = wa.id
        $where
        ORDER BY c.last_message_time DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// --- Stream CSV ---
$filename = 'whatsapp-contacts-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
// BOM for Excel UTF-8 compatibility
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Contact Name', 'Phone Number', 'Chat ID', 'Type', 'Account Label', 'Account Phone', 'Last Message', 'Last Active']);

while ($row = $result->fetch_assoc()) {
    $lastActive = convertToUserTz($row['last_message_time'], $userTz);
    $parts = explode('@', $row['chat_id']);
    $phone = $row['phone_number'] ? '+' . $row['phone_number']
           : ((str_ends_with($row['chat_id'], '@s.whatsapp.net') && isset($parts[0])) ? '+' . $parts[0] : '');
    fputcsv($out, [
        $row['contact_name'] ?: $parts[0],
        $phone,
        $row['chat_id'],
        $row['is_group'] ? 'Group' : 'Individual',
        $row['account_label'] ?: '',
        $row['account_phone'] ?: '',
        $row['last_message'] ?: '',
        $lastActive ?: '',
    ]);
}

fclose($out);
$stmt->close();
