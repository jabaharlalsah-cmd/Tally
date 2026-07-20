<div class="zb-ws" style="max-width:1080px;margin:0 auto" x-data="groupReport()">
    <div class="zb-panel">
        <div class="zb-panel-title">Group Balance Sheet @if ($group) — {{ $group->name }} · as at {{ $toLabel }} @endif</div>

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

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                {{-- LIABILITIES --}}
                <table class="zb-rtable">
                    <thead><tr><th>Liabilities</th><th style="text-align:right">Amount</th></tr></thead>
                    <tbody>
                        @foreach ($report['liability_sections'] as $si => $section)
                            <tr class="zb-gr-row" data-gr-row="L{{ $si }}" style="cursor:pointer">
                                <td><strong>{{ $section['name'] }}</strong></td>
                                <td style="text-align:right">{{ \App\Services\BalanceService::money(-$section['closing']) }}</td>
                            </tr>
                            @foreach ($section['rows'] as $r)
                                <tr class="zb-gr-child" data-gr-child-of="L{{ $si }}" style="display:none;background:color-mix(in srgb, var(--zb-evergreen, #0B6E4F) 3%, transparent)">
                                    <td style="padding-left:1.6rem">{{ $r['ledger'] }} <span class="text-muted" style="font-size:.72rem">· {{ $memberNames[$r['company_id']] ?? '' }}</span>
                                        @if ($r['elimination'] !== 0)<span class="zb-ic-badge">eliminated {{ \App\Services\BalanceService::money(abs($r['elimination'])) }}</span>@endif
                                    </td>
                                    <td style="text-align:right">{{ \App\Services\BalanceService::money(-$r['adjusted_closing']) }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                        @if ($report['is_profit'])
                            <tr><td><strong>Nett Profit</strong> <span class="text-muted" style="font-size:.72rem">(group, ties to Group P&amp;L)</span></td>
                                <td style="text-align:right">{{ \App\Services\BalanceService::money($report['net']) }}</td></tr>
                        @endif
                        @if ($report['consolidation_adjustment'] !== 0)
                            <tr><td><strong>Consolidation Adjustments</strong> <span class="text-muted" style="font-size:.72rem">(prior-period unrealised profit — report line, never posted)</span></td>
                                <td style="text-align:right">{{ \App\Services\BalanceService::money($report['consolidation_adjustment']) }}</td></tr>
                        @endif
                    </tbody>
                    <tfoot><tr style="font-weight:700;border-top:2px solid var(--zb-evergreen, #0B6E4F)">
                        <td>Total</td><td style="text-align:right">{{ \App\Services\BalanceService::money($report['liability_total']) }}</td>
                    </tr></tfoot>
                </table>

                {{-- ASSETS --}}
                <table class="zb-rtable">
                    <thead><tr><th>Assets</th><th style="text-align:right">Amount</th></tr></thead>
                    <tbody>
                        @foreach ($report['asset_sections'] as $si => $section)
                            <tr class="zb-gr-row" data-gr-row="A{{ $si }}" style="cursor:pointer">
                                <td><strong>{{ $section['name'] }}</strong></td>
                                <td style="text-align:right">{{ \App\Services\BalanceService::money($section['closing']) }}</td>
                            </tr>
                            @foreach ($section['rows'] as $r)
                                <tr class="zb-gr-child" data-gr-child-of="A{{ $si }}" style="display:none;background:color-mix(in srgb, var(--zb-evergreen, #0B6E4F) 3%, transparent)">
                                    <td style="padding-left:1.6rem">{{ $r['ledger'] }} <span class="text-muted" style="font-size:.72rem">· {{ $memberNames[$r['company_id']] ?? '' }}</span>
                                        @if ($r['elimination'] !== 0)<span class="zb-ic-badge">eliminated {{ \App\Services\BalanceService::money(abs($r['elimination'])) }}</span>@endif
                                    </td>
                                    <td style="text-align:right">{{ \App\Services\BalanceService::money($r['adjusted_closing']) }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                        @if ($report['closing_stock'] !== 0)
                            <tr><td><strong>Stock-in-Hand</strong> <span class="text-muted" style="font-size:.72rem">(group value — unrealised inter-company profit of {{ \App\Services\BalanceService::money($report['unrealised_elimination']) }} removed)</span></td>
                                <td style="text-align:right">{{ \App\Services\BalanceService::money($report['closing_stock']) }}</td></tr>
                        @endif
                        @if (! $report['is_profit'])
                            <tr><td><strong>Nett Loss</strong></td><td style="text-align:right">{{ \App\Services\BalanceService::money(-$report['net']) }}</td></tr>
                        @endif
                    </tbody>
                    <tfoot>
                        @if (! $report['balanced'])
                            {{-- The per-company convention (Phase 6D/7A): a member book whose
                                 openings don't balance shows a Difference line — the group
                                 report inherits it faithfully rather than hiding it. --}}
                            <tr class="text-muted"><td>Difference in opening balances (inherited from member books)</td>
                                <td style="text-align:right">{{ \App\Services\BalanceService::money(abs($report['difference'])) }}</td></tr>
                        @endif
                        <tr style="font-weight:700;border-top:2px solid var(--zb-evergreen, #0B6E4F)">
                            <td>Total {{ $report['balanced'] ? '· balanced' : '' }}</td>
                            <td style="text-align:right">{{ \App\Services\BalanceService::money($report['asset_total']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>
</div>
