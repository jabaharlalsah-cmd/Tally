<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 9B — a record that a Nepal VAT return (अनुसूची-१०) was filed for a BS period,
 * with the submission reference the IRD taxpayer portal returned. Recorded by hand;
 * there is no portal integration.
 */
class VatReturnFiling extends Model
{
    use BelongsToCompany;

    protected $fillable = ['period', 'submission_ref', 'filed_at', 'notes'];

    protected $casts = [
        'filed_at' => 'datetime',
    ];

    /** Record (or update) the submission reference for a BS period. */
    public static function record(string $period, string $submissionRef, ?string $notes = null): self
    {
        return static::updateOrCreate(
            ['period' => $period],
            ['submission_ref' => trim($submissionRef), 'notes' => $notes, 'filed_at' => now()]
        );
    }

    public static function isFiled(string $period): bool
    {
        return static::where('period', $period)->whereNotNull('submission_ref')->exists();
    }
}
