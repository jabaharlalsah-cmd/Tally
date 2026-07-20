-- ============================================================================
-- ZeroBook — Phase 15B (Ratio Analysis) — TENANT schema
-- Per-company financial-ratio health thresholds. Run against each tenant DB.
-- Migration: database/migrations/tenant/2026_07_24_000001_add_ratio_analysis.php
-- ============================================================================

-- 1. F11 switch (mirrors gst / vat / tds / budgets).
ALTER TABLE `company_features` ADD COLUMN `ratio_analysis` TINYINT(1) NOT NULL DEFAULT 0 AFTER `budgets`;

-- 2. Per-company colour bands.
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ratio_thresholds` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `ratio_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `green_min` decimal(12,4) DEFAULT NULL,
  `amber_min` decimal(12,4) DEFAULT NULL,
  `red_min` decimal(12,4) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ratio_thresholds_company_key_unique` (`company_id`,`ratio_key`),
  CONSTRAINT `ratio_thresholds_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
