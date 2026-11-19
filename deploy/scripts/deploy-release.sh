#!/usr/bin/env bash
# Déploiement d'une version (première mise en service comme mises à jour) — en root sur le VPS.
#
#   sudo bash deploy-release.sh /tmp/eftp-nonformel-AAAAMMJJ-HHMM-<commit>.tar.gz
#   sudo bash deploy-release.sh --rollback      # revient à la version précédente (code seulement, pas la base)
#
# Étapes : décompression dans releases/<horodatage>, liens vers shared/ (stockage, local.php), dépendances PHP
# sans paquets de développement, sauvegarde de la base, migrations, données de référence (idempotent),
# bascule atomique du lien « current », rechargement de PHP-FPM, contrôle bin/check.php. Garde 5 versions.
set -euo pipefail

APP_DIR="/var/www/eftpnonformel"
PHP_VER="8.3"
KEEP=5
[[ $EUID -eq 0 ]] || { echo "À lancer en root (sudo)." >&2; exit 1; }
step() { echo; echo "==> $*"; }
as_www() { sudo -u www-data -- "$@"; }

switch_to() {
  ln -sfn "$1" "$APP_DIR/current.tmp"
  mv -T "$APP_DIR/current.tmp" "$APP_DIR/current"
  systemctl reload "php$PHP_VER-fpm"
  systemctl reload apache2
}

if [[ "${1:-}" == "--rollback" ]]; then
  CUR="$(readlink -f "$APP_DIR/current")"
  PREV="$(ls -1d "$APP_DIR"/releases/*/ | sed 's#/$##' | sort | grep -B1 -x "$CUR" | head -n1)"
  [[ -n "$PREV" && "$PREV" != "$CUR" ]] || { echo "Aucune version précédente." >&2; exit 1; }
  switch_to "$PREV"
  echo "Retour à $(basename "$PREV"). ATTENTION : les migrations de base déjà appliquées ne sont pas annulées"
  echo "(restaurer au besoin la sauvegarde /var/backups/eftp/predeploy-*.sql.gz)."
  exit 0
fi

ARCHIVE="${1:?Usage : $0 <archive.tar.gz> | --rollback}"
[[ -f "$ARCHIVE" ]] || { echo "Archive introuvable : $ARCHIVE" >&2; exit 1; }
[[ -f "$APP_DIR/shared/local.php" ]] || { echo "shared/local.php absent : lancer d'abord install-server.sh" >&2; exit 1; }

REL="$APP_DIR/releases/$(date +%Y%m%d%H%M%S)"
step "Décompression dans $REL"
mkdir -p "$REL"
tar -xzf "$ARCHIVE" -C "$REL"

step "Liens vers les données persistantes (shared/)"
rm -rf "$REL/backend/storage"
ln -s "$APP_DIR/shared/storage" "$REL/backend/storage"
ln -sfn "$APP_DIR/shared/local.php" "$REL/backend/config/local.php"

step "Dépendances PHP (sans paquets de développement)"
COMPOSER_ALLOW_SUPERUSER=1 composer install -d "$REL/backend" --no-dev --optimize-autoloader --no-interaction --no-progress

step "Droits (code en lecture seule pour www-data)"
chown -R root:www-data "$REL"
find "$REL" -type d -exec chmod 750 {} +
find "$REL" -type f -exec chmod 640 {} +
chmod 750 "$REL"/deploy/scripts/*.sh

step "Sauvegarde de la base avant migration"
mkdir -p /var/backups/eftp
DBNAME="$(php -r '$c = require "'"$APP_DIR"'/shared/local.php"; echo $c["db"]["name"];')"
mysqldump --single-transaction --quick --routines "$DBNAME" | sed -E 's/ DEFINER=`[^`]+`@`[^`]+`//g' | gzip > "/var/backups/eftp/predeploy-$(basename "$REL").sql.gz"

step "Migrations et données de référence"
as_www php "$REL/backend/bin/migrate.php"
as_www php "$REL/backend/bin/seed.php"

step "Bascule de la version courante"
switch_to "$REL"

step "Contrôle de mise en service"
as_www php "$REL/backend/bin/check.php" || echo "!! check.php signale un point bloquant (voir ci-dessus)."
curl -fsS "https://eftpnonformel.ins.ne/api/v1/health" && echo || echo "!! /api/v1/health ne répond pas."

step "Nettoyage (conserve les $KEEP dernières versions)"
ls -1d "$APP_DIR"/releases/*/ | sort | head -n -"$KEEP" | xargs -r rm -rf

echo
echo "Version $(cat "$REL/REVISION" 2>/dev/null || basename "$REL") en ligne : https://eftpnonformel.ins.ne/"
