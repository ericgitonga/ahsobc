<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/includes/headless_gallery.php';

$TESTS = [
    'headless_gallery_returns_items_albums_and_8_houses' => function (): void {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $payload = ahsobc_query_headless_gallery($pdo, 'sqlite', []);
        if ($payload['status'] !== 'ok' || $payload['filtered_count'] < 5) {
            throw new \RuntimeException('Expected >= 5 items from headless gallery API');
        }
        $expectedHouses = ['Livingstone', 'Aggrey', 'Wilberforce', 'Grieve', 'Arthur', 'Francis', 'Smith', 'Sellwood'];
        if ($payload['houses'] !== $expectedHouses) {
            throw new \RuntimeException('Unexpected houses in API payload: ' . implode(', ', $payload['houses']));
        }
        if (empty($payload['albums']) || count($payload['albums']) < 12) {
            throw new \RuntimeException('Expected Flickr-style albums for occasions, 8 houses, and decades');
        }

        foreach ($payload['items'] as $item) {
            if (empty($item['preview_url']) || !str_starts_with($item['preview_url'], '/assets/img/archive/')) {
                throw new \RuntimeException("Expected archive visual preview URL for {$item['slug']}, got {$item['preview_url']}");
            }
        }
    },
    'headless_gallery_filters_by_query_house_decade_and_alumni_name' => function (): void {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $byAlumnusQuery = ahsobc_query_headless_gallery($pdo, 'sqlite', ['q' => 'Kamau']);
        if ($byAlumnusQuery['filtered_count'] < 2) {
            throw new \RuntimeException('Expected search for "Kamau" to match linked photographs');
        }

        $byHouse = ahsobc_query_headless_gallery($pdo, 'sqlite', ['house' => 'Francis']);
        if ($byHouse['filtered_count'] < 1 || $byHouse['items'][0]['house'] !== 'Francis') {
            throw new \RuntimeException('Expected Francis House filter to return Francis items');
        }
    },
    'updates_per_image_metadata_and_validates_inputs' => function (): void {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        ahsobc_provision_archive($pdo, 'sqlite');

        $upd = ahsobc_update_item_metadata($pdo, 'sqlite', [
            'slug' => '1932-athletics-squad',
            'title' => '1932 Senior Athletics & Relay Squad',
            'photo_context' => 'School Days',
            'temporal_raw' => 'circa 1931-1932',
            'cohorts_raw' => '1932',
            'house' => 'Grieve',
            'spatial' => 'The Backfield, Kikuyu',
            'description' => 'Updated caption for the 1932 athletics squad.',
        ]);
        if (!$upd['valid'] || ($upd['item']['title'] ?? '') !== '1932 Senior Athletics & Relay Squad') {
            throw new \RuntimeException('Failed to update item metadata: ' . json_encode($upd['errors']));
        }

        // Reject invalid house
        $badUpd = ahsobc_update_item_metadata($pdo, 'sqlite', [
            'slug' => '1932-athletics-squad',
            'house' => 'NonExistentHouse',
        ]);
        if ($badUpd['valid']) {
            throw new \RuntimeException('Expected metadata update with invalid house to fail');
        }
    },
    'parses_iiif_xywh_face_tag_bounding_boxes_into_percentages' => function (): void {
        $regions = ahsobc_parse_face_tag_regions([
            'Row 1, Position 3 from Left: Snr. Master Elijah Kariuki (School No. #118, Class of 1932, Grieve House, IIIF xywh=420,310,180,220)',
        ]);
        if (count($regions) !== 1 || !$regions[0]['has_region'] || $regions[0]['xywh'] !== 'xywh=420,310,180,220') {
            throw new \RuntimeException('Failed to parse IIIF xywh face region: ' . json_encode($regions));
        }
    },
    'isolates_pending_moderation_face_tags_from_public_gallery' => function (): void {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        ahsobc_provision_archive($pdo, 'sqlite');

        // Simulate a pending-moderation tag in face_tags_json (Issue #17)
        $stmt = $pdo->prepare('UPDATE archive_items SET face_tags_json = :tags WHERE slug = :slug');
        $stmt->execute([
            ':tags' => json_encode([
                'Row 1, Position 3 from Left: Approved Person (School No. #118, Class of 1932, Grieve House, IIIF xywh=420,310,180,220)',
                'Row 2, Position 1 from Left: Unverified Person [Pending Moderation]',
            ]),
            ':slug' => '1932-athletics-squad',
        ]);

        $payload = ahsobc_query_headless_gallery($pdo, 'sqlite', []);
        foreach ($payload['items'] as $it) {
            if ($it['slug'] === '1932-athletics-squad') {
                foreach ($it['face_tags'] as $tag) {
                    if (str_contains($tag, '[Pending Moderation]')) {
                        throw new \RuntimeException('Unmoderated [Pending Moderation] face tag leaked to public gallery API');
                    }
                }
            }
        }
    },
];
