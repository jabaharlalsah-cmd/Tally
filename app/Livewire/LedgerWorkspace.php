<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Livewire\Concerns\TogglesMasterActive;
use App\Livewire\Concerns\CreatesGroups;
use App\Models\AccountGroup;
use App\Models\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Component;

class LedgerWorkspace extends Component
{
    use GuardsActiveCompany;
    use TogglesMasterActive;
    use CreatesGroups; // inline Alt+C "create group" from the Under picker

    // Single create
    public string $name = '';
    public ?string $alias = null;
    public ?int $group_id = null;
    public string $group_label = '';
    public string $opening_balance = '';
    public ?string $opening_balance_type = 'Dr';
    // Mailing / registration block (all optional, always visible)
    public ?string $mailing_name = null;
    public ?string $address = null;
    public ?string $state = null;
    public string $country = 'India';
    public ?string $pincode = null;
    public ?string $pan = null;
    public ?string $gstin = null;
    // GST (F11-gated): rate + HSN/SAC on nominal ledgers; registration type on parties.
    public ?string $gst_rate = null;
    public ?string $hsn_sac = null;
    public ?string $gst_registration_type = null;
    public bool $maintain_bill_by_bill = false; // F11-gated (shown only when the feature is on)
    public bool $cost_centres_applicable = false; // F11-gated (Phase 5D)
    // TDS deductee tagging (F11-gated, Phase 10A). `deductee_pan` is deliberately distinct
    // from `pan` above: it is the PAN quoted on a TDS return, and its ABSENCE is what
    // triggers the Section 206AA rate. Only this field drives the deduction.
    public ?string $deductee_pan = null;
    public ?string $deductee_type = null;
    public ?int $default_tds_section_id = null;
    public string $default_tds_section_label = '';
    // Phase 12B - this party IS another company in the tenant (inter-company link).
    // Only offered when the active company is in a group; only valid on
    // party-tracking groups; the tag enforcement keys on it at post time.
    public ?int $linked_company_id = null;

    // Multiple create
    public ?int $multi_group_id = null;
    public string $multi_group_label = '';
    /** @var array<int,array{name:string,opening:string,type:string}> */
    public array $rows = [];

    // Alter
    public ?int $alter_id = null;
    public string $l_name = '';
    public ?string $l_alias = null;
    public ?int $l_group_id = null;
    public string $l_group_label = '';
    public string $l_opening = '';
    public ?string $l_type = 'Dr';
    public ?string $l_mailing_name = null;
    public ?string $l_address = null;
    public ?string $l_state = null;
    public string $l_country = 'India';
    public ?string $l_pincode = null;
    public ?string $l_pan = null;
    public ?string $l_gstin = null;
    public ?string $l_gst_rate = null;
    public ?string $l_hsn_sac = null;
    public ?string $l_gst_registration_type = null;
    public bool $l_maintain_bill_by_bill = false;
    public bool $l_cost_centres_applicable = false;
    public ?string $l_deductee_pan = null;
    public ?string $l_deductee_type = null;
    public ?int $l_default_tds_section_id = null;
    public string $l_default_tds_section_label = '';
    public ?int $l_linked_company_id = null; // Phase 12B
    public bool $l_is_reserved = false;
    public bool $l_is_pl = false;

    public function mount(): void
    {
        $this->rows = array_fill(0, 10, ['name' => '', 'opening' => '', 'type' => 'Dr']);
    }

    public function mastersCache(): array
    {
        return [
            'groups' => AccountGroup::orderBy('name')->get()->map->toCache()->all(),
            'ledgers' => Ledger::with('group')->orderBy('name')->get()->map->toCache()->all(),
            // Phase 10A — the sections in force this fiscal year, for the deductee's
            // "default TDS section" picker. A repealed section is never offered as a
            // default: it could only produce vouchers the server would refuse.
            'tdsSections' => app(\App\Services\TdsService::class)->sections(),
            // Phase 12B - the groupmate companies a party ledger may be linked to
            // ([] when ungrouped, which hides the field entirely).
            'linkableCompanies' => collect((array) app(\App\Services\InterCompanyService::class)->bootData()['interCompanyCompanyNames'])
                ->map(fn ($name, $id) => ['id' => (int) $id, 'name' => $name])->values()->all(),
        ];
    }

