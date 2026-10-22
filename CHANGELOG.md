# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org) (pre-1.0: MINOR = new features/user-facing
behaviour, PATCH = fixes/docs/housekeeping — see `SKILL.md`).

## [0.4.0] - 2026-10-22

### Added

- Main page top navigation bar (`/`, `public/index.php`, `public/assets/css/main.css`) with brand header and direct navigation links to `Home` (`/`) and `Centennial Photo Gallery` (`/gallery/`), verified in `e2e/test_smoke.py` (closes #19)
- Kenya Data Protection Act (2019) Takedown / Privacy Objection workflow (`dpa_takedown` contribution type in `public/includes/crowdsourcing_guard.php` and a dedicated **DPA Takedown / Privacy Request** tab in the Gallery modal) (closes #17)

### Security

- Enforced cryptographic CSRF token verification (`ahsobc_csrf_token()`, `ahsobc_verify_csrf_token()`), optional `AHSOBC_EDITOR_TOKEN` constant-time verification (`ahsobc_verify_editor_token()`), and atomic `flock()` sliding-window rate limiting on `POST /api/gallery.php` and `POST /gallery/` metadata mutation routes (closes #16)
- Isolated unmoderated `face_id` submissions from public `archive_items.face_tags_json` until archivist moderation and filtered any `[Pending Moderation]` tags out of public `ahsobc_query_headless_gallery()` responses (closes #17)
- Blocked leading `-` and `|` spreadsheet formula injection payloads in `ahsobc_has_csv_injection()`, enforced formula injection checks on crowdsourced and edited text fields, removed the unpinned `git clone` fallback in `omeka/scripts/install_omeka.sh`, and added `Content-Security-Policy`, `Strict-Transport-Security`, `LIKE` wildcard escaping, and `JSON_HEX_TAG` encoding (closes #18)

tag: `v0.4.0`

## [0.3.0] - 2026-10-22

### Added

- Flickr-style Headless Omeka S Visual Gallery, Albums & Inline Metadata Editor (`/gallery/`, `/api/gallery.php`):
  - Clean Flickr-style sub-navigation (`Photostream`, `Albums`, `People`) and tight edge-to-edge Photostream image grid with period-styled archival previews (`public/assets/img/archive/*.svg`) and zero page clutter (closes #14)
  - Visual Album cover cards (`ahsobc_build_albums()`) organized by Occasion (`School Days`, `Milestone Reunion`, `Regional Gathering`, `Informal Encounter`), the 8 AHS Houses in institutional order (`Livingstone` → `Sellwood`), and Centennial Decades
  - Per-image Theatre Lightbox (`#ahsobc-lightbox` + `public/assets/js/gallery-ui.js`) with `←`/`→`/`Esc` navigation, Deep-Zoom, IIIF `xywh` face-tag bounding boxes, and an inline `✎ Edit Metadata` mode (`POST /api/gallery.php`, `ahsobc_update_item_metadata()`) to edit and save Title, Caption, Album/Category, House, Date, Cohort(s), and Location directly on any photograph
  - Clean `+ Submit Photo` modal dialog (`#upload-modal`) for uploading new photos or tagging classmates without cluttering the main Photostream

tag: `v0.3.0`

## [0.2.0] - 2026-10-22

### Added

- Phase 1 Omeka S v4.1.1 Centennial Photo Gallery (`/gallery/`) & main site integration:
  - Root Apache `.htaccess` security headers & `/api/health.php` readiness check (closes #4)
  - Omeka S v4.1.1 installer (`omeka/scripts/install_omeka.sh`), `RewriteBase /gallery/` config, non-executable `files/.htaccess`, and zero-Docker `./local-preview/setup.sh` runner on port `8890` (closes #5)
  - Pinned archival module manifest (`omeka/modules.lock.json`) and strict dependency activation order (`Common` → `CustomVocab` → `IiifServer` → `ImageServer` → `Mirador` → `CSVImport` → `Mapping` → `Collecting`) (closes #6)
  - Idempotent schema provisioner (`omeka/scripts/provision_schema.php`), `Alumni Photograph` & `Alumni Profile` Resource Templates, AHS Houses & Photo Context controlled vocabularies, and unit-tested `temporal_parser.php` + `cohort_helper.php` (closes #7)
  - Custom `ahsobc-centennial` Omeka S theme (`#0b8ed5` AHS shield blue), 1926–2026 Decade Timeline Scrubber, Mirador IIIF v3 Deep-Zoom Inspector, and 40-year Alumni Profile Lifespan comparison view (closes #8)
  - Pre-ingest CSV manifest validator (`public/includes/manifest_validator.php`, `omeka/scripts/validate_staging.php`) with formula-injection/path-traversal guards and canonical Centennial seed corpus (`omeka/staging/sample_manifest.csv`) (closes #9)
  - Moderated Crowdsourced Collecting workflows across all 4 Contribution Types, Mirador row/seat & IIIF `xywh` face identification, honeypot + sliding-window IP rate limiter, and Kenya DPA 2019 consent guardrails (`public/includes/crowdsourcing_guard.php`) (closes #10)
  - Hardened daily MySQL + media backup script (`scripts/backup_alumni_archive.sh` using `chmod 0600 ~/.my.cnf.ahsobc` and 14-day retention) and Angryhosting FTPS deployment workflow (`.github/workflows/deploy-ftp.yml`) (closes #11, closes #3)

tag: `v0.2.0`

## [0.1.0] - 2026-10-22

### Added

- Initial project scaffold: repo, branch protection, CI (`unit` and `e2e` gates on every PR),
  versioning, main PHP placeholder landing page, and issue-first workflow (closes #1)

tag: `v0.1.0`
