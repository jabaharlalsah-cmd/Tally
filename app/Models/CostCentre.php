<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cost centre (Phase 5D). Optionally nested under a parent cost centre. A single
 * implicit "Primary" cost category is assumed for now.
 */
class CostCentre extends Model
{
    use BelongsToCompany;

    protected $fillable = ['name', 'parent_id'];

    protected $casts = [
        'parent_id' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** "Sales ▸ North" style ancestor chain. */
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
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'path' => $this->pathLabel(),
            // no reserved cost centres this phase
            'is_reserved' => false,
        ];
    }
}
