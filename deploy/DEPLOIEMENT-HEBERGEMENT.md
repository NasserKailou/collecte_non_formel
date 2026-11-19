# Déploiement du backend sur un hébergement géré (cPanel, Plesk, mutualisé) — et réparation de la base

Ce guide complète [README.md](README.md), qui décrit l'installation sur un VPS dont on est administrateur (root). Il s'applique dès que l'on dispose seulement d'un **panneau d'hébergement** : gestionnaire de fichiers ou FTP, phpMyAdmin, éventuellement un terminal et des tâches cron.

> **Erreur rencontrée** : à la connexion, `PDOException : SQLSTATE[HY000]: General error: 1364 Field 'id' doesn't have a default value`, après un import phpMyAdmin interrompu par `#1227 - Access denied; you need (at least one of) the SUPER, SET USER privilege(s)`.
> La réparation est décrite au **§1**.

---

## 1. Réparer la base actuelle du serveur (erreurs #1227 puis #1364)

### Ce qui s'est passé

1. L'export phpMyAdmin de la base locale (XAMPP) contient deux vues créées par `root` : `CREATE … DEFINER=\`root\`@\`localhost\` … VIEW v_q2_1_apprentices`.
2. Sur le serveur, le compte de la base n'est pas `root` et n'a pas le privilège SUPER. MySQL refuse donc de créer une vue au nom d'un autre utilisateur (**#1227**), et phpMyAdmin **arrête l'import à cet endroit**.
3. Dans un export phpMyAdmin, tout ce qui suit les vues n'a donc pas été exécuté : **toutes les clés primaires, index, `AUTO_INCREMENT` et clés étrangères**. Les tables existent et contiennent les données, mais leur colonne `id` n'a plus ni clé ni auto-incrément.
4. À la connexion, l'API écrit dans `login_logs` sans fournir d'`id`, d'où l'erreur **#1364**. Toute autre écriture (jetons, collectes…) échouerait de la même façon.

Le problème vient donc du **fichier d'import**, pas du code déployé.

### Correction (5 minutes)

**a) Sur le poste Windows**, préparer un fichier importable (les clauses `DEFINER` sont retirées, et les tables cassées sont supprimées avant d'être recréées) :

```powershell
cd C:\xamppSites\htdocs\non_formel\backend
C:\xamppSites\php\php.exe bin\sanitize-dump.php "C:\Users\<vous>\Downloads\eftp_nf(1).sql" "C:\Users\<vous>\Downloads\eftp_nf_serveur.sql" --drop
```

Résultat attendu : `OK : … 51 table(s), 2 vue(s) ; 2 clause(s) DEFINER retirée(s) ; SQL SECURITY INVOKER : 2 ; suppression préalable des tables (--drop)`.

**b) Sur le serveur**, dans phpMyAdmin : sélectionner la base, onglet **Importer**, choisir `eftp_nf_serveur.sql`, puis **Exécuter**. L'option `--drop` permet d'importer directement par-dessus la base actuelle, sans la vider à la main.

> Fichier trop gros pour phpMyAdmin (limite d'envoi) ? Le compresser en `.zip` ou `.gz` (accepté par phpMyAdmin), ou utiliser le terminal : `mysql -u UTILISATEUR -p NOM_BASE < eftp_nf_serveur.sql`.

**c) Vérifier** dans l'onglet SQL de phpMyAdmin :

```sql
SHOW KEYS FROM login_logs WHERE Key_name = 'PRIMARY';     -- 1 ligne attendue
SELECT EXTRA FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'login_logs' AND column_name = 'id';   -- auto_increment
SELECT COUNT(*) FROM v_q2_1_apprentices;                    -- la vue répond
```

Puis se connecter au back-office : l'erreur a disparu.

