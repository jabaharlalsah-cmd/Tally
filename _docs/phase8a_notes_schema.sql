-- ZeroBook Phase 8A — Debit & Credit Notes.
-- Applies to each TENANT database (tenant<slug>), NOT the central DB.
-- Laravel does this automatically: `php artisan tenants:migrate` (and every newly
-- provisioned tenant gets it via the migration + ReturnLedgerSeeder). This is the
-- phpMyAdmin equivalent — run it against one tenant database at a time.

-- 1) Admit the two new voucher types (raw ENUM MODIFY — every existing value kept).
ALTER TABLE `vouchers`
    MODIFY `type` ENUM('contra','payment','receipt','journal','sales','purchase','credit_note','debit_note','stock_journal','physical_stock') NOT NULL;

-- 2) The original invoice a Note adjusts (self-referencing, set-null on delete so a
--    return survives the deletion of the invoice it referenced).
ALTER TABLE `vouchers`
    ADD COLUMN `reference_voucher_id` BIGINT UNSIGNED NULL AFTER `reference_date`,
    ADD CONSTRAINT `vouchers_reference_voucher_id_foreign`
        FOREIGN KEY (`reference_voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL;

-- 3) The conventional return nominal ledgers (idempotent — skipped if present).
INSERT INTO `ledgers` (`name`, `group_id`, `country`, `is_reserved`, `is_pl_account`, `created_at`, `updated_at`)
SELECT 'Sales Return', `id`, 'India', 0, 0, NOW(), NOW() FROM `account_groups`
WHERE `name` = 'Sales Accounts'
  AND NOT EXISTS (SELECT 1 FROM `ledgers` WHERE `name` = 'Sales Return');

INSERT INTO `ledgers` (`name`, `group_id`, `country`, `is_reserved`, `is_pl_account`, `created_at`, `updated_at`)
SELECT 'Purchase Return', `id`, 'India', 0, 0, NOW(), NOW() FROM `account_groups`
WHERE `name` = 'Purchase Accounts'
  AND NOT EXISTS (SELECT 1 FROM `ledgers` WHERE `name` = 'Purchase Return');
