<?php
/**
 * Idempotent Schema, Vocabulary, Module & Resource Template Provisioner for AHSOBC Omeka S.
 *
 * Implements Phase 3.1, Phase 3.2, and Phase 4.2 of OmekaS.pdf:
 *   1. Connects to the Omeka S MySQL database (from config/database.ini or env) OR a local
 *      SQLite catalog database (.omeka-runtime/ahsobc_archive.sqlite) for zero-MySQL local preview.
 *   2. Registers and activates the 8 archival modules in exact dependency order:
 *      Common -> CustomVocab -> IiifServer -> ImageServer -> Mirador -> CSVImport -> Mapping -> Collecting.
 *   3. Seeds Custom Vocabularies:
 *      - "AHS Photo Context": School Days, Milestone Reunion, Regional Gathering, Informal Encounter
 *      - "AHS Houses & Societies": Livingstone, Aggrey, Wilberforce, Grieve, Arthur, Francis, Smith, Sellwood
 *   4. Seeds Resource Templates:
 *      - "Alumni Photograph" (dcterms:title, dcterms:type, dcterms:temporal, dcterms:relation, dcterms:subject, dcterms:spatial, dcterms:description, dcterms:isPartOf)
 *      - "Alumni Profile" (dcterms:title, dcterms:identifier, dcterms:subject, dcterms:isPartOf, dcterms:relation)
 *   5. Seeds the 4 Collecting Crowdsourcing Forms and the initial Centennial photograph & Alumni Profile records.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/public/includes/manifest_validator.php';

/**
 * Opens a PDO connection to either the configured Omeka S MySQL database (if reachable)
 * or the local SQLite database used by the local preview / CI runner.
 *
 * @return array{pdo: \PDO, driver: string, target: string}
 */
