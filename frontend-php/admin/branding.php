<?php
// App branding (#23) — the instance's own name, logo and favicon.
//
// Same semantics as every other admin setting: a saved value overrides the
// deployment's APP_NAME, and a blank one falls back to it. That is what makes the
// field safe to clear — the instance never ends up nameless, it goes back to the
// env value.
require_once dirname(__DIR__) . '/includes/admin-init.php';

$errors = [];
$self = APP_URL . '/admin/branding.php';

$stored = [
    'brand_name'         => (string)(overrideSetting($conn, 'brand_name') ?? ''),
    'brand_short_name'   => (string)(overrideSetting($conn, 'brand_short_name') ?? ''),
    'brand_logo_url'     => (string)(overrideSetting($conn, 'brand_logo_url') ?? ''),
    'brand_favicon_url'  => (string)(overrideSetting($conn, 'brand_favicon_url') ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    formRequireCsrf($self);

    $action = $_POST['action'] ?? 'save';

    if ($action === 'remove_asset') {
        $kind = (string)($_POST['kind'] ?? '');
        if (!in_array($kind, brandAssetKinds(), true)) {
            formRespond(false, 'Unknown image.', $self);
        }
        brandDeleteAsset($conn, $kind);
        logAudit($conn, 'admin.branding.asset_removed', 'brand_assets', $kind);
        formRespond(true, ucfirst($kind) . ' removed.', $self);
    }

    $name = trim((string)($_POST['brand_name'] ?? ''));
    $short = trim((string)($_POST['brand_short_name'] ?? ''));
    $logoUrl = trim((string)($_POST['brand_logo_url'] ?? ''));
    $faviconUrl = trim((string)($_POST['brand_favicon_url'] ?? ''));

    // Length only. The name is escaped at every output site — it reaches a
    // <title>, a sidebar, an email subject and an email body — so the validation
    // here is about it fitting, not about it being safe; sanitize() is what makes
    // it safe, and stripping characters at the input would silently mangle a
    // legitimate name like "Ali & Sons".
    if (mb_strlen($name) > 100) $errors['brand_name'] = 'Max 100 characters.';
    if (mb_strlen($short) > 40) $errors['brand_short_name'] = 'Max 40 characters.';

    // A name that lands in a mail header cannot contain a line break — that is
    // header injection, and the mailer's own guard truncates rather than fails,
    // so refusing it here is what keeps the stored value honest.
    if ($name !== mailHeaderSafe($name)) $errors['brand_name'] = 'No line breaks.';

    foreach ([['brand_logo_url', $logoUrl], ['brand_favicon_url', $faviconUrl]] as [$field, $value]) {
        if ($value !== '' && !brandIsSafeUrl($value)) {
            $errors[$field] = 'Enter a full http:// or https:// URL, or leave blank and upload a file.';
        }
    }

    // Read both uploads before writing anything: a valid name change should not
    // be saved and then reported as a failure because the logo was a PDF.
    $uploads = [];
    foreach (brandAssetKinds() as $kind) {
        $field = $kind . '_file';
        if (!isset($_FILES[$field])) continue;
        [$asset, $err] = brandReadUpload($_FILES[$field]);
        if ($err !== null) { $errors[$field] = $err; continue; }
        if ($asset !== null) $uploads[$kind] = $asset;
    }

    if (!$errors) {
        setAppSetting($conn, 'brand_name', $name);
        setAppSetting($conn, 'brand_short_name', $short);
        setAppSetting($conn, 'brand_logo_url', $logoUrl);
        setAppSetting($conn, 'brand_favicon_url', $faviconUrl);

        foreach ($uploads as $kind => $asset) {
            brandStoreAsset($conn, $kind, $asset['bytes'], $asset['mime']);
        }

        // Metadata only: the image bytes are not audit material.
        logAudit($conn, 'admin.branding.update', 'app_settings', null, [
            'name' => $name !== '' ? $name : '(env default)',
            'uploaded' => array_keys($uploads),
        ]);

        formRespond(true, 'Branding saved.', $self);
    }

    // formErrors(), not formRespond(): the plain-form path falls through to the
    // render below so the admin does not lose what they typed.
    formErrors('Please correct the highlighted fields.', $errors);

    $stored = [
        'brand_name' => $name, 'brand_short_name' => $short,
        'brand_logo_url' => $logoUrl, 'brand_favicon_url' => $faviconUrl,
    ];
}

function bErr($k) { global $errors; return empty($errors[$k]) ? '' : '<div class="invalid-feedback d-block">' . sanitize($errors[$k]) . '</div>'; }
function bCls($k) { global $errors; return empty($errors[$k]) ? '' : ' is-invalid'; }

$assets = brandAssets($conn, true);
$logoUrlNow = brandLogoUrl($conn);
$faviconUrlNow = brandFaviconUrl($conn);

$pageTitle = 'App Branding';
require_once dirname(__DIR__) . '/includes/admin-header.php';
?>

<?php // Rendered from the saved/effective values, deliberately not wired to the
      // inputs below: a live preview would invite an admin to trust a page that
      // has not been saved, so it states plainly which snapshot it shows. ?>
<div class="card mb-4">
    <div class="card-header">Preview</div>
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="rounded px-3 py-2 d-flex align-items-center gap-2" style="background:#1a1014;">
                <i class="bi bi-shield-lock" style="color:var(--admin-accent);"></i>
                <?php if ($logoUrlNow !== ''): ?>
                    <img src="<?= sanitize($logoUrlNow) ?>" alt="Logo" style="max-height:22px;max-width:140px;">
                <?php else: ?>
                    <span class="text-white small fw-500"><?= sanitize($brandName) ?><span class="text-white-50"> Admin</span></span>
                <?php endif; ?>
            </div>
            <div class="rounded-pill border d-flex align-items-center gap-2 px-3 py-1 small text-muted">
                <?php if ($faviconUrlNow !== ''): ?>
                    <img src="<?= sanitize($faviconUrlNow) ?>" alt="" style="height:16px;width:16px;">
                <?php else: ?>
                    <i class="bi bi-shield-lock" style="font-size:0.8rem;"></i>
                <?php endif; ?>
                <span><?= sanitize($brandName) ?> Admin</span>
            </div>
        </div>
        <p class="text-muted x-small mt-2 mb-0">
            This is what is currently saved — edits typed below are not reflected here until you save.
        </p>
    </div>
</div>

<?php // enctype is required for the file inputs; the AJAX path sends the same
      // FormData, so one form serves both. ?>
<form method="POST" enctype="multipart/form-data" data-ajax>
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">

    <div class="card mb-4">
        <div class="card-header">Name</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-7">
                    <label class="form-label">App name</label>
                    <input type="text" name="brand_name" class="form-control<?= bCls('brand_name') ?>"
                           value="<?= sanitize($stored['brand_name']) ?>" maxlength="100"
                           placeholder="<?= sanitize(APP_NAME) ?>">
                    <div class="form-text">
                        Shown in the sidebar, on every page title, on the login and sign-up pages, and in
                        every email this instance sends. Leave blank to use the deployment's own name
                        (<strong><?= sanitize(APP_NAME) ?></strong>, from <code>APP_NAME</code> in
                        <code>.env</code>).
                    </div>
                    <?= bErr('brand_name') ?>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Short name</label>
                    <input type="text" name="brand_short_name" class="form-control<?= bCls('brand_short_name') ?>"
                           value="<?= sanitize($stored['brand_short_name']) ?>" maxlength="40"
                           placeholder="<?= sanitize(brandShortName($conn)) ?>">
                    <div class="form-text">Optional. Used where the full name will not fit.</div>
                    <?= bErr('brand_short_name') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">Logo</div>
        <div class="card-body">
            <?php if ($logoUrlNow !== ''): ?>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="p-2 rounded" style="background:#0f172a;">
                        <img src="<?= sanitize($logoUrlNow) ?>" alt="Current logo" style="max-height:36px;max-width:180px;">
                    </div>
                    <div class="small text-muted">
                        This is what the sidebar and the sign-in page show now.
                        <?php if (isset($assets['logo'])): ?>
                            <div class="x-small">Uploaded file, <?= number_format($assets['logo']['bytes'] / 1024, 1) ?> KB.</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Upload an image</label>
                    <input type="file" name="logo_file" class="form-control<?= bCls('logo_file') ?>"
                           accept="image/png,image/jpeg,image/gif,image/webp">
                    <div class="form-text">
                        PNG, JPEG, GIF or WebP, up to <?= round(BRAND_MAX_ASSET_BYTES / 1024) ?> KB. A wide
                        transparent PNG about 32 pixels tall works best — the sidebar is dark.
                    </div>
                    <?= bErr('logo_file') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">…or link to one</label>
                    <input type="url" name="brand_logo_url" class="form-control<?= bCls('brand_logo_url') ?>"
                           value="<?= sanitize($stored['brand_logo_url']) ?>" placeholder="https://example.com/logo.png">
                    <div class="form-text">A URL here takes precedence over an uploaded file.</div>
                    <?= bErr('brand_logo_url') ?>
                </div>
            </div>
        </div>
        <?php if (isset($assets['logo'])): ?>
            <div class="card-footer bg-white">
                <?php // Its own form, not a second submit button in the one above:
                      // a first submit button becomes the form's default, so Enter
                      // in the name field would have deleted the logo. ?>
                <button class="btn btn-sm btn-outline-danger" type="submit" form="removeLogoForm"
                        data-confirm="Remove the uploaded logo? The sidebar goes back to the app name.">
                    Remove uploaded logo
                </button>
            </div>
        <?php endif; ?>
    </div>

    <div class="card mb-4">
        <div class="card-header">Favicon</div>
        <div class="card-body">
            <?php if ($faviconUrlNow !== ''): ?>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <img src="<?= sanitize($faviconUrlNow) ?>" alt="Current favicon" style="height:32px;width:32px;">
                    <div class="small text-muted">Shown in the browser tab.</div>
                </div>
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Upload an icon</label>
                    <input type="file" name="favicon_file" class="form-control<?= bCls('favicon_file') ?>"
                           accept="image/png,image/x-icon,image/vnd.microsoft.icon,image/webp">
                    <div class="form-text">PNG or ICO, square, 32×32 or 64×64.</div>
                    <?= bErr('favicon_file') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">…or link to one</label>
                    <input type="url" name="brand_favicon_url" class="form-control<?= bCls('brand_favicon_url') ?>"
                           value="<?= sanitize($stored['brand_favicon_url']) ?>" placeholder="https://example.com/favicon.ico">
                    <?= bErr('brand_favicon_url') ?>
                </div>
            </div>
        </div>
        <?php if (isset($assets['favicon'])): ?>
            <div class="card-footer bg-white">
                <button class="btn btn-sm btn-outline-danger" type="submit" form="removeFaviconForm"
                        data-confirm="Remove the uploaded favicon?">
                    Remove uploaded favicon
                </button>
            </div>
        <?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary">Save Branding</button>
</form>

<?php // Outside the form above — nested forms are invalid HTML and the browser
      // drops the inner one's fields entirely. ?>
<?php foreach (brandAssetKinds() as $kind): ?>
    <?php if (isset($assets[$kind])): ?>
        <form method="POST" id="remove<?= ucfirst($kind) ?>Form" class="d-none" data-ajax>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="remove_asset">
            <input type="hidden" name="kind" value="<?= $kind ?>">
        </form>
    <?php endif; ?>
<?php endforeach; ?>

<div class="card">
    <div class="card-header">Where this appears</div>
    <div class="card-body">
        <ul class="list-unstyled small mb-0">
            <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Sign-in, sign-up and password-reset pages</li>
            <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Tenant sidebar, page titles and browser tab</li>
            <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>This admin console</li>
            <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Every email — subject line, header band and footer</li>
            <li class="mb-0"><i class="bi bi-info-circle text-muted me-2"></i>Changes take effect immediately; no redeploy needed</li>
        </ul>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
