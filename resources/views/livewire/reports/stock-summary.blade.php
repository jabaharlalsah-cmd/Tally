@php($__rows = $rows)
<div class="zb-report"
     x-data="reportScreen({
        rows: @js($__rows),
        title: 'Stock Summary',
        drillUrl: @js(route('reports.stock-item', ['stockItem' => '__id__'])),
        gatewayUrl: @js(route('gateway')),
        from: @js($from),
        to: @js($to)
     })">

    <div class="zb-report-head">
        <div class="zb-report-title">Stock Summary</div>
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

    <div class="zb-panel">
        <div id="report-surface" tabindex="-1" class="zb-report-surface">
            <table class="zb-rtable">
                <thead>
                    <tr>
                        <th>Particulars</th>
                        <th style="width:12rem">Costing Method</th>
                        <th class="zb-vt-right" style="width:14rem">Quantity</th>
                        <th class="zb-vt-right" style="width:14rem">Value (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($__rows as $row)
                        <tr data-key="{{ $row['key'] }}"
                            class="zb-rrow zb-r-{{ $row['kind'] }}"
                            x-show="isVisible('{{ $row['key'] }}')"
                            :class="{ 'is-active': activeKey === '{{ $row['key'] }}' }"
                            @click="activeKey='{{ $row['key'] }}'; enter()"
                            @mousemove="activeKey='{{ $row['key'] }}'">
                            <td style="padding-left: {{ 0.5 + $row['depth'] * 1.25 }}rem">
                                @if ($row['kind'] === 'group' && $row['collapsible'])
                                    <span class="zb-r-caret" x-text="(detailed || expanded['{{ $row['key'] }}']) ? '▾' : '▸'"></span>
                                @endif
                                {{ $row['label'] }}
                            </td>
                            <td>{{ $row['costing'] }}</td>
                            <td class="zb-vt-right">{{ $row['qty'] }}</td>
                            <td class="zb-vt-right">{{ $row['value'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="zb-r-empty">No stock items yet. Create one under Inventory Info.</td></tr>
                    @endforelse
                    @if (! $empty)
                        <tr class="zb-rrow zb-r-total">
                            <td>Grand Total</td>
                            <td></td>
                            <td></td>
                            <td class="zb-vt-right">{{ $grand }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
        <div class="zb-report-status">
            <span class="zb-r-ok">Closing Stock value: {{ $grand }}</span>
            <span class="zb-report-fy">{{ $fromLabel }} to {{ $toLabel }}</span>
        </div>
        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">↑</span><span class="zb-kbd">↓</span> move · <span class="zb-kbd">Enter</span> expand / drill to item movements ·
            <span class="zb-kbd">Alt+F1</span> detailed · <span class="zb-kbd">F2</span> period · <span class="zb-kbd">Esc</span> back
        </p>
    </div>
</div>
