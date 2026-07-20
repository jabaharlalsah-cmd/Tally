<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\ActiveCompany;
use App\Support\CompanySchemaMemo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12A — company scoping, the way tenancy already works.
 *
 * Attached to every company-scoped model. Two duties:
 *
 *  1. READ — a global scope adds `WHERE {table}.company_id = <active>` to every
 *     Eloquent query when an active company is set (ActiveCompany). The column is
 *     table-qualified so joined queries stay unambiguous. Services never thread
 *     company ids through their signatures — the scope filters transparently,
 *     mirroring how the tenancy connection isolates tenants.
 *
 *  2. WRITE — a creating hook stamps company_id from the active company. It FAILS
 *     CLOSED: creating a scoped row with no active company throws rather than
 *     guessing (RuntimeException from ActiveCompany::check()).
 *
 * The Schema guard: during FRESH-tenant provisioning, migrations dated before 12A
 * run seeders on these models (tds_sections, INR currency) while company_id does
 * not exist yet. companyColumnExists() keeps both duties inert until the column
 * appears; the 12A migration then backfills those early rows to the default
 * company. Memoised POSITIVELY only and keyed by DATABASE + table, so (a) the
 * transition from "column absent" to "column present" inside one process
 * (provision = migrate, then seed) is observed — a negative result is always
 * re-checked — and (b) one process touching several tenant DBs (prove-multi-tenant
 * provisions two in a row) can never carry tenant A's schema verdict into
 * tenant B's migration window.
 */
trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            $id = ActiveCompany::id();

            if ($id !== null && static::companyColumnExists($builder->getModel())) {
                $builder->where($builder->getModel()->getTable().'.company_id', $id);
            }
        });

        static::creating(function (Model $model) {
            if ($model->getAttribute('company_id') !== null) {
                return; // explicitly pre-set (e.g. ActiveCompany::runAs seeding)
            }

            if (! static::companyColumnExists($model)) {
                return; // pre-12A schema (migration window) — stay inert
            }

            $model->setAttribute('company_id', ActiveCompany::check()); // fail closed
        });
    }

    protected static function companyColumnExists(Model $model): bool
    {
        // Phase 14A — shared, clearable memo (see CompanySchemaMemo). Keyed by db.table,
        // positive-only: a negative verdict is always re-checked so the column appearing
        // mid-process is observed, and a dropped-then-recreated DB is forgotten on teardown.
        $key = $model->getConnection()->getDatabaseName().'.'.$model->getTable();

        if (CompanySchemaMemo::has($key)) {
            return true;
        }

        if (Schema::hasColumn($model->getTable(), 'company_id')) {
            CompanySchemaMemo::remember($key);

            return true;
        }

        return false;
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
