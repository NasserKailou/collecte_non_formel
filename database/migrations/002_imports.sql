-- =============================================================================
-- 002 — Imports Excel en deux temps (prévisualisation puis validation tout-ou-rien)
-- Compatible MariaDB 10.4 et MySQL 8.
-- =============================================================================

CREATE TABLE imports (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36) CHARACTER SET ascii NOT NULL COMMENT 'Identifiant exposé par l API (non séquentiel)',
  type            ENUM('USERS','STRUCTURES') NOT NULL,
  status          ENUM('PREVISUALISE','IMPORTE','ANNULE') NOT NULL DEFAULT 'PREVISUALISE',
  source_file_id  BIGINT UNSIGNED NOT NULL,
  report_file_id  BIGINT UNSIGNED NULL COMMENT 'Rapport d anomalies, ou archive protégée des identifiants après import',
  total_rows      INT UNSIGNED NOT NULL DEFAULT 0,
  valid_rows      INT UNSIGNED NOT NULL DEFAULT 0,
  error_rows      INT UNSIGNED NOT NULL DEFAULT 0,
  summary_json    LONGTEXT NULL,
  created_by      INT UNSIGNED NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  committed_by    INT UNSIGNED NULL,
  committed_at    DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_imports_uuid (uuid),
  KEY ix_imports_creator (created_by, created_at),
  CONSTRAINT fk_imports_source FOREIGN KEY (source_file_id) REFERENCES files (id),
  CONSTRAINT fk_imports_report FOREIGN KEY (report_file_id) REFERENCES files (id),
  CONSTRAINT fk_imports_creator FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_imports_committer FOREIGN KEY (committed_by) REFERENCES users (id),
  CONSTRAINT ck_imports_summary CHECK (summary_json IS NULL OR JSON_VALID(summary_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
