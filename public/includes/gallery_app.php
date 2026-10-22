<?php
/**
 * AHSOBC Omeka S Centennial Gallery Runtime Controller (/gallery/).
 *
 * Connects to the provisioned Omeka S archive catalog (MySQL egimba_ahs on Angryhosting
 * or SQLite .omeka-runtime/ahsobc_archive.sqlite in local preview / CI) and renders:
 *   - Interactive 1926–2026 Centennial Timeline Scrubber (by Decade & Era)
 *   - Expanded Dublin Core Lifecycle facets (Photo Context, Class Cohort, AHS House, Search)
 *   - IIIF Presentation API v3 Manifest endpoint (?iiif_manifest=<slug>) & Mirador Deep-Zoom Inspector
 *   - Contribution Type 4: Cohort Faceting & 40-Year Alumni Profile Lifespan side-by-side view
 *   - Contribution Types 1–3: Moderated Crowdsourced Collecting Forms + Historic Face Identification
 */

declare(strict_types=1);

require_once __DIR__ . '/crowdsourcing_guard.php';
require_once dirname(__DIR__, 2) . '/omeka/scripts/provision_schema.php';

$conn = ahsobc_open_archive_db(
    getenv('AHSOBC_ARCHIVE_DB') ?: null,
    dirname(__DIR__, 2) . '/omeka/config/database.ini'
);
$pdo = $conn['pdo'];

// Auto-provision schema & seed corpus if tables do not exist yet
try {
    $countRow = $pdo->query('SELECT COUNT(*) AS c FROM archive_items')->fetch();
    if ((int) ($countRow['c'] ?? 0) === 0) {
        ahsobc_provision_archive($pdo, $conn['driver']);
    }
} catch (\PDOException $e) {
    ahsobc_provision_archive($pdo, $conn['driver']);
}

