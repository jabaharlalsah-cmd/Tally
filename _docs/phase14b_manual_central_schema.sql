-- ============================================================================
--  ZeroBook — Phase 14B (Manual) central-database schema (phpMyAdmin / raw SQL)
-- ============================================================================
--  Manual payment recording + subscription management. Apply against the CENTRAL
--  database (default: `zerobook_central`). Hand-runnable equivalent of the migrations:
--    2019_09_15_000080_extend_plans_for_billing.php
--    2019_09_15_000090_add_plan_ends_at_to_tenants.php
--    2019_09_15_000100_create_payments_table.php
--
--  NO tenant-database change in 14B. NOTE: App\Models\Tenant is a stancl VirtualColumn
--  model — `plan_ends_at` below is also listed in Tenant::getCustomColumns() (else it
--  round-trips through the JSON `data` column).
-- ============================================================================

USE `zerobook_central`;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1) plans — billing columns. price_inr widens from unsignedInteger to decimal.
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `plans`
  MODIFY `price_inr` DECIMAL(12,2) NULL,
  ADD COLUMN `price_npr` DECIMAL(12,2) NULL AFTER `price_inr`,
  ADD COLUMN `billing_period_months` SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `price_npr`,
  ADD COLUMN `is_public` TINYINT(1) NOT NULL DEFAULT 0 AFTER `billing_period_months`;

-- Purchasable plans (prices are PLACEHOLDERS, editable from the admin Plans screen).
INSERT INTO `plans` (`tier`,`name`,`price_inr`,`price_npr`,`billing_period_months`,`is_public`,`features`,`created_at`,`updated_at`) VALUES
  ('starter-monthly','Starter — Monthly',499,799,1,1,'{"gst":true,"vat":true,"tds":true,"bill_by_bill":true,"cost_centres":false,"multi_currency":false}',NOW(),NOW()),
  ('starter-annual','Starter — Annual',4990,7990,12,1,'{"gst":true,"vat":true,"tds":true,"bill_by_bill":true,"cost_centres":false,"multi_currency":false}',NOW(),NOW()),
  ('professional-monthly','Professional — Monthly',1499,2399,1,1,'{"gst":true,"vat":true,"tds":true,"bill_by_bill":true,"cost_centres":true,"multi_currency":false}',NOW(),NOW()),
  ('professional-annual','Professional — Annual',14990,23990,12,1,'{"gst":true,"vat":true,"tds":true,"bill_by_bill":true,"cost_centres":true,"multi_currency":false}',NOW(),NOW()),
  ('custom','Custom',NULL,NULL,1,0,'{"gst":true,"vat":true,"tds":true,"bill_by_bill":true,"cost_centres":true,"multi_currency":true}',NOW(),NOW())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`price_inr`=VALUES(`price_inr`),`price_npr`=VALUES(`price_npr`),
  `billing_period_months`=VALUES(`billing_period_months`),`is_public`=VALUES(`is_public`),`features`=VALUES(`features`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2) tenants — paid-subscription end date (separate from 14A's trial_ends_at).
--    status also gains the string value 'expired_subscription'.
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `tenants`
  ADD COLUMN `plan_ends_at` TIMESTAMP NULL DEFAULT NULL AFTER `trial_ends_at`;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3) payments — one manual-payment ledger for every tenant.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recorded_by_admin_id` bigint unsigned DEFAULT NULL,
  `notified_by_user_id` bigint unsigned DEFAULT NULL,
  `plan_id` bigint unsigned DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_mode` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `received_at` date NOT NULL,
  `proof_file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `invoice_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invoice_file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reversed_by_admin_id` bigint unsigned DEFAULT NULL,
  `reversed_at` timestamp NULL DEFAULT NULL,
  `reversal_reason` text COLLATE utf8mb4_unicode_ci,
  `rejection_reason` text COLLATE utf8mb4_unicode_ci,
  `subscription_period_start` date DEFAULT NULL,
  `subscription_period_end` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payments_tenant_id_status_index` (`tenant_id`,`status`),
  KEY `payments_status_received_at_index` (`status`,`received_at`),
  CONSTRAINT `payments_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_recorded_by_admin_id_foreign` FOREIGN KEY (`recorded_by_admin_id`) REFERENCES `platform_admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_reversed_by_admin_id_foreign` FOREIGN KEY (`reversed_by_admin_id`) REFERENCES `platform_admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Proof files are stored privately at storage/app/private/payment-proofs/{tenant_id}/…
-- and invoices at storage/app/private/payment-invoices/{tenant_id}/… — never public.
-- ============================================================================
