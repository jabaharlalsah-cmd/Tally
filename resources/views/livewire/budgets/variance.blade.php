@php($vcol = fn ($fav) => $fav ? '#0a7d33' : '#b23b32')
<div class="zb-report"
     x-data="budgetVariance({
        rows: @js($rows),
        title: 'Budget vs Actual',
        drillUrl: @js(route('reports.ledger', ['ledger' => '__id__'])),
        gatewayUrl: @js(route('gateway')),
        from: @js($from),
        to: @js($to)
     })">

    <div class="zb-report-head">
        <div class="zb-report-title">Budget vs Actual</div>
        <div class="zb-report-period">
            @if (count($budgets) > 1)
                <select class="form-control zb-field" wire:model.live="budgetId" data-zb-noselect style="width:auto">
                    @foreach ($budgets as $b)
                        <option value="{{ $b['id'] }}">{{ $b['name'] }}</option>
                    @endforeach
                </select>
            @endif
            <label>Period <span class="zb-kbd">F2</span></label>
            <input type="date" id="report-from" class="form-control zb-field" data-zb-noselect wire:model.blur="from">
            <span>to</span>
            <input type="date" id="report-to" class="form-control zb-field" data-zb-noselect wire:model.blur="to">
        </div>
    </div>

    <div class="zb-panel">
        <div id="report-surface" tabindex="-1" class="zb-report-surface">
            @if (! $budget)
                <p class="muted" style="padding:1rem">No budget found. Create one from the Budgets list first.</p>
            @else
                <table class="zb-rtable">
                    <thead>
                        <tr>
                            <th>Particulars</th>
                            <th class="zb-vt-right">Target</th>
                            <th class="zb-vt-right">Actual</th>
                            <th class="zb-vt-right">Variance</th>
                            <th class="zb-vt-right">Var %</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr data-key="{{ $row['key'] }}"
                                class="zb-rrow zb-r-{{ $row['kind'] }}"
                                :class="{ 'is-active': activeKey === '{{ $row['key'] }}' }"
                                @click="activeKey='{{ $row['key'] }}'; drill()"
                                @mousemove="activeKey='{{ $row['key'] }}'">
                                <td>
                                    {{ $row['name'] }}
                                    @if ($row['no_target'])<span class="muted" style="font-size:.75rem"> · no target</span>@endif
                                    @if ($row['revised'])<span class="muted" style="font-size:.75rem"> · revised</span>@endif
                                    <span class="muted" style="font-size:.72rem"> ({{ $row['nature'] }})</span>
                                </td>
                                <td class="zb-vt-right">@if ($row['no_target'])<span class="muted">No target</span>@else{{ $row['target'] }}@endif</td>
                                <td class="zb-vt-right">{{ $row['actual'] }}</td>
                                <td class="zb-vt-right" @if (! $row['no_target']) style="color:{{ $vcol($row['is_favorable']) }}" @endif>
                                    {{ $row['variance'] ?? '—' }}
                                </td>
                                <td class="zb-vt-right" @if (! $row['no_target'] && $row['variance_pct'] !== null) style="color:{{ $vcol($row['is_favorable']) }}" @endif>
                                    {{ $row['variance_pct'] === null ? '—' : $row['variance_pct'].'%' }}
                                </td>
                            </tr>
                        @endforeach
                        <tr class="zb-rrow zb-r-total">
                            <td><strong>Net (revenue − expenses)</strong></td>
                            <td class="zb-vt-right"><strong>{{ $totals['target'] }}</strong></td>
                            <td class="zb-vt-right"><strong>{{ $totals['actual'] }}</strong></td>
                            <td class="zb-vt-right"><strong style="color:{{ $vcol($totals['favorable']) }}">{{ $totals['variance'] }}</strong></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            @endif
        </div>
        <div class="zb-report-status">
            <span class="zb-report-fy">{{ $budget?->name }} · {{ $fromLabel }} to {{ $toLabel }}</span>
        </div>
        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">↑</span><span class="zb-kbd">↓</span> move ·
            <span class="zb-kbd">Enter</span> drill to vouchers · <span class="zb-kbd">F2</span> period ·
            <span class="zb-kbd">Esc</span> gateway · <span style="color:#0a7d33">green</span>=favorable
            <span style="color:#b23b32">red</span>=unfavorable
        </p>
    </div>
</div>
