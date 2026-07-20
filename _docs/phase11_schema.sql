-- ZeroBook Phase 11 — the multi-currency engine.
--
-- Applies to each TENANT database (tenant<slug>) ONLY. There is NOTHING to run against the
-- central database this phase (see the note at the very bottom for why).
--
-- Laravel does all of this automatically:
--     php artisan tenants:migrate         (each tenant: everything below)
-- This is the phpMyAdmin equivalent. Run sections 1–6 against ONE tenant database at a time.
--
-- A forex LAYER on top of the base-currency books. The Trial Balance, Balance Sheet and P&L
-- stay in base currency (INR for an Indian tenant, NPR for a Nepali one) — that never
-- changes. Every voucher line gains an OPTIONAL foreign amount + rate + currency; the base
-- `amount` (paise) stays the source of truth for the Dr/Cr balance gate. Every existing row
-- keeps NULL forex fields, so with multi-currency OFF nothing behaves differently and the
-- Trial Balance is byte-identical until the first foreign line is posted.
--
-- Column ORDER matches the Laravel migration's `after(...)` placement exactly, so a
-- SHOW CREATE TABLE on a DB built this way is identical to one built by tenants:migrate.

-- ---------------------------------------------------------------------------
-- 1) currencies — THE CURRENCY MASTER.
--
--    Exactly one row is `is_base` = 1 (the company's own reporting currency). The currency
--    master UI enforces exactly-one-base: marking a new base un-marks the previous one. A
--    Nepali tenant marks NPR base, which turns INR into a foreign currency for that book —
--    which is correct. Users add every other currency (USD, EUR, GBP, JPY, AED, …) by hand.
-- ---------------------------------------------------------------------------
CREATE TABLE `currencies` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`           VARCHAR(3)  NOT NULL,               -- 3-letter ISO: USD, EUR, INR, NPR
    `symbol`         VARCHAR(8)  NULL,                   -- $, EUR, ₹, Rs.
    `name`           VARCHAR(60) NOT NULL,
    `decimal_places` TINYINT UNSIGNED NOT NULL DEFAULT 2,
    `is_base`        TINYINT(1) NOT NULL DEFAULT 0,      -- exactly one = 1, the reporting currency
    `created_at`     TIMESTAMP NULL,
    `updated_at`     TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `currencies_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2) exchange_rates — THE RATE HISTORY.
--
--    rate = the base-currency value of ONE unit of the foreign currency, 6 decimal places
--    (1 USD = ₹83.50 → rate 83.500000). `rateOn(currency, date)` returns the most recent
--    rate on OR before a date, so a voucher back-dated between two rate rows uses the earlier
--    one. UNIQUE(currency_id, date) enforces one rate per currency per day. Manual entry;
--    automatic feeds are a later phase.
-- ---------------------------------------------------------------------------
CREATE TABLE `exchange_rates` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `currency_id` BIGINT UNSIGNED NOT NULL,
    `date`        DATE NOT NULL,
    `rate`        DECIMAL(16,6) NOT NULL,
    `created_at`  TIMESTAMP NULL,
    `updated_at`  TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `exchange_rates_currency_id_date_unique` (`currency_id`, `date`),
    KEY `exchange_rates_currency_id_date_index` (`currency_id`, `date`),
    CONSTRAINT `exchange_rates_currency_id_foreign`
        FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3) ledgers.currency_id — FOREIGN-CURRENCY LEDGER TAGGING.
--
--    NULL = base currency (the default for every existing ledger). A party ledger tagged
--    with a NON-base currency is a foreign-currency ledger: every voucher line on it must
--    carry a foreign amount + rate, and the server rejects the line if it doesn't.
--    ON DELETE SET NULL — removing a currency reverts its ledgers to base, never deletes them.
-- ---------------------------------------------------------------------------
ALTER TABLE `ledgers`
    ADD COLUMN `currency_id` BIGINT UNSIGNED NULL AFTER `gstin`,
    ADD KEY `ledgers_currency_id_foreign` (`currency_id`),
    ADD CONSTRAINT `ledgers_currency_id_foreign`
        FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- 4) voucher_entries — THE DUAL-CURRENCY SHAPE.