    public function saveSingle(): ?array
    {
        $this->validate([
            'name' => ['required', 'string', 'max:191', Rule::unique('ledgers', 'name')->where('company_id', \App\Support\ActiveCompany::check())],
            'group_id' => ['required', 'integer', Rule::exists('account_groups', 'id')->where('company_id', \App\Support\ActiveCompany::check())],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'opening_balance_type' => [Rule::requiredIf(fn () => (float) $this->opening_balance > 0), 'nullable', Rule::in(['Dr', 'Cr'])],
            'pan' => ['nullable', 'string', 'max:20'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'hsn_sac' => ['nullable', 'string', 'max:20'],
            'gst_registration_type' => ['nullable', 'string', 'max:30'],
            'deductee_pan' => ['nullable', 'string', 'max:10'],
            'deductee_type' => ['nullable', Rule::in(['individual_huf', 'company_firm_llp', 'other'])],
            'default_tds_section_id' => ['nullable', 'integer', Rule::exists('tds_sections', 'id')->where('company_id', \App\Support\ActiveCompany::check())],
            'linked_company_id' => ['nullable', 'integer'],
        ], [], ['group_id' => 'under', 'default_tds_section_id' => 'default TDS section']);

        // Phase 12B - a link needs a group, a groupmate target, and a party-tracking
        // group. The service is the single authority for all three rules.
        if ($icError = app(\App\Services\InterCompanyService::class)->assertLinkable($this->linked_company_id, $this->group_id)) {
            $this->addError('linked_company_id', $icError);

            return null;
        }

        $opening = (float) ($this->opening_balance === '' ? 0 : $this->opening_balance);

        $ledger = Ledger::create([
            'name' => trim($this->name),
            'alias' => $this->alias ? trim($this->alias) : null,
            'group_id' => $this->group_id,
            'opening_balance' => $opening,
            'opening_balance_type' => $opening > 0 ? $this->opening_balance_type : null,
            'mailing_name' => $this->mailing_name,
            'address' => $this->address,
            'state' => $this->state,
            'country' => $this->country ?: 'India',
            'pincode' => $this->pincode,
            'pan' => $this->pan,
            'gstin' => $this->gstin,
            'gst_rate' => ($this->gst_rate === null || $this->gst_rate === '') ? null : (float) $this->gst_rate,
            'hsn_sac' => $this->hsn_sac ?: null,
            'gst_registration_type' => $this->gst_registration_type ?: null,
            'maintain_bill_by_bill' => $this->maintain_bill_by_bill,
            'cost_centres_applicable' => $this->cost_centres_applicable,
            'deductee_pan' => $this->deductee_pan ? strtoupper(trim($this->deductee_pan)) : null,
            'deductee_type' => $this->deductee_type ?: null,
            'default_tds_section_id' => $this->default_tds_section_id ?: null,
            'linked_company_id' => $this->linked_company_id ?: null, // Phase 12B
        ]);

        $this->reset('name', 'alias', 'group_id', 'group_label', 'opening_balance', 'mailing_name', 'address', 'state', 'pincode', 'pan', 'gstin', 'gst_rate', 'hsn_sac', 'gst_registration_type', 'maintain_bill_by_bill', 'cost_centres_applicable', 'deductee_pan', 'deductee_type', 'default_tds_section_id', 'default_tds_section_label', 'linked_company_id');
        $this->opening_balance_type = 'Dr';
        $this->country = 'India';

        return $ledger->load('group')->toCache();
    }

    public function saveMulti(): array
    {
        Validator::make(
            ['multi_group_id' => $this->multi_group_id],
            ['multi_group_id' => ['required', 'integer', Rule::exists('account_groups', 'id')->where('company_id', \App\Support\ActiveCompany::check())]],
            [],
            ['multi_group_id' => 'under']
        )->validate();

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
            if (Ledger::whereRaw('LOWER(name) = ?', [$key])->exists()) {
                $v->errors()->add("rows.$i.name", "“{$rawName}” already exists.");
            }
            $opening = (string) ($row['opening'] ?? '');
            if ($opening !== '' && ! is_numeric($opening)) {
                $v->errors()->add("rows.$i.opening", 'Must be a number.');
            } elseif ($opening !== '' && (float) $opening < 0) {
                $v->errors()->add("rows.$i.opening", 'Must be zero or positive.');
            }
            // Dr/Cr side is required and must be valid whenever there is a balance.
            if ((float) ($opening === '' ? 0 : $opening) > 0 && ! in_array((string) ($row['type'] ?? ''), ['Dr', 'Cr'], true)) {
                $v->errors()->add("rows.$i.type", 'Choose Dr or Cr.');
            }
            $seen[$key] = true;
        }
        if (empty($seen)) {
            $v->errors()->add('rows.0.name', 'Enter at least one ledger name.');
        }
        if ($v->errors()->isNotEmpty()) {
            throw new \Illuminate\Validation\ValidationException($v);
        }

        $created = DB::transaction(function () {
            $made = [];
            foreach ($this->rows as $row) {
                $rawName = trim((string) ($row['name'] ?? ''));
                if ($rawName === '') {
                    continue;
                }
                $opening = (float) (($row['opening'] ?? '') === '' ? 0 : $row['opening']);
                $ledger = Ledger::create([
                    'name' => $rawName,
                    'group_id' => $this->multi_group_id,
                    'opening_balance' => $opening,
                    'opening_balance_type' => $opening > 0 ? (($row['type'] ?? 'Dr') ?: 'Dr') : null,
                    'country' => 'India',
                ]);
                $made[] = $ledger->load('group')->toCache();
            }

            return $made;
        });

        $this->reset('multi_group_id', 'multi_group_label');
        $this->rows = array_fill(0, 10, ['name' => '', 'opening' => '', 'type' => 'Dr']);

        return $created;
    }

