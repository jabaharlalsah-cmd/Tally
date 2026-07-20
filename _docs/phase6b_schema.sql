-- ZeroBook — Phase 6B schema (phpMyAdmin / raw SQL)
-- Item-Invoice Mode + Stock Posting + Weighted-Average COGS.
--
-- The ONLY schema change in 6B: the selling side of a stock movement.
-- On an OUT (sale) row, `rate`/`value` hold the COST (weighted-average, computed
-- by the server at post time) and `sale_rate`/`sale_value` hold the SELLING price
-- the user entered. On an IN (purchase) row the entered rate IS the cost, so it
-- lives in `rate`/`value` and these two columns stay NULL.
--
-- Run AFTER phase6a_schema.sql (which creates stock_entries).

ALTER TABLE `stock_entries`
    ADD COLUMN `sale_rate`  DECIMAL(15,4) NULL AFTER `value`,
    ADD COLUMN `sale_value` DECIMAL(15,2) NULL AFTER `sale_rate`;

-- Rollback:
-- ALTER TABLE `stock_entries` DROP COLUMN `sale_value`, DROP COLUMN `sale_rate`;
