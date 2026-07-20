-- ZeroBook Phase 12C-1 -- FIFO lot tracking for INTER-COMPANY stock movements.
--
-- Applies to each TENANT database (tenant<slug>) ONLY. Nothing central changes,
-- and NO existing table is touched -- the lot layer is purely additive.
--
-- Laravel does all of this automatically:
--     php artisan tenants:migrate
-- This is the phpMyAdmin equivalent. Run it against ONE tenant database at a time.
--
-- WHAT IT IS: provenance, never valuation. The 12C-2 consolidation must know how
-- many units on company B's books came from group-internal purchases (and at what
-- cost to the GROUP) versus outside purchases -- information weighted-average
-- pooling erases. One row per inter-company IN stock row (Purchase / Receipt Note
-- / Credit Note / Rejections In from a groupmate party), FIFO-depleted by every
-- OUT movement, split into CHILD rows by godown transfers. The weighted-average
-- engine never reads this table; the Trial Balance does not move a paisa.
-- Ungrouped tenants write no rows and pay no cost.

CREATE TABLE `inter_company_stock_lots` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id`          BIGINT UNSIGNED NOT NULL,      -- the RECEIVING company (12A discipline)
    `stock_item_id`       BIGINT UNSIGNED NOT NULL,
    `godown_id`           BIGINT UNSIGNED NULL,          -- where these units sit now
    `voucher_id`          BIGINT UNSIGNED NOT NULL,      -- the receiving IN voucher
    `stock_entry_id`      BIGINT UNSIGNED NOT NULL,      -- its IN row; CASCADE auto-cleans lots on alter
    `parent_lot_id`       BIGINT UNSIGNED NULL,          -- godown-transfer genealogy (child inherits FIFO position)
    `source_company_id`   BIGINT UNSIGNED NOT NULL,      -- the groupmate that sold us this inventory
    `source_voucher_id`   BIGINT UNSIGNED NULL,          -- best-effort matched counterparty OUT voucher (NULL = unmatched)
    `original_qty`        DECIMAL(15,4) NOT NULL,
    `remaining_qty`       DECIMAL(15,4) NOT NULL,        -- FIFO state; repaired by replay on alter/cancel
    `source_cost_paise`   BIGINT UNSIGNED NULL,          -- the groupmate's per-unit cost x100 (NULL = unmatched)
    `received_rate_paise` BIGINT UNSIGNED NOT NULL,      -- the transfer price we paid per unit x100
    `received_date`       DATE NOT NULL,                 -- the FIFO ordering key (receipt voucher date)
    `created_at`          TIMESTAMP NULL,
    `updated_at`          TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `inter_company_stock_lots_godown_id_foreign` (`godown_id`),
    KEY `inter_company_stock_lots_voucher_id_foreign` (`voucher_id`),
    KEY `inter_company_stock_lots_stock_entry_id_foreign` (`stock_entry_id`),
    KEY `inter_company_stock_lots_parent_lot_id_foreign` (`parent_lot_id`),
    KEY `inter_company_stock_lots_source_company_id_foreign` (`source_company_id`),
    KEY `inter_company_stock_lots_source_voucher_id_foreign` (`source_voucher_id`),
    KEY `ic_lots_item_remaining_index` (`stock_item_id`, `remaining_qty`),
    KEY `ic_lots_fifo_scan_index` (`company_id`, `stock_item_id`, `received_date`),
    CONSTRAINT `inter_company_stock_lots_company_id_foreign`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `inter_company_stock_lots_godown_id_foreign`
        FOREIGN KEY (`godown_id`) REFERENCES `godowns` (`id`) ON DELETE SET NULL,
    CONSTRAINT `inter_company_stock_lots_parent_lot_id_foreign`
        FOREIGN KEY (`parent_lot_id`) REFERENCES `inter_company_stock_lots` (`id`) ON DELETE CASCADE,
    CONSTRAINT `inter_company_stock_lots_source_company_id_foreign`
        FOREIGN KEY (`source_company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `inter_company_stock_lots_source_voucher_id_foreign`
        FOREIGN KEY (`source_voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL,
    CONSTRAINT `inter_company_stock_lots_stock_entry_id_foreign`
        FOREIGN KEY (`stock_entry_id`) REFERENCES `stock_entries` (`id`) ON DELETE CASCADE,
    CONSTRAINT `inter_company_stock_lots_stock_item_id_foreign`
        FOREIGN KEY (`stock_item_id`) REFERENCES `stock_items` (`id`) ON DELETE CASCADE,
    CONSTRAINT `inter_company_stock_lots_voucher_id_foreign`
        FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
