{{-- Inline quick-create Unit sub-screen (Alt+C from the Stock Item Unit picker).
     Lives inside the stockItemWorkspace x-data scope. --}}
<div class="zb-subscreen zb-sub-2" x-show="showQuickUnit" x-cloak style="z-index:1284">
    <div class="zb-subscreen-card">
        <div class="zb-subscreen-head">
            <span>Create Unit (inline)</span>
            <span class="zb-kbd">Ctrl+A · Esc</span>
        </div>
        <form class="zb-subscreen-body" data-zb-form="quick-unit" x-on:zb:commit.prevent="saveQuickUnit()" @submit.prevent>
            <div class="zb-form-row">
                <label for="qu-name">Name</label>
                <input id="qu-name" class="form-control zb-field" data-zb-field data-zb-label="Name" wire:model="qu_name" autocomplete="off" placeholder="e.g. Nos">
            </div>
            @error('qu_name') <div class="zb-field-error">{{ $message }}</div> @enderror
            <div class="zb-form-row">
                <label for="qu-symbol">Symbol</label>
                <input id="qu-symbol" class="form-control zb-field" data-zb-field data-zb-label="Symbol" wire:model="qu_symbol" autocomplete="off">
            </div>
            <div class="zb-form-row">
                <label for="qu-dp">Decimal places</label>
                <input id="qu-dp" type="text" inputmode="decimal" min="0" max="6" class="form-control zb-field" data-zb-field data-zb-label="Decimals" wire:model="qu_decimal_places" placeholder="0">
            </div>
            @error('qu_decimal_places') <div class="zb-field-error">{{ $message }}</div> @enderror
            <p class="text-muted" style="font-size:.76rem;margin-top:.4rem"><span class="zb-kbd">Ctrl+A</span> create &amp; return · <span class="zb-kbd">Esc</span> cancel</p>
        </form>
    </div>
</div>
