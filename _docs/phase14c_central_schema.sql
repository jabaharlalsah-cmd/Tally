-- ============================================================================
--  ZeroBook — Phase 14C central-database schema (phpMyAdmin / raw SQL)
-- ============================================================================
--  Backups, data export, and offboarding. Apply against the CENTRAL database
--  (default: `zerobook_central`). Hand-runnable equivalent of the migration
--  2019_09_15_000110_create_14c_backup_export_offboarding.php.
--
--  NO tenant-database change. `tenants.status` gains string values `restored`,
--  `archived`, `purge_scheduled`, `purged` (it is a VARCHAR — no enum change). The three
--  new tenants columns are also listed in Tenant::getCustomColumns() (stancl VirtualColumn).
--
--  Backup / export files live in PRIVATE storage (storage/app/private/{backups,exports}/…),
--  served only through access-controlled controllers.
-- ============================================================================

USE `zerobook_central`;

-- 1) Verified backups of tenant databases.
CREATE TABLE `tenant_backups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled', -- scheduled|on_demand|pre_offboarding
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size_bytes` bigint unsigned NOT NULL DEFAULT '0',
  `meta` json DEFAULT NULL,              -- voucher/entry counts for restore verification
  `taken_at` timestamp NULL DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tenant_backups_tenant_id_taken_at_index` (`tenant_id`,`taken_at`),
  KEY `tenant_backups_expires_at_index` (`expires_at`),
  CONSTRAINT `tenant_backups_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Customer data exports.
CREATE TABLE `tenant_exports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `initiated_by_user_id` bigint unsigned DEFAULT NULL,
  `initiated_by_admin_id` bigint unsigned DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued', -- queued|processing|completed|failed
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size_bytes` bigint unsigned NOT NULL DEFAULT '0',
  `error` text COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `download_expires_at` timestamp NULL DEFAULT NULL,
  `downloaded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tenant_exports_tenant_id_created_at_index` (`tenant_id`,`created_at`),
  CONSTRAINT `tenant_exports_initiated_by_admin_id_foreign` FOREIGN KEY (`initiated_by_admin_id`) REFERENCES `platform_admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_exports_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Offboarding lifecycle audit trail (append-only).
CREATE TABLE `tenant_lifecycle_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `triggered_by_user_id` bigint unsigned DEFAULT NULL,
  `triggered_by_admin_id` bigint unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tenant_lifecycle_events_tenant_id_created_at_index` (`tenant_id`,`created_at`),
  CONSTRAINT `tenant_lifecycle_events_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenant_lifecycle_events_triggered_by_admin_id_foreign` FOREIGN KEY (`triggered_by_admin_id`) REFERENCES `platform_admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) tenants — the offboarding schedule dates.
ALTER TABLE `tenants`
  ADD COLUMN `offboarding_initiated_at` TIMESTAMP NULL DEFAULT NULL AFTER `plan_ends_at`,
  ADD COLUMN `archive_scheduled_for`    TIMESTAMP NULL DEFAULT NULL AFTER `offboarding_initiated_at`,
  ADD COLUMN `purge_scheduled_for`      TIMESTAMP NULL DEFAULT NULL AFTER `archive_scheduled_for`;
-- ============================================================================
