# Base de données — notes de conception et d'exploitation

## Schéma

- Migration initiale : [database/migrations/001_schema.sql](../../database/migrations/001_schema.sql) — 48 tables + 2 vues.
- [002_imports.sql](../../database/migrations/002_imports.sql) (lot L2) — table `imports` : imports Excel en deux temps (prévisualisation `PREVISUALISE` → validation `IMPORTE`, ou `ANNULE`), fichier source et rapport rattachés à `files`, résumé ligne par ligne en JSON. Une migration appliquée n'est jamais modifiée (somme de contrôle vérifiée par le Migrator) : toute évolution passe par une nouvelle migration.
- Base locale de développement : `eftp_nf` (XAMPP, MariaDB 10.4.32). Cible de production : MySQL 8.0 ou MariaDB ≥ 10.4.
- Le code SQL reste dans l'intersection MariaDB 10.4 / MySQL 8 : pas de `JSON_TABLE`, pas d'index fonctionnel, colonnes générées en `STORED`, aucune contrainte `CHECK` sur une colonne `AUTO_INCREMENT`.

## Règle impérative : mode SQL strict imposé par l'application

Le serveur XAMPP local tourne avec `sql_mode = NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION` (**non strict**). Dans ce mode, MariaDB **convertit silencieusement** une valeur hors plage (ex. un effectif de −3 devient 0) au lieu de la rejeter. Constaté lors des tests du 12/11/2026.

Mesure : la classe de connexion PDO exécute à chaque ouverture de connexion :

```sql
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY';
SET SESSION time_zone = '+01:00';  -- heure du Niger (WAT), sans changement d'heure
```

Il est aussi recommandé de configurer le serveur de production en mode strict ; la protection applicative reste active dans tous les cas.

## Contraintes vérifiées (12/11/2026, transaction annulée)

| Test | Attendu | Résultat |
|---|---|---|
| 2ᵉ affectation active d'une même structure dans une campagne | refus | ✔ refus (`uq_assignments_active`) |
| Réaffectation après clôture de la 1ʳᵉ affectation | accepté | ✔ |
| Code structure ≠ 10 chiffres | refus | ✔ refus (`ck_structures_code`) |
| Même structure ciblée deux fois dans une campagne | refus | ✔ refus |
| Périmètre `REGION` sans région | refus | ✔ refus (`ck_us_level`) |
| Effectif négatif (mode strict) | refus | ✔ refus (hors plage) |
| Effectif négatif (mode non strict du serveur) | refus | ✘ converti en 0 → d'où la règle ci-dessus |
| Invalidation sans motif | refus | ✔ refus (`ck_cr_reason`) |
| 2ᵉ collecte pour la même structure et la même campagne | refus | ✔ refus (`uq_collections_cs`) |
| Vue Q2.1 (apprentis calculés depuis la Section IV) | M=5 F=3 T=8 ; M=0 F=2 T=2 | ✔ |
| Vue Q2.19 (formateurs calculés depuis la Section III) | M=1 F=1 T=2 | ✔ |

Ces vérifications deviendront des tests d'intégration PHPUnit au lot L1.
