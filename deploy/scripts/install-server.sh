#!/usr/bin/env bash
# Préparation initiale du VPS (une seule fois) — Ubuntu 24.04 LTS, en root.
#
#   tar -xzf eftp-nonformel-*.tar.gz -C /tmp/eftp-pkg
#   sudo bash /tmp/eftp-pkg/deploy/scripts/install-server.sh --email=adresse@exemple.ne
#
# Installe Apache + PHP 8.3-FPM + MariaDB, crée la base et son compte, la configuration de production
# (shared/local.php avec mot de passe et clé aléatoires), obtient le certificat Let's Encrypt, ouvre le pare-feu,
# installe les tâches planifiées. Rejouable : ce qui existe déjà est conservé (base, local.php, certificat).
#
# Prérequis : l'enregistrement DNS A de eftpnonformel.ins.ne pointe déjà sur l'IP publique du VPS.
set -euo pipefail

DOMAIN="eftpnonformel.ins.ne"
APP_DIR="/var/www/eftpnonformel"
PHP_VER="8.3"
DB_NAME="eftp_nf"
DB_USER="eftp_nf"
EMAIL=""
for arg in "$@"; do
  case "$arg" in
    --email=*) EMAIL="${arg#*=}" ;;
    *) echo "Option inconnue : $arg" >&2; exit 2 ;;
  esac
done
[[ $EUID -eq 0 ]] || { echo "À lancer en root (sudo)." >&2; exit 1; }
[[ -n "$EMAIL" ]] || { echo "Usage : $0 --email=adresse-pour-lets-encrypt" >&2; exit 2; }

PKG="$(cd "$(dirname "$0")/../.." && pwd)"   # racine de l'archive décompressée
DEPLOY="$PKG/deploy"
step() { echo; echo "==> $*"; }

step "Paquets système"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q apache2 mariadb-server certbot ufw unzip git composer \
  "php$PHP_VER-fpm" "php$PHP_VER-cli" "php$PHP_VER-mysql" "php$PHP_VER-mbstring" "php$PHP_VER-xml" \
  "php$PHP_VER-zip" "php$PHP_VER-gd" "php$PHP_VER-intl" "php$PHP_VER-curl" "php$PHP_VER-opcache"

step "Arborescence $APP_DIR"
mkdir -p "$APP_DIR/releases" /var/www/letsencrypt/.well-known/acme-challenge /var/backups/eftp
for d in "" exports imports templates releases logs; do mkdir -p "$APP_DIR/shared/storage/$d"; done
chown -R www-data:www-data "$APP_DIR/shared/storage"
chmod -R u=rwX,g=rwX,o= "$APP_DIR/shared/storage"
chmod 700 /var/backups/eftp

step "MariaDB"
install -m 644 "$DEPLOY/mysql/90-eftp.cnf" /etc/mysql/mariadb.conf.d/90-eftp.cnf
systemctl restart mariadb
if [[ ! -f "$APP_DIR/shared/local.php" ]]; then
  DB_PASS="$(openssl rand -hex 24)"
  APP_KEY="$(openssl rand -hex 32)"
  mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
  sed -e "s/__DB_PASS__/$DB_PASS/" -e "s/__APP_KEY__/$APP_KEY/" "$DEPLOY/local.php.example" > "$APP_DIR/shared/local.php"
  echo "   shared/local.php créé (mot de passe de base et clé applicative aléatoires)."
else
  echo "   shared/local.php existe déjà : conservé."
fi
chown root:www-data "$APP_DIR/shared/local.php"
chmod 640 "$APP_DIR/shared/local.php"

step "PHP $PHP_VER-FPM"
install -m 644 "$DEPLOY/php/99-eftp.ini" "/etc/php/$PHP_VER/fpm/conf.d/99-eftp.ini"
install -m 644 "$DEPLOY/php/99-eftp.ini" "/etc/php/$PHP_VER/cli/conf.d/99-eftp.ini"
install -m 644 "$DEPLOY/php/eftp-fpm-pool.conf" "/etc/php/$PHP_VER/fpm/pool.d/eftp.conf"
systemctl restart "php$PHP_VER-fpm"

step "Apache"
a2dismod -q mpm_prefork "php$PHP_VER" 2>/dev/null || true
a2enmod -q mpm_event proxy_fcgi setenvif rewrite headers ssl http2 deflate
cat > /etc/apache2/conf-available/eftp-security.conf <<'CONF'
ServerTokens Prod
ServerSignature Off
TraceEnable Off
CONF
a2enconf -q eftp-security
install -m 644 "$DEPLOY/apache/eftpnonformel-http.conf" /etc/apache2/sites-available/eftpnonformel-http.conf
install -m 644 "$DEPLOY/apache/eftpnonformel-https.conf" /etc/apache2/sites-available/eftpnonformel-https.conf
a2dissite -q 000-default 2>/dev/null || true
a2ensite -q eftpnonformel-http
apachectl configtest
systemctl reload apache2

step "Pare-feu (SSH, HTTP, HTTPS)"
ufw allow OpenSSH >/dev/null
ufw allow 'Apache Full' >/dev/null
ufw --force enable

step "Certificat Let's Encrypt pour $DOMAIN"
if [[ ! -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]]; then
  certbot certonly --webroot -w /var/www/letsencrypt -d "$DOMAIN" --email "$EMAIL" --agree-tos --non-interactive
fi
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
cat > /etc/letsencrypt/renewal-hooks/deploy/reload-apache.sh <<'HOOK'
#!/bin/sh
systemctl reload apache2
HOOK
chmod 755 /etc/letsencrypt/renewal-hooks/deploy/reload-apache.sh
a2ensite -q eftpnonformel-https
apachectl configtest
systemctl reload apache2

step "Tâches planifiées et rotation des journaux"
install -m 644 "$DEPLOY/cron/eftp-nonformel" /etc/cron.d/eftp-nonformel
install -m 644 "$DEPLOY/logrotate/eftp-nonformel" /etc/logrotate.d/eftp-nonformel
touch /var/log/php$PHP_VER-fpm-eftp.log && chown www-data:www-data /var/log/php$PHP_VER-fpm-eftp.log

echo
echo "Serveur prêt. Étape suivante : déployer la première version :"
echo "   sudo bash $DEPLOY/scripts/deploy-release.sh <archive.tar.gz>"
echo "puis créer l'administrateur national (voir deploy/README.md §5)."
