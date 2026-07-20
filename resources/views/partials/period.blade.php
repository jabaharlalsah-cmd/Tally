{{-- Period / date picker stub — opened with F2. Full period logic: Phase 4. --}}
<div class="zb-modal-backdrop" x-data="zbPeriod" x-show="$store.zb.period.open" x-cloak
     x-transition.opacity @mousedown.self="close()" role="dialog" aria-label="Change Period">
    <div class="zb-modal" data-zb-form="period" x-on:zb:commit.prevent="apply()">
        <div class="zb-modal-head">Change Period</div>
        <div class="zb-modal-body">
            <div class="zb-form-row">
                <label>From</label>
                <input id="zb-period-from" class="form-control zb-field" type="date"
                       x-model="from" data-zb-field data-zb-label="From date">
            </div>
            <div class="zb-form-row">
                <label>To</label>
                <input class="form-control zb-field" type="date"
                       x-model="to" data-zb-field data-zb-label="To date">
            </div>
            <p class="text-muted" style="font-size:.76rem;margin:.6rem 0 0">
                <span class="zb-kbd">Enter</span> advance &middot;
                <span class="zb-kbd">Ctrl+A</span> accept &middot;
                <span class="zb-kbd">Esc</span> cancel.
                Full period logic arrives in Phase 4.
            </p>
        </div>
        <div class="zb-modal-foot">
            <button type="button" class="btn btn-sm" @click="close()">Cancel</button>
            <button type="button" class="btn btn-sm zb-btn-primary" @click="apply()">Accept</button>
        </div>
    </div>
</div>
