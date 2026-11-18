# Phase 1 — Fonctionnalités, profils et cas d'utilisation

## 1. Profils

| Profil | Canal | Périmètre | Rôle |
|---|---|---|---|
| Administrateur national (`ADMIN`) | Web | National | Paramétrage, comptes, campagnes, référentiels, réouvertures exceptionnelles |
| Superviseur régional (`SUP_REG`) | Web | 1 région | Suivi, contrôle de 2ᵉ niveau (si activé), pilotage des superviseurs départementaux |
| Superviseur départemental (`SUP_DEP`) | Web | 1 ou plusieurs départements | Contrôle de 1er niveau, validation/invalidation, suivi des agents |
| Agent recenseur (`AGENT`) | Mobile | Communes/structures affectées | Collecte hors ligne, synchronisation, corrections |
| Lecteur/analyste (`VIEWER`) — *proposé* | Web | National ou régional | Consultation des tableaux de bord et exports, sans écriture |

Les droits sont portés par des **permissions** (`collection.validate`, `user.import`, …) rattachées aux rôles. Le périmètre territorial est une donnée distincte (affectation à une ou plusieurs unités territoriales), appliquée dans **chaque** requête côté serveur.

## 2. Catalogue fonctionnel

### 2.1 Référentiels
- Hiérarchie administrative Région → Département → Commune → Village/Quartier, importée depuis un fichier officiel (INS/RENACOM) avec rapport de vérification (8 / 66 / 266 attendus).
- (La localisation pédagogique DREN/IREFTP/IEFTP de la version 2024 a été supprimée en 2025 ; le modèle permet de la réintroduire comme hiérarchie secondaire si une version future la redemande.)
- Nomenclatures : types de structure, métiers par secteur, niveaux de certification, motifs d'invalidation, sources de financement…
- Validité temporelle (`valid_from` / `valid_to`) : un redécoupage crée de nouvelles entités sans modifier l'historique.

### 2.2 Utilisateurs
- Création, modification, activation/désactivation, réinitialisation du mot de passe (mot de passe temporaire + changement forcé à la première connexion).
- Import Excel en masse (agents, superviseurs) : modèle téléchargeable, contrôle des colonnes, des doublons (matricule, téléphone, e-mail) et de la cohérence géographique, prévisualisation, import **transactionnel tout-ou-rien**, rapport ligne par ligne téléchargeable, identifiants initiaux générés aléatoirement et remis via un export protégé.
- Hiérarchie de supervision configurable (1 ou 2 niveaux par campagne).
- Journal des connexions et des activités.

### 2.3 Structures
- Registre permanent avec identifiant interne stable (UUID) et code officiel unique (10 chiffres).
- Détection de doublons à la création : code identique (bloquant), nom normalisé + même commune (alerte), proximité GPS < 100 m (alerte).
- Historique des modifications (qui, quand, avant/après).
- **Structures découvertes sur le terrain** *(ajout proposé, indispensable pour un recensement)* : l'agent peut proposer une nouvelle structure ; elle reste « proposée » jusqu'à son approbation par le superviseur, après contrôle de doublon.

### 2.4 Années statistiques et campagnes
- Année statistique (ex. 2026-2027) → une ou plusieurs campagnes.
- Campagne : dates, instructions, **version de questionnaire figée**, structures cibles, agents affectés, niveaux de validation.
- États : `PREPARATION → OUVERTE ⇄ SUSPENDUE → CLOTUREE → ARCHIVEE`, transitions contrôlées côté serveur.
- Préparation d'une nouvelle campagne par reconduction : copie des structures actives et (au choix) des affectations de la campagne précédente, sans toucher aux anciennes données.

