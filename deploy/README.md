# Déploiement en production — https://eftpnonformel.ins.ne

Mise en service de la plateforme EFTP non formel (API, back-office web, distribution de l'APK) sur un VPS, et compilation de l'application mobile qui dialogue avec ce serveur.

> **Hébergement géré (cPanel, Plesk, mutualisé) sans accès root**, ou **erreur à la restauration d'une base** (`#1227 … SUPER privilege` à l'import, puis `1364 Field 'id' doesn't have a default value` à la connexion) : voir **[DEPLOIEMENT-HEBERGEMENT.md](DEPLOIEMENT-HEBERGEMENT.md)**.

| Élément | Adresse |
|---|---|
| Back-office web | `https://eftpnonformel.ins.ne/` (redirigé vers `/app/`) |
| API (web et tablettes) | `https://eftpnonformel.ins.ne/api/v1` |
| Test de santé | `https://eftpnonformel.ins.ne/api/v1/health` |
| Adresse compilée dans l'APK | `https://eftpnonformel.ins.ne/api/v1` (`mobile/lib/src/app_config.dart`, valeur par défaut) |

## 1. Architecture cible

- **Système** : Ubuntu 24.04 LTS (2 vCPU, 4 Go de RAM et 40 Go de disque conseillés).
- **Pile** : Apache 2.4 (mpm_event, HTTP/2) + PHP 8.3-FPM (pool dédié) + MariaDB 10.11 + Let's Encrypt.
- **Racine web** : `backend/public/` uniquement. Le reste du projet (configuration, stockage, migrations) n'est jamais exposé. Les règles `.htaccess` versionnées (réécriture vers l'API, CSP, HSTS, en-têtes de sécurité) restent actives (`AllowOverride All`).
- **Arborescence sur le serveur** :

```
/var/www/eftpnonformel/
├── current -> releases/20261118143000      version en ligne (lien basculé atomiquement)
├── releases/                               5 dernières versions (retour arrière immédiat)
│   └── 20261118143000/
│       ├── backend/  (config/local.php -> ../../shared/local.php, storage -> ../../shared/storage)
│       ├── database/  shared/  scripts/  deploy/
│       └── renacom.xlsx  listes_metiers.xlsx
└── shared/                                 conservé d'une version à l'autre
    ├── local.php                           configuration de production (secrets) — 0640 root:www-data
    └── storage/                            app.key, imports, modèles, APK publiés, exports, journaux — www-data
```

- **Tâches planifiées** (`/etc/cron.d/eftp-nonformel`) : file des exports chaque minute (`bin/worker.php --once`), sauvegarde quotidienne à 02:30, purge des journaux de plus de 90 jours.

## 2. Contenu de ce dossier

| Fichier | Rôle | Destination sur le serveur |
|---|---|---|
| `local.php.example` | Configuration de production (prod, débogage coupé, cookie `Secure`, exports en tâche de fond) | `shared/local.php` (généré par `install-server.sh`) |
| `apache/eftpnonformel-http.conf` | Port 80 : défi Let's Encrypt et redirection 301 vers HTTPS | `/etc/apache2/sites-available/` |
| `apache/eftpnonformel-https.conf` | Port 443 : TLS 1.2/1.3, racine `current/backend/public`, PHP-FPM, envoi ≤ 300 Mo | `/etc/apache2/sites-available/` |
| `php/eftp-fpm-pool.conf` | Pool PHP-FPM (mémoire 256 Mo, envoi 310 Mo, `open_basedir`) | `/etc/php/8.3/fpm/pool.d/eftp.conf` |
| `php/99-eftp.ini` | Fuseau Africa/Niamey, OPcache | `/etc/php/8.3/{fpm,cli}/conf.d/` |
| `mysql/90-eftp.cnf` | utf8mb4, mode SQL strict, écoute locale uniquement, UTC+1 | `/etc/mysql/mariadb.conf.d/` |
| `cron/eftp-nonformel` | Worker des exports, sauvegarde, purge | `/etc/cron.d/` |
| `logrotate/eftp-nonformel` | Rotation des journaux | `/etc/logrotate.d/` |
| `scripts/package.sh` | **Poste de développement** : fabrique l'archive à déployer | — |
| `scripts/install-server.sh` | **VPS, une fois** : paquets, base, configuration, certificat, pare-feu, cron | — |
| `scripts/deploy-release.sh` | **VPS, à chaque version** : déploiement, migrations, bascule, contrôle ; `--rollback` | — |
| `scripts/backup.sh` | Sauvegarde base + stockage (cron), sans clauses `DEFINER` | — |
| `scripts/package-zip.ps1` | **Poste Windows** : paquet ZIP complet (avec `vendor/`) pour un hébergement géré | — |
| `hebergement/local.php.example` | Configuration de production pour un hébergement géré | `backend/config/local.php` |
| `hebergement/racine-public_html.htaccess` | Si la racine web ne peut pas pointer sur `backend/public` | racine web |
| `DEPLOIEMENT-HEBERGEMENT.md` | Guide hébergement géré ; réparation d'une base mal importée | — |
| `../backend/bin/sanitize-dump.php` | Prépare une sauvegarde SQL pour un autre serveur (retire `DEFINER`, option `--drop`) | — |
| `mobile/key.properties.example` | Modèle de configuration de la clé de signature Android | `mobile/android/key.properties` (poste) |
| `mobile/build-apk.ps1` | Compilation de l'APK de production signé | — |

