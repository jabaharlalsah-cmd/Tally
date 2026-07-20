-- =====================================================================
-- ZeroBook — Phase 6A schema (ready to run in phpMyAdmin)
-- Inventory masters: units, stock_groups, godowns, stock_items + the
-- forward-compatible stock_entries ledger (nothing writes to it until 6B).
-- Mirrors migrations 2026_07_08_000001..000005 and seeds "Main Location".
-- =====================================================================

CREATE TABLE `units` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`           VARCHAR(255) NOT NULL,
    `symbol`         VARCHAR(255) NULL,
    `decimal_places` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`     TIMESTAMP NULL,
    `updated_at`     TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `units_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stock_groups` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(255) NOT NULL,
    `alias`      VARCHAR(255) NULL,
    `parent_id`  BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `stock_groups_name_unique` (`name`),
    KEY `stock_groups_parent_id_index` (`parent_id`),
    CONSTRAINT `stock_groups_parent_id_foreign`
        FOREIGN KEY (`parent_id`) REFERENCES `stock_groups` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `godowns` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(255) NOT NULL,
    `parent_id`   BIGINT UNSIGNED NULL,
    `is_reserved` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP NULL,
    `updated_at`  TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `godowns_name_unique` (`name`),
    KEY `godowns_parent_id_index` (`parent_id`),
    CONSTRAINT `godowns_parent_id_foreign`
        FOREIGN KEY (`parent_id`) REFERENCES `godowns` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `godowns` (`name`, `parent_id`, `is_reserved`, `created_at`, `updated_at`)
VALUES ('Main Location', NULL, 1, NOW(), NOW());

CREATE TABLE `stock_items` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(255) NOT NULL,
    `alias`             VARCHAR(255) NULL,
    `stock_group_id`    BIGINT UNSIGNED NULL,
    `unit_id`           BIGINT UNSIGNED NULL,
    `opening_qty`       DECIMAL(15,4) NOT NULL DEFAULT 0,
    `opening_rate`      DECIMAL(15,4) NOT NULL DEFAULT 0,
    `opening_value`     DECIMAL(15,2) NOT NULL DEFAULT 0,
    `opening_godown_id` BIGINT UNSIGNED NULL,
    `gst_rate`          DECIMAL(5,2) NULL,
    `hsn_sac`           VARCHAR(255) NULL,
    `costing_method`    VARCHAR(255) NOT NULL DEFAULT 'weighted_average',
    `reorder_level`     DECIMAL(15,4) NULL,
    `created_at`        TIMESTAMP NULL,
    `updated_at`        TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `stock_items_name_unique` (`name`),
    KEY `stock_items_stock_group_id_index` (`stock_group_id`),
    CONSTRAINT `stock_items_stock_group_id_foreign` FOREIGN KEY (`stock_group_id`) REFERENCES `stock_groups` (`id`) ON DELETE SET NULL,
    CONSTRAINT `stock_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL,
    CONSTRAINT `stock_items_opening_godown_id_foreign` FOREIGN KEY (`opening_godown_id`) REFERENCES `godowns` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Forward-compatible stock ledger — nothing writes to it until Phase 6B.
CREATE TABLE `stock_entries` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `voucher_id`    BIGINT UNSIGNED NOT NULL,
    `stock_item_id` BIGINT UNSIGNED NOT NULL,
    `godown_id`     BIGINT UNSIGNED NULL,
    `direction`     ENUM('in','out') NOT NULL,
    `quantity`      DECIMAL(15,4) NOT NULL,
    `rate`          DECIMAL(15,4) NOT NULL,
    `value`         DECIMAL(15,2) NOT NULL,
    `line_no`       INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at`    TIMESTAMP NULL,
    `updated_at`    TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `stock_entries_stock_item_id_index` (`stock_item_id`),
    KEY `stock_entries_voucher_id_index` (`voucher_id`),
    KEY `stock_entries_godown_id_foreign` (`godown_id`),
    CONSTRAINT `stock_entries_voucher_id_foreign` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `stock_entries_stock_item_id_foreign` FOREIGN KEY (`stock_item_id`) REFERENCES `stock_items` (`id`) ON DELETE CASCADE,
    CONSTRAINT `stock_entries_godown_id_foreign` FOREIGN KEY (`godown_id`) REFERENCES `godowns` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback (child → parent order):
-- DROP TABLE `stock_entries`;
-- DROP TABLE `stock_items`;
-- DROP TABLE `godowns`;
-- DROP TABLE `stock_groups`;
-- DROP TABLE `units`;
