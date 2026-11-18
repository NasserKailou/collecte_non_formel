-- Lot L6b — Saisie multicanal (tablette, web, questionnaire papier) et évolution du questionnaire.
--
-- 1. Mode de collecte de la campagne : TABLETTE (application mobile), PAPIER (questionnaires papier saisis ensuite
--    dans le back-office) ou MIXTE (les deux). Le serveur accepte les canaux autorisés par le mode.
-- 2. Canal de chaque collecte et de chaque révision (MOBILE, WEB, PAPIER) et, pour le papier, les références de la
--    fiche (numéro, date de l'entretien, nom de l'enquêteur qui l'a remplie) ; l'utilisateur connecté est celui qui saisit.
-- 3. Brouillons de saisie web conservés sur le serveur (la tablette garde les siens dans sa base locale).
-- Ajouts uniquement, avec valeurs par défaut : les données existantes ne sont pas modifiées.

ALTER TABLE campaigns
  ADD COLUMN collection_mode ENUM('TABLETTE','PAPIER','MIXTE') NOT NULL DEFAULT 'TABLETTE' AFTER validation_levels;

ALTER TABLE collections
  ADD COLUMN channel              ENUM('MOBILE','WEB','PAPIER') NOT NULL DEFAULT 'MOBILE' AFTER agent_id,
  ADD COLUMN paper_form_ref       VARCHAR(50)  NULL AFTER channel,
  ADD COLUMN paper_interview_date DATE         NULL AFTER paper_form_ref,
  ADD COLUMN paper_enumerator     VARCHAR(150) NULL AFTER paper_interview_date,
  ADD KEY ix_collections_channel (campaign_id, channel);

ALTER TABLE collection_submissions
  ADD COLUMN channel ENUM('MOBILE','WEB','PAPIER') NOT NULL DEFAULT 'MOBILE' AFTER app_version;

CREATE TABLE collection_drafts (
  campaign_structure_id INT UNSIGNED NOT NULL,
  uuid                  CHAR(36) CHARACTER SET ascii NOT NULL COMMENT 'Identifiant réservé pour la future collecte',
  user_id               INT UNSIGNED NOT NULL,
  channel               ENUM('WEB','PAPIER') NOT NULL,
  data_json             LONGTEXT NOT NULL,
  paper_json            TEXT NULL COMMENT 'Références de la fiche papier : form_ref, interview_date, enumerator',
  errors_count          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  warnings_count        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (campaign_structure_id),
  UNIQUE KEY uq_drafts_uuid (uuid),
  KEY ix_drafts_user (user_id),
  CONSTRAINT fk_drafts_cs FOREIGN KEY (campaign_structure_id) REFERENCES campaign_structures (id),
  CONSTRAINT fk_drafts_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT ck_drafts_json CHECK (JSON_VALID(data_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
