@php
    $hcol = fn ($h) => match ($h) { 'green' => '#0a7d33', 'amber' => '#97590a', 'red' => '#b23b32', default => '#6b7280' };
@endphp
<div class="zb-report"
     x-data="ratioDrilldown({
        title: @js($detail['label'] ?? 'Ratio Inputs'),
        ledgerUrl: @js(route('reports.ledger', ['ledger' => '__id__'])),
        backUrl: @js(route('reports.ratio-dashboard')),
        from: @js($from),
        to: @js($asOf)
     })">

    <div class="zb-report-head">
        <div class="zb-report-title">{{ $detail['label'] ?? 'Ratio' }} — inputs</div>
        <div class="zb-report-period">
            <a href="{{ route('reports.ratio-dashboard') }}" class="zb-report-toggle"><span class="zb-kbd">Esc</span> Back to dashboard</a>
        </div>
    </div>

    <div class="zb-panel">
        <div id="ratio-drill" tabindex="-1" class="zb-report-surface" style="padding:1rem">
            @if (! $detail)
                <p class="muted">Unknown ratio.</p>
            @else
                <p>
                    <strong style="font-size:1.3rem;color:{{ $hcol($detail['health']) }}">
                        @if ($detail['value'] === null) N/A @else {{ rtrim(rtrim(number_format($detail['value'], 2), '0'), '.') }}{{ $detail['unit'] === '%' ? '%' : ($detail['unit'] === 'd' ? ' days' : '×') }} @endif
                    </strong>
                    <span class="muted" style="margin-left:.6rem">health: {{ $detail['health'] }} · as of {{ $asOfLabel }}</span>
                </p>
                @if ($detail['reason'])
                    <p class="muted" style="font-size:.85rem">{{ $detail['reason'] }}</p>
                @endif

                <h2 style="font-size:.95rem;margin-top:1rem">Inputs (traced from the balance engine)</h2>
                <table class="zb-rtable" style="max-width:560px">
                    <thead><tr><th>Figure</th><th class="zb-vt-right">Amount</th><th>Source</th></tr></thead>
                    <tbody>
                        @foreach ($detail['inputs'] as $in)
                            <tr class="zb-rrow">
                                <td>{{ $in['label'] }}</td>
                                <td class="zb-vt-right">{{ $money((int) $in['value_paise']) }}</td>
                                <td class="muted" style="font-size:.78rem">{{ $in['source'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if ($contrib)
                    <h2 style="font-size:.95rem;margin-top:1.2rem">Contributing ledgers <span class="muted" style="font-size:.75rem">— Enter to open a ledger's vouchers</span></h2>
                    <table class="zb-rtable" style="max-width:560px">
                        <thead><tr><th>Ledger</th><th>Group</th><th class="zb-vt-right">Closing</th></tr></thead>
                        <tbody>
                            @foreach ($contrib as $grp)
                                @forelse ($grp['ledgers'] as $l)
                                    <tr class="zb-rrow zb-r-ledger" data-ledger-row data-ledger-id="{{ $l['ledger_id'] }}"
                                        @click="window.location.href='{{ route('reports.ledger', ['ledger' => $l['ledger_id']]) }}?from={{ $from }}&to={{ $asOf }}'">
                                        <td>{{ $l['name'] }}</td>
                                        <td class="muted">{{ $grp['group'] }}</td>
                                        <td class="zb-vt-right">{{ $money((int) $l['closing']) }}</td>
                                    </tr>
                                @empty
                                    <tr class="zb-rrow"><td class="muted">{{ $grp['group'] }}</td><td class="muted" colspan="2">no ledgers with activity</td></tr>
                                @endforelse
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @endif
        </div>
        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">↑</span><span class="zb-kbd">↓</span> move ·
            <span class="zb-kbd">Enter</span> drill to vouchers · <span class="zb-kbd">Esc</span> back
        </p>
    </div>
</div>
