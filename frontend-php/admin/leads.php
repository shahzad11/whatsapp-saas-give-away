<?php
// Lead generation (#50).
//
// The platform owner's prospecting tool: search a category in an area through
// SerpApi's Google Maps engine, get businesses with a phone number, and call
// them. Guarded by includes/admin-init.php like every other admin route.
//
// Two halves that do not talk to each other, on purpose:
//
//   **Search** spends credits. It runs through ajax/leads-search.php, one page
//   per press, and paints results into the page without reloading it.
//
//   **Saved leads** spends nothing. It is the `leads` table, filtered and
//   sorted server-side, and it is what the CSV export exports — the same
//   leadsList() call, so the file can never disagree with the table above it.
require_once dirname(__DIR__) . '/includes/admin-init.php';

$self = APP_URL . '/admin/leads.php';
$settings = leadsSettings($conn);
$configured = serpApiConfigured($conn);

// --- Filters for the saved-leads table (and therefore for the export) -------
$filters = [
    'q'          => trim($_GET['q'] ?? ''),
    'website'    => $_GET['website'] ?? '',
    'phone'      => $_GET['phone'] ?? '',
    'new'        => $_GET['new'] ?? '',
    'min_rating' => $_GET['min_rating'] ?? '',
    'sort'       => $_GET['sort'] ?? 'recent',
];

// --- CSV export -------------------------------------------------------------
//
// Handled before any output, because it replaces the page rather than adding to
// it. It exports exactly what the filters select, not everything: an export
// that silently ignored the filters would be a different answer to the question
// the admin just asked on screen.
if (($_GET['export'] ?? '') === 'csv') {
    $rows = leadsList($conn, $filters, 5000);
    logAudit($conn, 'admin.leads.export', 'leads', null, ['rows' => count($rows)] + $filters);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads-' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    // A BOM, so Excel opens a UTF-8 CSV as UTF-8. Without it a business name
    // with an accent or an Urdu character arrives mangled, which is the whole
    // file for some of these searches.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Name', 'Phone', 'WhatsApp', 'Website', 'Address', 'Rating',
                   'Reviews', 'Type', 'First seen', 'Last seen', 'Google Maps']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['title'],
            // Leading apostrophe: a spreadsheet reads "923001234567" as a number
            // and renders it as 9.23E+11, which destroys every phone number in
            // the file. This is the standard way to force a text cell.
            $r['phone_digits'] ? "'" . $r['phone_digits'] : ($r['phone'] ?? ''),
            leadWhatsappLink($r),
            $r['website'] ?? '',
            $r['address'] ?? '',
            $r['rating'] ?? '',
            $r['reviews'] ?? '',
            $r['types'] ?? '',
            $r['first_seen_at'],
            $r['last_seen_at'],
            'https://www.google.com/maps/place/?q=place_id:' . $r['place_id'],
        ]);
    }
    fclose($out);
    exit;
}

$categories = leadCategories($conn);
$areas = leadAreas($conn);
$saved = leadsList($conn, $filters, 500);

// What the tool has cost so far. Shown because credits are money and the only
// other place this is visible is SerpApi's own dashboard.
$spend = $conn->query(
    "SELECT COUNT(*) AS searches, COALESCE(SUM(credits_used), 0) AS credits
     FROM lead_searches WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)"
)->fetch_assoc();
$totalLeads = (int)($conn->query("SELECT COUNT(*) FROM leads")->fetch_row()[0] ?? 0);

$pageTitle = 'Leads';
require_once dirname(__DIR__) . '/includes/admin-header.php';
?>

<?php if (!$configured): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <strong>No SerpApi key is configured</strong>, so searching is switched off.
        Add one under <a href="<?= APP_URL ?>/admin/settings.php">Settings → Lead search</a>.
        Anything already saved is still listed below.
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card"><div class="card-body py-3">
            <div class="text-muted x-small text-uppercase">Saved leads</div>
            <div class="h4 mb-0"><?= number_format($totalLeads) ?></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card"><div class="card-body py-3">
            <div class="text-muted x-small text-uppercase">Searches (30 days)</div>
            <div class="h4 mb-0"><?= number_format((int)$spend['searches']) ?></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card"><div class="card-body py-3">
            <div class="text-muted x-small text-uppercase">Credits used (30 days)</div>
            <div class="h4 mb-0"><?= number_format((int)$spend['credits']) ?></div>
        </div></div>
    </div>
