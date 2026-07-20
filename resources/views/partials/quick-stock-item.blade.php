{{-- Inline quick-create Stock Item sub-screen (opened by Alt+C on an item line).
     Lives inside the voucherScreen x-data scope. A new item has no opening stock;
     its cost is built purely from movement. Group/Unit pick from existing masters
     (create a new group/unit from the full Stock Item master). --}}
<div class="zb-subscreen zb-sub-1" x-show="showQuickStockItem" x-cloak>
    <div class="zb-subscreen-card">
        <div class="zb-subscreen-head">
            <span>Create Stock Item (inline)</span>
            <span class="zb-kbd">Ctrl+A · Esc</span>
        </div>
        <form class="zb-subscreen-body" data-zb-form="quick-stock-item"
              x-on:zb:commit.prevent="saveQuickStockItem()" @submit.prevent>
            <div class="zb-form-row">
                <label for="qsi-name">Name</label>
                <input id="qsi-name" class="form-control zb-field" data-zb-field data-zb-label="Name"
                       wire:model="qsi_name" autocomplete="off">
            </div>
            @error('qsi_name') <div class="zb-field-error">{{ $message }}</div> @enderror

            <div class="zb-form-row">
                <label>Under (Stock Group)</label>
                <x-master-select id="qsi-under" source="stockGroups" model="qsi_group_id" model-label="qsi_group_label"
                                 :create-type="null" :allow-primary="false" label="Under"
                                 placeholder="Stock group (optional)" />
            </div>

            <div class="zb-form-row">
                <label>Unit</label>
                <x-master-select id="qsi-unit" source="units" model="qsi_unit_id" model-label="qsi_unit_label"
                                 :create-type="null" :allow-primary="false" label="Unit"
                                 placeholder="Unit of measure (optional)" />
            </div>

            {{-- Tax details (F11-gated, either regime) — same columns, relabelled by regime,
                 mirroring the ledger form and the Stock Item master. --}}
            <template x-if="taxOn">
                <div>
                    <div class="zb-form-row">
                        <label for="qsi-gstrate" x-text="(vatOn ? 'VAT' : 'GST') + ' rate (%)'">GST rate (%)</label>
                        <input id="qsi-gstrate" type="text" inputmode="decimal" class="form-control zb-field" data-zb-field
                               data-zb-label="Tax rate" wire:model="qsi_gst_rate" :placeholder="vatOn ? 'e.g. 13' : 'e.g. 18'">
                    </div>
                    @error('qsi_gst_rate') <div class="zb-field-error">{{ $message }}</div> @enderror

                    <div class="zb-form-row">
                        <label for="qsi-hsn" x-text="vatOn ? 'HS Code' : 'HSN / SAC'">HSN / SAC</label>
                        <input id="qsi-hsn" class="form-control zb-field" data-zb-field data-zb-label="HSN"
                               wire:model="qsi_hsn" autocomplete="off">
                    </div>
                </div>
            </template>

            <p class="text-muted" style="font-size:.76rem;margin-top:.4rem">
                <span class="zb-kbd">Ctrl+A</span> create &amp; return · <span class="zb-kbd">Esc</span> cancel
            </p>
        </form>
    </div>
</div>
