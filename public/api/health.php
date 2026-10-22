<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$requiredExtensions = ['pdo', 'gd', 'xml', 'intl', 'mbstring'];
$loadedExtensions = [];
foreach ($requiredExtensions as $ext) {
    $loadedExtensions[$ext] = extension_loaded($ext);
}

echo json_encode([
    'status' => 'ok',
    'site' => AHSOBC_SITE_NAME,
    'version' => AHSOBC_VERSION,
    'php_version' => PHP_VERSION,
    'gallery_path' => AHSOBC_GALLERY_PATH,
    'extensions' => $loadedExtensions,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
