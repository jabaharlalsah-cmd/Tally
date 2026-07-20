<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 15C — the audit record of a one-way scenario promotion: which scenario, who, how many
 * vouchers became real, and when. The denormalised scenario_name keeps the row readable even after
 * the (now-empty) scenario is deleted.
 */
class ScenarioPromotion extends Model
{
    protected $fillable = ['scenario_id', 'scenario_name', 'promoted_by_user_id', 'voucher_count', 'promoted_at'];

    protected $casts = [
        'scenario_id' => 'integer',
        'promoted_by_user_id' => 'integer',
        'voucher_count' => 'integer',
        'promoted_at' => 'datetime',
    ];

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }
}
