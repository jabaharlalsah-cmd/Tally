<div class="zb-ws" style="max-width:1000px;margin:0 auto" x-data="groupReport()">
    <div class="zb-panel">
        <div class="zb-panel-title">Group Trial Balance @if ($group) — {{ $group->name }} @endif</div>

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
                <thead><tr><th>Account group / ledger</th><th style="text-align:right">Debit</th><th style="text-align:right">Credit</th></tr></thead>
                <tbody>
                    @foreach ($report['sections'] as $si => $section)
                        <tr class="zb-gr-row" data-gr-row="{{ $si }}" style="cursor:pointer">
                            <td><strong>{{ $section['name'] }}</strong> <span class="text-muted" style="font-size:.72rem">({{ count($section['rows']) }} ledger rows — Enter expands)</span></td>
                            <td style="text-align:right">{{ $section['closing'] > 0 ? \App\Services\BalanceService::money($section['closing']) : '' }}</td>
                            <td style="text-align:right">{{ $section['closing'] < 0 ? \App\Services\BalanceService::money(-$section['closing']) : '' }}</td>
                        </tr>
                        @foreach ($section['rows'] as $r)
                            <tr class="zb-gr-child" data-gr-child-of="{{ $si }}" style="display:none;background:color-mix(in srgb, var(--zb-evergreen, #0B6E4F) 3%, transparent)">
                                <td style="padding-left:1.6rem">
                                    {{ $r['ledger'] }} <span class="text-muted" style="font-size:.72rem">· {{ $memberNames[$r['company_id']] ?? '' }}</span>
                                    @if ($r['elimination'] !== 0)
                                        <span class="zb-ic-badge">eliminated {{ \App\Services\BalanceService::money(abs($r['elimination'])) }}</span>
                                    @endif
                                </td>
                                <td style="text-align:right">{{ $r['adjusted_closing'] > 0 ? \App\Services\BalanceService::money($r['adjusted_closing']) : '' }}</td>
                                <td style="text-align:right">{{ $r['adjusted_closing'] < 0 ? \App\Services\BalanceService::money(-$r['adjusted_closing']) : '' }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="font-weight:700;border-top:2px solid var(--zb-evergreen, #0B6E4F)">
                        <td>Total {{ $report['balanced'] ? '· balanced' : '· ⚠ NOT BALANCED' }}</td>
                        <td style="text-align:right">{{ \App\Services\BalanceService::money($report['total_dr']) }}</td>
                        <td style="text-align:right">{{ \App\Services\BalanceService::money($report['total_cr']) }}</td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>
</div>
