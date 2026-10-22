<?php
/**
 * Entry point for https://www.ahsobc.or.ke/gallery/ (Omeka S Centennial Photo Gallery).
 *
 * When full Omeka S v4.1.1 core is unpacked into /gallery/ on Angryhosting alongside a live
 * MySQL database (and AHSOBC_USE_FULL_OMEKA_CORE=1 is set), this delegates to Omeka S's
 * Laminas MVC bootstrap. Otherwise, it serves the integrated AHSOBC Omeka S Centennial Gallery
 * runtime controller backed by the provisioned archive catalog (MySQL or SQLite).
 */

declare(strict_types=1);

if (
    getenv('AHSOBC_USE_FULL_OMEKA_CORE') === '1'
    && is_file(__DIR__ . '/bootstrap.php')
    && is_file(__DIR__ . '/application/Module.php')
) {
    require __DIR__ . '/bootstrap.php';
    \Omeka\Mvc\Application::init(require __DIR__ . '/application/config/application.config.php')->run();
    return;
}

require_once dirname(__DIR__) . '/includes/gallery_app.php';