    public function loadForAlter(int $id): void
    {
        $l = Ledger::with('group')->findOrFail($id);
        $this->alter_id = $l->id;
        $this->l_name = $l->name;
        $this->l_alias = $l->alias;
        $this->l_group_id = $l->group_id;
        $this->l_group_label = $l->group?->name ?? ($l->is_pl_account ? '⌂ Primary' : '');
        $this->l_opening = $l->opening_balance > 0 ? (string) $l->opening_balance : '';
        $this->l_type = $l->opening_balance_type ?: 'Dr';
        $this->l_mailing_name = $l->mailing_name;
        $this->l_address = $l->address;
        $this->l_state = $l->state;
        $this->l_country = $l->country ?: 'India';
        $this->l_pincode = $l->pincode;
        $this->l_pan = $l->pan;
        $this->l_gstin = $l->gstin;
        $this->l_gst_rate = $l->gst_rate !== null ? (string) (float) $l->gst_rate : null;
        $this->l_hsn_sac = $l->hsn_sac;
        $this->l_gst_registration_type = $l->gst_registration_type;
        $this->l_maintain_bill_by_bill = (bool) $l->maintain_bill_by_bill;
        $this->l_cost_centres_applicable = (bool) $l->cost_centres_applicable;
        $this->l_deductee_pan = $l->deductee_pan;
        $this->l_deductee_type = $l->deductee_type;
        $this->l_default_tds_section_id = $l->default_tds_section_id;
        $this->l_default_tds_section_label = $l->defaultTdsSection?->displayLabel() ?? '';
        $this->l_linked_company_id = $l->linked_company_id; // Phase 12B
        $this->l_is_reserved = (bool) $l->is_reserved;
        $this->l_is_pl = (bool) $l->is_pl_account;
        $this->resetErrorBag();
    }

