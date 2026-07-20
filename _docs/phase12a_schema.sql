-- ZeroBook Phase 12A -- multi-company inside a tenant.
--
-- Applies to each TENANT database (tenant<slug>) ONLY. NOTHING central changes
-- (plans/tenants/tenant_users untouched; the tenant's plan gates every company's
-- F11 identically).
--
-- Laravel does all of this automatically:
--     php artisan tenants:migrate
-- This is the phpMyAdmin equivalent. Run it against ONE tenant database at a time.
--
-- WHAT IT DOES -- one tenant DB, N fully isolated companies:
--   1. creates the `companies` registry;
--   2. creates ONE default company and EXPLICITLY assigns every existing row to
--      it (edit the name in section 2 if you want something other than
--      'Default Company' -- Laravel uses the tenant's name);
--   3. adds NOT-NULL company_id (+ FK, cascade) to all 25 operational tables;
--   4. re-keys every natural unique to a composite with company_id -- the same
--      ledger names, voucher numbers, currency codes and filing periods can now
--      exist once PER company;
--   5. moves the identity fields (state/GSTIN/PAN/TAN) from the single-row
--      company_features onto the companies row and drops the old columns;
--      company_features becomes one row per company (unique company_id).
--
-- SAFE ON A LIVE TENANT: after this runs the books are byte-identical -- they are
-- simply owned by one named company. The Trial Balance does not move a paisa.

-- ---------------------------------------------------------------------------
-- 1) The companies registry.
-- ---------------------------------------------------------------------------
CREATE TABLE `companies` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`             VARCHAR(120) NOT NULL,
    `slug`             VARCHAR(60)  NOT NULL,          -- URL-safe short name (the F1 picker shows it)
    `state`            VARCHAR(255) NULL,               -- GST intra/inter (was company_features.company_state)
    `gstin`            VARCHAR(20)  NULL,               -- was company_features.company_gstin
    `pan`              VARCHAR(30)  NULL,               -- Nepal VAT + 26Q deductor PAN (was company_pan)
    `tan`              VARCHAR(10)  NULL,               -- 26Q (was company_tan)
    `base_currency_id` BIGINT UNSIGNED NULL,
    `financial_year_start_month` TINYINT UNSIGNED NOT NULL DEFAULT 4, -- books FY; TDS stays statutory Apr-Mar
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,   -- deactivate, never casually delete
    `created_at`       TIMESTAMP NULL,
    `updated_at`       TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `companies_name_unique` (`name`),
    UNIQUE KEY `companies_slug_unique` (`slug`),
    KEY `companies_base_currency_id_foreign` (`base_currency_id`),
    CONSTRAINT `companies_base_currency_id_foreign`
        FOREIGN KEY (`base_currency_id`) REFERENCES `currencies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2) The default company -- every existing row will belong to it. Identity is
--    copied over from the single company_features row. EDIT THE NAME/SLUG here
--    to match the tenant (Laravel names it after the tenant automatically).
-- ---------------------------------------------------------------------------
INSERT INTO `companies`
    (`name`, `slug`, `state`, `gstin`, `pan`, `tan`, `base_currency_id`,
     `financial_year_start_month`, `is_active`, `created_at`, `updated_at`)
SELECT 'Default Company', 'default',
       cf.`company_state`, cf.`company_gstin`, cf.`company_pan`, cf.`company_tan`,
       (SELECT c.`id` FROM `currencies` c WHERE c.`is_base` = 1 LIMIT 1),
       4, 1, NOW(), NOW()
  FROM (SELECT * FROM `company_features` ORDER BY `id` LIMIT 1) cf
 WHERE NOT EXISTS (SELECT 1 FROM `companies`);

SET @co := (SELECT MIN(`id`) FROM `companies`);

-- The old CompanyFeature::current() had no unique guard; if a race ever left
-- stray rows, keep the oldest so the one-row-per-company unique below holds.
DELETE FROM `company_features`
 WHERE `id` > (SELECT keep_id FROM (SELECT MIN(`id`) AS keep_id FROM `company_features`) k);

