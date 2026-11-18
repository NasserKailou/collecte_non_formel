# Contrat de l'API REST — `/api/v1`

API unique consommée par le back-office web (session + CSRF) et par l'application mobile (jeton Bearer). Format JSON UTF-8, dates ISO 8601, heure du Niger (UTC+1).

## 1. Conventions

### Enveloppe de réponse

```json
{ "data": { }, "meta": { "page": 1, "per_page": 50, "total": 1234 } }
```

Erreur :

```json
{ "error": { "code": "CAMPAIGN_CLOSED", "message": "La campagne est clôturée : aucune soumission n'est acceptée.", "details": { } } }
```

### Codes HTTP

| Code | Usage |
|---|---|
| 200 / 201 | Succès / création |
| 204 | Succès sans contenu |
| 400 | Requête mal formée (`BAD_REQUEST`) |
| 401 | Non authentifié ou jeton expiré (`UNAUTHENTICATED`, `TOKEN_EXPIRED`) |
| 403 | Authentifié mais non autorisé : permission ou périmètre (`FORBIDDEN`, `OUT_OF_SCOPE`) |
| 404 | Ressource inexistante **ou hors périmètre** (on ne révèle pas l'existence d'une ressource d'un autre périmètre) |
| 409 | Conflit d'état ou de révision (`CONFLICT`, `REVISION_CONFLICT`) |
| 422 | Données invalides (`VALIDATION_FAILED`, avec `details.errors[]`) |
| 423 | Ressource verrouillée (`LOCKED`, `CAMPAIGN_CLOSED`, `CAMPAIGN_SUSPENDED`) |
| 426 | Version de l'application mobile trop ancienne (`UPGRADE_REQUIRED`) |
| 429 | Trop de requêtes (`RATE_LIMITED`, en-tête `Retry-After`) |
| 503 | Maintenance (`MAINTENANCE`) |

### En-têtes

| En-tête | Sens |
|---|---|
| `Authorization: Bearer <jeton>` | Mobile |
| `X-CSRF-Token` | Web, toute requête modifiante |
| `X-App-Version`, `X-Device-Id` | Mobile, obligatoires |
| `Idempotency-Key` | Soumissions : `<uuid collecte>:<sha256 de la charge utile>` |
| `If-None-Match` / `ETag` | Téléchargements de référentiels |
| `Content-Encoding: gzip` | Accepté sur les soumissions ; réponses compressées si `Accept-Encoding` |

### Pagination et filtres

`?page=1&per_page=50` (max. 200), `?sort=-last_submitted_at`, filtres nommés (`?campaign_id=3&region_id=5&status=SOUMISE`). Les grandes listes du web utilisent le protocole serveur de DataTables (`draw`, `start`, `length`, `search[value]`).

### Contrôle d'accès (dans chaque service, jamais seulement dans l'interface)

1. **Permission** du rôle (`collection.review`, `user.import`…).
2. **Périmètre** : la requête SQL reçoit une clause de portée calculée à partir de `user_scopes`. Un identifiant transmis par le client n'est jamais suffisant.
3. **État** : campagne `OUVERTE`, collecte modifiable, affectation active.

## 2. Authentification

| Méthode | Chemin | Accès | Description |
|---|---|---|---|
| POST | `/auth/login` | public | Mobile : `{identifier, password, device:{uid, model, os_version}}` → `{access_token, refresh_token, expires_in, user, offline_policy}`. Web : ouvre une session et renvoie le jeton CSRF |
| POST | `/auth/refresh` | public | `{refresh_token}` → nouvelle paire (rotation ; un ancien jeton rejoué révoque toute la famille) |
| POST | `/auth/logout` | authentifié | Révoque les jetons de l'appareil / détruit la session |
| POST | `/auth/password` | authentifié | Changement de mot de passe (obligatoire à la première connexion) |
| GET | `/me` | authentifié | Profil, rôles, permissions, périmètres |

Limitation : 5 échecs par identifiant en 15 min ⇒ verrouillage 15 min ; 20 échecs par IP en 15 min ⇒ `429`. Tout est tracé dans `login_logs`.

**Session web (implémentée — lot L5)** : `POST /auth/login` avec `{identifier, password, session: true}` (sans `device`) pose un cookie `eftp_session` (`HttpOnly`, `SameSite=Strict`, `Path=/`, durée 12 h, `Secure` si `EFTP_COOKIE_SECURE=1` — obligatoire en production) et renvoie `csrf_token` **au lieu** des jetons (aucun jeton n'est lisible par le JavaScript). Toute requête authentifiée par ce cookie et non `GET` doit porter `X-CSRF-Token` (HMAC de la session, clé `EFTP_APP_KEY` ou `storage/app.key` générée au premier usage) ; sinon `403 CSRF_FAILED`. `GET /me` renvoie de nouveau `csrf_token` (rechargement de page). `POST /auth/logout` efface le cookie. Les clients à jeton porteur (mobile) ne sont pas concernés.

`offline_policy` : `{max_offline_days: 30, max_unlock_failures: 10}` — durée maximale d'utilisation hors ligne sans reconnexion, et nombre d'échecs de déverrouillage local avant effacement des données locales déjà synchronisées.

## 3. Synchronisation mobile

### 3.1 Téléchargement (pull)

`GET /sync/pull?since=<curseur>&campaign_id=<id>` — uniquement ce qui a changé depuis le curseur (horodatage serveur opaque) :

```json
{
  "data": {
    "cursor": "2026-02-10T08:00:00.123+01:00",
    "campaigns": [ { "id": 3, "code": "EFTPNF-2025-2026", "status": "OUVERTE", "opens_on": "2026-01-05", "closes_on": "2026-03-31", "questionnaire_version": "2025.1" } ],
    "assignments": [ { "campaign_structure_id": 812, "structure": { "uuid": "…", "official_code": "…", "name": "…", "commune_id": 120, "type_code": "c", "zone": "a", "legal_status": "b", "locality_label": "…" }, "active": true } ],
    "collections": [ { "uuid": "…", "status": "INVALIDEE", "revision": 2, "review": { "reason": "…", "comment": "…", "at": "…" } } ],
    "removed_assignments": [ 790 ]
  }
}
```

Référentiels, téléchargés séparément et mis en cache avec `ETag` :

| Chemin | Contenu |
|---|---|
| `GET /referentials/territory` | Régions, départements, communes (avec centroïdes si disponibles) |
| `GET /referentials/trades` | Secteurs et métiers actifs |
| `GET /referentials/structure-types` | Types de structure |
| `GET /questionnaires/{version}` | Définition JSON complète (immuable, mise en cache définitive) |

### 3.2 Envoi (push) — idempotent

`POST /collections/{uuid}/submissions`

```json
{
  "campaign_structure_id": 812,
  "questionnaire_version": "2025.1",
  "base_revision": 0,
  "device_started_at": "2026-02-10T08:41:00+01:00",
  "device_completed_at": "2026-02-10T09:20:00+01:00",
  "data": { "Q1_1": "…", "trainers": [ ], "sectors": { }, "GPS": { } }
}
```

Traitement serveur, dans une transaction avec verrou (`SELECT … FOR UPDATE`) :

1. Authentification, version d'application, appareil non révoqué.
2. L'utilisateur est l'**agent affecté actif** de `campaign_structure_id`, sinon `403 OUT_OF_SCOPE`.
3. Campagne `OUVERTE`, sinon `423 CAMPAIGN_CLOSED` / `CAMPAIGN_SUSPENDED`.
4. Si une soumission de même empreinte existe déjà pour cette collecte : `200` avec l'accusé initial (**rejeu sans doublon**).
5. Si la collecte existe et que `base_revision` ≠ révision serveur : `409 REVISION_CONFLICT` avec l'état serveur.
6. Si la collecte existe et n'est ni `INVALIDEE` ni `REOUVERTE` : `423 LOCKED` (en attente de contrôle ou validée).
7. Rejeu complet des contrôles par `QuestionnaireEngine` : erreur bloquante ⇒ `422 VALIDATION_FAILED` avec les anomalies.
8. Écriture : `collections` (révision + 1, état `SOUMISE`, niveau 1), tables filles remplacées, `collection_submissions` (charge utile immuable), `collection_status_history`, `sync_logs`.

Réponse `201` (ou `200` pour un rejeu) :

```json
{ "data": { "uuid": "…", "status": "SOUMISE", "revision": 1, "received_at": "…", "warnings": [ { "code": "R_GPS_ACCURACY", "target": "GPS", "message": "…" } ] } }
```

Le mobile ne marque une collecte « Synchronisée » qu'à réception de cet accusé.

**Implémentation (lot L4) — précisions et écarts assumés par rapport à la liste ci-dessus :**

- **Le rejeu est testé en premier**, avant l'affectation et l'état de la campagne : une soumission déjà acceptée dont l'accusé s'est perdu reçoit toujours son accusé (`200`, `replayed: true`), même si la campagne a été clôturée ou la structure réaffectée entre-temps. Sinon la tablette garderait indéfiniment une collecte « non synchronisée » alors que le serveur l'a.
- **Empreinte** : SHA-256 du JSON canonique (clés triées récursivement) de `{campaign_structure_id, questionnaire_version, data}`. Rejeu ⇔ même empreinte **et** `base_revision + 1` = révision enregistrée **et** même agent. Renvoyer à l'identique après une invalidation (`base_revision` = révision courante) n'est donc pas un rejeu mais une nouvelle révision.
- Codes supplémentaires : `409 COLLECTION_EXISTS` (une autre collecte existe pour cette structure — `details.uuid`, à récupérer par `/sync/pull`), `409 QUESTIONNAIRE_VERSION_MISMATCH` (`details.expected`), `413 PAYLOAD_TOO_LARGE` (> 1 Mo), `423 CAMPAIGN_NOT_OPEN`.
- **Champs d'identité verrouillés** (1.1 nom, 1.2 code, 1.6.1–1.6.3 localisation) : toujours repris du registre ; une valeur différente envoyée par la tablette est remplacée et tracée (`validation_report.locked_overrides`). Les champs non verrouillés (1.6.4, 1.7, 1.8, 1.9) sont conservés tels que collectés ; les écarts avec le registre sont signalés au contrôleur (`identity_differences`), sans modifier le registre (D-07).
- **Contexte d'évaluation** (`_ctx`) calculé par le serveur : date du jour (heure du Niger), date d'ouverture de la campagne, distance GPS au centroïde de la commune (si les centroïdes sont fournis — ce n'est pas le cas du RENACOM actuel, le contrôle de distance est alors inopérant), seuils de `system_settings`, listes des métiers actifs par secteur (`trades.1..3` + `AUTRE`). Un `_ctx` envoyé par la tablette est ignoré.
- **Ventilation** : réponses simples et variables calculées dans `collection_answers` (une ligne par modalité cochée), formateurs, métiers, lignes « dont » et jeunes accompagnés dans leurs tables ; la charge utile complète est conservée dans `collection_submissions` (jamais modifiée).
- Chaque envoi (accepté, rejoué, refusé, en conflit) est journalisé dans `sync_logs`. Le corps peut être compressé (`Content-Encoding: gzip`).
- **Concurrence vérifiée par test** : 6 processus envoyant simultanément la même collecte ⇒ 1 création + 5 rejeux ; 2 versions différentes simultanées ⇒ 1 création + 1 `409 REVISION_CONFLICT`.

### 3.2 bis Questions non renseignées (décision du 14/11/2026)

Une question attendue (`required`) laissée vide produit un **avertissement** `REQUIRED` (« Non renseigné : cette question devrait normalement être remplie. »), jamais une erreur : la collecte est acceptée (`201`) et l'avertissement figure dans `warnings` pour le contrôleur. Les règles portant sur une question vide restent suspendues (pas de fausse anomalie). Les valeurs **invalides** (type, bornes, modalité inconnue, date) restent bloquantes (`422`).

### 3.2 ter Saisie sans blocage et test de connexion (15/11/2026)

- **Seules les valeurs impossibles bloquent** (`422`) : `TYPE` (texte dans un nombre…), `OPTION` (modalité inconnue), `RANGE` (hors bornes), `DATE` (date mal formée), `DUPLICATE` (ligne en double, fausserait les totaux). Les autres contrôles génériques (`PATTERN` du téléphone, `LENGTH`, `ROWS`, `GPS`) sont des **avertissements**. Moteurs PHP et Dart identiques (`QuestionnaireEngine::BLOCKING_CODES`).
- **Questionnaire 2025.3** : identique à 2025.2 (2.1 saisi librement, écart avec la Section IV signalé), mais **toutes les règles de cohérence sont des avertissements** (dates dans le futur, latrines, jeunes accompagnés, « dont » spéciaux, aucun apprenti, année de certification).
- **Montée de version en cours de campagne** : `PATCH /campaigns/{id}` accepte une version **plus récente du même questionnaire** même après réception de collectes (sinon `409`). Chaque collecte garde sa version : ses corrections sont acceptées avec cette version ; toute nouvelle collecte doit utiliser la version de la campagne (`409 QUESTIONNAIRE_VERSION_MISMATCH` sinon).
- `GET /health` (public, sans contrôle de version) → `{status, database, server_time, min_app_version}` ; `503` si la base ne répond pas. Utilisé par « Tester la connexion au serveur » sur la tablette.

### 3.3 Pièce jointe

`POST /collections/{uuid}/attachments?kind=FICHE_SIGNEE|AUTRE` (multipart, champ `file`, JPEG/PNG ≤ 3 Mo, type vérifié sur le contenu de l'image) — photo facultative de la fiche papier signée ; la collecte doit avoir été soumise ; renvoyer la même photo ne crée pas de doublon (`200`). Consultation : `GET /collections/{uuid}/attachments/{id}` (`collection.view`).

### 3.4 Structure découverte sur le terrain

`POST /structures/proposals` `{uuid, name, type_code, commune_id, locality_label, gps}` — uniquement dans une commune de la zone de l'agent (`agent_areas`). Le serveur renvoie les doublons potentiels (même commune et nom proche, ou point GPS à moins de 100 m). La structure reste `PROPOSEE` jusqu'à la décision du superviseur.

**Recensement sur le terrain (bouton « Nouveau centre » de la tablette)** — `POST /campaigns/{id}/field-structures` (`collection.submit`) `{uuid, name, type_code, other_type_label?, legal_status: PUBLIC|PRIVE, zone: URBAINE|RURALE, commune_id, locality_label?, latitude?, longitude?, gps_accuracy_m?}` : propose la structure (mêmes contrôles que ci-dessus : zone de l'agent → `403`, données → `422`), l'inscrit dans la campagne et l'affecte à l'agent, en une transaction. Réponse `201` `{campaign_id, campaign_structure_id, replayed, possible_duplicates[], structure{…}}` ; renvoyer le même `uuid` rend `200` avec la même inscription (idempotent). Campagne non `OUVERTE` → `423 CAMPAIGN_NOT_OPEN` ; structure déjà affectée à un autre agent → `409 ASSIGNED_TO_OTHER`. La collecte est ensuite soumise normalement (§3.2). Un doublon confirmé est fusionné par le superviseur.

## 4. Contrôle et validation (web)

| Méthode | Chemin | Permission | Description |
|---|---|---|---|
| GET | `/collections` | `collection.view` | Liste filtrée (année, campagne, région, département, commune, agent, type, statut, niveau) |
| GET | `/collections/{uuid}` | `collection.view` | Détail complet + anomalies + historique |
| GET | `/collections/{uuid}/submissions` | `collection.view` | Toutes les révisions |
| GET | `/collections/{uuid}/previous` | `collection.view` | Collecte de la même structure à la campagne précédente (comparaison N-1) |
| POST | `/collections/{uuid}/claim` | `collection.review` | Prise en charge → `EN_CONTROLE` (un seul contrôleur à la fois) |
| POST | `/collections/{uuid}/release` | `collection.review` | Libération → `SOUMISE` |
| POST | `/collections/{uuid}/validate` | `collection.review` | `{comment?}` — niveau 1 : passe au niveau 2 si la campagne en compte deux ; dernier niveau : `VALIDEE` |
| POST | `/collections/{uuid}/invalidate` | `collection.review` | `{reason_id, comment}` (motif obligatoire) → `INVALIDEE` |
| POST | `/collections/{uuid}/reopen` | `collection.reopen` (admin) | `{reason}` obligatoire, journalisé → `REOUVERTE` |

Niveau 1 : superviseur départemental dont le périmètre couvre le département de la collecte. Niveau 2 : superviseur régional de la région. Un même utilisateur ne peut pas valider les deux niveaux d'une même collecte.

**Implémentation (lot L4) :**

- Niveau 1 ouvert aux rôles `SUP_DEP`, `SUP_REG` et `ADMIN` (un département sans superviseur ne bloque pas la validation) ; niveau 2 à `SUP_REG` et `ADMIN` ; toujours dans le périmètre de l'utilisateur (hors périmètre ⇒ `404`). `403 SAME_REVIEWER` si la même personne tente les deux niveaux d'une même révision.
- `validate` et `invalidate` sont acceptés sur une collecte `SOUMISE` (prise en charge implicite) ou `EN_CONTROLE` par l'appelant ; prise en charge par un autre ⇒ `409 ALREADY_CLAIMED`. `release` : contrôleur ou administrateur.
- `invalidate` : `{reason_id | reason_code, comment}` ; motifs listés par `GET /rejection-reasons` ; commentaire obligatoire pour le motif `AUTRE`. La collecte repart au niveau 1 après correction.
- `reopen` (administrateur) : `VALIDEE → REOUVERTE`, motif obligatoire ; l'agent peut alors resoumettre (campagne ouverte).
- Campagne `ARCHIVEE` ⇒ `423` ; les décisions restent possibles après la clôture de la collecte.
- `GET /collections` : filtres `campaign_id`, `academic_year_id`, `status`, `review_level`, `region_id`, `department_id`, `commune_id`, `agent_uuid`, `type_code`, `q`, et `awaiting_me=1` (ce que l'appelant peut traiter maintenant). Le détail renvoie les données de la dernière révision, les variables calculées, les avertissements, les écarts avec le registre, les décisions, l'historique, les pièces jointes et `allowed_actions`.

**Validation groupée** — `POST /collections/validate-batch` (`collection.review`) `{uuids: [1..200], comment?}` → `{validated: [uuid…], failed: [{uuid, message}]}`. Chaque collecte passe par la validation unitaire (droits, niveau, prise en charge, superviseur différent au niveau 2, journal) ; un refus n'empêche pas les autres.

### 4.1 Synchronisation descendante — précisions (lot L4)

`GET /sync/pull?since=&campaign_id=` (`collection.submit`) : campagnes de l'agent (non archivées), affectations actives modifiées depuis le curseur (identité de la structure codée comme dans le questionnaire : `zone` a/b, `legal_status` a/b, `type_code`), `removed_assignments` (affectations retirées ou réaffectées), et collectes de l'agent avec le dernier retour du superviseur ; pour une collecte `INVALIDEE` ou `REOUVERTE`, `editable: true` et `data` (dernière révision), ce qui permet la correction sur une autre tablette ou par un agent réaffecté. Curseur : horodatage serveur (comparaison inclusive ; un élément peut revenir deux fois, à appliquer par remplacement). Curseur illisible ⇒ `422` : reprendre une synchronisation complète (sans `since`).

### 4.2 Contexte de contrôle transmis à la tablette (lot L7)

`/sync/pull` renvoie aussi `context` : `{gps_max_km, max_apprentices_per_trainer, options: {"trades.1": [ids…, "AUTRE"], …}}` — les mêmes seuils (`system_settings`) et listes de métiers admis que ceux du serveur à la soumission (classe `EvaluationContext`, partagée). La tablette applique donc hors ligne exactement les contrôles du serveur. Chaque campagne porte `collection_mode`.

### 4.3 Saisie web et saisie de fiches papier (lot L6b)

Les agents (rôle `AGENT`, permission `collection.submit`) saisissent indifféremment sur la tablette ou dans le back-office.

| Méthode | Chemin | Description |
|---|---|---|
| GET | `/entry/assignments` | Structures affectées à l'agent dans les campagnes ouvertes : `state` (`A_SAISIR`, `BROUILLON`, `A_CORRIGER`, `SOUMISE`), `allowed_channels` |
| GET | `/entry/{campaign_structure_id}` | Définition du questionnaire de la campagne, identité de la structure, `uuid` et `base_revision` à utiliser, données de départ (`source` : `BROUILLON`, `DERNIERE_REVISION` ou `PREREMPLISSAGE`), libellés des métiers, contrôle à blanc (`validation`) |
| PUT | `/entry/{campaign_structure_id}/draft` | `{data, channel: WEB\|PAPIER, paper?}` — brouillon conservé sur le serveur (sauvegarde automatique) ; renvoie le contrôle à blanc (mêmes règles et même contexte que la soumission) |
| DELETE | `/entry/{campaign_structure_id}/draft` | Abandon du brouillon |

La soumission passe par `POST /collections/{uuid}/submissions` (§3.2), avec en plus, en session web : `channel` (`WEB` par défaut, ou `PAPIER`) et, pour le papier, `paper: {form_ref, interview_date, enumerator}` (numéro de la fiche, date de l'entretien, nom de l'enquêteur qui l'a remplie ; obligatoires, sinon `422` avec `paper.*`). Une soumission par jeton porteur est toujours `MOBILE`. Le brouillon web est supprimé à l'acceptation.

**Mode de collecte de la campagne** (`collection_mode`, modifiable même après l'ouverture, par exemple si les tablettes manquent) :

| Mode | Canaux acceptés |
|---|---|
| `TABLETTE` (défaut) | `MOBILE`, `WEB` |
| `PAPIER` | `PAPIER` uniquement (la tablette refuse de commencer une collecte) |
| `MIXTE` | `MOBILE`, `WEB`, `PAPIER` |

Canal refusé ⇒ `422 CHANNEL_NOT_ALLOWED`. Le canal et les références papier sont enregistrés sur la collecte (`collections.channel`, `paper_*`) et sur chaque révision (`collection_submissions.channel`, références dans la charge utile), et figurent dans l'export des microdonnées.

### 4.4 Versions de l'application mobile (APK)

| Méthode | Chemin | Accès | Description |
|---|---|---|---|
| GET | `/app/releases` | `settings.manage` | Versions publiées et retirées (la plus récente d'abord) |
| POST | `/app/releases` | `settings.manage` | Multipart : `file` (APK, 300 Mo au plus, archive vérifiée : `AndroidManifest.xml` + `classes.dex`), `version` (X.Y.Z, supérieure à toutes les versions publiées), `notes`, `mandatory` |
| POST | `/app/releases/{id}/retire` | `settings.manage` | Retire une version (n'est plus proposée ; ne compte plus comme obligatoire) |
| GET | `/app/releases/latest` | authentifié, **toute version** | Dernière version publiée pour la version installée (`X-App-Version`) : `update_available`, `required`, `min_version`, `sha256`, `notes`, `download_path` (lien signé, valable 24 h) |
| GET | `/app/releases/{id}/download?expires=&signature=` | lien signé, **toute version** | Téléchargement de l'APK (le navigateur Android propose ensuite l'installation) |

Version minimale acceptée (`426 UPGRADE_REQUIRED`) = la plus élevée entre `EFTP_MIN_APP_VERSION` et la dernière version publiée « obligatoire ». Les deux routes marquées « toute version » restent accessibles à une application trop ancienne, pour qu'elle puisse se mettre à jour. **Toutes les versions doivent être signées avec la même clé** : sinon Android refuse la mise à jour, et désinstaller l'ancienne application effacerait les collectes non envoyées.

Les nouvelles versions du **questionnaire** n'exigent pas de nouvel APK : la définition attribuée à une campagne est téléchargée par la tablette à la synchronisation (signalé à l'agent).

### 4.5 Carte des centres

`GET /stats/map?campaign_id=` (`stats.view`) : centres ciblés par la campagne dans le périmètre de l'utilisateur, avec la position relevée à la collecte (`source` : `GPS` tablette, `SAISIE` coordonnées reportées) ou, à défaut, celle du registre (`REGISTRE`) ; `legal` (`PUBLIC`, `PRIVE`, `INCONNU`), `zone`, type, état de la collecte (`NON_COLLECTEE` si absente), canal, apprentis, formateurs ; `unplaced` compte les centres sans coordonnées. Page « Carte des centres » du back-office : Leaflet hébergé localement ; trois fonds au choix (mémorisé dans le navigateur) — **Plan** (OpenStreetMap), **Satellite** (imagerie Esri World Imagery) et **Hybride** (imagerie + limites, noms de lieux et routes Esri). Seules ressources externes, autorisées dans la CSP `img-src` (`tile.openstreetmap.org`, `server.arcgisonline.com`) ; elles exigent un accès Internet. **À vérifier avant la mise en production** : les conditions d'utilisation des services de tuiles Esri (compte ArcGIS ou accord d'usage selon le volume) et la politique d'usage des tuiles OpenStreetMap (faible volume ; un serveur de tuiles dédié est recommandé pour un usage intensif).

### 4.5 bis Tableau de bord de pilotage

`GET /stats/overview?campaign_id=` (`stats.view`, périmètre de l'utilisateur) : centres ciblés, affectés, collectés par état ; taux de couverture et de validation ; **rythme** (collectes du jour, des 7 derniers jours, moyenne journalière, rythme requis pour finir à la clôture, date d'achèvement projetée, série des 30 derniers jours) ; **contrôle** (stock à contrôler, ancienneté, collectes en attente depuis plus de 3 jours, délai médian et moyen de validation, décisions, taux de refus au premier contrôle, motifs de refus) ; **qualité** (avertissements, fiches signées, statut et précision du GPS) ; canaux de saisie ; agents actifs sur 7 jours ; couverture par région. `GET /stats/indicators` renvoie en plus `structures.by_zone` et `structures.by_legal_status` (questions 1.7 et 1.8, libellés du questionnaire).

### 4.6 Capacité mesurée

Mesure en processus (`tests/Integration/SubmissionThroughputTest.php`, lancée avec `EFTP_PERF=1`) le 13/11/2026 sur le poste de développement (XAMPP, PHP 8.2, MariaDB 10.4) : 300 soumissions complètes (contrôle du questionnaire, écriture, ventilation, journal) — moyenne 53,6 ms, p95 66,6 ms, soit ≈ 19 soumissions/s pour **un seul** processus PHP. L'objectif terrain de 5 000 soumissions par jour représente ≈ 0,06/s en moyenne (≈ 0,7/s si tout arrivait en 2 h). **Ce n'est pas un test de charge** : coût HTTP/TLS, concurrence de centaines de tablettes et volumétrie de production ne sont pas couverts ; le test de charge réel est prévu au lot L8.

## 5. Administration

| Ressource | Chemins | Permission |
|---|---|---|
| Utilisateurs | `GET/POST /users`, `GET/PATCH /users/{uuid}`, `POST /users/{uuid}/activate`, `/deactivate`, `/reset-password`, `PUT /users/{uuid}/scopes` | `user.manage` |
| Import d'agents | `GET /imports/users/template`, `POST /imports/users` (envoi → prévisualisation), `POST /imports/{id}/commit`, `GET /imports/{id}/report` | `user.import` |
| Appareils | `GET /users/{uuid}/devices`, `POST /devices/{id}/revoke` | `user.manage` |
| Territoire | `GET /regions`, `/departments?region_id=`, `/communes?department_id=`, `POST /imports/territory` | lecture : authentifié ; import : `territory.manage` |
| Structures | `GET/POST /structures`, `GET/PATCH /structures/{uuid}`, `GET /structures/{uuid}/history`, `POST /structures/{uuid}/approve`, `/reject`, `/merge` | `structure.manage` |
| Années | `GET/POST /academic-years`, `PATCH /academic-years/{id}` | `campaign.manage` |
| Campagnes | `GET/POST /campaigns`, `PATCH /campaigns/{id}`, `POST /campaigns/{id}/transition` `{to, reason}`, `POST /campaigns/{id}/structures` (ciblage en masse), `POST /campaigns/{id}/clone-from/{source}` | `campaign.manage` |
| Affectations | `GET /campaigns/{id}/assignments?unassigned=1`, `POST /campaigns/{id}/assignments` (en masse `{agent_id, campaign_structure_ids[]}`), `DELETE /assignments/{id}` (clôture), `PUT /campaigns/{id}/agents/{agent}/areas` | `assignment.manage` |
| Questionnaires | `GET /questionnaires`, `POST /questionnaires/{code}/versions`, `POST /questionnaire-versions/{id}/publish` | `questionnaire.manage` |
| Paramètres | `GET/PATCH /settings` — implémenté (L5) : `PATCH` `{clé: valeur}`, type contrôlé selon `value_type`, chaque modification journalisée | `settings.manage` |
| Journaux | `GET /audit-logs?action=&entity_type=&entity_id=`, `GET /login-logs?identifier=&channel=`, `GET /sync-logs?outcome=&collection_uuid=` (+ `matricule`, `from`, `to`, pagination) — implémenté (L5) | `audit.view` |

### 5.1 Utilisateurs (implémenté — lot L2)

| Méthode | Chemin | Permission | Corps / réponse |
|---|---|---|---|
| GET | `/users?q=&role=&status=&region_id=&department_id=&supervisor_uuid=&page=&per_page=` | `user.view` | Liste paginée, **restreinte au périmètre** de l'appelant |
| POST | `/users` | `user.manage` | `{matricule, last_name, first_name, sex, phone?, email?, role, scopes:[{level, region_id\|department_id\|commune_id}], supervisor_uuid?}` → `201 {user, temporary_password}` |
| GET | `/users/{uuid}` | `user.view` | Fiche (rôle, périmètres libellés, superviseur, nombre de subordonnés et d'appareils actifs) |
| PATCH | `/users/{uuid}` | `user.manage` | Champs partiels ; `role` et `scopes` peuvent changer ensemble ; le **matricule est immuable** |
| PUT | `/users/{uuid}/scopes` | `user.manage` | `{scopes:[…]}` ; `409 SUBORDINATES_OUT_OF_SCOPE` si des comptes supervisés ne seraient plus couverts |
| POST | `/users/{uuid}/activate`, `/deactivate` | `user.manage` | `{reason?}` ; la désactivation ferme immédiatement toutes les sessions (web et tablettes) |
| POST | `/users/{uuid}/reset-password` | `user.manage` | → `{user, temporary_password}` ; déverrouille, ferme les sessions, changement obligatoire à la connexion |
| GET | `/users/{uuid}/devices` | `user.manage` | Tablettes connues du compte |
| POST | `/devices/{id}/revoke` | `user.manage` | `{reason?}` → `204` ; jetons de l'appareil révoqués, connexion refusée ensuite (`DEVICE_REVOKED`) |
| GET | `/regions`, `/departments?region_id=`, `/communes?department_id=` | authentifié | Listes pour les formulaires |

Règles (appliquées par `UserService`, quelle que soit l'interface) :

- **Rôle ↔ niveau de périmètre** : ADMIN = NATIONAL (unique) ; SUP_REG = REGION ; SUP_DEP = DEPARTMENT ; AGENT = DEPARTMENT ou COMMUNE ; VIEWER = NATIONAL, REGION ou DEPARTMENT. Plusieurs lignes possibles (sauf NATIONAL).
- **Visibilité** : un compte n'est visible que si **tout** son périmètre est inclus dans celui de l'appelant ; sinon `404`. On voit toujours son propre compte.
- **Pas d'élévation de privilèges** : hors administrateur national, on n'attribue que des rôles de rang inférieur au sien (ADMIN 4 > SUP_REG 3 > SUP_DEP 2 > AGENT, VIEWER 1), dans un périmètre inclus dans le sien (`403 FORBIDDEN` / `OUT_OF_SCOPE`). On ne gère pas un compte de rang égal ou supérieur.
- **Superviseur** : AGENT ← SUP_DEP ou SUP_REG ; SUP_DEP ← SUP_REG ; SUP_REG ← ADMIN ; il doit être actif et son périmètre doit couvrir celui du compte.
- **Auto-protection** : personne ne modifie son propre rôle, périmètre ou statut.
- **Unicité** du matricule, du téléphone et de l'e-mail (comptes supprimés compris). Téléphone normalisé en `+227XXXXXXXX` ; il est aussi accepté à la connexion sous la forme locale (`90123456`).
- **Mot de passe provisoire** : 12 caractères aléatoires lisibles (`Kx7m-Pq4r-Tz9w`, sans 0/O/1/l/I), affiché une seule fois. Il est haché en Argon2id à coût réduit (≈ 7 ms au lieu de ≈ 200 ms) : son entropie (≈ 70 bits) rend l'attaque hors ligne impraticable, et il est re-haché au coût standard dès la première connexion, où son changement est obligatoire. Sans cela, créer 500 comptes demanderait ≈ 100 s de calcul.
- Toute action est journalisée (`USER_CREATE`, `USER_UPDATE`, `USER_ACTIVATE`, `USER_DEACTIVATE`, `USER_PASSWORD_RESET`, `DEVICE_REVOKE`).

### 5.2 Import Excel des agents (implémenté — lot L2)

| Méthode | Chemin | Description |
|---|---|---|
| GET | `/imports/users/template` | Modèle `.xlsx` propre à l'utilisateur (limité à son périmètre) : feuille « Agents » où **Sexe, Rôle, Région, Département, Commune et Superviseur se choisissent par liste déroulante** — Département en cascade de la Région et Commune en cascade du Département (plages nommées `L_REG_<id>`, `L_DEP_<id>` + `INDIRECT`) ; feuille masquée « Listes » (libellés et correspondances) ; « Notice » ; « Territoire » (noms et codes RENACOM). Libellés affichés (« M - Masculin », « Agent recenseur (AGENT) », « MAT — Nom Prénom (SUP_DEP) », « NOM (code) » pour un homonyme) **traduits en codes à l'import** ; les anciennes saisies (codes, noms) restent acceptées. |
| POST | `/imports/users` | `multipart/form-data`, champ `file` (`.xlsx`, 5 Mo, 5 000 lignes max.) → `201` prévisualisation : `{id, status: PREVISUALISE, total_rows, valid_rows, error_rows, can_commit, fatal_errors, by_role, rows[{line, matricule, name, role, scope, supervisor, errors[]}]}`. **Aucun compte n'est créé.** |
| GET | `/imports/{id}` | État et détail |
| POST | `/imports/{id}/commit` | Recontrôle complet du fichier (la base a pu changer), puis création de **tous** les comptes en une transaction, ou d'aucun (`422 IMPORT_REJECTED` avec la liste des anomalies). → `{import, created, archive_password}` |
| POST | `/imports/{id}/cancel` | Abandon d'un import non validé |
| GET | `/imports/{id}/report` | Avant validation : rapport de contrôle ligne par ligne (`.xlsx`). Après : **archive ZIP chiffrée AES-256** contenant les identifiants et mots de passe provisoires (à ouvrir avec 7-Zip ; l'explorateur Windows ne lit pas l'AES). Le mot de passe de l'archive n'est renvoyé qu'une fois par `commit` et n'est jamais stocké. Archive expirée après 7 jours (`410`). Chaque téléchargement est journalisé. |

Permission `user.import` ; un import n'est visible que de son auteur (et de l'administrateur national). Contrôles par ligne : colonnes obligatoires, formats (matricule, sexe, téléphone, e-mail), doublons dans le fichier et en base, région / département / commune reconnus par nom (accents et casse ignorés ; « NIAMEY » accepté pour « VILLE DE NIAMEY ») ou par code RENACOM, cohérence rôle ↔ territoire, superviseur (dans le fichier ou existant), droits de l'importateur. Le fichier est lu par le seul lecteur Xlsx de PhpSpreadsheet (signature ZIP vérifiée, aucun autre format interprété) et stocké hors racine web.

### 5.3 Structures (implémenté — lot L3)

| Méthode | Chemin | Permission | Description |
|---|---|---|---|
| GET | `/structures?q=&status=&type_code=&region_id=&department_id=&commune_id=&page=` | `structure.view` | Registre, restreint au périmètre (`q` : nom ou début de code) |
| POST | `/structures` | `structure.manage` | `{official_code, name, type_code, other_type_label?, legal_status?, zone?, commune_id, locality_label?, latitude?, longitude?, gps_accuracy_m?, authorization_date?, opening_date?, confirm_duplicates?}` → `201` structure `ACTIVE` |
| POST | `/structures/proposals` | `structure.propose` (agent) | `{uuid, name, type_code, commune_id, locality_label?, gps:{lat, lng, accuracy}?}` → `201 {structure, possible_duplicates, replayed}` ; **idempotent** (même UUID rejoué ⇒ `200`, `replayed: true`) ; commune du périmètre de l'agent ou de sa zone de travail ; code officiel ignoré |
| GET | `/structures/{uuid}`, `/structures/{uuid}/history` | `structure.view` | Fiche ; historique (action, avant/après, motif, auteur) |
| PATCH | `/structures/{uuid}` | `structure.manage` | Modification partielle, `reason?` ; changement de commune refusé si la structure a des collectes |
| POST | `/structures/{uuid}/approve` | `structure.manage` | `{official_code, comment?}` : `PROPOSEE → ACTIVE` (code obligatoire et unique) |
| POST | `/structures/{uuid}/reject` | `structure.manage` | `{reason}` : `PROPOSEE → REJETEE` |
| POST | `/structures/{uuid}/merge` | `structure.manage` | `{target_uuid, reason}` : doublon → `FUSIONNEE` ; refusé si le doublon a des collectes ou est ciblé par une campagne non clôturée |
| POST | `/structures/{uuid}/close`, `/reopen` | `structure.manage` | `{reason}` : `ACTIVE ⇄ FERMEE` |

Règles : code officiel à 10 chiffres **unique** (bloquant) et obligatoire pour une structure `ACTIVE` ; coordonnées dans l'emprise du Niger ; dates non futures. **Doublons probables** (même commune et nom identique ou proche — mots génériques ignorés, distance d'édition faible — ou point GPS à moins de 100 m) : `409 DUPLICATE_SUSPECTED` avec `details.candidates[]`, création possible en renvoyant `confirm_duplicates: true` (tracé dans l'historique). Le village/quartier saisi alimente `localities` (source `TERRAIN`, statut `PROPOSEE`).

### 5.4 Années et campagnes (implémenté — lot L3)

| Méthode | Chemin | Permission | Description |
|---|---|---|---|
| GET | `/academic-years` | authentifié | Liste |
| POST | `/academic-years` | `campaign.manage` | `{code: "2026-2027", start_date, end_date, label?}` |
| PATCH | `/academic-years/{id}` | `campaign.manage` | `label`, dates, `status` (`PREPARATION → ACTIVE → CLOTUREE` ; clôture refusée tant qu'une campagne de l'année n'est pas clôturée) |
| GET | `/campaigns?academic_year_id=&status=`, `/campaigns/{id}` | authentifié | Avec `allowed_transitions`, `accepts_submissions`, et `targeted / assigned / unassigned` calculés **dans le périmètre de l'appelant** ; le détail inclut l'historique des états |
| POST | `/campaigns` | `campaign.manage` | `{academic_year_id, code, name, questionnaire_version: "2025.1", opens_on, closes_on, validation_levels: 1\|2, instructions?}` |
| PATCH | `/campaigns/{id}` | `campaign.manage` | Intitulé, dates, consignes ; `questionnaire_version` et `validation_levels` **figés dès l'ouverture** (`409`) ; campagne clôturée ⇒ `423 LOCKED` |
| POST | `/campaigns/{id}/transition` | `campaign.manage` | `{to, reason}` ; motif obligatoire pour `SUSPENDUE`, `CLOTUREE`, `ARCHIVEE` |
| POST | `/campaigns/{id}/structures` | `campaign.manage` | Ciblage idempotent : `{structure_uuids: [...]}` et/ou `{filter: {region_id\|department_id\|commune_id} \| {all: true}}` → `{added, already_targeted, ignored[]}` ; structures `ACTIVE` uniquement |
| DELETE | `/campaigns/{id}/structures/{uuid}` | `campaign.manage` | Retrait du ciblage (refusé si une collecte existe) |
| POST | `/campaigns/{id}/clone-from/{source}` | `campaign.manage` | `{copy_assignments?}` : reconduction vers une campagne en préparation (structures encore actives ; affectations et zones dont l'agent est actif et couvre toujours la commune). Rejouable sans doublon ; la source n'est pas modifiée |

Ouverture (`→ OUVERTE`) exigée : version de questionnaire publiée, année non clôturée, date de fin non dépassée (sinon `409` avec `details.problems[]`). Une campagne peut s'ouvrir **sans structure ciblée** : les agents recensent alors les centres sur le terrain (§3.4). La **version du questionnaire** reste modifiable (`PATCH`) tant qu'aucune collecte n'a été reçue (ni soumission ni brouillon web), même campagne ouverte ; ensuite `409`. Les tablettes téléchargent la nouvelle version au pull et y basculent leurs collectes non envoyées. Seule une campagne `OUVERTE` accepte des soumissions : `CampaignService::assertAcceptsSubmissions()` renvoie `423 CAMPAIGN_SUSPENDED`, `CAMPAIGN_NOT_OPEN` ou `CAMPAIGN_CLOSED` (utilisé par la collecte, lot L4).

### 5.5 Affectations (implémenté — lot L3)

| Méthode | Chemin | Permission | Description |
|---|---|---|---|
| GET | `/campaigns/{id}/assignments?unassigned=1&agent_uuid=&commune_id=&department_id=&q=` | `assignment.manage` | Structures ciblées du périmètre, avec agent actif et état de la collecte |
| POST | `/campaigns/{id}/assignments` | `assignment.manage` | `{agent_uuid, campaign_structure_ids?: [], structure_uuids?: []}` → `{assigned, reassigned, unchanged}` |
| DELETE | `/assignments/{id}` | `assignment.manage` | `{reason?}` : clôture (historique conservé) |
| PUT | `/campaigns/{id}/agents/{agent_uuid}/areas` | `assignment.manage` | `{commune_ids: []}` : zones de travail de l'agent pour la campagne (seules celles du périmètre de l'appelant sont remplacées) |

Règles : **un seul agent actif par structure et par campagne** (garanti aussi par un index unique en base) ; une réaffectation clôt l'affectation précédente (`end_reason = 'Réaffectation'`). L'agent doit être actif, visible de l'appelant, et son périmètre ou sa zone de travail doit couvrir la commune. Traitement en masse **tout-ou-rien** (`422` avec la liste des anomalies). Réaffectation, retrait d'affectation et retrait du ciblage sont refusés dès qu'une collecte a été remise (`SOUMISE`, `EN_CONTROLE`, `VALIDEE`) : les données reçues ne changent pas de propriétaire silencieusement. Campagne clôturée : `423`.

Transitions de campagne autorisées : `PREPARATION→OUVERTE`, `OUVERTE→SUSPENDUE`, `SUSPENDUE→OUVERTE`, `OUVERTE|SUSPENDUE→CLOTUREE`, `CLOTUREE→ARCHIVEE`. Toute autre transition ⇒ `409`. Le passage à `OUVERTE` exige une version de questionnaire publiée.

## 6. Statistiques et exports

| Méthode | Chemin | Description |
|---|---|---|
| GET | `/stats/progress?campaign_id=&level=region\|department\|commune` | Couverture, collectées, validées, invalidées, en attente, non collectées |
| GET | `/stats/agents?campaign_id=` | Performance et retards des agents (périmètre de l'utilisateur) |
| GET | `/stats/timeline?campaign_id=&granularity=day\|week` | Évolution des soumissions et validations |
| GET | `/stats/indicators?campaign_id=&basis=validated\|submitted` | Indicateurs du questionnaire (apprentis, formateurs, vulnérables, infrastructures…) |
| GET | `/stats/compare?campaign_ids=3,7` | Comparaison pluriannuelle (variables mises en correspondance ; non comparables signalées) |
| POST | `/exports` | `{format: xlsx\|csv\|pdf, dataset, filters}` → tâche de fond `{job_id}` |
| GET | `/jobs/{id}` | Avancement ; lien de téléchargement à la fin |
| GET | `/files/{uuid}` | Téléchargement (propriétaire uniquement, lien à durée limitée) |

Toutes les statistiques et tous les exports appliquent le périmètre de l'utilisateur.

**Implémentation (lot L6) :**

- `/stats/*` : permission `stats.view`. Base des indicateurs : `validated` (collectes `VALIDEE`) ou `submitted` (`SOUMISE`, `EN_CONTROLE`, `VALIDEE`) ; défaut : paramètre `stats_basis`. Les collectes `INVALIDEE` ou `REOUVERTE` (en correction) ne sont jamais comptées. `indicators` accepte `group_by=region|department` (tableau par unité). Indicateurs : structures (par type), apprentis (sexe, durée, secteur, 15 métiers les plus suivis), publics vulnérables, formateurs (sexe, grade, certification, nationalité, ratio apprentis/formateur), jeunes accompagnés, taux de « Oui » des questions Oui/Non, fréquences des choix multiples.
- `compare` : 2 à 6 campagnes ; `comparable: false` et une note si les versions de questionnaire diffèrent.
- `POST /exports` (`export.create`) → `202` avec la tâche : `{dataset: collections|indicators|progress|agents, format: xlsx|csv|pdf, filters: {campaign_id, basis?, level?}}`. Formats : microdonnées xlsx/csv ; indicateurs xlsx/pdf ; avancement xlsx/csv/pdf ; agents xlsx/csv. `GET /exports` : mes 30 derniers exports. Fichiers téléchargeables 7 jours par leur seul demandeur (`410` après expiration).
- **Microdonnées (classeur prêt pour l'annuaire)** :
  - `donnees_generale_centre` : une ligne par centre, déclarée en **tableau Excel** (filtres, tri, source directe de tableaux croisés dynamiques). En-têtes en clair (« 1.9 Type de structure », « Total apprentis (section IV) »…), valeurs en clair (Oui/Non, libellés des modalités, choix multiples séparés par « ; »).
  - `T01_centres_type` à `T11_financement` : tableaux croisés précalculés (T01 centres par type ; T02 par statut et zone ; T03 apprentis par sexe ; T04 par secteur ; T05 par durée ; T06 par métier ; T07 publics vulnérables ; T08 formateurs ; T09 jeunes accompagnés ; T10 équipements, nombre de « Oui » ; T11 financement). **Chaque tableau commence par les colonnes Région, Département, Commune** : une ligne par commune, puis « Ensemble du département », « Ensemble de la région » (en gras) et « Total ». Les taux et ratios sont recalculés à chaque niveau. Modèle proposé, **à valider par la DSD**.
  - `formateurs`, `metiers_apprentis`, `apprentis_vulnerables`, `jeunes_accompagnes` : détail par centre (tableaux Excel, valeurs en clair), reliable à la feuille principale par « Identifiant de la collecte » ou « Code du centre ».
  - `dictionnaire_variables` (colonne en clair, code de la variable, question, type, modalités) et `lisez_moi` (campagne, base, nombre de centres, date, mode d'emploi des feuilles).
  - En CSV : la seule table `donnees_generale_centre`. **Données personnelles exclues** : noms et téléphones des répondants (section V), noms des formateurs.
- Excel produit en flux (mémoire constante, sans limite de lignes) ; CSV en UTF-8 avec BOM, séparateur « ; », **formules neutralisées** (un texte commençant par `=`, `+`, `-` ou `@` est préfixé d'une apostrophe) ; PDF via Dompdf sans aucune ressource externe.
- Traitement : immédiat si `EFTP_EXPORTS_INLINE=1` (développement) ; sinon file `jobs` traitée par `php bin/worker.php` (tâche planifiée), avec prise exclusive de chaque tâche et purge des fichiers expirés. Le périmètre est recalculé au traitement : un compte désactivé entre-temps n'obtient rien.
