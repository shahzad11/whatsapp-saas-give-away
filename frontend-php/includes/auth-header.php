<?php
$pageTitle = $pageTitle ?? 'Welcome';
// #23. These pages are unauthenticated, which is exactly why the branding has to
// reach them: the sign-in page is the first thing anyone sees of the instance.
$brandName = brandName($conn ?? null);
$brandFavicon = brandFaviconUrl($conn ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle) ?> - <?= sanitize($brandName) ?></title>
    <?php if ($brandFavicon !== ''): ?>
        <link rel="icon" href="<?= sanitize($brandFavicon) ?>">
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-body">
