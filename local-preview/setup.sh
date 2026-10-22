#!/usr/bin/env bash
# =============================================================================
# Zero-Docker Local Preview Runner for AHSOBC (/ and /gallery/)
#
# Provisions the local SQLite archive catalog (.omeka-runtime/ahsobc_archive.sqlite),
# validates the staging CSV manifest, and starts PHP's built-in server on port 8890.
# =============================================================================
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${PORT:-8890}"
HOST="${HOST:-127.0.0.1}"

cd "$REPO_ROOT"

echo "== 1/3 Validating staging manifest (omeka/staging/sample_manifest.csv) =="
php omeka/scripts/validate_staging.php

echo "== 2/3 Provisioning local Omeka S Centennial schema & seed corpus =="
php omeka/scripts/provision_schema.php

echo "== 3/3 Starting AHSOBC local preview server =="
echo "   Main Site Placeholder : http://${HOST}:${PORT}/"
echo "   Centennial Gallery    : http://${HOST}:${PORT}/gallery/"
echo "   Health Check          : http://${HOST}:${PORT}/api/health.php"
exec php -S "${HOST}:${PORT}" -t public
