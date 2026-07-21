{{-- Inline quick-create Group sub-screen (opened by Alt+C from any Under picker).
     Lives inside a master workspace's x-data scope. --}}
<div class="zb-subscreen zb-sub-2" x-show="showQuickGroup" x-cloak>
    <div class="zb-subscreen-card">
        <div class="zb-subscreen-head">
            <span>Create Group (inline)</span>
            <span class="zb-kbd">Ctrl+A · Esc</span>
        </div>
        <form class="zb-subscreen-body" data-zb-form="quick-group"
              x-on:zb:commit.prevent="saveQuickGroup()" @submit.prevent>
            <div class="zb-form-row">
                <label for="qg-name">Name</label>
                <input id="qg-name" class="form-control zb-field" data-zb-field data-zb-label="Name"
                       wire:model="qg_name" autocomplete="off">
            </div>
            @error('qg_name') <div class="zb-field-error">{{ $message }}</div> @enderror

            <div class="zb-form-row">
                <label for="qg-alias">Alias</label>
                <input id="qg-alias" class="form-control zb-field" data-zb-field data-zb-label="Alias"
                       wire:model="qg_alias" autocomplete="off">
            </div>

            <div class="zb-form-row">
                <label>Under</label>
                <x-master-select :manage="false" id="qg-under" source="groups" model="qg_parent_id" model-label="qg_parent_label"
                                 :create-type="null" :allow-primary="true" label="Under"
                                 placeholder="Parent group, or ⌂ Primary" />
            </div>

            <div class="zb-form-row" x-show="$wire.qg_parent_id === null">
                <label for="qg-nature">Nature</label>
                <select id="qg-nature" class="form-select zb-field" data-zb-field data-zb-label="Nature" wire:model="qg_nature">
                    <option>Assets</option>
                    <option>Liabilities</option>
                    <option>Income</option>
                    <option>Expenses</option>
                </select>
            </div>
            @error('qg_parent_id') <div class="zb-field-error">{{ $message }}</div> @enderror

            <p class="text-muted" style="font-size:.76rem;margin-top:.4rem">
                <span class="zb-kbd">Ctrl+A</span> create &amp; return &middot; <span class="zb-kbd">Esc</span> cancel
            </p>
        </form>
    </div>
</div>
