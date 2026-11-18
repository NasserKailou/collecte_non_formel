-- =============================================================================
-- Plateforme EFTP non formel — Niger
-- Migration 001 : schéma initial
-- Compatible MariaDB 10.4+ et MySQL 8.0+ (InnoDB, utf8mb4)
-- Conventions :
--   * id auto-incrémentés ; uuid CHAR(36) pour les entités créées hors ligne
--     (structures, collectes) ou exposées à l'API
--   * aucune suppression en cascade sur les données de collecte (RESTRICT)
--   * suppression logique (deleted_at) pour utilisateurs et structures
-- =============================================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- -----------------------------------------------------------------------------
-- 1. RÉFÉRENTIELS TERRITORIAUX
-- -----------------------------------------------------------------------------
CREATE TABLE regions (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code_renacom  VARCHAR(10)  NOT NULL,
  name          VARCHAR(100) NOT NULL,
  valid_from    DATE NULL,
  valid_to      DATE NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_regions_code (code_renacom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE departments (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  region_id     SMALLINT UNSIGNED NOT NULL,
  code_renacom  VARCHAR(10)  NOT NULL,
  name          VARCHAR(100) NOT NULL,
  is_city       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Ville à statut particulier',
  valid_from    DATE NULL,
  valid_to      DATE NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_departments_code (code_renacom),
  KEY ix_departments_region (region_id),
  CONSTRAINT fk_departments_region FOREIGN KEY (region_id) REFERENCES regions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE communes (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  department_id SMALLINT UNSIGNED NOT NULL,
  code_renacom  VARCHAR(10)  NOT NULL,
  name          VARCHAR(100) NOT NULL,
  centroid_lat  DECIMAL(9,6) NULL,
  centroid_lng  DECIMAL(9,6) NULL,
  valid_from    DATE NULL,
  valid_to      DATE NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_communes_code (code_renacom),
  KEY ix_communes_department (department_id),
  CONSTRAINT fk_communes_department FOREIGN KEY (department_id) REFERENCES departments (id),
  CONSTRAINT ck_communes_lat CHECK (centroid_lat IS NULL OR centroid_lat BETWEEN 11 AND 24),
  CONSTRAINT ck_communes_lng CHECK (centroid_lng IS NULL OR centroid_lng BETWEEN 0 AND 16.5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE localities (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  commune_id      SMALLINT UNSIGNED NOT NULL,
  name            VARCHAR(150) NOT NULL,
  name_normalized VARCHAR(150) NOT NULL COMMENT 'Majuscules sans accents ni espaces multiples',
  kind            ENUM('VILLAGE','QUARTIER','AUTRE') NOT NULL DEFAULT 'AUTRE',
  source          ENUM('OFFICIEL','TERRAIN') NOT NULL DEFAULT 'TERRAIN',
  status          ENUM('PROPOSEE','VALIDEE','REJETEE') NOT NULL DEFAULT 'PROPOSEE',
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_localities_commune_name (commune_id, name_normalized),
  CONSTRAINT fk_localities_commune FOREIGN KEY (commune_id) REFERENCES communes (id),
  CONSTRAINT fk_localities_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. NOMENCLATURES
-- -----------------------------------------------------------------------------
CREATE TABLE sectors (
  id      TINYINT UNSIGNED NOT NULL,
  code    VARCHAR(20)  NOT NULL,
  label   VARCHAR(100) NOT NULL,
  sort    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sectors_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trades (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_code   SMALLINT UNSIGNED NULL COMMENT 'CODE_TYPE_METIER du fichier listes_metiers',
  sector_id     TINYINT UNSIGNED NULL COMMENT 'NULL = à rattacher (non proposé en collecte)',
  label         VARCHAR(150) NOT NULL,
  is_other      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Option « Autre (préciser) »',
  alias_of_id   SMALLINT UNSIGNED NULL COMMENT 'Doublon pointant vers le métier canonique',
  sort          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_trades_sector (sector_id, active),
  KEY ix_trades_source (source_code),
  CONSTRAINT fk_trades_sector FOREIGN KEY (sector_id) REFERENCES sectors (id),
  CONSTRAINT fk_trades_alias FOREIGN KEY (alias_of_id) REFERENCES trades (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE structure_types (
  id       TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code     VARCHAR(10)  NOT NULL COMMENT 'Lettre de la modalité Q1_9 (a..k)',
  label    VARCHAR(100) NOT NULL,
  is_other TINYINT(1) NOT NULL DEFAULT 0,
  sort     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  active   TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_structure_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rejection_reasons (
  id      SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code    VARCHAR(40)  NOT NULL,
  label   VARCHAR(200) NOT NULL,
  active  TINYINT(1) NOT NULL DEFAULT 1,
  sort    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rejection_reasons_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. UTILISATEURS, RÔLES, PÉRIMÈTRES, SÉCURITÉ
-- -----------------------------------------------------------------------------
CREATE TABLE roles (
  id      TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code    VARCHAR(30)  NOT NULL,
  label   VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  id      SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code    VARCHAR(60)  NOT NULL,
  label   VARCHAR(200) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id       TINYINT UNSIGNED  NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                  CHAR(36) CHARACTER SET ascii NOT NULL,
  matricule             VARCHAR(40)  NOT NULL,
  last_name             VARCHAR(100) NOT NULL,
  first_name            VARCHAR(100) NOT NULL,
  sex                   ENUM('M','F') NOT NULL,
  phone                 VARCHAR(20)  NULL,
  email                 VARCHAR(190) NULL,
  password_hash         VARCHAR(255) NOT NULL,
  must_change_password  TINYINT(1) NOT NULL DEFAULT 1,
  status                ENUM('ACTIF','INACTIF') NOT NULL DEFAULT 'ACTIF',
  supervisor_id         INT UNSIGNED NULL COMMENT 'Supérieur hiérarchique direct',
  failed_attempts       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until          DATETIME NULL,
  last_login_at         DATETIME NULL,
  password_changed_at   DATETIME NULL,
  created_by            INT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_uuid (uuid),
  UNIQUE KEY uq_users_matricule (matricule),
  UNIQUE KEY uq_users_phone (phone),
  UNIQUE KEY uq_users_email (email),
  KEY ix_users_supervisor (supervisor_id),
  KEY ix_users_status (status),
  CONSTRAINT fk_users_supervisor FOREIGN KEY (supervisor_id) REFERENCES users (id),
  CONSTRAINT fk_users_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
  user_id   INT UNSIGNED     NOT NULL,
  role_id   TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Périmètre territorial d'un utilisateur (une ou plusieurs lignes)
CREATE TABLE user_scopes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  level         ENUM('NATIONAL','REGION','DEPARTMENT','COMMUNE') NOT NULL,
  region_id     SMALLINT UNSIGNED NULL,
  department_id SMALLINT UNSIGNED NULL,
  commune_id    SMALLINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_scopes (user_id, level, region_id, department_id, commune_id),
  KEY ix_user_scopes_region (region_id),
  KEY ix_user_scopes_department (department_id),
  KEY ix_user_scopes_commune (commune_id),
  CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_us_region FOREIGN KEY (region_id) REFERENCES regions (id),
  CONSTRAINT fk_us_department FOREIGN KEY (department_id) REFERENCES departments (id),
  CONSTRAINT fk_us_commune FOREIGN KEY (commune_id) REFERENCES communes (id),
  CONSTRAINT ck_us_level CHECK (
       (level = 'NATIONAL'   AND region_id IS NULL     AND department_id IS NULL     AND commune_id IS NULL)
    OR (level = 'REGION'     AND region_id IS NOT NULL AND department_id IS NULL     AND commune_id IS NULL)
    OR (level = 'DEPARTMENT' AND department_id IS NOT NULL AND commune_id IS NULL)
    OR (level = 'COMMUNE'    AND commune_id IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE devices (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  device_uid    VARCHAR(64)  NOT NULL COMMENT 'Identifiant généré par l application à l installation',
  model         VARCHAR(100) NULL,
  os_version    VARCHAR(50)  NULL,
  app_version   VARCHAR(20)  NULL,
  last_seen_at  DATETIME NULL,
  revoked_at    DATETIME NULL,
  revoked_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_devices_user_uid (user_id, device_uid),
  CONSTRAINT fk_devices_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_devices_revoker FOREIGN KEY (revoked_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jetons opaques : seule l'empreinte SHA-256 est stockée
CREATE TABLE api_tokens (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  device_id     INT UNSIGNED NULL,
  type          ENUM('ACCESS','REFRESH') NOT NULL,
  token_hash    CHAR(64) CHARACTER SET ascii NOT NULL,
  family_id     CHAR(36) CHARACTER SET ascii NOT NULL COMMENT 'Chaîne de rotation (détection de rejeu)',
  expires_at    DATETIME NOT NULL,
  revoked_at    DATETIME NULL,
  last_used_at  DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_tokens_hash (token_hash),
  KEY ix_api_tokens_user (user_id, type, revoked_at),
  KEY ix_api_tokens_family (family_id),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_tokens_device FOREIGN KEY (device_id) REFERENCES devices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  identifier  VARCHAR(190) NOT NULL COMMENT 'Identifiant saisi (matricule, téléphone ou e-mail)',
  ip          VARCHAR(45)  NOT NULL,
  user_agent  VARCHAR(255) NULL,
  channel     ENUM('WEB','MOBILE') NOT NULL,
  success     TINYINT(1) NOT NULL,
  reason      VARCHAR(60) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_login_logs_ip_time (ip, created_at),
  KEY ix_login_logs_identifier_time (identifier, created_at),
  KEY ix_login_logs_user (user_id, created_at),
  CONSTRAINT fk_login_logs_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NULL,
  action       VARCHAR(60)  NOT NULL,
  entity_type  VARCHAR(60)  NOT NULL,
  entity_id    VARCHAR(40)  NULL,
  before_json  LONGTEXT NULL,
  after_json   LONGTEXT NULL,
  reason       VARCHAR(500) NULL,
  ip           VARCHAR(45)  NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_audit_entity (entity_type, entity_id),
  KEY ix_audit_user_time (user_id, created_at),
  KEY ix_audit_action_time (action, created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT ck_audit_before CHECK (before_json IS NULL OR JSON_VALID(before_json)),
  CONSTRAINT ck_audit_after  CHECK (after_json  IS NULL OR JSON_VALID(after_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE system_settings (
  setting_key   VARCHAR(80) NOT NULL,
  value         TEXT NOT NULL,
  value_type    ENUM('string','int','float','bool','json') NOT NULL DEFAULT 'string',
  description   VARCHAR(255) NULL,
  updated_by    INT UNSIGNED NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key),
  CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE files (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid           CHAR(36) CHARACTER SET ascii NOT NULL,
  owner_id       INT UNSIGNED NOT NULL,
  purpose        ENUM('IMPORT_USERS','IMPORT_STRUCTURES','IMPORT_TERRITORY','EXPORT','ATTACHMENT','REPORT') NOT NULL,
  original_name  VARCHAR(255) NOT NULL,
  stored_path    VARCHAR(255) NOT NULL COMMENT 'Chemin relatif sous storage/, hors webroot',
  mime_type      VARCHAR(100) NOT NULL,
  size_bytes     INT UNSIGNED NOT NULL,
  sha256         CHAR(64) CHARACTER SET ascii NOT NULL,
  expires_at     DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_files_uuid (uuid),
  KEY ix_files_owner (owner_id, purpose),
  CONSTRAINT fk_files_owner FOREIGN KEY (owner_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- File de tâches de fond (imports, exports, agrégats) traitée par bin/worker.php
CREATE TABLE jobs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type           VARCHAR(60) NOT NULL,
  payload        LONGTEXT NOT NULL,
  status         ENUM('EN_ATTENTE','EN_COURS','TERMINE','ECHEC') NOT NULL DEFAULT 'EN_ATTENTE',
  attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  progress       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  result_json    LONGTEXT NULL,
  error          TEXT NULL,
  result_file_id BIGINT UNSIGNED NULL,
  created_by     INT UNSIGNED NULL,
  available_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at     DATETIME NULL,
  finished_at    DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_jobs_queue (status, available_at),
  KEY ix_jobs_creator (created_by, created_at),
  CONSTRAINT fk_jobs_creator FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_jobs_file FOREIGN KEY (result_file_id) REFERENCES files (id),
  CONSTRAINT ck_jobs_payload CHECK (JSON_VALID(payload)),
  CONSTRAINT ck_jobs_progress CHECK (progress <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. STRUCTURES (identité permanente)
-- -----------------------------------------------------------------------------
CREATE TABLE structures (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid               CHAR(36) CHARACTER SET ascii NOT NULL,
  official_code      CHAR(10) CHARACTER SET ascii NULL COMMENT 'Code officiel à 10 chiffres ; NULL tant qu une structure proposée n est pas codifiée',
  name               VARCHAR(150) NOT NULL,
  name_normalized    VARCHAR(150) NOT NULL,
  structure_type_id  TINYINT UNSIGNED NOT NULL,
  other_type_label   VARCHAR(150) NULL,
  legal_status       ENUM('PUBLIC','PRIVE') NULL,
  zone               ENUM('URBAINE','RURALE') NULL,
  commune_id         SMALLINT UNSIGNED NOT NULL,
  locality_id        INT UNSIGNED NULL,
  locality_label     VARCHAR(150) NULL,
  latitude           DECIMAL(9,6) NULL,
  longitude          DECIMAL(9,6) NULL,
  gps_accuracy_m     DECIMAL(7,1) NULL,
  authorization_date DATE NULL,
  opening_date       DATE NULL,
  status             ENUM('ACTIVE','PROPOSEE','FERMEE','REJETEE','FUSIONNEE') NOT NULL DEFAULT 'ACTIVE',
  merged_into_id     INT UNSIGNED NULL,
  proposed_by        INT UNSIGNED NULL,
  approved_by        INT UNSIGNED NULL,
  approved_at        DATETIME NULL,
  created_by         INT UNSIGNED NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at         DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_structures_uuid (uuid),
  UNIQUE KEY uq_structures_code (official_code),
  KEY ix_structures_commune (commune_id, status),
  KEY ix_structures_name (name_normalized),
  KEY ix_structures_type (structure_type_id),
  CONSTRAINT fk_structures_type FOREIGN KEY (structure_type_id) REFERENCES structure_types (id),
  CONSTRAINT fk_structures_commune FOREIGN KEY (commune_id) REFERENCES communes (id),
  CONSTRAINT fk_structures_locality FOREIGN KEY (locality_id) REFERENCES localities (id),
  CONSTRAINT fk_structures_merged FOREIGN KEY (merged_into_id) REFERENCES structures (id),
  CONSTRAINT fk_structures_proposer FOREIGN KEY (proposed_by) REFERENCES users (id),
  CONSTRAINT fk_structures_approver FOREIGN KEY (approved_by) REFERENCES users (id),
  CONSTRAINT fk_structures_creator FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT ck_structures_code CHECK (official_code IS NULL OR official_code REGEXP '^[0-9]{10}$'),
  CONSTRAINT ck_structures_lat CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90),
  CONSTRAINT ck_structures_lng CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE structure_history (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  structure_id  INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NULL,
  action        ENUM('CREATION','MODIFICATION','PROPOSITION','APPROBATION','REJET','FUSION','FERMETURE','REOUVERTURE') NOT NULL,
  changes_json  LONGTEXT NULL,
  reason        VARCHAR(500) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_structure_history (structure_id, created_at),
  CONSTRAINT fk_sh_structure FOREIGN KEY (structure_id) REFERENCES structures (id),
  CONSTRAINT fk_sh_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT ck_sh_changes CHECK (changes_json IS NULL OR JSON_VALID(changes_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. QUESTIONNAIRES VERSIONNÉS
-- -----------------------------------------------------------------------------
CREATE TABLE questionnaires (
  id      SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code    VARCHAR(40)  NOT NULL,
  label   VARCHAR(200) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_questionnaires_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE questionnaire_versions (
  id               SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  questionnaire_id SMALLINT UNSIGNED NOT NULL,
  version_code     VARCHAR(20)  NOT NULL,
  label            VARCHAR(200) NOT NULL,
  definition_json  LONGTEXT NOT NULL COMMENT 'Définition complète — immuable une fois publiée',
  checksum         CHAR(64) CHARACTER SET ascii NOT NULL,
  status           ENUM('BROUILLON','PUBLIEE','RETIREE') NOT NULL DEFAULT 'BROUILLON',
  published_at     DATETIME NULL,
  published_by     INT UNSIGNED NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qv (questionnaire_id, version_code),
  CONSTRAINT fk_qv_questionnaire FOREIGN KEY (questionnaire_id) REFERENCES questionnaires (id),
  CONSTRAINT fk_qv_publisher FOREIGN KEY (published_by) REFERENCES users (id),
  CONSTRAINT ck_qv_json CHECK (JSON_VALID(definition_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Décomposition de la définition pour interrogation (générée à la publication)
CREATE TABLE questionnaire_sections (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  version_id  SMALLINT UNSIGNED NOT NULL,
  code        VARCHAR(20)  NOT NULL,
  title       VARCHAR(200) NOT NULL,
  sort        SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qs (version_id, code),
  CONSTRAINT fk_qs_version FOREIGN KEY (version_id) REFERENCES questionnaire_versions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE questionnaire_questions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  version_id   SMALLINT UNSIGNED NOT NULL,
  section_id   INT UNSIGNED NOT NULL,
  code         VARCHAR(40)  NOT NULL,
  number_label VARCHAR(20)  NULL COMMENT 'Numérotation affichée (ex. 2.13.2)',
  label        VARCHAR(500) NOT NULL,
  type         VARCHAR(30)  NOT NULL,
  required     TINYINT(1)   NOT NULL DEFAULT 0,
  sort         SMALLINT UNSIGNED NOT NULL,
  config_json  LONGTEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qq (version_id, code),
  CONSTRAINT fk_qq_version FOREIGN KEY (version_id) REFERENCES questionnaire_versions (id),
  CONSTRAINT fk_qq_section FOREIGN KEY (section_id) REFERENCES questionnaire_sections (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE questionnaire_options (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id  INT UNSIGNED NOT NULL,
  code         VARCHAR(20)  NOT NULL,
  label        VARCHAR(200) NOT NULL,
  sort         SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qo (question_id, code),
  CONSTRAINT fk_qo_question FOREIGN KEY (question_id) REFERENCES questionnaire_questions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE questionnaire_rules (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  version_id   SMALLINT UNSIGNED NOT NULL,
  code         VARCHAR(40)  NOT NULL,
  kind         ENUM('VISIBLE_IF','REQUIRED_IF','CHECK') NOT NULL,
  target_code  VARCHAR(40)  NULL,
  expression   TEXT NOT NULL COMMENT 'Expression JsonLogic',
  severity     ENUM('ERREUR','AVERTISSEMENT') NOT NULL DEFAULT 'ERREUR',
  message      VARCHAR(500) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qr (version_id, code),
  CONSTRAINT fk_qr_version FOREIGN KEY (version_id) REFERENCES questionnaire_versions (id),
  CONSTRAINT ck_qr_json CHECK (JSON_VALID(expression))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Correspondance des variables entre versions (comparaisons pluriannuelles)
CREATE TABLE variable_mappings (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  from_version_id  SMALLINT UNSIGNED NOT NULL,
  from_code        VARCHAR(60) NOT NULL,
  to_version_id    SMALLINT UNSIGNED NOT NULL,
  to_code          VARCHAR(60) NULL COMMENT 'NULL = non comparable',
  note             VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vm (from_version_id, from_code, to_version_id),
  CONSTRAINT fk_vm_from FOREIGN KEY (from_version_id) REFERENCES questionnaire_versions (id),
  CONSTRAINT fk_vm_to FOREIGN KEY (to_version_id) REFERENCES questionnaire_versions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. ANNÉES STATISTIQUES, CAMPAGNES, CIBLAGE, AFFECTATIONS
-- -----------------------------------------------------------------------------
CREATE TABLE academic_years (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        CHAR(9) CHARACTER SET ascii NOT NULL COMMENT 'AAAA-AAAA',
  label       VARCHAR(100) NOT NULL,
  start_date  DATE NOT NULL,
  end_date    DATE NOT NULL,
  status      ENUM('PREPARATION','ACTIVE','CLOTUREE') NOT NULL DEFAULT 'PREPARATION',
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_academic_years_code (code),
  CONSTRAINT fk_ay_creator FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT ck_ay_code CHECK (code REGEXP '^[0-9]{4}-[0-9]{4}$'),
  CONSTRAINT ck_ay_dates CHECK (end_date > start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaigns (
  id                        SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  academic_year_id          SMALLINT UNSIGNED NOT NULL,
  code                      VARCHAR(40)  NOT NULL,
  name                      VARCHAR(200) NOT NULL,
  questionnaire_version_id  SMALLINT UNSIGNED NOT NULL,
  status                    ENUM('PREPARATION','OUVERTE','SUSPENDUE','CLOTUREE','ARCHIVEE') NOT NULL DEFAULT 'PREPARATION',
  opens_on                  DATE NOT NULL,
  closes_on                 DATE NOT NULL,
  validation_levels         TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '1 = départemental ; 2 = départemental puis régional',
  instructions              TEXT NULL,
  created_by                INT UNSIGNED NULL,
  created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_campaigns_code (code),
  KEY ix_campaigns_year (academic_year_id),
  KEY ix_campaigns_status (status),
  CONSTRAINT fk_campaigns_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id),
  CONSTRAINT fk_campaigns_qv FOREIGN KEY (questionnaire_version_id) REFERENCES questionnaire_versions (id),
  CONSTRAINT fk_campaigns_creator FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT ck_campaigns_dates CHECK (closes_on >= opens_on),
  CONSTRAINT ck_campaigns_levels CHECK (validation_levels IN (1,2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaign_status_history (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id  SMALLINT UNSIGNED NOT NULL,
  from_status  VARCHAR(20) NULL,
  to_status    VARCHAR(20) NOT NULL,
  user_id      INT UNSIGNED NULL,
  reason       VARCHAR(500) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_campsh_campaign (campaign_id, created_at),
  CONSTRAINT fk_campsh_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id),
  CONSTRAINT fk_campsh_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Structures ciblées par une campagne
CREATE TABLE campaign_structures (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id   SMALLINT UNSIGNED NOT NULL,
  structure_id  INT UNSIGNED NOT NULL,
  added_by      INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_campaign_structures (campaign_id, structure_id),
  KEY ix_cs_structure (structure_id),
  CONSTRAINT fk_cs_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id),
  CONSTRAINT fk_cs_structure FOREIGN KEY (structure_id) REFERENCES structures (id),
  CONSTRAINT fk_cs_adder FOREIGN KEY (added_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zones de travail d'un agent pour une campagne (permet de proposer des
-- structures découvertes sur le terrain dans ses communes)
CREATE TABLE agent_areas (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id  SMALLINT UNSIGNED NOT NULL,
  agent_id     INT UNSIGNED NOT NULL,
  commune_id   SMALLINT UNSIGNED NOT NULL,
  assigned_by  INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_agent_areas (campaign_id, agent_id, commune_id),
  KEY ix_agent_areas_commune (campaign_id, commune_id),
  CONSTRAINT fk_aa_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id),
  CONSTRAINT fk_aa_agent FOREIGN KEY (agent_id) REFERENCES users (id),
  CONSTRAINT fk_aa_commune FOREIGN KEY (commune_id) REFERENCES communes (id),
  CONSTRAINT fk_aa_assigner FOREIGN KEY (assigned_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Affectation d'une structure ciblée à un agent ; historique conservé.
-- active_key garantit un seul agent actif par structure et par campagne.
CREATE TABLE assignments (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_structure_id  INT UNSIGNED NOT NULL,
  agent_id               INT UNSIGNED NOT NULL,
  assigned_by            INT UNSIGNED NULL,
  assigned_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at               DATETIME NULL,
  ended_by               INT UNSIGNED NULL,
  end_reason             VARCHAR(255) NULL,
  active_key             INT UNSIGNED AS (IF(ended_at IS NULL, campaign_structure_id, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assignments_active (active_key),
  KEY ix_assignments_agent (agent_id, ended_at),
  KEY ix_assignments_cs (campaign_structure_id),
  CONSTRAINT fk_asg_cs FOREIGN KEY (campaign_structure_id) REFERENCES campaign_structures (id),
  CONSTRAINT fk_asg_agent FOREIGN KEY (agent_id) REFERENCES users (id),
  CONSTRAINT fk_asg_assigner FOREIGN KEY (assigned_by) REFERENCES users (id),
  CONSTRAINT fk_asg_ender FOREIGN KEY (ended_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. COLLECTES
-- -----------------------------------------------------------------------------
-- Statuts serveur :
--   SOUMISE      en attente de contrôle au niveau review_level (1 = départemental, 2 = régional)
--   EN_CONTROLE  prise en charge par un superviseur au niveau review_level
--   VALIDEE      validation finale (dernier niveau prévu par la campagne)
--   INVALIDEE    renvoyée à l'agent avec motif
--   REOUVERTE    réouverture exceptionnelle par l'administrateur
-- BROUILLON / TERMINEE n'existent que sur le mobile.
CREATE TABLE collections (
  id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                      CHAR(36) CHARACTER SET ascii NOT NULL COMMENT 'Généré sur le mobile',
  campaign_structure_id     INT UNSIGNED NOT NULL,
  campaign_id               SMALLINT UNSIGNED NOT NULL,
  structure_id              INT UNSIGNED NOT NULL,
  questionnaire_version_id  SMALLINT UNSIGNED NOT NULL,
  agent_id                  INT UNSIGNED NOT NULL,
  status                    ENUM('SOUMISE','EN_CONTROLE','VALIDEE','INVALIDEE','REOUVERTE') NOT NULL DEFAULT 'SOUMISE',
  review_level              TINYINT UNSIGNED NOT NULL DEFAULT 1,
  revision                  INT UNSIGNED NOT NULL DEFAULT 1,
  region_id                 SMALLINT UNSIGNED NOT NULL COMMENT 'Instantané de localisation',
  department_id             SMALLINT UNSIGNED NOT NULL,
  commune_id                SMALLINT UNSIGNED NOT NULL,
  locality_label            VARCHAR(150) NULL,
  latitude                  DECIMAL(9,6) NULL,
  longitude                 DECIMAL(9,6) NULL,
  gps_accuracy_m            DECIMAL(7,1) NULL,
  gps_captured_at           DATETIME NULL,
  gps_status                ENUM('OK','MANUEL','INDISPONIBLE','REFUSE') NOT NULL DEFAULT 'INDISPONIBLE',
  gps_note                  VARCHAR(255) NULL,
  gps_distance_km           DECIMAL(7,2) NULL COMMENT 'Distance au centroïde de la commune',
  filled_on                 DATE NULL,
  device_started_at         DATETIME NULL,
  device_completed_at       DATETIME NULL,
  first_submitted_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_submitted_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  claimed_by                INT UNSIGNED NULL,
  claimed_at                DATETIME NULL,
  validated_by              INT UNSIGNED NULL,
  validated_at              DATETIME NULL,
  warnings_count            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  paper_form_signed         TINYINT(1) NOT NULL DEFAULT 0,
  payload_sha256            CHAR(64) CHARACTER SET ascii NOT NULL,
  created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_collections_uuid (uuid),
  UNIQUE KEY uq_collections_cs (campaign_structure_id),
  KEY ix_collections_campaign_status (campaign_id, status, review_level),
  KEY ix_collections_geo (campaign_id, region_id, department_id, commune_id),
  KEY ix_collections_agent (agent_id, campaign_id),
  KEY ix_collections_structure (structure_id),
  KEY ix_collections_submitted (campaign_id, last_submitted_at),
  CONSTRAINT fk_col_cs FOREIGN KEY (campaign_structure_id) REFERENCES campaign_structures (id),
  CONSTRAINT fk_col_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id),
  CONSTRAINT fk_col_structure FOREIGN KEY (structure_id) REFERENCES structures (id),
  CONSTRAINT fk_col_qv FOREIGN KEY (questionnaire_version_id) REFERENCES questionnaire_versions (id),
  CONSTRAINT fk_col_agent FOREIGN KEY (agent_id) REFERENCES users (id),
  CONSTRAINT fk_col_region FOREIGN KEY (region_id) REFERENCES regions (id),
  CONSTRAINT fk_col_department FOREIGN KEY (department_id) REFERENCES departments (id),
  CONSTRAINT fk_col_commune FOREIGN KEY (commune_id) REFERENCES communes (id),
  CONSTRAINT fk_col_claimer FOREIGN KEY (claimed_by) REFERENCES users (id),
  CONSTRAINT fk_col_validator FOREIGN KEY (validated_by) REFERENCES users (id),
  CONSTRAINT ck_col_level CHECK (review_level IN (1,2)),
  CONSTRAINT ck_col_lat CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90),
  CONSTRAINT ck_col_lng CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chaque soumission acceptée est conservée intégralement et jamais modifiée
CREATE TABLE collection_submissions (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  collection_id        INT UNSIGNED NOT NULL,
  revision             INT UNSIGNED NOT NULL,
  payload_json         LONGTEXT NOT NULL,
  payload_sha256       CHAR(64) CHARACTER SET ascii NOT NULL,
  validation_report    LONGTEXT NULL COMMENT 'Erreurs/avertissements rejoués par le serveur',
  device_id            INT UNSIGNED NULL,
  app_version          VARCHAR(20) NULL,
  submitted_by         INT UNSIGNED NOT NULL,
  received_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_csub_revision (collection_id, revision),
  KEY ix_csub_hash (collection_id, payload_sha256),
  CONSTRAINT fk_csub_collection FOREIGN KEY (collection_id) REFERENCES collections (id),
  CONSTRAINT fk_csub_device FOREIGN KEY (device_id) REFERENCES devices (id),
  CONSTRAINT fk_csub_user FOREIGN KEY (submitted_by) REFERENCES users (id),
  CONSTRAINT ck_csub_payload CHECK (JSON_VALID(payload_json)),
  CONSTRAINT ck_csub_report CHECK (validation_report IS NULL OR JSON_VALID(validation_report))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Réponses simples (Sections I, II, V) ; une ligne par option cochée pour un choix multiple
CREATE TABLE collection_answers (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  collection_id  INT UNSIGNED NOT NULL,
  question_code  VARCHAR(40) CHARACTER SET ascii NOT NULL,
  option_code    VARCHAR(20) CHARACTER SET ascii NOT NULL DEFAULT '',
  value_text     TEXT NULL,
  value_number   DECIMAL(14,4) NULL,
  value_date     DATE NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ca (collection_id, question_code, option_code),
  KEY ix_ca_question (question_code, option_code),
  CONSTRAINT fk_ca_collection FOREIGN KEY (collection_id) REFERENCES collections (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Section III — maîtres artisans / formateurs
CREATE TABLE collection_trainers (
  id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  collection_id               INT UNSIGNED NOT NULL,
  line_no                     SMALLINT UNSIGNED NOT NULL,
  full_name                   VARCHAR(150) NOT NULL,
  sex                         ENUM('M','F') NOT NULL,
  grade                       ENUM('MAITRE_ARTISAN','FORMATEUR') NOT NULL,
  initial_training            TINYINT(1) NOT NULL,
  continuing_trainings_count  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  certified                   TINYINT(1) NOT NULL,
  cert_name                   VARCHAR(150) NULL,
  cert_level                  VARCHAR(100) NULL,
  cert_body                   VARCHAR(150) NULL,
  cert_year                   SMALLINT UNSIGNED NULL,
  nationality                 ENUM('NIGERIEN','UEMOA','AUTRE') NOT NULL,
  pedagogical_followup        TINYINT(1) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ct (collection_id, line_no),
  CONSTRAINT fk_ct_collection FOREIGN KEY (collection_id) REFERENCES collections (id),
  CONSTRAINT ck_ct_cert_year CHECK (cert_year IS NULL OR cert_year BETWEEN 1950 AND 2100),
  CONSTRAINT ck_ct_cert_fields CHECK (certified = 1 OR (cert_name IS NULL AND cert_level IS NULL AND cert_body IS NULL AND cert_year IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Section IV — une ligne par métier (secteur, durée) ; effectifs par promotion × sexe
CREATE TABLE collection_trades (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  collection_id      INT UNSIGNED NOT NULL,
  sector_id          TINYINT UNSIGNED NOT NULL,
  line_no            SMALLINT UNSIGNED NOT NULL,
  trade_id           SMALLINT UNSIGNED NULL,
  trade_other_label  VARCHAR(150) NULL,
  duration_class     ENUM('LE3','M4_9','GT9') NOT NULL COMMENT '<= 3 mois / 4 à 9 mois / > 9 mois',
  p1_m  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  p1_f  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  p2_m  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  p2_f  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  p3_m  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  p3_f  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ctr (collection_id, sector_id, line_no),
  KEY ix_ctr_trade (trade_id),
  CONSTRAINT fk_ctr_collection FOREIGN KEY (collection_id) REFERENCES collections (id),
  CONSTRAINT fk_ctr_sector FOREIGN KEY (sector_id) REFERENCES sectors (id),
  CONSTRAINT fk_ctr_trade FOREIGN KEY (trade_id) REFERENCES trades (id),
  CONSTRAINT ck_ctr_trade CHECK (trade_id IS NOT NULL OR trade_other_label IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Section IV — lignes « dont » par secteur × catégorie × promotion × sexe
CREATE TABLE collection_sector_specials (
  collection_id  INT UNSIGNED NOT NULL,
  sector_id      TINYINT UNSIGNED NOT NULL,
  category       ENUM('HANDICAPES','REFUGIES','RETOURNES','DEPLACES') NOT NULL,
  p1_m  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  p1_f  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  p2_m  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  p2_f  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  p3_m  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  p3_f  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (collection_id, sector_id, category),
  CONSTRAINT fk_css_collection FOREIGN KEY (collection_id) REFERENCES collections (id),
  CONSTRAINT fk_css_sector FOREIGN KEY (sector_id) REFERENCES sectors (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Question 2.2 — jeunes accompagnés : durée × mesure × sexe
CREATE TABLE collection_accompanied (
  collection_id   INT UNSIGNED NOT NULL,
  duration_class  ENUM('LE3','M4_9','GT9') NOT NULL,
  measure         ENUM('INSCRITS','PLACES','OUTILLES') NOT NULL,
  male            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  female          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (collection_id, duration_class, measure),
  CONSTRAINT fk_cac_collection FOREIGN KEY (collection_id) REFERENCES collections (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE collection_reviews (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  collection_id  INT UNSIGNED NOT NULL,
  revision       INT UNSIGNED NOT NULL,
  level          TINYINT UNSIGNED NOT NULL COMMENT '1 départemental, 2 régional, 9 administrateur',
  reviewer_id    INT UNSIGNED NOT NULL,
  decision       ENUM('VALIDEE','INVALIDEE','REOUVERTURE') NOT NULL,
  reason_id      SMALLINT UNSIGNED NULL,
  comment        TEXT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_cr_collection (collection_id, created_at),
  KEY ix_cr_reviewer (reviewer_id, created_at),
  CONSTRAINT fk_cr_collection FOREIGN KEY (collection_id) REFERENCES collections (id),
  CONSTRAINT fk_cr_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (id),
  CONSTRAINT fk_cr_reason FOREIGN KEY (reason_id) REFERENCES rejection_reasons (id),
  CONSTRAINT ck_cr_reason CHECK (decision = 'VALIDEE' OR reason_id IS NOT NULL OR (comment IS NOT NULL AND CHAR_LENGTH(comment) >= 5))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE collection_status_history (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  collection_id  INT UNSIGNED NOT NULL,
  from_status    VARCHAR(20) NULL,
  to_status      VARCHAR(20) NOT NULL,
  review_level   TINYINT UNSIGNED NULL,
  revision       INT UNSIGNED NOT NULL,
  user_id        INT UNSIGNED NULL,
  note           VARCHAR(500) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_colsh_collection (collection_id, created_at),
  KEY ix_colsh_time (created_at, to_status),
  CONSTRAINT fk_colsh_collection FOREIGN KEY (collection_id) REFERENCES collections (id),
  CONSTRAINT fk_colsh_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE collection_attachments (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  collection_id  INT UNSIGNED NOT NULL,
  file_id        BIGINT UNSIGNED NOT NULL,
  kind           ENUM('FICHE_SIGNEE','AUTRE') NOT NULL DEFAULT 'FICHE_SIGNEE',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cat (collection_id, file_id),
  CONSTRAINT fk_cat_collection FOREIGN KEY (collection_id) REFERENCES collections (id),
  CONSTRAINT fk_cat_file FOREIGN KEY (file_id) REFERENCES files (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sync_logs (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED NOT NULL,
  device_id        INT UNSIGNED NULL,
  direction        ENUM('PUSH','PULL') NOT NULL,
  endpoint         VARCHAR(120) NOT NULL,
  collection_uuid  CHAR(36) CHARACTER SET ascii NULL,
  http_status      SMALLINT UNSIGNED NOT NULL,
  outcome          ENUM('ACCEPTE','REJOUE','CONFLIT','REFUSE','ERREUR','OK') NOT NULL,
  detail           VARCHAR(500) NULL,
  duration_ms      INT UNSIGNED NULL,
  request_bytes    INT UNSIGNED NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_sync_user_time (user_id, created_at),
  KEY ix_sync_collection (collection_uuid),
  KEY ix_sync_time_outcome (created_at, outcome),
  CONSTRAINT fk_sync_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_sync_device FOREIGN KEY (device_id) REFERENCES devices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. VUES DÉRIVÉES (variables calculées du questionnaire)
-- -----------------------------------------------------------------------------
-- Q2.1 : effectif des APPRENTIS par durée × secteur × sexe, calculé depuis la Section IV
CREATE VIEW v_q2_1_apprentices AS
SELECT ct.collection_id,
       ct.duration_class,
       ct.sector_id,
       SUM(ct.p1_m + ct.p2_m + ct.p3_m) AS male,
       SUM(ct.p1_f + ct.p2_f + ct.p3_f) AS female,
       SUM(ct.p1_m + ct.p2_m + ct.p3_m + ct.p1_f + ct.p2_f + ct.p3_f) AS total
FROM collection_trades ct
GROUP BY ct.collection_id, ct.duration_class, ct.sector_id;

-- Q2.19 : MAÎTRES ARTISANS / FORMATEURS par sexe, calculé depuis la Section III
CREATE VIEW v_q2_19_trainers AS
SELECT t.collection_id,
       SUM(t.sex = 'M') AS male,
       SUM(t.sex = 'F') AS female,
       COUNT(*)         AS total
FROM collection_trainers t
GROUP BY t.collection_id;

-- (La table schema_migrations est créée et tenue par backend/bin/migrate.php.)

SET foreign_key_checks = 1;
