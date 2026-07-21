<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Livewire\Concerns\TogglesMasterActive;
use App\Models\TdsDeduction;
use App\Models\TdsSection;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Phase 10A — the TDS Sections master (F11-adjacent).
 *
 * This screen is what makes the phase future-proof. Rates and thresholds change with
 * every Finance Act, and the section codes themselves changed wholesale when the Income
 * Tax Act 2025 replaced the 194-series with Section 393. None of that is in the code:
 * the user adds a section, edits a rate, or expires an old one by setting `effective_to`
 * — and the engine simply reads the table.
 *
 * A section is never deleted once a voucher has deducted under it; it is EXPIRED, so the
 * historical vouchers keep their section and future ones stop seeing it.
 */
class TdsSectionWorkspace extends Component
{
    use GuardsActiveCompany;
    use TogglesMasterActive;

    // Create
    public string $code = '';
    public string $label = '';
    public ?string $rate = null;
    public ?string $rate_company = null;
    public ?string $no_pan_rate = null;
    public ?string $threshold_single = null;
    public ?string $threshold_annual = null;
    public string $threshold_period = 'annual';
    public string $deduct_basis = 'aggregate';
    public ?string $effective_from = null;
    public ?string $effective_to = null;
    public ?string $notes = null;

    // Alter
    public ?int $alter_id = null;
    public string $a_code = '';
    public string $a_label = '';
    public ?string $a_rate = null;
    public ?string $a_rate_company = null;
    public ?string $a_no_pan_rate = null;
    public ?string $a_threshold_single = null;
    public ?string $a_threshold_annual = null;
    public string $a_threshold_period = 'annual';
    public string $a_deduct_basis = 'aggregate';
    public ?string $a_effective_from = null;
    public ?string $a_effective_to = null;
    public ?string $a_notes = null;

    public function mount(): void
    {
        $this->effective_from = (string) Voucher::statutoryFyStartFor(Carbon::today());
    }

    /** Every section, in force or not — the master screen shows the whole catalog. */
    public function sectionCache(): array
    {
        return TdsSection::orderBy('effective_from')->orderBy('code')->get()
            ->map(fn (TdsSection $s) => array_merge($s->toCache(), [
                'in_force' => $s->isEffectiveFor(Voucher::statutoryFyStartFor(Carbon::today())),
                'effective_label' => $s->effectiveLabel(),
                'threshold_label' => $s->thresholdLabel(),
                'notes' => $s->notes,
                'usage' => TdsDeduction::where('tds_section_id', $s->id)->count(),
            ]))->all();
    }

    /** The current fiscal year, so the screen can say which sections are live. */
    public function currentFy(): array
    {
        $fy = Voucher::statutoryFyStartFor(Carbon::today());

        return ['start' => $fy, 'label' => Voucher::statutoryFyLabel($fy)];
    }

