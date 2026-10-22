<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/omeka/scripts/provision_schema.php';

$TESTS = [
    'provisions_omeka_schema_and_seed_corpus_idempotently' => function (): void {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $first = ahsobc_provision_archive($pdo, 'sqlite');
        $second = ahsobc_provision_archive($pdo, 'sqlite');

        if ($first['modules_activated'] !== 8 || $second['modules_activated'] !== 8) {
            throw new \RuntimeException('Expected 8 active Omeka S modules');
        }
        if ($second['resource_templates_seeded'] !== 2 || $second['custom_vocabs_seeded'] !== 2) {
            throw new \RuntimeException('Expected 2 resource templates and 2 custom vocabularies');
        }

        // Verify strict activation order starts with Common and ends with Collecting
        $mods = $pdo->query('SELECT id FROM omeka_modules ORDER BY activation_order ASC')->fetchAll(\PDO::FETCH_COLUMN);
        if ($mods[0] !== 'Common' || $mods[count($mods) - 1] !== 'Collecting') {
            throw new \RuntimeException('Unexpected module activation order: ' . implode(', ', $mods));
        }

        // Verify seeded items match sample_manifest.csv row count without duplicating on second run
        $itemCount = (int) $pdo->query('SELECT COUNT(*) FROM archive_items')->fetchColumn();
        if ($itemCount !== $second['archive_items_seeded'] || $itemCount < 3) {
            throw new \RuntimeException("Expected idempotent item count, got {$itemCount}");
        }
    },
];
