-- =====================================================================================================================
--  COLLECTE EFTP — RÉINITIALISATION DE LA BASE AVANT LE LANCEMENT DE LA COLLECTE RÉELLE
--  (après la formation et les tests)
-- =====================================================================================================================
--
--  À QUOI SERT CE FICHIER
--  ----------------------
--  Il vide la base de toutes les données de formation et de test, puis crée (ou remet à neuf) le compte de
--  l'administrateur national. Il fait le même travail que « bin/reset-operation.php », mais il s'importe
--  directement dans phpMyAdmin, sans ligne de commande.
--
--  COMMENT L'UTILISER
--  ------------------
--   1. SAUVEGARDER D'ABORD LA BASE : phpMyAdmin › sélectionner la base › onglet « Exporter » › « Exécuter ».
--      L'opération est IRRÉVERSIBLE sans cette sauvegarde.
--   2. (Facultatif) Modifier les PARAMÈTRES de la section 0 avec un éditeur de texte (Bloc-notes, Notepad++).
--   3. phpMyAdmin › cliquer sur la base de la plateforme dans la colonne de gauche › onglet « Importer »
--      › « Choisir un fichier » › ce fichier › laisser les options par défaut › « Importer ».
--   4. Lire les deux tableaux affichés à la fin (section 6 : compteurs après nettoyage et compte administrateur).
--   5. Se connecter au back-office avec le compte ci-dessous, puis CHANGER LE MOT DE PASSE (demandé automatiquement).
--
--  COMPTE ADMINISTRATEUR CRÉÉ / RÉINITIALISÉ PAR CE SCRIPT
--  ------------------------------------------------------
--      Matricule (identifiant) : ADM001
--      Mot de passe            : Eftp@Niger2026
--      Le changement de mot de passe est OBLIGATOIRE à la première connexion.
--      Ce mot de passe figure en clair dans ce fichier : ne le laissez jamais en service.
--      Si le matricule ADM001 existe déjà, son mot de passe est remis à cette valeur et il redevient
--      administrateur national actif.
--
--  CE QUI EST SUPPRIMÉ
--  -------------------
--   - toutes les collectes et leur contenu : réponses, formateurs, métiers, apprentis, révisions, contrôles,
--     historiques, pièces jointes (photos des fiches), brouillons web/papier ;
--   - années statistiques, campagnes, structures inscrites aux campagnes, affectations, zones des agents ;
--   - journaux de synchronisation, de connexion et d'audit ;
--   - jetons de connexion et appareils : TOUT LE MONDE devra se reconnecter (web et tablettes) ;
--   - tâches d'export, imports, liste des fichiers produits ;
--   - localités (quartiers/villages) ajoutées sur le terrain ;
--   - structures (centres) : voir le paramètre @supprimer_tout_registre ;
--   - comptes utilisateurs : voir le paramètre @supprimer_comptes.
--
--  CE QUI EST CONSERVÉ
--  -------------------
--   territoire (régions, départements, communes), secteurs, métiers, types de structure, motifs de refus,
--   rôles et permissions, questionnaires publiés (dont 2025.3), paramètres de la plateforme,
--   versions publiées de l'application mobile (APK).
--
--  REMARQUES TECHNIQUES
--  --------------------
--   - Tout s'exécute dans UNE transaction : si une instruction échoue, phpMyAdmin s'arrête et rien n'est enregistré.
--   - DELETE est utilisé (et non TRUNCATE) : les numéros d'identifiants ne repartent PAS de 1. C'est voulu : une
--     tablette qui aurait gardé une collecte de formation ne pourra jamais la rattacher à une campagne réelle.
--   - Les FICHIERS sur le disque (exports Excel/PDF, photos) ne peuvent pas être effacés par SQL. Ils deviennent
--     inaccessibles depuis la plateforme. Pour libérer la place : Plesk › Fichiers › httpdocs/storage/exports
--     et storage/imports › supprimer le contenu (sauf .gitkeep). Ne PAS toucher à storage/releases (APK) ni app.key.
--   - Aucune procédure stockée ni vue n'est créée : le script fonctionne avec un utilisateur MySQL sans droit SUPER.
-- =====================================================================================================================


