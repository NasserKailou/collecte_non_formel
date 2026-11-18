# Format du questionnaire et langage de règles

Ce document est la **spécification commune** des deux moteurs qui interprètent le questionnaire : le moteur PHP (serveur, `backend/src/Questionnaire`) et le moteur Dart (mobile). Les deux doivent produire les mêmes résultats sur les vecteurs de test partagés de `shared/rules-fixtures/`.

## 1. Fichiers

| Fichier | Rôle |
|---|---|
| `scripts/questionnaire/build_eftp_nf_2025.php` | **Source** de la définition (génère les parties répétitives : grilles, contrôles « dont ≤ total ») |
| `shared/questionnaire/eftp-nf-2025.1.json` | Définition générée, publiée dans `questionnaire_versions.definition_json` |
| `shared/rules-fixtures/jsonlogic.json` | Vecteurs de test de l'évaluateur d'expressions |
| `shared/rules-fixtures/eftp-nf-2025.1.json` | Vecteurs de test du moteur de questionnaire : une collecte fictive sans anomalie (`base`) et 44 variantes (`cases`) avec les anomalies, effacements et valeurs calculées attendus |
| `backend/tests/Unit/SharedFixturesTest.php` | Exécution des vecteurs côté PHP (le test Dart équivalent sera écrit au lot L7) |

État au 12/11/2026 : 75 éléments, 51 variables calculées, 124 contrôles ; 118 tests PHPUnit réussis (71 vecteurs JsonLogic, 44 scénarios de questionnaire, 3 contrôles de cohérence de la définition).

Une version publiée est **immuable** : toute modification produit une nouvelle version (`2025.2`, `2026.1`…).

## 2. Données évaluées

Le moteur évalue un objet de données unique construit à partir de la charge utile d'une collecte :

```json
{
  "Q1_1": "Atelier de couture Al Baraka",
  "Q1_3": true,
  "Q1_10": ["a", "g"],
  "accompanied": { "LE3": { "INSCRITS": { "M": 4, "F": 6 }, "PLACES": { "M": 1, "F": 2 }, "OUTILLES": { "M": 0, "F": 1 } } },
  "trainers": [ { "full_name": "…", "sex": "M", "grade": "FORMATEUR", "certified": false } ],
  "sectors": {
    "s1": { "trades": [ { "trade": 1, "duration": "M4_9", "p1_m": 3, "p1_f": 0 } ],
            "specials": { "HANDICAPES": { "p1_m": 0 }, "REFUGIES": { } } }
  },
  "GPS": { "lat": 13.51, "lng": 2.11, "accuracy_m": 8, "captured_at": "…", "status": "OK", "note": null },
  "_ctx": { "today": "2026-11-12", "current_year": 2026, "campaign_opens_on": "2026-01-05", "gps_distance_km": null, "gps_max_km": 15 }
}
```

- Oui/Non = booléens JSON. Choix unique = code de modalité. Choix multiple = tableau de codes. Dates = chaînes ISO `AAAA-MM-JJ` (ou `AAAA` / `AAAA-MM` si le champ autorise une date partielle) ; les dates se comparent comme des chaînes, ce qui est correct en format ISO.
- `_ctx` est fourni par l'appelant (date du jour, paramètres de campagne) ; il n'est jamais saisi.
- Les variables calculées (`computed`) sont ajoutées à cet objet avant l'évaluation des contrôles.

## 3. Structure de la définition

```json
{
  "schema": 1,
  "questionnaire": "EFTP_NF",
  "version": "2025.1",
  "title": "…",
  "enums": { "duration": [ { "code": "LE3", "label": "≤ 3 mois" } ] },
  "sections": [ { "code": "S1", "number": "I", "title": "…", "items": [ ] } ],
  "computed": [ { "code": "Q2_19.M", "expr": { } } ],
  "rules": [ { "code": "R_…", "severity": "error|warning", "targets": ["…"], "when": { }, "assert": { }, "message": "…" } ]
}
```

### 3.1 Éléments (`items`)

