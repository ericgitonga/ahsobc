<?php
/**
 * Headless Omeka S Search, Album Builder, Per-Image Metadata Editor & Media Resolver
 * for the AHSOBC Flickr-Style Centennial Photo Gallery.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/omeka/scripts/provision_schema.php';

/**
 * Resolves the web-accessible visual preview URL for an archival item.
 */
function ahsobc_resolve_item_preview_url(string $slug, string $filePath): string
{
    $publicRoot = dirname(__DIR__);
    $cleanRel = '/' . ltrim($filePath, '/');
    if (str_starts_with($cleanRel, '/assets/img/archive/') && is_file($publicRoot . $cleanRel)) {
        return $cleanRel;
    }

    $basename = pathinfo($filePath, PATHINFO_FILENAME);

    $candidates = [
        "/gallery/files/large/{$basename}.jpg",
        "/assets/img/archive/class-of-1975/{$slug}.jpg",
        "/assets/img/archive/{$slug}.jpg",
        "/assets/img/archive/{$slug}.png",
        "/assets/img/archive/{$slug}.svg",
    ];

    foreach ($candidates as $relPath) {
        if (is_file($publicRoot . $relPath)) {
            return $relPath;
        }
    }

    return '/assets/img/obc-logo.jpg';
}

/**
 * Extracts structured IIIF xywh bounding boxes and labels from face-row annotation strings.
 *
 * @param list<string> $faceTags
 * @return list<array{
 *   note: string,
 *   has_region: bool,
 *   xywh: string|null,
 *   left_pct: float,
 *   top_pct: float,
 *   width_pct: float,
 *   height_pct: float
 * }>
 */
function ahsobc_parse_face_tag_regions(array $faceTags): array
{
    $parsed = [];
    foreach ($faceTags as $tagNote) {
        $noteStr = trim((string) $tagNote);
        if ($noteStr === '') {
            continue;
        }
        if (preg_match('/xywh=(\d+),(\d+),(\d+),(\d+)/', $noteStr, $m)) {
            $x = (int) $m[1];
            $y = (int) $m[2];
            $w = (int) $m[3];
            $h = (int) $m[4];
            $parsed[] = [
                'note' => $noteStr,
                'has_region' => true,
                'xywh' => "xywh={$x},{$y},{$w},{$h}",
                'left_pct' => round(min(92.0, max(2.0, ($x / 1200.0) * 100.0)), 2),
                'top_pct' => round(min(88.0, max(2.0, ($y / 825.0) * 100.0)), 2),
                'width_pct' => round(min(35.0, max(6.0, ($w / 1200.0) * 100.0)), 2),
                'height_pct' => round(min(45.0, max(8.0, ($h / 825.0) * 100.0)), 2),
            ];
        } else {
            $parsed[] = [
                'note' => $noteStr,
                'has_region' => false,
                'xywh' => null,
                'left_pct' => 0.0,
                'top_pct' => 0.0,
                'width_pct' => 0.0,
                'height_pct' => 0.0,
            ];
        }
    }
    return $parsed;
}

/**
 * Builds Flickr-style Albums (grouped by Occasion/Context, the 8 Houses in order, and Decades)
 * from the Omeka S archive catalog.
 *
 * @return list<array<string, mixed>>
 */
