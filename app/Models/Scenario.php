<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 15C — a named container of provisional ("what-if") vouchers, company-scoped. A voucher
 * tagged with this scenario's id is excluded from real reports until the scenario is selected in a
 * report's picker (or the voucher is promoted to real). Archiving (is_active = false) hides the
 * scenario from active pickers without deleting its vouchers.
 */
class Scenario extends Model
{
    use BelongsToCompany;

    protected $fillable = ['name', 'slug', 'description', 'created_by_user_id', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'created_by_user_id' => 'integer',
    ];

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(ScenarioPromotion::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Provisional vouchers still held by this scenario (excludes any already promoted). */
    public function voucherCount(): int
    {
        return $this->vouchers()->count();
    }
}
