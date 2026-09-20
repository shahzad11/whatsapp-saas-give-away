<?php
require_once dirname(__DIR__) . '/config/init.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$userTz = getUserTimezone($conn, $userId);
$canExport = planHasFeature(getUserPlan($conn, $userId), 'csv_export');

// --- Fetch user's accounts for filter dropdown ---
$stmt = $conn->prepare("SELECT id, label, phone_number, push_name FROM wa_accounts WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- Query params ---
$search    = trim($_GET['search'] ?? '');
$accountFilter = (int)($_GET['account'] ?? 0);
$typeFilter = $_GET['type'] ?? '';
$sort      = $_GET['sort'] ?? 'last_active';
$dir       = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = max(10, min(100, (int)($_GET['per_page'] ?? 25)));

// --- Build WHERE clause ---
$where = "WHERE wa.user_id = ?
    AND c.chat_id NOT IN ('status@broadcast', '0@s.whatsapp.net')
    AND c.chat_id NOT LIKE '%@newsletter'
    AND c.chat_id NOT LIKE '%@broadcast'";
$params = [$userId];
$types  = "i";

if ($search !== '') {
    // phone_number is searched as well as chat_id, and the needle is stripped of
    // everything that is not a digit for that column. The table renders the
    // phone as "+923001234567", so searching for what is on screen — with the +,
    // or with spaces pasted from a contact card — matched nothing before: the
    // stored value has no punctuation, and a group's chat_id has no phone in it
    // at all.
    $digits = preg_replace('/\D+/', '', $search);
    $like = '%' . $search . '%';

    if ($digits !== '') {
        $where .= " AND (c.contact_name LIKE ? OR c.chat_id LIKE ? OR c.phone_number LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $params[] = '%' . $digits . '%';
        $types .= "sss";
    } else {
        $where .= " AND (c.contact_name LIKE ? OR c.chat_id LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $types .= "ss";
    }
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

// --- Count total ---
$countSql = "SELECT COUNT(*) as total FROM wa_contacts c JOIN wa_accounts wa ON c.account_id = wa.id $where";
$stmt = $conn->prepare($countSql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$totalRows = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// --- Sort ---
// The "Phone Number" column renders phone_number, falling back to the digits in
// chat_id, so that is what it must sort on. Sorting the raw chat_id put every
// group JID in among the numbers and ordered lexicographically, so "12…" sorted
// before "2…" — visibly not the column the user clicked.
$phoneExpr = "COALESCE(NULLIF(c.phone_number, ''), SUBSTRING_INDEX(c.chat_id, '@', 1))";
$sortMap = [
    'name'        => 'c.contact_name',
    'chat_id'     => $phoneExpr . ' + 0',   // numeric: '9' must not sort before '12'
    'last_active' => 'c.last_message_time',
    'account'     => 'wa.label',
    'type'        => 'c.is_group',
];
$orderCol = $sortMap[$sort] ?? 'c.last_message_time';
$orderDir = $dir === 'asc' ? 'ASC' : 'DESC';

// --- Fetch contacts ---
$sql = "SELECT c.id, c.chat_id, c.contact_name, c.phone_number, c.last_message, c.last_message_time, c.is_group,
               wa.id as account_id, wa.label as account_label, wa.phone_number as account_phone
        FROM wa_contacts c
        JOIN wa_accounts wa ON c.account_id = wa.id
        $where
        ORDER BY $orderCol $orderDir
        LIMIT ? OFFSET ?";

$fetchTypes = $types . "ii";
$fetchParams = array_merge($params, [$perPage, $offset]);
$stmt = $conn->prepare($sql);
$stmt->bind_param($fetchTypes, ...$fetchParams);
$stmt->execute();
$contacts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- Helper: build query string preserving params ---
function contactsQs($overrides = []) {
    $base = [
        'search'   => $_GET['search'] ?? '',
        'account'  => $_GET['account'] ?? '',
        'type'     => $_GET['type'] ?? '',
        'sort'     => $_GET['sort'] ?? 'last_active',
        'dir'      => $_GET['dir'] ?? 'desc',
        'page'     => $_GET['page'] ?? 1,
        'per_page' => $_GET['per_page'] ?? 25,
    ];
    $merged = array_merge($base, $overrides);
    // Remove empty values
    $merged = array_filter($merged, fn($v) => $v !== '' && $v !== null);
    // Remove defaults
    if (($merged['sort'] ?? '') === 'last_active') unset($merged['sort']);
    if (($merged['dir'] ?? '') === 'desc') unset($merged['dir']);
    if (($merged['page'] ?? 1) == 1) unset($merged['page']);
    if (($merged['per_page'] ?? 25) == 25) unset($merged['per_page']);
    return $merged ? '?' . http_build_query($merged) : '';
}

function sortUrl($col) {
    $currentSort = $_GET['sort'] ?? 'last_active';
    $currentDir  = strtolower($_GET['dir'] ?? 'desc');
    $newDir = ($currentSort === $col && $currentDir === 'asc') ? 'desc' : 'asc';
    return contactsQs(['sort' => $col, 'dir' => $newDir, 'page' => 1]);
}

function sortIcon($col) {
    $currentSort = $_GET['sort'] ?? 'last_active';
    $currentDir  = strtolower($_GET['dir'] ?? 'desc');
    if ($currentSort !== $col) return '<i class="bi bi-chevron-expand text-muted ms-1"></i>';
    return $currentDir === 'asc'
        ? '<i class="bi bi-chevron-up ms-1"></i>'
        : '<i class="bi bi-chevron-down ms-1"></i>';
}

function extractPhone($chatId, $storedPhone = null) {
    if ($storedPhone) return $storedPhone;
    if (!str_ends_with($chatId, '@s.whatsapp.net')) return '';
    $parts = explode('@', $chatId);
    return $parts[0] ?? '';
}

$pageTitle = 'Contacts';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<?php if (empty($accounts)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="bi bi-people d-block"></i>
                <h5>No contacts yet</h5>
                <p>Link a WhatsApp account and open Chats to sync your contacts.</p>
                <a href="<?= APP_URL ?>/whatsapp/link.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Link Account</a>
            </div>
        </div>
    </div>
<?php else: ?>

<!-- Toolbar -->
<div class="contacts-toolbar mb-3">
    <form method="GET" class="contacts-toolbar-inner">
        <div class="contacts-toolbar-left">
            <div class="contacts-search-wrap">
                <i class="bi bi-search"></i>
                <input type="text" name="search" value="<?= sanitize($search) ?>" placeholder="Search contacts..." class="form-control form-control-sm">
            </div>
            <select name="account" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Accounts</option>
                <?php foreach ($accounts as $acc): ?>
                    <option value="<?= $acc['id'] ?>" <?= $accountFilter === (int)$acc['id'] ? 'selected' : '' ?>>
                        <?= sanitize($acc['label'] ?: ($acc['phone_number'] ?: 'Account #' . $acc['id'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Types</option>
                <option value="individual" <?= $typeFilter === 'individual' ? 'selected' : '' ?>>Individuals</option>
                <option value="group" <?= $typeFilter === 'group' ? 'selected' : '' ?>>Groups</option>
            </select>
            <!-- preserve sort params -->
            <?php if (($sort ?? 'last_active') !== 'last_active'): ?>
                <input type="hidden" name="sort" value="<?= sanitize($sort) ?>">
            <?php endif; ?>
            <?php if (($dir ?? 'desc') !== 'desc'): ?>
                <input type="hidden" name="dir" value="<?= sanitize($dir) ?>">
            <?php endif; ?>
        </div>
        <div class="contacts-toolbar-right">
            <span class="text-muted small me-2"><?= number_format($totalRows) ?> contact(s)</span>
            <?php // Gated on the plan's csv_export lever. export-contacts.php
                  // enforces it too — this only avoids offering a dead button. ?>
            <?php if ($totalRows > 0 && $canExport): ?>
                <a href="ajax/export-contacts.php<?= contactsQs() ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-download me-1"></i>Export CSV
                </a>
            <?php elseif ($totalRows > 0): ?>
                <span class="text-muted small" title="CSV export is not part of your plan">
                    <i class="bi bi-lock me-1"></i>Export CSV
                </span>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($totalRows === 0): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="bi bi-search d-block"></i>
                <h5>No contacts found</h5>
                <p>Try adjusting your search or filters.</p>
                <a href="contacts.php" class="btn btn-outline-primary btn-sm">Clear Filters</a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card table-card">
        <div class="table-responsive">
            <table class="table table-stack">
                <thead>
                    <tr>
                        <th><a href="contacts.php<?= sortUrl('name') ?>" class="sort-header">Name <?= sortIcon('name') ?></a></th>
                        <th><a href="contacts.php<?= sortUrl('chat_id') ?>" class="sort-header">Phone Number <?= sortIcon('chat_id') ?></a></th>
                        <?php if (count($accounts) > 1): ?>
                        <th><a href="contacts.php<?= sortUrl('account') ?>" class="sort-header">Account <?= sortIcon('account') ?></a></th>
                        <?php endif; ?>
                        <th>Last Message</th>
                        <th><a href="contacts.php<?= sortUrl('last_active') ?>" class="sort-header">Last Active <?= sortIcon('last_active') ?></a></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contacts as $c): ?>
                    <tr>
                        <td class="fw-500" data-label="Name">
                            <?php if ($c['is_group']): ?>
                                <i class="bi bi-people-fill text-success me-1 small"></i>
                            <?php else: ?>
                                <i class="bi bi-person-fill text-primary me-1 small"></i>
                            <?php endif; ?>
                            <?php // chatDisplayName() never returns a JID: an unnamed
                                  // group reads as "Group chat" rather than the
                                  // numeric JID prefix the old fallback printed,
                                  // and an unmappable @lid as "Unknown contact". ?>
                            <?= sanitize(chatDisplayName(
                                    $c['contact_name'],
                                    $c['phone_number'] ?? null,
                                    $c['chat_id'],
                                    (bool)$c['is_group']
                                )) ?>
                        </td>
                        <td class="small" data-label="Phone Number">
                            <?php $phone = extractPhone($c['chat_id'], $c['phone_number'] ?? null); ?>
                            <?= $phone ? '+' . sanitize($phone) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <?php if (count($accounts) > 1): ?>
                        <td class="small" data-label="Account"><?= sanitize($c['account_label'] ?: ('Account #' . $c['account_id'])) ?></td>
                        <?php endif; ?>
                        <td class="text-muted small" data-label="Last Message" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= sanitize(mb_strimwidth($c['last_message'] ?? '', 0, 60, '...')) ?>
                        </td>
                        <td class="text-muted small" data-label="Last Active">
                            <?php
                            $converted = convertToUserTz($c['last_message_time'], $userTz);
                            echo $converted ? sanitize($converted) : '-';
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <nav class="mt-3 d-flex justify-content-between align-items-center">
        <div class="text-muted small">
            Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalRows)) ?> of <?= number_format($totalRows) ?>
        </div>
        <ul class="pagination pagination-sm mb-0">
            <?php if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="contacts.php<?= contactsQs(['page' => $page - 1]) ?>">&laquo;</a>
                </li>
            <?php endif; ?>

            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            if ($startPage > 1): ?>
                <li class="page-item"><a class="page-link" href="contacts.php<?= contactsQs(['page' => 1]) ?>">1</a></li>
                <?php if ($startPage > 2): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="contacts.php<?= contactsQs(['page' => $i]) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>

            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; ?>
                <li class="page-item"><a class="page-link" href="contacts.php<?= contactsQs(['page' => $totalPages]) ?>"><?= $totalPages ?></a></li>
            <?php endif; ?>

            <?php if ($page < $totalPages): ?>
                <li class="page-item">
                    <a class="page-link" href="contacts.php<?= contactsQs(['page' => $page + 1]) ?>">&raquo;</a>
                </li>
            <?php endif; ?>
        </ul>
    </nav>
    <?php endif; ?>

<?php endif; ?>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
