<?php
/**
 * Site-wide configuration constants for Alliance High School Old Boys Club (AHSOBC).
 */

declare(strict_types=1);

define('AHSOBC_SITE_NAME', 'Alliance High School Old Boys Club');
define('AHSOBC_MOTTO', 'Strong to Serve');
define('AHSOBC_DOMAIN', 'https://www.ahsobc.or.ke');
define('AHSOBC_GALLERY_PATH', '/gallery/');
define('AHSOBC_VERSION', trim((string) @file_get_contents(dirname(__DIR__, 2) . '/VERSION')) ?: '0.1.0');
define('AHSOBC_CENTENNIAL_SPAN', '1926–2026');
