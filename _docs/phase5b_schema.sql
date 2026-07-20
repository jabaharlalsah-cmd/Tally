-- =====================================================================
-- ZeroBook — Phase 5B schema changes (ready to run in phpMyAdmin)
-- GST: company profile + ledger tax fields + the six duty ledgers.
--
-- Mirrors migration 2026_07_07_000005_add_gst_to_company_and_ledgers.php
-- and seeder TaxLedgerSeeder.php. All new columns are NULLABLE and GST
-- behaviour is gated by the F11 `gst` flag, so nothing before GST is affected.
-- =====================================================================

-- 1) Company GST profile (single-company row on company_features).
ALTER TABLE `company_features`
    ADD COLUMN `company_gstin` VARCHAR(255) NULL AFTER `multi_currency`,
    ADD COLUMN `company_state` VARCHAR(255) NULL AFTER `company_gstin`;

UPDATE `company_features` SET `company_state` = 'Maharashtra' WHERE `company_state` IS NULL;

-- 2) Ledger GST columns.
--    tax_type / tax_role  → set on the six GST duty ledgers (below)
--    gst_rate / hsn_sac   → set on Sales/Purchase nominal ledgers
--    gst_registration_type→ set on party ledgers (gstin + state already exist)
ALTER TABLE `ledgers`
    ADD COLUMN `tax_type` ENUM('central','state','integrated') NULL AFTER `gstin`,
    ADD COLUMN `tax_role` ENUM('output','input') NULL AFTER `tax_type`,
    ADD COLUMN `gst_rate` DECIMAL(5,2) NULL AFTER `tax_role`,
    ADD COLUMN `hsn_sac` VARCHAR(255) NULL AFTER `gst_rate`,
    ADD COLUMN `gst_registration_type` VARCHAR(255) NULL AFTER `hsn_sac`;

-- 3) The six reserved GST duty ledgers under "Duties & Taxes".
--    (Uses a sub-select for the group id; safe to re-run — INSERT IGNORE on name.)
INSERT IGNORE INTO `ledgers`
    (`name`, `group_id`, `opening_balance`, `is_reserved`, `is_pl_account`,
     `tax_role`, `tax_type`, `country`, `created_at`, `updated_at`)
SELECT v.name, g.id, 0, 1, 0, v.role, v.type, 'India', NOW(), NOW()
FROM (
    SELECT 'Output CGST' AS name, 'output' AS role, 'central'    AS type UNION ALL
    SELECT 'Output SGST',         'output',        'state'                UNION ALL
    SELECT 'Output IGST',         'output',        'integrated'           UNION ALL
    SELECT 'Input CGST',          'input',         'central'              UNION ALL
    SELECT 'Input SGST',          'input',         'state'                UNION ALL
    SELECT 'Input IGST',          'input',         'integrated'
) AS v
CROSS JOIN (SELECT id FROM `account_groups` WHERE name = 'Duties & Taxes' LIMIT 1) AS g;

-- =====================================================================
-- Rollback
-- =====================================================================
-- DELETE FROM `ledgers` WHERE `name` IN
--   ('Output CGST','Output SGST','Output IGST','Input CGST','Input SGST','Input IGST');
-- ALTER TABLE `ledgers`
--   DROP COLUMN `tax_type`, DROP COLUMN `tax_role`, DROP COLUMN `gst_rate`,
--   DROP COLUMN `hsn_sac`, DROP COLUMN `gst_registration_type`;
-- ALTER TABLE `company_features`
--   DROP COLUMN `company_gstin`, DROP COLUMN `company_state`;
