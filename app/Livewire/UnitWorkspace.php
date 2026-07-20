<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Livewire\Concerns\CreatesUnits;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Units of Measure master (Phase 6A) — Create (single + multiple), Display, Alter,
 * Delete. Flat master (no hierarchy). The `CreatesUnits` concern also powers the
 * inline Alt+C create from the Stock Item form.
 */
class UnitWorkspace extends Component
{
    use GuardsActiveCompany;
    use CreatesUnits;

    // Single create
    public string $name = '';
    public ?string $symbol = null;
    public string $decimal_places = '0';

    // Multiple create — [{name, symbol, decimals}]
    public array $rows = [];

    // Alter
    public ?int $alter_id = null;
    public string $u_name = '';
    public ?string $u_symbol = null;
    public string $u_decimals = '0';

    public function mount(): void
    {
        $this->rows = array_fill(0, 10, ['name' => '', 'symbol' => '', 'decimals' => '0']);
    }

    public function cache(): array
    {
        return Unit::orderBy('name')->get()->map->toCache()->all();
    }

    public function saveSingle(): ?array
    {
        $this->validate([
            'name' => ['required', 'string', 'max:191', Rule::unique('units', 'name')->where('company_id', \App\Support\ActiveCompany::check())],
            'symbol' => ['nullable', 'string', 'max:20'],
            'decimal_places' => ['required', 'integer', 'min:0', 'max:6'],
        ]);

        $unit = Unit::create([
            'name' => trim($this->name),
            'symbol' => $this->symbol ? trim($this->symbol) : null,
            'decimal_places' => (int) $this->decimal_places,
        ]);

        $this->reset('name', 'symbol');
        $this->decimal_places = '0';

        return $unit->toCache();
    }

    public function saveMulti(): array
    {
        $seen = [];
        $v = \Illuminate\Support\Facades\Validator::make([], []);
        foreach ($this->rows as $i => $row) {
            $rawName = trim((string) ($row['name'] ?? ''));
            if ($rawName === '') {
                continue;
            }
            $key = mb_strtolower($rawName);
            if (isset($seen[$key])) {
                $v->errors()->add("rows.$i.name", "“{$rawName}” is duplicated in this batch.");

                continue;
            }
            if (Unit::whereRaw('LOWER(name) = ?', [$key])->exists()) {
                $v->errors()->add("rows.$i.name", "“{$rawName}” already exists.");
            }
            $dp = (string) ($row['decimals'] ?? '0');
            if ($dp !== '' && (! ctype_digit($dp) || (int) $dp > 6)) {
                $v->errors()->add("rows.$i.decimals", 'Decimals must be 0–6.');
            }
            $seen[$key] = true;
        }
        if (empty($seen)) {
            $v->errors()->add('rows.0.name', 'Enter at least one unit name.');
        }
        if ($v->errors()->isNotEmpty()) {
            throw new ValidationException($v);
        }

        $created = DB::transaction(function () {
            $made = [];
            foreach ($this->rows as $row) {
                $rawName = trim((string) ($row['name'] ?? ''));
                if ($rawName === '') {
                    continue;
                }
                $unit = Unit::create([
                    'name' => $rawName,
                    'symbol' => ($row['symbol'] ?? '') !== '' ? trim($row['symbol']) : null,
                    'decimal_places' => (int) (($row['decimals'] ?? '0') === '' ? 0 : $row['decimals']),
                ]);
                $made[] = $unit->toCache();
            }

            return $made;
        });

        $this->rows = array_fill(0, 10, ['name' => '', 'symbol' => '', 'decimals' => '0']);

        return $created;
    }

    public function loadForAlter(int $id): void
    {
        $u = Unit::findOrFail($id);
        $this->alter_id = $u->id;
        $this->u_name = $u->name;
        $this->u_symbol = $u->symbol;
        $this->u_decimals = (string) $u->decimal_places;
        $this->resetErrorBag();
    }

    public function saveAlter(): ?array
    {
        $u = Unit::findOrFail($this->alter_id);
        $this->validate([
            'u_name' => ['required', 'string', 'max:191', Rule::unique('units', 'name')->where('company_id', \App\Support\ActiveCompany::check())->ignore($u->id)],
            'u_symbol' => ['nullable', 'string', 'max:20'],
            'u_decimals' => ['required', 'integer', 'min:0', 'max:6'],
        ], [], ['u_name' => 'name']);

        $u->update([
            'name' => trim($this->u_name),
            'symbol' => $this->u_symbol ? trim($this->u_symbol) : null,
            'decimal_places' => (int) $this->u_decimals,
        ]);

        return $u->fresh()->toCache();
    }

    public function deleteMaster(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $u = Unit::lockForUpdate()->find($id);
            if (! $u) {
                return ['ok' => false, 'message' => 'Unit not found.'];
            }
            if (\App\Models\StockItem::where('unit_id', $u->id)->exists()) {
                return ['ok' => false, 'message' => 'Unit is used by stock items — cannot delete.'];
            }
            $u->delete();

            return ['ok' => true, 'message' => 'Deleted.'];
        });
    }

    public function render()
    {
        return view('livewire.inventory.unit-workspace');
    }
}
