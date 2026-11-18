# Application mobile — base locale et synchronisation

Base SQLite chiffrée (SQLCipher, via Drift) ; clé aléatoire de 256 bits conservée dans le Keystore Android (`flutter_secure_storage`). La base est ouverte au déverrouillage de l'application et refermée en arrière-plan prolongé.

## 1. Schéma local (v1)

| Table | Colonnes principales | Rôle |
|---|---|---|
| `account` | `user_uuid` PK, `matricule`, `full_name`, `roles_json`, `offline_verifier` (Argon2id du mot de passe), `last_online_login_at`, `unlock_failures` | Compte connecté (un seul par appareil à la fois) |
| `kv` | `key` PK, `value` | Curseurs de synchronisation, ETags, paramètres (`offline_policy`) |
| `regions`, `departments`, `communes` | `id` PK, `parent_id`, `name`, `centroid_lat`, `centroid_lng` | Référentiel territorial |
| `sectors`, `trades` | `id` PK, `sector_id`, `label`, `is_other`, `active` | Nomenclature des métiers |
| `structure_types` | `code` PK, `label`, `is_other` | Types de structure |
| `pending_structures` (v2) | `uuid` PK, `campaign_id`, `temp_cs_id` UNIQUE, `payload_json`, `state`, `server_cs_id`, `last_error`, `duplicates_json` | Centres créés sur le terrain en attente d'envoi |
| `questionnaires` | `version` PK, `definition_json`, `checksum` | Définitions téléchargées (immuables) |
| `campaigns` | `id` PK, `code`, `name`, `status`, `opens_on`, `closes_on`, `questionnaire_version` | Campagnes autorisées |
| `assignments` | `campaign_structure_id` PK, `campaign_id`, `structure_uuid`, `structure_json`, `active` | Structures affectées (identité figée au téléchargement) |
| `collections` | `uuid` PK, `campaign_structure_id` UNIQUE, `questionnaire_version`, `local_status`, `server_status`, `server_revision`, `data_json`, `progress_pct`, `errors_count`, `warnings_count`, `review_json`, `started_at`, `completed_at`, `updated_at` | Brouillons et collectes |
| `collection_events` | `id` PK, `collection_uuid`, `event`, `detail`, `at` | Traçabilité locale (modifications après synchronisation, réouvertures, conflits) |
| `outbox` | `id` PK, `collection_uuid`, `base_revision`, `payload_json`, `payload_sha256`, `state`, `attempts`, `next_attempt_at`, `last_error`, `created_at`, `acked_at` | File d'envoi persistante |
| `attachments` | `id` PK, `collection_uuid`, `local_path`, `state` | Photos de fiches signées en attente d'envoi |
| `sync_log` | `id` PK, `direction`, `endpoint`, `http_status`, `outcome`, `at` | Historique de synchronisation consultable par l'agent |

