<?php
/**
 * AHSOBC Flickr-Style Centennial Photo Gallery (/gallery/) Powered by Headless Omeka S.
 *
 * Provides:
 *   - Clean Photostream (edge-to-edge visual grid)
 *   - Albums View (Occasions, 8 AHS Houses in order, Centennial Decades)
 *   - People View (Alumni Lifespan profiles across 40+ years)
 *   - Per-Image Theatre Lightbox with full Metadata View, Inline Metadata Editing,
 *     and Classmate Face Identification
 */

declare(strict_types=1);

require_once __DIR__ . '/crowdsourcing_guard.php';
require_once __DIR__ . '/headless_gallery.php';

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
            ['label' => ['en' => ['Album / Context']], 'value' => ['en' => [$item['photo_context']]]],
            ['label' => ['en' => ['Circa / Event Date']], 'value' => ['en' => [$item['temporal_raw']]]],
            ['label' => ['en' => ['Class Cohort(s)']], 'value' => ['en' => [$item['cohorts_raw']]]],
            ['label' => ['en' => ['Location / Venue']], 'value' => ['en' => [$item['spatial']]]],
            ['label' => ['en' => ['House']], 'value' => ['en' => [$item['house'] . ' House']]],
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

// 2. Handle POST Submissions (Inline Metadata Edit OR Crowdsourced Photo/Face Tag)
$flashSuccess = null;
$flashErrors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['ahsobc_edit_metadata_submit'])) {
    $upd = ahsobc_update_item_metadata($pdo, $conn['driver'], $_POST);
    if (!$upd['valid']) {
        http_response_code(422);
        $flashErrors = $upd['errors'];
    } else {
        $flashSuccess = 'Metadata updated for "' . htmlspecialchars((string) $upd['item']['title'], ENT_QUOTES, 'UTF-8') . '".';
    }
}

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

            if ($sanitized['contribution_type'] === 'face_id' && !empty($sanitized['target_item_id'])) {
                $stmtItem = $pdo->prepare('SELECT face_tags_json FROM archive_items WHERE slug = :slug');
                $stmtItem->execute([':slug' => $sanitized['target_item_id']]);
                $existingRow = $stmtItem->fetch();
                if ($existingRow) {
                    $tags = json_decode((string) $existingRow['face_tags_json'], true) ?: [];
                    $tags[] = $sanitized['formatted_annotation'] . ' [Pending Moderation]';
                    $stmtUpd = $pdo->prepare('UPDATE archive_items SET face_tags_json = :tags WHERE slug = :slug');
                    $stmtUpd->execute([
                        ':tags' => json_encode($tags, JSON_UNESCAPED_SLASHES),
                        ':slug' => $sanitized['target_item_id'],
                    ]);
                }
            }

            $flashSuccess = 'Thank you, ' . htmlspecialchars($sanitized['submitter_name'], ENT_QUOTES, 'UTF-8')
                . '! Your contribution has been recorded.';
        }
    }
}

// 3. Query Headless Omeka S Catalog
$headlessPayload = ahsobc_query_headless_gallery($pdo, $conn['driver'], $_GET);
$headlessItems = $headlessPayload['items'];
$albums = $headlessPayload['albums'];
$allProfiles = $headlessPayload['alumni_profiles'];
$profilesBySlug = [];
foreach ($allProfiles as $prof) {
    $profilesBySlug[$prof['slug']] = $prof;
}

$filterDecade = $headlessPayload['filters']['decade'];
$filterContext = $headlessPayload['filters']['context'];
$filterHouse = $headlessPayload['filters']['house'];
$filterCohort = $headlessPayload['filters']['cohort'];
$filterSearch = $headlessPayload['filters']['q'];
$selectedItemSlug = trim((string) ($_GET['item'] ?? ''));
$selectedProfileSlug = trim((string) ($_GET['alumnus'] ?? ''));
$activeTab = trim((string) ($_GET['tab'] ?? 'photostream'));
if ($selectedProfileSlug !== '') {
    $activeTab = 'people';
}