</div>

<?php // --- Search -------------------------------------------------------- ?>
<div class="card mb-4">
    <div class="card-header">Find businesses</div>
    <div class="card-body">
        <?php // Not a <form>. Submitting this would be a page load, and a page
              // load that spent a credit could be repeated by a refresh or a
              // back button — which is exactly how an admin ends up paying for
              // the same search three times. Every request here is an explicit
              // button press handled by JavaScript. ?>
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label x-small text-muted mb-1" for="leadCategory">Category</label>
                <select id="leadCategory" class="form-select form-select-sm">
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= sanitize($c['query']) ?>"><?= sanitize($c['label']) ?></option>
                    <?php endforeach; ?>
                    <option value="">— type my own —</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label x-small text-muted mb-1" for="leadQuery">Search for</label>
                <input type="text" id="leadQuery" class="form-control form-control-sm"
                       placeholder="dentist" value="<?= sanitize($categories[0]['query'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label x-small text-muted mb-1" for="leadLocation">Area</label>
                <input type="text" id="leadLocation" class="form-control form-control-sm"
                       list="leadAreaList" placeholder="Lahore, Pakistan">
                <datalist id="leadAreaList">
                    <?php foreach ($areas as $a): ?>
                        <option value="<?= sanitize($a['location']) ?>"><?= sanitize($a['label']) ?></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-md-3">
                <label class="form-label x-small text-muted mb-1" for="leadRadius">Radius (m)</label>
                <input type="number" id="leadRadius" class="form-control form-control-sm"
                       min="1000" max="200000" step="1000" value="<?= (int)$settings['radius_m'] ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label x-small text-muted mb-1" for="leadMinRating">Minimum rating</label>
                <select id="leadMinRating" class="form-select form-select-sm">
                    <option value="">Any</option>
                    <?php foreach (['2.0', '2.5', '3.0', '3.5', '4.0', '4.5'] as $r): ?>
                        <option value="<?= $r ?>"><?= $r ?>+</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" id="leadOpenNow">
                    <label class="form-check-label small" for="leadOpenNow">Open now</label>
                </div>
            </div>
            <div class="col-md-6 d-flex gap-2 justify-content-end">
                <button id="leadSearchBtn" class="btn btn-sm btn-primary" <?= $configured ? '' : 'disabled' ?>>
                    <i class="bi bi-search me-1"></i>Search — 1 credit
                </button>
            </div>
        </div>

        <div id="leadSearchStatus" class="small mt-3"></div>
        <div id="leadResults" class="table-responsive mt-2"></div>
        <div class="text-center mt-3">
            <button id="leadMoreBtn" class="btn btn-sm btn-outline-primary d-none">
                Load 20 more — 1 credit
            </button>
        </div>
    </div>
</div>

