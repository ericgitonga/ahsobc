<?php
/**
 * AHS Cohort Year, House, School Number, and Face-Row Identification Helper.
 *
 * Supports pipe-delimited multi-cohort parsing ("1974 | 1975"), House normalization,
 * School Number validation, and Mirador IIIF face-row identification formatting.
 */

declare(strict_types=1);

require_once __DIR__ . '/schema_definitions.php';

/**
 * Parses a raw dcterms:subject cohort string (e.g., "1974 | 1975" or "Class of 1978")
 * into normalized integer cohort years and formatted labels.
 *
 * @param string $rawCohort
 * @return array{
 *   valid: bool,
 *   years: list<int>,
 *   labels: list<string>,
 *   decades: list<string>,
 *   error: string|null
 * }
 */
function ahsobc_parse_cohorts(string $rawCohort): array
{
    $trimmed = trim($rawCohort);
    if ($trimmed === '') {
        return [
            'valid' => false,
            'years' => [],
            'labels' => [],
            'decades' => [],
            'error' => 'Cohort value cannot be empty.',
        ];
    }

    $parts = preg_split('/\s*[|,;]\s*/', $trimmed) ?: [];
    $years = [];
    $maxYear = (int) date('Y') + 1;

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (preg_match('/^(?:Class\s+of\s+)?[\'’]?(\d{2}|\d{4})$/i', $part, $m)) {
            $token = $m[1];
            $year = strlen($token) === 2 ? (1900 + (int) $token) : (int) $token;
            if ($year < 1926 || $year > $maxYear) {
                return [
                    'valid' => false,
                    'years' => [],
                    'labels' => [],
                    'decades' => [],
                    'error' => "Cohort year {$year} is outside Alliance High School's 1926–{$maxYear} range.",
                ];
            }
            if (!in_array($year, $years, true)) {
                $years[] = $year;
            }
        } else {
            return [
                'valid' => false,
                'years' => [],
                'labels' => [],
                'decades' => [],
                'error' => "Invalid cohort token '{$part}'. Expected year (e.g., '1974', '1974 | 1975', or 'Class of 1978').",
            ];
        }
    }

    if (!$years) {
        return [
            'valid' => false,
            'years' => [],
            'labels' => [],
            'decades' => [],
            'error' => 'No valid cohort years found.',
        ];
    }

    sort($years);
    $labels = array_map(static fn(int $y): string => "Class of {$y}", $years);
    $decades = array_values(array_unique(array_map(
        static fn(int $y): string => ((int) floor($y / 10) * 10) . 's',
        $years
    )));

    return [
        'valid' => true,
        'years' => $years,
        'labels' => $labels,
        'decades' => $decades,
        'error' => null,
    ];
}

/**
 * Normalizes an Alliance High School House name against the 9 canonical Houses.
 */
function ahsobc_normalize_house(string $rawHouse): ?string
{
    $clean = trim(preg_replace('/\bHouse\b/i', '', $rawHouse) ?? '');
    if ($clean === '') {
        return null;
    }
    foreach (AHSOBC_HOUSES as $house) {
        if (strcasecmp($clean, $house) === 0) {
            return $house;
        }
    }
    return null;
}

/**
 * Validates and normalizes an AHS School Number (e.g. "#1420" or "1420").
 */
function ahsobc_normalize_school_number(string $rawNumber): ?string
{
    $clean = ltrim(trim($rawNumber), '# ');
    if ($clean === '' || !preg_match('/^\d{1,5}$/', $clean)) {
        return null;
    }
    $num = (int) $clean;
    if ($num < 1 || $num > 50000) {
        return null;
    }
    return (string) $num;
}

/**
 * Validates and formats a structured Historic Face Identification annotation (Contribution Type 3).
 *
 * @param array<string, mixed> $input
 * @return array{
 *   valid: bool,
 *   formatted_note: string,
 *   structured: array<string, mixed>,
 *   errors: list<string>
 * }
 */
function ahsobc_format_face_identification(array $input): array
{
    $errors = [];

    $row = isset($input['row']) ? (int) $input['row'] : 0;
    if ($row < 1 || $row > 25) {
        $errors[] = 'Row number must be between 1 (Front/Seated) and 25.';
    }

    $position = isset($input['position_from_left']) ? (int) $input['position_from_left'] : 0;
    if ($position < 1 || $position > 60) {
        $errors[] = 'Position from left must be between 1 and 60.';
    }

    $fullName = trim((string) ($input['full_name'] ?? ''));
    if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 120) {
        $errors[] = 'Proposed alumnus name must be between 3 and 120 characters.';
    }

    $schoolNumber = null;
    $rawSchoolNo = trim((string) ($input['school_number'] ?? ''));
    if ($rawSchoolNo !== '') {
        $schoolNumber = ahsobc_normalize_school_number($rawSchoolNo);
        if ($schoolNumber === null) {
            $errors[] = 'AHS School Number must be a positive integer between 1 and 50000.';
        }
    }

    $cohortYear = null;
    $rawCohort = trim((string) ($input['cohort'] ?? ''));
    if ($rawCohort !== '') {
        $parsedCohort = ahsobc_parse_cohorts($rawCohort);
        if (!$parsedCohort['valid']) {
            $errors[] = $parsedCohort['error'] ?? 'Invalid cohort year.';
        } else {
            $cohortYear = $parsedCohort['years'][0];
        }
    }

    $house = null;
    $rawHouse = trim((string) ($input['house'] ?? ''));
    if ($rawHouse !== '') {
        $house = ahsobc_normalize_house($rawHouse);
        if ($house === null) {
            $errors[] = 'Unrecognized AHS House. Valid houses: ' . implode(', ', AHSOBC_HOUSES) . '.';
        }
    }

    $xywh = null;
    $rawRegion = trim((string) ($input['iiif_region'] ?? ''));
    if ($rawRegion !== '') {
        if (preg_match('/^(?:xywh=)?(\d{1,5},\d{1,5},\d{1,5},\d{1,5})$/', $rawRegion, $m)) {
            $xywh = 'xywh=' . $m[1];
        } else {
            $errors[] = 'IIIF region must match xywh=x,y,w,h integer coordinates.';
        }
    }

    if ($errors) {
        return [
            'valid' => false,
            'formatted_note' => '',
            'structured' => [],
            'errors' => $errors,
        ];
    }

    $metaParts = [];
    if ($schoolNumber !== null) {
        $metaParts[] = "School No. #{$schoolNumber}";
    }
    if ($cohortYear !== null) {
        $metaParts[] = "Class of {$cohortYear}";
    }
    if ($house !== null) {
        $metaParts[] = "{$house} House";
    }
    if ($xywh !== null) {
        $metaParts[] = "IIIF {$xywh}";
    }

    $suffix = $metaParts ? ' (' . implode(', ', $metaParts) . ')' : '';
    $formatted = "Row {$row}, Position {$position} from Left: {$fullName}{$suffix}";

    return [
        'valid' => true,
        'formatted_note' => $formatted,
        'structured' => [
            'row' => $row,
            'position_from_left' => $position,
            'full_name' => $fullName,
            'school_number' => $schoolNumber,
            'cohort_year' => $cohortYear,
            'house' => $house,
            'iiif_region' => $xywh,
        ],
        'errors' => [],
    ];
}
