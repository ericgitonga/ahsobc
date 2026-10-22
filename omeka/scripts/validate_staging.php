<?php
/**
 * CLI Pre-Ingest Staging Manifest Validator for Omeka S CSVImport (Phase 4.1).
 *
 * Usage:
 *   php omeka/scripts/validate_staging.php [path/to/manifest.csv] [--check-files staging_root_dir]
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/public/includes/manifest_validator.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$manifestFile = $argv[1] ?? (dirname(__DIR__) . '/staging/sample_manifest.csv');
$stagingRoot = null;
if (in_array('--check-files', $argv, true)) {
    $idx = array_search('--check-files', $argv, true);
    $stagingRoot = $argv[$idx + 1] ?? dirname(__DIR__);
}

if (!is_file($manifestFile)) {
    fwrite(STDERR, "Error: Manifest file not found at '{$manifestFile}'.\n");
    exit(1);
}

$content = (string) file_get_contents($manifestFile);
$result = ahsobc_validate_csv_manifest($content, $stagingRoot);

if (!$result['valid']) {
    fwrite(STDERR, "Manifest Validation FAILED ({$manifestFile}):\n");
    foreach ($result['errors'] as $err) {
        fwrite(STDERR, "  - {$err}\n");
    }
    exit(1);
}

echo "Manifest OK ({$manifestFile}): " . count($result['records']) . " valid archival record(s) ready for Omeka S CSVImport.\n";
exit(0);
