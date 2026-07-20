-- ZeroBook Phase 10A — the TDS deduction engine.
--
-- Applies to each TENANT database (tenant<slug>), NOT the central DB — except section (7)
-- at the bottom, which patches the CENTRAL `plans` table.
--
-- Laravel does all of this automatically:
--     php artisan migrate                 (central: plans.features gains "tds")
--     php artisan tenants:migrate         (each tenant: everything below)
-- This is the phpMyAdmin equivalent. Run sections 1–6 against ONE tenant database at a
-- time, and section 7 against the central database once.
--
-- Nothing here changes an existing balance. The new TDS Payable ledger opens at zero, so
-- the Trial Balance is byte-identical until the first TDS-deducting Payment is posted.

-- ---------------------------------------------------------------------------
-- 1) The F11 switch. TDS is orthogonal to the GST/VAT regime choice: an Indian
--    company deducts tax at source whether or not it is GST-registered.
-- ---------------------------------------------------------------------------
ALTER TABLE `company_features`
    ADD COLUMN `tds` TINYINT(1) NOT NULL DEFAULT 0 AFTER `vat`;

-- ---------------------------------------------------------------------------
-- 2) tds_sections — THE RATE TABLE.
--
--    Rates and thresholds are DATA, never code, so next year's Finance Act is a row edit
--    rather than a deploy. Sections are effective-dated by FISCAL-YEAR START (the same
--    integer `vouchers.fy_start` carries: 2026 => FY 2026-27), because on 1 April 2026 the
--    Income Tax Act 2025 replaced the old 194-series with Section 393 sub-provisions.
--    A live book contains both, so the catalog holds both and the VOUCHER's own fiscal
--    year decides which is legal.
--
--    UNIQUE(code, effective_from) lets the SAME code be re-dated when only its threshold
--    changed — 194I-B went from an annual 2,40,000 to a per-month 50,000 in FY 2025-26.
--
--    threshold_period : the window `threshold_annual` aggregates over (194I is monthly).
--    deduct_basis     : once crossed, tax the whole aggregate — or, for 194Q, only the
--                       value in EXCESS of the threshold.
--    no_pan_rate      : the Section 206AA floor. NULL means the statutory 20%; the proviso
--                       to 206AA(1) caps it at 5% for 194Q, so that exception is data too.
-- ---------------------------------------------------------------------------
CREATE TABLE `tds_sections` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`             VARCHAR(30)  NOT NULL,          -- '194J' | '393-194J'
    `label`            VARCHAR(191) NOT NULL,
    `rate`             DECIMAL(5,2) NOT NULL,          -- individuals / HUF, and everyone when rate_company IS NULL
    `rate_company`     DECIMAL(5,2) NULL,              -- 194C: 1% individual, 2% company
    `no_pan_rate`      DECIMAL(5,2) NULL,              -- Section 206AA floor; NULL => 20
    `threshold_single` DECIMAL(15,2) NULL,             -- single-transaction threshold (194C: 30,000)
    `threshold_annual` DECIMAL(15,2) NULL,             -- aggregate threshold over `threshold_period`
    `threshold_period` ENUM('annual','monthly') NOT NULL DEFAULT 'annual',
    `deduct_basis`     ENUM('aggregate','excess') NOT NULL DEFAULT 'aggregate',
    `effective_from`   SMALLINT NOT NULL,              -- FY start year, e.g. 2026 = FY 2026-27
    `effective_to`     SMALLINT NULL,                  -- NULL = still in force
    `notes`            TEXT NULL,
    `created_at`       TIMESTAMP NULL,
    `updated_at`       TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tds_sections_code_effective_from_unique` (`code`, `effective_from`),
    KEY `tds_sections_effective_from_effective_to_index` (`effective_from`, `effective_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3) tds_deductee_ytd — the running fiscal-year state per (deductee, SECTION).
--
--    This is what makes the threshold decision possible. TDS law does not ask "is this
--    payment large?" — it asks "has the year's aggregate to this vendor UNDER THIS SECTION
--    crossed the line yet?". The same vendor may be paid professional fees (194J) and
--    separately under a contract (194C), and neither aggregate touches the other.
--
--    Maintained inside the voucher's own post transaction, never lazily recomputed.
-- ---------------------------------------------------------------------------
CREATE TABLE `tds_deductee_ytd` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `deductee_ledger_id` BIGINT UNSIGNED NOT NULL,
    `tds_section_id`     BIGINT UNSIGNED NOT NULL,
    `fy_start`           SMALLINT NOT NULL,
    `paid_amount`        DECIMAL(18,2) NOT NULL DEFAULT 0,   -- Σ taxable base paid (pre-GST)
    `deducted_amount`    DECIMAL(18,2) NOT NULL DEFAULT 0,   -- Σ TDS actually withheld
    `created_at`         TIMESTAMP NULL,
    `updated_at`         TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tds_ytd_unique` (`deductee_ledger_id`, `tds_section_id`, `fy_start`),
    KEY `tds_deductee_ytd_tds_section_id_foreign` (`tds_section_id`),
    CONSTRAINT `tds_deductee_ytd_deductee_ledger_id_foreign`
        FOREIGN KEY (`deductee_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `tds_deductee_ytd_tds_section_id_foreign`
        FOREIGN KEY (`tds_section_id`) REFERENCES `tds_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4) tds_deductions — one row per TDS-engaged Payment, INCLUDING the ones that deducted
--    nothing because the deductee was still below the threshold. Those zero rows carry
--    their weight: they are the audit trail of a below-threshold payment, the source a
--    monthly-threshold section (194I) reconstructs its month window from, the "amount
--    paid" Form 26Q reports, and what makes the alter/cancel reversal uniform.
--
--    payment_amount vs base_amount is the subtle pair:
--      payment_amount = the taxable base of THIS voucher (pre-GST).
--      base_amount    = the CUMULATIVE base the liability was computed on. Equal to
--                       payment_amount in the steady state; equal to the whole year-to-date
--                       aggregate on the voucher that first crosses the threshold — because
--                       Indian TDS law makes you catch up on everything paid earlier.
--                       (For 194Q it is the excess over the threshold.)
--      It always holds that: deducted = ROUND(base_amount × rate) − already_deducted.
--
--    voucher_entry_id points at the Cr TDS Payable line, and is NULL when nothing was
--    deducted — such a voucher has only its two ordinary lines.
--    rate is stored because it is not re-derivable from the section alone (it already
--    reflects the deductee type and Section 206AA).
-- ---------------------------------------------------------------------------
CREATE TABLE `tds_deductions` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `voucher_id`         BIGINT UNSIGNED NOT NULL,
    `voucher_entry_id`   BIGINT UNSIGNED NULL,
    `deductee_ledger_id` BIGINT UNSIGNED NOT NULL,
    `tds_section_id`     BIGINT UNSIGNED NOT NULL,
    `payment_amount`     DECIMAL(18,2) NOT NULL,
    `base_amount`        DECIMAL(18,2) NOT NULL,
    `rate`               DECIMAL(5,2)  NOT NULL,
    `deducted_amount`    DECIMAL(18,2) NOT NULL,
    `fy_start`           SMALLINT NOT NULL,
    `reason`             VARCHAR(255) NULL,
    `created_at`         TIMESTAMP NULL,
    `updated_at`         TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `tds_deductions_voucher_id_foreign` (`voucher_id`),
    KEY `tds_deductions_voucher_entry_id_foreign` (`voucher_entry_id`),
    KEY `tds_deductions_tds_section_id_foreign` (`tds_section_id`),
    KEY `tds_ded_state_idx` (`deductee_ledger_id`, `tds_section_id`, `fy_start`),
    CONSTRAINT `tds_deductions_voucher_id_foreign`
        FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `tds_deductions_voucher_entry_id_foreign`
        FOREIGN KEY (`voucher_entry_id`) REFERENCES `voucher_entries` (`id`) ON DELETE SET NULL,
    CONSTRAINT `tds_deductions_deductee_ledger_id_foreign`
        FOREIGN KEY (`deductee_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `tds_deductions_tds_section_id_foreign`
        FOREIGN KEY (`tds_section_id`) REFERENCES `tds_sections` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5) ledgers — the 'tds' tax_type, and deductee tagging on party ledgers.
--
--    `deductee_pan` is deliberately distinct from the existing `pan`: `pan` is the party's
--    general / Nepal-VAT PAN, `deductee_pan` is the PAN quoted on a TDS return. Only
--    `deductee_pan` drives Section 206AA — clearing it is what makes the rate jump to 20%.
-- ---------------------------------------------------------------------------
ALTER TABLE `ledgers`
    MODIFY `tax_type` ENUM('central','state','integrated','vat','tds') NULL;

ALTER TABLE `ledgers`
    ADD COLUMN `deductee_pan`  VARCHAR(10) NULL AFTER `gst_registration_type`,
    ADD COLUMN `deductee_type` ENUM('individual_huf','company_firm_llp','other') NULL AFTER `deductee_pan`,
    ADD COLUMN `default_tds_section_id` BIGINT UNSIGNED NULL AFTER `deductee_type`,
    ADD CONSTRAINT `ledgers_default_tds_section_id_foreign`
        FOREIGN KEY (`default_tds_section_id`) REFERENCES `tds_sections` (`id`) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- 6) Seed.
--
--    (a) The TDS Payable duty ledger, under "Duties & Taxes".
--
--        Note `tax_role` is NULL. It is the ONE duty ledger with no role: TDS Payable is
--        neither output nor input tax, it is money withheld from a vendor and owed to the
--        Revenue. GstService::taxLedgerMap() selects WHERE tax_type IS NOT NULL AND
--        tax_role IS NOT NULL, so the NULL keeps this ledger completely invisible to the
--        GST and VAT engines — it can never be picked as a tax line on an invoice, and it
--        never appears in either regime's summary.
--
--    (b) The section catalog.
--
--        ┌──────────────────────────────────────────────────────────────────────────┐
--        │  A CHARTERED ACCOUNTANT MUST VERIFY THESE RATES AND THRESHOLDS AGAINST   │
--        │  THE CURRENT FINANCE ACT BEFORE THIS IS USED ON A REAL BOOK.             │
--        │  They change annually and are seeded here as reasonable DEFAULTS only.   │
--        └──────────────────────────────────────────────────────────────────────────┘
--
--        The old 194-series rows end at effective_to = 2025 (last valid FY 2025-26) and are
--        kept so a historical voucher still books and reports correctly; the Section 393
--        rows begin at effective_from = 2026 and never expire.
-- ---------------------------------------------------------------------------
INSERT INTO `ledgers` (`name`, `group_id`, `opening_balance`, `opening_balance_type`,
                       `is_reserved`, `is_pl_account`, `tax_role`, `tax_type`, `country`,
                       `created_at`, `updated_at`)
SELECT 'TDS Payable', g.`id`, 0, NULL, 1, 0, NULL, 'tds', 'India', NOW(), NOW()
  FROM `account_groups` g
 WHERE g.`name` = 'Duties & Taxes'
   AND NOT EXISTS (SELECT 1 FROM `ledgers` l WHERE l.`name` = 'TDS Payable');

INSERT INTO `tds_sections`
    (`code`, `label`, `rate`, `rate_company`, `no_pan_rate`, `threshold_single`, `threshold_annual`,
     `threshold_period`, `deduct_basis`, `effective_from`, `effective_to`, `notes`, `created_at`, `updated_at`)
VALUES
-- ---- the old 194-series, repealed at the end of FY 2025-26 ------------------
('194A',   'Interest other than on securities',            10.00, NULL, NULL, NULL,     50000.00,   'annual', 'aggregate', 2000, 2025,
 'Bank/post-office and senior-citizen payees have different thresholds — add separate sections if you need them.', NOW(), NOW()),
('194C',   'Payments to contractors',                       1.00, 2.00, NULL, 30000.00, 100000.00,  'annual', 'aggregate', 2000, 2025,
 'A single bill above the single threshold is deducted on its own; once the annual aggregate is also crossed, tax falls on the whole aggregate.', NOW(), NOW()),
('194H',   'Commission or brokerage',                       2.00, NULL, NULL, NULL,     20000.00,   'annual', 'aggregate', 2000, 2025,
 'Verify the current rate — this was reduced from 5% to 2% with effect from 1 October 2024.', NOW(), NOW()),
('194J',   'Fees for professional or technical services',  10.00, NULL, NULL, NULL,     50000.00,   'annual', 'aggregate', 2000, 2025,
 'Technical services and call-centre payments are deducted at 2%, not 10% — add a separate section for those.', NOW(), NOW()),
-- Rent, land & building — the ANNUAL-threshold era, closed at FY 2024-25.
('194I-B', 'Rent — land, building or furniture',           10.00, NULL, NULL, NULL,     240000.00,  'annual', 'aggregate', 2000, 2024,
 'Threshold is per MONTH (or part of a month) from FY 2025-26; it was ₹2,40,000 per year before that.', NOW(), NOW()),
('194Q',   'Purchase of goods',                             0.10, NULL, 5.00, NULL,     5000000.00, 'annual', 'excess',    2021, 2025,
 'Deducted only on the value EXCEEDING the aggregate threshold, and only by buyers above the turnover limit. Without a PAN the 206AA floor is 5%, not 20%.', NOW(), NOW()),

-- ---- the Finance Act 2025 re-thresholded 194I to a per-MONTH ₹50,000 --------
('194I-A', 'Rent — plant, machinery or equipment',          2.00, NULL, NULL, NULL,     50000.00,   'monthly', 'aggregate', 2025, 2025,
 'Threshold is per MONTH (or part of a month), not per year.', NOW(), NOW()),
('194I-B', 'Rent — land, building or furniture',           10.00, NULL, NULL, NULL,     50000.00,   'monthly', 'aggregate', 2025, 2025,
 'Threshold is per MONTH (or part of a month) from FY 2025-26; it was ₹2,40,000 per year before that.', NOW(), NOW()),

-- ---- Section 393 of the Income Tax Act 2025 — in force from FY 2026-27 ------
('393-194A',   'Interest other than on securities',            10.00, NULL, NULL, NULL,     50000.00,   'annual',  'aggregate', 2026, NULL,
 'Bank/post-office and senior-citizen payees have different thresholds — add separate sections if you need them.', NOW(), NOW()),
('393-194C',   'Payments to contractors',                       1.00, 2.00, NULL, 30000.00, 100000.00,  'annual',  'aggregate', 2026, NULL,
 'A single bill above the single threshold is deducted on its own; once the annual aggregate is also crossed, tax falls on the whole aggregate.', NOW(), NOW()),
('393-194H',   'Commission or brokerage',                       2.00, NULL, NULL, NULL,     20000.00,   'annual',  'aggregate', 2026, NULL,
 'Verify the current rate — this was reduced from 5% to 2% with effect from 1 October 2024.', NOW(), NOW()),
('393-194I-A', 'Rent — plant, machinery or equipment',          2.00, NULL, NULL, NULL,     50000.00,   'monthly', 'aggregate', 2026, NULL,
 'Threshold is per MONTH (or part of a month), not per year.', NOW(), NOW()),
('393-194I-B', 'Rent — land, building or furniture',           10.00, NULL, NULL, NULL,     50000.00,   'monthly', 'aggregate', 2026, NULL,
 'Threshold is per MONTH (or part of a month) from FY 2025-26; it was ₹2,40,000 per year before that.', NOW(), NOW()),
('393-194J',   'Fees for professional or technical services',  10.00, NULL, NULL, NULL,     50000.00,   'annual',  'aggregate', 2026, NULL,
 'Technical services and call-centre payments are deducted at 2%, not 10% — add a separate section for those.', NOW(), NOW()),
('393-194Q',   'Purchase of goods',                             0.10, NULL, 5.00, NULL,     5000000.00, 'annual',  'excess',    2026, NULL,
 'Deducted only on the value EXCEEDING the aggregate threshold, and only by buyers above the turnover limit. Without a PAN the 206AA floor is 5%, not 20%.', NOW(), NOW());

-- ---------------------------------------------------------------------------
-- 7) CENTRAL DATABASE ONLY — run this once against `zerobook_central`.
--
--    `Plan::allows()` returns FALSE for a feature key absent from the JSON, and PlanGate
--    is the server-side security boundary the F11 save path calls. Without this, every
--    tenant provisioned before Phase 10A would find TDS permanently locked. TDS is
--    unlocked on every tier, exactly as GST and VAT are: it is statutory compliance,
--    not a premium add-on.
-- ---------------------------------------------------------------------------
-- USE `zerobook_central`;
-- UPDATE `plans`
--    SET `features` = JSON_SET(`features`, '$.tds', TRUE),
--        `updated_at` = NOW();
