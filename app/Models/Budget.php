<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\ActiveCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 15A — a named container of budget targets for one company + fiscal year.
 *
 * At most one budget per company is is_primary=true; the primary is what variance reports
 * use by default. Company scoping is transparent via BelongsToCompany (a global scope +
 * a creating hook that stamps company_id from the active company).
 */
class Budget extends Model
{
    use BelongsToCompany;

    protected $fillable = ['name', 'fiscal_year_start', 'is_primary', 'created_by_user_id', 'notes'];

    protected $casts = [
        'fiscal_year_start' => 'integer',
        'is_primary' => 'boolean',
        'created_by_user_id' => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(BudgetRevision::class)->orderByDesc('revised_at')->orderByDesc('id');
    }

    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    /** The company's primary budget (the default for variance reports), or null. */
    public static function primaryForCompany(): ?self
    {
        return static::query()->where('is_primary', true)->latest('id')->first();
    }

    /**
     * Make THIS budget the sole primary for its company — clears the flag on every other
     * budget in the same company, then sets it here. Scoped by BelongsToCompany, so it never
     * reaches across companies.
     */
    public function makePrimary(): void
    {
        static::query()
            ->where('company_id', $this->company_id ?? ActiveCompany::check())
            ->where('id', '!=', $this->id)
            ->update(['is_primary' => false]);

        $this->forceFill(['is_primary' => true])->save();
    }

    public function fyLabel(): string
    {
        return Voucher::fyLabel((int) $this->fiscal_year_start);
    }
}
