<?php

namespace App\Livewire\Concerns;

use App\Models\Ledger;
use Illuminate\Validation\Rule;

/**
 * Inline quick-create-ledger, used by voucher lines (Alt+C on a ledger field).
 * Mirrors CreatesGroups: a small ql_* form that persists a ledger and returns
 * its cache shape so the client can select it without a reload.
 */
trait CreatesLedgers
{
    public string $ql_name = '';
    public ?string $ql_alias = null;
    public ?int $ql_group_id = null;
    public string $ql_group_label = '';
    public string $ql_opening = '';
    public ?string $ql_type = 'Dr';
    // GST (F11-gated): rate on a Sales/Purchase ledger created inline during
    // invoice entry; state/GSTIN for a party ledger created inline.
    public ?string $ql_gst_rate = null;
    public ?string $ql_state = null;
    public ?string $ql_gstin = null;

    public function saveQuickLedger(): ?array
    {
        $this->validate([
            'ql_name' => ['required', 'string', 'max:191', Rule::unique('ledgers', 'name')->where('company_id', \App\Support\ActiveCompany::check())],
            'ql_group_id' => ['required', 'integer', Rule::exists('account_groups', 'id')->where('company_id', \App\Support\ActiveCompany::check())],
            'ql_opening' => ['nullable', 'numeric', 'min:0'],
            'ql_type' => [Rule::requiredIf(fn () => (float) $this->ql_opening > 0), 'nullable', Rule::in(['Dr', 'Cr'])],
            'ql_gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ql_state' => ['nullable', 'string', 'max:100'],
            'ql_gstin' => ['nullable', 'string', 'max:20'],
        ], [], ['ql_name' => 'name', 'ql_group_id' => 'under']);

        $opening = (float) ($this->ql_opening === '' ? 0 : $this->ql_opening);

        $ledger = Ledger::create([
            'name' => trim($this->ql_name),
            'alias' => $this->ql_alias ? trim($this->ql_alias) : null,
            'group_id' => $this->ql_group_id,
            'opening_balance' => $opening,
            'opening_balance_type' => $opening > 0 ? $this->ql_type : null,
            'gst_rate' => ($this->ql_gst_rate === null || $this->ql_gst_rate === '') ? null : (float) $this->ql_gst_rate,
            'state' => $this->ql_state ?: null,
            'gstin' => $this->ql_gstin ?: null,
            'country' => 'India',
        ]);

        $this->reset('ql_name', 'ql_alias', 'ql_group_id', 'ql_group_label', 'ql_opening', 'ql_gst_rate', 'ql_state', 'ql_gstin');
        $this->ql_type = 'Dr';

        return $ledger->load('group')->toCache();
    }
}
