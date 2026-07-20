<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NAS parity, Phase 3 — Active / Inactive on every master. Tenant database.
 *
 * WHY
 * The Dibi Tech master-data standard requires each master dropdown to carry a
 * gear icon opening a manage modal with Create / Edit / Active-Inactive toggle /
 * Delete-disabled-when-in-use, and requires the dropdown itself to list ACTIVE
 * items only. None of that was buildable: of the nine master tables, only
 * `companies` had an active flag. This adds the missing eight (nine tables
 * touched here, companies excluded since it already has one).
 *
 * It also matches TallyPrime, where a ledger or group that is no longer used is
 * retired rather than deleted, because deleting one with history would orphan
 * vouchers.
 *
 * DEFAULT IS TRUE, AND THAT IS THE SAFE DIRECTION
 * Opposite to the `inventory` feature flag added in Phase 2. There, OFF was
 * correct for new companies and existing users had to be backfilled ON. Here
 * every existing master IS in use and must stay selectable, so the column
 * defaults to true and existing rows inherit it — no backfill needed, and no
 * dropdown silently empties the moment this migration runs.
 *
 * WHAT THIS MIGRATION DELIBERATELY DOES NOT DO
 * It adds no guard against deactivating a reserved master. That belongs in the
 * model and the UI, where the reason can be explained to the user; the columns
 * it would key off (account_groups.is_reserved / is_primary, ledgers.is_reserved,
 * currencies.is_base) already exist.
 */
return new class extends Migration
{
    /**
     * Every master that appears in a picker. `companies` is absent on purpose —
     * it has carried is_active since the multi-company phase.
     */
    private const TABLES = [
        'account_groups',
        'ledgers',
        'units',
        'godowns',
        'stock_groups',
        'stock_items',
        'cost_centres',
        'currencies',
        'tds_sections',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            // Guarded: a tenant provisioned before a given master existed will
            // not have that table, and re-running must not fail on a column that
            // is already there.
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'is_active')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->boolean('is_active')->default(true);
                // Pickers filter on it on every keystroke, and the manage modal
                // sorts by it, so it is worth an index on the bigger masters.
                $t->index(['company_id', 'is_active'], $table.'_company_active_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'is_active')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex($table.'_company_active_idx');
                $t->dropColumn('is_active');
            });
        }
    }
};