    public function saveAlter(): ?array
    {
        $l = Ledger::findOrFail($this->alter_id);

        $rules = [
            'l_opening' => ['nullable', 'numeric', 'min:0'],
            'l_type' => [Rule::requiredIf(fn () => (float) $this->l_opening > 0), 'nullable', Rule::in(['Dr', 'Cr'])],
            'l_gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'l_hsn_sac' => ['nullable', 'string', 'max:20'],
            'l_gst_registration_type' => ['nullable', 'string', 'max:30'],
            'l_deductee_pan' => ['nullable', 'string', 'max:10'],
            'l_deductee_type' => ['nullable', Rule::in(['individual_huf', 'company_firm_llp', 'other'])],
            'l_default_tds_section_id' => ['nullable', 'integer', Rule::exists('tds_sections', 'id')->where('company_id', \App\Support\ActiveCompany::check())],
            'l_linked_company_id' => ['nullable', 'integer'],
        ];
        // Reserved ledgers keep their name AND their group (moving Cash to another
        // group would change its nature); the P&L A/c keeps its special (null) group.
        $canEditGroup = ! $l->is_pl_account && ! $l->is_reserved;
        if (! $l->is_reserved) {
            $rules['l_name'] = ['required', 'string', 'max:191', Rule::unique('ledgers', 'name')->where('company_id', \App\Support\ActiveCompany::check())->ignore($l->id)];
        }
        if ($canEditGroup) {
            $rules['l_group_id'] = ['required', 'integer', Rule::exists('account_groups', 'id')->where('company_id', \App\Support\ActiveCompany::check())];
        }
        $this->validate($rules, [], ['l_group_id' => 'under', 'l_name' => 'name']);

        // Phase 12B - same three linkability rules as create (group, groupmate, party
        // group) — but ONLY when the link is being SET or CHANGED. An UNCHANGED link
        // whose group has since been deleted is documented-inert; re-asserting it here
        // would freeze every other field of the ledger behind an invisible error.
        $icGroupId = $canEditGroup ? $this->l_group_id : $l->group_id;
        $linkChanged = (int) ($this->l_linked_company_id ?? 0) !== (int) ($l->linked_company_id ?? 0);
        if ($linkChanged && ($icError = app(\App\Services\InterCompanyService::class)->assertLinkable($this->l_linked_company_id, $icGroupId))) {
            $this->addError('l_linked_company_id', $icError);

            return null;
        }

        $opening = (float) ($this->l_opening === '' ? 0 : $this->l_opening);

        $payload = [
            'alias' => $this->l_alias ? trim($this->l_alias) : null,
            'opening_balance' => $opening,
            'opening_balance_type' => $opening > 0 ? $this->l_type : null,
            'mailing_name' => $this->l_mailing_name,
            'address' => $this->l_address,
            'state' => $this->l_state,
            'country' => $this->l_country ?: 'India',
            'pincode' => $this->l_pincode,
            'pan' => $this->l_pan,
            'gstin' => $this->l_gstin,
            'maintain_bill_by_bill' => $this->l_maintain_bill_by_bill,
            'cost_centres_applicable' => $this->l_cost_centres_applicable,
            'gst_rate' => ($this->l_gst_rate === null || $this->l_gst_rate === '') ? null : (float) $this->l_gst_rate,
            'hsn_sac' => $this->l_hsn_sac ?: null,
            'gst_registration_type' => $this->l_gst_registration_type ?: null,
            // Clearing the PAN here is exactly what makes Section 206AA bite on the next
            // payment — the deduction jumps to the section's no-PAN floor.
            'deductee_pan' => $this->l_deductee_pan ? strtoupper(trim($this->l_deductee_pan)) : null,
            'deductee_type' => $this->l_deductee_type ?: null,
            'default_tds_section_id' => $this->l_default_tds_section_id ?: null,
            'linked_company_id' => $this->l_linked_company_id ?: null, // Phase 12B
        ];
        if (! $l->is_reserved) {
            $payload['name'] = trim($this->l_name);
        }
        if ($canEditGroup) {
            $payload['group_id'] = $this->l_group_id;
        }

        $l->update($payload);

        return $l->fresh()->load('group')->toCache();
    }

    /** Debtor here <-> Creditor there; loans mirror to the opposite side. */
    private const MIRROR_GROUPS = [
        'Sundry Debtors' => 'Sundry Creditors',
        'Sundry Creditors' => 'Sundry Debtors',
        'Loans & Advances (Asset)' => 'Unsecured Loans',
        'Loans (Liability)' => 'Loans & Advances (Asset)',
    ];

