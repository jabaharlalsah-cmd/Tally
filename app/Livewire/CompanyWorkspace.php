<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\Company;
use App\Models\Voucher;
use App\Services\CompanyProvisioner;
use App\Support\ActiveCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Phase 12A — the Companies master (Create / Create Multiple / Display / Alter /
 * Deactivate). The Company model is deliberately UNSCOPED (it is the registry the
 * picker reads), so this is the one workspace that lists across companies.
 *
 * Create seeds the full chart via CompanyProvisioner (28 groups, Cash/P&L, duty
 * ledgers, TDS catalog, INR, forex ledgers, Main Location, fresh all-off F11).
 * Alt+D DEACTIVATES — a company is never deleted from a list screen; its books
 * stay intact and it can be reactivated from the Alter form. The ACTIVE company
 * and the LAST active company cannot be deactivated.
 *
 * financial_year_start_month is locked once the company has vouchers: changing
 * it would re-bucket historical fy_start values and collide voucher numbering.
 */
class CompanyWorkspace extends Component
{
    use GuardsActiveCompany;

    // Single create
    public string $name = '';
    public ?string $slug = null;

    // Multiple create — [{name}]
    public array $rows = [];

    // Alter
    public ?int $alter_id = null;
    public string $c_name = '';
    public string $c_slug = '';
    public string $c_fy_month = '4';
    public bool $c_is_active = true;

    public function mount(): void
    {
        $this->rows = array_fill(0, 6, ['name' => '']);
    }

    public function cache(): array
    {
        // Voucher counts per company, deliberately WITHOUT the company scope —
        // this management screen is the one legitimate cross-company reader
        // (it drives the "FY month locked" rule and the inactive tag).
        $counts = Voucher::withoutGlobalScope('company')
            ->selectRaw('company_id, COUNT(*) AS c')->groupBy('company_id')->pluck('c', 'company_id');

        return Company::orderBy('name')->get()->map(function (Company $c) use ($counts) {
            return array_merge($c->toCache(), [
                'has_vouchers' => (int) ($counts[$c->id] ?? 0) > 0,
                'is_current' => $c->id === ActiveCompany::id(),
            ]);
        })->all();
    }

    public function saveSingle(): ?array
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('companies', 'name')],
            'slug' => ['nullable', 'string', 'max:60'],
        ]);

        $company = app(CompanyProvisioner::class)->create(trim($this->name), $this->slug ? trim($this->slug) : null);

        $this->reset('name', 'slug');

        return array_merge($company->toCache(), ['has_vouchers' => false, 'is_current' => false]);
    }

    public function saveMulti(): array
    {
        $seen = [];
        $v = Validator::make([], []);
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
            if (Company::whereRaw('LOWER(name) = ?', [$key])->exists()) {
                $v->errors()->add("rows.$i.name", "“{$rawName}” already exists.");
            }
            $seen[$key] = true;
        }
        if (empty($seen)) {
            $v->errors()->add('rows.0.name', 'Enter at least one company name.');
        }
        if ($v->errors()->isNotEmpty()) {
            throw new ValidationException($v);
        }

        $provisioner = app(CompanyProvisioner::class);
        $made = [];
        foreach ($this->rows as $row) {
            $rawName = trim((string) ($row['name'] ?? ''));
            if ($rawName === '') {
                continue;
            }
            $company = $provisioner->create($rawName);
            $made[] = array_merge($company->toCache(), ['has_vouchers' => false, 'is_current' => false]);
        }

        $this->rows = array_fill(0, 6, ['name' => '']);

        return $made;
    }

    public function loadForAlter(int $id): void
    {
        $c = Company::findOrFail($id);
        $this->alter_id = $c->id;
        $this->c_name = $c->name;
        $this->c_slug = $c->slug;
        $this->c_fy_month = (string) $c->financial_year_start_month;
        $this->c_is_active = (bool) $c->is_active;
        $this->resetErrorBag();
    }

    public function saveAlter(): ?array
    {
        $c = Company::findOrFail($this->alter_id);

        $this->validate([
            'c_name' => ['required', 'string', 'max:120', Rule::unique('companies', 'name')->ignore($c->id)],
            'c_slug' => ['required', 'string', 'max:60', Rule::unique('companies', 'slug')->ignore($c->id)],
            'c_fy_month' => ['required', 'integer', 'min:1', 'max:12'],
        ], [], ['c_name' => 'name', 'c_slug' => 'slug', 'c_fy_month' => 'FY start month']);

        $hasVouchers = Voucher::withoutGlobalScope('company')->where('company_id', $c->id)->exists();

        if ($hasVouchers && (int) $this->c_fy_month !== (int) $c->financial_year_start_month) {
            $this->addError('c_fy_month', 'The FY start month is locked once the company has vouchers — historical numbering was derived under it.');

            return null;
        }

        if (! $this->c_is_active && $c->is_active) {
            if ($guard = $this->deactivationGuard($c)) {
                $this->addError('c_is_active', $guard);

                return null;
            }
        }

        $c->update([
            'name' => trim($this->c_name),
            'slug' => trim($this->c_slug),
            'financial_year_start_month' => (int) $this->c_fy_month,
            'is_active' => $this->c_is_active,
        ]);
        ActiveCompany::refresh();

        return array_merge($c->fresh()->toCache(), [
            'has_vouchers' => $hasVouchers,
            'is_current' => $c->id === ActiveCompany::id(),
        ]);
    }

    /** Alt+D — DEACTIVATE (books preserved; reactivate from Alter). */
    public function deleteMaster(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $c = Company::lockForUpdate()->find($id);
            if (! $c) {
                return ['ok' => false, 'message' => 'Company not found.'];
            }
            if (! $c->is_active) {
                return ['ok' => false, 'message' => 'Already deactivated.'];
            }
            if ($guard = $this->deactivationGuard($c)) {
                return ['ok' => false, 'message' => $guard];
            }

            $c->update(['is_active' => false]);

            return ['ok' => true, 'message' => 'Deactivated.', 'record' => array_merge(
                $c->fresh()->toCache(), ['has_vouchers' => true, 'is_current' => false],
            )];
        });
    }

    private function deactivationGuard(Company $c): ?string
    {
        if ($c->id === ActiveCompany::id()) {
            return 'This is the company you are working in — switch companies (F3) first.';
        }
        if (Company::where('is_active', true)->where('id', '!=', $c->id)->doesntExist()) {
            return 'A tenant needs at least one active company.';
        }

        return null;
    }

    public function render()
    {
        return view('livewire.company-workspace');
    }
}
