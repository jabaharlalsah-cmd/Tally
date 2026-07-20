<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 11 — a currency in the tenant's currency master.
 *
 * Exactly one currency has `is_base = true` — the company's own reporting currency, in
 * which the Trial Balance / Balance Sheet / P&L are always expressed. Every other currency
 * is a foreign currency whose transactions carry a base-currency equivalent at the day's rate.
 */
class Currency extends Model
{
    use BelongsToCompany;

    protected $fillable = ['code', 'symbol', 'name', 'decimal_places', 'is_base'];

    protected $casts = [
        'decimal_places' => 'integer',
        'is_base' => 'boolean',
    ];

    public function rates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class);
    }

    /** The tenant's base (reporting) currency. */
    public static function base(): ?self
    {
        return static::where('is_base', true)->first();
    }

    /** "USD — US Dollar" */
    public function label(): string
    {
        return $this->code.' — '.$this->name;
    }

    /** Shape sent to the client-side currency cache. */
    public function toCache(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'symbol' => $this->symbol,
            'name' => $this->name,
            'decimal_places' => (int) $this->decimal_places,
            'is_base' => (bool) $this->is_base,
        ];
    }
}
