-- ZeroBook Phase 7C — desktop sync infrastructure.
-- Applies to each TENANT database (tenant<slug>), NOT the central DB.
-- Laravel does this automatically: `php artisan tenants:migrate` (and every newly
-- provisioned tenant gets it). This script is the phpMyAdmin equivalent — run it
-- against one tenant database at a time (USE tenantalpha; then run the below).

-- 1) Idempotency key the desktop assigns to each offline voucher (set by the sync
--    layer AFTER VoucherScreen::post(), so post() is unchanged).
ALTER TABLE `vouchers`
    ADD COLUMN `client_uuid` CHAR(36) NULL AFTER `number`,
    ADD UNIQUE INDEX `vouchers_client_uuid_unique` (`client_uuid`);

-- 2) The pull change-log. Every voucher create/update/delete (web SaaS, desktop
--    sync, or the Tally importer) appends a row; its id is the pull cursor.
CREATE TABLE `sync_changes` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `entity`      VARCHAR(40) NOT NULL,
    `record_id`   BIGINT UNSIGNED NOT NULL,
    `op`          ENUM('created','updated','deleted') NOT NULL,
    `occurred_at` TIMESTAMP NOT NULL,
    PRIMARY KEY (`id`),
    INDEX `sync_changes_entity_id_index` (`entity`, `id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
