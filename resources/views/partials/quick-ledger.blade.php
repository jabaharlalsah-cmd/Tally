{{-- Inline quick-create Ledger sub-screen (opened by Alt+C on a voucher line).
     Lives inside the voucherScreen x-data scope. --}}
<div class="zb-subscreen zb-sub-1" x-show="showQuickLedger" x-cloak>
    <div class="zb-subscreen-card">
        <div class="zb-subscreen-head">
            <span>Create Ledger (inline)</span>
            <span class="zb-kbd">Ctrl+A · Esc</span>
        </div>
        <form class="zb-subscreen-body" data-zb-form="quick-ledger"
              x-on:zb:commit.prevent="saveQuickLedger()" @submit.prevent>
            <div class="zb-form-row">
                <label for="qled-name">Name</label>
                <input id="qled-name" class="form-control zb-field" data-zb-field data-zb-label="Name"
                       wire:model="ql_name" autocomplete="off">
            </div>
            @error('ql_name') <div class="zb-field-error">{{ $message }}</div> @enderror

            <div class="zb-form-row">
                <label for="qled-alias">Alias</label>
                <input id="qled-alias" class="form-control zb-field" data-zb-field data-zb-label="Alias"
                       wire:model="ql_alias" autocomplete="off">
            </div>

            <div class="zb-form-row">
                <label>Under</label>
                <x-master-select :manage="false" id="qled-under" source="groups" model="ql_group_id" model-label="ql_group_label"
                                 create-type="group" :allow-primary="false" label="Under"
                                 placeholder="Group (Alt+C to create a group)" />
            </div>
            @error('ql_group_id') <div class="zb-field-error">{{ $message }}</div> @enderror

            <div class="zb-form-row">
                <label for="qled-opening">Opening Balance</label>
                <div class="zb-opening">
                    <input id="qled-opening" type="text" inputmode="decimal" class="form-control zb-field zb-opening-amt"
                           data-zb-field data-zb-label="Opening" wire:model="ql_opening" placeholder="0.00">
                    <select class="form-select zb-field zb-opening-side" data-zb-field data-zb-label="Dr/Cr" wire:model="ql_type">
                        <option value="Dr">Dr</option>
                        <option value="Cr">Cr</option>
                    </select>
                </div>
            </div>
            @error('ql_opening') <div class="zb-field-error">{{ $message }}</div> @enderror

            {{-- Tax details (F11-gated, either regime): party gets a tax-ID (+state under GST),
                 a nominal ledger gets the rate. Same columns, relabelled by regime. --}}
            <template x-if="taxOn">
                <div>
                    <template x-if="quickLedgerIsParty">
                        <div>
                            <div class="zb-form-row" x-show="gstOn">
                                <label for="qled-state">State</label>
                                <input id="qled-state" class="form-control zb-field" data-zb-field data-zb-label="State"
                                       wire:model="ql_state" autocomplete="off" placeholder="e.g. Maharashtra">
                            </div>
                            <div class="zb-form-row">
                                <label for="qled-gstin" x-text="vatOn ? 'PAN' : 'GSTIN'">GSTIN</label>
                                <input id="qled-gstin" class="form-control zb-field" data-zb-field :data-zb-label="vatOn ? 'PAN' : 'GSTIN'"
                                       wire:model="ql_gstin" autocomplete="off">
                            </div>
                        </div>
                    </template>
                    <template x-if="!quickLedgerIsParty">
                        <div class="zb-form-row">
                            <label for="qled-gstrate" x-text="(vatOn ? 'VAT' : 'GST') + ' rate (%)'">GST rate (%)</label>
                            <input id="qled-gstrate" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field
                                   data-zb-label="Tax rate" wire:model="ql_gst_rate" :placeholder="vatOn ? 'e.g. 13' : 'e.g. 18'">
                        </div>
                    </template>
                    @error('ql_gst_rate') <div class="zb-field-error">{{ $message }}</div> @enderror
                </div>
            </template>

            <p class="text-muted" style="font-size:.76rem;margin-top:.4rem">
                <span class="zb-kbd">Ctrl+A</span> create &amp; return · <span class="zb-kbd">Esc</span> cancel
            </p>
        </form>
    </div>
</div>