function ahsobc_build_albums(\PDO $pdo): array
{
    $allRows = $pdo->query('SELECT slug, file_path, photo_context, house, decade, cohorts_raw, temporal_raw FROM archive_items ORDER BY year_start ASC, id ASC')->fetchAll();
    $albums = [];

    // 1. Featured Class Cohort Albums (e.g. Class of 1975 Golden Jubilee Album)
    $featuredCohorts = [
        '1975' => 'Class of 1975 Golden Jubilee & Reunion Collection.',
        '1960' => 'Class of 1960 Silver & Centennial Reunion photographs.',
        '1974' => 'Class of 1974 Chapter & Reunion photographs.',
        '1932' => 'Pioneer Class of 1932 archival plates.',
    ];
    foreach ($featuredCohorts as $cohortYear => $cohortSubtitle) {
        $matches = array_values(array_filter(
            $allRows,
            static fn(array $r): bool => str_contains((string) $r['cohorts_raw'], (string) $cohortYear)
        ));
        if (count($matches) === 0) {
            continue;
        }
        $coverRow = $matches[0];
        $albums[] = [
            'id' => 'cohort-' . $cohortYear,
            'group' => 'Class Cohort Albums',
            'title' => "Class of {$cohortYear}",
            'subtitle' => $cohortSubtitle,
            'filter_key' => 'cohort',
            'filter_value' => (string) $cohortYear,
            'url' => '/gallery/?cohort=' . rawurlencode((string) $cohortYear),
            'count' => count($matches),
            'cover_url' => ahsobc_resolve_item_preview_url($coverRow['slug'], $coverRow['file_path']),
        ];
    }

    // 2. Occasion / Lifecycle Context Albums
    foreach (AHSOBC_PHOTO_CONTEXTS as $ctxLabel => $ctxDesc) {
        $matches = array_values(array_filter($allRows, static fn(array $r): bool => $r['photo_context'] === $ctxLabel));
        $coverRow = $matches[0] ?? ($allRows[0] ?? null);
        $albums[] = [
            'id' => 'context-' . strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $ctxLabel)),
            'group' => 'Occasions & Life Stages',
            'title' => $ctxLabel,
            'subtitle' => $ctxDesc,
            'filter_key' => 'context',
            'filter_value' => $ctxLabel,
            'url' => '/gallery/?context=' . rawurlencode($ctxLabel),
            'count' => count($matches),
            'cover_url' => $coverRow ? ahsobc_resolve_item_preview_url($coverRow['slug'], $coverRow['file_path']) : '/assets/img/obc-logo.jpg',
        ];
    }

    // 2. The 8 AHS Houses in Institutional Order
    foreach (AHSOBC_HOUSES as $houseName) {
        $matches = array_values(array_filter($allRows, static fn(array $r): bool => $r['house'] === $houseName));
        $coverRow = $matches[0] ?? ($allRows[0] ?? null);
        $albums[] = [
            'id' => 'house-' . strtolower($houseName),
            'group' => 'AHS Houses (Livingstone → Sellwood)',
            'title' => $houseName . ' House',
            'subtitle' => "Photographs and Old Boys from {$houseName} House.",
            'filter_key' => 'house',
            'filter_value' => $houseName,
            'url' => '/gallery/?house=' . rawurlencode($houseName),
            'count' => count($matches),
            'cover_url' => $coverRow ? ahsobc_resolve_item_preview_url($coverRow['slug'], $coverRow['file_path']) : '/assets/img/obc-logo.jpg',
        ];
    }

    // 3. Decade Albums (for decades that have items, plus key Centennial decades)
    $decades = ['1920s', '1930s', '1940s', '1950s', '1960s', '1970s', '1980s', '1990s', '2000s', '2010s', '2020s'];
    foreach ($decades as $dec) {
        $matches = array_values(array_filter($allRows, static fn(array $r): bool => $r['decade'] === $dec));
        if (count($matches) === 0 && !in_array($dec, ['1930s', '1950s', '1980s', '2010s', '2020s'], true)) {
            continue;
        }
        $coverRow = $matches[0] ?? ($allRows[0] ?? null);
        $albums[] = [
            'id' => 'decade-' . $dec,
            'group' => 'Centennial Decades (1926–2026)',
            'title' => $dec,
            'subtitle' => "Archival photographs from the {$dec}.",
            'filter_key' => 'decade',
            'filter_value' => $dec,
            'url' => '/gallery/?decade=' . rawurlencode($dec),
            'count' => count($matches),
            'cover_url' => $coverRow ? ahsobc_resolve_item_preview_url($coverRow['slug'], $coverRow['file_path']) : '/assets/img/obc-logo.jpg',
        ];
    }

    return $albums;
}

