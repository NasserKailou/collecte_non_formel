#!/usr/bin/env bash
# Sauvegarde quotidienne (cron 02:30, root) : base complète + stockage (imports, modèles, APK publiés, clé).
# Les exports (régénérables, purgés après 7 jours) ne sont pas sauvegardés. Conservation : 14 jours.
#   À COPIER RÉGULIÈREMENT HORS DU VPS (rsync/scp vers un autre serveur) : une sauvegarde sur la même machine
#   ne protège pas contre la perte du VPS.
set -euo pipefail

APP_DIR="/var/www/eftpnonformel"
DEST="/var/backups/eftp"
DAYS=14
STAMP="$(date +%Y%m%d-%H%M)"
mkdir -p "$DEST" && chmod 700 "$DEST"

DBNAME="$(php -r '$c = require "'"$APP_DIR"'/shared/local.php"; echo $c["db"]["name"];')"
mysqldump --single-transaction --quick --routines "$DBNAME" | gzip > "$DEST/db-$STAMP.sql.gz"
tar -czf "$DEST/storage-$STAMP.tar.gz" -C "$APP_DIR/shared" --exclude=storage/exports storage local.php

find "$DEST" -maxdepth 1 -type f \( -name 'db-*' -o -name 'storage-*' -o -name 'predeploy-*' \) -mtime +"$DAYS" -delete
echo "[$(date -Is)] sauvegarde OK : db-$STAMP.sql.gz, storage-$STAMP.tar.gz"