Propriétés communes : `code` (chemin de la donnée), `number` (numéro affiché), `label`, `help`, `type`, `required` (bool), `visible_if` (expression), `prefill` (source de pré-remplissage), `locked` (non modifiable par l'agent), `group` (regroupement visuel).

| Type | Valeur | Paramètres | Contrôles génériques |
|---|---|---|---|
| `text` | chaîne | `min_length`, `max_length`, `pattern`, `multiline` | longueur, motif |
| `phone` | chaîne | — | `^[0-9]{8}$` |
| `integer` | entier | `min`, `max` | type, bornes |
| `date` | chaîne ISO | `partial` (accepte `AAAA` et `AAAA-MM`) | format, date réelle |
| `yesno` | booléen | — | type |
| `single` | code | `options` **ou** `options_source` (référentiel) | code existant |
| `multi` | tableau de codes | `options` | codes existants, sans doublon ; obligatoire ⇒ au moins un |
| `gps` | objet | — | obligatoire ⇒ coordonnées valides, **ou** statut `INDISPONIBLE`/`REFUSE` avec motif |
| `grid` | objet `ligne.colonne` | `rows`, `columns`, `cell` (type + bornes), `totals` | chaque cellule contrôlée comme un `integer` |
| `roster` | tableau d'objets | `columns` (éléments), `min_rows`, `max_rows`, `unique`, `row_rules` | chaque colonne contrôlée ligne par ligne ; unicité ; règles de ligne |
| `computed`, `computed_grid` | — | `source` (chemin calculé) | aucun (affichage en lecture seule) |
| `auto` | — | `source` | rempli par le serveur (enquêteur, superviseur) |
| `geo` | identifiant | `level` | pré-rempli depuis le référentiel des structures, verrouillé |

Pour `single` avec `options_source`, l'appelant fournit la liste des codes admis dans `_ctx.options.<source>` ; si elle est absente, le contrôle d'appartenance est ignoré (le serveur la fournit toujours).

### 3.2 Ordre d'évaluation (identique dans les deux moteurs)

1. Parcours des éléments dans l'ordre de la définition. Pour chacun : évaluation de `visible_if` sur les données **déjà nettoyées**.
   - Élément masqué : sa valeur est supprimée et son chemin ajouté à `cleared` (règle R-SKIP).
   - Élément visible : contrôles génériques du type, puis `required`.
   - `roster` : même logique pour chaque colonne de chaque ligne, avec les données de la ligne comme contexte (3.3), puis unicité, puis `row_rules`.
2. Calcul des `computed` dans l'ordre déclaré (les résultats sont écrits au chemin `code`).
3. Évaluation des `rules` : si `when` est absent ou vrai, et que `assert` est faux, une anomalie est produite pour chaque chemin de `targets`… en pratique pour la **première** cible (les autres servent à la mise en évidence à l'écran).

### 3.3 Contexte d'une ligne de tableau

Pour les `visible_if` de colonnes et les `row_rules`, les données évaluées sont la ligne, enrichie de `_line` (numéro de ligne à partir de 1) et `_root` (données complètes). Exemple : `{"var": "certified"}` lit la colonne de la ligne ; `{"var": "_root._ctx.current_year"}` lit le contexte.

### 3.4 Résultat

```json
{
  "valid": false,
  "errors":   [ { "code": "REQUIRED", "severity": "error", "target": "Q1_4", "message": "Réponse obligatoire." } ],
  "warnings": [ { "code": "R_DATE_OPEN_BEFORE_AUTH", "severity": "warning", "target": "Q1_5", "message": "…" } ],
  "cleared":  [ "Q1_3_1" ],
  "computed": { "Q2_19": { "M": 1, "F": 1, "T": 2 } }
}
```

Codes génériques : `REQUIRED`, `TYPE`, `RANGE`, `LENGTH`, `PATTERN`, `DATE`, `OPTION`, `ROWS`, `DUPLICATE`, `GPS`. Les vecteurs de test comparent `code`, `severity` et `target`, pas le texte des messages.

Format des cibles : `Q1_4` ; `accompanied.LE3.PLACES.M` ; `trainers[2].cert_year` (indice à partir de 0) ; `sectors.s1.trades[0].trade`.

Un message peut contenir des marqueurs `{chemin}`, remplacés par la valeur correspondante dans le contexte d'évaluation (ex. `{_line}`).

## 4. Langage d'expressions : sous-ensemble de JsonLogic

Les expressions suivent [JsonLogic](https://jsonlogic.com) : `{"opérateur": [arguments]}`. Seuls les opérateurs ci-dessous sont autorisés ; tout autre opérateur est une **erreur de définition**, levée à l'évaluation.

| Opérateur | Sémantique retenue |
|---|---|
| `var` | `{"var": "a.b.0.c"}` ou `{"var": ["chemin", défaut]}` ; chemin vide = donnée courante ; chemin absent → `null` (ou défaut) |
| `==`, `!=` | égalité **sans conversion de type**, sauf entre nombres (`1 == 1.0`) |
| `<`, `<=`, `>`, `>=` | entre deux nombres : numérique ; entre deux chaînes : ordre lexicographique ; dans tous les autres cas (dont `null`) → **faux** |
| `and`, `or` | renvoient l'opérande décisif (court-circuit), comme JsonLogic |
| `!`, `!!` | négation / conversion en booléen selon la vérité JsonLogic |
| `if` | `[cond, alors, cond2, alors2, …, sinon]` |
| `in` | élément dans un tableau, ou sous-chaîne dans une chaîne |
| `+`, `-`, `*`, `/` | arithmétique ; `null` vaut 0 pour `+` et `-` ; division par 0 → `null` |
| `max`, `min` | sur les arguments numériques ; aucun argument → `null` |
| `map`, `filter`, `reduce`, `all`, `some`, `none` | comme JsonLogic ; dans `reduce`, `{"var": "current"}` et `{"var": "accumulator"}` ; `all` sur un tableau vide → **faux** (règle JsonLogic) |

**Vérité :** sont faux `false`, `null`, `0`, `""` et `[]` ; tout le reste est vrai.

Les deux implémentations sont vérifiées par `shared/rules-fixtures/jsonlogic.json` (expression, données, résultat attendu).
