-- Versions de l'application mobile (APK) publiées depuis l'administration.
-- La tablette interroge /app/releases/latest à chaque synchronisation et propose la mise à jour ; une version
-- « obligatoire » relève la version minimale acceptée par le serveur (426 UPGRADE_REQUIRED en dessous).
-- Le fichier est conservé dans storage/releases/ ; l'empreinte SHA-256 permet à la tablette de vérifier le téléchargement.

CREATE TABLE app_releases (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version       VARCHAR(20)  NOT NULL COMMENT 'Version sémantique, ex. 1.2.0',
  file_name     VARCHAR(150) NOT NULL,
  storage_path  VARCHAR(255) NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL,
  sha256        CHAR(64) CHARACTER SET ascii NOT NULL,
  notes         TEXT NULL COMMENT 'Nouveautés affichées aux agents',
  mandatory     TINYINT(1) NOT NULL DEFAULT 0,
  status        ENUM('PUBLIEE','RETIREE') NOT NULL DEFAULT 'PUBLIEE',
  published_by  INT UNSIGNED NOT NULL,
  published_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  retired_at    DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_app_releases_version (version),
  UNIQUE KEY uq_app_releases_sha (sha256),
  CONSTRAINT fk_app_releases_user FOREIGN KEY (published_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
