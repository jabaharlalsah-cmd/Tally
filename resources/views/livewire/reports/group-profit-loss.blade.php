<div class="zb-ws" style="max-width:900px;margin:0 auto" x-data="groupReport()">
    <div class="zb-panel">
        <div class="zb-panel-title">Group Profit &amp; Loss @if ($group) — {{ $group->name }} · {{ $fromLabel }} to {{ $toLabel }} @endif</div>

        @if (! $group)
            @if (count($groups) > 0)
                <p class="text-muted">Your active company isn't in a group. Switch to a grouped company (F1), or pick a group:</p>
                @include('livewire.reports.partials.group-report-shell')
            @else
                <p class="text-muted">No company group exists yet — create one under Companies › Groups.</p>
            @endif
        @elseif (isset($report['error']))
            <p style="color:#b45309">{{ $report['error'] }}</p>
        @else
            @include('livewire.reports.partials.group-report-shell')

            <table class="zb-rtable">
                <tbody>
                    <tr><td><strong>Trading income</strong> <span class="text-muted" style="font-size:.72rem">(inter-company sales eliminated)</span></td>
                        <td style="text-align:right">{{ \App\Services\BalanceService::money($report['trading_income']) }}</td></tr>
                    <tr><td>Closing stock <span class="text-muted" style="font-size:.72rem">(group value, unrealised removed)</span></td>
                        <td style="text-align:right">{{ \App\Services\BalanceService::money($report['closing_stock']) }}</td></tr>
                    <tr><td><strong>Trading expenses</strong> <span class="text-muted" style="font-size:.72rem">(inter-company purchases eliminated)</span></td>
                        <td style="text-align:right">({{ \App\Services\BalanceService::money($report['trading_expense']) }})</td></tr>
                    <tr><td>Opening stock</td>
                        <td style="text-align:right">({{ \App\Services\BalanceService::money($report['opening_stock']) }})</td></tr>
                    <tr style="font-weight:600;border-top:1px solid color-mix(in srgb, var(--zb-evergreen, #0B6E4F) 30%, transparent)">
                        <td>Gross {{ $report['gross_profit'] >= 0 ? 'Profit' : 'Loss' }}</td>
                        <td style="text-align:right">{{ \App\Services\BalanceService::money(abs($report['gross_profit'])) }}</td></tr>
                    <tr><td>Indirect incomes</td><td style="text-align:right">{{ \App\Services\BalanceService::money($report['indirect_income']) }}</td></tr>
                    <tr><td>Indirect expenses</td><td style="text-align:right">({{ \App\Services\BalanceService::money($report['indirect_expense']) }})</td></tr>
                </tbody>
                <tfoot>
                    <tr style="font-weight:700;border-top:2px solid var(--zb-evergreen, #0B6E4F)">
                        <td>Group Nett {{ $report['is_profit'] ? 'Profit' : 'Loss' }}
                            <span class="text-muted" style="font-size:.72rem">
                                {{ $report['tie_check'] ? '· ties: Σ member nets − Δunrealised' : '· ⚠ tie check failed — inspect the adjustments panel' }}
                            </span>
                        </td>
                        <td style="text-align:right">{{ \App\Services\BalanceService::money(abs($report['net'])) }}</td>
                    </tr>
                </tfoot>
            </table>

            {{-- per-company drill --}}
            <table class="zb-rtable" style="margin-top:.9rem">
                <thead><tr><th>Member company</th><th style="text-align:right">Individual net</th></tr></thead>
                <tbody>
                    @foreach ($report['member_nets'] as $m)
                        <tr>
                            <td>{{ $m['company'] }} <span class="text-muted" style="font-size:.72rem">— open its P&amp;L after switching company (F1)</span></td>
                            <td style="text-align:right">{{ \App\Services\BalanceService::money($m['net']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="text-muted"><td>Δ unrealised profit over the period</td>
                        <td style="text-align:right">({{ \App\Services\BalanceService::money($report['unrealised']['total'] - $report['unrealised_at_from']) }})</td></tr>
                </tbody>
            </table>
        @endif
    </div>
</div>
