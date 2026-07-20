<div class="zb-report"
     x-data="reportScreen({
        rows: @js($rows),
        title: 'Balance Sheet',
        drillUrl: @js(route('reports.ledger', ['ledger' => '__id__'])),
        gatewayUrl: @js(route('gateway')),
        from: @js($from),
        to: @js($to)
     })">

    @include('partials.report-controls', ['reportTitle' => 'Balance Sheet'])

    <div class="zb-panel">
        <div id="report-surface" tabindex="-1" class="zb-report-surface">
            <div class="zb-rcols">
                @include('partials.report-column', ['colRows' => $left, 'heading' => 'Liabilities'])
                @include('partials.report-column', ['colRows' => $right, 'heading' => 'Assets'])
            </div>
        </div>
        <div class="zb-report-status" :class="{ 'is-off': !@js($balanced) }">
            @if ($balanced)
                <span class="zb-r-ok">✓ Balance Sheet balances (Assets = Liabilities + Nett Profit)</span>
            @else
                <span class="zb-r-bad">⚠ Balance Sheet does not balance</span>
            @endif
            <span class="zb-report-fy">{{ $fromLabel }} to {{ $toLabel }}</span>
        </div>
        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">↑</span><span class="zb-kbd">↓</span> move · <span class="zb-kbd">Enter</span> expand / drill to ledger ·
            <span class="zb-kbd">Alt+F1</span> detailed · <span class="zb-kbd">F2</span> period · <span class="zb-kbd">Esc</span> back
        </p>
    </div>
</div>
