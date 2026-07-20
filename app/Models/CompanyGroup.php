<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Phase 12B — a set of companies explicitly declared as RELATED (one owner's
 * subsidiaries), inside one tenant.
 *
 * Deliberately NOT company-scoped (no BelongsToCompany): a group spans companies,
 * so it is a tenant-level master, like Company itself. Grouping is opt-in — a
 * tenant with no groups behaves exactly as a 12A tenant — and membership is
 * exclusive: a company belongs to at most one group at a time (DB-enforced by
 * the pivot's unique company_id).
 *
 * The group's one behavioural consequence lives in InterCompanyService: a voucher
 * touching a party ledger linked to a GROUPMATE company must carry the
 * inter-company tag that 12C's consolidation eliminates.
 */
class CompanyGroup extends Model
{
    protected $fillable = ['name', 'slug', 'notes'];

    public function companies()
    {
        return $this->belongsToMany(Company::class, 'company_group_members')->withTimestamps();
    }

    /** The group a company belongs to, or null (a company is in ≤ 1 group). */
    public static function forCompany(?int $companyId): ?self
    {
        if ($companyId === null) {
            return null;
        }

        return static::whereHas('companies', fn ($q) => $q->whereKey($companyId))->first();
    }

    /** A slug for $name that is unique among this tenant's groups. */
    public static function uniqueSlugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'group';
        $slug = $base;
        $n = 2;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