function ahsobc_open_archive_db(?string $sqlitePath = null, ?string $databaseIniPath = null): array
{
    if ($databaseIniPath !== null && is_file($databaseIniPath)) {
        $ini = parse_ini_file($databaseIniPath);
        if (
            is_array($ini)
            && !empty($ini['dbname'])
            && !empty($ini['user'])
            && isset($ini['password'])
            && $ini['password'] !== '[YourGeneratedSecretPass]'
        ) {
            $host = $ini['host'] ?? 'localhost';
            $port = (int) ($ini['port'] ?? 3306);
            $dbname = $ini['dbname'];
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $pdo = new \PDO($dsn, (string) $ini['user'], (string) $ini['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            return ['pdo' => $pdo, 'driver' => 'mysql', 'target' => $dbname];
        }
    }

    $resolvedSqlite = $sqlitePath
        ?: (getenv('AHSOBC_ARCHIVE_DB') ?: dirname(__DIR__, 2) . '/.omeka-runtime/ahsobc_archive.sqlite');
    $dir = dirname($resolvedSqlite);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $pdo = new \PDO('sqlite:' . $resolvedSqlite, null, null, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    return ['pdo' => $pdo, 'driver' => 'sqlite', 'target' => $resolvedSqlite];
}

/**
 * Provisions tables, modules, custom vocabularies, resource templates, alumni profiles,
 * and staged photographs idempotently.
 *
 * @return array<string, mixed> Summary of provisioned entities.
 */
function ahsobc_provision_archive(\PDO $pdo, string $driver = 'sqlite'): array
{
    $autoInc = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS omeka_modules (
            id VARCHAR(64) PRIMARY KEY,
            version VARCHAR(32) NOT NULL,
            is_active INT NOT NULL DEFAULT 1,
            activation_order INT NOT NULL
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS custom_vocab (
            id {$autoInc},
            label VARCHAR(190) NOT NULL UNIQUE,
            terms_text TEXT NOT NULL
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS resource_template (
            id {$autoInc},
            label VARCHAR(190) NOT NULL UNIQUE,
            resource_class VARCHAR(120) NOT NULL
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS resource_template_property (
            id {$autoInc},
            template_label VARCHAR(190) NOT NULL,
            property_term VARCHAR(120) NOT NULL,
            alternate_label VARCHAR(190) NOT NULL,
            data_type VARCHAR(120) NOT NULL,
            is_required INT NOT NULL DEFAULT 0,
            description TEXT,
            position INT NOT NULL DEFAULT 0,
            UNIQUE(template_label, property_term)
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS alumni_profiles (
            id {$autoInc},
            slug VARCHAR(120) NOT NULL UNIQUE,
            full_name VARCHAR(190) NOT NULL,
            school_number VARCHAR(32) NOT NULL,
            cohort_year INT NOT NULL,
            house VARCHAR(64) NOT NULL,
            bio_summary TEXT NOT NULL
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS archive_items (
            id {$autoInc},
            slug VARCHAR(190) NOT NULL UNIQUE,
            file_path VARCHAR(255) NOT NULL,
            title VARCHAR(255) NOT NULL,
            photo_context VARCHAR(64) NOT NULL,
            temporal_raw VARCHAR(120) NOT NULL,
            year_start INT NOT NULL,
            year_end INT NOT NULL,
            decade VARCHAR(16) NOT NULL,
            era_id VARCHAR(64) NOT NULL,
            era_label VARCHAR(190) NOT NULL,
            cohorts_raw VARCHAR(190) NOT NULL,
            cohorts_json TEXT NOT NULL,
            spatial VARCHAR(255) NOT NULL,
            house VARCHAR(64) NOT NULL,
            description TEXT NOT NULL,
            featured_alumni_json TEXT NOT NULL,
            face_tags_json TEXT NOT NULL,
            created_at VARCHAR(40) NOT NULL
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS collecting_submissions (
            id {$autoInc},
            contribution_type VARCHAR(64) NOT NULL,
            submitter_name VARCHAR(190) NOT NULL,
            submitter_email VARCHAR(190) NOT NULL,
            payload_json TEXT NOT NULL,
            dpa_consent INT NOT NULL DEFAULT 1,
            moderation_status VARCHAR(32) NOT NULL DEFAULT 'pending_review',
            created_at VARCHAR(40) NOT NULL
        );
    ");

    // 1. Seed & activate the 8 modules in strict dependency order
    $lockPath = dirname(__DIR__) . '/modules.lock.json';
    $lockData = is_file($lockPath) ? json_decode((string) file_get_contents($lockPath), true) : [];
    $modulesMeta = $lockData['modules'] ?? [];

    $stmtMod = $pdo->prepare('
        REPLACE INTO omeka_modules (id, version, is_active, activation_order)
        VALUES (:id, :version, 1, :order)
    ');
    foreach (AHSOBC_OMEKA_MODULES as $idx => $modName) {
        $ver = $modulesMeta[$modName]['version'] ?? '1.0.0';
        $stmtMod->execute([
            ':id' => $modName,
            ':version' => $ver,
            ':order' => $idx + 1,
        ]);
    }

    // 2. Seed Custom Vocabularies
    $vocabs = [
        'AHS Photo Context' => implode("\n", array_keys(AHSOBC_PHOTO_CONTEXTS)),
        'AHS Houses & Societies' => implode("\n", array_map(static fn(string $h): string => "{$h} House", AHSOBC_HOUSES)),
    ];
    $stmtVocab = $pdo->prepare('
        REPLACE INTO custom_vocab (label, terms_text)
        VALUES (:label, :terms)
    ');
    foreach ($vocabs as $vLabel => $vTerms) {
        $stmtVocab->execute([':label' => $vLabel, ':terms' => $vTerms]);
    }

    // 3. Seed Resource Templates ("Alumni Photograph" & "Alumni Profile")
    $templates = [
        AHSOBC_ALUMNI_PHOTOGRAPH_TEMPLATE,
        AHSOBC_ALUMNI_PROFILE_TEMPLATE,
    ];
    $stmtTpl = $pdo->prepare('
        REPLACE INTO resource_template (label, resource_class)
        VALUES (:label, :rclass)
    ');
    $stmtProp = $pdo->prepare('
        REPLACE INTO resource_template_property
        (template_label, property_term, alternate_label, data_type, is_required, description, position)
        VALUES (:tlabel, :term, :alabel, :dtype, :req, :desc, :pos)
    ');

    foreach ($templates as $tpl) {
        $stmtTpl->execute([
            ':label' => $tpl['label'],
            ':rclass' => $tpl['resource_class'],
        ]);
        foreach ($tpl['properties'] as $pos => $prop) {
            $stmtProp->execute([
                ':tlabel' => $tpl['label'],
                ':term' => $prop['term'],
                ':alabel' => $prop['label'],
                ':dtype' => $prop['data_type'],
                ':req' => $prop['required'] ? 1 : 0,
                ':desc' => $prop['description'],
                ':pos' => $pos + 1,
            ]);
        }
    }

    // 4. Seed Sample Alumni Profiles (for Contribution Type 4: Cohort Faceting & 40-Year Lifespans)
    $profiles = [
        [
            'slug' => 'dr-kamau-githunguri-1960',
            'full_name' => 'Dr. Joseph Kamau Githunguri',
            'school_number' => '1842',
            'cohort_year' => 1960,
            'house' => 'Francis',
            'bio_summary' => 'School Prefect (1959–1960), Chapel Choir tenor, and Class of 1960 Silver & Centennial Reunion Convener.',
        ],
        [
            'slug' => 'eng-david-mwangi-1974',
            'full_name' => 'Eng. David Njoroge Mwangi',
            'school_number' => '3410',
            'cohort_year' => 1974,
            'house' => 'Aggrey',
            'bio_summary' => 'First XV Rugby wing (1973–1974) and UK Chapter Coordinator in London.',
        ],
        [
            'slug' => 'prof-peter-onyango-1975',
            'full_name' => 'Prof. Peter Ochieng Onyango',
            'school_number' => '3588',
            'cohort_year' => 1975,
            'house' => 'Wilberforce',
            'bio_summary' => 'Debating Society President (1975) and Centennial Archives Committee member.',
        ],
        [
            'slug' => 'elijah-kariuki-1932',
            'full_name' => 'Snr. Master Elijah Kariuki',
            'school_number' => '118',
            'cohort_year' => 1932,
            'house' => 'Grieve',
            'bio_summary' => 'Pioneer Track & Field 440-yard champion and early Bush educator.',
        ],
    ];

    $stmtProf = $pdo->prepare('
        REPLACE INTO alumni_profiles (slug, full_name, school_number, cohort_year, house, bio_summary)
        VALUES (:slug, :full_name, :school_number, :cohort_year, :house, :bio_summary)
    ');
    foreach ($profiles as $prof) {
        $stmtProf->execute([
            ':slug' => $prof['slug'],
            ':full_name' => $prof['full_name'],
            ':school_number' => $prof['school_number'],
            ':cohort_year' => $prof['cohort_year'],
            ':house' => $prof['house'],
            ':bio_summary' => $prof['bio_summary'],
        ]);
    }

    // 5. Ingest the canonical sample_manifest.csv records into archive_items
    $manifestPath = dirname(__DIR__) . '/staging/sample_manifest.csv';
    $csvContent = (string) file_get_contents($manifestPath);
    $validated = ahsobc_validate_csv_manifest($csvContent, null);
    if (!$validated['valid']) {
        throw new \RuntimeException('Seed manifest validation failed: ' . implode('; ', $validated['errors']));
    }

    $alumniLinksByFile = [
        'files/staging/1932_athletics_squad.tif' => ['elijah-kariuki-1932'],
        'files/staging/1985_25th_reunion_dinner.jpg' => ['dr-kamau-githunguri-1960'],
        'files/staging/2018_casual_london_meetup.jpg' => ['eng-david-mwangi-1974', 'prof-peter-onyango-1975'],
        'files/staging/1958_chapel_choir_prefects.jpg' => ['dr-kamau-githunguri-1960'],
        'files/staging/2026_centennial_founders_day.jpg' => ['dr-kamau-githunguri-1960', 'eng-david-mwangi-1974', 'prof-peter-onyango-1975'],
    ];

    $defaultFaceTagsByFile = [
        'files/staging/1932_athletics_squad.tif' => [
            'Row 1, Position 3 from Left: Snr. Master Elijah Kariuki (School No. #118, Class of 1932, Grieve House, IIIF xywh=420,310,180,220)',
        ],
        'files/staging/1958_chapel_choir_prefects.jpg' => [
            'Row 2, Position 4 from Left: Dr. Joseph Kamau Githunguri (School No. #1842, Class of 1960, Francis House, IIIF xywh=610,280,150,190)',
        ],
        'files/staging/1985_25th_reunion_dinner.jpg' => [
            'Row 1, Position 2 from Left: Dr. Joseph Kamau Githunguri (School No. #1842, Class of 1960, Francis House, IIIF xywh=380,240,170,210)',
        ],
    ];

    $stmtItem = $pdo->prepare('
        REPLACE INTO archive_items (
            slug, file_path, title, photo_context, temporal_raw,
            year_start, year_end, decade, era_id, era_label,
            cohorts_raw, cohorts_json, spatial, house, description,
            featured_alumni_json, face_tags_json, created_at
        ) VALUES (
            :slug, :file_path, :title, :photo_context, :temporal_raw,
            :year_start, :year_end, :decade, :era_id, :era_label,
            :cohorts_raw, :cohorts_json, :spatial, :house, :description,
            :featured_alumni_json, :face_tags_json, :created_at
        )
    ');

    foreach ($validated['records'] as $rec) {
        $baseSlug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', pathinfo($rec['file_path'], PATHINFO_FILENAME)));
        $temporal = $rec['parsed_temporal'];
        $cohorts = $rec['parsed_cohorts'];
        $houseClean = ahsobc_normalize_house($rec['dcterms:isPartOf']) ?? 'Francis';
        $linkedAlumni = $alumniLinksByFile[$rec['file_path']] ?? [];
        $faceTags = $defaultFaceTagsByFile[$rec['file_path']] ?? [];

        $stmtItem->execute([
            ':slug' => $baseSlug,
            ':file_path' => $rec['file_path'],
            ':title' => $rec['dcterms:title'],
            ':photo_context' => $rec['dcterms:type'],
            ':temporal_raw' => $rec['dcterms:temporal'],
            ':year_start' => $temporal['year_start'],
            ':year_end' => $temporal['year_end'],
            ':decade' => $temporal['decade'],
            ':era_id' => $temporal['era_id'],
            ':era_label' => $temporal['era_label'],
            ':cohorts_raw' => $rec['dcterms:subject'],
            ':cohorts_json' => json_encode($cohorts['years']),
            ':spatial' => $rec['dcterms:spatial'] ?: 'Alliance High School, Kikuyu',
            ':house' => $houseClean,
            ':description' => $rec['dcterms:description'],
            ':featured_alumni_json' => json_encode($linkedAlumni),
            ':face_tags_json' => json_encode($faceTags),
            ':created_at' => gmdate('c'),
        ]);
    }

    return [
        'driver' => $driver,
        'modules_activated' => count(AHSOBC_OMEKA_MODULES),
        'custom_vocabs_seeded' => count($vocabs),
        'resource_templates_seeded' => count($templates),
        'alumni_profiles_seeded' => count($profiles),
        'archive_items_seeded' => count($validated['records']),
    ];
}

// Execute when invoked directly via CLI
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $sqliteArg = $argv[1] ?? null;
    $dbIniArg = $argv[2] ?? (dirname(__DIR__) . '/config/database.ini');
    $conn = ahsobc_open_archive_db($sqliteArg, $dbIniArg);
    $summary = ahsobc_provision_archive($conn['pdo'], $conn['driver']);
    echo "== AHSOBC Omeka S Schema & Centennial Seed Provisioned ==\n";
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