--
--    All three columns are NULL on a base line. On a foreign line the invariant
--        amount = ROUND(foreign_amount × exchange_rate × 100)      [amount is in paise]
--    holds, and is verified SERVER-SIDE on every post — a tampered base amount or an
--    unhistorical rate is rejected. `amount` remains the only thing the balance gate reads,
--    so the books balance in base currency exactly as before.
-- ---------------------------------------------------------------------------
ALTER TABLE `voucher_entries`
    ADD COLUMN `currency_id`    BIGINT UNSIGNED NULL AFTER `amount`,
    ADD COLUMN `foreign_amount` DECIMAL(18,4) NULL AFTER `currency_id`,
    ADD COLUMN `exchange_rate`  DECIMAL(16,6) NULL AFTER `foreign_amount`,
    ADD KEY `voucher_entries_currency_id_foreign` (`currency_id`),
    ADD CONSTRAINT `voucher_entries_currency_id_foreign`
        FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- 5) Seed the base currency — INR (the primary Indian-tenant case).
--
--    A Nepali tenant marks NPR base from the currency master afterwards. `updateOrCreate`
--    semantics: re-running is idempotent (matches on code = 'INR').
-- ---------------------------------------------------------------------------
INSERT INTO `currencies` (`code`, `symbol`, `name`, `decimal_places`, `is_base`, `created_at`, `updated_at`)
SELECT 'INR', '₹', 'Indian Rupee', 2, 1, NOW(), NOW()
 WHERE NOT EXISTS (SELECT 1 FROM `currencies` c WHERE c.`code` = 'INR');

-- ---------------------------------------------------------------------------
-- 6) Seed the two RESERVED forex ledgers that absorb the exchange-rate timing difference.
--
--        Foreign Exchange Gain  → Indirect Incomes   (Cr on a realised/unrealised gain)
--        Foreign Exchange Loss  → Indirect Expenses  (Dr on a realised/unrealised loss)
--
--    They are ordinary base-currency nominal ledgers, so they flow into the P&L naturally
--    and are invisible to the GST/VAT/TDS engines (those key on tax_type, which is NULL
--    here). ForexService identifies them by these reserved names. Both open at zero, so the
--    Trial Balance is unchanged until the first settlement or revaluation posts.
-- ---------------------------------------------------------------------------
INSERT INTO `ledgers` (`name`, `group_id`, `opening_balance`, `opening_balance_type`,
                       `is_reserved`, `is_pl_account`, `country`, `created_at`, `updated_at`)
SELECT 'Foreign Exchange Gain', g.`id`, 0, NULL, 1, 0, 'India', NOW(), NOW()
  FROM `account_groups` g
 WHERE g.`name` = 'Indirect Incomes'
   AND NOT EXISTS (SELECT 1 FROM `ledgers` l WHERE l.`name` = 'Foreign Exchange Gain');

INSERT INTO `ledgers` (`name`, `group_id`, `opening_balance`, `opening_balance_type`,
                       `is_reserved`, `is_pl_account`, `country`, `created_at`, `updated_at`)
SELECT 'Foreign Exchange Loss', g.`id`, 0, NULL, 1, 0, 'India', NOW(), NOW()
  FROM `account_groups` g
 WHERE g.`name` = 'Indirect Expenses'
   AND NOT EXISTS (SELECT 1 FROM `ledgers` l WHERE l.`name` = 'Foreign Exchange Loss');

-- ---------------------------------------------------------------------------
-- 7) CENTRAL DATABASE — NOTHING TO DO.
--
--    Unlike TDS (Phase 10A), Phase 11 needs no central change:
--
--      • The `multi_currency` F11 switch already exists on `company_features` (seeded false
--        since Phase 7A) — this migration does NOT touch that table.
--      • The central `plans` table already carries `multi_currency` in its `features` JSON,
--        gated to the ENTERPRISE tier only (starter = false, professional = false,
--        enterprise = true) since Phase 7B. Multi-currency is a premium tier feature, so
--        PlanGate::allows('multi_currency') is already correct for every tier.
--
--    If you ever want to unlock multi-currency on a lower tier, that is the one central edit:
--      -- USE `zerobook_central`;
--      -- UPDATE `plans`
--      --    SET `features` = JSON_SET(`features`, '$.multi_currency', TRUE), `updated_at` = NOW()
--      --  WHERE `slug` = 'professional';
