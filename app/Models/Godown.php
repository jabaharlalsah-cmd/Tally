<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Godown / Location (Phase 6A) — a hierarchical location master. "Main Location"
 * is seeded and reserved (non-deletable).
 */
class Godown extends Model
{
    use BelongsToCompany;

    protected $fillable = ['name', 'parent_id', 'is_reserved'];

    protected $casts = [
        'parent_id' => 'integer',
        'is_reserved' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** "Main Location ▸ Rack A" style ancestor chain. */
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

    /** Shape sent to the client-side masters cache (for the Godown pickers). */
    public function toCache(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'path' => $this->pathLabel(),
            'is_reserved' => (bool) $this->is_reserved,
        ];
    }
}
