-- ZeroBook Phase 10B — Form 26Q quarterly return-file exporter.
--
-- Applies to each TENANT database (tenant<slug>), NOT the central DB. Laravel does this
-- automatically with `php artisan tenants:migrate`; this is the phpMyAdmin equivalent —
-- run it against ONE tenant database at a time.
--
-- 10B is READ-ONLY over the Phase 10A deduction engine: it adds the challan (remittance)
-- identifiers and the deductor's filing identity, then exports. It never alters
-- tds_sections / tds_deductions / tds_deductee_ytd. Nothing here changes a balance.

-- ---------------------------------------------------------------------------
-- 1) tds_challans — the real bank/book identifiers for each remittance.
--
--    In 10A, remitting TDS is an ordinary Payment (Dr TDS Payable / Cr Bank); the
--    deduction→remittance mapping is FIFO. A real 26Q return additionally needs, per
--    remittance, the BSR code of the receiving bank branch, the 5-digit challan serial
--    from the bank stamp, and the actual deposit date. One challan per remittance voucher.
-- ---------------------------------------------------------------------------
CREATE TABLE `tds_challans` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `voucher_id`     BIGINT UNSIGNED NOT NULL,           -- the remittance Payment
    `bsr_code`       VARCHAR(7)  NULL,                   -- 7-digit BSR (CD field 15)
    `challan_number` VARCHAR(5)  NULL,                   -- 5-digit challan serial (CD field 17)
    `deposit_date`   DATE NULL,                          -- actual bank deposit date (CD field 19)
    `total_amount`   DECIMAL(18,2) NOT NULL DEFAULT 0,   -- amount deposited (whole rupees)
    `minor_head`     VARCHAR(3) NOT NULL DEFAULT '200',  -- Annexure 7 (200 = TDS payable)
    `created_at`     TIMESTAMP NULL,
    `updated_at`     TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tds_challans_voucher_id_unique` (`voucher_id`),
    CONSTRAINT `tds_challans_voucher_id_foreign`
        FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2) company_features — the deductor's Form 26Q filing identity.
--
--    A 26Q Batch Header is mandatory-heavy: TAN, deductor name/address/state/PIN/email/
--    phone/category, and a full "responsible person" block. None of this existed before.
--    The exporter REFUSES to run until it is set, rather than emit placeholders the FVU
--    would reject. The company PAN (BH field 15) reuses the existing company_pan column.
-- ---------------------------------------------------------------------------
ALTER TABLE `company_features`
    ADD COLUMN `company_tan`          VARCHAR(10) NULL AFTER `company_pan`,
    ADD COLUMN `deductor_name`        VARCHAR(75) NULL AFTER `company_tan`,
    ADD COLUMN `deductor_address1`    VARCHAR(25) NULL AFTER `deductor_name`,
    ADD COLUMN `deductor_address2`    VARCHAR(25) NULL AFTER `deductor_address1`,
    ADD COLUMN `deductor_state_code`  VARCHAR(2)  NULL AFTER `deductor_address2`,
    ADD COLUMN `deductor_pincode`     VARCHAR(6)  NULL AFTER `deductor_state_code`,
    ADD COLUMN `deductor_email`       VARCHAR(75) NULL AFTER `deductor_pincode`,
    ADD COLUMN `deductor_phone`       VARCHAR(10) NULL AFTER `deductor_email`,
    ADD COLUMN `deductor_type`        VARCHAR(1)  NULL AFTER `deductor_phone`,   -- Annexure 4 code
    ADD COLUMN `resp_name`            VARCHAR(75) NULL AFTER `deductor_type`,
    ADD COLUMN `resp_designation`     VARCHAR(20) NULL AFTER `resp_name`,
    ADD COLUMN `resp_pan`             VARCHAR(10) NULL AFTER `resp_designation`,
    ADD COLUMN `resp_address1`        VARCHAR(25) NULL AFTER `resp_pan`,
    ADD COLUMN `resp_state_code`      VARCHAR(2)  NULL AFTER `resp_address1`,
    ADD COLUMN `resp_pincode`         VARCHAR(6)  NULL AFTER `resp_state_code`,
    ADD COLUMN `resp_email`           VARCHAR(75) NULL AFTER `resp_pincode`,
    ADD COLUMN `resp_phone`           VARCHAR(10) NULL AFTER `resp_email`;

-- ---------------------------------------------------------------------------
-- 3) tds_return_filings — the post-filing acknowledgement log.
--
--    Not auto-populated. After the return is uploaded and accepted, the CA records the
--    token / provisional receipt here, so the 26Q Returns screen shows each quarter's
--    status (draft → ready → filed with token). One row per (fiscal year, quarter).
-- ---------------------------------------------------------------------------
CREATE TABLE `tds_return_filings` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `fy_start`   SMALLINT NOT NULL,                 -- fiscal-year START, e.g. 2026 = FY 2026-27
    `quarter`    TINYINT UNSIGNED NOT NULL,         -- 1-4
    `filed_at`   TIMESTAMP NULL,
    `token_no`   VARCHAR(15) NULL,                  -- acknowledgement / provisional receipt number
    `receipt_no` VARCHAR(30) NULL,
    `notes`      TEXT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tds_return_filings_fy_start_quarter_unique` (`fy_start`, `quarter`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
