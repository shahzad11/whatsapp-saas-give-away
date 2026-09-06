<?php

// White-label branding (#23): the app's name, short name, logo and favicon.
//
// APP_NAME remains the deployment-level default, exactly like every other
// setting that was an environment variable first (see includes/settings.php): an
// absent or blank row falls through to the env constant, so clearing the field
// in the admin console cannot leave the instance nameless.
//
// The logo and favicon *bytes* live in a table, not on disk. The frontend
// container has no writable volume, so a file written under the document root
// would be lost on the next `docker compose build` — and every deploy in this
// project builds from source. The database is the only store here that survives
// a release, and the deploy ritual already dumps it before every one. It also
// satisfies "stored outside the web root" for free: nothing under the document
// root can be requested directly, and brand-asset.php is the only reader.

// Uploads are capped well below the TEXT/BLOB ceiling and well below anything a
// sane logo needs. A 5 MB PNG in a navbar is a bug, not a preference.
const BRAND_MAX_ASSET_BYTES = 512 * 1024;

// The formats a browser will render in an <img> and a <link rel="icon">, mapped
// from what finfo actually reports. SVG is deliberately absent: an SVG is a
// document that can carry script, and serving one from our own origin would
// hand an admin-uploaded file same-origin scripting. PNG/JPEG/GIF/ICO/WebP
// cover every real logo.
function brandAllowedMimes() {
    return [
        'image/png'                => 'png',
        'image/jpeg'               => 'jpg',
        'image/gif'                => 'gif',
        'image/webp'               => 'webp',
        'image/vnd.microsoft.icon' => 'ico',
        'image/x-icon'             => 'ico',
    ];
}

function brandAssetKinds() {
    return ['logo', 'favicon'];
}

// --- Reading ----------------------------------------------------------------

function brandName(?mysqli $conn = null) {
    $value = trim((string)(overrideSetting($conn, 'brand_name') ?? ''));
    return $value !== '' ? $value : APP_NAME;
}

// Used where the full name will not fit — a favicon-sized mark, an email
// subject prefix. Falls back to the full name rather than to a truncation,
// which would be a guess.
function brandShortName(?mysqli $conn = null) {
    $value = trim((string)(overrideSetting($conn, 'brand_short_name') ?? ''));
    return $value !== '' ? $value : brandName($conn);
}

// Metadata for both assets in one query, cached for the request: the header
// renders a logo and a favicon on every page, and two queries per page for
// something that changes once a year is waste.
//
// Wrapped, like appSettings(), because a request can arrive before the table
// exists — a boot mid-upgrade must not fatal every page.
function brandAssets(?mysqli $conn = null, $refresh = false) {
    static $cache = null;
    if ($cache !== null && !$refresh) return $cache;

    $db = settingsConn($conn);
    if (!$db) return [];

    $cache = [];
    try {
        $res = $db->query("SELECT kind, mime_type, byte_size, UNIX_TIMESTAMP(updated_at) AS version FROM brand_assets");
        while ($res && $row = $res->fetch_assoc()) {
            $cache[$row['kind']] = [
                'mime'    => $row['mime_type'],
                'bytes'   => (int)$row['byte_size'],
                'version' => (int)$row['version'],
            ];
        }
        if ($res) $res->free();
    } catch (mysqli_sql_exception $e) {
        error_log('brand_assets unavailable: ' . $e->getMessage());
    }
    return $cache;
}

// An external URL wins over an upload: an operator who already serves their logo
// from a CDN should not have to upload a second copy, and the field is the more
// explicit of the two. Returns '' when there is nothing to show, which is what
// every caller falls back on.
//
// The `v=` parameter is the stored row's mtime, so a replaced logo appears
// immediately instead of being served from the browser cache for a year — which
// is the whole point of the far-future Cache-Control in brand-asset.php.
function brandAssetUrl(?mysqli $conn = null, $kind = 'logo') {
    if (!in_array($kind, brandAssetKinds(), true)) return '';

    $url = trim((string)(overrideSetting($conn, 'brand_' . $kind . '_url') ?? ''));
    if ($url !== '' && brandIsSafeUrl($url)) return $url;

    $asset = brandAssets($conn)[$kind] ?? null;
    return $asset ? APP_URL . '/brand-asset.php?kind=' . $kind . '&v=' . $asset['version'] : '';
}

