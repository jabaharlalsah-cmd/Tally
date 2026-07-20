-- ============================================================================
-- ZeroBook — Phase 15C (Scenarios) — TENANT schema
-- Provisional "what-if" voucher layer. Run against EACH tenant DB (phpMyAdmin →
-- pick the tenant<slug> database → SQL tab → paste → Go). Idempotent-safe order.
-- Migration: database/migrations/tenant/2026_07_25_000001_add_scenarios.php
--
-- A voucher is EITHER real (scenario_id NULL) or provisional (tagged to exactly
-- one scenario). Every voucher-reading query defaults to `scenario_id IS NULL`
-- (the real books — byte-identical to pre-15C); a report opts in by selecting
-- scenarios, widening the filter to `scenario_id IS NULL OR IN (...)`. Promotion
-- is a one-way transactional flip that clears scenario_id and stamps
-- promoted_from_scenario_id for the audit trail.
-- ============================================================================

-- 1. F11 switch (mirrors gst / vat / tds / budgets / ratio_analysis).
ALTER TABLE `company_features`
  ADD COLUMN `scenarios` TINYINT(1) NOT NULL DEFAULT 0 AFTER `ratio_analysis`;

-- 2. The named containers — company-scoped (BelongsToCompany), archivable via is_active.
/*!40101 SET @saved_cs_client = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scenarios` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scenarios_company_slug_unique` (`company_id`,`slug`),
  KEY `scenarios_company_id_is_active_index` (`company_id`,`is_active`),
  CONSTRAINT `scenarios_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- 3. Promotion audit log. scenario_id is nullable + ON DELETE SET NULL so the row
--    survives if the (now-empty) scenario is later deleted; the denormalised
--    scenario_name keeps it readable regardless.
/*!40101 SET @saved_cs_client = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scenario_promotions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `scenario_id` bigint unsigned DEFAULT NULL,
  `scenario_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `promoted_by_user_id` bigint unsigned DEFAULT NULL,
  `voucher_count` int unsigned NOT NULL,
  `promoted_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scenario_promotions_scenario_id_foreign` (`scenario_id`),
  CONSTRAINT `scenario_promotions_scenario_id_foreign` FOREIGN KEY (`scenario_id`) REFERENCES `scenarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- 4. The voucher tag + audit trail. scenario_id ON DELETE SET NULL is a safety net
--    only — promotion clears it explicitly first, and deleting a scenario removes
--    its provisional vouchers, so this default rarely fires. The composite index
--    (company_id, scenario_id) serves the filtered report queries.
ALTER TABLE `vouchers`
  ADD COLUMN `scenario_id` bigint unsigned DEFAULT NULL AFTER `company_id`,
  ADD COLUMN `promoted_from_scenario_id` bigint unsigned DEFAULT NULL AFTER `scenario_id`,
  ADD KEY `vouchers_company_scenario_idx` (`company_id`,`scenario_id`),
  ADD CONSTRAINT `vouchers_scenario_id_foreign` FOREIGN KEY (`scenario_id`) REFERENCES `scenarios` (`id`) ON DELETE SET NULL;

-- ============================================================================
-- ROLLBACK (reverse order) — only if you must remove Phase 15C:
--   ALTER TABLE `vouchers` DROP FOREIGN KEY `vouchers_scenario_id_foreign`;
--   ALTER TABLE `vouchers` DROP KEY `vouchers_company_scenario_idx`,
--     DROP COLUMN `promoted_from_scenario_id`, DROP COLUMN `scenario_id`;
--   DROP TABLE `scenario_promotions`;
--   DROP TABLE `scenarios`;
--   ALTER TABLE `company_features` DROP COLUMN `scenarios`;
-- ============================================================================
