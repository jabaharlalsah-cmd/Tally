@php($__rows = $rows)
<div class="zb-report"
     x-data="reportScreen({
        rows: @js($__rows),
        title: 'Trial Balance',
        drillUrl: @js(route('reports.ledger', ['ledger' => '__id__'])),
        gatewayUrl: @js(route('gateway')),
        grandTotal: @js($grandTotal),
        from: @js($from),
        to: @js($to)
     })">

    <div class="zb-report-head">
        <div class="zb-report-title">Trial Balance</div>
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
                        <th class="zb-vt-right" x-show="cfgOpt('showOpening')" x-cloak>Opening Dr</th>
                        <th class="zb-vt-right" x-show="cfgOpt('showOpening')" x-cloak>Opening Cr</th>
                        <th class="zb-vt-right">Closing Dr</th>
                        <th class="zb-vt-right">Closing Cr</th>
                        <th class="zb-vt-right" x-show="cfgOpt('showPercent')" x-cloak>%</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($__rows as $row)
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
                            <td class="zb-vt-right" x-show="cfgOpt('showOpening')" x-cloak>{{ $row['open_dr'] }}</td>
                            <td class="zb-vt-right" x-show="cfgOpt('showOpening')" x-cloak>{{ $row['open_cr'] }}</td>
                            <td class="zb-vt-right">{{ $row['dr'] }}</td>
                            <td class="zb-vt-right">{{ $row['cr'] }}</td>
                            <td class="zb-vt-right" x-show="cfgOpt('showPercent')" x-cloak>@if ($row['kind'] !== 'total')<span x-text="pct('{{ $row['key'] }}')"></span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="zb-report-status" :class="{ 'is-off': !@js($balanced) }">
            @if ($balanced)
                <span class="zb-r-ok">✓ Trial Balance is balanced</span>
            @else
                <span class="zb-r-bad">⚠ Trial Balance does not balance — check opening balances</span>
            @endif
            <span class="zb-report-fy">{{ $fromLabel }} to {{ $toLabel }}</span>
        </div>
        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">↑</span><span class="zb-kbd">↓</span> move · <span class="zb-kbd">Enter</span> expand / drill to ledger ·
            <span class="zb-kbd">Alt+F1</span> detailed · <span class="zb-kbd">F2</span> period · <span class="zb-kbd">Esc</span> back
        </p>
    </div>
</div>
