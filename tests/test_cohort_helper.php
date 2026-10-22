<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/includes/cohort_helper.php';

$TESTS = [
    'parses_pipe_delimited_and_prefixed_cohorts' => function (): void {
        $res = ahsobc_parse_cohorts('1974 | 1975');
        if (!$res['valid'] || $res['years'] !== [1974, 1975] || $res['decades'] !== ['1970s']) {
            throw new \RuntimeException('Failed pipe cohort parse: ' . json_encode($res));
        }

        $pref = ahsobc_parse_cohorts("Class of '60");
        if (!$pref['valid'] || $pref['years'] !== [1960] || $pref['labels'] !== ['Class of 1960']) {
            throw new \RuntimeException('Failed two-digit Class of parse: ' . json_encode($pref));
        }
    },
    'rejects_out_of_range_cohorts' => function (): void {
        $preFounding = ahsobc_parse_cohorts('1920');
        if ($preFounding['valid']) {
            throw new \RuntimeException('Should reject cohort before 1926 founding');
        }
        $empty = ahsobc_parse_cohorts('   ');
        if ($empty['valid']) {
            throw new \RuntimeException('Should reject empty cohort string');
        }
    },
    'normalizes_eight_ahs_houses_and_school_numbers' => function (): void {
        $expectedOrder = ['Livingstone', 'Aggrey', 'Wilberforce', 'Grieve', 'Arthur', 'Francis', 'Smith', 'Sellwood'];
        if (AHSOBC_HOUSES !== $expectedOrder) {
            throw new \RuntimeException('Unexpected AHSOBC_HOUSES order: ' . implode(', ', AHSOBC_HOUSES));
        }
        if (ahsobc_normalize_house('francis house') !== 'Francis') {
            throw new \RuntimeException('Failed Francis normalization');
        }
        if (ahsobc_normalize_house('Wilberforce') !== 'Wilberforce') {
            throw new \RuntimeException('Failed Wilberforce normalization');
        }
        if (ahsobc_normalize_house('Hogwarts') !== null) {
            throw new \RuntimeException('Should return null for unknown house');
        }
        if (ahsobc_normalize_school_number('#1842') !== '1842') {
            throw new \RuntimeException('Failed school number normalization');
        }
        if (ahsobc_normalize_school_number('0') !== null || ahsobc_normalize_school_number('abc') !== null) {
            throw new \RuntimeException('Should reject invalid school numbers');
        }
    },
    'formats_mirador_face_row_identification' => function (): void {
        $res = ahsobc_format_face_identification([
            'row' => 2,
            'position_from_left' => 4,
            'full_name' => 'Dr. Joseph Kamau Githunguri',
            'school_number' => '#1842',
            'cohort' => '1960',
            'house' => 'Francis House',
            'iiif_region' => '610,280,150,190',
        ]);
        if (!$res['valid']) {
            throw new \RuntimeException('Expected valid face identification: ' . json_encode($res['errors']));
        }
        $expected = 'Row 2, Position 4 from Left: Dr. Joseph Kamau Githunguri (School No. #1842, Class of 1960, Francis House, IIIF xywh=610,280,150,190)';
        if ($res['formatted_note'] !== $expected) {
            throw new \RuntimeException("Unexpected formatted note: {$res['formatted_note']}");
        }
    },
];
