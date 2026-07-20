-- ============================================================================
-- ZeroBook · Phase 16 — owner mobile number on tenant logins
-- phpMyAdmin-ready SQL. Matches the Laravel migration:
--   database/migrations/2026_07_16_000004_add_mobile_to_tenant_users.php
-- Run against the CENTRAL database: u958726172_zerobook
-- ============================================================================

ALTER TABLE `tenant_users`
  ADD COLUMN `mobile` VARCHAR(32) NULL DEFAULT NULL AFTER `email`;

-- Rollback:
-- ALTER TABLE `tenant_users` DROP COLUMN `mobile`;