<?php // --- Saved leads ---------------------------------------------------- ?>
<div class="card table-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Saved leads</span>
        <?php // The export carries the current filters, so the file matches the
              // table. Built from the same $filters the query already used. ?>
        <a class="btn btn-sm btn-outline-secondary"
           href="<?= $self ?>?<?= sanitize(http_build_query($filters + ['export' => 'csv'])) ?>">
            <i class="bi bi-download me-1"></i>Export CSV
        </a>
    </div>
    <div class="card-body border-bottom">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label x-small text-muted mb-1">Search</label>
                <input type="search" name="q" class="form-control form-control-sm"
                       placeholder="Name, address or type" value="<?= sanitize($filters['q']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label x-small text-muted mb-1">Website</label>
                <select name="website" class="form-select form-select-sm">
                    <option value="">Any</option>
                    <option value="no" <?= $filters['website'] === 'no' ? 'selected' : '' ?>>No website</option>
                    <option value="yes" <?= $filters['website'] === 'yes' ? 'selected' : '' ?>>Has a website</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label x-small text-muted mb-1">Phone</label>
                <select name="phone" class="form-select form-select-sm">
                    <option value="">Any</option>
                    <option value="yes" <?= $filters['phone'] === 'yes' ? 'selected' : '' ?>>Has a number</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label x-small text-muted mb-1">Rating</label>
                <select name="min_rating" class="form-select form-select-sm">
                    <option value="">Any</option>
                    <?php foreach (['3.0', '3.5', '4.0', '4.5'] as $r): ?>
                        <option value="<?= $r ?>" <?= $filters['min_rating'] === $r ? 'selected' : '' ?>><?= $r ?>+</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label x-small text-muted mb-1">Sort</label>
                <select name="sort" class="form-select form-select-sm">
                    <?php foreach (['recent' => 'Recently seen', 'new' => 'Newest', 'rating' => 'Rating',
                                    'reviews' => 'Most reviews', 'name' => 'Name'] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= $filters['sort'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-flex">
                <button class="btn btn-sm btn-primary w-100">Apply</button>
            </div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Business</th><th>Phone</th><th>Website</th>
                    <th>Rating</th><th>Seen</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$saved): ?>
                <tr><td colspan="6" class="text-muted small">
                    Nothing saved yet. Run a search above — results are kept automatically.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($saved as $l): ?>
                <tr>
                    <td>
                        <div class="fw-500"><?= sanitize($l['title']) ?></div>
                        <?php if ($l['address']): ?>
                            <div class="text-muted x-small"><?= sanitize($l['address']) ?></div>
                        <?php endif; ?>
                        <?php if ($l['types']): ?>
                            <div class="text-muted x-small"><?= sanitize($l['types']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($l['phone']): ?>
                            <a href="tel:<?= sanitize($l['phone_digits'] ?: $l['phone']) ?>"
                               class="text-decoration-none"><?= sanitize($l['phone']) ?></a>
                            <?php if ($l['phone_digits']): ?>
                                <a href="<?= sanitize(leadWhatsappLink($l)) ?>" target="_blank" rel="noopener"
                                   class="ms-1 text-success" title="Open in WhatsApp">
                                    <i class="bi bi-whatsapp"></i>
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php // "No website" is a badge, not a blank cell: it is the
                              // strongest buying signal this tool produces, and an
                              // empty cell reads as missing data. ?>
                        <?php if ($l['website']): ?>
                            <a href="<?= sanitize($l['website']) ?>" target="_blank" rel="noopener noreferrer"
                               class="text-decoration-none"><?= sanitize(parse_url($l['website'], PHP_URL_HOST) ?: 'site') ?></a>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark">no website</span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($l['rating']): ?>
                            <?= sanitize(number_format((float)$l['rating'], 1)) ?>
                            <span class="text-muted">(<?= number_format((int)$l['reviews']) ?>)</span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= sanitize(timeAgo($l['last_seen_at'])) ?></td>
                    <td>
                        <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
                           href="https://www.google.com/maps/place/?q=place_id:<?= rawurlencode($l['place_id']) ?>">Map</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body border-top">
        <p class="text-muted x-small mb-0">
            Showing up to 500. Leads are never deleted and never shown to tenants — this list is
            the platform owner's, and <code>leads</code> has no tenant column at all.
        </p>
    </div>
</div>

<script>
// Search is deliberately hand-written rather than a data-ajax form: every
// request costs a credit, so it must come from a click and never from a form
// submit that a refresh or a back button could repeat.
(function () {
    'use strict';

    var btn = document.getElementById('leadSearchBtn');
    var moreBtn = document.getElementById('leadMoreBtn');
    var status = document.getElementById('leadSearchStatus');
    var out = document.getElementById('leadResults');
    var category = document.getElementById('leadCategory');
    var queryInput = document.getElementById('leadQuery');

    var nextUrl = null;
    var busy = false;
    var table = null;

    // Picking a category fills the free-text box rather than replacing it, so
    // "dentist" can be edited into "cosmetic dentist" without losing the list.
    category.addEventListener('change', function () {
        if (category.value) queryInput.value = category.value;
        queryInput.focus();
    });

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function ensureTable() {
        if (table) return table;
        out.innerHTML = '<table class="table align-middle mb-0">'
            + '<thead><tr><th></th><th>Business</th><th>Phone</th><th>Website</th>'
            + '<th>Rating</th><th>Open</th><th></th></tr></thead><tbody></tbody></table>';
        table = out.querySelector('tbody');
        return table;
    }

    function rowHtml(r) {
        var phone = r.phone
            ? '<a href="tel:' + esc(r.phone) + '" class="text-decoration-none">' + esc(r.phone) + '</a>'
              + (r.wa_link ? ' <a href="' + esc(r.wa_link) + '" target="_blank" rel="noopener" '
                 + 'class="ms-1 text-success" title="Open in WhatsApp"><i class="bi bi-whatsapp"></i></a>' : '')
            : '<span class="text-muted">—</span>';

        var site = r.website
            ? '<a href="' + esc(r.website) + '" target="_blank" rel="noopener noreferrer" '
              + 'class="text-decoration-none">site</a>'
            : '<span class="badge bg-warning text-dark">no website</span>';

        var rating = r.rating
            ? esc(r.rating) + ' <span class="text-muted">(' + esc(r.reviews || 0) + ')</span>'
            : '<span class="text-muted">—</span>';

        var thumb = r.thumbnail
            ? '<img src="' + esc(r.thumbnail) + '" alt="" width="40" height="40" '
              + 'style="object-fit:cover;border-radius:6px">'
            : '';

        return '<tr>'
            + '<td>' + thumb + '</td>'
            + '<td><div class="fw-500">' + esc(r.title)
            + (r.is_new ? ' <span class="badge bg-success">new</span>'
                        : ' <span class="badge bg-light text-muted">seen</span>') + '</div>'
            + '<div class="text-muted x-small">' + esc(r.address || '') + '</div>'
            + '<div class="text-muted x-small">' + esc(r.types || '') + '</div></td>'
            + '<td class="small">' + phone + '</td>'
            + '<td class="small">' + site + '</td>'
            + '<td class="small">' + rating + '</td>'
            + '<td class="small text-muted">' + esc(r.open_state || '') + '</td>'
            + '<td><a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="'
            + esc(r.maps_url) + '">Map</a></td>'
            + '</tr>';
    }

    function run(useNext) {
        if (busy) return;
        busy = true;

        var target = useNext ? moreBtn : btn;
        var label = target.innerHTML;
        target.disabled = true;
        target.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Searching…';
        status.innerHTML = '';

        var body = {
            csrf_token: '<?= sanitize(csrfToken()) ?>',
            query: queryInput.value.trim(),
            location: document.getElementById('leadLocation').value.trim(),
            radius_m: parseInt(document.getElementById('leadRadius').value, 10) || 0,
            min_rating: document.getElementById('leadMinRating').value,
            open_now: document.getElementById('leadOpenNow').checked,
            next_url: useNext ? nextUrl : ''
        };

        fetch('<?= APP_URL ?>/ajax/leads-search.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) {
                    status.innerHTML = '<span class="text-danger">' + esc(res.error || 'Search failed.') + '</span>';
                    // The credit is spent either way, so the button comes back
                    // enabled — but nothing is retried automatically.
                    return;
                }

                if (!useNext) { out.innerHTML = ''; table = null; }
                var body = ensureTable();
                res.results.forEach(function (r) { body.insertAdjacentHTML('beforeend', rowHtml(r)); });

                status.innerHTML = '<span class="text-muted">' + res.results.length + ' result(s) — '
                    + res.new_count + ' new, ' + res.seen_count + ' already known.</span>';

                nextUrl = res.next_url || null;
                moreBtn.classList.toggle('d-none', !nextUrl);

                if (!res.results.length) {
                    status.innerHTML = '<span class="text-muted">Nothing found. '
                        + 'The credit was still used — try a wider radius or a different area.</span>';
                }
            })
            .catch(function () {
                status.innerHTML = '<span class="text-danger">Could not reach the server. '
                    + 'The search may or may not have been charged — check Leads again before retrying.</span>';
            })
            .then(function () {
                busy = false;
                target.disabled = false;
                target.innerHTML = label;
            });
    }

    btn.addEventListener('click', function () { nextUrl = null; run(false); });
    moreBtn.addEventListener('click', function () { run(true); });
})();
</script>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
