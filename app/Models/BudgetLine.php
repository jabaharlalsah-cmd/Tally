<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 15A — one target inside a budget: EITHER a ledger OR an account group (exactly one,
 * enforced by a DB CHECK + BudgetService validation). The annual_target is spread across the
 * fiscal months into budget_line_periods by allocation_method.
 *
 * No company_id of its own — it is reached only through its (company-scoped) parent budget.
 */
class BudgetLine extends Model
{
    public const METHODS = ['even', 'custom', 'seasonal'];

    protected $fillable = ['budget_id', 'ledger_id', 'account_group_id', 'annual_target', 'allocation_method', 'notes'];

    protected $casts = [
        'annual_target' => 'decimal:2',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    public function accountGroup(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(BudgetLinePeriod::class);
    }

    public function isGroupLine(): bool
    {
        return $this->account_group_id !== null;
    }

    /** The reporting nature ('Income'|'Expenses'|'Assets'|'Liabilities') this line targets. */
    public function nature(): ?string
    {
        if ($this->isGroupLine()) {
            return $this->accountGroup?->nature;
        }

        return $this->ledger?->group?->nature;
    }

    public function targetName(): string
    {
        if ($this->isGroupLine()) {
            return $this->accountGroup?->name ?? 'Group';
        }

        return $this->ledger?->name ?? 'Ledger';
    }
}
