-- =====================================================================
-- ZeroBook — NAS parity, Phase 2: the `inventory` F11 switch
-- TENANT database. Run once per tenant database (tenant<slug>).
-- phpMyAdmin-ready. Matches migration:
--   database/migrations/tenant/2026_07_30_000002_add_inventory_feature_flag.php
--
-- Every other optional module already had an F11 switch; inventory did not,
-- so a pure-services company still saw Inventory Info, Stock Journal,
-- Physical Stock, the order/note vouchers, Orders Outstanding, Stock Summary
-- and Lot Provenance on its Gateway.
--
-- THE BACKFILL BELOW IS NOT OPTIONAL.
-- The column defaults to 0 because a NEW company starts with every feature
-- off. But leaving an EXISTING company at 0 would hide inventory from a
-- business actively using it — the data would still be there but unreachable
-- from the menu, which reads as data loss. So any company that already has
-- stock items or stock movements is switched ON and keeps exactly the menu it
-- had before.
--
-- Godown presence is deliberately NOT used as a signal: every company is
-- seeded with a "Main Location" godown at creation, so it would switch the
-- flag on for everyone and defeat the purpose.
-- =====================================================================

ALTER TABLE `company_features`
    ADD COLUMN `inventory` TINYINT(1) NOT NULL DEFAULT 0 AFTER `multi_currency`;

-- Preserve the status quo for anyone already keeping stock.
UPDATE `company_features` cf
SET cf.`inventory` = 1
WHERE EXISTS (
    SELECT 1 FROM `stock_items` si WHERE si.`company_id` = cf.`company_id`
);

UPDATE `company_features` cf
SET cf.`inventory` = 1
WHERE EXISTS (
    SELECT 1 FROM `stock_entries` se WHERE se.`company_id` = cf.`company_id`
);

-- Verification — list each company and whether inventory ended up on.
-- SELECT cf.company_id, c.name, cf.inventory
-- FROM company_features cf JOIN companies c ON c.id = cf.company_id
-- ORDER BY c.name;

-- Rollback:
-- ALTER TABLE `company_features` DROP COLUMN `inventory`;
