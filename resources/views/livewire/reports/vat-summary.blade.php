@php($__rows = $rows)
<div class="zb-report"
     x-data="gstSummary({
        title: 'VAT Summary',
        drillUrl: @js(route('reports.ledger', ['ledger' => '__id__'])),
        gatewayUrl: @js(route('gateway')),
        from: @js($from),
        to: @js($to)
     })">

    <div class="zb-report-head">
        <div class="zb-report-title">VAT Summary <span class="text-muted" style="font-size:.75rem;font-weight:400">· Nepal</span></div>
        <div class="zb-report-period">
            <label>Period <span class="zb-kbd">F2</span></label>
            <input type="date" id="report-from" class="form-control zb-field" data-zb-noselect wire:model.blur="from">
            <span>to</span>
            <input type="date" id="report-to" class="form-control zb-field" data-zb-noselect wire:model.blur="to">
        </div>
    </div>

    <div class="zb-panel">
        @unless ($vatEnabled)
            <div class="zb-gst-off">
                VAT is currently <strong>off</strong>. Turn on <span class="zb-kbd">F11</span> → “Enable VAT (Nepal)”
                to record VAT on invoices. Figures below are for any VAT already posted.
            </div>
        @endunless

        <div id="report-surface" tabindex="-1" class="zb-report-surface">
            <table class="zb-rtable zb-gst-table">
                <thead>
                    <tr>
                        <th>Particulars</th>
                        <th class="zb-vt-right">Amount (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($__rows as $i => $row)
                        <tr data-gst-row data-ledger-id="{{ $row['ledger_id'] ?? '' }}"
                            class="zb-rrow zb-gst-{{ $row['kind'] }}"
                            :class="{ 'is-active': activeIdx === {{ $i }} && '{{ $row['ledger_id'] ?? '' }}' !== '' }"
                            @if (!empty($row['ledger_id'])) @click="activeIdx = {{ $i }}; drill()" @mousemove="activeIdx = {{ $i }}" @endif>
                            <td>
                                {{ $row['label'] }}
                                @if (!empty($row['ledger_id']))<span class="zb-gst-drill">↵ drill</span>@endif
                            </td>
                            <td class="zb-vt-right">{{ $row['amount'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="zb-report-status">
            <span class="zb-report-fy">{{ $fromLabel }} to {{ $toLabel }}
                @if ($companyPan) · Company PAN: {{ $companyPan }} @endif</span>
        </div>
        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">↑</span><span class="zb-kbd">↓</span> move · <span class="zb-kbd">Enter</span> drill to ledger vouchers ·
            <span class="zb-kbd">F2</span> period · <span class="zb-kbd">Esc</span> back
            &nbsp;·&nbsp; Net = Output VAT − Input VAT credit. Return filing arrives in a later phase.
        </p>
    </div>
</div>
