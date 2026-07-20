-- =====================================================================
-- ZeroBook — NAS parity, Phase 3: Active / Inactive on every master
-- TENANT database. Run once per tenant database (tenant<slug>).
-- phpMyAdmin-ready. Matches migration:
--   database/migrations/tenant/2026_07_30_000003_add_is_active_to_masters.php
--
-- WHY
-- The Dibi Tech master-data standard needs each master dropdown to carry a
-- gear icon (Create / Edit / Active-Inactive / Delete-disabled-when-in-use)
-- and to list ACTIVE items only. Of the nine master tables, only `companies`
-- had an active flag, so none of that was buildable. It also matches
-- TallyPrime, where a master that is no longer used is retired rather than
-- deleted — deleting one with history would orphan its vouchers.
--
-- DEFAULT IS 1, AND THAT IS THE SAFE DIRECTION.
-- Opposite to the `inventory` feature flag: there OFF was right for new
-- companies and existing users were backfilled ON. Here every existing master
-- IS in use and must stay selectable, so the column defaults to 1 and existing
-- rows inherit it. No backfill needed, and no dropdown silently empties the
-- moment this runs.
--
-- NOTE: `companies` is deliberately absent — it has had is_active since the
-- multi-company phase.
-- =====================================================================

ALTER TABLE `account_groups` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    ADD INDEX `account_groups_company_active_idx` (`company_id`, `is_active`);

ALTER TABLE `ledgers` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    ADD INDEX `ledgers_company_active_idx` (`company_id`, `is_active`);

ALTER TABLE `units` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    ADD INDEX `units_company_active_idx` (`company_id`, `is_active`);

ALTER TABLE `godowns` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    ADD INDEX `godowns_company_active_idx` (`company_id`, `is_active`);

ALTER TABLE `stock_groups` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    ADD INDEX `stock_groups_company_active_idx` (`company_id`, `is_active`);

ALTER TABLE `stock_items` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    ADD INDEX `stock_items_company_active_idx` (`company_id`, `is_active`);

ALTER TABLE `cost_centres` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    ADD INDEX `cost_centres_company_active_idx` (`company_id`, `is_active`);

ALTER TABLE `currencies` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    ADD INDEX `currencies_company_active_idx` (`company_id`, `is_active`);

ALTER TABLE `tds_sections` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    ADD INDEX `tds_sections_company_active_idx` (`company_id`, `is_active`);

-- Verification — every master should report 0 inactive immediately after running.
-- SELECT 'account_groups' t, COUNT(*) rows_total, SUM(is_active = 0) inactive FROM account_groups
-- UNION ALL SELECT 'ledgers', COUNT(*), SUM(is_active = 0) FROM ledgers
-- UNION ALL SELECT 'units', COUNT(*), SUM(is_active = 0) FROM units
-- UNION ALL SELECT 'godowns', COUNT(*), SUM(is_active = 0) FROM godowns
-- UNION ALL SELECT 'stock_groups', COUNT(*), SUM(is_active = 0) FROM stock_groups
-- UNION ALL SELECT 'stock_items', COUNT(*), SUM(is_active = 0) FROM stock_items
-- UNION ALL SELECT 'cost_centres', COUNT(*), SUM(is_active = 0) FROM cost_centres
-- UNION ALL SELECT 'currencies', COUNT(*), SUM(is_active = 0) FROM currencies
-- UNION ALL SELECT 'tds_sections', COUNT(*), SUM(is_active = 0) FROM tds_sections;

-- Rollback (repeat per table):
-- ALTER TABLE `account_groups` DROP INDEX `account_groups_company_active_idx`, DROP COLUMN `is_active`;
