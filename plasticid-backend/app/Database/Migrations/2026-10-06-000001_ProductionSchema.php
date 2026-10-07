<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Production schema - the single source of truth for the database.
 *
 * FastAPI (`plasticid/api/database.py`) never creates or alters tables; it only
 * verifies this contract at startup. `php spark migrate` runs with the
 * dedicated `migrations` DB group (see Config\Services::migrations), so this
 * class executes as plasticid_migrate, while the web-facing default group
 * (plasticid_ci) stays least-privilege.
 *
 * Wipe policy (approved): up() drops and recreates every application table.
 * Always take a backup first: scripts/backup-db.sh.
 */
class ProductionSchema extends Migration
{
    /**
     * Tables owned by this migration. CodeIgniter's own `migrations`
     * bookkeeping table is deliberately never touched.
     */
    private const TABLES = [
        'api_keys',
        'prediction_logs',
        'prediction_outbox',
        'images',
        'predictions',
        'api_key_requests',
        'jobs',              // unreferenced prototype table
        'schema_migrations', // legacy FastAPI bookkeeping table
    ];

    private const RETENTION_EVENTS = [
        'ev_purge_prediction_logs',
        'ev_purge_prediction_outbox',
        'ev_purge_api_key_requests',
    ];

    public function up()
    {
        $this->dropApplicationTables();
        $this->createApplicationTables();
        $this->grantRuntimePrivileges();
        $this->createRetentionEvents();
    }

    public function down()
    {
        $this->dropRetentionEvents();
        $this->dropApplicationTables();
    }

