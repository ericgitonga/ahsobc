#!/usr/bin/env bash
# =============================================================================
# User-Level Automated Daily Backup Script for AHSOBC Centennial Archive
# Implements Phase 5.1 & Phase 5.2 of OmekaS.pdf (Hardened per extras/improvement.pdf)
#
# Security Hardening:
#   Uses --defaults-extra-file="$HOME/.my.cnf.ahsobc" (chmod 0600) rather than
#   passing -p'[Password]' on the CLI command line where it would be visible in `ps aux`.
#
# Angryhosting Cron Schedule (crontab -e or Control Panel > Advanced > Cron Jobs):
#   0 2 * * *    /bin/bash $HOME/scripts/backup_alumni_archive.sh
#   */10 * * * * /usr/bin/php $HOME/ahsobc.or.ke/www/html/gallery/application/scripts/perform-job.php
#   0 4 1 * *    /usr/bin/php $HOME/ahsobc.or.ke/www/html/gallery/application/scripts/clear-cache.php
# =============================================================================
set -euo pipefail

BACKUP_DIR="${AHSOBC_BACKUP_DIR:-$HOME/backups}"
WEBROOT_GALLERY="${AHSOBC_GALLERY_DIR:-$HOME/ahsobc.or.ke/www/html/gallery}"
MY_CNF="${AHSOBC_MY_CNF:-$HOME/.my.cnf.ahsobc}"
DB_NAME="${AHSOBC_DB_NAME:-egimba_ahs}"
RETENTION_DAYS="${AHSOBC_RETENTION_DAYS:-14}"
DATE="$(date +"%Y%m%d_%H%M%S")"

umask 0077
mkdir -p "$BACKUP_DIR"

# 1. Verify ~/.my.cnf.ahsobc permissions and dump MySQL database egimba_ahs
if [ -f "$MY_CNF" ]; then
    chmod 0600 "$MY_CNF"
    mysqldump --defaults-extra-file="$MY_CNF" \
        --single-transaction \
        --quick \
        --routines \
        "$DB_NAME" | gzip -9 > "${BACKUP_DIR}/db_${DATE}.sql.gz"
    echo "[OK] MySQL dump written to ${BACKUP_DIR}/db_${DATE}.sql.gz"
else
    echo "[WARN] Credentials file $MY_CNF not found; skipping MySQL dump." >&2
fi

# 2. Archive uploaded media and IIIF derivatives in gallery/files/
if [ -d "${WEBROOT_GALLERY}/files" ]; then
    tar -czf "${BACKUP_DIR}/files_${DATE}.tar.gz" -C "$WEBROOT_GALLERY" files/
    echo "[OK] Media archive written to ${BACKUP_DIR}/files_${DATE}.tar.gz"
else
    echo "[WARN] Gallery files directory ${WEBROOT_GALLERY}/files not found; skipping media archive." >&2
fi

# 3. Purge archives older than 14 days to conserve Angryhosting disk quota
find "$BACKUP_DIR" -type f -name "*.tar.gz" -mtime +"$RETENTION_DAYS" -delete
find "$BACKUP_DIR" -type f -name "*.sql.gz" -mtime +"$RETENTION_DAYS" -delete
echo "[OK] Pruned backups older than ${RETENTION_DAYS} days in ${BACKUP_DIR}."
