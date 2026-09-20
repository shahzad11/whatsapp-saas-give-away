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
    'category'   => trim($_GET['category'] ?? ''),
    'area'       => trim($_GET['area'] ?? ''),
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
    // Category and Area sit next to the name rather than at the end: they are
    // what tells the reader which search a row came out of, and a caller working
    // down the file needs that before they need the review count.
    fputcsv($out, ['Name', 'Category searched', 'Area searched', 'Phone', 'WhatsApp',
                   'Website', 'Address', 'Rating', 'Reviews', 'Type',
                   'First seen', 'Last seen', 'Google Maps']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['title'],
            $r['source_query'] ?? '',
            $r['source_location'] ?? '',
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
$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalFiltered = leadsCount($conn, $filters);
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) $page = $totalPages;
$saved = leadsList($conn, $filters, $perPage, ($page - 1) * $perPage);
$sources = leadsSourceValues($conn);

// The area the search box starts on, from Settings → Lead search. Never blank:
// an empty Area box is what spent 138 leads' worth of credits on Google's guess
// of where SerpApi's datacentre is.
$defaultArea = $settings['location'];

// Where the map opens (#52): the last search that resolved coordinates, which
// is almost always the part of the world the admin is prospecting in. Without
// one the world view is the honest answer — anything else would be a guess.
$lastLl = (string)($conn->query(
    "SELECT resolved_ll FROM lead_searches
     WHERE resolved_ll IS NOT NULL AND resolved_ll != ''
     ORDER BY id DESC LIMIT 1"
)->fetch_row()[0] ?? '');
$mapStart = null;   // [lat, lng] when the last search left coordinates to open on
if (preg_match('/@(-?\d+\.?\d*),(-?\d+\.?\d*)/', $lastLl, $m)) {
    $mapStart = [(float)$m[1], (float)$m[2]];
}

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
<?php // Leaflet, only on this page (#52): it is the one screen with a map, and
      // admin-header.php has no per-page asset hook, so the tags live here. The
      // integrity hashes are Leaflet's own, from leafletjs.com/download.html.
      // z-index:0 keeps Leaflet's panes (which run to z-index 1000) under
      // Bootstrap's dropdowns and modals without touching the shared sheet. ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<style>#leadMap { z-index: 0; }</style>

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
                <?php // Pre-filled, not placeheld. A placeholder reads as a value:
                      // the grey "Lahore, Pakistan" that used to sit here looked
                      // filled in, the field went to SerpApi empty, and Google
                      // answered from its own datacentre in Virginia. ?>
                <input type="text" id="leadLocation" class="form-control form-control-sm"
                       list="leadAreaList" required
                       value="<?= sanitize($defaultArea) ?>">
                <datalist id="leadAreaList">
                    <?php foreach ($areas as $a): ?>
                        <option value="<?= sanitize($a['location']) ?>"><?= sanitize($a['label']) ?></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-md-3">
                <label class="form-label x-small text-muted mb-1" for="leadRadius">Radius (km)</label>
                <input type="number" id="leadRadius" class="form-control form-control-sm"
                       min="<?= LEADS_MIN_RADIUS_KM ?>" max="<?= LEADS_MAX_RADIUS_KM ?>" step="1"
                       value="<?= leadsRadiusMToKm($settings['radius_m']) ?>">
                <div class="form-text x-small">Around the area or pin — also the circle shown on the map.</div>
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

        <?php // The map pin (#52): the alternative origin to the Area box. A
              // click drops a draggable marker, the hidden inputs carry its
              // coordinates to ajax/leads-search.php, and the circle previews
              // the same radius the search will run with — so the map never
              // promises a wider net than the credit buys. ?>
        <div class="mt-3">
            <div id="leadMap" style="height:320px" class="rounded border w-100"></div>
            <input type="hidden" id="leadLat" value="">
            <input type="hidden" id="leadLng" value="">
            <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                <span id="leadPinStatus" class="small text-muted">
                    Click the map to drop a pin instead of typing an area.
                </span>
                <button type="button" id="leadPinLocate" class="btn btn-outline-secondary btn-sm ms-auto">
                    <i class="bi bi-geo-alt me-1"></i>Use my location
                </button>
                <button type="button" id="leadPinClear" class="btn btn-outline-secondary btn-sm d-none">
                    Clear pin
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
        <span>Saved leads · <?= number_format($totalFiltered) ?> total</span>
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
                       placeholder="Name, address, category or area" value="<?= sanitize($filters['q']) ?>">
            </div>
            <?php // Built from the values actually present in `leads`, so the two
                  // dropdowns can only ever select something that returns rows. ?>
            <div class="col-md-3">
                <label class="form-label x-small text-muted mb-1">Category</label>
                <select name="category" class="form-select form-select-sm" onchange="this.form.requestSubmit()">
                    <option value="">All categories</option>
                    <?php foreach ($sources['categories'] as $c): ?>
                        <option value="<?= sanitize($c['value']) ?>"
                            <?= $filters['category'] === $c['value'] ? 'selected' : '' ?>>
                            <?= sanitize($c['value']) ?> (<?= $c['n'] ?>)
                        </option>
                    <?php endforeach; ?>
                    <?php // Shown only while such rows exist — or while the URL asks
                          // for them, so a bookmarked filter still renders selected. ?>
                    <?php if ($sources['unrecorded']['categories'] > 0 || $filters['category'] === '-'): ?>
                        <option value="-" <?= $filters['category'] === '-' ? 'selected' : '' ?>>
                            Not recorded (<?= number_format($sources['unrecorded']['categories']) ?>)
                        </option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label x-small text-muted mb-1">Area</label>
                <select name="area" class="form-select form-select-sm" onchange="this.form.requestSubmit()">
                    <option value="">All areas</option>
                    <?php foreach ($sources['areas'] as $a): ?>
                        <option value="<?= sanitize($a['value']) ?>"
                            <?= $filters['area'] === $a['value'] ? 'selected' : '' ?>>
                            <?= sanitize($a['value']) ?> (<?= $a['n'] ?>)
                        </option>
                    <?php endforeach; ?>
                    <?php // The 138 rows from before the area was recorded, and the
                          // reason this option exists at all: they are the ones an
                          // admin most needs to be able to single out. It renders
                          // only while such rows exist — or while the URL asks for
                          // them, so a bookmarked filter still renders selected. ?>
                    <?php if ($sources['unrecorded']['areas'] > 0 || $filters['area'] === '-'): ?>
                        <option value="-" <?= $filters['area'] === '-' ? 'selected' : '' ?>>
                            Not recorded (<?= number_format($sources['unrecorded']['areas']) ?>)
                        </option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label x-small text-muted mb-1">Website</label>
                <select name="website" class="form-select form-select-sm" onchange="this.form.requestSubmit()">
                    <option value="">Any</option>
                    <option value="no" <?= $filters['website'] === 'no' ? 'selected' : '' ?>>No website</option>
                    <option value="yes" <?= $filters['website'] === 'yes' ? 'selected' : '' ?>>Has a website</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label x-small text-muted mb-1">Phone</label>
                <select name="phone" class="form-select form-select-sm" onchange="this.form.requestSubmit()">
                    <option value="">Any</option>
                    <option value="yes" <?= $filters['phone'] === 'yes' ? 'selected' : '' ?>>Has a number</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label x-small text-muted mb-1">Rating</label>
                <select name="min_rating" class="form-select form-select-sm" onchange="this.form.requestSubmit()">
                    <option value="">Any</option>
                    <?php foreach (['3.0', '3.5', '4.0', '4.5'] as $r): ?>
                        <option value="<?= $r ?>" <?= $filters['min_rating'] === $r ? 'selected' : '' ?>><?= $r ?>+</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label x-small text-muted mb-1">Sort</label>
                <select name="sort" class="form-select form-select-sm" onchange="this.form.requestSubmit()">
                    <?php foreach (['recent' => 'Recently seen', 'new' => 'Newest', 'rating' => 'Rating',
                                    'reviews' => 'Most reviews', 'name' => 'Name'] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= $filters['sort'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex">
                <button class="btn btn-sm btn-primary w-100">Apply</button>
            </div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead>
                <tr>
                    <th>Business</th><th>Category</th><th>Area searched</th>
                    <th>Phone</th><th>Website</th>
                    <th>Rating</th><th>Seen</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$saved): ?>
                <tr><td colspan="8" class="text-muted small">
                    Nothing saved yet. Run a search above — results are kept automatically.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($saved as $l): ?>
                <tr>
                    <td data-label="Business">
                        <div class="fw-500 text-truncate" style="max-width: 260px"
                             title="<?= sanitize($l['title']) ?>"><?= sanitize($l['title']) ?></div>
                        <?php if ($l['address']): ?>
                            <div class="text-muted x-small text-truncate" style="max-width: 260px"
                                 title="<?= sanitize($l['address']) ?>"><?= sanitize($l['address']) ?></div>
                        <?php endif; ?>
                    </td>
                    <?php // Which search produced this row. Without these two columns a
                          // saved list of 200 businesses is unreadable — and a lead from
                          // the wrong country is indistinguishable from a good one. ?>
                    <td class="small" data-label="Category">
                        <?php if ($l['source_query']): ?>
                            <a class="text-decoration-none" href="<?= $self ?>?<?= sanitize(http_build_query(
                                ['category' => $l['source_query']] + $filters)) ?>"><?= sanitize($l['source_query']) ?></a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="small" data-label="Area searched">
                        <?php if ($l['source_location']): ?>
                            <a class="text-decoration-none" href="<?= $self ?>?<?= sanitize(http_build_query(
                                ['area' => $l['source_location']] + $filters)) ?>"><?= sanitize($l['source_location']) ?></a>
                        <?php else: ?>
                            <span class="text-muted" title="Found before the area was recorded">not recorded</span>
                        <?php endif; ?>
                    </td>
                    <td class="small" data-label="Phone">
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
                    <td class="small" data-label="Website">
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
                    <td class="small" data-label="Rating">
                        <?php if ($l['rating']): ?>
                            <?= sanitize(number_format((float)$l['rating'], 1)) ?>
                            <span class="text-muted">(<?= number_format((int)$l['reviews']) ?>)</span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted" data-label="Seen"><?= sanitize(timeAgo($l['last_seen_at'])) ?></td>
                    <td>
                        <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
                           href="https://www.google.com/maps/place/?q=place_id:<?= rawurlencode($l['place_id']) ?>">Map</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <nav class="card-body border-top d-flex justify-content-between align-items-center" aria-label="Saved leads pages">
        <?php if ($page > 1): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= $self ?>?<?= sanitize(http_build_query($filters + ['page' => $page - 1])) ?>">&laquo; Previous</a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
        <span class="text-muted small">Page <?= (int)$page ?> of <?= number_format($totalPages) ?></span>
        <?php if ($page < $totalPages): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= $self ?>?<?= sanitize(http_build_query($filters + ['page' => $page + 1])) ?>">Next &raquo;</a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
    <div class="card-body border-top">
        <p class="text-muted x-small mb-0">
            Leads are never deleted and never shown to tenants — this list is
            the platform owner's, and <code>leads</code> has no tenant column at all.
        </p>
    </div>