> Testé le 18/11/2026 sur MariaDB 10.4 avec un compte sans privilège SUPER : l'import d'origine échoue à la ligne 4382 (#1227) et laisse `login_logs` sans clé primaire ; le fichier préparé s'importe par-dessus et rétablit la clé primaire, l'auto-incrément et la vue, et l'insertion dans `login_logs` fonctionne.

### Attention : données de démonstration

La base locale contient le **jeu de démonstration** (année « 2090-2091 DÉMONSTRATION », campagne `DEMO-CAMPAGNE`, structures `99000000xx`, comptes `DEMO-*` et `ADM-DEMO`). Si le serveur est celui de la **collecte réelle**, mieux vaut partir d'une base neuve (§5, option A) et créer le vrai administrateur (§6), plutôt que d'y restaurer la base du poste. La formation des agents sur tablette utilise le **mode Démo** de l'application mobile, qui n'envoie jamais rien au serveur (voir docs/mobile/README.md).

### Pour éviter que cela se reproduise

- Toujours passer une sauvegarde par `bin/sanitize-dump.php` avant de l'importer sur un autre serveur.
- Les sauvegardes du VPS (`deploy/scripts/backup.sh`) retirent désormais elles-mêmes les clauses `DEFINER` : elles se réimportent partout.
- Une base créée par `bin/migrate.php` (migrations du projet) n'a pas ce problème : les vues appartiennent au compte qui les crée.

---

## 2. Fabriquer le paquet à envoyer

Le backend **ne fonctionne pas** si l'on envoie `backend/` seul (comme `backend/backend.zip`). La configuration va chercher les migrations dans `../database/migrations` et les questionnaires dans `../shared/questionnaire`, et `bin/seed.php` lit `../renacom.xlsx` et `../listes_metiers.xlsx`.

Sur le poste Windows :

```powershell
cd C:\xamppSites\htdocs\non_formel
powershell -ExecutionPolicy Bypass -File deploy\scripts\package-zip.ps1
# → dist\eftp-nonformel-httpdocs-AAAAMMJJ-HHMM.zip   (à décompresser tel quel dans httpdocs/)
```

Contenu (= contenu de `httpdocs/`) : `.htaccess`, `backend/` (avec `vendor/`, `storage/` vide, `config/local.php.example`, sans tests), `database/`, `shared/`, les deux fichiers `.xlsx` et `REVISION`. Rien d'autre (ni application mobile, ni documentation).

## 3. Envoyer les fichiers et régler la racine web

Envoyer le ZIP par le gestionnaire de fichiers (ou FTP), puis le **décompresser sur le serveur**. Deux dispositions sont possibles :

### Option 1 (recommandée) : la racine du domaine pointe sur `backend/public`

```
/home/compte/eftp/                  ← ZIP décompressé ici, HORS de public_html
├── backend/
│   └── public/                     ← racine web du domaine ou du sous-domaine
├── database/  shared/  deploy/  renacom.xlsx  listes_metiers.xlsx
```

Dans le panneau (cPanel › *Domaines* ou *Sous-domaines* ; Plesk › *Hébergement et DNS › Paramètres d'hébergement*), régler la **racine du document** sur `/home/compte/eftp/backend/public`. Le `.htaccess` placé à la racine du ZIP n'est pas utile dans ce cas et peut être supprimé. `base_path` = `''`.

### Option 2 : impossible de changer la racine (tout dans `public_html`)

Décompresser le ZIP **directement dans `public_html`**. Le `.htaccess` fourni à la racine (copie de `deploy/hebergement/racine-public_html.htaccess`) redirige toutes les adresses vers `backend/public/` sans les changer, et rien d'autre n'est servi. `base_path` reste `''`. Vérifier ensuite le §7 (aucun fichier interne ne doit être lisible).

### Droits

- `backend/storage/` et ses sous-dossiers doivent être **inscriptibles** par PHP (755 suffit en général quand PHP s'exécute sous le compte de l'hébergement ; 775 sinon).
- `backend/config/local.php` : 640 (ou 600).

## 4. Configuration de production

Copier `deploy/hebergement/local.php.example` en **`backend/config/local.php`**, puis le compléter avec :

- le nom de la base, l'utilisateur et le mot de passe créés dans le panneau (*Bases de données MySQL*) ; donner au compte **tous les privilèges** sur la base (le privilège SUPER n'est pas nécessaire) ;
- une clé `app_key` : 64 caractères hexadécimaux aléatoires (`php -r "echo bin2hex(random_bytes(32));"` sur le poste), générée une fois puis conservée.

PHP : version **8.2 ou plus**, avec les extensions `pdo_mysql`, `mbstring`, `zip`, `gd`, `intl`, `xml` et `curl` (sélecteur de version PHP du panneau). Limites d'envoi recommandées : `upload_max_filesize` et `post_max_size` ≥ 300 M (publication de l'APK), `memory_limit` 256 M.

