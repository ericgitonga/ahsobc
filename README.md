# Alliance High School Old Boys Club (`ahsobc`)

Official website and **Omeka S Centennial Photo Gallery (1926–2026)** for the **Alliance High School Old Boys Club (AHSOBC)** — [https://www.ahsobc.or.ke/](https://www.ahsobc.or.ke/).

## Architecture Overview

- **`public/` (`/`):** Zero-build PHP 8.1+/8.2 main site entry point. Currently serves the AHSOBC placeholder landing card (*"Strong to Serve"*) with direct navigation into the Centennial Photo Gallery, structured with modular `public/includes/` helpers so future sections drop in seamlessly on shared/managed hosting.
- **`omeka/` (`/gallery/`):** Tracked configuration templates, pinned module lockfile (`omeka/modules.lock.json`), idempotent schema & vocabulary provisioner (`omeka/scripts/provision_schema.php`), custom `ahsobc-centennial` theme, and pre-ingest CSV staging validator for **Omeka S v4.1.1**.
- **`scripts/`:** Production cron backup automation (`backup_alumni_archive.sh`) for Angryhosting (`egimba_ahs` MySQL database + `files/` derivative archive with 14-day retention).
- **`local-preview/`:** One-command local development runner (`./local-preview/setup.sh` on port `8890`).

## Quick Start (Local Development)

### 1. Run the Main PHP Site Locally

```bash
php -S 127.0.0.1:8000 -t public
```

Open [http://127.0.0.1:8000/](http://127.0.0.1:8000/).

### 2. Run the Full Local Preview (Main Site + Omeka S `/gallery/`)

```bash
./local-preview/setup.sh
```

Open [http://127.0.0.1:8890/](http://127.0.0.1:8890/) and [http://127.0.0.1:8890/gallery/](http://127.0.0.1:8890/gallery/).

## Testing & Quality Gates

Every pull request is gated by both PHP unit tests and Playwright E2E browser checks:

```bash
# Run dependency-free PHP unit tests & syntax checks
php tests/run.php

# Run Playwright E2E suite (with PHP server running on 127.0.0.1:8000)
conda run -n ds python e2e/run.py
```
