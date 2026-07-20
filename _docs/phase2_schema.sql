-- =====================================================================
-- ZeroBook — Phase 2 (Masters) schema + predefined data
-- Ready-to-run in phpMyAdmin: select the `tally` database, then import/run.
-- Recreates account_groups (28 reserved predefined groups) and ledgers
-- (Cash + Profit & Loss A/c). Prefer `php artisan migrate:fresh --seed`.
-- =====================================================================


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `account_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alias` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `nature` enum('Assets','Liabilities','Income','Expenses') COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `is_reserved` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `is_sub_ledger` tinyint(1) NOT NULL DEFAULT '0',
  `nett_balance` tinyint(1) NOT NULL DEFAULT '0',
  `used_for_calculation` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_groups_name_unique` (`name`),
  KEY `account_groups_parent_id_index` (`parent_id`),
  KEY `account_groups_nature_index` (`nature`),
  CONSTRAINT `account_groups_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `account_groups` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `account_groups` DISABLE KEYS */;
INSERT INTO `account_groups` VALUES (1,'Capital Account',NULL,NULL,'Liabilities',1,1,10,0,0,0,'2026-07-06 08:54:36','2026-07-06 08:54:36'),(2,'Current Assets',NULL,NULL,'Assets',1,1,20,0,0,0,'2026-07-06 08:54:36','2026-07-06 08:54:36'),(3,'Current Liabilities',NULL,NULL,'Liabilities',1,1,30,0,0,0,'2026-07-06 08:54:36','2026-07-06 08:54:36'),(4,'Direct Expenses',NULL,NULL,'Expenses',1,1,40,0,0,0,'2026-07-06 08:54:36','2026-07-06 08:54:36'),(5,'Direct Incomes',NULL,NULL,'Income',1,1,50,0,0,0,'2026-07-06 08:54:36','2026-07-06 08:54:36'),(6,'Fixed Assets',NULL,NULL,'Assets',1,1,60,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(7,'Indirect Expenses',NULL,NULL,'Expenses',1,1,70,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(8,'Indirect Incomes',NULL,NULL,'Income',1,1,80,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(9,'Investments',NULL,NULL,'Assets',1,1,90,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(10,'Loans (Liability)',NULL,NULL,'Liabilities',1,1,100,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(11,'Misc. Expenses (ASSET)',NULL,NULL,'Assets',1,1,110,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(12,'Purchase Accounts',NULL,NULL,'Expenses',1,1,120,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(13,'Sales Accounts',NULL,NULL,'Income',1,1,130,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(14,'Suspense A/c',NULL,NULL,'Liabilities',1,1,140,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(15,'Branch / Divisions',NULL,NULL,'Liabilities',1,1,150,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(16,'Bank Accounts',NULL,2,'Assets',0,1,160,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(17,'Cash-in-Hand',NULL,2,'Assets',0,1,170,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(18,'Deposits (Asset)',NULL,2,'Assets',0,1,180,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(19,'Loans & Advances (Asset)',NULL,2,'Assets',0,1,190,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(20,'Stock-in-Hand',NULL,2,'Assets',0,1,200,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(21,'Sundry Debtors',NULL,2,'Assets',0,1,210,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(22,'Duties & Taxes',NULL,3,'Liabilities',0,1,220,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(23,'Provisions',NULL,3,'Liabilities',0,1,230,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(24,'Sundry Creditors',NULL,3,'Liabilities',0,1,240,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(25,'Reserves & Surplus',NULL,1,'Liabilities',0,1,250,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(26,'Bank OD A/c',NULL,10,'Liabilities',0,1,260,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(27,'Secured Loans',NULL,10,'Liabilities',0,1,270,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(28,'Unsecured Loans',NULL,10,'Liabilities',0,1,280,0,0,0,'2026-07-06 08:54:37','2026-07-06 08:54:37');
/*!40000 ALTER TABLE `account_groups` ENABLE KEYS */;
DROP TABLE IF EXISTS `ledgers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ledgers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alias` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `group_id` bigint unsigned DEFAULT NULL,
  `opening_balance` decimal(18,2) NOT NULL DEFAULT '0.00',
  `opening_balance_type` enum('Dr','Cr') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mailing_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `state` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'India',
  `pincode` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gstin` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_reserved` tinyint(1) NOT NULL DEFAULT '0',
  `is_pl_account` tinyint(1) NOT NULL DEFAULT '0',
  `maintain_bill_by_bill` tinyint(1) NOT NULL DEFAULT '0',
  `cost_centres_applicable` tinyint(1) NOT NULL DEFAULT '0',
  `bank_account_no` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_ifsc` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ledgers_name_unique` (`name`),
  KEY `ledgers_group_id_index` (`group_id`),
  CONSTRAINT `ledgers_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `account_groups` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `ledgers` DISABLE KEYS */;
INSERT INTO `ledgers` VALUES (1,'Cash',NULL,17,0.00,NULL,NULL,NULL,NULL,'India',NULL,NULL,NULL,1,0,0,0,NULL,NULL,NULL,'2026-07-06 08:54:37','2026-07-06 08:54:37'),(2,'Profit & Loss A/c',NULL,NULL,0.00,NULL,NULL,NULL,NULL,'India',NULL,NULL,NULL,1,1,0,0,NULL,NULL,NULL,'2026-07-06 08:54:37','2026-07-06 08:54:37');
/*!40000 ALTER TABLE `ledgers` ENABLE KEYS */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

