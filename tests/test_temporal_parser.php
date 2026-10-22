<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/includes/temporal_parser.php';

$TESTS = [
    'parses_exact_iso_date' => function (): void {
        $res = ahsobc_parse_temporal('1985-06-15');
        if (!$res['valid'] || $res['year_start'] !== 1985 || $res['year_end'] !== 1985 || $res['decade'] !== '1980s') {
            throw new \RuntimeException('Failed ISO date parse: ' . json_encode($res));
        }
        if ($res['is_fuzzy'] !== false || $res['era_id'] !== 'late-20th-century') {
            throw new \RuntimeException('Unexpected era/fuzzy flag: ' . json_encode($res));
        }
    },
    'parses_fuzzy_circa_year_range' => function (): void {
        $res = ahsobc_parse_temporal('circa 1930-1932');
        if (!$res['valid'] || !$res['is_fuzzy'] || $res['year_start'] !== 1930 || $res['year_end'] !== 1932) {
            throw new \RuntimeException('Failed circa range parse: ' . json_encode($res));
        }
        if ($res['decade'] !== '1930s' || $res['era_id'] !== 'founding') {
            throw new \RuntimeException('Expected 1930s founding era: ' . json_encode($res));
        }
    },
    'parses_single_circa_year_and_shorthand_range' => function (): void {
        $c1 = ahsobc_parse_temporal('circa 1958');
        if (!$c1['valid'] || $c1['year_start'] !== 1958 || $c1['era_id'] !== 'carey-francis-independence') {
            throw new \RuntimeException('Failed circa 1958 parse: ' . json_encode($c1));
        }
        $c2 = ahsobc_parse_temporal('1954-56');
        if (!$c2['valid'] || $c2['year_start'] !== 1954 || $c2['year_end'] !== 1956) {
            throw new \RuntimeException('Failed 2-digit range parse: ' . json_encode($c2));
        }
    },
    'parses_decade_expressions' => function (): void {
        $res = ahsobc_parse_temporal('Early 1970s');
        if (!$res['valid'] || !$res['is_fuzzy'] || $res['year_start'] !== 1970 || $res['year_end'] !== 1979 || $res['decade'] !== '1970s') {
            throw new \RuntimeException('Failed decade expression parse: ' . json_encode($res));
        }
    },
    'rejects_invalid_and_out_of_horizon_dates' => function (): void {
        foreach (['', '1895', '1985-02-30', 'circa 1960-1950', 'not-a-date'] as $bad) {
            $res = ahsobc_parse_temporal($bad);
            if ($res['valid']) {
                throw new \RuntimeException("Expected invalid temporal result for '{$bad}'");
            }
        }
    },
];