## 3. Prérequis

1. **DNS** : enregistrement `A` `eftpnonformel.ins.ne` → IP publique du VPS (et `AAAA` si IPv6). Vérifier : `nslookup eftpnonformel.ins.ne`.
2. **Accès** : SSH avec un compte `sudo` (clé SSH conseillée, connexion root par mot de passe désactivée).
3. **Ports** 80 et 443 ouverts chez l'hébergeur (le pare-feu local `ufw` est configuré par le script).
4. **Adresse e-mail** pour Let's Encrypt (alertes d'expiration).
5. Sur le poste : Git Bash (fourni avec Git pour Windows) et tout le code à déployer **committé**.

## 4. Première installation

### 4.1 Sur le poste de développement

```bash
cd /c/xampp8/htdocs/collecte_non_formel
git status                                # rien à committer
bash deploy/scripts/package.sh            # → dist/eftp-nonformel-AAAAMMJJ-HHMM-<commit>.tar.gz
scp dist/eftp-nonformel-*.tar.gz utilisateur@IP_DU_VPS:/tmp/
```

L'archive ne contient ni `vendor/` (réinstallé sur le serveur sans PHPUnit), ni `backend/storage/` (la clé, les identifiants de démonstration et les exports du poste ne partent pas en production), ni les tests, l'application mobile, la documentation et l'APK.

### 4.2 Sur le VPS

```bash
mkdir -p /tmp/eftp-pkg && tar -xzf /tmp/eftp-nonformel-*.tar.gz -C /tmp/eftp-pkg
sudo bash /tmp/eftp-pkg/deploy/scripts/install-server.sh --email=adresse@ins.ne
sudo bash /tmp/eftp-pkg/deploy/scripts/deploy-release.sh /tmp/eftp-nonformel-*.tar.gz
```

`install-server.sh` génère un mot de passe de base et une clé applicative aléatoires dans `/var/www/eftpnonformel/shared/local.php`. `deploy-release.sh` applique les migrations et charge les référentiels (`bin/seed.php` : rôles, territoire RENACOM, métiers, versions 2025.1 à 2025.3 du questionnaire), puis lance `bin/check.php`, qui doit se terminer par **0 bloquant**.

## 5. Créer l'administrateur national

```bash
cd /var/www/eftpnonformel/current/backend
sudo -u www-data php bin/create-admin.php --matricule=ADM001 --nom=NOM --prenom=Prénom --sexe=M --telephone=9XXXXXXX
```

Le mot de passe provisoire s'affiche une seule fois ; il doit être changé à la première connexion sur `https://eftpnonformel.ins.ne/`.