-- ---------------------------------------------------------------------------
-- account_groups -- the chart-of-accounts tree; 28 reserved groups PER company
-- ---------------------------------------------------------------------------
ALTER TABLE `account_groups` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `account_groups` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `account_groups` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `account_groups` DROP INDEX `account_groups_name_unique`;
ALTER TABLE `account_groups` ADD UNIQUE KEY `account_groups_company_name_unique` (`company_id`, `name`);
ALTER TABLE `account_groups` ADD CONSTRAINT `account_groups_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- ledgers -- the core master; Cash/P&L/duty/return/forex ledgers seeded per company
-- ---------------------------------------------------------------------------
ALTER TABLE `ledgers` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `ledgers` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `ledgers` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `ledgers` DROP INDEX `ledgers_name_unique`;
ALTER TABLE `ledgers` ADD UNIQUE KEY `ledgers_company_name_unique` (`company_id`, `name`);
ALTER TABLE `ledgers` ADD CONSTRAINT `ledgers_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- cost_centres -- analytical centres
-- ---------------------------------------------------------------------------
ALTER TABLE `cost_centres` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `cost_centres` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `cost_centres` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `cost_centres` DROP INDEX `cost_centres_name_unique`;
ALTER TABLE `cost_centres` ADD UNIQUE KEY `cost_centres_company_name_unique` (`company_id`, `name`);
ALTER TABLE `cost_centres` ADD CONSTRAINT `cost_centres_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- units -- units of measure
-- ---------------------------------------------------------------------------
ALTER TABLE `units` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `units` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `units` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `units` DROP INDEX `units_name_unique`;
ALTER TABLE `units` ADD UNIQUE KEY `units_company_name_unique` (`company_id`, `name`);
ALTER TABLE `units` ADD CONSTRAINT `units_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- stock_groups -- inventory categories
-- ---------------------------------------------------------------------------
ALTER TABLE `stock_groups` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `stock_groups` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `stock_groups` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `stock_groups` DROP INDEX `stock_groups_name_unique`;
ALTER TABLE `stock_groups` ADD UNIQUE KEY `stock_groups_company_name_unique` (`company_id`, `name`);
ALTER TABLE `stock_groups` ADD CONSTRAINT `stock_groups_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- godowns -- locations; each company owns its own reserved 'Main Location'
-- ---------------------------------------------------------------------------
ALTER TABLE `godowns` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `godowns` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `godowns` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `godowns` DROP INDEX `godowns_name_unique`;
ALTER TABLE `godowns` ADD UNIQUE KEY `godowns_company_name_unique` (`company_id`, `name`);
ALTER TABLE `godowns` ADD CONSTRAINT `godowns_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- stock_items -- inventory items
-- ---------------------------------------------------------------------------
ALTER TABLE `stock_items` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `stock_items` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `stock_items` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `stock_items` DROP INDEX `stock_items_name_unique`;
ALTER TABLE `stock_items` ADD UNIQUE KEY `stock_items_company_name_unique` (`company_id`, `name`);
ALTER TABLE `stock_items` ADD CONSTRAINT `stock_items_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- currencies -- each company has its OWN base currency (is_base is per-company)
-- ---------------------------------------------------------------------------
ALTER TABLE `currencies` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `currencies` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `currencies` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `currencies` DROP INDEX `currencies_code_unique`;
ALTER TABLE `currencies` ADD UNIQUE KEY `currencies_company_code_unique` (`company_id`, `code`);
ALTER TABLE `currencies` ADD CONSTRAINT `currencies_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- exchange_rates -- rates hang off per-company currencies; unique(currency_id,date) stays valid
-- ---------------------------------------------------------------------------
ALTER TABLE `exchange_rates` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `exchange_rates` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `exchange_rates` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `exchange_rates` ADD CONSTRAINT `exchange_rates_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- tds_sections -- the statutory rate table is a per-company MASTER (Tally model); 15 rows seeded per company
-- ---------------------------------------------------------------------------
ALTER TABLE `tds_sections` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `tds_sections` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `tds_sections` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `tds_sections` DROP INDEX `tds_sections_code_effective_from_unique`;
ALTER TABLE `tds_sections` ADD UNIQUE KEY `tds_sections_company_code_effective_from_unique` (`company_id`, `code`, `effective_from`);
ALTER TABLE `tds_sections` ADD CONSTRAINT `tds_sections_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- vouchers -- THE numbering re-key: (company_id, type, fy_start, number) = per-company voucher numbering;
--    client_uuid unique becomes (company_id, client_uuid) so the sync dedupe (scoped) and the index agree
-- ---------------------------------------------------------------------------
ALTER TABLE `vouchers` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `vouchers` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `vouchers` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `vouchers` DROP INDEX `vouchers_type_fy_start_number_unique`;
ALTER TABLE `vouchers` ADD UNIQUE KEY `vouchers_company_type_fy_start_number_unique` (`company_id`, `type`, `fy_start`, `number`);
ALTER TABLE `vouchers` DROP INDEX `vouchers_client_uuid_unique`;
ALTER TABLE `vouchers` ADD UNIQUE KEY `vouchers_company_client_uuid_unique` (`company_id`, `client_uuid`);
ALTER TABLE `vouchers` ADD KEY `vouchers_company_scan_index` (`company_id`, `date`);
ALTER TABLE `vouchers` ADD CONSTRAINT `vouchers_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- voucher_entries -- every ledger report aggregates this table directly; (company_id, ledger_id) scan index
-- ---------------------------------------------------------------------------
ALTER TABLE `voucher_entries` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `voucher_entries` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `voucher_entries` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `voucher_entries` ADD KEY `voucher_entries_company_scan_index` (`company_id`, `ledger_id`);
ALTER TABLE `voucher_entries` ADD CONSTRAINT `voucher_entries_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- bill_allocations -- bill-wise references
-- ---------------------------------------------------------------------------
ALTER TABLE `bill_allocations` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `bill_allocations` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `bill_allocations` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `bill_allocations` ADD CONSTRAINT `bill_allocations_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- cost_allocations -- cost-centre allocations
-- ---------------------------------------------------------------------------
ALTER TABLE `cost_allocations` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `cost_allocations` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `cost_allocations` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `cost_allocations` ADD CONSTRAINT `cost_allocations_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- stock_entries -- stock movements; (company_id, stock_item_id) scan index
-- ---------------------------------------------------------------------------
ALTER TABLE `stock_entries` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `stock_entries` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `stock_entries` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `stock_entries` ADD KEY `stock_entries_company_scan_index` (`company_id`, `stock_item_id`);
ALTER TABLE `stock_entries` ADD CONSTRAINT `stock_entries_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- order_lines -- order commitments
-- ---------------------------------------------------------------------------
ALTER TABLE `order_lines` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `order_lines` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `order_lines` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `order_lines` ADD CONSTRAINT `order_lines_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- order_fulfillments -- order fulfilment reconciliation
-- ---------------------------------------------------------------------------
ALTER TABLE `order_fulfillments` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `order_fulfillments` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `order_fulfillments` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `order_fulfillments` ADD CONSTRAINT `order_fulfillments_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- tds_deductions -- TDS audit trail
-- ---------------------------------------------------------------------------
ALTER TABLE `tds_deductions` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `tds_deductions` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `tds_deductions` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `tds_deductions` ADD CONSTRAINT `tds_deductions_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- tds_deductee_ytd -- TDS threshold state (unique already company-safe via the deductee FK)
-- ---------------------------------------------------------------------------
ALTER TABLE `tds_deductee_ytd` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `tds_deductee_ytd` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `tds_deductee_ytd` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `tds_deductee_ytd` ADD CONSTRAINT `tds_deductee_ytd_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- tds_challans -- TDS remittance challans (unique voucher_id stays)
-- ---------------------------------------------------------------------------
ALTER TABLE `tds_challans` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `tds_challans` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `tds_challans` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `tds_challans` ADD CONSTRAINT `tds_challans_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- gst_return_filings -- each company files its own GSTR periods
-- ---------------------------------------------------------------------------
ALTER TABLE `gst_return_filings` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `gst_return_filings` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `gst_return_filings` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `gst_return_filings` DROP INDEX `gst_return_filings_period_return_type_unique`;
ALTER TABLE `gst_return_filings` ADD UNIQUE KEY `gst_return_filings_company_period_return_type_unique` (`company_id`, `period`, `return_type`);
ALTER TABLE `gst_return_filings` ADD CONSTRAINT `gst_return_filings_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- vat_return_filings -- each company files its own VAT periods
-- ---------------------------------------------------------------------------
ALTER TABLE `vat_return_filings` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `vat_return_filings` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `vat_return_filings` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `vat_return_filings` DROP INDEX `vat_return_filings_period_unique`;
ALTER TABLE `vat_return_filings` ADD UNIQUE KEY `vat_return_filings_company_period_unique` (`company_id`, `period`);
ALTER TABLE `vat_return_filings` ADD CONSTRAINT `vat_return_filings_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- tds_return_filings -- each company files its own 26Q quarters
-- ---------------------------------------------------------------------------
ALTER TABLE `tds_return_filings` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `tds_return_filings` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `tds_return_filings` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `tds_return_filings` DROP INDEX `tds_return_filings_fy_start_quarter_unique`;
ALTER TABLE `tds_return_filings` ADD UNIQUE KEY `tds_return_filings_company_fy_start_quarter_unique` (`company_id`, `fy_start`, `quarter`);
ALTER TABLE `tds_return_filings` ADD CONSTRAINT `tds_return_filings_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- company_features -- ONE F11 row PER company (unique company_id); identity columns leave for companies
-- ---------------------------------------------------------------------------
ALTER TABLE `company_features` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `company_features` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `company_features` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `company_features` ADD UNIQUE KEY `company_features_company_id_unique` (`company_id`);
ALTER TABLE `company_features` ADD CONSTRAINT `company_features_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- sync_changes -- the desktop pull change-log, queried RAW by SyncService::pull, so the company
--    filter there needs this column; 'deleted' ops are unresolvable without it
-- ---------------------------------------------------------------------------
ALTER TABLE `sync_changes` ADD COLUMN `company_id` BIGINT UNSIGNED NULL AFTER `id`;
UPDATE `sync_changes` SET `company_id` = @co WHERE `company_id` IS NULL;
ALTER TABLE `sync_changes` MODIFY `company_id` BIGINT UNSIGNED NOT NULL;
ALTER TABLE `sync_changes` ADD KEY `sync_changes_company_scan_index` (`company_id`, `entity`, `id`);
ALTER TABLE `sync_changes` ADD CONSTRAINT `sync_changes_company_id_foreign`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

-- ---------------------------------------------------------------------------
-- Identity leaves company_features (it lives on the companies row now).
-- ---------------------------------------------------------------------------
ALTER TABLE `company_features`
    DROP COLUMN `company_gstin`,
    DROP COLUMN `company_state`,
    DROP COLUMN `company_pan`,
    DROP COLUMN `company_tan`;
