<?php

namespace App\Livewire\Concerns;

use App\Models\Unit;
use Illuminate\Validation\Rule;

/**
 * Shared unit creation, used by UnitWorkspace (its own create) and
 * StockItemWorkspace (inline Alt+C "create unit" from the Unit picker).
 */
trait CreatesUnits
{
    // Inline quick-create-unit form (Alt+C from the Stock Item Unit picker)
    public string $qu_name = '';
    public ?string $qu_symbol = null;
    public string $qu_decimal_places = '0';

    public function saveQuickUnit(): ?array
    {
        $this->validate([
            'qu_name' => ['required', 'string', 'max:191', Rule::unique('units', 'name')->where('company_id', \App\Support\ActiveCompany::check())],
            'qu_symbol' => ['nullable', 'string', 'max:20'],
            'qu_decimal_places' => ['required', 'integer', 'min:0', 'max:6'],
        ], [], ['qu_name' => 'name', 'qu_decimal_places' => 'decimal places']);

        $unit = Unit::create([
            'name' => trim($this->qu_name),
            'symbol' => $this->qu_symbol ? trim($this->qu_symbol) : null,
            'decimal_places' => (int) $this->qu_decimal_places,
        ]);

        $this->reset('qu_name', 'qu_symbol');
        $this->qu_decimal_places = '0';

        return $unit->toCache();
    }
}
