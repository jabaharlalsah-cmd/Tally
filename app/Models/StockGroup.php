<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock Group (Phase 6A) — a self-referencing hierarchy exactly like
 * AccountGroup. parent_id null = a top-level ("Primary") group.
 */
class StockGroup extends Model
{
    use BelongsToCompany;

    protected $fillable = ['name', 'alias', 'parent_id'];

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

    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class, 'stock_group_id');
    }

    /** "Raw Materials ▸ Chemicals" style ancestor chain. */
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

    /** Shape sent to the client-side masters cache (for the Under pickers). */
    public function toCache(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'alias' => $this->alias,
            'parent_id' => $this->parent_id,
            'path' => $this->pathLabel(),
            'is_reserved' => false,
        ];
    }
}
