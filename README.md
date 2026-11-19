# Plateforme nationale de collecte — EFTP non formel (Niger)

Système de recensement statistique des structures d'Enseignement et de Formation Techniques et Professionnels non formels : back-office web national, application mobile Flutter hors ligne et backend PHP/MySQL avec API REST.

## État d'avancement

| Phase | État |
|---|---|
| 1 — Audit et analyse | Terminée et validée (12/11/2026) |
| 2 — Conception | Terminée — schéma MySQL ([docs/database](docs/database/README.md)), questionnaire 2025 + moteur de règles ([spécification](docs/architecture/questionnaire-et-regles.md)), [contrat d'API](docs/api/README.md), [base locale mobile](docs/mobile/README.md) |
| 3 — Backend, lot L1 (socle) | **Livré et testé** — migrations, référentiels (RENACOM, métiers), authentification par jetons, contrôle des permissions, API `/auth`, `/me`, `/referentials`, `/questionnaires`, `/settings` |
| 3 — Backend, lot L2 (comptes) | **Livré et testé** — gestion des utilisateurs par périmètre territorial (visibilité, pas d'élévation de privilèges, superviseurs), désactivation, réinitialisation, révocation d'appareils, import Excel des agents en deux temps (modèle, prévisualisation, tout-ou-rien, rapport, archive chiffrée des identifiants) — 500 agents importés en ≈ 5 s |
| 3 — Backend, lot L3 (structures, campagnes) | **Livré et testé** — registre des structures (code unique, détection des doublons nom/GPS, historique, propositions de terrain idempotentes, approbation, rejet, fusion, fermeture), années statistiques, campagnes et transitions d'état contrôlées, ciblage en masse, reconduction, affectations (un seul agent actif par structure, réaffectation historisée), zones de travail |
| 3 — Backend, lot L4 (collecte) | **Livré et testé** — soumission idempotente des collectes (contrôles du questionnaire rejoués par le serveur, révisions, conflits, verrou, concurrence vérifiée par processus parallèles), synchronisation descendante incrémentale, pièces jointes, contrôle et validation à deux niveaux, invalidation motivée, correction, réouverture |
| 4 — Back-office web, lot L5 | **Livré** — session sécurisée (cookie HttpOnly + jeton anti-CSRF), interface en français aux couleurs nationales : tableau de bord, collectes et fiche de contrôle, structures, campagnes, affectations, utilisateurs, import des agents, paramètres, journaux ; bibliothèques hébergées localement, politique de sécurité du contenu stricte |
| 4 — Statistiques et exports, lot L6 | **Livré et testé** — avancement par territoire, performance des agents, évolution, indicateurs du questionnaire (base validées ou soumises), comparaison pluriannuelle ; exports Excel (en flux), CSV et PDF en tâches de fond, données personnelles exclues ; microdonnées prêtes pour l'annuaire (feuille `donnees_generale_centre` en tableau Excel, variables et valeurs en clair, tableaux croisés T01 à T11 par région, département et commune) ; pages « Statistiques » et « Exports » du back-office |
| Saisie multicanal, lot L6b | **Livré** — mode de collecte par campagne (tablette, papier, mixte) ; page « Ma saisie » (saisie directe et fiches papier, sauvegarde automatique, contrôle par le serveur) ; canal et références papier dans l'export ; carte des centres (public/privé, état de la collecte, source de la position) ; publication de l'APK depuis l'administration et détection des mises à jour par les tablettes ; mesure de capacité (≈ 19 soumissions/s par processus PHP, voir [docs/api](docs/api/README.md) §4.6). **Gestion des versions du questionnaire depuis le back-office : à venir** |
| 5 — Application mobile, lot L7 | **En cours** — moteur de règles (vecteurs partagés réussis), base chiffrée SQLCipher, connexion en ligne et hors ligne, collecte et synchronisation testées ; écrans écrits, non encore essayés sur tablette ; APK non produit (disque plein) — voir [docs/mobile](docs/mobile/README.md) |
| 6 à 7 — Sécurité et charge, documentation | À venir (lots L8 et L9) |

## Démarrage (XAMPP, PHP 8.2, MariaDB 10.4)

```bash
cd backend
composer install
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS eftp_nf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php bin/migrate.php                       # schéma (--fresh pour tout recréer, interdit en production)
php bin/seed.php                          # rôles, territoire (renacom.xlsx), métiers, questionnaire 2025.1
php bin/create-admin.php --matricule=ADM001 --nom=NOM --prenom=Prénom --sexe=M
```

API : `http://localhost:8085/non_formel/backend/public/api/v1/` (port Apache de cette installation XAMPP).
Back-office web : `http://localhost:8085/non_formel/` (ou `/non_formel/backend`, redirigés vers `backend/public/app/`) — connexion avec un compte administrateur, superviseur ou lecteur ; les agents utilisent l'application mobile.

Jeu de démonstration (données fictives « DÉMO », base de développement uniquement, refusé en production) :

```bash
php bin/seed-demo.php                    # 7 comptes DEMO-* (un par profil), campagne DEMO-CAMPAGNE, 12 structures, 9 collectes à tous les stades
php bin/seed-demo.php --reset-passwords  # régénère les mots de passe des comptes DEMO-*
```

Les identifiants sont écrits dans `backend/storage/demo-identifiants.txt` (hors racine web).
Paramètres propres au poste : `backend/config/local.php` (non versionné) ou variables d'environnement `EFTP_*`.

Sécurité de l'installation de développement : le projet étant sous `htdocs`, `non_formel/.htaccess` interdit tout accès web sauf `backend/public/`. **En production, la racine web doit pointer directement sur `backend/public/`.**

### Production — https://eftpnonformel.ins.ne

Dossier de déploiement complet (VPS Ubuntu 24.04, Apache + PHP 8.3-FPM + MariaDB, Let's Encrypt, cron, sauvegardes, APK signé) : **[deploy/README.md](deploy/README.md)**. API de production : `https://eftpnonformel.ins.ne/api/v1` (adresse par défaut de l'application mobile).

### Lancer les tests

```bash
cd backend
composer install
php vendor/bin/phpunit --bootstrap vendor/autoload.php tests
```

Les tests d'intégration créent et recréent une base dédiée `eftp_nf_test` et un dossier de stockage temporaire ; ils ne touchent jamais `eftp_nf`. État au 13/11/2026 (lots L6b et L7) : 218 tests, 1 078 assertions, tous réussis (la mesure de capacité `SubmissionThroughputTest` ne se lance qu’vec `EFTP_PERF=1`) ; affichage conditionnel du back-office : `node backend/tests/js/jsonlogic.test.mjs` (71 vecteurs partagés). Application mobile : `cd mobile && flutter test` — 125 tests réussis (vecteurs partagés, base chiffrée, synchronisation). Les formulaires du back-office n'ont pas de tests automatisés (syntaxe contrôlée par `node --check`).

### Tâches de fond (exports, purge)

```bash
php bin/worker.php --once     # à planifier chaque minute en production (avec EFTP_EXPORTS_INLINE=0)
``` Mesure du test de volume : 500 agents — prévisualisation 1,0 s, validation 4,3 s (une transaction).

### Régénérer la définition du questionnaire

```bash
php scripts/questionnaire/build_eftp_nf_2025.php
```

## Référence fonctionnelle

- `Questionnaire_EFTP_Non_Formel_do30 12 2025.pdf` / `.xlsx` — questionnaire officiel 2025-2026 (DSD/MEFTP)

## Documentation de la Phase 1

1. [Analyse du questionnaire](docs/phase1/01_analyse_questionnaire.md) — dictionnaire des variables, règles de saut, contrôles, incohérences, différences avec la version 2024
2. [Fonctionnalités et cas d'utilisation](docs/phase1/02_fonctionnalites_cas_utilisation.md)
3. [Architecture, modèle conceptuel et workflows](docs/phase1/03_architecture_mcd_workflows.md)
4. [Risques et planning](docs/phase1/04_risques_planning.md)

## Pile technique retenue

PHP 8.3 sans framework · MySQL 8 InnoDB · Bootstrap 5 · Chart.js · DataTables · PhpSpreadsheet · Dompdf · Flutter/Dart · SQLite chiffrée (SQLCipher via Drift) · Riverpod · API REST JSON `/api/v1`.
