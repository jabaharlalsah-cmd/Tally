<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountGroup extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'name', 'alias', 'parent_id', 'nature',
        'is_primary', 'is_reserved', 'sort_order',
        'is_sub_ledger', 'nett_balance', 'used_for_calculation',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_reserved' => 'boolean',
        'is_sub_ledger' => 'boolean',
        'nett_balance' => 'boolean',
        'used_for_calculation' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(Ledger::class, 'group_id');
    }

    /** Depth from the root primary group (primary = 0). */
    public function depth(): int
    {
        $depth = 0;
        $node = $this;
        while ($node->parent_id) {
            $depth++;
            $node = $node->parent;
            if (! $node || $depth > 20) {
                break; // cycle guard
            }
        }

        return $depth;
    }

    /** "Capital Account ▸ Reserves & Surplus" style parent chain. */
    public function pathLabel(): string
    {
        $names = [];
        $node = $this;
        $guard = 0;
        while ($node && $guard++ < 20) {
            array_unshift($names, $node->name);
            $node = $node->parent;
        }

        return implode(' ▸ ', $names);
    }

    /** Shape sent to the client-side masters cache (for the pickers). */
    public function toCache(): array
    {
        return [
            'id' => $this->id,
            'is_active' => (bool) ($this->is_active ?? true),
            'name' => $this->name,
            'alias' => $this->alias,
            'parent_id' => $this->parent_id,
            'nature' => $this->nature,
            'is_primary' => (bool) $this->is_primary,
            'is_reserved' => (bool) $this->is_reserved,
            'path' => $this->pathLabel(),
        ];
    }
}
