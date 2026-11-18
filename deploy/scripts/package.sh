#!/usr/bin/env bash
# Prépare l'archive de déploiement depuis le poste de développement (Git Bash sous Windows, ou Linux).
#
#   bash deploy/scripts/package.sh            → dist/eftp-nonformel-<date>-<commit>.tar.gz
#
# Le contenu provient du DERNIER COMMIT (git archive) : committer d'abord les modifications à déployer.
# Exclus : vendor/ (réinstallé sur le serveur sans les paquets de développement), storage/ (données du poste :
# clé, identifiants de démonstration, exports), tests, application mobile, documentation, APK, fichiers locaux.
set -euo pipefail

ROOT="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
cd "$ROOT"

if [[ -n "$(git status --porcelain)" ]]; then
  echo "ATTENTION : modifications non committées — elles ne seront PAS dans l'archive (git archive HEAD)." >&2
fi

COMMIT="$(git rev-parse --short HEAD)"
NAME="eftp-nonformel-$(date +%Y%m%d-%H%M)-$COMMIT"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

git archive --format=tar HEAD \
  backend database shared scripts deploy renacom.xlsx listes_metiers.xlsx \
  | tar -x -C "$TMP"

rm -rf "$TMP/backend/vendor" "$TMP/backend/storage" "$TMP/backend/tests" "$TMP/backend/config/local.php"
echo "$COMMIT" > "$TMP/REVISION"

mkdir -p dist
tar -czf "dist/$NAME.tar.gz" -C "$TMP" .
echo "Archive prête : dist/$NAME.tar.gz ($(du -h "dist/$NAME.tar.gz" | cut -f1))"
echo "Envoi :   scp dist/$NAME.tar.gz <utilisateur>@<ip-du-vps>:/tmp/"
echo "Serveur : sudo bash /var/www/eftpnonformel/current/deploy/scripts/deploy-release.sh /tmp/$NAME.tar.gz"