> **Ne jamais lancer `bin/seed-demo.php` en production** (il refuse de toute façon de s'exécuter en mode `prod`). `migrate.php --fresh` est également interdit.

## 6. Vérifications après mise en service

```bash
curl -s https://eftpnonformel.ins.ne/api/v1/health          # {"data":{"status":"ok","database":true,…}}
curl -sI http://eftpnonformel.ins.ne/ | head -3              # 301 vers https://
curl -sI https://eftpnonformel.ins.ne/app/ | grep -iE 'strict-transport|content-security'
curl -s -o /dev/null -w '%{http_code}\n' https://eftpnonformel.ins.ne/config/config.php   # 404 : rien hors de public/
sudo -u www-data php /var/www/eftpnonformel/current/backend/bin/check.php
```

Puis, dans le back-office : connexion de l'administrateur, création de l'année et de la campagne, import des agents, **un export Excel** (vérifie la tâche planifiée du worker : `tail -f /var/www/eftpnonformel/shared/storage/logs/worker.log`).

## 7. Application mobile

### 7.1 Adresse du serveur

`mobile/lib/src/app_config.dart` a pour valeur par défaut `https://eftpnonformel.ins.ne/api/v1` : un `flutter build apk --release` sans paramètre vise donc la production. Le lien de mise à jour renvoyé par le serveur (`download_path`, ex. `/app/releases/3/download?…`) est concaténé à cette adresse.

`android/app/src/main/res/xml/network_security_config.xml` n'autorise plus que le HTTPS. Les exceptions HTTP de développement (`10.0.2.2`, `localhost`) sont déplacées dans `android/app/src/debug/res/xml/` et n'existent que dans le build de débogage :

```bash
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8085/non_formel/backend/public/api/v1   # développement
```

### 7.2 Clé de signature de production (une seule fois)

```powershell
keytool -genkey -v -keystore C:\cles\eftpnf-release.jks -keyalg RSA -keysize 2048 -validity 10000 -alias eftpnf
copy deploy\mobile\key.properties.example mobile\android\key.properties   # puis renseigner les mots de passe
```

**Sauvegarder le fichier `.jks` et ses mots de passe en deux lieux sûrs.** Toutes les versions doivent être signées avec cette clé : sinon Android refuse la mise à jour, et désinstaller l'application effacerait les collectes non envoyées. Sans `key.properties`, `build.gradle.kts` signe avec la clé de débogage et affiche un avertissement (APK de test uniquement).

> L'APK présent à la racine du dépôt (`app-release.apk`) est signé avec la clé de débogage : **ne pas le diffuser** aux tablettes de terrain.

### 7.3 Compiler et publier

```powershell
powershell -ExecutionPolicy Bypass -File deploy\mobile\build-apk.ps1    # → dist\eftp-collecte-<version>.apk
```

Le script contrôle la clé et l'alignement des versions (`pubspec.yaml` / `AppConfig.appVersion`), lance les tests, compile, et affiche l'empreinte SHA-256. Publier ensuite l'APK dans le back-office (**Paramètres › Application mobile**) : les tablettes le détectent à leur synchronisation suivante. Pour chaque nouvelle version, augmenter `version:` dans `pubspec.yaml` (ex. `1.0.1+2`) et `appVersion` dans `app_config.dart`.

## 8. Mises à jour

```bash
# poste
git commit … && bash deploy/scripts/package.sh && scp dist/eftp-nonformel-<…>.tar.gz utilisateur@IP:/tmp/
# VPS
sudo bash /var/www/eftpnonformel/current/deploy/scripts/deploy-release.sh /tmp/eftp-nonformel-<…>.tar.gz
```

Avant chaque migration, la base est sauvegardée dans `/var/backups/eftp/predeploy-<version>.sql.gz`. Retour arrière du code :

```bash
sudo bash /var/www/eftpnonformel/current/deploy/scripts/deploy-release.sh --rollback
```

Le retour arrière n'annule pas une migration : si nécessaire, restaurer la sauvegarde `predeploy-*` (`gunzip -c … | mysql eftp_nf`).

## 9. Sauvegardes et restauration

- Quotidienne : `/var/backups/eftp/db-*.sql.gz` (base) et `storage-*.tar.gz` (imports, APK publiés, `app.key`, `local.php`) ; 14 jours.
- **Copier ces fichiers hors du VPS** (autre serveur, stockage de l'INS) : à organiser selon les moyens disponibles.
- Restauration : `gunzip -c db-….sql.gz | sudo mysql eftp_nf` puis `sudo tar -xzf storage-….tar.gz -C /var/www/eftpnonformel/shared`.

- Base venant d'**un autre serveur** (export phpMyAdmin du poste, par exemple) : la préparer d'abord avec `php backend/bin/sanitize-dump.php export.sql pret.sql --drop`. Sinon l'import s'arrête aux vues (#1227) et laisse des tables sans clé primaire (#1364 à la connexion). Détails : [DEPLOIEMENT-HEBERGEMENT.md](DEPLOIEMENT-HEBERGEMENT.md) §1.

## 10. Exploitation courante

| Besoin | Commande |
|---|---|
| Journaux de l'application | `/var/www/eftpnonformel/shared/storage/logs/app-AAAA-MM-JJ.log` |
| Erreurs PHP | `/var/log/php8.3-fpm-eftp.log` |
| Accès / erreurs Apache | `/var/log/apache2/eftpnonformel-ssl-*.log` |
| Worker des exports | `/var/www/eftpnonformel/shared/storage/logs/worker.log` |
| Renouvellement du certificat | automatique (`certbot.timer`) ; test : `sudo certbot renew --dry-run` |
| Version minimale de l'application | `mobile.min_app_version` dans `shared/local.php`, ou version « obligatoire » publiée depuis le back-office |

## 11. Points à traiter avant l'ouverture au public

- **Identifiant Android** provisoire `ne.eftpnf.eftp_nf_collecte` : le remplacer **avant la première diffusion** si un identifiant officiel est retenu (impossible ensuite sans réinstaller les tablettes).
- **Fonds de carte** : vérifier les conditions d'utilisation des tuiles Esri et OpenStreetMap (docs/api §4.5).
- **Test de charge** réel (lot L8) et essai de l'APK sur tablette avec ce serveur.
- Activer le **verrouillage d'écran Android** des tablettes (docs/mobile).
- Dépôt Git : `backend/storage/` (dont `app.key` et `demo-identifiants.txt`) est versionné dans l'historique ; il n'est pas déployé, et la production utilise sa propre clé (`shared/local.php`). Voir le `.gitignore` ajouté à la racine.
