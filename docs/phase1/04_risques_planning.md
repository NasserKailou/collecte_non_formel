# Phase 1 — Risques techniques et planning de réalisation

## 1. Risques identifiés

| # | Risque | Impact | Probabilité | Mesure |
|---|---|---|---|---|
| R1 | **Référentiel territorial officiel non disponible** (8 régions / 66 départements / 266 communes / villages) — interdiction d'inventer des localités | Bloquant pour les affectations et les collectes | Élevée | Module d'import + contrôle (effectifs attendus, codes uniques, hiérarchie complète). **Fichier officiel INS/RENACOM à fournir.** En attendant : jeu de test fictif clairement marqué `TEST`, jamais déployé en production |
| R2 | **Écart d'environnement** : poste local en PHP 8.2.12 + MariaDB 10.4, alors que le cahier exige PHP 8.3+ et MySQL 8 | Fonctions incompatibles, tests non représentatifs | Certaine | Décision D-ENV : installer PHP 8.3 et MySQL 8 en local (ou via Docker), ou coder en restant compatible PHP 8.2 / MariaDB 10.4 en attendant |
| R3 | **Changement de questionnaire** (deux versions en deux ans) | Collectes incomparables, migrations lourdes | Élevée | Questionnaire piloté par métadonnées et versionné ; table de correspondance des variables entre versions |
| R4 | Ambiguïtés du questionnaire (A-01 à A-10) | Mauvaise interprétation statistique | Moyenne | Valeurs par défaut documentées et modifiables par configuration ; validation par la DSD avant la mise en production |
| R5 | Perte de données sur le terrain (panne, vol de tablette, application désinstallée) | Perte de collectes | Moyenne | Écriture locale à chaque champ (transactions SQLite), synchronisation dès que possible, export chiffré de secours, révocation à distance |
| R6 | Synchronisation massive après plusieurs jours hors ligne (centaines d'agents simultanés) | Saturation du serveur | Moyenne | Charge utile compressée, une collecte par requête (petites transactions), limitation de débit par appareil avec `Retry-After`, tests k6 |
| R7 | Doublons de structures (orthographes différentes, structures découvertes sur le terrain) | Sur-comptage | Élevée | Code officiel unique, détection approchée nom + commune + GPS, approbation par le superviseur des structures proposées |
| R8 | Diversité des terminaux Android (versions anciennes, faible mémoire) | Plantages, lenteurs | Moyenne | Cible minimale Android 8 (API 26), tests sur appareil d'entrée de gamme, formulaire paginé par section |
| R9 | GPS imprécis ou indisponible | Coordonnées fausses | Moyenne | Enregistrement de la précision, nouvelle tentative, motif obligatoire si absent, contrôle de distance non bloquant |
| R10 | Compromission de compte ou fuite de données nominatives (formateurs, téléphones) | Atteinte à la vie privée | Faible à moyenne | Argon2id, jetons révocables, SQLCipher, contrôle de périmètre systématique, journal d'audit, HTTPS obligatoire, tests IDOR automatisés |
| R11 | Pas d'environnement représentatif de la production pour les tests de charge | Résultats non représentatifs | Moyenne | Tests sur une préproduction dimensionnée comme la production ; résultats documentés avec la configuration exacte ; aucun test destructif en production |
| R12 | Distribution de l'APK (signature, mises à jour) | Versions hétérogènes sur le terrain | Moyenne | Signature avec un keystore du ministère, version minimale imposée par l'API (`426 Upgrade Required`), page de téléchargement interne |
| R13 | Volume de travail (3 composants complets + 20 documents) | Délais | Certaine | Livraison incrémentale par lots testables, dans l'ordre ci-dessous |

## 2. Décisions du client (12/11/2026)

| Réf. | Décision |
|---|---|
| D-ENV | Développement sur la configuration XAMPP actuelle : **PHP 8.2 / MariaDB 10.4**. Le code reste compatible avec PHP 8.3 / MySQL 8 pour la production (pas de fonctionnalité propre à MariaDB, pas de fonctionnalité exclusive à PHP 8.3) |
| D-REF | Référentiel territorial : `renacom.xlsx` fourni (8 régions, 67 entités de niveau département, 266 communes, sans villages) |
| A-01 | Financement (1.10) et mode de paiement (1.14.1) : **réponses multiples** |
| A-06 | Métiers : nomenclature `listes_metiers.xlsx` fournie |
| VAL | **Deux niveaux de validation** : départemental puis régional. Le nombre de niveaux reste un paramètre de la campagne (2 par défaut) |
| D-01 / D-08 | 2.1 (apprentis) et 2.19 (maîtres artisans/formateurs) sont deux grandeurs distinctes. 2.1 est calculée depuis la Section IV (apprentis), 2.19 depuis la Section III (formateurs) |

### 2.1 Analyse de `renacom.xlsx`

- Format : une feuille, colonnes `niveau | code | libellé | — | code région parent | code département parent`.
- 8 régions ✔, 266 communes ✔, aucun code ni libellé en double, chaque département et chaque commune rattaché à un parent existant ✔.
- **67 entités de niveau département** (le cahier des charges en annonce 66) : 63 départements + 4 villes à statut particulier (Ville de Maradi, Ville de Tahoua, Ville de Zinder, Ville de Niamey, cette dernière constituant à elle seule la région de Niamey). Importées telles quelles, avec un indicateur `is_city`.
- Les codes sont des numéros séquentiels internes (1–8, 1–67, 1–266), pas des codes INS hiérarchiques : ils sont conservés dans `code_renacom` et stables pour les imports futurs.
- **Aucun village/quartier** : 1.6.4 est saisi librement sur le terrain, puis consolidé dans un référentiel `localities` (statut `TERRAIN`, validable par le superviseur).

### 2.2 Analyse de `listes_metiers.xlsx`

- Feuille `secteur` : 3 secteurs (1 Primaire, 2 Secondaire, 3 Tertiaire) ✔.
- Feuille `metier` : 68 lignes, avec deux anomalies :
  - **7 doublons** : les codes 8–14 répètent les codes 1–7 (différence d'espace final). Les codes 8–14 sont importés comme alias inactifs pointant vers 1–7.
  - **13 métiers sans secteur** (`NULL`). Rattachement proposé, **à valider par la DSD** :

| Code | Métier | Secteur proposé | Remarque |
|---|---|---|---|
| 56 | Lavage | 3 Tertiaire | |
| 57 | Soudure | 2 Secondaire | |
| 58 | Autres métiers | les 3 | devient l'option « Autre (préciser) » de chaque secteur |
| 59 | Réparation télé | 2 Secondaire | cohérent avec 27 « Électronique » |
| 60 | Réparation radio | 2 Secondaire | idem |
| 61 | Réparation téléphone | 2 Secondaire | quasi-doublon de 27 « Électronique (réparation de cellulaire) » |
| 62 | Pressing | 3 Tertiaire | |
| 63 | Informatique | 3 Tertiaire | |
| 64 | Multimédia | 3 Tertiaire | |
| 65 | Agent de sécurité | 3 Tertiaire | |
| 66 | Tannerie | 2 Secondaire | quasi-doublon de 28 « Cuirs et peaux/tannerie » |
| 67 | Vitro-alu | 2 Secondaire | |
| 68 | Réparation vélo | 2 Secondaire | |

## 3. Décisions initialement attendues (historique)

| Réf. | Question | Recommandation |
|---|---|---|
| D-ENV | PHP 8.3 + MySQL 8 en local dès maintenant, ou compatibilité PHP 8.2 / MariaDB 10.4 en attendant ? | Installer PHP 8.3 et MySQL 8 (ou Docker), pour coller à la cible de production |
| D-REF | Disposez-vous du fichier officiel des régions, départements, communes et villages, avec leurs codes ? | Le fournir ; sinon, jeu de test fictif marqué `TEST` |
| D-01 / D-08 | Calculer 2.1 et 2.19 depuis les Sections IV et III (aucune double saisie) ? | Oui |
| A-01 | Financement et mode de paiement en choix multiple ? | Oui |
| A-05 / A-06 | Nomenclatures fermées pour les métiers et les niveaux de certificat ? | Oui, listes à fournir par la DSD |
| VAL | Nombre de niveaux de validation : départemental seul, ou départemental puis régional ? | Configurable par campagne ; 1 niveau par défaut |

## 4. Planning de réalisation par lots

Chaque lot se termine par des tests automatisés exécutés et une documentation à jour. Un lot n'est annoncé « terminé » que s'il fonctionne réellement.

| Lot | Contenu | Livrables vérifiables | Dépend de |
|---|---|---|---|
| **L0 — Conception** (Phase 2) | Modèle logique MySQL complet, schéma SQLite, contrat d'API (OpenAPI), JSON du questionnaire 2025, grammaire des règles + vecteurs de test, diagrammes UML | `docs/database`, `docs/api/openapi.yaml`, `shared/questionnaire/eftp-nf-2025.json` | Phase 1 validée |
| **L1 — Socle backend** | Noyau (routeur, conteneur, PDO, configuration, erreurs, journalisation), migrations, authentification web + API, rôles/permissions, périmètres, audit, limitation des tentatives | Tests PHPUnit : authentification, autorisations, IDOR | L0 |
| **L2 — Référentiels et comptes** | Import territorial, nomenclatures, utilisateurs, hiérarchie de supervision, import Excel des agents (modèle, prévisualisation, transaction, rapport) | Import de 500 agents fictifs testé ; rapport d'erreurs | L1 |
| **L3 — Structures, années, campagnes, affectations** | Registre des structures (doublons, historique), années, campagnes et transitions d'état, ciblage, affectations, vue des non-affectées | Tests : la clôture bloque les soumissions ; unicité des affectations | L2 |
| **L4 — Moteur de questionnaire et collecte serveur** | Versions de questionnaire, interpréteur de règles PHP, endpoints de soumission idempotents, historique des révisions, gestion des conflits | Vecteurs de règles verts ; tests d'idempotence et de reprise | L3 |
| **L5 — Back-office web** | Gabarit (orange/vert), tableaux de bord, modules d'administration, consultation et contrôle des collectes, validation/invalidation, journaux, paramètres | Parcours complet admin → superviseur | L4 |
| **L6 — Statistiques et exports** | Tables d'agrégats, indicateurs du questionnaire, comparaison pluriannuelle, exports Excel/CSV/PDF en tâche de fond | Tests statistiques : agrégats = recalcul direct sur jeu fictif | L5 |
| **L7 — Application mobile** | Authentification en ligne/hors ligne, SQLCipher, téléchargement des affectations, formulaire dynamique à 5 sections, interpréteur de règles Dart, GPS, outbox et synchronisation, statuts, corrections | `flutter test` (mêmes vecteurs de règles), APK de test installable | L4 (en parallèle de L5/L6) |
| **L8 — Intégration et tests** | Parcours de bout en bout, tests de sécurité (ZAP, IDOR, CSRF, XSS, upload), tests de charge k6 (300 / 500 / 1 000 utilisateurs, synchronisation massive) | Rapports chiffrés réellement exécutés | L6, L7 |
| **L9 — Documentation et déploiement** | Guides (installation, administrateur, superviseur, agent, import, campagne, synchronisation, sauvegarde, maintenance), scripts de sauvegarde/restauration, procédure de déploiement | Les 20 documents du §19 | L8 |
