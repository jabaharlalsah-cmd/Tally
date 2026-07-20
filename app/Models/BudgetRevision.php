<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 15A — the audit log of budget revisions: who changed the targets, on what effective
 * date, and why. One row per reviseBudget() call.
 */
class BudgetRevision extends Model
{
    protected $fillable = ['budget_id', 'revised_at', 'revised_by_user_id', 'notes'];

    protected $casts = [
        'revised_at' => 'date',
        'revised_by_user_id' => 'integer',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }
}
