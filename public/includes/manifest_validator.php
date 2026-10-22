<?php
/**
 * Pre-Ingest CSV Manifest & Staging File Validator for Omeka S (Phase 4.1 of OmekaS.pdf).
 *
 * Validates CSV manifests destined for the Omeka S CSVImport module:
 *   - Verifies required headers: file_path, dcterms:title, dcterms:type, dcterms:temporal, dcterms:subject, dcterms:description
 *   - Rejects path traversal ("..") and paths outside files/staging/
 *   - Rejects CSV formula injection payloads (=, +, -, @)
 *   - Validates dcterms:type against AHSOBC_PHOTO_CONTEXTS
 *   - Validates dcterms:temporal via ahsobc_parse_temporal()
 *   - Validates dcterms:subject via ahsobc_parse_cohorts()
 */

declare(strict_types=1);

require_once __DIR__ . '/temporal_parser.php';
require_once __DIR__ . '/cohort_helper.php';

const AHSOBC_REQUIRED_MANIFEST_HEADERS = [
    'file_path',
    'dcterms:title',
    'dcterms:type',
    'dcterms:temporal',
    'dcterms:subject',
    'dcterms:description',
];

/**
 * Checks if a cell string starts with a dangerous spreadsheet formula character.
 */
function ahsobc_has_csv_injection(string $value): bool
{
    $trimmed = ltrim($value);
    if ($trimmed === '') {
        return false;
    }
    return in_array($trimmed[0], ['=', '+', '@', "\t", "\r"], true);
}

/**
 * Validates a staging file_path value for safety and allowed archival extensions.
 */
function ahsobc_validate_staging_filepath(string $filePath): ?string
{
    $trimmed = trim($filePath);
    if ($trimmed === '') {
        return 'file_path cannot be empty.';
    }
    if (str_contains($trimmed, '..') || str_starts_with($trimmed, '/') || str_contains($trimmed, '\\')) {
        return "Unsafe file_path '{$trimmed}': must be a relative path under files/staging/ with no '..' traversal.";
    }
    if (!str_starts_with($trimmed, 'files/staging/')) {
        return "file_path '{$trimmed}' must reside inside 'files/staging/'.";
    }
    $ext = strtolower(pathinfo($trimmed, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'tif', 'tiff', 'webp'];
    if (!in_array($ext, $allowedExtensions, true)) {
        return "Unsupported file extension '.{$ext}' in '{$trimmed}'. Allowed: " . implode(', ', $allowedExtensions) . '.';
    }
    return null;
}

/**
 * Parses and validates an entire CSV manifest string (or file contents).
 *
 * @param string $csvContent Raw CSV string.
 * @param string|null $stagingRootDir Optional root directory on disk to verify physical file existence.
 * @return array{
 *   valid: bool,
 *   records: list<array<string, mixed>>,
 *   errors: list<string>
 * }
 */
function ahsobc_validate_csv_manifest(string $csvContent, ?string $stagingRootDir = null): array
{
    $errors = [];
    $records = [];

    $lines = preg_split('/\r\n|\r|\n/', trim($csvContent)) ?: [];
    $lines = array_values(array_filter($lines, static fn(string $l): bool => trim($l) !== ''));

    if (count($lines) < 2) {
        return [
            'valid' => false,
            'records' => [],
            'errors' => ['Manifest CSV must contain a header row and at least one data row.'],
        ];
    }

    $headers = str_getcsv($lines[0], ',', '"', '\\');
    $headers = array_map(static fn(?string $h): string => trim((string) $h), $headers);

    foreach (AHSOBC_REQUIRED_MANIFEST_HEADERS as $reqHeader) {
        if (!in_array($reqHeader, $headers, true)) {
            $errors[] = "Missing required CSV header column: '{$reqHeader}'.";
        }
    }

    if ($errors) {
        return [
            'valid' => false,
            'records' => [],
            'errors' => $errors,
        ];
    }

    $headerCount = count($headers);

    for ($i = 1, $total = count($lines); $i < $total; $i++) {
        $rowNum = $i + 1;
        $cols = str_getcsv($lines[$i], ',', '"', '\\');
        if (count($cols) !== $headerCount) {
            $errors[] = "Row {$rowNum}: Expected {$headerCount} columns, found " . count($cols) . '.';
            continue;
        }

        $rowAssoc = [];
        foreach ($headers as $idx => $hName) {
            $cellVal = trim((string) ($cols[$idx] ?? ''));
            if (ahsobc_has_csv_injection($cellVal)) {
                $errors[] = "Row {$rowNum}, column '{$hName}': Potential CSV formula injection character rejected.";
            }
            $rowAssoc[$hName] = $cellVal;
        }

        // 1. Validate file_path
        $pathErr = ahsobc_validate_staging_filepath($rowAssoc['file_path']);
        if ($pathErr !== null) {
            $errors[] = "Row {$rowNum}: {$pathErr}";
        } elseif ($stagingRootDir !== null) {
            $fullDiskPath = rtrim($stagingRootDir, '/') . '/' . $rowAssoc['file_path'];
            if (!is_file($fullDiskPath)) {
                $errors[] = "Row {$rowNum}: Staged file not found on disk at '{$fullDiskPath}'.";
            }
        }

        // 2. Validate dcterms:title
        if (mb_strlen($rowAssoc['dcterms:title']) < 3) {
            $errors[] = "Row {$rowNum}: dcterms:title must be at least 3 characters.";
        }

        // 3. Validate dcterms:type against controlled vocabulary
        $photoType = $rowAssoc['dcterms:type'];
        if (!array_key_exists($photoType, AHSOBC_PHOTO_CONTEXTS)) {
            $errors[] = "Row {$rowNum}: dcterms:type '{$photoType}' is not in Controlled Vocab (" . implode(', ', array_keys(AHSOBC_PHOTO_CONTEXTS)) . ').';
        }

        // 4. Validate dcterms:temporal
        $temporal = ahsobc_parse_temporal($rowAssoc['dcterms:temporal']);
        if (!$temporal['valid']) {
            $errors[] = "Row {$rowNum}: " . ($temporal['error'] ?? 'Invalid dcterms:temporal.');
        }

        // 5. Validate dcterms:subject (Cohorts)
        $cohorts = ahsobc_parse_cohorts($rowAssoc['dcterms:subject']);
        if (!$cohorts['valid']) {
            $errors[] = "Row {$rowNum}: " . ($cohorts['error'] ?? 'Invalid dcterms:subject cohort.');
        }

        // 6. Validate dcterms:description
        if ($rowAssoc['dcterms:description'] === '') {
            $errors[] = "Row {$rowNum}: dcterms:description cannot be empty.";
        }

        $records[] = [
            'row_number' => $rowNum,
            'file_path' => $rowAssoc['file_path'],
            'dcterms:title' => $rowAssoc['dcterms:title'],
            'dcterms:type' => $photoType,
            'dcterms:temporal' => $rowAssoc['dcterms:temporal'],
            'dcterms:subject' => $rowAssoc['dcterms:subject'],
            'dcterms:description' => $rowAssoc['dcterms:description'],
            'dcterms:spatial' => $rowAssoc['dcterms:spatial'] ?? '',
            'dcterms:isPartOf' => $rowAssoc['dcterms:isPartOf'] ?? '',
            'parsed_temporal' => $temporal,
            'parsed_cohorts' => $cohorts,
        ];
    }

    return [
        'valid' => count($errors) === 0,
        'records' => $records,
        'errors' => $errors,
    ];
}
