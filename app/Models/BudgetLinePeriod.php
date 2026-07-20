<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 15A — the RESOLVED target for one fiscal month of one budget line.
 *
 * `month` is the fiscal-month ordinal (1 = the company's FY-start month). `revised_from` is
 * null for the ORIGINAL allocation; a later revision writes NEW rows carrying the revision's
 * effective date, only for the months from that boundary forward. The effective target for a
 * month is the row with the greatest revised_from ≤ that month (null = original, earliest) —
 * so historical periods stay locked at their original targets.
 */
class BudgetLinePeriod extends Model
{
    protected $fillable = ['budget_line_id', 'month', 'target_amount', 'revised_from'];

    protected $casts = [
        'month' => 'integer',
        'target_amount' => 'decimal:2',
        'revised_from' => 'date',
    ];

    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class);
    }
}
