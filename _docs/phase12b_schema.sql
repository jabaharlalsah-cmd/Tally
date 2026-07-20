-- ZeroBook Phase 12B -- company groups + inter-company transaction tagging.
--
-- Applies to each TENANT database (tenant<slug>) ONLY. Nothing central changes.
--
-- Laravel does all of this automatically:
--     php artisan tenants:migrate
-- This is the phpMyAdmin equivalent. Run it against ONE tenant database at a time.
--
-- OPT-IN AND INERT BY DEFAULT: no rows are seeded -- groups are user-created. A
-- tenant that never creates a group behaves exactly as a 12A tenant (a CA firm's
-- unrelated client books never see any tagging). Once companies are grouped,
-- transactions between them must carry the server-derived inter-company tag --
-- the rows Phase 12C's consolidation eliminates.

-- ---------------------------------------------------------------------------
-- 1) company_groups -- the group master. A TENANT-level concept (deliberately
--    NO company_id: a group spans companies, so it sits above the 12A scope).
-- ---------------------------------------------------------------------------
CREATE TABLE `company_groups` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(120) NOT NULL,
    `slug`       VARCHAR(60)  NOT NULL,
    `notes`      TEXT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `company_groups_name_unique` (`name`),
    UNIQUE KEY `company_groups_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2) company_group_members -- membership. UNIQUE(company_id): a company belongs
--    to at most ONE group at a time. Deleting a group cascades the membership
--    rows and touches no company.
-- ---------------------------------------------------------------------------
CREATE TABLE `company_group_members` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_group_id` BIGINT UNSIGNED NOT NULL,
    `company_id`       BIGINT UNSIGNED NOT NULL,
    `created_at`       TIMESTAMP NULL,
    `updated_at`       TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `company_group_members_company_id_unique` (`company_id`),
    KEY `company_group_members_company_group_id_index` (`company_group_id`),
    CONSTRAINT `company_group_members_company_group_id_foreign`
        FOREIGN KEY (`company_group_id`) REFERENCES `company_groups` (`id`) ON DELETE CASCADE,
    CONSTRAINT `company_group_members_company_id_foreign`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3) ledgers.linked_company_id -- marks a PARTY ledger in company A as "this
--    party IS company B". Only meaningful on party-tracking groups (Sundry
--    Debtors/Creditors, Loans & Advances (Asset), Loans (Liability) + children;
--    the Ledger master enforces that -- the schema stays permissive so a link
--    can outlive a deleted group, where it is simply inert). ON DELETE SET NULL:
--    removing the linked company reverts the ledger to an ordinary party.
-- ---------------------------------------------------------------------------
ALTER TABLE `ledgers`
    ADD COLUMN `linked_company_id` BIGINT UNSIGNED NULL AFTER `currency_id`,
    ADD KEY `ledgers_linked_company_id_foreign` (`linked_company_id`),
    ADD CONSTRAINT `ledgers_linked_company_id_foreign`
        FOREIGN KEY (`linked_company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- 4) voucher_intercompany_tags -- the mandatory tag, ONE per voucher (unique).
--    company_id = the POSTING company (the 12A every-operational-table
--    discipline; 12C scans "my company's tags" through the scope);
--    counterparty_company_id = the other side; counterparty_ledger_id = the
--    mirror ledger in the counterparty company when one is unambiguously
--    reciprocally linked (lets 12C match eliminations precisely, not by amount).
--    created_at only -- a tag is never updated, only re-derived (delete+insert
--    on alter; the voucher FK cascades it on cancel).
-- ---------------------------------------------------------------------------
CREATE TABLE `voucher_intercompany_tags` (
    `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id`              BIGINT UNSIGNED NOT NULL,
    `voucher_id`              BIGINT UNSIGNED NOT NULL,
    `counterparty_company_id` BIGINT UNSIGNED NOT NULL,
    `counterparty_ledger_id`  BIGINT UNSIGNED NULL,
    `created_at`              TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `voucher_intercompany_tags_voucher_id_unique` (`voucher_id`),
    KEY `voucher_intercompany_tags_counterparty_company_id_foreign` (`counterparty_company_id`),
    KEY `voucher_intercompany_tags_counterparty_ledger_id_foreign` (`counterparty_ledger_id`),
    KEY `voucher_intercompany_tags_company_scan_index` (`company_id`, `counterparty_company_id`),
    CONSTRAINT `voucher_intercompany_tags_company_id_foreign`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `voucher_intercompany_tags_counterparty_company_id_foreign`
        FOREIGN KEY (`counterparty_company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `voucher_intercompany_tags_counterparty_ledger_id_foreign`
        FOREIGN KEY (`counterparty_ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE SET NULL,
    CONSTRAINT `voucher_intercompany_tags_voucher_id_foreign`
        FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
