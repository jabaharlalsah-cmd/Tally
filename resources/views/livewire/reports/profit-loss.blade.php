<div class="zb-report"
     x-data="reportScreen({
        rows: @js($rows),
        title: 'Profit &amp; Loss A/c',
        drillUrl: @js(route('reports.ledger', ['ledger' => '__id__'])),
        gatewayUrl: @js(route('gateway')),
        from: @js($from),
        to: @js($to)
     })">

    @include('partials.report-controls', ['reportTitle' => 'Profit & Loss A/c'])

    <div class="zb-panel">
        <div id="report-surface" tabindex="-1" class="zb-report-surface">
            <div class="zb-rcols">
                @include('partials.report-column', ['colRows' => $left, 'heading' => 'Expenses'])
                @include('partials.report-column', ['colRows' => $right, 'heading' => 'Income'])
            </div>
        </div>
        <div class="zb-report-status">
            <span class="zb-r-ok">
                @if ($isProfit) Nett Profit: {{ $net }} @else Nett Loss: {{ $net }} @endif
            </span>
            <span class="zb-report-fy">{{ $fromLabel }} to {{ $toLabel }}</span>
        </div>
        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">↑</span><span class="zb-kbd">↓</span> move · <span class="zb-kbd">Enter</span> expand / drill to ledger ·
            <span class="zb-kbd">Alt+F1</span> detailed · <span class="zb-kbd">F2</span> period · <span class="zb-kbd">Esc</span> back
        </p>
    </div>
</div>