## 5. Base de données

### Option A : base neuve (production réelle, recommandée)

Il faut un **terminal** (cPanel › *Terminal*, ou SSH) :

```bash
cd ~/eftp/backend            # ou ~/public_html/backend (option 2)
php bin/migrate.php           # crée toutes les tables et les vues
php bin/seed.php              # rôles, territoire RENACOM, métiers, questionnaires 2025.1 à 2025.3
php bin/check.php             # doit finir par « 0 bloquant »
```

Sans terminal, chaque commande peut être lancée **une fois** par une tâche cron programmée dans la minute suivante (`php /home/compte/eftp/backend/bin/migrate.php > /home/compte/eftp/backend/storage/logs/migrate.txt 2>&1`). Supprimer ensuite la tâche et relire le fichier de sortie.

> En ligne de commande, `php` peut être une ancienne version. Dans ce cas, utiliser le chemin complet indiqué par l'hébergeur, par exemple `/opt/cpanel/ea-php83/root/usr/bin/php` ou `/opt/plesk/php/8.3/bin/php`.

### Option B : reprendre une base existante (poste ou autre serveur)

Exporter la base (phpMyAdmin › Exporter › méthode rapide, format SQL), la préparer avec `bin/sanitize-dump.php … --drop` (§1 a), puis l'importer (§1 b). La table `schema_migrations` est incluse : `bin/migrate.php` n'appliquera ensuite que les migrations plus récentes.

## 6. Créer l'administrateur national (base neuve)

```bash
php bin/create-admin.php --matricule=ADM001 --nom=NOM --prenom=Prénom --sexe=M --telephone=9XXXXXXX
```

Le mot de passe provisoire n'est affiché qu'une fois. Il doit être changé à la première connexion.

## 7. Vérifications

| Test | Résultat attendu |
|---|---|
| `https://domaine/api/v1/health` | `{"data":{"status":"ok","database":true,…}}` |
| `https://domaine/` | redirige vers `/app/` (back-office) |
| `https://domaine/backend/config/local.php`, `/database/migrations/001_schema.sql`, `/backend/storage/app.key`, `/renacom.xlsx` | **404** (jamais le contenu) |
| `http://domaine/` | redirige vers `https://` |
| Connexion au back-office | aucune erreur ; une ligne apparaît dans `login_logs` |
| Un export Excel | fichier téléchargé (sinon voir §8, tâche cron) |
| `php bin/check.php` | « 0 bloquant » |

## 8. Tâches planifiées (cron)

| Fréquence | Commande | Rôle |
|---|---|---|
| chaque minute | `php /home/compte/eftp/backend/bin/worker.php --once >> /home/compte/eftp/backend/storage/logs/worker.log 2>&1` | exports Excel/PDF (si `exports.inline` = `false`) |
| chaque jour | sauvegarde de la base par l'outil de l'hébergeur (cPanel › *Sauvegardes*) **et** copie hors du serveur | — |

Si l'hébergeur n'autorise pas les tâches cron, mettre `'inline' => true` dans `local.php` : les exports sont alors produits pendant la requête.

## 9. Mettre à jour une version déjà en ligne

1. Sauvegarder la base (phpMyAdmin › Exporter), `backend/config/local.php` et `backend/storage/` (APK publiés, imports, `app.key`).
2. Fabriquer le nouveau paquet (§2), le décompresser **à côté** de l'ancien, puis y recopier `backend/config/local.php` et le contenu de `backend/storage/`.
3. Basculer la racine web (option 1) ou remplacer les dossiers (option 2), sans toucher à `storage/` ni à `local.php`.
4. Lancer `php bin/migrate.php`, puis `php bin/check.php`.

## 10. Dépannage

| Symptôme | Cause et solution |
|---|---|
| `#1227 … SUPER, SET USER privilege(s)` à l'import | Clauses `DEFINER` de l'export : passer le fichier par `bin/sanitize-dump.php` (§1). |
| `1364 Field 'id' doesn't have a default value` | Import précédent interrompu : tables sans clé primaire ni auto-incrément. Réimporter avec `--drop` (§1). |
| `1146 Table '…' doesn't exist` | Base vide : §5. |
| Page blanche ou erreur 500 | Journaux : `backend/storage/logs/app-AAAA-MM-JJ.log` et journal d'erreurs PHP du panneau. Causes fréquentes : `local.php` absent ou mal rempli, ou version de PHP trop ancienne. |
| `/app/` s'affiche mais l'API répond 404 | `mod_rewrite` inactif, ou `.htaccess` de `backend/public/` non envoyé (afficher les fichiers cachés dans le gestionnaire de fichiers). |
| Les tablettes affichent « serveur injoignable » | Vérifier l'adresse compilée dans l'APK (`mobile/lib/src/app_config.dart`) et le certificat HTTPS (l'APK de production n'accepte que HTTPS). |

