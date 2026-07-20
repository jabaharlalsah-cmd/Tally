<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 12A — multi-company inside a tenant (per-tenant migration).
 *
 * One tenant database now holds N companies, each a fully isolated set of books.
 * Every operational table gains a NOT-NULL `company_id` (indexed, FK → companies,
 * cascade), every natural-key unique index becomes composite with company_id, and
 * every existing row is explicitly assigned to a DEFAULT COMPANY created here —
 * so the migration is safe on a live tenant: after it runs, the books are exactly
 * what they were, now owned by one named company.
 *
 *  1. companies — the registry. name/slug unique; identity fields state, gstin,
 *     pan, tan MOVE here from the single-row company_features (copied, then the
 *     old columns are dropped); base_currency_id points at the company's own base
 *     currency row; financial_year_start_month (default 4 = April) drives the
 *     BOOKS fiscal year; is_active supports deactivate-without-delete.
 *
 *  2. The default company — named after the tenant (via tenancy) or 'Default
 *     Company'. Created only if no company exists, so re-runs and repair runs are
 *     idempotent.
 *
 *  3. company_id everywhere — 24 tables, in FK-dependency-safe order. Pattern per
 *     table: add nullable column → backfill to the default company → NOT NULL →
 *     re-key unique indexes → FK. Voucher numbering's unique becomes
 *     (company_id, type, fy_start, number) — per-company numbering; client_uuid's
 *     unique becomes (company_id, client_uuid) so the sync dedupe lookup (scoped)
 *     and the index agree.
 *
 *  4. company_features — one row PER company: + company_id UNIQUE, identity
 *     columns dropped (they live on companies now). Flags and the 26Q deductor
 *     block stay.
 *
 * NOTHING central changes: plans/tenants/tenant_users are untouched; a tenant's
 * plan gates each of its companies' F11 identically (PlanGate is tenant-level).
 */