// 1. Serve IIIF Presentation API v3 Manifest if requested (?iiif_manifest=<slug>)
if (!empty($_GET['iiif_manifest'])) {
    $manifestSlug = trim((string) $_GET['iiif_manifest']);
    $stmt = $pdo->prepare('SELECT * FROM archive_items WHERE slug = :slug LIMIT 1');
    $stmt->execute([':slug' => $manifestSlug]);
    $item = $stmt->fetch();

    header('Content-Type: application/ld+json; profile="http://iiif.io/api/presentation/3/context.json"');
    if (!$item) {
        http_response_code(404);
        echo json_encode(['error' => 'IIIF manifest not found for item: ' . $manifestSlug]);
        exit;
    }

    $baseUrl = AHSOBC_DOMAIN . '/gallery';
    $manifest = [
        '@context' => 'http://iiif.io/api/presentation/3/context.json',
        'id' => "{$baseUrl}/?iiif_manifest=" . rawurlencode($item['slug']),
        'type' => 'Manifest',
        'label' => ['en' => [$item['title']]],
        'summary' => ['en' => [$item['description']]],
        'metadata' => [
            ['label' => ['en' => ['Photo Context / Category (dcterms:type)']], 'value' => ['en' => [$item['photo_context']]]],
            ['label' => ['en' => ['Circa / Event Date (dcterms:temporal)']], 'value' => ['en' => [$item['temporal_raw']]]],
            ['label' => ['en' => ['Class Cohort(s) (dcterms:subject)']], 'value' => ['en' => [$item['cohorts_raw']]]],
            ['label' => ['en' => ['Location / Venue (dcterms:spatial)']], 'value' => ['en' => [$item['spatial']]]],
            ['label' => ['en' => ['House / Society (dcterms:isPartOf)']], 'value' => ['en' => [$item['house'] . ' House']]],
        ],
        'items' => [
            [
                'id' => "{$baseUrl}/canvas/" . rawurlencode($item['slug']),
                'type' => 'Canvas',
                'width' => 3200,
                'height' => 2200,
                'items' => [
                    [
                        'id' => "{$baseUrl}/page/" . rawurlencode($item['slug']),
                        'type' => 'AnnotationPage',
                        'items' => [
                            [
                                'id' => "{$baseUrl}/annotation/" . rawurlencode($item['slug']),
                                'type' => 'Annotation',
                                'motivation' => 'painting',
                                'body' => [
                                    'id' => AHSOBC_DOMAIN . '/' . ltrim($item['file_path'], '/'),
                                    'type' => 'Image',
                                    'format' => 'image/jpeg',
                                    'width' => 3200,
                                    'height' => 2200,
                                ],
                                'target' => "{$baseUrl}/canvas/" . rawurlencode($item['slug']),
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
    echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}

// 2. Handle POST Crowdsourced Collecting Submissions (Contribution Types 1, 2, 3, 4)
$flashSuccess = null;
$flashErrors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['ahsobc_collecting_submit'])) {
    $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $rlFile = dirname(__DIR__, 2) . '/.omeka-runtime/collecting_ratelimit.json';
    $maxReq = (int) (getenv('AHSOBC_RATE_LIMIT_MAX') ?: 15);

    if (!ahsobc_check_rate_limit($clientIp, $rlFile, $maxReq, 3600)) {
        http_response_code(429);
        $flashErrors[] = 'Rate limit exceeded: too many submissions from your connection. Please try again later.';
    } else {
        $validation = ahsobc_validate_crowdsourced_submission($_POST);
        if (!$validation['valid']) {
            http_response_code(422);
            $flashErrors = $validation['errors'];
        } else {
            $sanitized = $validation['sanitized'];
            $stmtInsert = $pdo->prepare('
                INSERT INTO collecting_submissions
                (contribution_type, submitter_name, submitter_email, payload_json, dpa_consent, moderation_status, created_at)
                VALUES (:ctype, :sname, :semail, :payload, :dpa, :status, :created)
            ');
            $stmtInsert->execute([
                ':ctype' => $sanitized['contribution_type'],
                ':sname' => $sanitized['submitter_name'],
                ':semail' => $sanitized['submitter_email'],
                ':payload' => json_encode($sanitized, JSON_UNESCAPED_SLASHES),
                ':dpa' => 1,
                ':status' => 'pending_review',
                ':created' => gmdate('c'),
            ]);

            // If Contribution Type 3 (Face ID), also append pending face identification to item preview
            if ($sanitized['contribution_type'] === 'face_id' && !empty($sanitized['target_item_id'])) {
                $stmtItem = $pdo->prepare('SELECT face_tags_json FROM archive_items WHERE slug = :slug');
                $stmtItem->execute([':slug' => $sanitized['target_item_id']]);
                $existingRow = $stmtItem->fetch();
                if ($existingRow) {
                    $tags = json_decode((string) $existingRow['face_tags_json'], true) ?: [];
                    $tags[] = $sanitized['formatted_annotation'] . ' [Pending Archivist Moderation]';
                    $stmtUpd = $pdo->prepare('UPDATE archive_items SET face_tags_json = :tags WHERE slug = :slug');
                    $stmtUpd->execute([
                        ':tags' => json_encode($tags, JSON_UNESCAPED_SLASHES),
                        ':slug' => $sanitized['target_item_id'],
                    ]);
                }
            }

            $flashSuccess = 'Thank you, ' . htmlspecialchars($sanitized['submitter_name'], ENT_QUOTES, 'UTF-8')
                . '! Your archival contribution has been recorded in the Omeka S Collecting queue for archivist verification.';
        }
    }
}

// 3. Query filters (Decade, Context, House, Cohort, Search, Selected Item, Selected Alumni Profile)
$filterDecade = trim((string) ($_GET['decade'] ?? ''));
$filterContext = trim((string) ($_GET['context'] ?? ''));
$filterHouse = trim((string) ($_GET['house'] ?? ''));
$filterCohort = trim((string) ($_GET['cohort'] ?? ''));
$filterSearch = trim((string) ($_GET['q'] ?? ''));
$selectedItemSlug = trim((string) ($_GET['item'] ?? ''));
$selectedProfileSlug = trim((string) ($_GET['alumnus'] ?? ''));

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
    $where[] = 'cohorts_raw LIKE :cohort';
    $params[':cohort'] = '%' . $filterCohort . '%';
}
if ($filterSearch !== '') {
    $where[] = '(title LIKE :q OR description LIKE :q OR spatial LIKE :q OR cohorts_raw LIKE :q)';
    $params[':q'] = '%' . $filterSearch . '%';
}

$sql = 'SELECT * FROM archive_items';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY year_start ASC, id ASC';

$stmtItems = $pdo->prepare($sql);
$stmtItems->execute($params);
$items = $stmtItems->fetchAll();

$allItems = $pdo->query('SELECT slug, title, temporal_raw FROM archive_items ORDER BY year_start ASC')->fetchAll();
$allProfiles = $pdo->query('SELECT * FROM alumni_profiles ORDER BY cohort_year ASC')->fetchAll();
$profilesBySlug = [];
foreach ($allProfiles as $prof) {
    $profilesBySlug[$prof['slug']] = $prof;
}

$activeItem = null;
if ($selectedItemSlug !== '') {
    $stmtOne = $pdo->prepare('SELECT * FROM archive_items WHERE slug = :slug LIMIT 1');
    $stmtOne->execute([':slug' => $selectedItemSlug]);
    $activeItem = $stmtOne->fetch() ?: null;
}

$activeProfile = null;
$profileLifespanItems = [];
if ($selectedProfileSlug !== '' && isset($profilesBySlug[$selectedProfileSlug])) {
    $activeProfile = $profilesBySlug[$selectedProfileSlug];
    $stmtLife = $pdo->prepare('SELECT * FROM archive_items WHERE featured_alumni_json LIKE :pslug ORDER BY year_start ASC');
    $stmtLife->execute([':pslug' => '%' . $selectedProfileSlug . '%']);
    $profileLifespanItems = $stmtLife->fetchAll();
}

$decades = ['1920s', '1930s', '1940s', '1950s', '1960s', '1970s', '1980s', '1990s', '2000s', '2010s', '2020s'];
$activeModules = $pdo->query('SELECT id, version FROM omeka_modules WHERE is_active = 1 ORDER BY activation_order ASC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Centennial Photo Gallery · <?= htmlspecialchars(AHSOBC_SITE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/css/gallery.css">
</head>
<body>
    <header class="ahsobc-topbar">
        <a class="ahsobc-brand" href="/gallery/">
            <img src="/assets/img/obc-logo.jpg" alt="Alliance High School Old Boys Club">
            <div>
                <div class="ahsobc-brand-title">Alliance High School Old Boys Club</div>
                <div class="ahsobc-brand-sub">Strong to Serve · Omeka S Centennial Photo Repository (1926–2026)</div>
            </div>
        </a>
        <nav class="ahsobc-nav" aria-label="Primary Navigation">
            <a href="/">Main Page</a>
            <a href="/gallery/" class="<?= ($selectedItemSlug === '' && $selectedProfileSlug === '') ? 'active' : '' ?>">Centennial Archive</a>
            <a href="#lifespans">Cohort Lifespans</a>
            <a href="#contribute">Contribute &amp; Identify Faces</a>
        </nav>
    </header>

    <main class="ahsobc-container">
        <?php if ($flashSuccess !== null): ?>
            <div class="alert-success" role="status">
                <?= $flashSuccess ?>
            </div>
        <?php endif; ?>

        <?php if ($flashErrors): ?>
            <div class="alert-error" role="alert">
                <strong>Please correct the following before submitting:</strong>
                <ul style="margin-top:0.4rem;padding-left:1.25rem;">
                    <?php foreach ($flashErrors as $err): ?>
                        <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Hero & 1926-2026 Centennial Timeline Scrubber -->
        <section class="centennial-hero" aria-labelledby="archive-heading">
            <h1 id="archive-heading">Centennial Alumni Photographic Repository (1926–2026)</h1>
            <p>
                Powered by Omeka S v4.1.1 with IIIF Deep-Zoom, Mirador panoramic inspection, and Dublin Core lifecycle cataloging across
                <strong>School Days</strong>, <strong>Milestone Reunions</strong>, <strong>Regional Gatherings</strong>, and <strong>Informal Encounters</strong>.
            </p>

            <div class="timeline-scrubber" role="region" aria-label="Centennial Decade Timeline Scrubber">
                <div class="timeline-scrubber-label">100-Year Centennial Timeline Scrubber (1926–2026)</div>
                <div class="decade-pills">
                    <a class="decade-pill <?= $filterDecade === '' ? 'active' : '' ?>" href="/gallery/">All Decades</a>
                    <?php foreach ($decades as $dec): ?>
                        <a class="decade-pill <?= $filterDecade === $dec ? 'active' : '' ?>"
                           href="/gallery/?decade=<?= rawurlencode($dec) ?>">
                            <?= htmlspecialchars($dec, ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- Multi-Facet Filter Bar -->
        <form class="filter-bar" method="get" action="/gallery/" aria-label="Filter Centennial Photographs">
            <div>
                <label for="filter-context">Photo Context (dcterms:type)</label>
                <select id="filter-context" name="context">
                    <option value="">All Lifecycle Contexts</option>
                    <?php foreach (AHSOBC_PHOTO_CONTEXTS as $ctxKey => $ctxDesc): ?>
                        <option value="<?= htmlspecialchars($ctxKey, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $filterContext === $ctxKey ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ctxKey, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="filter-house">AHS House</label>
                <select id="filter-house" name="house">
                    <option value="">All 8 Houses</option>
                    <?php foreach (AHSOBC_HOUSES as $houseName): ?>
                        <option value="<?= htmlspecialchars($houseName, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $filterHouse === $houseName ? 'selected' : '' ?>>
                            <?= htmlspecialchars($houseName, ENT_QUOTES, 'UTF-8') ?> House
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="filter-cohort">Class Cohort (dcterms:subject)</label>
                <input id="filter-cohort" type="text" name="cohort"
                       placeholder="e.g. 1960 or 1974"
                       value="<?= htmlspecialchars($filterCohort, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div>
                <label for="filter-q">Keyword / Venue Search</label>
                <input id="filter-q" type="text" name="q"
                       placeholder="Chapel, London, Athletics..."
                       value="<?= htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div style="display:flex;align-items:flex-end;gap:0.5rem;">
                <button type="submit" class="btn-primary" style="width:100%;">Apply Filters</button>
            </div>
        </form>

        <!-- Active Mirador IIIF Deep-Zoom Inspector Modal/Section when an item is selected -->
        <?php if ($activeItem !== null): ?>
            <?php
            $itemFaceTags = json_decode((string) $activeItem['face_tags_json'], true) ?: [];
            $itemAlumniSlugs = json_decode((string) $activeItem['featured_alumni_json'], true) ?: [];
            ?>
            <section class="mirador-frame" id="mirador-viewer" aria-label="Mirador IIIF Deep-Zoom Inspector">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;margin-bottom:1rem;">
                    <div>
                        <span class="badge badge-context"><?= htmlspecialchars($activeItem['photo_context'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="badge"><?= htmlspecialchars($activeItem['temporal_raw'], ENT_QUOTES, 'UTF-8') ?></span>
                        <h2 style="color:#ffffff;margin-top:0.5rem;"><?= htmlspecialchars($activeItem['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                    </div>
                    <div style="display:flex;gap:0.5rem;">
                        <a class="btn-primary" href="/gallery/?iiif_manifest=<?= rawurlencode($activeItem['slug']) ?>">
                            IIIF v3 Manifest (JSON-LD)
                        </a>
                        <a class="btn-primary" style="background:#334e68;" href="/gallery/">Close Inspector</a>
                    </div>
                </div>

                <p style="margin-bottom:1rem;color:#cbd5e1;">
                    <?= htmlspecialchars($activeItem['description'], ENT_QUOTES, 'UTF-8') ?>
                </p>

                <div style="background:#102a43;padding:1rem;border-radius:8px;margin-bottom:1rem;">
                    <strong style="color:#7cc4fa;font-size:0.85rem;text-transform:uppercase;">
                        Mirador IIIF Deep-Zoom Face-Row Annotations (<?= count($itemFaceTags) ?> Recorded)
                    </strong>
                    <?php if ($itemFaceTags): ?>
                        <ul style="margin-top:0.5rem;padding-left:1.25rem;color:#f0f4f8;">
                            <?php foreach ($itemFaceTags as $tagNote): ?>
                                <li><?= htmlspecialchars((string) $tagNote, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p style="margin-top:0.35rem;color:#9fb3c8;">No row identifications recorded yet. Use Contribution Type 3 below to suggest classmates.</p>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Archival Photographs Grid -->
        <section aria-label="Archival Photographs">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <h2 style="color:var(--ahsobc-navy);font-size:1.25rem;">
                    Showing <?= count($items) ?> Archival Photograph<?= count($items) === 1 ? '' : 's' ?>
                </h2>
                <span style="font-size:0.82rem;color:var(--ahsobc-muted);">
                    Template: <code>Alumni Photograph</code> (Dublin Core Extended)
                </span>
            </div>

            <div class="photo-grid">
                <?php foreach ($items as $item): ?>
                    <?php
                    $linkedSlugs = json_decode((string) $item['featured_alumni_json'], true) ?: [];
                    $faceTags = json_decode((string) $item['face_tags_json'], true) ?: [];
                    ?>
                    <article class="photo-card" data-decade="<?= htmlspecialchars($item['decade'], ENT_QUOTES, 'UTF-8') ?>"
                             data-context="<?= htmlspecialchars($item['photo_context'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="photo-card-preview">
                            <div class="badge-row">
                                <span class="badge badge-context"><?= htmlspecialchars($item['photo_context'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="badge"><?= htmlspecialchars($item['decade'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="badge"><?= htmlspecialchars($item['house'], ENT_QUOTES, 'UTF-8') ?> House</span>
                            </div>
                            <div style="font-size:0.8rem;color:#bae6fd;margin-top:1.5rem;">
                                <code><?= htmlspecialchars($item['file_path'], ENT_QUOTES, 'UTF-8') ?></code>
                            </div>
                        </div>

                        <div class="photo-card-body">
                            <h3 class="photo-card-title">
                                <a href="/gallery/?item=<?= rawurlencode($item['slug']) ?>#mirador-viewer">
                                    <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </h3>
                            <p style="font-size:0.88rem;color:var(--ahsobc-muted);">
                                <?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?>
                            </p>

                            <dl class="meta-dl">
                                <dt>Circa / Date</dt>
                                <dd><?= htmlspecialchars($item['temporal_raw'], ENT_QUOTES, 'UTF-8') ?></dd>
                                <dt>Class Cohort(s)</dt>
                                <dd><?= htmlspecialchars($item['cohorts_raw'], ENT_QUOTES, 'UTF-8') ?></dd>
                                <dt>Location / Venue</dt>
                                <dd><?= htmlspecialchars($item['spatial'], ENT_QUOTES, 'UTF-8') ?></dd>
                                <dt>Featured Alumni</dt>
                                <dd>
                                    <?php foreach ($linkedSlugs as $aSlug): ?>
                                        <?php if (isset($profilesBySlug[$aSlug])): ?>
                                            <a href="/gallery/?alumnus=<?= rawurlencode($aSlug) ?>#lifespans"
                                               style="color:var(--ahsobc-blue);font-weight:600;text-decoration:none;margin-right:0.4rem;">
                                                <?= htmlspecialchars($profilesBySlug[$aSlug]['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                                (#<?= htmlspecialchars($profilesBySlug[$aSlug]['school_number'], ENT_QUOTES, 'UTF-8') ?>)
                                            </a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </dd>
                            </dl>

                            <div style="margin-top:auto;padding-top:0.75rem;display:flex;gap:0.5rem;flex-wrap:wrap;">
                                <a class="btn-primary" href="/gallery/?item=<?= rawurlencode($item['slug']) ?>#mirador-viewer">
                                    Inspect in Mirador Zoom (<?= count($faceTags) ?> Tags)
                                </a>
                                <a class="decade-pill" href="/gallery/?iiif_manifest=<?= rawurlencode($item['slug']) ?>">
                                    IIIF Manifest
                                </a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Contribution Type 4: Cohort Faceting & 40-Year Lifespans -->
        <section class="section-card" id="lifespans" aria-labelledby="lifespans-heading">
            <h2 id="lifespans-heading">Cohort Faceting &amp; Alumni Lifespans (School Days vs. Reunions 40+ Years Later)</h2>
            <p style="color:var(--ahsobc-muted);margin-bottom:1rem;">
                Select an Old Boy profile (<code>dcterms:relation</code>) to compare their school-day campus portraits side-by-side with milestone reunions and regional chapter encounters decades later.
            </p>

            <div class="decade-pills" style="margin-bottom:1.25rem;">
                <?php foreach ($allProfiles as $prof): ?>
                    <a class="decade-pill <?= $selectedProfileSlug === $prof['slug'] ? 'active' : '' ?>"
                       href="/gallery/?alumnus=<?= rawurlencode($prof['slug']) ?>#lifespans">
                        <?= htmlspecialchars($prof['full_name'], ENT_QUOTES, 'UTF-8') ?>
                        · #<?= htmlspecialchars($prof['school_number'], ENT_QUOTES, 'UTF-8') ?>
                        (Class of <?= (int) $prof['cohort_year'] ?>, <?= htmlspecialchars($prof['house'], ENT_QUOTES, 'UTF-8') ?>)
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($activeProfile !== null): ?>
                <div style="background:#f0f7ff;border:1px solid #b3d4fc;border-radius:10px;padding:1.25rem;">
                    <h3 style="color:var(--ahsobc-navy);">
                        <?= htmlspecialchars($activeProfile['full_name'], ENT_QUOTES, 'UTF-8') ?>
                        — School No. #<?= htmlspecialchars($activeProfile['school_number'], ENT_QUOTES, 'UTF-8') ?>
                        · Class of <?= (int) $activeProfile['cohort_year'] ?>
                        · <?= htmlspecialchars($activeProfile['house'], ENT_QUOTES, 'UTF-8') ?> House
                    </h3>
                    <p style="color:var(--ahsobc-muted);margin:0.35rem 0 1rem;">
                        <?= htmlspecialchars($activeProfile['bio_summary'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <div class="photo-grid">
                        <?php foreach ($profileLifespanItems as $lItem): ?>
                            <div class="contribution-box" style="background:#ffffff;">
                                <div class="code"><?= htmlspecialchars($lItem['photo_context'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($lItem['temporal_raw'], ENT_QUOTES, 'UTF-8') ?></div>
                                <h3><?= htmlspecialchars($lItem['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <p style="font-size:0.85rem;color:var(--ahsobc-muted);">
                                    <?= htmlspecialchars($lItem['description'], ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <!-- Phase 4.2: Alumni Crowdsourcing Across Life Stages (Collecting Module) -->
        <section class="section-card" id="contribute" aria-labelledby="contribute-heading">
            <h2 id="contribute-heading">Alumni Crowdsourcing Across Life Stages (Omeka S Collecting Module)</h2>
            <p style="color:var(--ahsobc-muted);">
                Gather photographs taken long after graduation alongside historic school memories and row-by-row face identifications.
            </p>

            <div class="contribution-grid" style="margin-bottom:1.5rem;">
                <?php foreach (AHSOBC_CONTRIBUTION_TYPES as $cKey => $cMeta): ?>
                    <div class="contribution-box">
                        <div class="code"><?= htmlspecialchars($cMeta['code'], ENT_QUOTES, 'UTF-8') ?></div>
                        <h3><?= htmlspecialchars($cMeta['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <p style="font-size:0.85rem;color:var(--ahsobc-muted);">
                            <?= htmlspecialchars($cMeta['summary'], ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="photo-grid">
                <!-- Form A: Upload Reunion / Informal / School Photograph (Contribution Types 1 & 2) -->
                <form class="contribution-box" method="post" action="/gallery/#contribute" aria-label="Submit Archival Photograph">
                    <div class="code">CONTRIBUTION TYPES 1 &amp; 2</div>
                    <h3>Submit a Reunion, Chapter, or School Days Photograph</h3>

                    <!-- Honeypot field -->
                    <div style="position:absolute;left:-9999px;" aria-hidden="true">
                        <label for="hp-photo">Leave this field blank</label>
                        <input id="hp-photo" type="text" name="website_url_hp" value="" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="filter-bar" style="grid-template-columns:1fr;border:none;padding:0;background:transparent;margin-top:0.75rem;margin-bottom:0;">
                        <div>
                            <label for="sub-type">Contribution Category</label>
                            <select id="sub-type" name="contribution_type" required>
                                <option value="reunions">Reunions &amp; Campus Returns</option>
                                <option value="informal">Informal Encounters &amp; Trips</option>
                            </select>
                        </div>
                        <div>
                            <label for="sub-name">Your Name</label>
                            <input id="sub-name" type="text" name="submitter_name" required placeholder="e.g. John Kamau (Class of 1988)">
                        </div>
                        <div>
                            <label for="sub-email">Your Email</label>
                            <input id="sub-email" type="email" name="submitter_email" required placeholder="you@example.com">
                        </div>
                        <div>
                            <label for="sub-title">Photograph Title (dcterms:title)</label>
                            <input id="sub-title" type="text" name="dcterms_title" required placeholder="e.g. 1998 20th Reunion Dinner">
                        </div>
                        <div>
                            <label for="sub-context">Photo Context (dcterms:type)</label>
                            <select id="sub-context" name="dcterms_type" required>
                                <?php foreach (AHSOBC_PHOTO_CONTEXTS as $ctxKey => $ctxDesc): ?>
                                    <option value="<?= htmlspecialchars($ctxKey, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($ctxKey, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="sub-temporal">Circa / Event Date (dcterms:temporal)</label>
                            <input id="sub-temporal" type="text" name="dcterms_temporal" required placeholder="e.g. circa 1978 or 2019-11-15">
                        </div>
                        <div>
                            <label for="sub-cohort">Class Cohort(s) (dcterms:subject)</label>
                            <input id="sub-cohort" type="text" name="dcterms_subject" required placeholder="e.g. 1978 | 1979">
                        </div>
                        <div>
                            <label for="sub-spatial">Location / Venue (dcterms:spatial)</label>
                            <input id="sub-spatial" type="text" name="dcterms_spatial" placeholder="e.g. Carey Francis Chapel or Nairobi">
                        </div>
                        <div>
                            <label for="sub-desc">Archival Caption &amp; Depicted Old Boys (dcterms:description)</label>
                            <textarea id="sub-desc" name="dcterms_description" rows="2" required placeholder="Describe the occasion and Old Boys pictured..."></textarea>
                        </div>
                        <div style="font-size:0.8rem;color:var(--ahsobc-muted);">
                            <label style="display:flex;align-items:flex-start;gap:0.5rem;text-transform:none;font-weight:400;">
                                <input type="checkbox" name="dpa_consent" value="1" required style="margin-top:0.2rem;">
                                <span><?= htmlspecialchars(AHSOBC_DPA_CONSENT_TEXT, ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                        </div>
                        <button type="submit" name="ahsobc_collecting_submit" value="1" class="btn-primary">
                            Submit Photograph to Archive
                        </button>
                    </div>
                </form>

                <!-- Form B: Historic Face Identification (Contribution Type 3) -->
                <form class="contribution-box" method="post" action="/gallery/#contribute" aria-label="Identify a Classmate in a Group Photo">
                    <div class="code">CONTRIBUTION TYPE 3</div>
                    <h3>Historic Face Identification (Row &amp; Mirador Region Tagging)</h3>
                    <input type="hidden" name="contribution_type" value="face_id">

                    <!-- Honeypot field -->
                    <div style="position:absolute;left:-9999px;" aria-hidden="true">
                        <label for="hp-face">Leave this field blank</label>
                        <input id="hp-face" type="text" name="website_url_hp" value="" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="filter-bar" style="grid-template-columns:1fr;border:none;padding:0;background:transparent;margin-top:0.75rem;margin-bottom:0;">
                        <div>
                            <label for="face-target">Select Group Photograph</label>
                            <select id="face-target" name="target_item_id" required>
                                <?php foreach ($allItems as $itOpt): ?>
                                    <option value="<?= htmlspecialchars($itOpt['slug'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($itOpt['title'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($itOpt['temporal_raw'], ENT_QUOTES, 'UTF-8') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                            <div>
                                <label for="face-row">Row No. (1 = Front)</label>
                                <input id="face-row" type="number" name="row" min="1" max="25" required value="1">
                            </div>
                            <div>
                                <label for="face-pos">Seat from Left</label>
                                <input id="face-pos" type="number" name="position_from_left" min="1" max="60" required value="1">
                            </div>
                        </div>
                        <div>
                            <label for="face-fullname">Identified Old Boy's Full Name</label>
                            <input id="face-fullname" type="text" name="full_name" required placeholder="e.g. Hon. Kenneth Matiba">
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.5rem;">
                            <div>
                                <label for="face-sno">School No. (#)</label>
                                <input id="face-sno" type="text" name="school_number" placeholder="e.g. 1420">
                            </div>
                            <div>
                                <label for="face-cohort">Class Year</label>
                                <input id="face-cohort" type="text" name="cohort" placeholder="e.g. 1950">
                            </div>
                            <div>
                                <label for="face-house">House</label>
                                <input id="face-house" type="text" name="house" placeholder="e.g. Aggrey">
                            </div>
                        </div>
                        <div>
                            <label for="face-xywh">Optional IIIF Region Coordinates (xywh)</label>
                            <input id="face-xywh" type="text" name="iiif_region" placeholder="e.g. xywh=420,310,180,220">
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                            <div>
                                <label for="face-subname">Your Name</label>
                                <input id="face-subname" type="text" name="submitter_name" required placeholder="Your Name">
                            </div>
                            <div>
                                <label for="face-subemail">Your Email</label>
                                <input id="face-subemail" type="email" name="submitter_email" required placeholder="you@example.com">
                            </div>
                        </div>
                        <div style="font-size:0.8rem;color:var(--ahsobc-muted);">
                            <label style="display:flex;align-items:flex-start;gap:0.5rem;text-transform:none;font-weight:400;">
                                <input type="checkbox" name="dpa_consent" value="1" required style="margin-top:0.2rem;">
                                <span><?= htmlspecialchars(AHSOBC_DPA_CONSENT_TEXT, ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                        </div>
                        <button type="submit" name="ahsobc_collecting_submit" value="1" class="btn-primary">
                            Submit Face Identification
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <!-- Omeka S Active Archival Modules & Status Footer -->
        <footer class="section-card" style="font-size:0.85rem;color:var(--ahsobc-muted);">
            <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
                <div>
                    <strong style="color:var(--ahsobc-navy);">Active Omeka S v4.1.1 Archival Modules (Activation Order):</strong>
                    <?php
                    $modBadges = array_map(
                        static fn(array $m): string => $m['id'] . ' v' . $m['version'],
                        $activeModules
                    );
                    echo htmlspecialchars(implode(' → ', $modBadges), ENT_QUOTES, 'UTF-8');
                    ?>
                </div>
                <div>
                    Kenya DPA (2019) Compliant · Alliance High School Old Boys Club (<?= htmlspecialchars(AHSOBC_CENTENNIAL_SPAN, ENT_QUOTES, 'UTF-8') ?>)
                </div>
            </div>
        </footer>
    </main>
</body>
</html>
