{{-- Inline quick-create Stock Group sub-screen (Alt+C from the Stock Item Under
     picker). Lives inside the stockItemWorkspace x-data scope. --}}
<div class="zb-subscreen zb-sub-1" x-show="showQuickStockGroup" x-cloak>
    <div class="zb-subscreen-card">
        <div class="zb-subscreen-head">
            <span>Create Stock Group (inline)</span>
            <span class="zb-kbd">Ctrl+A · Esc</span>
        </div>
        <form class="zb-subscreen-body" data-zb-form="quick-stockgroup" x-on:zb:commit.prevent="saveQuickStockGroup()" @submit.prevent>
            <div class="zb-form-row">
                <label for="qsg-name">Name</label>
                <input id="qsg-name" class="form-control zb-field" data-zb-field data-zb-label="Name" wire:model="qsg_name" autocomplete="off">
            </div>
            @error('qsg_name') <div class="zb-field-error">{{ $message }}</div> @enderror
            <div class="zb-form-row">
                <label for="qsg-alias">Alias</label>
                <input id="qsg-alias" class="form-control zb-field" data-zb-field data-zb-label="Alias" wire:model="qsg_alias" autocomplete="off">
            </div>
            <div class="zb-form-row">
                <label>Under</label>
                <x-master-select id="qsg-under" source="stockGroups" model="qsg_parent_id" model-label="qsg_parent_label" :allow-primary="false" label="Under" placeholder="Optional parent" />
            </div>
            @error('qsg_parent_id') <div class="zb-field-error">{{ $message }}</div> @enderror
            <p class="text-muted" style="font-size:.76rem;margin-top:.4rem"><span class="zb-kbd">Ctrl+A</span> create &amp; return · <span class="zb-kbd">Esc</span> cancel</p>
        </form>
    </div>
</div>
