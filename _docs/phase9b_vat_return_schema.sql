-- ZeroBook Phase 9B — Nepal VAT return filing log (अनुसूची-१० / Schedule 10).
-- Applies to each TENANT database (tenant<slug>), NOT the central DB.
-- Laravel does this automatically: `php artisan tenants:migrate` (and every newly
-- provisioned tenant gets it via the migration). This is the phpMyAdmin equivalent —
-- run it against one tenant database at a time.
--
-- Phase 9B adds NO other schema: the VAT return exporter is a pure read-only projection
-- over the existing vouchers / voucher_entries / ledgers / company_features tables.
-- This single table is the manual filing log (ZeroBook does not talk to the IRD taxpayer
-- portal — the VAT return is a web form with no public submission API), so the taxpayer
-- records the portal's submission reference by hand after filing.

CREATE TABLE `vat_return_filings` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `period`         CHAR(7)      NOT NULL,               -- BS YYYY-MM, e.g. '2082-04' = Shrawan 2082
    `submission_ref` VARCHAR(255) NULL DEFAULT NULL,      -- IRD taxpayer-portal submission reference
    `filed_at`       TIMESTAMP NULL DEFAULT NULL,
    `notes`          TEXT NULL DEFAULT NULL,
    `created_at`     TIMESTAMP NULL DEFAULT NULL,
    `updated_at`     TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `vat_return_filings_period_unique` (`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The taxpayer PAN the return is filed under already exists on `company_features`
-- (`company_pan`, added in Phase 5E — in Nepal the PAN is the VAT registration number).
-- The VAT duty ledgers (Output VAT / Input VAT, tax_type='vat') are seeded by
-- TaxLedgerSeeder. Nothing else is needed.