function brandLogoUrl(?mysqli $conn = null) {
    return brandAssetUrl($conn, 'logo');
}

function brandFaviconUrl(?mysqli $conn = null) {
    return brandAssetUrl($conn, 'favicon');
}

// http(s) and nothing else. A stored value reaches an href/src attribute, so
// `javascript:` and `data:` are refused rather than escaped — escaping makes the
// attribute well-formed, it does not make the scheme harmless.
function brandIsSafeUrl($url) {
    $scheme = strtolower((string)parse_url((string)$url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) && parse_url((string)$url, PHP_URL_HOST) !== null;
}

// --- Writing ----------------------------------------------------------------

// Validates an uploaded file and returns [bytes, mime] or [null, error].
//
// The MIME is sniffed from the content with finfo, never taken from the
// browser's Content-Type: that header is supplied by the client and is what a
// crafted upload sets to whatever it likes. getimagesize() is the second gate —
// a file whose bytes finfo happens to read as an image but which has no
// decodable dimensions is not a logo.
function brandReadUpload(array $file) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        // INI_SIZE/FORM_SIZE mean the file never fully arrived, so its own size
        // cannot be reported back — say what the limit is instead.
        return [null, in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'That file is too large. The limit is ' . round(BRAND_MAX_ASSET_BYTES / 1024) . ' KB.'
            : 'The upload did not complete. Please try again.'];
    }

    // The tmp_name must be a file PHP itself received. Without this check a
    // crafted POST naming an arbitrary path would have it read and stored.
    if (!is_uploaded_file($file['tmp_name'])) return [null, 'That upload could not be verified.'];

    if ((int)$file['size'] > BRAND_MAX_ASSET_BYTES) {
        return [null, 'That file is ' . round($file['size'] / 1024) . ' KB. The limit is '
            . round(BRAND_MAX_ASSET_BYTES / 1024) . ' KB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    $allowed = brandAllowedMimes();
    if (!isset($allowed[$mime])) {
        return [null, 'That is not an image we can serve. Use PNG, JPEG, GIF, WebP or ICO.'];
    }

    // ICO is not a format getimagesize() reads on every build, so it is exempt
    // from the dimension gate; finfo's magic-number match is the check there.
    if ($allowed[$mime] !== 'ico' && @getimagesize($file['tmp_name']) === false) {
        return [null, 'That image could not be read. Try re-exporting it.'];
    }

    $bytes = file_get_contents($file['tmp_name']);
    if ($bytes === false || $bytes === '') return [null, 'That file could not be read.'];

    return [['bytes' => $bytes, 'mime' => $mime], null];
}

function brandStoreAsset(mysqli $conn, $kind, $bytes, $mime) {
    if (!in_array($kind, brandAssetKinds(), true)) return false;

    $size = strlen($bytes);
    $stmt = $conn->prepare(
        "INSERT INTO brand_assets (kind, mime_type, content, byte_size)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE mime_type = VALUES(mime_type), content = VALUES(content),
                                 byte_size = VALUES(byte_size), updated_at = CURRENT_TIMESTAMP"
    );
    // Bound inline, as a plain string. The obvious-looking alternative,
    // send_long_data(), silently wrote NULL here: it only feeds a parameter
    // declared 'b', and with 's' mysqli sends the (null) bound variable instead
    // and MySQL rejects the row — "Column 'content' cannot be null". An inline
    // bind is also simply sufficient, because BRAND_MAX_ASSET_BYTES caps an
    // upload at 512 KB, orders of magnitude below max_allowed_packet. Chunking
    // would only be needed for a blob that could approach it.
    $stmt->bind_param('sssi', $kind, $mime, $bytes, $size);
    $stmt->execute();
    $stmt->close();

    brandAssets($conn, true);
    return true;
}

function brandDeleteAsset(mysqli $conn, $kind) {
    $stmt = $conn->prepare("DELETE FROM brand_assets WHERE kind = ?");
    $stmt->bind_param('s', $kind);
    $stmt->execute();
    $stmt->close();

    brandAssets($conn, true);
}

// The bytes, for brand-asset.php only. Separate from brandAssets() so a page
// that renders a logo tag never loads the logo itself into memory.
function brandAssetContent(mysqli $conn, $kind) {
    $stmt = $conn->prepare("SELECT mime_type, content FROM brand_assets WHERE kind = ?");
    $stmt->bind_param('s', $kind);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
