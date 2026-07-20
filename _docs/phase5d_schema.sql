-- =====================================================================
-- ZeroBook — Phase 5D schema (ready to run in phpMyAdmin)
-- Cost centres: cost_centres + cost_allocations.
--
-- Mirrors migrations 2026_07_07_000007 / _000008. The cost-applicable ledger
-- flag (ledgers.cost_centres_applicable) already exists from Phase 2.
-- =====================================================================

CREATE TABLE `cost_centres` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(255) NOT NULL,
    `parent_id`  BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `cost_centres_name_unique` (`name`),
    KEY `cost_centres_parent_id_index` (`parent_id`),
    CONSTRAINT `cost_centres_parent_id_foreign`
        FOREIGN KEY (`parent_id`) REFERENCES `cost_centres` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cost_allocations` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `voucher_id`       BIGINT UNSIGNED NOT NULL,
    `ledger_id`        BIGINT UNSIGNED NOT NULL,
    `voucher_entry_id` BIGINT UNSIGNED NULL,
    `cost_centre_id`   BIGINT UNSIGNED NOT NULL,
    `amount`           DECIMAL(18,2) NOT NULL,
    `created_at`       TIMESTAMP NULL,
    `updated_at`       TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `cost_allocations_cost_centre_id_index` (`cost_centre_id`),
    KEY `cost_allocations_voucher_id_index` (`voucher_id`),
    KEY `cost_allocations_ledger_id_foreign` (`ledger_id`),
    KEY `cost_allocations_voucher_entry_id_foreign` (`voucher_entry_id`),
    CONSTRAINT `cost_allocations_voucher_id_foreign`
        FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `cost_allocations_ledger_id_foreign`
        FOREIGN KEY (`ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `cost_allocations_voucher_entry_id_foreign`
        FOREIGN KEY (`voucher_entry_id`) REFERENCES `voucher_entries` (`id`) ON DELETE SET NULL,
    CONSTRAINT `cost_allocations_cost_centre_id_foreign`
        FOREIGN KEY (`cost_centre_id`) REFERENCES `cost_centres` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback:
-- DROP TABLE `cost_allocations`;
-- DROP TABLE `cost_centres`;
