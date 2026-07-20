@php($__rows = $rows)
<div class="zb-report"
     x-data="tdsSummary({
        title: 'TDS Deduction Summary',
        drillUrl: @js(route('reports.tds-deductee', ['section' => '__sid__', 'ledger' => '__did__'])),
        gatewayUrl: @js(route('gateway')),
        from: @js($from),
        to: @js($to)
     })">

    <div class="zb-report-head">
        <div class="zb-report-title">TDS Deduction Summary</div>
        <div class="zb-report-period">
            <label>Period <span class="zb-kbd">F2</span></label>
            <input type="date" id="report-from" class="form-control zb-field" data-zb-noselect wire:model.blur="from">
            <span>to</span>
            <input type="date" id="report-to" class="form-control zb-field" data-zb-noselect wire:model.blur="to">
        </div>
    </div>

    @unless ($enabled)
        <div class="zb-panel">
            <p class="zb-list-empty">
                TDS is switched off for this company. Turn it on with <span class="zb-kbd">F11</span> →
                <a href="{{ route('features') }}">Company Features</a>.
            </p>
        </div>
    @else
    <div class="zb-panel">
        <div class="zb-tds-cards">
            <div class="zb-tds-card">
                <div class="zb-tds-card-label">Base paid</div>
                <div class="zb-tds-card-value">{{ $total_base }}</div>
            </div>
            <div class="zb-tds-card">
                <div class="zb-tds-card-label">TDS deducted</div>
                <div class="zb-tds-card-value is-amber">{{ $total_deducted }}</div>
            </div>
            <div class="zb-tds-card">
                <div class="zb-tds-card-label">Remitted this year</div>
                <div class="zb-tds-card-value">{{ $remitted }}</div>
            </div>
            <div class="zb-tds-card">
                <div class="zb-tds-card-label">Still payable</div>
                <div class="zb-tds-card-value is-amber">{{ $total_outstanding }}</div>
            </div>
        </div>

        <div id="report-surface" tabindex="-1" class="zb-report-surface">
            <table class="zb-rtable zb-tds-rtable">
                <thead>
                    <tr>
                        <th>Section / Deductee</th>
                        <th>PAN</th>
                        <th class="zb-vt-right">Rate</th>
                        <th class="zb-vt-right">Base paid (₹)</th>
                        <th class="zb-vt-right">TDS deducted (₹)</th>
                        <th class="zb-vt-right">Still payable (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($__rows as $i => $row)
                        @php($drillable = $row['kind'] === 'deductee')
                        <tr data-tds-row
                            @if ($drillable)
                                data-tds-section="{{ $row['tds_section_id'] }}"
                                data-tds-deductee="{{ $row['deductee_ledger_id'] }}"
                            @endif
                            class="zb-rrow zb-tds-{{ $row['kind'] }}"
                            :class="{ 'is-active': activeIdx === {{ $i }} && {{ $drillable ? 'true' : 'false' }} }"
                            @if ($drillable) @click="activeIdx = {{ $i }}; drill()" @mousemove="activeIdx = {{ $i }}" @endif>
                            <td>
                                @if ($drillable)
                                    <span class="zb-tds-deductee-name">{{ $row['label'] }}</span>
                                    <span class="zb-tds-sub"> — {{ $row['sub'] }}</span>
                                    <span class="zb-cc-drill">↵ drill</span>
                                @else
                                    {{ $row['label'] }}
                                    @if ($row['sub'])<span class="zb-tds-sub"> — {{ $row['sub'] }}</span>@endif
                                @endif
                            </td>
                            <td class="zb-tds-pan">{{ $row['pan'] }}</td>
                            <td class="zb-vt-right">{{ $row['rate'] }}</td>
                            <td class="zb-vt-right">{{ $row['base'] }}</td>
                            <td class="zb-vt-right">{{ $row['deducted'] }}</td>
                            <td class="zb-vt-right">{{ $row['outstanding'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="zb-list-empty">No TDS deducted in this period.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="zb-cc-grand">
                        <td class="zb-vt-right" colspan="3">Grand Total — all sections</td>
                        <td class="zb-vt-right">{{ $total_base }}</td>
                        <td class="zb-vt-right">{{ $total_deducted }}</td>
                        <td class="zb-vt-right">{{ $total_outstanding }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="zb-report-status">
            <span class="zb-report-fy">
                {{ $fromLabel }} to {{ $toLabel }} · FY {{ $fy_label }} ·
                TDS Payable ledger closing {{ $payable_closing }}
            </span>
        </div>

        <p class="zb-tds-recon">
            “Still payable” is the tax deducted but not yet remitted. A remittance is an ordinary Payment
            (<strong>Dr</strong> TDS Payable / <strong>Cr</strong> bank) — one challan clears many deductions and carries
            no section tag, so it is allocated oldest-first across the year’s deductions. The column therefore
            sums to the TDS Payable ledger’s closing balance of <strong>{{ $payable_closing }}</strong>.
        </p>
        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">↑</span><span class="zb-kbd">↓</span> move · <span class="zb-kbd">Enter</span> drill to voucher(s) ·
            <span class="zb-kbd">F2</span> period · <span class="zb-kbd">Esc</span> back
        </p>
    </div>
    @endunless
</div>
