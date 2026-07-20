-- ============================================================================
-- ZeroBook — Phase 13 (FIFO/LIFO general costing) — TENANT schema
-- Generalizes the 12C-1 lot table to serve regular FIFO/LIFO items too. Run
-- against EACH tenant DB that already has `inter_company_stock_lots` (phpMyAdmin
-- → pick the tenant<slug> database → SQL tab → paste → Go).
-- Migration: database/migrations/tenant/2026_07_26_000001_generalize_stock_lots.php
--
-- A voucher stays real vs provisional (15C); a lot is inter-company (source_* set)
-- or a general FIFO/LIFO tranche (source_* null). costing_method snapshots the
-- lot's depletion order. Existing inter-company rows default to 'fifo' — exactly
-- what 12C-1's fold already implements — so no historical row changes behaviour.
-- ============================================================================

-- 1. Rename the table (the self-referencing parent_lot_id FK follows automatically).
RENAME TABLE `inter_company_stock_lots` TO `stock_lots`;

-- 2. source_company_id becomes NULLABLE (a regular FIFO/LIFO lot has no groupmate
--    source). Drop the FK, relax the column, re-add the FK.
ALTER TABLE `stock_lots`
  DROP FOREIGN KEY `inter_company_stock_lots_source_company_id_foreign`;

ALTER TABLE `stock_lots`
  MODIFY COLUMN `source_company_id` BIGINT UNSIGNED NULL,
  ADD CONSTRAINT `stock_lots_source_company_id_foreign`
    FOREIGN KEY (`source_company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- 3. costing_method snapshot (default 'fifo' → the 12C-1 depletion order).
ALTER TABLE `stock_lots`
  ADD COLUMN `costing_method` ENUM('weighted_average','fifo','lifo')
    NOT NULL DEFAULT 'fifo' AFTER `remaining_qty`;

-- 4. The FIFO/LIFO depletion scan.
ALTER TABLE `stock_lots`
  ADD INDEX `stock_lots_item_method_remaining_index` (`stock_item_id`,`costing_method`,`remaining_qty`);

-- Note: the original 12C-1 indexes/FKs keep their inter_company_stock_lots_* names
-- (MySQL does not rename them on RENAME TABLE) — cosmetic only, fully functional.

-- ============================================================================
-- ROLLBACK (reverse order) — only if you must revert Phase 13:
--   DELETE FROM `stock_lots` WHERE `source_company_id` IS NULL; -- drop regular lots
--   ALTER TABLE `stock_lots` DROP INDEX `stock_lots_item_method_remaining_index`;
--   ALTER TABLE `stock_lots` DROP COLUMN `costing_method`;
--   ALTER TABLE `stock_lots` DROP FOREIGN KEY `stock_lots_source_company_id_foreign`;
--   ALTER TABLE `stock_lots` MODIFY COLUMN `source_company_id` BIGINT UNSIGNED NOT NULL,
--     ADD CONSTRAINT `inter_company_stock_lots_source_company_id_foreign`
--       FOREIGN KEY (`source_company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;
--   RENAME TABLE `stock_lots` TO `inter_company_stock_lots`;
-- ============================================================================
