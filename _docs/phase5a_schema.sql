-- =====================================================================
-- ZeroBook — Phase 5A schema changes (ready to run in phpMyAdmin)
-- Sales (F8) / Purchase (F9) vouchers + "as Invoice" header metadata.
--
-- Mirrors migration:
--   2026_07_07_000004_add_sales_purchase_and_invoice_meta_to_vouchers.php
--
-- Safe to run once on an existing Phase 4 database. All new columns are
-- NULLABLE, so existing Contra/Payment/Receipt/Journal vouchers are untouched.
-- =====================================================================

-- 1) Widen the voucher type enum to admit Sales and Purchase.
ALTER TABLE `vouchers`
    MODIFY `type` ENUM('contra','payment','receipt','journal','sales','purchase') NOT NULL;

-- 2) Invoice metadata columns on the voucher header.
ALTER TABLE `vouchers`
    ADD COLUMN `party_ledger_id` BIGINT UNSIGNED NULL AFTER `fy_start`,
    ADD COLUMN `reference_no`    VARCHAR(255)    NULL AFTER `party_ledger_id`,
    ADD COLUMN `reference_date`  DATE            NULL AFTER `reference_no`;

-- 3) FK: party ledger points at ledgers; nulled if the ledger is deleted.
--    (MySQL auto-creates the supporting index for this foreign key.)
ALTER TABLE `vouchers`
    ADD CONSTRAINT `vouchers_party_ledger_id_foreign`
        FOREIGN KEY (`party_ledger_id`) REFERENCES `ledgers` (`id`)
        ON DELETE SET NULL;

-- =====================================================================
-- Rollback (if ever needed) — remove any sales/purchase rows first.
-- =====================================================================
-- ALTER TABLE `vouchers` DROP FOREIGN KEY `vouchers_party_ledger_id_foreign`;
-- ALTER TABLE `vouchers`
--     DROP COLUMN `party_ledger_id`,
--     DROP COLUMN `reference_no`,
--     DROP COLUMN `reference_date`;
-- ALTER TABLE `vouchers`
--     MODIFY `type` ENUM('contra','payment','receipt','journal') NOT NULL;
