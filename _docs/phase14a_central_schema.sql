-- ============================================================================
--  ZeroBook — Phase 14A central-database schema (phpMyAdmin / raw SQL)
-- ============================================================================
--  SaaS operations: self-signup + platform-admin surface.
--
--  Apply against the CENTRAL database (default: `zerobook_central`). This is the
--  hand-runnable equivalent of the Laravel migrations:
--    2019_09_15_000007_add_trial_and_paid_plans.php
--    2019_09_15_000050_extend_tenants_for_saas.php
--    2019_09_15_000060_add_verified_at_to_tenant_users.php
--    2019_09_15_000070_create_platform_admin_actions_table.php
--
--  It is idempotent-friendly: the plan inserts use INSERT IGNORE; the ALTERs assume
--  the columns do not yet exist. NO tenant-database change is needed in 14A.
--
--  NOTE on the `tenants` table: App\Models\Tenant is a stancl VirtualColumn model.
--  The three new columns below are REAL columns AND are listed in
--  Tenant::getCustomColumns() — if you add columns here without updating that array,
--  the model would round-trip them through the JSON `data` column instead.
-- ============================================================================

USE `zerobook_central`;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1) Plans — add `trial` (self-signup lands here) and `paid-monthly` (manual
--    conversion target). Both unlock every feature; the trial WINDOW is on the
--    tenant row (trial_ends_at), not the plan.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `plans` (`tier`, `name`, `price_inr`, `features`, `created_at`, `updated_at`) VALUES
  ('trial', 'Free Trial', 0,
   '{"gst":true,"vat":true,"tds":true,"bill_by_bill":true,"cost_centres":true,"multi_currency":true}',
   NOW(), NOW()),
  ('paid-monthly', 'Paid — Monthly', 1499,
   '{"gst":true,"vat":true,"tds":true,"bill_by_bill":true,"cost_centres":true,"multi_currency":true}',
   NOW(), NOW());

-- ─────────────────────────────────────────────────────────────────────────────
-- 2) tenants — SaaS lifecycle columns.
--    status also gains two new string values (no enum change — it is a VARCHAR):
--    'pending_verification' and 'expired_trial'.
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `tenants`
  ADD COLUMN `trial_ends_at`  TIMESTAMP NULL DEFAULT NULL AFTER `provisioned_at`,
  ADD COLUMN `verified_at`    TIMESTAMP NULL DEFAULT NULL AFTER `trial_ends_at`,
  ADD COLUMN `last_active_at` TIMESTAMP NULL DEFAULT NULL AFTER `verified_at`;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3) tenant_users — email verification marker.
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `tenant_users`
  ADD COLUMN `verified_at` TIMESTAMP NULL DEFAULT NULL AFTER `role`;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4) platform_admin_actions — the platform-admin audit log (append-only).
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `platform_admin_actions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` bigint unsigned DEFAULT NULL,
  `action` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target_user_id` bigint unsigned DEFAULT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `meta` json DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `platform_admin_actions_tenant_id_created_at_index` (`tenant_id`,`created_at`),
  KEY `platform_admin_actions_admin_id_created_at_index` (`admin_id`,`created_at`),
  KEY `platform_admin_actions_action_index` (`action`),
  CONSTRAINT `platform_admin_actions_admin_id_foreign`
    FOREIGN KEY (`admin_id`) REFERENCES `platform_admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  Done. The action vocabulary written into platform_admin_actions.action:
--    provision | impersonate_start | impersonate_write_on | impersonate_write_off
--    | impersonate_end | suspend | reactivate | extend_trial | plan_change
-- ============================================================================
