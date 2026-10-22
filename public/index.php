<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(AHSOBC_SITE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>
    <header class="site-topbar">
        <a class="site-brand" href="/">
            <img src="/assets/img/obc-logo.jpg" alt="Alliance High School Old Boys Club">
            <span class="site-brand-name">AHSOBC</span>
        </a>
        <nav class="site-nav" aria-label="Main Navigation">
            <a class="site-nav-link active" href="/">Home</a>
            <a class="site-nav-link site-nav-cta" href="<?= htmlspecialchars(AHSOBC_GALLERY_PATH, ENT_QUOTES, 'UTF-8') ?>">Centennial Photo Gallery</a>
        </nav>
    </header>

    <main class="card">
        <img class="logo" src="/assets/img/obc-logo.jpg" alt="Alliance High School - Strong to Serve">

        <h1>Hello, World!</h1>
        <div class="motto"><?= htmlspecialchars(AHSOBC_MOTTO, ENT_QUOTES, 'UTF-8') ?></div>
        <p>Welcome to our website.</p>

        <div class="actions">
            <a class="btn-gallery" href="<?= htmlspecialchars(AHSOBC_GALLERY_PATH, ENT_QUOTES, 'UTF-8') ?>">
                Explore Centennial Photo Gallery
            </a>
        </div>
    </main>
</body>
</html>
