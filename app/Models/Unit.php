<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Unit of Measure (Phase 6A). decimal_places controls how a quantity in this unit
 * rounds/displays (0 = a whole-count unit like "Nos"; 2 = "Kg").
 */
class Unit extends Model
{
    use BelongsToCompany;

    protected $fillable = ['name', 'symbol', 'decimal_places'];

    protected $casts = [
        'decimal_places' => 'integer',
    ];

    /** Shape sent to the client-side masters cache (for the Unit pickers). */
    public function toCache(): array
    {
        return [
            'id' => $this->id,
            'is_active' => (bool) ($this->is_active ?? true),
            'name' => $this->name,
            'symbol' => $this->symbol,
            'decimal_places' => (int) $this->decimal_places,
            'is_reserved' => false,
        ];
    }
}
