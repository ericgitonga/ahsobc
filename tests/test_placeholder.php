<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/includes/config.php';

$TESTS = [
    'config_constants_are_defined' => function (): void {
        if (AHSOBC_SITE_NAME !== 'Alliance High School Old Boys Club') {
            throw new \RuntimeException('Unexpected AHSOBC_SITE_NAME: ' . AHSOBC_SITE_NAME);
        }
        if (AHSOBC_MOTTO !== 'Strong to Serve') {
            throw new \RuntimeException('Unexpected AHSOBC_MOTTO: ' . AHSOBC_MOTTO);
        }
        if (AHSOBC_GALLERY_PATH !== '/gallery/') {
            throw new \RuntimeException('Unexpected AHSOBC_GALLERY_PATH: ' . AHSOBC_GALLERY_PATH);
        }
        if (!preg_match('/^\d+\.\d+\.\d+$/', AHSOBC_VERSION)) {
            throw new \RuntimeException('Invalid semver AHSOBC_VERSION: ' . AHSOBC_VERSION);
        }
    },
    'logo_asset_exists' => function (): void {
        $logoPath = dirname(__DIR__) . '/public/assets/img/obc-logo.jpg';
        if (!is_file($logoPath) || filesize($logoPath) < 1000) {
            throw new \RuntimeException('Missing or empty logo asset at ' . $logoPath);
        }
    },
];
