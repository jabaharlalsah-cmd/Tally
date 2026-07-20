@php($vcol = fn ($fav) => $fav ? '#0a7d33' : '#b23b32')
<div class="zb-report"
     x-data="budgetSummary({ gatewayUrl: @js(route('gateway')) })">

    <div class="zb-report-head">
        <div class="zb-report-title">Budget Summary</div>
        <div class="zb-report-period">
            @if (count($budgets) > 1)
                <select class="form-control zb-field" wire:model.live="budgetId" data-zb-noselect style="width:auto">
                    @foreach ($budgets as $b)
                        <option value="{{ $b['id'] }}">{{ $b['name'] }}</option>
                    @endforeach
                </select>
            @endif
            <label>As of <span class="zb-kbd">F2</span></label>
            <input type="date" id="summary-asof" class="form-control zb-field" data-zb-noselect wire:model.blur="asOf">
        </div>
    </div>

    <div class="zb-panel">
        <div id="budget-summary" tabindex="-1" class="zb-report-surface" style="padding:1rem">
            @if (! $budget || ! $data)
                <p class="muted">No budget found. Create one from the Budgets list first.</p>
            @else
                <table class="zb-rtable" style="max-width:640px">
                    <thead>
                        <tr><th></th><th class="zb-vt-right">Budget</th><th class="zb-vt-right">Actual</th><th class="zb-vt-right">Variance</th><th class="zb-vt-right">%</th></tr>
                    </thead>
                    <tbody>
                        <tr class="zb-rrow">
                            <td>Total Revenue</td>
                            <td class="zb-vt-right">{{ $money($data['revenue']['budget']) }}</td>
                            <td class="zb-vt-right">{{ $money($data['revenue']['actual']) }}</td>
                            <td class="zb-vt-right" style="color:{{ $vcol($data['revenue']['is_favorable']) }}">{{ $money($data['revenue']['variance']) }}</td>
                            <td class="zb-vt-right" style="color:{{ $vcol($data['revenue']['is_favorable']) }}">{{ $data['revenue']['variance_pct'] === null ? '—' : $data['revenue']['variance_pct'].'%' }}</td>
                        </tr>
                        <tr class="zb-rrow">
                            <td>Total Expenses</td>
                            <td class="zb-vt-right">{{ $money($data['expense']['budget']) }}</td>
                            <td class="zb-vt-right">{{ $money($data['expense']['actual']) }}</td>
                            <td class="zb-vt-right" style="color:{{ $vcol($data['expense']['is_favorable']) }}">{{ $money($data['expense']['variance']) }}</td>
                            <td class="zb-vt-right" style="color:{{ $vcol($data['expense']['is_favorable']) }}">{{ $data['expense']['variance_pct'] === null ? '—' : $data['expense']['variance_pct'].'%' }}</td>
                        </tr>
                        <tr class="zb-rrow zb-r-total">
                            <td><strong>Net Profit</strong></td>
                            <td class="zb-vt-right"><strong>{{ $money($data['net_profit']['budget']) }}</strong></td>
                            <td class="zb-vt-right"><strong>{{ $money($data['net_profit']['actual']) }}</strong></td>
                            <td class="zb-vt-right"><strong style="color:{{ $vcol($data['net_profit']['is_favorable']) }}">{{ $money($data['net_profit']['variance']) }}</strong></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
                <p class="muted" style="margin-top:.8rem">
                    YTD from {{ \Carbon\Carbon::parse($data['from'])->format('d-M-Y') }} to {{ \Carbon\Carbon::parse($data['as_of'])->format('d-M-Y') }}.
                    Amounts in ₹. <span style="color:#0a7d33">Green</span> = favorable, <span style="color:#b23b32">red</span> = unfavorable.
                </p>
            @endif
        </div>
        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">F2</span> as-of date · <span class="zb-kbd">Esc</span> gateway
        </p>
    </div>
</div>
