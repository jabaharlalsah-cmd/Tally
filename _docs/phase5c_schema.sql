-- =====================================================================
-- ZeroBook — Phase 5C schema (ready to run in phpMyAdmin)
-- Bill-wise details: bill_allocations table.
--
-- Mirrors migration 2026_07_07_000006_create_bill_allocations_table.php.
-- The bill-wise ledger flag (ledgers.maintain_bill_by_bill) already exists
-- from Phase 2, so no ledger change is needed here.
-- =====================================================================

CREATE TABLE `bill_allocations` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `voucher_id`       BIGINT UNSIGNED NOT NULL,
    `ledger_id`        BIGINT UNSIGNED NOT NULL,
    `voucher_entry_id` BIGINT UNSIGNED NULL,
    `ref_type`         ENUM('new','against','advance','onaccount') NOT NULL,
    `ref_name`         VARCHAR(255) NOT NULL,
    `amount`           DECIMAL(18,2) NOT NULL,
    `due_date`         DATE NULL,
    `created_at`       TIMESTAMP NULL,
    `updated_at`       TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `bill_allocations_ledger_id_ref_name_index` (`ledger_id`, `ref_name`),
    KEY `bill_allocations_voucher_id_index` (`voucher_id`),
    KEY `bill_allocations_voucher_entry_id_foreign` (`voucher_entry_id`),
    CONSTRAINT `bill_allocations_voucher_id_foreign`
        FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `bill_allocations_ledger_id_foreign`
        FOREIGN KEY (`ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `bill_allocations_voucher_entry_id_foreign`
        FOREIGN KEY (`voucher_entry_id`) REFERENCES `voucher_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback:
-- DROP TABLE `bill_allocations`;
