{{-- Shared report period bar + detailed toggle. Rendered inside a reportScreen
     x-data scope; wire:model binds the component's from/to. --}}
<div class="zb-report-head">
    <div class="zb-report-title">{{ $reportTitle }}</div>
    <div class="zb-report-period">
        <label>Period <span class="zb-kbd">F2</span></label>
        <input type="date" id="report-from" class="form-control zb-field" data-zb-noselect wire:model.blur="from">
        <span>to</span>
        <input type="date" id="report-to" class="form-control zb-field" data-zb-noselect wire:model.blur="to">
        <button type="button" class="zb-report-toggle" @click="toggleDetailed()">
            <span class="zb-kbd">Alt+F1</span> <span x-text="detailed ? 'Condensed' : 'Detailed'"></span>
        </button>
    </div>
</div>
