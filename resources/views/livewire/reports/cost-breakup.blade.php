@php($__rows = $rows)
<div class="zb-report"
     x-data="costBreakup({
        title: 'Cost Centre Breakup',
        drillUrl: @js(route('reports.cost-centre', ['costCentre' => '__id__'])),
        gatewayUrl: @js(route('gateway')),
        from: @js($from),
        to: @js($to)
     })">

    <div class="zb-report-head">
        <div class="zb-report-title">Cost Centre Breakup</div>
        <div class="zb-report-period">
            <label>Period <span class="zb-kbd">F2</span></label>
            <input type="date" id="report-from" class="form-control zb-field" data-zb-noselect wire:model.blur="from">
            <span>to</span>
            <input type="date" id="report-to" class="form-control zb-field" data-zb-noselect wire:model.blur="to">
        </div>
    </div>

    <div class="zb-panel">
        <div id="report-surface" tabindex="-1" class="zb-report-surface">
            <table class="zb-rtable zb-cc-table">
                <thead>
                    <tr>
                        <th>Cost Centre / Ledger</th>
                        <th class="zb-vt-right">Amount (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($__rows as $i => $row)
                        <tr data-cc-row
                            @if ($row['kind'] === 'centre') data-cc-id="{{ $row['cost_centre_id'] }}" @endif
                            class="zb-rrow zb-cc-{{ $row['kind'] }}"
                            :class="{ 'is-active': activeIdx === {{ $i }} && '{{ $row['cost_centre_id'] ?? '' }}' !== '' }"
                            @if ($row['kind'] === 'centre') @click="activeIdx = {{ $i }}; drill()" @mousemove="activeIdx = {{ $i }}" @endif>
                            <td>
                                @if ($row['kind'] === 'ledger')<span class="zb-cc-ledger">{{ $row['label'] }}</span>@else{{ $row['label'] }}@endif
                                @if ($row['kind'] === 'centre')<span class="zb-cc-drill">↵ drill</span>@endif
                            </td>
                            <td class="zb-vt-right">{{ $row['amount'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="zb-list-empty">No cost allocations in this period.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="zb-cc-grand">
                        <td class="zb-vt-right">Grand Total (all cost centres)</td>
                        <td class="zb-vt-right">{{ $grandTotal }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="zb-report-status">
            <span class="zb-report-fy">{{ $fromLabel }} to {{ $toLabel }} · analytical — the ledger balances are unaffected</span>
        </div>
        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">↑</span><span class="zb-kbd">↓</span> move · <span class="zb-kbd">Enter</span> drill to voucher(s) ·
            <span class="zb-kbd">F2</span> period · <span class="zb-kbd">Esc</span> back
        </p>
    </div>
</div>
