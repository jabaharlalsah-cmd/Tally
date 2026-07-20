<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Models\CompanyFeature;
use App\Support\PlanGate;
use Livewire\Component;

class FeaturesScreen extends Component
{
    use GuardsActiveCompany;

    public bool $bill_by_bill = false;
    public bool $cost_centres = false;
    public bool $gst = false;
    public bool $vat = false;
    // Phase 10A — TDS is deliberately NOT part of the GST/VAT mutual exclusion below:
    // it is a withholding obligation, not a sales-tax regime, and an Indian company
    // deducts tax at source whether or not it is GST-registered.
    public bool $tds = false;
    public bool $multi_currency = false;
    // Phase 15A — Budgets is orthogonal to the tax regime, like TDS.
    public bool $budgets = false;
    // Phase 15B — Ratio Analysis, likewise orthogonal.
    public bool $ratio_analysis = false;
    // Phase 15C — Scenarios (provisional what-if vouchers).
    public bool $scenarios = false;
    // Company GST profile (drives intra/inter determination on every invoice).
    public ?string $company_gstin = null;
    public ?string $company_state = null;
    // Company VAT profile (Nepal) — a single flat rate, no state concept.
    public ?string $company_pan = null;

    // Phase 10B — the deductor's Form 26Q filing identity. Reused as the tenant's Indian
    // PAN via company_pan; the rest are the TAN + address + responsible-person block a 26Q
    // return needs. Optional to set, but the 26Q exporter refuses without them.
    public array $deductor = [
        'company_tan' => null, 'deductor_name' => null, 'deductor_address1' => null,
        'deductor_address2' => null, 'deductor_state_code' => null, 'deductor_pincode' => null,
        'deductor_email' => null, 'deductor_phone' => null, 'deductor_type' => null,
        'resp_name' => null, 'resp_designation' => null, 'resp_pan' => null, 'resp_address1' => null,
        'resp_state_code' => null, 'resp_pincode' => null, 'resp_email' => null, 'resp_phone' => null,
    ];

    // Phase 7B — plan gating. The features the tenant's plan does NOT unlock (their
    // toggles are disabled in the UI) and the plan name, for the "upgrade" message.
    public array $lockedFeatures = [];
    public ?string $planName = null;

    public function mount(): void
    {
        $f = CompanyFeature::current();
        $company = activeCompany();
        $this->bill_by_bill = (bool) $f->bill_by_bill;
        $this->cost_centres = (bool) $f->cost_centres;
        $this->gst = (bool) $f->gst;
        $this->vat = (bool) $f->vat;
        $this->tds = (bool) $f->tds;
        $this->multi_currency = (bool) $f->multi_currency;
        $this->budgets = (bool) $f->budgets;
        $this->ratio_analysis = (bool) $f->ratio_analysis;
        $this->scenarios = (bool) $f->scenarios;
        // Phase 12A — identity lives on the ACTIVE company's row now; the F11 form
        // still edits it (same fields, same blade), it just reads/writes companies.
        $this->company_gstin = $company?->gstin;
        $this->company_state = $company?->state;
        $this->company_pan = $company?->pan;
        foreach (array_keys($this->deductor) as $k) {
            $this->deductor[$k] = $k === 'company_tan' ? $company?->tan : $f->{$k};
        }

        $this->lockedFeatures = PlanGate::lockedFeatures();
        $this->planName = PlanGate::planName();
    }

    public function save(): array
    {
        // GST and VAT are mutually exclusive — the client keeps only one on, and
        // the server enforces it too as a hard safety net.
        if ($this->gst && $this->vat) {
            $this->addError('regime', 'GST and VAT are mutually exclusive — enable only one.');

            return [];
        }

        // Phase 7B — plan gate is the SECURITY BOUNDARY. Reject enabling any feature
        // the tenant's plan doesn't unlock, regardless of what the (UI-disabled)
        // toggle or a crafted request sends. The screen's disabled state is UX only.
        $enabled = array_keys(array_filter([
            'gst' => $this->gst, 'vat' => $this->vat, 'tds' => $this->tds, 'bill_by_bill' => $this->bill_by_bill,
            'cost_centres' => $this->cost_centres, 'multi_currency' => $this->multi_currency,
        ]));
        if ($violation = PlanGate::violation($enabled)) {
            $this->addError('plan', $violation);

            return [];
        }

        $this->validate([
            'company_gstin' => ['nullable', 'string', 'max:20'],
            'company_state' => [$this->gst ? 'required' : 'nullable', 'string', 'max:100'],
            'company_pan' => ['nullable', 'string', 'max:30'],
        ], [
            'company_state.required' => 'Set the company state — it decides intra- vs inter-state GST.',
        ], ['company_state' => 'company state']);

        // Phase 10B — the deductor profile fields (uppercase the TAN/PANs; the 26Q
        // exporter validates their exact format at export time, so we store leniently).
        $deductorUpdate = [];
        foreach ($this->deductor as $k => $v) {
            $v = is_string($v) ? trim($v) : $v;
            if (in_array($k, ['company_tan', 'resp_pan'], true) && $v) {
                $v = strtoupper($v);
            }
            $deductorUpdate[$k] = $v ?: null;
        }

        // Phase 12A — the split write: flags + the 26Q address/responsible-person
        // block stay on the company's F11 row; identity goes to the companies row.
        $tan = $deductorUpdate['company_tan'] ?? null;
        unset($deductorUpdate['company_tan']);

        $f = CompanyFeature::current();
        $f->update(array_merge([
            'bill_by_bill' => $this->bill_by_bill,
            'cost_centres' => $this->cost_centres,
            'gst' => $this->gst,
            'vat' => $this->vat,
            'tds' => $this->tds,
            'multi_currency' => $this->multi_currency,
            'budgets' => $this->budgets,
            'ratio_analysis' => $this->ratio_analysis,
            'scenarios' => $this->scenarios,
        ], $deductorUpdate));

        activeCompany()->update([
            'gstin' => $this->company_gstin ? trim($this->company_gstin) : null,
            'state' => $this->company_state ? trim($this->company_state) : null,
            'pan' => $this->company_pan ? strtoupper(trim($this->company_pan)) : null,
            'tan' => $tan,
        ]);
        \App\Support\ActiveCompany::refresh(); // drop the memoised identity row

        return $f->toFlags();
    }

    public function render()
    {
        return view('livewire.features-screen');
    }
}
