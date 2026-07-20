@php
    $asOfRaw = $report['as_of'] ?? ($asOf ?? '');
    $fromRaw = '';
@endphp
<div class="zb-report"
     x-data="scenarioImpact({
        masterUrl: @js(route('reports.scenario-master')),
        gatewayUrl: @js(route('gateway')),
        ledgerUrl: @js(route('reports.ledger', ['ledger' => '__id__'])),
        from: @js($fromRaw),
        to: @js($asOfRaw)
     })">

    <div class="zb-report-head">
        <div class="zb-report-title">Scenario Impact</div>
        <div class="zb-report-period">
            @if ($selected)
                <span>{{ $selected->name }} · as of {{ $asOfRaw }}</span>
            @endif
            <a href="{{ route('reports.scenario-master') }}" class="zb-report-toggle"><span class="zb-kbd">Esc</span> Master</a>
        </div>
    </div>

    <div class="zb-panel">
        <div class="zb-create-row" style="display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap;padding:1rem 1rem 0">
            <div>
                <label class="muted" style="display:block;font-size:.72rem;font-weight:600;margin-bottom:.2rem">Scenario</label>
                <select wire:model.live="scenarioId" class="form-control zb-field" style="width:16rem">
                    @forelse ($list as $s)
                        <option value="{{ $s['id'] }}">{{ $s['name'] }}</option>
                    @empty
                        <option value="">No active scenarios</option>
                    @endforelse
                </select>
            </div>
            <div>
                <label class="muted" for="scn-asof" style="display:block;font-size:.72rem;font-weight:600;margin-bottom:.2rem">As of</label>
                <input type="date" id="scn-asof" wire:model.live="asOf" class="form-control zb-field" style="width:11rem">
            </div>
        </div>

        <div id="scenario-impact" tabindex="-1" class="zb-report-surface" data-zb-form style="padding:1rem">
            @if (! $selected)
                <p class="muted">Select a scenario to see its impact.</p>
            @else
                {{-- Headline: real vs real+scenario --}}
                <table class="zb-rtable" style="max-width:760px">
                    <thead>
                        <tr>
                            <th>Metric</th>
                            <th class="zb-vt-right">Real books</th>
                            <th class="zb-vt-right">With scenario</th>
                            <th class="zb-vt-right">Delta</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($metricLabels as $key => $label)
                            @if (isset($report['headline'][$key]))
                                @php $h = $report['headline'][$key]; $d = (int) $h['delta']; @endphp
                                <tr class="zb-rrow" wire:key="hl-{{ $key }}">
                                    <td>{{ $label }}</td>
                                    <td class="zb-vt-right">{{ $money($h['real']) }}</td>
                                    <td class="zb-vt-right">{{ $money($h['scenario']) }}</td>
                                    <td class="zb-vt-right {{ $d >= 0 ? 'text-success' : 'text-danger' }}">{{ $d >= 0 ? '+' : '-' }}{{ $money($d) }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>

                {{-- Per-ledger closing deltas (drillable) --}}
                @if (! empty($report['ledger_delta']))
                    <h3 class="muted" style="font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin:1.4rem 0 .5rem">Ledger deltas</h3>
                    <table class="zb-rtable" style="max-width:760px">
                        <thead>
                            <tr>
                                <th>Ledger</th>
                                <th class="zb-vt-right">Real</th>
                                <th class="zb-vt-right">With scenario</th>
                                <th class="zb-vt-right">Delta</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['ledger_delta'] as $row)
                                @php $rd = (int) $row['delta']; @endphp
                                <tr data-ledger-row data-ledger-id="{{ $row['id'] }}" class="zb-rrow zb-r-ledger" wire:key="ld-{{ $row['id'] }}">
                                    <td><a href="{{ route('reports.ledger', ['ledger' => $row['id']]) }}">{{ $row['name'] }}</a></td>
                                    <td class="zb-vt-right">{{ $money($row['real']) }}</td>
                                    <td class="zb-vt-right">{{ $money($row['scenario']) }}</td>
                                    <td class="zb-vt-right {{ $rd >= 0 ? 'text-success' : 'text-danger' }}">{{ $rd >= 0 ? '+' : '-' }}{{ $money($rd) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                {{-- The scenario's provisional vouchers --}}
                <h3 class="muted" style="font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin:1.4rem 0 .5rem">Provisional vouchers ({{ $report['voucher_count'] }})</h3>
                <table class="zb-rtable" style="max-width:820px">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>No.</th>
                            <th>Narration</th>
                            <th class="zb-vt-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['vouchers'] as $v)
                            <tr class="zb-rrow" wire:key="vch-{{ $loop->index }}">
                                <td>{{ $v['date'] }}</td>
                                <td>{{ ucfirst($v['type']) }}</td>
                                <td>{{ $v['number'] }}</td>
                                <td class="muted">{{ $v['narration'] }}</td>
                                <td class="zb-vt-right">{{ $money($v['amount']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="muted">No provisional vouchers in this scenario yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @endif
        </div>
        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">↑</span><span class="zb-kbd">↓</span> move ·
            <span class="zb-kbd">Enter</span> drill to ledger ·
            <span class="zb-kbd">F2</span> as-of date ·
            <span class="zb-kbd">Esc</span> master
        </p>
    </div>
</div>
