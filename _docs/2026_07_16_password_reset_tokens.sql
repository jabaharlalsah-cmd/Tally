-- ============================================================================
-- ZeroBook · Phase 16 — password self-service (forgot / reset / change)
-- phpMyAdmin-ready SQL. Matches the two Laravel migrations:
--   database/migrations/2026_07_16_000001_create_platform_password_reset_tokens_table.php
--   database/migrations/2026_07_16_000002_create_tenant_password_reset_tokens_table.php
-- Run against the CENTRAL database: u958726172_zerobook
-- (Both tables also hold for the `users` broker's existing `password_reset_tokens`.)
-- ============================================================================

-- Platform-admin reset tokens (admin.<domain>).
CREATE TABLE IF NOT EXISTS `platform_password_reset_tokens` (
  `email`      VARCHAR(255) NOT NULL,
  `token`      VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tenant-user reset tokens (tenant subdomains). The user lookup during reset is
-- additionally scoped by tenant_id in the application layer.
CREATE TABLE IF NOT EXISTS `tenant_password_reset_tokens` (
  `email`      VARCHAR(255) NOT NULL,
  `token`      VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback:
-- DROP TABLE IF EXISTS `platform_password_reset_tokens`;
-- DROP TABLE IF EXISTS `tenant_password_reset_tokens`;
