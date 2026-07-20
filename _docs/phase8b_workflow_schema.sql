-- ZeroBook Phase 8B — Inventory-workflow vouchers (Orders, Delivery/Receipt Notes,
-- Rejections) + order reconciliation tables.
-- Applies to each TENANT database (tenant<slug>), NOT the central DB.
-- Laravel does this automatically: `php artisan tenants:migrate` (and every newly
-- provisioned tenant gets it via the migration). This is the phpMyAdmin equivalent —
-- run it against one tenant database at a time. NONE of this touches accounting:
-- these six voucher types post no voucher_entries, so the Trial Balance is untouched.

-- 1) Admit the six new voucher types (raw ENUM MODIFY — every existing value kept).
ALTER TABLE `vouchers`
    MODIFY `type` ENUM(
        'contra','payment','receipt','journal','sales','purchase','credit_note','debit_note',
        'sales_order','purchase_order','delivery_note','receipt_note','rejection_out','rejection_in',
        'stock_journal','physical_stock'
    ) NOT NULL;

-- 2) order_lines — one outstanding commitment per item on a Sales/Purchase Order.
--    delivered_qty is a maintained cache of the sum of its fulfillments (= ordered − pending).
CREATE TABLE `order_lines` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `voucher_id`    BIGINT UNSIGNED NOT NULL,
    `stock_item_id` BIGINT UNSIGNED NOT NULL,
    `godown_id`     BIGINT UNSIGNED NULL,
    `ordered_qty`   DECIMAL(15,4) NOT NULL,
    `delivered_qty` DECIMAL(15,4) NOT NULL DEFAULT 0,   -- = Σ fulfillments (maintained)
    `rate`          DECIMAL(15,4) NOT NULL DEFAULT 0,
    `amount`        DECIMAL(15,2) NOT NULL DEFAULT 0,
    `line_no`       INT UNSIGNED  NOT NULL DEFAULT 1,
    `created_at`    TIMESTAMP NULL,
    `updated_at`    TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `order_lines_voucher_id_index` (`voucher_id`),
    KEY `order_lines_stock_item_id_index` (`stock_item_id`),
    CONSTRAINT `order_lines_voucher_id_foreign`
        FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `order_lines_stock_item_id_foreign`
        FOREIGN KEY (`stock_item_id`) REFERENCES `stock_items` (`id`) ON DELETE CASCADE,
    CONSTRAINT `order_lines_godown_id_foreign`
        FOREIGN KEY (`godown_id`) REFERENCES `godowns` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) order_fulfillments — the audit trail: each Delivery/Receipt Note that fulfils
--    (part of) an order line adds a row, so delivered_qty is always re-derivable and
--    reversal on alter/cancel is deterministic (delete the rows, re-sum).
CREATE TABLE `order_fulfillments` (
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_line_id`          BIGINT UNSIGNED NOT NULL,
    `fulfillment_voucher_id` BIGINT UNSIGNED NOT NULL,
    `qty`                    DECIMAL(15,4) NOT NULL,
    `line_no`                INT UNSIGNED  NOT NULL DEFAULT 1,
    `created_at`             TIMESTAMP NULL,
    `updated_at`             TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `order_fulfillments_order_line_id_index` (`order_line_id`),
    KEY `order_fulfillments_fulfillment_voucher_id_index` (`fulfillment_voucher_id`),
    CONSTRAINT `order_fulfillments_order_line_id_foreign`
        FOREIGN KEY (`order_line_id`) REFERENCES `order_lines` (`id`) ON DELETE CASCADE,
    CONSTRAINT `order_fulfillments_fulfillment_voucher_id_foreign`
        FOREIGN KEY (`fulfillment_voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: reference_voucher_id already exists on `vouchers` (added in Phase 8A). Phase 8B
-- reuses it to chain Delivery/Receipt Note → Order and Sales/Purchase invoice →
-- Delivery/Receipt Note (the double-stock safeguard). No column change needed here.
