-- ============================================================================
-- ZeroBook — Phase 15A (Budgets) — TENANT schema
-- Per-company budget targets + actual-vs-budget variance. Run against each
-- tenant database (tenant<slug>). Generated from the live migration DDL.
-- Migration: database/migrations/tenant/2026_07_23_000001_add_budgets.php
-- ============================================================================

-- 1. F11 switch (mirrors gst / vat / tds).
ALTER TABLE `company_features` ADD COLUMN `budgets` TINYINT(1) NOT NULL DEFAULT 0 AFTER `multi_currency`;

-- 2-5. Budget tables (in FK-safe create order).
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `budgets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fiscal_year_start` smallint NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `budgets_company_id_fiscal_year_start_index` (`company_id`,`fiscal_year_start`),
  KEY `budgets_company_id_is_primary_index` (`company_id`,`is_primary`),
  CONSTRAINT `budgets_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `budget_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `budget_id` bigint unsigned NOT NULL,
  `ledger_id` bigint unsigned DEFAULT NULL,
  `account_group_id` bigint unsigned DEFAULT NULL,
  `annual_target` decimal(18,2) NOT NULL DEFAULT '0.00',
  `allocation_method` enum('even','custom','seasonal') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'even',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `budget_lines_budget_ledger_unique` (`budget_id`,`ledger_id`),
  UNIQUE KEY `budget_lines_budget_group_unique` (`budget_id`,`account_group_id`),
  KEY `budget_lines_ledger_id_foreign` (`ledger_id`),
  KEY `budget_lines_account_group_id_foreign` (`account_group_id`),
  CONSTRAINT `budget_lines_account_group_id_foreign` FOREIGN KEY (`account_group_id`) REFERENCES `account_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budget_lines_budget_id_foreign` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budget_lines_ledger_id_foreign` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budget_lines_target_xor` CHECK (((`ledger_id` is null) <> (`account_group_id` is null)))
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `budget_line_periods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `budget_line_id` bigint unsigned NOT NULL,
  `month` tinyint unsigned NOT NULL,
  `target_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `revised_from` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `budget_line_periods_budget_line_id_month_index` (`budget_line_id`,`month`),
  CONSTRAINT `budget_line_periods_budget_line_id_foreign` FOREIGN KEY (`budget_line_id`) REFERENCES `budget_lines` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `budget_revisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `budget_id` bigint unsigned NOT NULL,
  `revised_at` date NOT NULL,
  `revised_by_user_id` bigint unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `budget_revisions_budget_id_index` (`budget_id`),
  CONSTRAINT `budget_revisions_budget_id_foreign` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