    /**
     * Phase 12B - "Create reciprocal ledger in linked company": one click creates
     * the MIRROR party ledger in the linked company (Debtor there <-> Creditor
     * here), linked back to the active company, so the inter-company tag can
     * record counterparty_ledger_id and 12C can match eliminations precisely.
     *
     * A deliberate, explicit cross-company WRITE above the isolation layer -
     * ActiveCompany::runAs (the CompanyProvisioner precedent), never a scope
     * change. Soft convenience: nothing prevents non-mirrored posting.
     */
    public function createReciprocal(int $ledgerId): array
    {
        $l = Ledger::with('group')->find($ledgerId);
        if (! $l) {
            return ['ok' => false, 'message' => 'Ledger not found.'];
        }

        $ic = app(\App\Services\InterCompanyService::class);
        if (! $l->linked_company_id || ! in_array((int) $l->linked_company_id, $ic->groupmateIds(), true)) {
            return ['ok' => false, 'message' => 'Link this ledger to a same-group company first.'];
        }

        // Resolve the mirror nature, walking up for children (Bank OD, Secured...).
        $mirrorGroup = null;
        $g = $l->group;
        $guard = 0;
        while ($g && $mirrorGroup === null && $guard++ < 12) {
            $mirrorGroup = self::MIRROR_GROUPS[$g->name] ?? null;
            $g = $g->parent_id ? \App\Models\AccountGroup::find($g->parent_id) : null;
        }
        if ($mirrorGroup === null) {
            return ['ok' => false, 'message' => 'Only a party-tracking ledger can have a reciprocal.'];
        }

        $me = activeCompany();
        $result = \App\Support\ActiveCompany::runAs((int) $l->linked_company_id, function () use ($me, $mirrorGroup) {
            $groupId = \App\Models\AccountGroup::where('name', $mirrorGroup)->value('id');
            if (! $groupId) {
                return ['status' => 'no-group'];
            }

            // The mirror is matched BY NAME — so the matched ledger must pass the
            // same guards a manual link would: party-tracking group, and not already
            // linked to a third company. Stamping the back-link onto e.g. an expense
            // ledger that happens to share the company's name would make every real
            // third-party voucher on it derive as inter-company.
            $existing = Ledger::where('name', $me->name)->first();

            if ($existing) {
                if ((int) $existing->linked_company_id === $me->id) {
                    return ['status' => 'already', 'ledger' => $existing];
                }
                if ($existing->linked_company_id) {
                    return ['status' => 'linked-elsewhere', 'ledger' => $existing];
                }
                if (! app(\App\Services\InterCompanyService::class)->isPartyTrackingGroup($existing->group_id)) {
                    return ['status' => 'non-party', 'ledger' => $existing];
                }
                $existing->update(['linked_company_id' => $me->id]);

                return ['status' => 'linked', 'ledger' => $existing];
            }

            return ['status' => 'created', 'ledger' => Ledger::create(
                ['name' => $me->name, 'group_id' => $groupId, 'linked_company_id' => $me->id, 'country' => 'India'],
            )];
        });

        $counterpartyName = \App\Models\Company::find($l->linked_company_id)?->name;

        return match ($result['status']) {
            'no-group' => ['ok' => false, 'message' => 'The linked company is missing the mirror account group.'],
            'linked-elsewhere' => ['ok' => false, 'message' => 'A ledger named "'.$me->name.'" in '.$counterpartyName.' is already linked to a different company — resolve that link first.'],
            'non-party' => ['ok' => false, 'message' => 'A NON-party ledger named "'.$me->name.'" already exists in '.$counterpartyName.' — rename it or link a party ledger there manually.'],
            'already' => ['ok' => true, 'message' => 'Reciprocal ledger "'.$me->name.'" already exists in '.$counterpartyName.' and links back here.'],
            'linked' => ['ok' => true, 'message' => 'Existing ledger "'.$me->name.'" in '.$counterpartyName.' now links back here (reciprocal).'],
            'created' => ['ok' => true, 'message' => 'Reciprocal ledger "'.$me->name.'" created in '.$counterpartyName.' under '.$mirrorGroup.'.'],
        };
    }

    public function deleteMaster(int $id): array
    {
        $l = Ledger::find($id);
        if (! $l) {
            return ['ok' => false, 'message' => 'Ledger not found.'];
        }
        if ($l->is_reserved) {
            return ['ok' => false, 'message' => 'Reserved ledger cannot be deleted.'];
        }
        $l->delete();

        return ['ok' => true, 'message' => 'Deleted.'];
    }

    public function render()
    {
        return view('livewire.ledger-workspace');
    }

    /** The model TogglesMasterActive retires and restores. */
    protected function masterModelClass(): string
    {
        return \App\Models\Ledger::class;
    }
}
