<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\ExchangeRate;
use Carbon\Carbon;

/**
 * Phase 11 — the exchange-rate lookup.
 *
 * A rate is the base-currency value of one unit of a foreign currency, and it stays in
 * force until superseded, so `rateOn()` returns the most recent rate ON OR BEFORE a date.
 * Rates are entered manually (from the Currency master); automatic feeds are a later phase.
 * A missing rate is a hard error — the user must supply one before a foreign voucher posts.
 */
class ExchangeRateService
{
    /** Cached (currency_id => [ [date, rate], … ]) sorted descending by date. */
    private ?array $cache = null;

    /**
     * The rate for a currency on or before a date. The base currency is always 1.0.
     * Throws when no rate exists on or before the date.
     */
    public function rateOn(int $currencyId, Carbon $date): float
    {
        $currency = Currency::find($currencyId);
        if (! $currency) {
            throw new \RuntimeException("Unknown currency #{$currencyId}.");
        }
        if ($currency->is_base) {
            return 1.0;
        }

        $rate = ExchangeRate::where('currency_id', $currencyId)
            ->where('date', '<=', $date->toDateString())
            ->orderByDesc('date')->orderByDesc('id')
            ->value('rate');

        if ($rate === null) {
            throw new \RuntimeException(sprintf(
                'No exchange rate for %s on or before %s. Add one in the Currency master before posting.',
                $currency->code, $date->format('d-M-Y'),
            ));
        }

        return (float) $rate;
    }

    /** Same as rateOn but returns null instead of throwing (for the client cache / previews). */
    public function rateOrNull(int $currencyId, Carbon $date): ?float
    {
        try {
            return $this->rateOn($currencyId, $date);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * The latest rate per non-base currency, for the voucher screen's 0-network cache.
     *
     * @return array<int, array{currency_id:int, code:string, rate:float, date:string}>
     */
    public function latestRates(): array
    {
        $out = [];
        foreach (Currency::where('is_base', false)->get() as $c) {
            $r = ExchangeRate::where('currency_id', $c->id)
                ->orderByDesc('date')->orderByDesc('id')->first();
            if ($r) {
                $out[$c->id] = [
                    'currency_id' => $c->id,
                    'code' => $c->code,
                    'rate' => (float) $r->rate,
                    'date' => $r->date->toDateString(),
                ];
            }
        }

        return $out;
    }

    /**
     * The full rate history per non-base currency, as ascending [date, rate] pairs, for
     * the voucher screen to compute rate-on-date client-side (mirroring rateOn) with zero
     * network — so a back-dated foreign voucher defaults to the correct historical rate.
     *
     * @return array<int, array<int, array{0:string,1:float}>>
     */
    public function historyCache(): array
    {
        $out = [];
        foreach (ExchangeRate::orderBy('date')->orderBy('id')->get(['currency_id', 'date', 'rate']) as $r) {
            $out[$r->currency_id][] = [$r->date->toDateString(), (float) $r->rate];
        }

        return $out;
    }

    /** The full rate history for a currency, newest first — for the Currency master. */
    public function history(int $currencyId): array
    {
        return ExchangeRate::where('currency_id', $currencyId)
            ->orderByDesc('date')->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'date' => $r->date->toDateString(),
                'date_label' => $r->date->format('d-M-Y'),
                'rate' => (float) $r->rate,
            ])->all();
    }
}
