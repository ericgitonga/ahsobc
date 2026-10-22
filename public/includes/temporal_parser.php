<?php
/**
 * Fuzzy Historical Date & Temporal Range Parser for AHSOBC Centennial Gallery.
 *
 * Parses dcterms:temporal values such as:
 *   - "circa 1930-1932"
 *   - "circa 1952"
 *   - "1985-06-15"
 *   - "2018-09-22"
 *   - "1960s"
 *   - "Term 2, 1978"
 * into normalized year ranges, decades, and Centennial Eras (1926–2026).
 */

declare(strict_types=1);

require_once __DIR__ . '/schema_definitions.php';

/**
 * Parses an archival date string into structured temporal facets.
 *
 * @param string $raw Raw dcterms:temporal string.
 * @return array{
 *   valid: bool,
 *   raw: string,
 *   is_fuzzy: bool,
 *   year_start: int|null,
 *   year_end: int|null,
 *   iso_date: string|null,
 *   decade: string|null,
 *   era_id: string|null,
 *   era_label: string|null,
 *   display_label: string,
 *   error: string|null
 * }
 */
function ahsobc_parse_temporal(string $raw): array
{
    $trimmed = trim($raw);
    if ($trimmed === '') {
        return [
            'valid' => false,
            'raw' => $raw,
            'is_fuzzy' => false,
            'year_start' => null,
            'year_end' => null,
            'iso_date' => null,
            'decade' => null,
            'era_id' => null,
            'era_label' => null,
            'display_label' => '',
            'error' => 'Temporal date cannot be empty.',
        ];
    }

    $isFuzzy = (bool) preg_match('/\b(circa|c\.|ca\.|approx|early|mid|late)\b/i', $trimmed);
    $isoDate = null;
    $yearStart = null;
    $yearEnd = null;

    // 1. Exact ISO date YYYY-MM-DD
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $trimmed, $m)) {
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        if (!checkdate($month, $day, $year)) {
            return [
                'valid' => false,
                'raw' => $raw,
                'is_fuzzy' => false,
                'year_start' => null,
                'year_end' => null,
                'iso_date' => null,
                'decade' => null,
                'era_id' => null,
                'era_label' => null,
                'display_label' => $trimmed,
                'error' => "Invalid calendar date: {$trimmed}.",
            ];
        }
        $yearStart = $year;
        $yearEnd = $year;
        $isoDate = $trimmed;
    } elseif (preg_match('/^(?:circa|c\.?|ca\.?)?\s*(\d{4})\s*[-–\/]\s*(\d{2,4})$/i', $trimmed, $m)) {
        // 2. Year range: "circa 1930-1932" or "1954-56"
        $yearStart = (int) $m[1];
        $endRaw = $m[2];
        if (strlen($endRaw) === 2) {
            $centuryPrefix = substr((string) $yearStart, 0, 2);
            $yearEnd = (int) ($centuryPrefix . $endRaw);
        } else {
            $yearEnd = (int) $endRaw;
        }
        if ($yearEnd < $yearStart) {
            return [
                'valid' => false,
                'raw' => $raw,
                'is_fuzzy' => true,
                'year_start' => null,
                'year_end' => null,
                'iso_date' => null,
                'decade' => null,
                'era_id' => null,
                'era_label' => null,
                'display_label' => $trimmed,
                'error' => "End year ({$yearEnd}) cannot precede start year ({$yearStart}).",
            ];
        }
    } elseif (preg_match('/^(?:early|mid|late)?\s*(\d{4})s$/i', $trimmed, $m)) {
        // 3. Decade expression: "1960s", "Early 1970s"
        $decadeBase = (int) (substr($m[1], 0, 3) . '0');
        $yearStart = $decadeBase;
        $yearEnd = $decadeBase + 9;
        $isFuzzy = true;
    } elseif (preg_match('/\b(19\d{2}|20\d{2})\b/', $trimmed, $m)) {
        // 4. Single 4-digit year with optional prefix/context: "circa 1952", "1932", "Term 2, 1978"
        $yearStart = (int) $m[1];
        $yearEnd = $yearStart;
    } else {
        return [
            'valid' => false,
            'raw' => $raw,
            'is_fuzzy' => $isFuzzy,
            'year_start' => null,
            'year_end' => null,
            'iso_date' => null,
            'decade' => null,
            'era_id' => null,
            'era_label' => null,
            'display_label' => $trimmed,
            'error' => "Unrecognized archival date format: {$trimmed}. Expected ISO date (YYYY-MM-DD), year (YYYY), range (circa YYYY-YYYY), or decade (YYYYs).",
        ];
    }

    // Validate against plausible historical horizon (1920 to current year + 1)
    $maxYear = (int) date('Y') + 1;
    if ($yearStart < 1920 || $yearEnd > $maxYear) {
        return [
            'valid' => false,
            'raw' => $raw,
            'is_fuzzy' => $isFuzzy,
            'year_start' => $yearStart,
            'year_end' => $yearEnd,
            'iso_date' => $isoDate,
            'decade' => null,
            'era_id' => null,
            'era_label' => null,
            'display_label' => $trimmed,
            'error' => "Year range {$yearStart}–{$yearEnd} is outside the valid AHS archival horizon (1920–{$maxYear}).",
        ];
    }

    $decadeStart = (int) floor($yearStart / 10) * 10;
    $decade = $decadeStart . 's';

    $eraId = 'centennial-21st-century';
    $eraLabel = '21st Century & Centennial Era (2000–2026)';
    foreach (AHSOBC_CENTENNIAL_ERAS as $era) {
        if ($yearStart >= $era['year_start'] && $yearStart <= $era['year_end']) {
            $eraId = $era['id'];
            $eraLabel = $era['label'];
            break;
        }
        if ($yearStart < 1926) {
            $eraId = 'founding';
            $eraLabel = 'Founding & Early Bush (1926–1949)';
            break;
        }
    }

    return [
        'valid' => true,
        'raw' => $trimmed,
        'is_fuzzy' => $isFuzzy,
        'year_start' => $yearStart,
        'year_end' => $yearEnd,
        'iso_date' => $isoDate,
        'decade' => $decade,
        'era_id' => $eraId,
        'era_label' => $eraLabel,
        'display_label' => $trimmed,
        'error' => null,
    ];
}
