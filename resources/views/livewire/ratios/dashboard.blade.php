@php
    $hcol = fn ($h) => match ($h) { 'green' => '#0a7d33', 'amber' => '#97590a', 'red' => '#b23b32', default => '#6b7280' };
    $dcol = fn ($fav) => $fav === null ? '#6b7280' : ($fav ? '#0a7d33' : '#b23b32');
@endphp
<div class="zb-report"
     x-data="ratioDashboard({
        drilldownUrl: @js(route('reports.ratio-drilldown', ['ratio' => '__key__'])),
        thresholdsUrl: @js(route('reports.ratio-thresholds')),
        gatewayUrl: @js(route('gateway'))
     })">

    <div class="zb-report-head">
        <div class="zb-report-title">Ratio Analysis</div>
        <div class="zb-report-period">
            <label>As of <span class="zb-kbd">F2</span></label>
            <input type="date" id="ratio-asof" class="form-control zb-field" data-zb-noselect wire:model.blur="asOf">
            <a href="{{ route('reports.ratio-thresholds') }}" class="zb-report-toggle"><span class="zb-kbd">F12</span> Thresholds</a>
        </div>
    </div>

    <div class="zb-panel">
        <div id="ratio-surface" tabindex="-1" class="zb-report-surface" style="padding:.5rem 0">
            @foreach ($sections as $key => $rows)
                @continue (empty($rows))
                <div style="padding:.4rem 1rem .1rem">
                    <strong style="font-size:.82rem;letter-spacing:.02em;text-transform:uppercase;color:#5c6b63">{{ $sectionTitles[$key] }}</strong>
                </div>
                <table class="zb-rtable" style="margin-bottom:.4rem">
                    <thead>
                        <tr>
                            <th style="min-width:14rem">Ratio</th>
                            <th class="zb-vt-right">Value</th>
                            <th class="zb-vt-right">Δ vs prior</th>
                            <th>Trend</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr class="zb-rrow zb-r-ledger" data-ratio-row data-key="{{ $r['key'] }}"
                                @click="drillTo('{{ $r['key'] }}')">
                                <td>{{ $r['label'] }}</td>
                                <td class="zb-vt-right"><strong style="color:{{ $hcol($r['health']) }}">{{ $r['display'] }}</strong></td>
                                <td class="zb-vt-right" style="color:{{ $dcol($r['delta_favorable']) }}">{{ $r['delta_display'] }}</td>
                                <td>
                                    @if ($r['spark'])
                                        <svg viewBox="0 0 120 30" width="120" height="30" style="display:block">
                                            <polyline points="{{ $r['spark'] }}" fill="none" stroke="#6b7280" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>
                                        </svg>
                                    @else
                                        <span class="muted" style="font-size:.72rem">—</span>
                                    @endif
                                </td>
                                <td class="muted" style="font-size:.72rem">
                                    @if ($r['reason'])<span title="{{ $r['reason'] }}">N/A</span>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach
        </div>
        <div class="zb-report-status">
            <span class="zb-report-fy">As of {{ $asOfLabel }}</span>
            <span class="muted" style="font-size:.72rem">
                <span style="color:#0a7d33">green</span> healthy ·
                <span style="color:#97590a">amber</span> watch ·
                <span style="color:#b23b32">red</span> concern · thresholds are editable (F12)
            </span>
        </div>
        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">↑</span><span class="zb-kbd">↓</span> move ·
            <span class="zb-kbd">Enter</span> drill to inputs · <span class="zb-kbd">F2</span> as-of ·
            <span class="zb-kbd">F12</span> thresholds · <span class="zb-kbd">Esc</span> gateway
        </p>
    </div>
</div>