    private function dropApplicationTables(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLES as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function createApplicationTables(): void
    {
        $this->db->query(<<<'SQL'
CREATE TABLE `api_keys` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `api_key` VARCHAR(64) NOT NULL,
  `owner` VARCHAR(255) NOT NULL,
  `key_type` VARCHAR(16) NOT NULL DEFAULT 'user',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `rate_limit` INT UNSIGNED NOT NULL DEFAULT 10,
  `window_seconds` INT UNSIGNED NOT NULL DEFAULT 60,
  `total_requests` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `last_used_at` DATETIME(6) NULL,
  `deactivated_at` DATETIME(6) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_keys_api_key` (`api_key`),
  KEY `idx_api_keys_owner_status` (`owner`, `is_active`),
  KEY `idx_api_keys_last_used_at` (`last_used_at`),
  CONSTRAINT `chk_api_keys_active` CHECK (`is_active` IN (0, 1)),
  CONSTRAINT `chk_api_keys_key_type` CHECK (`key_type` IN ('user', 'service')),
  CONSTRAINT `chk_api_keys_rate_limit` CHECK (`rate_limit` >= 1),
  CONSTRAINT `chk_api_keys_window` CHECK (`window_seconds` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `prediction_logs` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `job_id` VARCHAR(64) NOT NULL,
  `api_key` VARCHAR(64) NOT NULL,
  `api_key_id` BIGINT NULL,
  `model` VARCHAR(64) NOT NULL DEFAULT '',
  `detections` INT UNSIGNED NOT NULL DEFAULT 0,
  `inference_ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `source` VARCHAR(16) NOT NULL DEFAULT 'api',
  `detected_object` VARCHAR(64) NULL,
  `image_sha256` CHAR(64) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prediction_logs_job_id` (`job_id`),
  KEY `idx_prediction_logs_api_key` (`api_key`),
  KEY `idx_prediction_logs_api_key_created` (`api_key`, `created_at`),
  KEY `idx_prediction_logs_api_key_id` (`api_key_id`),
  KEY `idx_prediction_logs_created_at` (`created_at`),
  CONSTRAINT `fk_prediction_logs_api_key` FOREIGN KEY (`api_key_id`) REFERENCES `api_keys` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_prediction_logs_source` CHECK (`source` IN ('api', 'portal')),
  CONSTRAINT `chk_prediction_logs_detections` CHECK (`detections` >= 0),
  CONSTRAINT `chk_prediction_logs_inference` CHECK (`inference_ms` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `prediction_outbox` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `job_id` VARCHAR(64) NOT NULL,
  `api_key_id` BIGINT NULL,
  `payload` JSON NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `next_attempt_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `locked_at` DATETIME(6) NULL,
  `claim_token` CHAR(32) NULL,
  `delivered_at` DATETIME(6) NULL,
  `last_error` VARCHAR(255) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prediction_outbox_job_id` (`job_id`),
  KEY `idx_prediction_outbox_dispatch` (`status`, `next_attempt_at`),
  KEY `idx_prediction_outbox_api_key` (`api_key_id`),
  CONSTRAINT `fk_prediction_outbox_api_key` FOREIGN KEY (`api_key_id`) REFERENCES `api_keys` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_prediction_outbox_status` CHECK (`status` IN ('pending', 'processing', 'delivered', 'failed', 'dead_letter')),
  CONSTRAINT `chk_prediction_outbox_attempts` CHECK (`attempts` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `images` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `filename` VARCHAR(255) NOT NULL,
  `job_id` VARCHAR(64) NOT NULL,
  `source` VARCHAR(16) NOT NULL DEFAULT 'api',
  `api_key_id` BIGINT NULL,
  `model` VARCHAR(64) NULL,
  `inference_ms` INT UNSIGNED NULL,
  `detection_count` INT UNSIGNED NULL,
  `detected_object` VARCHAR(64) NULL,
  `image_width` INT UNSIGNED NULL,
  `image_height` INT UNSIGNED NULL,
  `image_sha256` CHAR(64) NULL,
  `content_type` VARCHAR(100) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_images_job_id` (`job_id`),
  KEY `idx_images_api_key_id` (`api_key_id`),
  KEY `idx_images_image_sha256` (`image_sha256`),
  KEY `idx_images_created_at` (`created_at`),
  CONSTRAINT `fk_images_api_key` FOREIGN KEY (`api_key_id`) REFERENCES `api_keys` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_images_source` CHECK (`source` IN ('api', 'portal')),
  CONSTRAINT `chk_images_detection_count` CHECK (`detection_count` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `predictions` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `image_id` BIGINT NOT NULL,
  `label` VARCHAR(100) NOT NULL,
  `confidence` FLOAT NOT NULL,
  `x1` FLOAT NOT NULL,
  `y1` FLOAT NOT NULL,
  `x2` FLOAT NOT NULL,
  `y2` FLOAT NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_predictions_image_id` (`image_id`),
  CONSTRAINT `fk_predictions_image` FOREIGN KEY (`image_id`) REFERENCES `images` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_predictions_confidence` CHECK (`confidence` >= 0 AND `confidence` <= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `api_key_requests` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(254) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `api_key_id` BIGINT NULL,
  `error_message` VARCHAR(255) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_api_key_requests_status_created` (`status`, `created_at`),
  CONSTRAINT `fk_api_key_requests_api_key` FOREIGN KEY (`api_key_id`) REFERENCES `api_keys` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_api_key_requests_status` CHECK (`status` IN ('pending', 'approved', 'failed', 'rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
    }

    /**
     * Table-level DML grants for the runtime users. MySQL refuses to grant on
     * a table that does not exist yet, so these cannot live in bootstrap.sh;
     * they belong here, right after the tables are created. The db-level
     * SELECT grants and this user's GRANT OPTION come from mysql/bootstrap.sh.
     */
    private function grantRuntimePrivileges(): void
    {
        $api  = "'plasticid_api'@'%'";
        $ci   = "'plasticid_ci'@'%'";
        $tabs = ['api_keys', 'prediction_logs', 'prediction_outbox'];
        foreach ($tabs as $table) {
            $this->db->query("GRANT INSERT, UPDATE, DELETE ON `{$table}` TO {$api}");
        }
        foreach (['images', 'predictions', 'api_key_requests'] as $table) {
            $this->db->query("GRANT SELECT, INSERT, UPDATE ON `{$table}` TO {$ci}");
        }
    }

    /**
     * Retention: scheduled MySQL events (event_scheduler is enabled via the
     * mysqld --event-scheduler=ON flag in docker-compose.yml). Each statement
     * is batched so a large backlog is drained across multiple runs.
     */
    private function createRetentionEvents(): void
    {
        $this->db->query(<<<'SQL'
CREATE EVENT IF NOT EXISTS `ev_purge_prediction_logs`
ON SCHEDULE EVERY 1 HOUR
ON COMPLETION PRESERVE ENABLE
DO DELETE FROM `prediction_logs`
   WHERE `created_at` < NOW(6) - INTERVAL 90 DAY
   ORDER BY `id` LIMIT 5000
SQL);

        $this->db->query(<<<'SQL'
CREATE EVENT IF NOT EXISTS `ev_purge_prediction_outbox`
ON SCHEDULE EVERY 1 HOUR
ON COMPLETION PRESERVE ENABLE
DO DELETE FROM `prediction_outbox`
   WHERE `status` IN ('delivered', 'dead_letter')
     AND `created_at` < NOW(6) - INTERVAL 7 DAY
   ORDER BY `id` LIMIT 5000
SQL);

        $this->db->query(<<<'SQL'
CREATE EVENT IF NOT EXISTS `ev_purge_api_key_requests`
ON SCHEDULE EVERY 1 DAY
ON COMPLETION PRESERVE ENABLE
DO DELETE FROM `api_key_requests`
   WHERE `created_at` < NOW(6) - INTERVAL 90 DAY
   ORDER BY `id` LIMIT 5000
SQL);
    }

    private function dropRetentionEvents(): void
    {
        foreach (self::RETENTION_EVENTS as $event) {
            $this->db->query('DROP EVENT IF EXISTS `' . $event . '`');
        }
    }
}