</div>

<?php // Loaded here rather than in admin-footer.php so the rest of the console
      // never downloads it. Before the inline script, which uses `L` at init. ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
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
    var locationInput = document.getElementById('leadLocation');
    var radiusInput = document.getElementById('leadRadius');
    var latInput = document.getElementById('leadLat');
    var lngInput = document.getElementById('leadLng');
    var pinStatus = document.getElementById('leadPinStatus');
    var pinClear = document.getElementById('leadPinClear');
    var pinLocate = document.getElementById('leadPinLocate');

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
            + '<thead><tr><th></th><th>Business</th><th>Category</th><th>Area searched</th>'
            + '<th>Phone</th><th>Website</th>'
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
            + '<td class="small">' + esc(r.source_query || '—') + '</td>'
            + '<td class="small">' + esc(r.source_location || '—') + '</td>'
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

        // Refused in the browser as well as on the server, because this is the
        // only place it can be refused before a credit is at risk at all. An
        // empty area does not fail — it succeeds against Google's guess of where
        // the request came from, which is a datacentre on another continent.
        if (!useNext && !pinSet() && locationInput.value.trim() === '') {
            status.innerHTML = '<span class="text-danger">Type an area to search in, '
                + 'e.g. "Lahore, Pakistan" — without one the results come back from '
                + 'wherever Google thinks the request came from. No credit was used.</span>';
            locationInput.focus();
            return;
        }

        busy = true;

        var target = useNext ? moreBtn : btn;
        var label = target.innerHTML;
        target.disabled = true;
        target.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Searching…';
        status.innerHTML = '';

        var body = {
            csrf_token: '<?= sanitize(csrfToken()) ?>',
            query: queryInput.value.trim(),
            // A pin is the whole origin — SerpApi rejects lat/lon combined with
            // a location text, so the typed area is kept in the box but not sent.
            location: pinSet() ? '' : locationInput.value.trim(),
            // Kilometres. The server converts — see leadsRadiusKmToM().
            radius_km: parseInt(radiusInput.value, 10) || 0,
            min_rating: document.getElementById('leadMinRating').value,
            open_now: document.getElementById('leadOpenNow').checked,
            next_url: useNext ? nextUrl : ''
        };
        if (pinSet()) { body.lat = latInput.value; body.lng = lngInput.value; }

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

                // The search that actually ran, spelled out. The area is echoed by
                // the server rather than read back off the form, so the line says
                // what SerpApi was asked and not what the box happens to hold now.
                // A pin search records its origin as '@lat,lng' — right for the
                // ledger, meaningless as a sentence, so it reads as a pin here.
                var ranLocation = (res.location || '');
                ranLocation = ranLocation.charAt(0) === '@' ? 'at the map pin' : 'in ' + ranLocation;
                var ran = '<span class="text-muted">' + esc(res.query || '')
                    + ' ' + esc(ranLocation) + ', ' + esc(res.radius_km || '') + ' km — </span>';

                // A typed-area search pans the map to where SerpApi resolved it,
                // so the admin can see which "Lahore" the credit actually went
                // to. A set pin is the origin itself — panning would move the
                // marker's context under it, so it stays put.
                if (res.resolved_ll && !pinSet() && map) {
                    var ll = /^@(-?\d+\.?\d*),(-?\d+\.?\d*)/.exec(res.resolved_ll);
                    if (ll) map.setView([parseFloat(ll[1]), parseFloat(ll[2])], 11);
                }

                status.innerHTML = ran + '<span class="text-muted">' + res.results.length + ' result(s), '
                    + res.new_count + ' new, ' + res.seen_count + ' already known.</span>';

                nextUrl = res.next_url || null;
                moreBtn.classList.toggle('d-none', !nextUrl);

                if (!res.results.length) {
                    status.innerHTML = ran + '<span class="text-muted">nothing found. '
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

    // Wired up before the map is: a Leaflet that failed to load (a bad SRI
    // hash, a blocked CDN) must not take the Search button down with it.
    btn.addEventListener('click', function () { nextUrl = null; run(false); });
    moreBtn.addEventListener('click', function () { run(true); });

    // --- The map pin (#52) -------------------------------------------------
    //
    // The pin and the Area box are two spellings of the same thing — where the
    // search happens — so they are never sent together: while a pin is set the
    // Area input is disabled and its value kept but unsubmitted. The circle is
    // the Radius field in metres, so the preview is the exact `m` SerpApi gets.

    // The last search's resolved coordinates, else a world view.
    var mapStart = <?= $mapStart !== null ? json_encode($mapStart) : 'null' ?>;
    var map = null;
    if (typeof L !== 'undefined') {
        try {
            map = L.map('leadMap').setView(mapStart || [20, 0], mapStart ? 11 : 2);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
        } catch (e) {
            map = null;
        }
    }
    if (!map) {
        // No Leaflet, no map — the search still works off the typed area.
        document.getElementById('leadMap').classList.add('d-none');
        pinStatus.textContent = 'Map unavailable — type an area to search in.';
        pinLocate.classList.add('d-none');
    }

    var marker = null;
    var circle = null;
    var areaPlaceholder = locationInput.placeholder;

    function pinSet() {
        return latInput.value !== '' && lngInput.value !== '';
    }

    function radiusKm() {
        return parseInt(radiusInput.value, 10) || 0;
    }

    function pinStatusText(lat, lng) {
        pinStatus.textContent = 'Pin: ' + lat.toFixed(4) + ', ' + lng.toFixed(4)
            + ' · searching within ' + radiusKm() + ' km';
    }

    function setPin(lat, lng) {
        if (!map) return;
        if (!marker) {
            marker = L.marker([lat, lng], { draggable: true }).addTo(map);
            marker.on('dragend', function () {
                var p = marker.getLatLng();
                setPin(p.lat, p.lng);
            });
        } else {
            marker.setLatLng([lat, lng]);
        }
        latInput.value = lat.toFixed(6);
        lngInput.value = lng.toFixed(6);
        updateCircle();
        pinStatusText(lat, lng);
        pinClear.classList.remove('d-none');
        // Disabled, not cleared: the typed value is kept so clearing the pin
        // restores the search exactly as it was.
        locationInput.disabled = true;
        locationInput.placeholder = 'Using the map pin';
    }

    function updateCircle() {
        if (!map || !marker) return;
        var metres = radiusKm() * 1000;
        if (!circle) {
            circle = L.circle(marker.getLatLng(), { radius: metres }).addTo(map);
        } else {
            circle.setLatLng(marker.getLatLng());
            circle.setRadius(metres);
        }
    }

    function clearPin() {
        if (map && marker) { map.removeLayer(marker); marker = null; }
        if (map && circle) { map.removeLayer(circle); circle = null; }
        latInput.value = '';
        lngInput.value = '';
        locationInput.disabled = false;
        locationInput.placeholder = areaPlaceholder;
        pinClear.classList.add('d-none');
        pinStatus.textContent = 'Click the map to drop a pin instead of typing an area.';
    }

    if (map) map.on('click', function (e) {
        setPin(e.latlng.lat, e.latlng.lng);
    });
    radiusInput.addEventListener('input', function () {
        updateCircle();
        if (marker) {
            var p = marker.getLatLng();
            pinStatusText(p.lat, p.lng);
        }
    });
    pinClear.addEventListener('click', clearPin);
    pinLocate.addEventListener('click', function () {
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(function (pos) {
            setPin(pos.coords.latitude, pos.coords.longitude);
            if (map) map.setView([pos.coords.latitude, pos.coords.longitude], 11);
        }, function () {
            // Denial is a shrug, not an error: the pin can still be dropped
            // by hand, so the note stays muted.
            pinStatus.textContent = 'Location unavailable — drop the pin by hand instead.';
        });
    });
})();
</script>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