return new class extends Migration
{
    /** [table => [old unique name, old unique cols, new composite name]] */
    private const RENAME_UNIQUES = [
        'account_groups' => [['account_groups_name_unique', ['name'], 'account_groups_company_name_unique']],
        'ledgers' => [['ledgers_name_unique', ['name'], 'ledgers_company_name_unique']],
        'cost_centres' => [['cost_centres_name_unique', ['name'], 'cost_centres_company_name_unique']],
        'units' => [['units_name_unique', ['name'], 'units_company_name_unique']],
        'stock_groups' => [['stock_groups_name_unique', ['name'], 'stock_groups_company_name_unique']],
        'godowns' => [['godowns_name_unique', ['name'], 'godowns_company_name_unique']],
        'stock_items' => [['stock_items_name_unique', ['name'], 'stock_items_company_name_unique']],
        'currencies' => [['currencies_code_unique', ['code'], 'currencies_company_code_unique']],
        'tds_sections' => [['tds_sections_code_effective_from_unique', ['code', 'effective_from'], 'tds_sections_company_code_effective_from_unique']],
        'vouchers' => [
            ['vouchers_type_fy_start_number_unique', ['type', 'fy_start', 'number'], 'vouchers_company_type_fy_start_number_unique'],
            ['vouchers_client_uuid_unique', ['client_uuid'], 'vouchers_company_client_uuid_unique'],
        ],
        'gst_return_filings' => [['gst_return_filings_period_return_type_unique', ['period', 'return_type'], 'gst_return_filings_company_period_return_type_unique']],
        'vat_return_filings' => [['vat_return_filings_period_unique', ['period'], 'vat_return_filings_company_period_unique']],
        'tds_return_filings' => [['tds_return_filings_fy_start_quarter_unique', ['fy_start', 'quarter'], 'tds_return_filings_company_fy_start_quarter_unique']],
    ];

    /** Tables that gain company_id + a plain (company_id, x) helper index. */
    private const PLAIN_INDEXES = [
        'vouchers' => ['date'],
        'voucher_entries' => ['ledger_id'],
        'stock_entries' => ['stock_item_id'],
        'sync_changes' => ['entity', 'id'],
    ];

    /**
     * Every table that gains company_id, in an order where nothing references a
     * table that comes later. (The FK all point at companies, so order only
     * matters for readability — kept master→transaction→compliance.)
     */
    private const SCOPED_TABLES = [
        'account_groups', 'ledgers', 'cost_centres', 'units', 'stock_groups',
        'godowns', 'stock_items', 'currencies', 'exchange_rates', 'tds_sections',
        'vouchers', 'voucher_entries', 'bill_allocations', 'cost_allocations',
        'stock_entries', 'order_lines', 'order_fulfillments',
        'tds_deductions', 'tds_deductee_ytd', 'tds_challans',
        'gst_return_filings', 'vat_return_filings', 'tds_return_filings',
        'company_features', 'sync_changes',
    ];

    public function up(): void
    {
        // ---- 1. The companies registry ---------------------------------------
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('slug', 60)->unique();      // URL-safe short name (switch URLs)
            $table->string('state')->nullable();        // GST intra/inter (was company_features.company_state)
            $table->string('gstin', 20)->nullable();    // was company_features.company_gstin
            $table->string('pan', 30)->nullable();      // Nepal VAT + 26Q deductor PAN (was company_pan)
            $table->string('tan', 10)->nullable();      // 26Q (was company_tan)
            $table->foreignId('base_currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->unsignedTinyInteger('financial_year_start_month')->default(4); // books FY; TDS stays statutory Apr
            $table->boolean('is_active')->default(true); // deactivate, never casually delete
            $table->timestamps();
        });

        // ---- 2. The default company (idempotent) -----------------------------
        $defaultId = DB::table('companies')->orderBy('id')->value('id');

        if ($defaultId === null) {
            $tenantName = null;
            if (function_exists('tenant') && tenant()) {
                $tenantName = tenant()->name ?? null;
            }
            $name = trim((string) $tenantName) !== '' ? trim((string) $tenantName) : 'Default Company';

            // Identity moves over from the single company_features row.
            $features = DB::table('company_features')->orderBy('id')->first();

            $defaultId = DB::table('companies')->insertGetId([
                'name' => $name,
                'slug' => Str::slug($name) ?: 'default',
                'state' => $features->company_state ?? null,
                'gstin' => $features->company_gstin ?? null,
                'pan' => $features->company_pan ?? null,
                'tan' => $features->company_tan ?? null,
                'base_currency_id' => DB::table('currencies')->where('is_base', true)->value('id'),
                'financial_year_start_month' => 4,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ---- 3. company_id on every operational table -------------------------
        // The old CompanyFeature::current() (first() ?? create) had no unique guard;
        // if a race ever left stray rows, keep the oldest so unique(company_id) holds.
        $keepFeatureId = DB::table('company_features')->orderBy('id')->value('id');
        if ($keepFeatureId !== null) {
            DB::table('company_features')->where('id', '>', $keepFeatureId)->delete();
        }

        foreach (self::SCOPED_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->after('id');
            });

            // The explicit data-migration step: every existing row belongs to the
            // tenant's default company. Nothing is moved silently — this IS the move.
            DB::table($tableName)->whereNull('company_id')->update(['company_id' => $defaultId]);

            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable(false)->change();
            });

            // Re-key natural uniques: drop the tenant-wide index, add the composite.
            foreach (self::RENAME_UNIQUES[$tableName] ?? [] as [$oldName, $oldCols, $newName]) {
                Schema::table($tableName, function (Blueprint $table) use ($oldName, $oldCols, $newName) {
                    $table->dropUnique($oldName);
                    $table->unique(array_merge(['company_id'], $oldCols), $newName);
                });
            }

            // Helper indexes for the hottest company-filtered scans.
            foreach ([self::PLAIN_INDEXES[$tableName] ?? null] as $cols) {
                if ($cols !== null) {
                    Schema::table($tableName, function (Blueprint $table) use ($tableName, $cols) {
                        $table->index(array_merge(['company_id'], $cols), $tableName.'_company_scan_index');
                    });
                }
            }

            if ($tableName === 'company_features') {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->unique('company_id', 'company_features_company_id_unique'); // one F11 row per company
                });
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->foreign('company_id', $tableName.'_company_id_foreign')
                    ->references('id')->on('companies')->cascadeOnDelete();
            });
        }

        // ---- 4. Identity columns leave company_features ----------------------
        Schema::table('company_features', function (Blueprint $table) {
            $table->dropColumn(['company_gstin', 'company_state', 'company_pan', 'company_tan']);
        });
    }

    public function down(): void
    {
        // Reverse only cleanly reverses a SINGLE-company tenant (the pre-12A shape):
        // with multiple companies the restored tenant-wide uniques would collide —
        // and because MySQL DDL is non-transactional, a mid-way abort would have
        // already merged some tables irreversibly. So FAIL FAST, before touching
        // anything: rolling back a multi-company tenant is a deliberate data
        // migration (move/merge the extra companies first), not a schema revert.
        if (DB::table('companies')->count() > 1) {
            throw new \RuntimeException(
                'Refusing to roll back: this tenant has multiple companies. '.
                'Reversing would merge their books irreversibly — consolidate to a '.
                'single company first.'
            );
        }

        // Identity back onto company_features from the default company.
        Schema::table('company_features', function (Blueprint $table) {
            $table->string('company_gstin')->nullable();
            $table->string('company_state')->nullable();
            $table->string('company_pan')->nullable();
            $table->string('company_tan', 10)->nullable();
        });

        $default = DB::table('companies')->orderBy('id')->first();
        if ($default) {
            DB::table('company_features')->update([
                'company_gstin' => $default->gstin,
                'company_state' => $default->state,
                'company_pan' => $default->pan,
                'company_tan' => $default->tan,
            ]);
        }

        foreach (array_reverse(self::SCOPED_TABLES) as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropForeign($tableName.'_company_id_foreign');
            });

            if ($tableName === 'company_features') {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropUnique('company_features_company_id_unique');
                });
            }

            foreach ([self::PLAIN_INDEXES[$tableName] ?? null] as $cols) {
                if ($cols !== null) {
                    Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                        $table->dropIndex($tableName.'_company_scan_index');
                    });
                }
            }

            foreach (self::RENAME_UNIQUES[$tableName] ?? [] as [$oldName, $oldCols, $newName]) {
                Schema::table($tableName, function (Blueprint $table) use ($oldName, $oldCols, $newName) {
                    $table->dropUnique($newName);
                    $table->unique($oldCols, $oldName);
                });
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('company_id');
            });
        }

        Schema::dropIfExists('companies');
    }
};
