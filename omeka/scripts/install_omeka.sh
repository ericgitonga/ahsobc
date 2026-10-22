#!/usr/bin/env bash
# =============================================================================
# Omeka S v4.1.1 & Archival Modules Installer for AHSOBC (/gallery/)
# Implements Phase 2.1, Phase 2.2, and Phase 3.2 of OmekaS.pdf
#
# Usage:
#   ./omeka/scripts/install_omeka.sh [TARGET_GALLERY_DIR]
#   Default TARGET_GALLERY_DIR: ~/ahsobc.or.ke/www/html/gallery
# =============================================================================
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LOCKFILE="$REPO_ROOT/omeka/modules.lock.json"
TARGET_DIR="${1:-$HOME/ahsobc.or.ke/www/html/gallery}"

echo "== AHSOBC Omeka S v4.1.1 Installer =="
echo "Target gallery directory: $TARGET_DIR"

mkdir -p "$TARGET_DIR"
TMP_WORK="$(mktemp -d)"
trap 'rm -rf "$TMP_WORK"' EXIT

# 1. Download & unpack Omeka S v4.1.1 core if not already present
if [ ! -f "$TARGET_DIR/application/Module.php" ]; then
    OMEKA_URL="https://github.com/omeka/omeka-s/releases/download/v4.1.1/omeka-s-4.1.1.zip"
    echo "-- Downloading Omeka S v4.1.1 from $OMEKA_URL --"
    curl -fsSL --proto '=https' --tlsv1.2 "$OMEKA_URL" -o "$TMP_WORK/omeka-s.zip"
    unzip -q "$TMP_WORK/omeka-s.zip" -d "$TMP_WORK/unpacked"
    cp -r "$TMP_WORK/unpacked/omeka-s/." "$TARGET_DIR/"
else
    echo "-- Omeka S v4.1.1 core already unpacked in $TARGET_DIR --"
fi

# 2. Install the 8 Essential Archival Modules in dependency order (Phase 3.2)
mkdir -p "$TARGET_DIR/modules"

install_module() {
    local name="$1"
    local zip_url="$2"
    local repo_url="$3"
    local dest="$TARGET_DIR/modules/$name"

    if [ -d "$dest" ] && [ -f "$dest/config/module.ini" ]; then
        echo "-- Module '$name' already installed --"
        return 0
    fi

    echo "-- Installing module '$name' --"
    if curl -fsSL --proto '=https' --tlsv1.2 "$zip_url" -o "$TMP_WORK/${name}.zip" 2>/dev/null; then
        mkdir -p "$TMP_WORK/mod_${name}"
        unzip -q "$TMP_WORK/${name}.zip" -d "$TMP_WORK/mod_${name}"
        local inner
        inner="$(find "$TMP_WORK/mod_${name}" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
        if [ -n "$inner" ]; then
            cp -r "$inner" "$dest"
        fi
    else
        echo "   Release zip unavailable; falling back to shallow git clone for $name"
        git clone --depth 1 "$repo_url" "$dest"
    fi
}

install_module "Common"      "https://github.com/Daniel-KM/Omeka-S-module-Common/releases/download/3.4.62/Common-3.4.62.zip"           "https://github.com/Daniel-KM/Omeka-S-module-Common.git"
install_module "CustomVocab" "https://github.com/omeka-s-modules/CustomVocab/releases/download/v2.0.2/CustomVocab-2.0.2.zip"          "https://github.com/omeka-s-modules/CustomVocab.git"
install_module "IiifServer"  "https://github.com/Daniel-KM/Omeka-S-module-IiifServer/releases/download/3.6.21/IiifServer-3.6.21.zip"   "https://github.com/Daniel-KM/Omeka-S-module-IiifServer.git"
install_module "ImageServer" "https://github.com/Daniel-KM/Omeka-S-module-ImageServer/releases/download/3.6.19/ImageServer-3.6.19.zip" "https://github.com/Daniel-KM/Omeka-S-module-ImageServer.git"
install_module "Mirador"     "https://github.com/Daniel-KM/Omeka-S-module-Mirador/releases/download/3.4.8/Mirador-3.4.8.zip"         "https://github.com/Daniel-KM/Omeka-S-module-Mirador.git"
install_module "CSVImport"   "https://github.com/omeka-s-modules/CSVImport/releases/download/v2.6.1/CSVImport-2.6.1.zip"              "https://github.com/omeka-s-modules/CSVImport.git"
install_module "Mapping"     "https://github.com/omeka-s-modules/Mapping/releases/download/v2.1.0/Mapping-2.1.0.zip"                  "https://github.com/omeka-s-modules/Mapping.git"
install_module "Collecting"  "https://github.com/omeka-s-modules/Collecting/releases/download/v1.11.0/Collecting-1.11.0.zip"          "https://github.com/omeka-s-modules/Collecting.git"

# 3. Install custom AHSOBC Centennial theme
mkdir -p "$TARGET_DIR/themes"
cp -r "$REPO_ROOT/omeka/themes/ahsobc-centennial" "$TARGET_DIR/themes/"

# 4. Configure .htaccess, local.config.php, database.ini, and files/staging/
cp "$REPO_ROOT/omeka/config/.htaccess.gallery" "$TARGET_DIR/.htaccess"
cp "$REPO_ROOT/omeka/config/local.config.php.dist" "$TARGET_DIR/config/local.config.php"
if [ ! -f "$TARGET_DIR/config/database.ini" ]; then
    cp "$REPO_ROOT/omeka/config/database.ini.example" "$TARGET_DIR/config/database.ini"
    chmod 0600 "$TARGET_DIR/config/database.ini"
    echo "-- Created $TARGET_DIR/config/database.ini (update password before connecting to MySQL) --"
fi

mkdir -p "$TARGET_DIR/files/staging" "$TARGET_DIR/files/original" "$TARGET_DIR/files/large" "$TARGET_DIR/files/medium" "$TARGET_DIR/files/square" "$TARGET_DIR/files/tile"
cp "$REPO_ROOT/omeka/config/.htaccess.files" "$TARGET_DIR/files/.htaccess"
cp "$REPO_ROOT/omeka/staging/sample_manifest.csv" "$TARGET_DIR/files/staging/sample_manifest.csv"

# 5. Apply hardened permissions (755 dirs, 644 files; writable files/ for derivatives)
find "$TARGET_DIR" -type d -exec chmod 0755 {} +
find "$TARGET_DIR" -type f -exec chmod 0644 {} +
chmod -R 0775 "$TARGET_DIR/files"
if [ -f "$TARGET_DIR/config/database.ini" ]; then
    chmod 0600 "$TARGET_DIR/config/database.ini"
fi

echo "== Omeka S v4.1.1 deployment complete in $TARGET_DIR =="