## 11. Réinitialiser la base après la formation, avant de lancer l'opération

Les formations et tests faits avec de vrais comptes sur le serveur laissent des campagnes, collectes, centres et journaux qui ne doivent pas se mélanger à la collecte réelle. Les formations faites en **mode Démo** de l'application, elles, n'envoient rien au serveur. Le script `backend/bin/reset-operation.php` remet la base à l'état « prête pour l'opération ».

> **Sans ligne de commande :** importer `deploy/hebergement/reinitialisation-operation.sql` dans phpMyAdmin (base › Importer). Même travail, après sauvegarde ; crée ou réinitialise l'administrateur **ADM001** (mot de passe provisoire indiqué en tête du fichier, à changer à la première connexion). Les options se règlent en section 0 du fichier.

**1. Sauvegarder** (phpMyAdmin › Exporter). L'opération est irréversible.

**2. Aperçu** (ne modifie rien ; affiche le nombre de lignes supprimées table par table) :

```bash
cd ~/httpdocs/backend
php bin/reset-operation.php
```

**3. Exécution** (le nom de la base doit être retapé) :

```bash
php bin/reset-operation.php --executer --confirmer=NOM_DE_LA_BASE
```

| Toujours supprimé | Toujours conservé |
|---|---|
| collectes (réponses, formateurs, métiers, révisions, contrôles, photos), brouillons, années, campagnes, affectations, centres créés sur le terrain, journaux (synchronisation, connexions, audit), jetons et appareils (tout le monde se reconnecte), exports, imports | territoire RENACOM, secteurs et métiers, types de structure, rôles, questionnaires publiés, paramètres, APK publiés, registre officiel des structures, comptes utilisateurs |

| Option | Effet supplémentaire |
|---|---|
| `--toutes-structures` | supprime tout le registre des structures (ex. les structures « DÉMO » de la base du poste), à réimporter ensuite |
| `--utilisateurs` | supprime tous les comptes sauf les administrateurs nationaux |
| `--garder=ADM001` | avec `--utilisateurs` : ne garde **que** ce(s) administrateur(s) — à utiliser pour éliminer `ADM-DEMO` |

Exemple (base restaurée depuis le poste, avec le jeu de démonstration) : créer d'abord le vrai administrateur (`php bin/create-admin.php …`), puis :

```bash
php bin/reset-operation.php --toutes-structures --utilisateurs --garder=ADM001                                   # aperçu
php bin/reset-operation.php --toutes-structures --utilisateurs --garder=ADM001 --executer --confirmer=NOM_DE_LA_BASE
```

Sécurités : aperçu par défaut ; confirmation par le nom exact de la base ; refus si aucun administrateur ne serait conservé ; arrêt si une table non prévue existe ; tout dans une transaction (rien n'est modifié en cas d'erreur) ; trace `DATABASE_RESET` dans le journal d'audit. Les compteurs d'identifiants **ne repartent pas de 1**, volontairement : une tablette qui aurait gardé une collecte de formation ne pourra jamais la rattacher à une campagne ou une structure réelle.

**4. Ensuite** : dans le back-office, créer l'année et la campagne réelles, importer les agents et les structures, puis affecter. Sur les tablettes ayant servi à une formation **avec de vrais comptes** : Paramètres Android › Applications › Collecte EFTP › Stockage › **Effacer les données**, avant de les remettre aux agents. Le mode Démo peut rester : il est séparé.

> Testé le 18/11/2026 sur une copie de la base du poste (`--toutes-structures --utilisateurs --garder=ADM-DEMO`) : collectes, campagnes, structures et comptes de test supprimés ; 266 communes, 68 métiers et 3 questionnaires conservés ; trace d'audit écrite ; une confirmation incorrecte est refusée.
