-- =====================================================================
-- ZeroBook — Phase 5E schema (ready to run in phpMyAdmin)
-- Nepal VAT: a second, mutually-exclusive tax regime alongside GST.
--
-- Mirrors migration 2026_07_07_000009_add_nepal_vat_regime.php + the two
-- extra rows in TaxLedgerSeeder. The rate/HS-code (gst_rate/hsn_sac) and party
-- gstin columns are REUSED for VAT (relabelled in the UI) — never duplicated.
-- =====================================================================

-- 1) Widen the ledger tax_type enum to admit 'vat'.
ALTER TABLE `ledgers`
    MODIFY `tax_type` ENUM('central','state','integrated','vat') NULL;

-- 2) Company VAT profile on company_features (vat is mutually exclusive with gst,
--    enforced in the app; company_pan is the VAT analogue of company_gstin).
ALTER TABLE `company_features`
    ADD COLUMN `vat`         TINYINT(1)   NOT NULL DEFAULT 0 AFTER `gst`,
    ADD COLUMN `company_pan` VARCHAR(255) NULL              AFTER `company_state`;

-- 3) The two reserved Nepal VAT duty ledgers under "Duties & Taxes".
INSERT IGNORE INTO `ledgers`
    (`name`, `group_id`, `opening_balance`, `is_reserved`, `is_pl_account`,
     `tax_role`, `tax_type`, `country`, `created_at`, `updated_at`)
SELECT v.name, g.id, 0, 1, 0, v.role, 'vat', 'India', NOW(), NOW()
FROM (
    SELECT 'Output VAT' AS name, 'output' AS role UNION ALL
    SELECT 'Input VAT',          'input'
) AS v
CROSS JOIN (SELECT id FROM `account_groups` WHERE name = 'Duties & Taxes' LIMIT 1) AS g;

-- =====================================================================
-- Rollback
-- =====================================================================
-- DELETE FROM `ledgers` WHERE `name` IN ('Output VAT','Input VAT');
-- ALTER TABLE `company_features` DROP COLUMN `vat`, DROP COLUMN `company_pan`;
-- UPDATE `ledgers` SET `tax_type`=NULL,`tax_role`=NULL WHERE `tax_type`='vat';
-- ALTER TABLE `ledgers` MODIFY `tax_type` ENUM('central','state','integrated') NULL;