$activeItem = null;
if ($selectedItemSlug !== '') {
    foreach ($headlessItems as $it) {
        if ($it['slug'] === $selectedItemSlug) {
            $activeItem = $it;
            break;
        }
    }
    if ($activeItem === null) {
        $stmtOne = $pdo->prepare('SELECT * FROM archive_items WHERE slug = :slug LIMIT 1');
        $stmtOne->execute([':slug' => $selectedItemSlug]);
        $activeItem = $stmtOne->fetch() ?: null;
    }
}

$activeProfile = null;
$profileLifespanItems = [];
if ($selectedProfileSlug !== '' && isset($profilesBySlug[$selectedProfileSlug])) {
    $activeProfile = $profilesBySlug[$selectedProfileSlug];
    $stmtLife = $pdo->prepare('SELECT * FROM archive_items WHERE featured_alumni_json LIKE :pslug ORDER BY year_start ASC');
    $stmtLife->execute([':pslug' => '%' . $selectedProfileSlug . '%']);
    $profileLifespanItems = $stmtLife->fetchAll();
}

$allItems = $pdo->query('SELECT slug, title, temporal_raw FROM archive_items ORDER BY year_start ASC')->fetchAll();
$hasActiveFilter = ($filterDecade !== '' || $filterContext !== '' || $filterHouse !== '' || $filterCohort !== '' || $filterSearch !== '');
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
    <!-- 1. Clean Flickr-Style Top Bar -->
    <header class="flickr-topbar">
        <a class="flickr-brand" href="/gallery/">
            <img src="/assets/img/obc-logo.jpg" alt="Alliance High School Old Boys Club">
            <span class="flickr-brand-name">AHSOBC Photos</span>
            <span class="flickr-brand-badge">1926–2026</span>
        </a>

        <form id="ahsobc-filter-form" class="flickr-search-form" method="get" action="/gallery/" role="search" aria-label="Search Centennial Photographs">
            <input id="filter-q"
                   class="flickr-search-input"
                   type="text"
                   name="q"
                   placeholder="Search photos, albums, cohorts (e.g. 1960), houses, or names..."
                   value="<?= htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8') ?>"
                   autocomplete="off">
        </form>

        <div class="flickr-top-actions">
            <a class="flickr-top-link" href="/">Main Site</a>
            <button type="button" id="open-upload-modal-btn" class="btn-primary">+ Submit Photo</button>
        </div>
    </header>

    <!-- 2. Clean Flickr Sub-Navigation Tabs (Photostream | Albums | People) -->
    <nav class="flickr-subnav" aria-label="Gallery Views">
        <div class="flickr-subnav-inner">
            <div class="flickr-tabs" role="tablist">
                <a href="/gallery/"
                   id="tab-btn-photostream"
                   data-switch-tab="photostream"
                   class="flickr-tab <?= $activeTab === 'photostream' ? 'active' : '' ?>">
                    Photostream <span id="ahsobc-tab-count" class="flickr-tab-count">(<?= count($headlessItems) ?>)</span>
                </a>
                <a href="/gallery/?tab=albums"
                   id="tab-btn-albums"
                   data-switch-tab="albums"
                   class="flickr-tab <?= $activeTab === 'albums' ? 'active' : '' ?>">
                    Albums <span class="flickr-tab-count">(<?= count($albums) ?>)</span>
                </a>
                <a href="/gallery/?tab=people"
                   id="tab-btn-people"
                   data-switch-tab="people"
                   class="flickr-tab <?= $activeTab === 'people' ? 'active' : '' ?>">
                    People <span class="flickr-tab-count">(<?= count($allProfiles) ?>)</span>
                </a>
            </div>

            <!-- Subtle Decade Quick-Links -->
            <div class="decade-pills" role="region" aria-label="Centennial Decade Timeline Scrubber">
                <a class="decade-pill <?= !$hasActiveFilter ? 'active' : '' ?>" href="/gallery/">All</a>
                <?php foreach (['1930s', '1950s', '1980s', '2010s', '2020s'] as $dec): ?>
                    <a class="decade-pill <?= $filterDecade === $dec ? 'active' : '' ?>"
                       href="/gallery/?decade=<?= rawurlencode($dec) ?>">
                        <?= htmlspecialchars($dec, ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </nav>

    <main class="ahsobc-container">
        <!-- Visually hidden heading for screen readers & semantic structure -->
        <h1 id="archive-heading" style="position:absolute;left:-9999px;">Centennial Alumni Photographic Repository (1926–2026)</h1>

        <?php if ($flashSuccess !== null): ?>
            <div class="alert-success" role="status">
                <?= $flashSuccess ?>
            </div>
        <?php endif; ?>

        <?php if ($flashErrors): ?>
            <div class="alert-error" role="alert">
                <strong>Please check the following:</strong>
                <ul style="margin-top:0.35rem;padding-left:1.25rem;">
                    <?php foreach ($flashErrors as $err): ?>
                        <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Active Album / Filter Breadcrumb Bar (only shown when filtering by an Album or Search) -->
        <?php if ($hasActiveFilter): ?>
            <div class="active-filter-banner" id="active-album-banner">
                <div>
                    <strong style="color:var(--ahsobc-navy);">
                        <?php if ($filterContext !== ''): ?>
                            Album: <?= htmlspecialchars($filterContext, ENT_QUOTES, 'UTF-8') ?>
                        <?php elseif ($filterHouse !== ''): ?>
                            Album: <?= htmlspecialchars($filterHouse, ENT_QUOTES, 'UTF-8') ?> House
                        <?php elseif ($filterDecade !== ''): ?>
                            Album: <?= htmlspecialchars($filterDecade, ENT_QUOTES, 'UTF-8') ?>
                        <?php elseif ($filterSearch !== ''): ?>
                            Search: "<?= htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8') ?>"
                        <?php elseif ($filterCohort !== ''): ?>
                            Cohort: <?= htmlspecialchars($filterCohort, ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </strong>
                    <span style="color:var(--ahsobc-muted);font-size:0.85rem;margin-left:0.5rem;">
                        (<?= count($headlessItems) ?> photo<?= count($headlessItems) === 1 ? '' : 's' ?>)
                    </span>
                </div>
                <a href="/gallery/" class="decade-pill">← All Photos</a>
            </div>
        <?php endif; ?>

        <!-- Direct Item Inspector Section when navigated via ?item=<slug> -->
        <?php if ($activeItem !== null): ?>
            <section class="section-card" id="mirador-viewer" aria-label="Mirador IIIF Deep-Zoom Inspector" style="margin-bottom:1.25rem;background:#111827;color:#f3f4f6;">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;">
                    <div>
                        <span class="badge badge-context"><?= htmlspecialchars((string) $activeItem['photo_context'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="badge"><?= htmlspecialchars((string) $activeItem['temporal_raw'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="badge"><?= htmlspecialchars((string) $activeItem['house'], ENT_QUOTES, 'UTF-8') ?> House</span>
                        <h2 style="color:#ffffff;margin-top:0.4rem;"><?= htmlspecialchars((string) $activeItem['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                        <p style="color:#cbd5e1;font-size:0.9rem;margin-top:0.25rem;"><?= htmlspecialchars((string) $activeItem['description'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div style="display:flex;gap:0.5rem;">
                        <a class="btn-primary" href="/gallery/?iiif_manifest=<?= rawurlencode((string) $activeItem['slug']) ?>">IIIF v3 Manifest</a>
                        <a class="lb-btn" href="/gallery/">Close</a>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <!-- TAB 1: PHOTOSTREAM (Clean, Tight Flickr Image Grid) -->
        <section id="view-panel-photostream" aria-label="Photostream" <?= $activeTab !== 'photostream' ? 'hidden' : '' ?>>
            <div id="ahsobc-photo-wall" class="photo-grid">
                <?php foreach ($headlessItems as $idx => $item): ?>
                    <article class="photo-card"
                             data-index="<?= (int) $idx ?>"
                             data-slug="<?= htmlspecialchars($item['slug'], ENT_QUOTES, 'UTF-8') ?>"
                             data-decade="<?= htmlspecialchars($item['decade'], ENT_QUOTES, 'UTF-8') ?>"
                             data-house="<?= htmlspecialchars($item['house'], ENT_QUOTES, 'UTF-8') ?>"
                             data-context="<?= htmlspecialchars($item['photo_context'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="photo-card-visual"
                             role="button"
                             tabindex="0"
                             aria-label="Open <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>"
                             data-open-lightbox="<?= (int) $idx ?>">
                            <img class="photo-card-img"
                                 src="<?= htmlspecialchars($item['preview_url'], ENT_QUOTES, 'UTF-8') ?>"
                                 alt="<?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>"
                                 loading="lazy">
                            <div class="photo-card-overlay">
                                <h3 class="photo-card-title">
                                    <a href="<?= htmlspecialchars($item['item_url'], ENT_QUOTES, 'UTF-8') ?>"
                                       data-open-lightbox="<?= (int) $idx ?>">
                                        <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                </h3>
                                <div class="photo-card-sub">
                                    <span><?= htmlspecialchars($item['temporal_raw'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($item['house'], ENT_QUOTES, 'UTF-8') ?> House</span>
                                    <span><?= htmlspecialchars($item['photo_context'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- TAB 2: ALBUMS (Flickr-Style Album Cards by Occasion, 8 Houses, and Decades) -->
        <section id="view-panel-albums" aria-label="Albums" <?= $activeTab !== 'albums' ? 'hidden' : '' ?>>
            <?php
            $albumsByGroup = [];
            foreach ($albums as $alb) {
                $albumsByGroup[$alb['group']][] = $alb;
            }
            ?>
            <?php foreach ($albumsByGroup as $groupName => $groupAlbums): ?>
                <h2 class="album-section-title"><?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="albums-grid">
                    <?php foreach ($groupAlbums as $alb): ?>
                        <a class="album-card"
                           href="<?= htmlspecialchars($alb['url'], ENT_QUOTES, 'UTF-8') ?>"
                           data-album-key="<?= htmlspecialchars($alb['filter_key'], ENT_QUOTES, 'UTF-8') ?>"
                           data-album-val="<?= htmlspecialchars($alb['filter_value'], ENT_QUOTES, 'UTF-8') ?>">
                            <img src="<?= htmlspecialchars($alb['cover_url'], ENT_QUOTES, 'UTF-8') ?>"
                                 alt="<?= htmlspecialchars($alb['title'], ENT_QUOTES, 'UTF-8') ?>"
                                 loading="lazy">
                            <div class="album-card-overlay">
                                <div class="album-card-title"><?= htmlspecialchars($alb['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="album-card-count"><?= (int) $alb['count'] ?> photo<?= (int) $alb['count'] === 1 ? '' : 's' ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </section>

        <!-- TAB 3: PEOPLE / LIFESPANS (Clean Alumni Directory) -->
        <section id="view-panel-people" class="section-card" aria-label="People and Cohort Lifespans" <?= $activeTab !== 'people' ? 'hidden' : '' ?>>
            <div id="lifespans">
                <h2 style="color:var(--ahsobc-navy);margin-bottom:0.4rem;">Old Boys in the Archive (School Days vs. Reunions)</h2>
                <p style="color:var(--ahsobc-muted);font-size:0.9rem;margin-bottom:1rem;">
                    Select an Old Boy to view every photograph they appear in across decades.
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
                    <div style="background:#f8fafc;border:1px solid var(--ahsobc-border);border-radius:8px;padding:1.25rem;">
                        <h3 style="color:var(--ahsobc-navy);">
                            <?= htmlspecialchars($activeProfile['full_name'], ENT_QUOTES, 'UTF-8') ?>
                            — School No. #<?= htmlspecialchars($activeProfile['school_number'], ENT_QUOTES, 'UTF-8') ?>
                            · Class of <?= (int) $activeProfile['cohort_year'] ?>
                            · <?= htmlspecialchars($activeProfile['house'], ENT_QUOTES, 'UTF-8') ?> House
                        </h3>
                        <p style="color:var(--ahsobc-muted);font-size:0.88rem;margin:0.25rem 0 1rem;">
                            <?= htmlspecialchars($activeProfile['bio_summary'], ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <div class="albums-grid">
                            <?php foreach ($profileLifespanItems as $lItem): ?>
                                <div class="section-card" style="padding:1rem;">
                                    <div style="font-size:0.75rem;font-weight:700;color:var(--ahsobc-blue);text-transform:uppercase;">
                                        <?= htmlspecialchars($lItem['photo_context'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($lItem['temporal_raw'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <h4 style="margin:0.25rem 0;"><?= htmlspecialchars($lItem['title'], ENT_QUOTES, 'UTF-8') ?></h4>
                                    <p style="font-size:0.84rem;color:var(--ahsobc-muted);">
                                        <?= htmlspecialchars($lItem['description'], ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <!-- 3. Flickr Photo Theatre + Per-Image Metadata & Inline Editor (#ahsobc-lightbox) -->
    <div id="ahsobc-lightbox" class="ahsobc-lightbox" role="dialog" aria-modal="true" aria-labelledby="lb-title" aria-hidden="true" hidden>
        <div class="lb-topbar">
            <div style="display:flex;align-items:center;gap:0.65rem;">
                <span id="lb-counter" class="badge">1 / 5</span>
                <div id="lb-badges" class="badge-row"></div>
            </div>
            <div class="lb-controls">
                <button type="button" id="lb-prev-btn" class="lb-btn" aria-label="Previous Photograph">← Prev</button>
                <button type="button" id="lb-next-btn" class="lb-btn" aria-label="Next Photograph">Next →</button>
                <button type="button" id="lb-zoom-in" class="lb-btn">Zoom +</button>
                <button type="button" id="lb-toggle-faces" class="lb-btn">Hide Face Tags</button>
                <a id="lb-iiif-link" href="/gallery/" class="lb-btn">IIIF JSON</a>
                <button type="button" id="lb-close-btn" class="lb-btn" style="background:#dc2626;border-color:#dc2626;" aria-label="Close Lightbox">✕ Close</button>
            </div>
        </div>

        <div class="lb-workspace">
            <div class="lb-stage">
                <div id="lb-stage-inner" class="lb-stage-inner">
                    <img id="lb-image" src="/assets/img/obc-logo.jpg" alt="">
                    <div id="lb-face-boxes"></div>
                </div>
            </div>

            <!-- Per-Image Metadata & Inline Edit Panel -->
            <aside class="lb-drawer" aria-label="Photograph Metadata and Editing">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.5rem;">
                    <h2 id="lb-title" style="color:#ffffff;font-size:1.2rem;line-height:1.3;">Photograph Title</h2>
                    <button type="button" id="lb-edit-toggle-btn" class="lb-btn" style="flex-shrink:0;">✎ Edit Metadata</button>
                </div>

                <div id="lb-save-status" class="alert-success" style="display:none;padding:0.5rem 0.75rem;font-size:0.82rem;margin:0;"></div>

                <!-- Read-Only Metadata View -->
                <div id="lb-metadata-view" style="display:flex;flex-direction:column;gap:0.9rem;">
                    <p id="lb-description" style="font-size:0.88rem;color:#d1d5db;"></p>

                    <dl id="lb-meta-dl" class="meta-dl" style="border-top:1px solid #27272a;padding-top:0.8rem;"></dl>

                    <div style="background:#222226;padding:0.85rem;border-radius:6px;border:1px solid #3f3f46;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.4rem;">
                            <strong style="color:#93c5fd;font-size:0.78rem;text-transform:uppercase;">People / Face Identifications</strong>
                            <button type="button" id="lb-tag-classmate-btn" class="lb-btn" style="padding:0.2rem 0.55rem;font-size:0.75rem;">+ Tag Person</button>
                        </div>
                        <ul id="lb-face-tags" style="padding-left:1.1rem;font-size:0.82rem;color:#e5e7eb;display:flex;flex-direction:column;gap:0.3rem;"></ul>
                    </div>
                </div>

                <!-- Inline Metadata Editor Form (Hidden until user clicks "✎ Edit Metadata") -->
                <form id="lb-edit-form" class="lb-edit-form" method="post" action="/gallery/" hidden>
                    <input type="hidden" name="ahsobc_edit_metadata_submit" value="1">
                    <input type="hidden" id="edit-slug" name="slug" value="">

                    <div>
                        <label for="edit-title">Title</label>
                        <input id="edit-title" type="text" name="title" required>
                    </div>
                    <div>
                        <label for="edit-description">Caption / Description</label>
                        <textarea id="edit-description" name="description" rows="3" required></textarea>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                        <div>
                            <label for="edit-context">Album / Category</label>
                            <select id="edit-context" name="photo_context" required>
                                <?php foreach (AHSOBC_PHOTO_CONTEXTS as $ctxKey => $ctxDesc): ?>
                                    <option value="<?= htmlspecialchars($ctxKey, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($ctxKey, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="edit-house">AHS House</label>
                            <select id="edit-house" name="house" required>
                                <?php foreach (AHSOBC_HOUSES as $hName): ?>
                                    <option value="<?= htmlspecialchars($hName, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($hName, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                        <div>
                            <label for="edit-temporal">Date / Circa Year</label>
                            <input id="edit-temporal" type="text" name="temporal_raw" required>
                        </div>
                        <div>
                            <label for="edit-cohorts">Class Cohort(s)</label>
                            <input id="edit-cohorts" type="text" name="cohorts_raw" required>
                        </div>
                    </div>
                    <div>
                        <label for="edit-spatial">Location / Venue</label>
                        <input id="edit-spatial" type="text" name="spatial" required>
                    </div>
                    <div style="display:flex;gap:0.5rem;margin-top:0.25rem;">
                        <button type="submit" id="lb-save-metadata-btn" class="btn-primary" style="flex:1;">Save Changes</button>
                        <button type="button" id="lb-cancel-edit-btn" class="lb-btn">Cancel</button>
                    </div>
                </form>
            </aside>
        </div>
    </div>

    <!-- 4. Clean Modal for Submitting a Photo or Face Identification -->
    <div id="upload-modal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="upload-modal-title" hidden>
        <div class="modal-card">
            <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--ahsobc-border);padding-bottom:0.75rem;">
                <h2 id="upload-modal-title" style="font-size:1.15rem;color:var(--ahsobc-navy);">Contribute to the AHSOBC Archive</h2>
                <button type="button" id="close-upload-modal-btn" class="lb-btn" style="background:#f1f5f9;color:#1e293b;border-color:#cbd5e1;">✕</button>
            </div>

            <div class="decade-pills" style="margin-top:0.85rem;">
                <button type="button" id="modal-tab-photo" class="decade-pill active">Submit a Photograph</button>
                <button type="button" id="modal-tab-face" class="decade-pill">Tag a Classmate in a Photo</button>
            </div>

            <!-- Form A: Submit Photograph -->
            <form id="modal-form-photo" class="form-stack" method="post" action="/gallery/" aria-label="Submit Archival Photograph">
                <div style="position:absolute;left:-9999px;" aria-hidden="true">
                    <input type="text" name="website_url_hp" value="" tabindex="-1" autocomplete="off">
                </div>
                <input type="hidden" name="contribution_type" value="reunions">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                    <div>
                        <label for="sub-name">Your Name</label>
                        <input id="sub-name" type="text" name="submitter_name" required placeholder="John Kamau">
                    </div>
                    <div>
                        <label for="sub-email">Your Email</label>
                        <input id="sub-email" type="email" name="submitter_email" required placeholder="you@example.com">
                    </div>
                </div>
                <div>
                    <label for="sub-title">Photograph Title</label>
                    <input id="sub-title" type="text" name="dcterms_title" required placeholder="e.g. 1998 20th Reunion Dinner">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.5rem;">
                    <div>
                        <label for="sub-context">Album / Category</label>
                        <select id="sub-context" name="dcterms_type" required>
                            <?php foreach (AHSOBC_PHOTO_CONTEXTS as $ctxKey => $ctxDesc): ?>
                                <option value="<?= htmlspecialchars($ctxKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($ctxKey, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="sub-temporal">Date / Year</label>
                        <input id="sub-temporal" type="text" name="dcterms_temporal" required placeholder="circa 1978">
                    </div>
                    <div>
                        <label for="sub-cohort">Cohort Year(s)</label>
                        <input id="sub-cohort" type="text" name="dcterms_subject" required placeholder="1978">
                    </div>
                </div>
                <div>
                    <label for="sub-spatial">Location / Venue</label>
                    <input id="sub-spatial" type="text" name="dcterms_spatial" placeholder="Alliance High School, Kikuyu">
                </div>
                <div>
                    <label for="sub-desc">Caption &amp; Old Boys Pictured</label>
                    <textarea id="sub-desc" name="dcterms_description" rows="2" required placeholder="Describe the occasion..."></textarea>
                </div>
                <label style="display:flex;align-items:flex-start;gap:0.5rem;font-weight:400;font-size:0.78rem;">
                    <input type="checkbox" name="dpa_consent" value="1" required style="margin-top:0.2rem;">
                    <span><?= htmlspecialchars(AHSOBC_DPA_CONSENT_TEXT, ENT_QUOTES, 'UTF-8') ?></span>
                </label>
                <button type="submit" name="ahsobc_collecting_submit" value="1" class="btn-primary">Submit Photograph</button>
            </form>

            <!-- Form B: Tag a Classmate (Contribution Type 3) -->
            <form id="modal-form-face" class="form-stack" method="post" action="/gallery/" aria-label="Identify a Classmate in a Group Photo" hidden>
                <div style="position:absolute;left:-9999px;" aria-hidden="true">
                    <input type="text" name="website_url_hp" value="" tabindex="-1" autocomplete="off">
                </div>
                <input type="hidden" name="contribution_type" value="face_id">
                <div>
                    <label for="face-target">Photograph</label>
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
                        <input id="face-sno" type="text" name="school_number" placeholder="1420">
                    </div>
                    <div>
                        <label for="face-cohort">Class Year</label>
                        <input id="face-cohort" type="text" name="cohort" placeholder="1950">
                    </div>
                    <div>
                        <label for="face-house">House</label>
                        <input id="face-house" type="text" name="house" placeholder="Francis">
                    </div>
                </div>
                <div>
                    <label for="face-xywh">Optional IIIF Region (xywh)</label>
                    <input id="face-xywh" type="text" name="iiif_region" placeholder="xywh=420,310,180,220">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                    <div>
                        <label for="face-subname">Your Name</label>
                        <input id="face-subname" type="text" name="submitter_name" required>
                    </div>
                    <div>
                        <label for="face-subemail">Your Email</label>
                        <input id="face-subemail" type="email" name="submitter_email" required>
                    </div>
                </div>
                <label style="display:flex;align-items:flex-start;gap:0.5rem;font-weight:400;font-size:0.78rem;">
                    <input type="checkbox" name="dpa_consent" value="1" required style="margin-top:0.2rem;">
                    <span><?= htmlspecialchars(AHSOBC_DPA_CONSENT_TEXT, ENT_QUOTES, 'UTF-8') ?></span>
                </label>
                <button type="submit" name="ahsobc_collecting_submit" value="1" class="btn-primary">Submit Face Identification</button>
            </form>
        </div>
    </div>

    <script>
        window.AHSOBC_INITIAL_ITEMS = <?= json_encode($headlessItems, JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="/assets/js/gallery-ui.js" defer></script>
</body>
</html>
