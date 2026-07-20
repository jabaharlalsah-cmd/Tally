<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 11 — one exchange rate for a currency on a date.
 *
 * `rate` is the base-currency value of ONE unit of the foreign currency: 1 USD = ₹83.50 →
 * rate 83.5000. ExchangeRateService::rateOn() returns the most recent rate on or before a
 * queried date, so a rate stays in force until superseded.
 */
class ExchangeRate extends Model
{
    use BelongsToCompany;

    protected $fillable = ['currency_id', 'date', 'rate'];

    protected $casts = [
        'date' => 'date',
        'rate' => 'decimal:6',
    ];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