`local_status` : `BROUILLON`, `TERMINEE`, `SOUMISE`, `INVALIDEE`, `EN_CONFLIT` (avec le miroir des états serveur reçus au pull).
`outbox.state` (affiché à l'agent) : `LOCAL` (local uniquement), `EN_ATTENTE`, `EN_COURS`, `SYNCHRONISE`, `ECHEC`, `A_RESYNCHRONISER`.

## 2. Règles de fonctionnement

- **Sauvegarde automatique** : chaque modification de champ écrit `collections.data_json` dans une transaction (aucune perte en cas de fermeture brutale).
- **Terminer** : le moteur Dart (même définition, mêmes vecteurs de test que le serveur) doit renvoyer zéro erreur bloquante ; la collecte passe à `TERMINEE` et un élément `outbox` est créé avec l'empreinte SHA-256 de la charge utile canonique (clés triées).
- **Envoi** : un élément à la fois, temporisation exponentielle (30 s, 1 min, 2 min… plafonnée à 30 min), déclenché par le retour du réseau, en arrière-plan (WorkManager) ou manuellement.
- **Accusé** : `SYNCHRONISE` uniquement à réception du `201/200` du serveur ; la collecte devient `SOUMISE` et n'est plus modifiable localement.
- **Rejet 422** : l'élément passe en `ECHEC`, la collecte revient en `BROUILLON` avec les anomalies du serveur affichées.
- **Conflit 409/423** : état serveur enregistré, collecte en `EN_CONFLIT`, copie locale conservée intacte et signalée ; **jamais d'écrasement silencieux** dans un sens ou dans l'autre.
- **Invalidation** (reçue au pull) : la collecte redevient modifiable, avec le motif affiché ; la correction repart avec `base_revision` = révision serveur.
- **Purge** : les données d'une collecte ne sont supprimées de l'appareil qu'une fois validée, ou après la clôture de la campagne et un délai paramétrable.
- **Hors ligne** : déverrouillage local par le vérificateur Argon2id ; au-delà de `max_offline_days` sans connexion, une reconnexion en ligne est exigée (les données locales restent intactes).

## 3. Implémentation (lot L7 — état au 13/11/2026)

Code : `mobile/` (Flutter 3.44, Dart 3.12, Android). Adresse du serveur fixée à la compilation : `--dart-define=API_BASE_URL=…/api/v1`.

| Élément | Fichier | État |
|---|---|---|
| Moteur JsonLogic + questionnaire (portage fidèle du PHP) | `lib/src/rules/` | **Testé** : 116 vecteurs partagés réussis (71 JsonLogic, 45 scénarios) |
| Base locale chiffrée SQLCipher | `lib/src/data/local_db.dart` | **Testé** : fichier illisible sans la clé, mauvaise clé refusée |
| Secrets (clé de base, jetons, identifiant d'appareil) dans le Keystore | `lib/src/data/secure_store.dart` | Écrit (dépend de l'appareil : non testé sur poste) |
| Client API (en-têtes mobiles, rafraîchissement du jeton) | `lib/src/net/api_client.dart` | Testé via la synchronisation |
| Connexion en ligne, déverrouillage hors ligne (Argon2id 8 Mio), changement de mot de passe | `lib/src/services/auth_service.dart` | Écrit ; non testé sur appareil |
| Collecte : pré-remplissage, sauvegarde automatique, Terminer, empreinte | `lib/src/services/collection_service.dart` | **Testé** |
| Synchronisation : file d'envoi, rejeu, 422, conflits 409/423, invalidation reçue, affectation retirée | `lib/src/services/sync_service.dart` | **Testé** (7 scénarios, faux serveur) |
| Écrans : connexion, liste des structures, formulaire par section (contrôle immédiat, GPS, photo de la fiche), conflits, journal | `lib/src/ui/` | Écrits ; analyse statique sans erreur ; **non vérifiés sur appareil** |

Ajouts du 13/11/2026 :
- **GPS automatique** : à l'ouverture d'une collecte modifiable sans position, la tablette relève la position (précision maximale, 60 s au plus) ; l'agent peut la refaire ou déclarer la position indisponible avec un motif.
- **Mise à jour de l'application** : après chaque synchronisation (même refusée en 426), la tablette interroge `/app/releases/latest` ; un bandeau propose « Télécharger et installer » (lien signé ouvert dans le navigateur, installation par Android) ; la base locale est conservée. Toutes les versions doivent être signées avec la même clé.
- **Mise à jour du questionnaire** : une nouvelle version attribuée à une campagne est téléchargée automatiquement et signalée dans le résumé de synchronisation.
- Campagne en mode `PAPIER` : la tablette refuse de commencer une collecte.

Ajouts du 14/11/2026 — **recensement sur le terrain** (« Nouveau centre ») :
- Bouton orange « Nouveau centre » toujours visible sur l'accueil (et dans l'écran vide). Une seule page à étapes numérotées : position GPS relevée automatiquement (bouton « Réessayer » / « Refaire », alerte si précision > 50 m), nom, type (gros boutons, précision si « Autre »), public/privé, commune (présélectionnée si l'agent n'en a qu'une, recherche par saisie sinon), quartier, urbaine/rurale. « Commencer le questionnaire » ouvre aussitôt le formulaire pré-rempli, **hors ligne**.
- Alerte de doublon pendant la saisie du nom (même commune, nom proche, mots génériques ignorés) avec bouton « Ouvrir » sur le centre existant ; confirmation explicite pour créer quand même, et pour continuer sans GPS.
- Schéma local **v2** : table `pending_structures (uuid, campaign_id, temp_cs_id, payload_json, state, server_cs_id, last_error, duplicates_json)`. Le centre reçoit un identifiant d'inscription provisoire **négatif** ; sa collecte n'entre dans la file d'envoi qu'après l'enregistrement du centre.
- Synchronisation : les centres partent **avant** les collectes (`POST /campaigns/{id}/field-structures`, idempotent par l'UUID) ; l'identifiant définitif remplace le provisoire (affectation, collecte, file d'envoi). Refus (hors zone, campagne fermée…) : saisie conservée, mention rouge sur l'accueil, nouvel essai à chaque synchronisation. Doublon possible : signalé à l'agent et au superviseur. Filtre « Créés sur le terrain » sur l'accueil.
- Tests : 3 scénarios ajoutés (création hors ligne puis envoi ordonné, refus 403, validation) — 128 tests mobiles réussis.

Ajouts du 14/11/2026 — **saisie facilitée** :
- **Questions non renseignées** : avertissement orange (« devrait normalement être remplie »), jamais bloquant ; repère orange « • » au lieu de l'astérisque rouge. « Terminer » propose « Voir la liste » ou « Terminer quand même ».
- **Navigation** : boutons « Précédent » / « Suivant » en bas de chaque section (en plus du balayage), « Section x sur y », « Terminer » sur la dernière section ; l'état (points à corriger / à vérifier) ouvre la liste.
- **Questionnaire 2025.2** : 2.1 (apprentis par durée, secteur et sexe) se saisit librement ; tout écart avec les totaux recalculés depuis la Section IV (`IV_DUR.*`) est signalé par un avertissement « Incohérence 2.1 … » sans blocage. Vecteurs partagés `shared/rules-fixtures/eftp-nf-2025.2.json` (serveur et tablette identiques).
- **Changement de version d'une campagne** : les collectes locales jamais acceptées basculent sur la nouvelle version (données conservées ; une collecte terminée non envoyée redevient brouillon, à vérifier puis terminer).
- **Connexion mémorisée** : après la première connexion en ligne, l'application s'ouvre directement (plus de verrouillage automatique après 15 min). Mot de passe redemandé seulement après « Verrouiller », « Se déconnecter », dépassement de `max_offline_days` ou changement de mot de passe exigé. La base reste chiffrée par la clé du Keystore ; **activer le verrouillage d'écran Android des tablettes**.
- `LocalDb.transaction` est réentrante (appel imbriqué = même transaction).

Ajouts du 15/11/2026 :
- **Tester la connexion au serveur** (menu ⋮ de l'accueil et écran de connexion) : réseau et serveur (temps de réponse), base de données, session de l'agent, avec un conseil clair ; affiche l'adresse du serveur, les collectes et nouveaux centres en attente, la dernière synchronisation ; bouton « Envoyer maintenant » si tout va bien. N'envoie aucune donnée de collecte.
- **Tableaux compacts** (2.1, 2.2) sur tablette : durées en lignes, colonnes regroupées sous leur en-tête (secteur, inscrits/placés/outillés), cases numériques (chiffres seulement), ligne Total en direct, case concernée surlignée et message sous le tableau. Sur téléphone, l'affichage par cartes est conservé.
- **Sans blocage** : seules les valeurs impossibles bloquent (voir docs/api §3.2 ter) ; questionnaire 2025.3 attribué aux campagnes DEMO et EFTPFN-2026-2027 (les 9 collectes de démonstration gardent 2025.1).
- APK de test : `flutter build apk --release --dart-define=API_BASE_URL=http://<adresse du serveur>:8085/non_formel/backend/public/api/v1`. Signée avec la clé de débogage : **créer une clé de production avant la diffusion** (une mise à jour signée avec une autre clé ne s'installe pas par-dessus).

Écarts assumés par rapport à la conception de la phase 2 :
- **SQLCipher via `package:sqlite3` v3** (binaire SQLCipher précompilé, empreinte vérifiée, sélectionné dans `pubspec.yaml` › `hooks`) au lieu de Drift : schéma SQL explicite, migrations par `PRAGMA user_version`, pas de génération de code. `sqlcipher_flutter_libs` est obsolète avec sqlite3 v3.
- **`ChangeNotifier`** (Flutter standard) au lieu de Riverpod.
- **Synchronisation en arrière-plan (WorkManager) non encore faite** : la synchronisation se fait à l'ouverture, au retour du réseau, toutes les 10 minutes application ouverte, et manuellement.
- Campagne en mode `PAPIER` : la tablette refuse de commencer une collecte (saisie dans le back-office).

Limites connues : APK non produit sur le poste de développement (disque plein : 1,4 Go libres, compilation Gradle interrompue) ; aucun essai sur tablette réelle ; identifiant d'application provisoire `ne.eftpnf.eftp_nf_collecte`, à remplacer par l'identifiant officiel ; trafic HTTP en clair autorisé seulement vers les adresses de développement (`network_security_config.xml`, à retirer en production).
