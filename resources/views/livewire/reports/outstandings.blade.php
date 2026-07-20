@php($__rows = $rows)
<div class="zb-report"
     x-data="outstandings({
        title: @js($title),
        billUrl: @js(route('reports.bill', ['ledger' => '__id__'])),
        gatewayUrl: @js(route('gateway'))
     })">

    <div class="zb-report-head">
        <div class="zb-report-title">{{ $title }}</div>
        <div class="zb-report-period">
            <label>As on <span class="zb-kbd">F2</span></label>
            <input type="date" id="report-to" class="form-control zb-field" data-zb-noselect wire:model.blur="to">
        </div>
    </div>

    <div class="zb-panel">
        <div id="report-surface" tabindex="-1" class="zb-report-surface">
            <table class="zb-rtable zb-out-table">
                <thead>
                    <tr>
                        <th>Party / Bill Ref</th>
                        <th style="width:6.5rem">Date</th>
                        <th style="width:6.5rem">Due</th>
                        <th style="width:5rem" class="zb-vt-right">Overdue</th>
                        <th style="width:9rem" class="zb-vt-right">Original</th>
                        <th style="width:9rem" class="zb-vt-right">{{ $colLabel }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($__rows as $i => $row)
                        <tr data-out-row
                            @if ($row['kind'] === 'bill') data-ledger-id="{{ $row['ledger_id'] }}" data-ref="{{ $row['ref'] }}" @endif
                            class="zb-rrow zb-out-{{ $row['kind'] }}"
                            :class="{ 'is-active': activeIdx === {{ $i }} && '{{ $row['ref'] ?? '' }}' !== '' }"
                            @if ($row['kind'] === 'bill') @click="activeIdx = {{ $i }}; drill()" @mousemove="activeIdx = {{ $i }}" @endif>
                            <td>
                                @if ($row['kind'] === 'bill')<span class="zb-out-ref">{{ $row['label'] }}</span>@else{{ $row['label'] }}@endif
                                @if ($row['kind'] === 'bill' && $row['overdue'])<span class="zb-out-overdue-tag">overdue</span>@endif
                            </td>
                            <td>{{ $row['date'] }}</td>
                            <td>{{ $row['due'] }}</td>
                            <td class="zb-vt-right">{{ $row['overdue'] }}</td>
                            <td class="zb-vt-right">{{ $row['original'] }}</td>
                            <td class="zb-vt-right">{{ $row['pending'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="zb-list-empty">No outstanding bills as on {{ $asOf }}.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="zb-out-grand">
                        <td colspan="5" class="zb-vt-right">Grand Total ({{ $group }})</td>
                        <td class="zb-vt-right">{{ $grandPending }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="zb-report-status" :class="{ 'is-off': !@js($reconciles) }">
            @if ($reconciles)
                <span class="zb-r-ok">✓ Reconciles with ledger balances</span>
            @else
                <span class="zb-r-bad">⚠ Outstanding does not reconcile</span>
            @endif
            <span class="zb-report-fy">
                Bills pending {{ $grandPending }} + on-account {{ $grandOnAccount }} = closing {{ $grandClosing }} · as on {{ $asOf }}
            </span>
        </div>
        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">↑</span><span class="zb-kbd">↓</span> move · <span class="zb-kbd">Enter</span> drill to voucher(s) ·
            <span class="zb-kbd">F2</span> as-on date · <span class="zb-kbd">Esc</span> back
        </p>
    </div>
</div>