/**
 * Validates and updates an archival photograph's metadata in the Omeka S database.
 *
 * @param array<string, mixed> $input
 * @return array{valid: bool, item: array<string, mixed>|null, errors: list<string>}
 */
function ahsobc_update_item_metadata(\PDO $pdo, string $driver, array $input): array
{
    $errors = [];
    $slug = trim((string) ($input['slug'] ?? ''));
    if ($slug === '') {
        return ['valid' => false, 'item' => null, 'errors' => ['Missing photograph slug.']];
    }

    $stmtFind = $pdo->prepare('SELECT * FROM archive_items WHERE slug = :slug LIMIT 1');
    $stmtFind->execute([':slug' => $slug]);
    $existing = $stmtFind->fetch();
    if (!$existing) {
        return ['valid' => false, 'item' => null, 'errors' => ["Photograph '{$slug}' not found."]];
    }

    $title = trim((string) ($input['title'] ?? $existing['title']));
    if (mb_strlen($title) < 3 || mb_strlen($title) > 180) {
        $errors[] = 'Title must be between 3 and 180 characters.';
    } elseif (ahsobc_has_csv_injection($title)) {
        $errors[] = 'Title cannot begin with a spreadsheet formula character.';
    }

    $photoContext = trim((string) ($input['photo_context'] ?? $existing['photo_context']));
    if (!array_key_exists($photoContext, AHSOBC_PHOTO_CONTEXTS)) {
        $errors[] = 'Album / Photo Context must be one of: ' . implode(', ', array_keys(AHSOBC_PHOTO_CONTEXTS)) . '.';
    }

    $temporalRaw = trim((string) ($input['temporal_raw'] ?? $existing['temporal_raw']));
    $temporal = ahsobc_parse_temporal($temporalRaw);
    if (!$temporal['valid']) {
        $errors[] = $temporal['error'] ?? 'Invalid date format.';
    }

    $cohortsRaw = trim((string) ($input['cohorts_raw'] ?? $existing['cohorts_raw']));
    $cohorts = ahsobc_parse_cohorts($cohortsRaw);
    if (!$cohorts['valid']) {
        $errors[] = $cohorts['error'] ?? 'Invalid class cohort year(s).';
    }

    $rawHouse = trim((string) ($input['house'] ?? $existing['house']));
    $houseClean = ahsobc_normalize_house($rawHouse);
    if ($houseClean === null) {
        $errors[] = 'House must be one of the 8 AHS Houses: ' . implode(', ', AHSOBC_HOUSES) . '.';
    }

    $spatial = trim((string) ($input['spatial'] ?? $existing['spatial']));
    if ($spatial === '') {
        $spatial = 'Alliance High School, Kikuyu';
    } elseif (ahsobc_has_csv_injection($spatial)) {
        $errors[] = 'Location cannot begin with a spreadsheet formula character.';
    }

    $description = trim((string) ($input['description'] ?? $existing['description']));
    if (mb_strlen($description) < 3) {
        $errors[] = 'Description / caption must be at least 3 characters.';
    } elseif (ahsobc_has_csv_injection($description)) {
        $errors[] = 'Description cannot begin with a spreadsheet formula character.';
    }

    if ($errors) {
        return ['valid' => false, 'item' => null, 'errors' => $errors];
    }

    $stmtUpd = $pdo->prepare('
        UPDATE archive_items
        SET title = :title,
            photo_context = :photo_context,
            temporal_raw = :temporal_raw,
            year_start = :year_start,
            year_end = :year_end,
            decade = :decade,
            era_id = :era_id,
            era_label = :era_label,
            cohorts_raw = :cohorts_raw,
            cohorts_json = :cohorts_json,
            spatial = :spatial,
            house = :house,
            description = :description
        WHERE slug = :slug
    ');
    $stmtUpd->execute([
        ':title' => $title,
        ':photo_context' => $photoContext,
        ':temporal_raw' => $temporalRaw,
        ':year_start' => $temporal['year_start'],
        ':year_end' => $temporal['year_end'],
        ':decade' => $temporal['decade'],
        ':era_id' => $temporal['era_id'],
        ':era_label' => $temporal['era_label'],
        ':cohorts_raw' => $cohortsRaw,
        ':cohorts_json' => json_encode($cohorts['years']),
        ':spatial' => $spatial,
        ':house' => $houseClean,
        ':description' => $description,
        ':slug' => $slug,
    ]);

    $refreshed = ahsobc_query_headless_gallery($pdo, $driver, []);
    $updatedItem = null;
    foreach ($refreshed['items'] as $it) {
        if ($it['slug'] === $slug) {
            $updatedItem = $it;
            break;
        }
    }

    return [
        'valid' => true,
        'item' => $updatedItem,
        'errors' => [],
    ];
}

/**
 * Queries items, albums, and alumni profiles for the Flickr-style gallery UI and `/api/gallery.php`.
 *
 * @param array<string, mixed> $query
 * @return array<string, mixed>
 */
function ahsobc_query_headless_gallery(\PDO $pdo, string $driver, array $query = []): array
{
    try {
        $countRow = $pdo->query('SELECT COUNT(*) AS c FROM archive_items')->fetch();
        $currentCount = (int) ($countRow['c'] ?? 0);
        $hasPlaceholders = (int) $pdo->query("SELECT COUNT(*) FROM archive_items WHERE slug = '1932-athletics-squad'")->fetchColumn() > 0;
        if ($currentCount === 0 || $hasPlaceholders) {
            ahsobc_provision_archive($pdo, $driver);
        }
    } catch (\PDOException $e) {
        ahsobc_provision_archive($pdo, $driver);
    }

    $filterDecade = trim((string) ($query['decade'] ?? ''));
    $filterContext = trim((string) ($query['context'] ?? ''));
    $filterHouse = trim((string) ($query['house'] ?? ''));
    $filterCohort = trim((string) ($query['cohort'] ?? ''));
    $filterSearch = trim((string) ($query['q'] ?? ''));
    $filterAlumnus = trim((string) ($query['alumnus'] ?? ''));

    $allProfiles = $pdo->query('SELECT * FROM alumni_profiles ORDER BY cohort_year ASC')->fetchAll();
    $profilesBySlug = [];
    foreach ($allProfiles as $prof) {
        $profilesBySlug[$prof['slug']] = $prof;
    }

    $where = [];
    $params = [];

    if ($filterDecade !== '') {
        $where[] = 'decade = :decade';
        $params[':decade'] = $filterDecade;
    }
    if ($filterContext !== '') {
        $where[] = 'photo_context = :context';
        $params[':context'] = $filterContext;
    }
    if ($filterHouse !== '') {
        $where[] = 'house = :house';
        $params[':house'] = $filterHouse;
    }
    if ($filterCohort !== '') {
        $escapedCohort = addcslashes($filterCohort, '%_\\');
        $where[] = "cohorts_raw LIKE :cohort ESCAPE '\\'";
        $params[':cohort'] = '%' . $escapedCohort . '%';
    }
    if ($filterAlumnus !== '') {
        $escapedAlumnus = addcslashes($filterAlumnus, '%_\\');
        $where[] = "featured_alumni_json LIKE :alumnus ESCAPE '\\'";
        $params[':alumnus'] = '%' . $escapedAlumnus . '%';
    }
    if ($filterSearch !== '') {
        $matchingAlumniSlugs = [];
        foreach ($allProfiles as $prof) {
            if (
                stripos($prof['full_name'], $filterSearch) !== false
                || stripos($prof['school_number'], ltrim($filterSearch, '#')) !== false
            ) {
                $matchingAlumniSlugs[] = $prof['slug'];
            }
        }

        $escapedQ = addcslashes($filterSearch, '%_\\');
        $searchClauses = [
            "title LIKE :q ESCAPE '\\'",
            "description LIKE :q ESCAPE '\\'",
            "spatial LIKE :q ESCAPE '\\'",
            "cohorts_raw LIKE :q ESCAPE '\\'",
            "house LIKE :q ESCAPE '\\'",
            "photo_context LIKE :q ESCAPE '\\'",
            "temporal_raw LIKE :q ESCAPE '\\'",
            "face_tags_json LIKE :q ESCAPE '\\'",
        ];
        $params[':q'] = '%' . $escapedQ . '%';

        foreach ($matchingAlumniSlugs as $idx => $aSlug) {
            $paramKey = ":aslug_{$idx}";
            $searchClauses[] = "featured_alumni_json LIKE {$paramKey} ESCAPE '\\'";
            $params[$paramKey] = '%' . addcslashes($aSlug, '%_\\') . '%';
        }

        $where[] = '(' . implode(' OR ', $searchClauses) . ')';
    }

    $sql = 'SELECT * FROM archive_items';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY year_start ASC, id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $items = [];
    foreach ($rows as $row) {
        $linkedSlugs = json_decode((string) $row['featured_alumni_json'], true) ?: [];
        $featuredAlumni = [];
        foreach ($linkedSlugs as $aSlug) {
            if (isset($profilesBySlug[$aSlug])) {
                $p = $profilesBySlug[$aSlug];
                $featuredAlumni[] = [
                    'slug' => $p['slug'],
                    'full_name' => $p['full_name'],
                    'school_number' => $p['school_number'],
                    'cohort_year' => (int) $p['cohort_year'],
                    'house' => $p['house'],
                ];
            }
        }

        $rawFaceTags = json_decode((string) $row['face_tags_json'], true) ?: [];
        // Isolate unmoderated [Pending Moderation] tags from public responses (F-02 / Issue #17)
        $approvedFaceTags = array_values(array_filter(
            $rawFaceTags,
            static fn($tag): bool => is_string($tag) && !str_contains($tag, '[Pending Moderation]')
        ));
        $previewUrl = ahsobc_resolve_item_preview_url($row['slug'], $row['file_path']);

        $items[] = [
            'id' => (int) $row['id'],
            'slug' => $row['slug'],
            'title' => $row['title'],
            'photo_context' => $row['photo_context'],
            'temporal_raw' => $row['temporal_raw'],
            'year_start' => (int) $row['year_start'],
            'year_end' => (int) $row['year_end'],
            'decade' => $row['decade'],
            'era_id' => $row['era_id'],
            'era_label' => $row['era_label'],
            'cohorts_raw' => $row['cohorts_raw'],
            'cohorts' => json_decode((string) $row['cohorts_json'], true) ?: [],
            'spatial' => $row['spatial'],
            'house' => $row['house'],
            'description' => $row['description'],
            'file_path' => $row['file_path'],
            'preview_url' => $previewUrl,
            'iiif_manifest_url' => '/gallery/?iiif_manifest=' . rawurlencode($row['slug']),
            'item_url' => '/gallery/?item=' . rawurlencode($row['slug']) . '#mirador-viewer',
            'featured_alumni' => $featuredAlumni,
            'face_tags' => $approvedFaceTags,
            'face_regions' => ahsobc_parse_face_tag_regions($approvedFaceTags),
            'face_tag_count' => count($approvedFaceTags),
        ];
    }

    $totalCatalogCount = (int) $pdo->query('SELECT COUNT(*) FROM archive_items')->fetchColumn();

    return [
        'status' => 'ok',
        'engine' => 'Omeka S v4.1.1 Headless Archive API',
        'total_catalog_items' => $totalCatalogCount,
        'filtered_count' => count($items),
        'filters' => [
            'decade' => $filterDecade,
            'context' => $filterContext,
            'house' => $filterHouse,
            'cohort' => $filterCohort,
            'q' => $filterSearch,
            'alumnus' => $filterAlumnus,
        ],
        'houses' => AHSOBC_HOUSES,
        'contexts' => array_keys(AHSOBC_PHOTO_CONTEXTS),
        'decades' => ['1920s', '1930s', '1940s', '1950s', '1960s', '1970s', '1980s', '1990s', '2000s', '2010s', '2020s'],
        'albums' => ahsobc_build_albums($pdo),
        'items' => $items,
        'alumni_profiles' => $allProfiles,
    ];
}
