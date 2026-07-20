-- ZeroBook — Phase 6C schema (phpMyAdmin / raw SQL)
-- Stock Journal (transfer / consumption) + Physical Stock (stock-take) vouchers.
--
-- The ONLY schema change in 6C: widen the vouchers.type ENUM to admit the two new
-- voucher types. Both post to `stock_entries` only (transfer = 2 rows, consumption
-- and physical stock = 1 row); they write NO `voucher_entries` (no money side), so
-- no other table changes.
--
-- Run AFTER phase6b_schema.sql.

ALTER TABLE `vouchers`
    MODIFY `type` ENUM('contra','payment','receipt','journal','sales','purchase','stock_journal','physical_stock') NOT NULL;

-- Tag each stock movement with its kind so the item-level valuation fold can treat
-- an inter-godown TRANSFER as value-neutral (skip it), leaving the item total and
-- weighted-average unchanged no matter how the average later shifts. godown-level
-- quantity still counts transfer rows. (sale | purchase | transfer | consumption |
-- physical | NULL)
ALTER TABLE `stock_entries`
    ADD COLUMN `movement_type` VARCHAR(20) NULL AFTER `direction`;

-- Rollback (remove any stock_journal/physical_stock rows first):
-- ALTER TABLE `stock_entries` DROP COLUMN `movement_type`;
-- ALTER TABLE `vouchers`
--     MODIFY `type` ENUM('contra','payment','receipt','journal','sales','purchase') NOT NULL;
