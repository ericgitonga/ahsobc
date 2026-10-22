<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/includes/manifest_validator.php';

$TESTS = [
    'validates_canonical_sample_manifest_csv' => function (): void {
        $csvPath = dirname(__DIR__) . '/omeka/staging/sample_manifest.csv';
        $csvContent = (string) file_get_contents($csvPath);
        $res = ahsobc_validate_csv_manifest($csvContent, null);
        if (!$res['valid'] || count($res['records']) < 3) {
            throw new \RuntimeException('Canonical sample_manifest.csv failed validation: ' . json_encode($res['errors']));
        }
    },
    'rejects_path_traversal_and_bad_extensions' => function (): void {
        if (ahsobc_validate_staging_filepath('../etc/passwd') === null) {
            throw new \RuntimeException('Should reject path traversal');
        }
        if (ahsobc_validate_staging_filepath('files/staging/shell.php') === null) {
            throw new \RuntimeException('Should reject executable .php extension');
        }
        if (ahsobc_validate_staging_filepath('files/staging/1932_athletics_squad.tif') !== null) {
            throw new \RuntimeException('Should accept valid .tif under files/staging/');
        }
    },
    'rejects_csv_formula_injection_and_invalid_vocab' => function (): void {
        $maliciousCsv = implode("\n", [
            'file_path,dcterms:title,dcterms:type,dcterms:temporal,dcterms:subject,dcterms:description',
            'files/staging/test.jpg,"=CMD|\' /C calc\'!A0","Unknown Category","circa 1950","1950","Test description"',
        ]);
        $res = ahsobc_validate_csv_manifest($maliciousCsv, null);
        if ($res['valid'] || count($res['errors']) < 2) {
            throw new \RuntimeException('Expected formula injection and invalid vocab errors: ' . json_encode($res));
        }

        // Verify leading '-' and '|' formula injection payloads are also rejected (F-03 / Issue #18)
        if (!ahsobc_has_csv_injection('-2+3+cmd|\' /C calc\'!A0')) {
            throw new \RuntimeException('Expected leading minus formula payload to be flagged');
        }
        if (!ahsobc_has_csv_injection('|cmd')) {
            throw new \RuntimeException('Expected leading pipe DDE payload to be flagged');
        }
    },
];