    private function rules(?int $ignoreId = null, string $p = ''): array
    {
        return [
            $p.'code' => [
                'required', 'string', 'max:30',
                Rule::unique('tds_sections', 'code')->where('company_id', \App\Support\ActiveCompany::check())
                    ->where(fn ($q) => $q->where('effective_from', (int) $this->{$p.'effective_from'}))
                    ->ignore($ignoreId),
            ],
            $p.'label' => ['required', 'string', 'max:191'],
            $p.'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            $p.'rate_company' => ['nullable', 'numeric', 'min:0', 'max:100'],
            $p.'no_pan_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            $p.'threshold_single' => ['nullable', 'numeric', 'min:0'],
            $p.'threshold_annual' => ['nullable', 'numeric', 'min:0'],
            $p.'threshold_period' => ['required', Rule::in(['annual', 'monthly'])],
            $p.'deduct_basis' => ['required', Rule::in(['aggregate', 'excess'])],
            $p.'effective_from' => ['required', 'integer', 'min:1990', 'max:2100'],
            $p.'effective_to' => ['nullable', 'integer', 'min:1990', 'max:2100', 'gte:'.$p.'effective_from'],
            $p.'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private const MESSAGES = [
        'code.unique' => 'A section with this code already exists for that fiscal year. Give it a different starting year to re-date it.',
        'a_code.unique' => 'A section with this code already exists for that fiscal year.',
        'effective_to.gte' => 'A section cannot expire before it starts.',
        'a_effective_to.gte' => 'A section cannot expire before it starts.',
    ];

    private function payload(string $p = ''): array
    {
        $num = fn (string $k) => $this->{$p.$k} === null || $this->{$p.$k} === '' ? null : (float) $this->{$p.$k};

        return [
            'code' => trim($this->{$p.'code'}),
            'label' => trim($this->{$p.'label'}),
            'rate' => (float) $this->{$p.'rate'},
            'rate_company' => $num('rate_company'),
            'no_pan_rate' => $num('no_pan_rate'),
            'threshold_single' => $num('threshold_single'),
            'threshold_annual' => $num('threshold_annual'),
            'threshold_period' => $this->{$p.'threshold_period'},
            'deduct_basis' => $this->{$p.'deduct_basis'},
            'effective_from' => (int) $this->{$p.'effective_from'},
            'effective_to' => $this->{$p.'effective_to'} === null || $this->{$p.'effective_to'} === '' ? null : (int) $this->{$p.'effective_to'},
            'notes' => $this->{$p.'notes'} ?: null,
        ];
    }

    public function saveSingle(): ?array
    {
        $this->validate($this->rules(), self::MESSAGES);

        $s = TdsSection::create($this->payload());

        $this->reset([
            'code', 'label', 'rate', 'rate_company', 'no_pan_rate',
            'threshold_single', 'threshold_annual', 'threshold_period', 'deduct_basis',
            'effective_to', 'notes',
        ]);
        $this->effective_from = (string) Voucher::statutoryFyStartFor(Carbon::today());

        return $s->toCache();
    }

    public function loadForAlter(int $id): void
    {
        $s = TdsSection::findOrFail($id);
        $this->alter_id = $s->id;
        $this->a_code = $s->code;
        $this->a_label = $s->label;
        $this->a_rate = (string) $s->rate;
        $this->a_rate_company = $s->rate_company !== null ? (string) $s->rate_company : null;
        $this->a_no_pan_rate = $s->no_pan_rate !== null ? (string) $s->no_pan_rate : null;
        $this->a_threshold_single = $s->threshold_single !== null ? (string) $s->threshold_single : null;
        $this->a_threshold_annual = $s->threshold_annual !== null ? (string) $s->threshold_annual : null;
        $this->a_threshold_period = $s->threshold_period;
        $this->a_deduct_basis = $s->deduct_basis;
        $this->a_effective_from = (string) $s->effective_from;
        $this->a_effective_to = $s->effective_to !== null ? (string) $s->effective_to : null;
        $this->a_notes = $s->notes;
        $this->resetErrorBag();
    }

    public function saveAlter(): ?array
    {
        $s = TdsSection::findOrFail($this->alter_id);
        $this->validate($this->rules($s->id, 'a_'), self::MESSAGES);

        $s->update($this->payload('a_'));

        return $s->fresh()->toCache();
    }

    /**
     * Expire a section as of the end of the previous fiscal year. This is what you do to a
     * repealed section: it disappears from every picker, while the vouchers that already
     * deducted under it keep pointing at it and still report correctly.
     */
    public function expireSection(int $id): array
    {
        $s = TdsSection::find($id);
        if (! $s) {
            return ['ok' => false, 'message' => 'Section not found.'];
        }
        $fy = Voucher::statutoryFyStartFor(Carbon::today());
        if (! $s->isEffectiveFor($fy)) {
            return ['ok' => false, 'message' => $s->code.' is already out of force ('.$s->effectiveLabel().').'];
        }
        if ($s->effective_from > $fy - 1) {
            return ['ok' => false, 'message' => $s->code.' starts this year — delete it instead, or set an end year by hand.'];
        }
        $s->update(['effective_to' => $fy - 1]);

        return ['ok' => true, 'message' => $s->code.' expired at the end of FY '.Voucher::statutoryFyLabel($fy - 1), 'record' => $s->fresh()->toCache()];
    }

    /**
     * Delete a section outright. Refused once any voucher has deducted under it — the
     * deduction record must always be able to name its section. Expire it instead.
     */
    public function deleteMaster(int $id): array
    {
        $s = TdsSection::find($id);
        if (! $s) {
            return ['ok' => false, 'message' => 'Section not found.'];
        }
        $used = TdsDeduction::where('tds_section_id', $s->id)->count();
        if ($used > 0) {
            return ['ok' => false, 'message' => $s->code.' has '.$used.' deduction'.($used === 1 ? '' : 's').' against it and cannot be deleted. Expire it instead.'];
        }
        if (\App\Models\Ledger::where('default_tds_section_id', $s->id)->exists()) {
            return ['ok' => false, 'message' => $s->code.' is the default section for one or more deductees. Clear it there first.'];
        }
        $s->delete();

        return ['ok' => true, 'message' => 'Deleted '.$s->code];
    }

    public function render()
    {
        return view('livewire.tds-section-workspace');
    }

    /** The model TogglesMasterActive retires and restores. */
    protected function masterModelClass(): string
    {
        return \App\Models\TdsSection::class;
    }
}