-- =====================================================================================================================
-- 0. PARAMÈTRES (modifiables)
-- =====================================================================================================================

-- 1 = supprime TOUT le registre des structures (structures de test « DÉMO », registre provisoire…) : il faudra
--     réimporter le registre officiel ensuite (recommandé si le vrai registre n'est pas encore importé).
-- 0 = supprime SEULEMENT les centres créés sur le terrain pendant la formation (proposés par un agent) et garde
--     le registre importé (à choisir si le registre officiel est déjà en place).
SET @supprimer_tout_registre = 1;

-- 1 = supprime TOUS les comptes (agents, superviseurs, ADM-DEMO, DEMO-*, comptes de formation…)
--     SAUF l'administrateur ADM001 ci-dessous (recommandé : les vrais comptes seront importés ensuite).
-- 0 = garde tous les comptes existants (à choisir si les comptes des agents et superviseurs sont déjà les vrais).
SET @supprimer_comptes = 1;

-- Administrateur national créé ou réinitialisé.
-- Le hachage ci-dessous correspond au mot de passe : Eftp@Niger2026
-- (ne pas modifier le hachage à la main : changez plutôt le mot de passe après la connexion).
SET @adm_matricule = 'ADM001';
SET @adm_nom       = 'ADMINISTRATEUR';
SET @adm_prenom    = 'National';
SET @adm_sexe      = 'M';           -- M ou F
SET @adm_hash      = '$2y$12$D.OuS8KGbShXS79Mny7J/uQzreiN5Iwwv79jZlpOeFHGuP.A5AERq';  -- = Eftp@Niger2026


-- =====================================================================================================================
-- 1. PRÉPARATION
-- =====================================================================================================================
SET NAMES utf8mb4;
-- Désactive temporairement le contrôle des clés étrangères : les tables peuvent être vidées dans n'importe quel ordre.
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;


-- =====================================================================================================================
-- 2. COMPTE ADMINISTRATEUR (créé AVANT la purge pour garder un accès dans tous les cas)
-- =====================================================================================================================

-- Création, ou remise à neuf si le matricule existe déjà (mot de passe, statut actif, déverrouillage).
INSERT INTO users (uuid, matricule, last_name, first_name, sex, password_hash, must_change_password, status)
VALUES ('e9fbad70-46ca-4fc3-9d92-7091f002e6a2', @adm_matricule, @adm_nom, @adm_prenom, @adm_sexe, @adm_hash, 1, 'ACTIF')
ON DUPLICATE KEY UPDATE
    password_hash        = VALUES(password_hash),
    must_change_password = 1,          -- changement obligatoire à la première connexion
    status               = 'ACTIF',
    deleted_at           = NULL,
    failed_attempts      = 0,
    locked_until         = NULL,
    supervisor_id        = NULL;

-- Identifiant interne de l'administrateur, utilisé dans la suite du script.
SET @admin_id = (SELECT id FROM users WHERE matricule = @adm_matricule);

-- Rôle ADMIN (administrateur national) et périmètre NATIONAL uniquement.
DELETE FROM user_roles  WHERE user_id = @admin_id;
DELETE FROM user_scopes WHERE user_id = @admin_id;
INSERT INTO user_roles  (user_id, role_id) SELECT @admin_id, id FROM roles WHERE code = 'ADMIN';
INSERT INTO user_scopes (user_id, level)   VALUES (@admin_id, 'NATIONAL');


-- =====================================================================================================================
-- 3. COLLECTES, CAMPAGNES ET JOURNAUX (supprimés entièrement)
-- =====================================================================================================================

-- 3.1 Contenu des collectes (questionnaires remplis)
DELETE FROM collection_answers;            -- réponses aux questions
DELETE FROM collection_accompanied;        -- personnes accompagnées
DELETE FROM collection_trades;             -- apprentis par métier
DELETE FROM collection_trainers;           -- maîtres artisans / formateurs
DELETE FROM collection_sector_specials;    -- questions spécifiques par secteur
DELETE FROM collection_submissions;        -- révisions envoyées (tablette, web, papier)
DELETE FROM collection_reviews;            -- décisions des superviseurs
DELETE FROM collection_status_history;     -- historique des statuts
DELETE FROM collection_attachments;        -- pièces jointes (photos des fiches signées)
DELETE FROM collection_drafts;             -- brouillons de saisie web / papier
DELETE FROM collections;                   -- les collectes elles-mêmes

-- 3.2 Organisation de l'opération
DELETE FROM assignments;                   -- affectations des structures aux agents
DELETE FROM agent_areas;                   -- zones de travail des agents
DELETE FROM campaign_structures;           -- structures inscrites aux campagnes
DELETE FROM campaign_status_history;       -- historique d'ouverture / clôture des campagnes
DELETE FROM campaigns;                     -- campagnes
DELETE FROM academic_years;                -- années statistiques

-- 3.3 Journaux, sessions et traitements
DELETE FROM sync_logs;                     -- synchronisations des tablettes
DELETE FROM login_logs;                    -- connexions
DELETE FROM audit_logs;                    -- journal d'audit
DELETE FROM api_tokens;                    -- jetons de connexion : tout le monde se reconnecte
DELETE FROM devices;                       -- tablettes enregistrées
DELETE FROM jobs;                          -- tâches d'export en file d'attente
DELETE FROM imports;                       -- historiques d'import Excel
DELETE FROM files;                         -- fichiers produits (exports, photos, rapports)


-- =====================================================================================================================
-- 4. LOCALITÉS ET STRUCTURES
-- =====================================================================================================================

-- 4.1 Quartiers / villages ajoutés par les agents sur le terrain (les localités officielles sont gardées).
DELETE FROM localities WHERE source = 'TERRAIN';
UPDATE structures SET locality_id = NULL
 WHERE locality_id IS NOT NULL AND locality_id NOT IN (SELECT id FROM localities);

-- 4.2 Structures à supprimer : tout le registre (@supprimer_tout_registre = 1)
--     ou seulement les centres créés sur le terrain (proposés par un agent, ou au statut PROPOSEE / REJETEE).
DELETE FROM structure_history
 WHERE structure_id IN (SELECT id FROM structures
                         WHERE @supprimer_tout_registre = 1
                            OR proposed_by IS NOT NULL OR status IN ('PROPOSEE', 'REJETEE'));

-- Fusions pointant vers une structure supprimée : remises à vide.
UPDATE structures SET merged_into_id = NULL
 WHERE merged_into_id IN (SELECT id FROM (SELECT id FROM structures
                                           WHERE @supprimer_tout_registre = 1
                                              OR proposed_by IS NOT NULL OR status IN ('PROPOSEE', 'REJETEE')) AS a_supprimer);

DELETE FROM structures
 WHERE @supprimer_tout_registre = 1
    OR proposed_by IS NOT NULL OR status IN ('PROPOSEE', 'REJETEE');


-- =====================================================================================================================
-- 5. COMPTES UTILISATEURS (si @supprimer_comptes = 1 : tous sauf l'administrateur ADM001)
-- =====================================================================================================================

-- 5.1 Les références vers les comptes supprimés sont remises à vide (ou rattachées à l'administrateur
--     quand elles sont obligatoires), pour qu'aucune donnée conservée ne pointe vers un compte disparu.
UPDATE structures             SET created_by    = NULL WHERE @supprimer_comptes = 1 AND created_by    IS NOT NULL AND created_by    <> @admin_id;
UPDATE structures             SET approved_by   = NULL WHERE @supprimer_comptes = 1 AND approved_by   IS NOT NULL AND approved_by   <> @admin_id;
UPDATE structures             SET proposed_by   = NULL WHERE @supprimer_comptes = 1 AND proposed_by   IS NOT NULL AND proposed_by   <> @admin_id;
UPDATE structure_history      SET user_id       = NULL WHERE @supprimer_comptes = 1 AND user_id       IS NOT NULL AND user_id       <> @admin_id;
UPDATE questionnaire_versions SET published_by  = NULL WHERE @supprimer_comptes = 1 AND published_by  IS NOT NULL AND published_by  <> @admin_id;
UPDATE system_settings        SET updated_by    = NULL WHERE @supprimer_comptes = 1 AND updated_by    IS NOT NULL AND updated_by    <> @admin_id;
UPDATE localities             SET created_by    = NULL WHERE @supprimer_comptes = 1 AND created_by    IS NOT NULL AND created_by    <> @admin_id;
UPDATE users                  SET created_by    = NULL WHERE @supprimer_comptes = 1 AND created_by    IS NOT NULL AND created_by    <> @admin_id;
UPDATE users                  SET supervisor_id = NULL WHERE @supprimer_comptes = 1 AND supervisor_id IS NOT NULL AND supervisor_id <> @admin_id;
UPDATE app_releases           SET published_by  = @admin_id WHERE @supprimer_comptes = 1 AND published_by <> @admin_id;

-- 5.2 Suppression des comptes, de leurs rôles et de leurs périmètres.
DELETE FROM user_scopes WHERE @supprimer_comptes = 1 AND user_id <> @admin_id;
DELETE FROM user_roles  WHERE @supprimer_comptes = 1 AND user_id <> @admin_id;
DELETE FROM users       WHERE @supprimer_comptes = 1 AND id      <> @admin_id;

-- 5.3 Comptes conservés : compteurs d'échecs de connexion remis à zéro, comptes déverrouillés.
UPDATE users SET failed_attempts = 0, locked_until = NULL;

-- 5.4 Trace de l'opération dans le journal d'audit (première ligne du nouveau journal).
INSERT INTO audit_logs (user_id, action, entity_type, entity_id, after_json, reason)
VALUES (NULL, 'DATABASE_RESET', 'database', DATABASE(),
        JSON_OBJECT('toutes_structures', @supprimer_tout_registre = 1, 'utilisateurs', @supprimer_comptes = 1,
                    'administrateur', @adm_matricule),
        'Réinitialisation avant lancement de l''opération (reinitialisation-operation.sql via phpMyAdmin)');


-- =====================================================================================================================
-- 6. VALIDATION ET CONTRÔLE
-- =====================================================================================================================
COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

-- Résultat attendu : 0 collecte, 0 campagne ; structures = 0 si tout le registre a été supprimé ;
-- comptes = 1 (ADM001) si les comptes ont été supprimés ; questionnaires, métiers, communes > 0 (conservés).
SELECT 'collectes'            AS element, COUNT(*) AS nombre FROM collections
UNION ALL SELECT 'campagnes',             COUNT(*) FROM campaigns
UNION ALL SELECT 'affectations',          COUNT(*) FROM assignments
UNION ALL SELECT 'structures',            COUNT(*) FROM structures
UNION ALL SELECT 'comptes utilisateurs',  COUNT(*) FROM users
UNION ALL SELECT 'versions de questionnaire (conservées)', COUNT(*) FROM questionnaire_versions
UNION ALL SELECT 'métiers (conservés)',   COUNT(*) FROM trades
UNION ALL SELECT 'communes (conservées)', COUNT(*) FROM communes;

-- Le compte à utiliser pour la première connexion :
SELECT u.matricule AS identifiant, 'Eftp@Niger2026' AS mot_de_passe_provisoire, r.code AS role, s.level AS perimetre,
       u.status AS statut, IF(u.must_change_password = 1, 'oui', 'non') AS changement_obligatoire
  FROM users u
  JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
  JOIN user_scopes s ON s.user_id = u.id
 WHERE u.id = @admin_id;

-- =====================================================================================================================
--  ÉTAPES SUIVANTES (back-office web, connecté en ADM001)
--   1. Changer le mot de passe provisoire.
--   2. Créer l'année statistique 2026-2027 et la rendre active.
--   3. Importer les comptes : superviseurs régionaux (rôle SUP_REG, périmètre région) et agents recenseurs.
--   4. Importer le registre officiel des structures (si @supprimer_tout_registre = 1).
--   5. Créer la campagne : questionnaire 2025.3, circuit « Un niveau : superviseur régional », mode Tablette ;
--      inscrire les structures, les affecter aux agents, puis OUVRIR la campagne.
--   6. Tablettes ayant servi à la formation avec de vrais comptes : Paramètres Android › Applications ›
--      Collecte EFTP › Stockage › Effacer les données, avant de les remettre aux agents.
-- =====================================================================================================================
