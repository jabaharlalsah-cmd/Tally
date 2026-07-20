-- ZeroBook Phase 9A — GST return filing log (GSTR-1 / GSTR-3B).
-- Applies to each TENANT database (tenant<slug>), NOT the central DB.
-- Laravel does this automatically: `php artisan tenants:migrate` (and every newly
-- provisioned tenant gets it via the migration). This is the phpMyAdmin equivalent —
-- run it against one tenant database at a time.
--
-- Phase 9A adds NO other schema: the GSTR-1/GSTR-3B exporters are pure read-only
-- projections over the existing vouchers / voucher_entries / ledgers / stock_entries /
-- company_features tables. This single table is the manual filing log (ZeroBook does
-- not talk to the GSTN portal, so the ARN is recorded by hand after upload).

CREATE TABLE `gst_return_filings` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `period`      CHAR(6)      NOT NULL,                       -- MMYYYY, e.g. '042026'
    `return_type` ENUM('gstr1','gstr3b') NOT NULL,
    `filed_at`    TIMESTAMP NULL DEFAULT NULL,
    `arn`         VARCHAR(255) NULL DEFAULT NULL,              -- portal Acknowledgement Reference No.
    `notes`       TEXT NULL DEFAULT NULL,
    `created_at`  TIMESTAMP NULL DEFAULT NULL,
    `updated_at`  TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `gst_return_filings_period_return_type_unique` (`period`, `return_type`),
    KEY `gst_return_filings_period_index` (`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The company GSTIN the returns are filed under already exists on `company_features`
-- (`company_gstin`, added in Phase 5B). Party GSTINs already exist on `ledgers.gstin`
-- (Phase 2). Item HSN codes already exist on `stock_items.hsn_sac` (Phase 6A).
-- Nothing else is needed.