### 2.5 Affectations
- Affectation d'un agent à des communes et/ou à une liste de structures pour une campagne.
- Une structure n'a **qu'un seul agent actif** par campagne (contrainte d'unicité) ; une réaffectation clôt l'ancienne affectation et conserve l'historique.
- Vue des structures non affectées, répartition en masse (sélection multiple, filtres géographiques).

### 2.6 Collecte (mobile)
- Liste des structures affectées, recherche par nom/code, statut de chaque collecte.
- Formulaire par étapes (5 parties), progression, sections complètes/incomplètes, erreurs et avertissements expliqués.
- Sauvegarde automatique locale à chaque champ, reprise après fermeture.
- Capture GPS hors ligne (précision, date, état).
- Validation complète avant de passer à « Terminée ».
- Correction d'une collecte invalidée, avec affichage du motif.
- Historique pluriannuel de l'agent.

### 2.7 Synchronisation
- File d'attente persistante, envoi idempotent par UUID + révision, accusé de réception serveur, reprises automatiques avec temporisation exponentielle, synchronisation manuelle et en arrière-plan.
- Téléchargement différentiel des référentiels et affectations (`since=` + ETag), compression gzip.

### 2.8 Contrôle et validation (web)
- File des collectes à contrôler, prise en charge (`EN_CONTROLE`), consultation complète, comparaison avec la collecte de l'année précédente pour la même structure.
- Validation (observation facultative) / invalidation (motif obligatoire, catalogue + texte libre), historique de toutes les soumissions.
- Réouverture exceptionnelle par l'administrateur uniquement, avec motif, journalisée.

### 2.9 Tableaux de bord et analyses
- Suivi opérationnel : couverture, validation, attentes, retards, classement des agents, évolution journalière/hebdomadaire, par région/département/commune.
- Indicateurs statistiques issus du questionnaire (effectifs par sexe/secteur/durée, formateurs, vulnérables, jeunes accompagnés/placés/outillés, apprentis accompagnés par le FAFPA, infrastructures, eau, électricité, Internet, latrines, plateformes d'orientation). Les indicateurs « cantines » et « semi-public » du prompt maître ne sont pas calculables avec la version 2025 du questionnaire.
- Comparaison pluriannuelle par campagne, en signalant les différences de version de questionnaire et de périmètre.
- **Les indicateurs statistiques sont calculés sur les collectes validées** (paramétrable : validées seules / validées + soumises, avec mention explicite à l'écran).

### 2.10 Exports
- Excel, CSV, PDF ; respect des filtres et du périmètre ; export consolidé national (format « large », une ligne par structure, plus une feuille par tableau détaillé).
- Exports lourds traités en tâche de fond, avec notification.

### 2.11 Administration technique
- Paramètres applicatifs, journaux d'audit, sauvegardes planifiées et restauration documentée, mode maintenance.

## 3. Cas d'utilisation principaux

| ID | Acteur | Cas d'utilisation |
|---|---|---|
| UC-01 | Admin | Importer le référentiel territorial officiel |
| UC-02 | Admin | Créer une année statistique et ouvrir une campagne |
| UC-03 | Admin | Importer des agents/superviseurs par Excel |
| UC-04 | Admin | Affecter des structures aux agents |
| UC-05 | Agent | Se connecter (en ligne la 1ʳᵉ fois, puis hors ligne) et télécharger ses affectations |
| UC-06 | Agent | Collecter une structure hors ligne, enregistrer un brouillon, reprendre |
| UC-07 | Agent | Terminer et soumettre (synchroniser) une collecte |
| UC-08 | Agent | Proposer une structure non répertoriée |
| UC-09 | Sup. dép. | Contrôler, valider ou invalider une collecte |
| UC-10 | Agent | Corriger une collecte invalidée et la resoumettre |
| UC-11 | Sup. rég. | Suivre la performance des agents et superviseurs de sa région |
| UC-12 | Admin | Clôturer une campagne |
| UC-13 | Admin | Rouvrir exceptionnellement une collecte validée |
| UC-14 | Admin/Viewer | Consulter les tableaux de bord et comparer les campagnes |
| UC-15 | Tous (web) | Exporter des données filtrées |
| UC-16 | Admin | Publier une nouvelle version du questionnaire |
