<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 15B — the per-company colour bands for one ratio. green_min / amber_min are boundaries
 * read according to the ratio's direction (defined in RatioService): a higher-is-better ratio is
 * green at value ≥ green_min; a lower-is-better ratio is green at value ≤ green_min. Company-scoped
 * by BelongsToCompany.
 */
class RatioThreshold extends Model
{
    use BelongsToCompany;

    protected $fillable = ['ratio_key', 'green_min', 'amber_min', 'red_min'];

    protected $casts = [
        'green_min' => 'float',
        'amber_min' => 'float',
        'red_min' => 'float',
    ];

    /** The active company's thresholds keyed by ratio_key (creating the seeded defaults on first use). */
    public static function mapForCompany(array $defaults): array
    {
        $existing = static::query()->get()->keyBy('ratio_key');

        // Seed any missing ratio with its industry-neutral default (idempotent, per company).
        foreach ($defaults as $key => $d) {
            if (! $existing->has($key)) {
                $existing[$key] = static::create([
                    'ratio_key' => $key,
                    'green_min' => $d['green_min'] ?? null,
                    'amber_min' => $d['amber_min'] ?? null,
                    'red_min' => $d['red_min'] ?? null,
                ]);
            }
        }

        return $existing->all();
    }
}
