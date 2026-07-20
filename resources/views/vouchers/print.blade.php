<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $documentTitle }} · {{ $voucher->displayNumber() }} — ZeroBook</title>
    <style>
        /* ZeroBook — own invoice design (brand palette inlined so the print
           page is fully self-contained). Deep evergreen identity. */
        :root {
            --evergreen: #0B6E4F;
            --evergreen-700: #084C37;
            --ink: #14231E;
            --muted: #5F726A;
            --faint: #8B9A92;
            --line: #E4E7E3;
            --line-strong: #Cfd6d0;
            --tint: #E6F2EC;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            background: #eef1ee;
            color: var(--ink);
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .zbp-toolbar {
            max-width: 820px;
            margin: 1rem auto 0;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            padding: 0 0.5rem;
        }
        .zbp-btn {
            border: 1px solid var(--line-strong);
            background: #fff;
            color: var(--evergreen-700);
            font-weight: 600;
            font-size: 0.85rem;
            padding: 0.45rem 0.9rem;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
        }
        .zbp-btn-primary {
            background: var(--evergreen);
            border-color: var(--evergreen);
            color: #fff;
        }
        .zbp-sheet {
            max-width: 820px;
            margin: 1rem auto 3rem;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 2.4rem 2.6rem;
            box-shadow: 0 6px 26px rgba(20, 35, 30, 0.08);
        }
        .zbp-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 3px solid var(--evergreen);
            padding-bottom: 1rem;
            margin-bottom: 1.3rem;
        }
        .zbp-brand {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 1.7rem;
            font-weight: 700;
            color: var(--evergreen-700);
            letter-spacing: 0.01em;
        }
        .zbp-brand small {
            display: block;
            font-family: inherit;
            font-size: 0.72rem;
            font-weight: 500;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--faint);
            margin-top: 0.15rem;
        }
        .zbp-company {
            text-align: right;
            font-size: 0.86rem;
            color: var(--muted);
            max-width: 20rem;
        }
        .zbp-company strong {
            display: block;
            color: var(--ink);
            font-size: 1rem;
            margin-bottom: 0.15rem;
        }
        .zbp-doc-title {
            text-align: center;
            font-size: 1.05rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            color: var(--evergreen-700);
            background: var(--tint);
            border-radius: 8px;
            padding: 0.45rem;
            margin-bottom: 1.3rem;
        }
        .zbp-meta {
            display: flex;
            justify-content: space-between;
            gap: 1.5rem;
            margin-bottom: 1.4rem;
        }
        .zbp-party {
            flex: 1;
        }
        .zbp-label {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--faint);
            margin-bottom: 0.25rem;
        }
        .zbp-party-name {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--ink);
        }
        .zbp-party-detail {
            font-size: 0.84rem;
            color: var(--muted);
            line-height: 1.5;
            margin-top: 0.2rem;
        }
        .zbp-refbox {
            text-align: right;
            font-size: 0.86rem;
            color: var(--muted);
            min-width: 13rem;
        }
        .zbp-refbox div {
            margin-bottom: 0.3rem;
        }
        .zbp-refbox strong {
            color: var(--ink);
        }
        table.zbp-lines {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.2rem;
        }
        table.zbp-lines th {
            text-align: left;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #fff;
            background: var(--evergreen);
            padding: 0.5rem 0.7rem;
        }
        table.zbp-lines th.num, table.zbp-lines td.num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        table.zbp-lines td {
            padding: 0.55rem 0.7rem;
            border-bottom: 1px solid var(--line);
            font-size: 0.9rem;
        }
        table.zbp-lines tr:last-child td { border-bottom: 1px solid var(--line-strong); }
        .zbp-drcr {
            display: inline-block;
            font-size: 0.66rem;
            font-weight: 700;
            color: var(--evergreen-700);
            border: 1px solid var(--line-strong);
            border-radius: 4px;
            padding: 0 0.3rem;
            margin-right: 0.4rem;
        }
        .zbp-hsn {
            display: block;
            font-size: 0.72rem;
            color: var(--faint);
        }
        .zbp-subtotal-row td {
            border-bottom: 1px solid var(--line) !important;
            padding-top: 0.6rem;
            font-weight: 600;
        }
        .zbp-tax-row td {
            border-bottom: 1px dashed var(--line) !important;
            color: var(--muted);
            font-size: 0.86rem;
        }
        .zbp-total-row td {
            border-bottom: none !important;
            padding-top: 0.8rem;
            font-weight: 700;
            font-size: 1rem;
        }
        .zbp-total-row .num {
            border-top: 2px solid var(--evergreen);
            color: var(--evergreen-700);
        }
        .zbp-words {
            background: #fafbfa;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 0.7rem 0.9rem;
            font-size: 0.88rem;
            margin-bottom: 1.1rem;
        }
        .zbp-words .zbp-label { margin-bottom: 0.2rem; }
        .zbp-words strong { color: var(--ink); }
        .zbp-narr {
            font-size: 0.84rem;
            color: var(--muted);
            margin-bottom: 1.6rem;
        }
        .zbp-foot {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 2.4rem;
            border-top: 1px solid var(--line);
            padding-top: 0.8rem;
            font-size: 0.78rem;
            color: var(--faint);
        }
        .zbp-sign {
            text-align: right;
        }
        .zbp-sign-line {
            margin-top: 2.2rem;
            border-top: 1px solid var(--line-strong);
            width: 12rem;
            padding-top: 0.3rem;
            color: var(--muted);
        }

        /* ---- print-specific: hide the on-screen chrome, tighten the page ---- */
        @media print {
            html, body { background: #fff; }
            .zbp-toolbar { display: none !important; }
            .zbp-sheet {
                margin: 0;
                border: none;
                border-radius: 0;
                box-shadow: none;
                max-width: none;
                padding: 0.4in 0.5in;
            }
            table.zbp-lines th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .zbp-doc-title, .zbp-drcr, .zbp-words { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        @page { margin: 12mm; }
    </style>
</head>
<body>
    <div class="zbp-toolbar">
        <a class="zbp-btn" href="{{ route('daybook') }}">← Day Book</a>
        <button class="zbp-btn zbp-btn-primary" type="button" onclick="window.print()">Print (Ctrl+P)</button>
    </div>

    <div class="zbp-sheet">
        {{-- Company header --}}
        <div class="zbp-head">
            <div class="zbp-brand">
                {{ $company['product'] ?? 'ZeroBook' }}
                <small>Keeping the books at zero difference</small>
            </div>
            <div class="zbp-company">
                <strong>{{ $company['company'] ?? 'ZeroBook Foundation Co.' }}</strong>
                {{ $regime === 'vat' ? 'Tax Invoice (Nepal VAT)' : ($items->isNotEmpty() ? 'Item Invoice' : 'Accounting Invoice') }}
                @if ($companyTaxId)<br>{{ $taxIdLabel }}: {{ $companyTaxId }}@endif
            </div>
        </div>

        <div class="zbp-doc-title">{{ $documentTitle }}</div>

        {{-- Party + reference metadata --}}
        <div class="zbp-meta">
            <div class="zbp-party">
                @if ($isInvoice && $party)
                    <div class="zbp-label">{{ $voucher->type === 'purchase' ? 'Supplier' : 'Bill To' }}</div>
                    <div class="zbp-party-name">{{ $party->mailing_name ?: $party->name }}</div>
                    <div class="zbp-party-detail">
                        @if ($party->address){!! nl2br(e($party->address)) !!}<br>@endif
                        @if ($regime !== 'vat' && $party->state){{ $party->state }}@if($party->pincode) — {{ $party->pincode }}@endif<br>
                        @elseif ($party->pincode)PIN: {{ $party->pincode }}<br>@endif
                        @if ($party->gstin){{ $taxIdLabel }}: {{ $party->gstin }}<br>@endif
                        @if ($regime !== 'vat' && $party->pan)PAN: {{ $party->pan }}@endif
                    </div>
                @else
                    <div class="zbp-label">Voucher</div>
                    <div class="zbp-party-name">{{ $voucher->displayNumber() }}</div>
                    <div class="zbp-party-detail">{{ \App\Models\Voucher::TYPES[$voucher->type]['label'] ?? ucfirst($voucher->type) }} · double-entry voucher</div>
                @endif
            </div>
            <div class="zbp-refbox">
                <div>Voucher No: <strong>{{ $voucher->displayNumber() }}</strong></div>
                <div>Date: <strong>{{ $voucher->date->format('d-M-Y') }}</strong></div>
                @if (! empty($referenceOriginal))
                    <div>Against Invoice: <strong>{{ $referenceOriginal }}</strong></div>
                @endif
                @if ($voucher->reference_no)
                    <div>Ref / Supplier Inv.: <strong>{{ $voucher->reference_no }}</strong></div>
                @endif
                @if ($voucher->reference_date)
                    <div>Ref Date: <strong>{{ $voucher->reference_date->format('d-M-Y') }}</strong></div>
                @endif
            </div>
        </div>

        {{-- Lines --}}
        @if ($isInvoice)
            @php($isItemInvoice = $items->isNotEmpty())
            <table class="zbp-lines">
                <thead>
                    <tr>
                        <th style="width:3rem">#</th>
                        <th>@if($isItemInvoice)Stock Item @else Particulars @endif</th>
                        @if ($isItemInvoice)
                            <th class="num" style="width:6rem">Qty</th>
                            <th class="num" style="width:8rem">Rate (₹)</th>
                        @endif
                        <th class="num" style="width:12rem">Amount (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($isItemInvoice)
                        {{-- Item invoice: stock lines. Only the selling side is shown;
                             the weighted-average cost is never printed on a sale. --}}
                        @foreach ($items as $i => $it)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td>
                                    {{ $it['name'] }}
                                    @if ($hasGst && ($it['hsn_sac'] || $it['gst_rate'] !== null))
                                        <span class="zbp-hsn">@if($it['hsn_sac'])HSN/SAC {{ $it['hsn_sac'] }}@endif @if($it['gst_rate'] !== null)· {{ rtrim(rtrim(number_format($it['gst_rate'],2),'0'),'.') }}%@endif</span>
                                    @endif
                                </td>
                                <td class="num">{{ rtrim(rtrim(number_format($it['qty'], 4), '0'), '.') }}@if($it['unit']) {{ $it['unit'] }}@endif</td>
                                <td class="num">{{ \App\Support\Money::indianFormat($it['rate']) }}</td>
                                <td class="num">{{ \App\Support\Money::indianFormat($it['amount']) }}</td>
                            </tr>
                        @endforeach
                    @else
                        @foreach ($allocation as $i => $l)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td>
                                    {{ $l['ledger'] }}
                                    @if ($hasGst && ($l['hsn_sac'] || $l['gst_rate'] !== null))
                                        <span class="zbp-hsn">@if($l['hsn_sac'])HSN/SAC {{ $l['hsn_sac'] }}@endif @if($l['gst_rate'] !== null)· {{ rtrim(rtrim(number_format($l['gst_rate'],2),'0'),'.') }}%@endif</span>
                                    @endif
                                </td>
                                <td class="num">{{ \App\Support\Money::indianFormat($l['amount']) }}</td>
                            </tr>
                        @endforeach
                    @endif
                    @if ($hasGst)
                        <tr class="zbp-subtotal-row">
                            <td colspan="{{ $isItemInvoice ? 3 : 1 }}"></td>
                            <td class="num">Taxable Value</td>
                            <td class="num">{{ $taxableFormatted }}</td>
                        </tr>
                        @foreach ($taxBreakup as $t)
                            <tr class="zbp-tax-row">
                                <td colspan="{{ $isItemInvoice ? 3 : 1 }}"></td>
                                <td class="num">{{ $t['ledger'] }}</td>
                                <td class="num">{{ \App\Support\Money::indianFormat($t['amount']) }}</td>
                            </tr>
                        @endforeach
                    @endif
                    <tr class="zbp-total-row">
                        <td colspan="{{ $isItemInvoice ? 3 : 1 }}"></td>
                        <td class="num">{{ $hasGst ? ('Invoice Total (incl. '.($regime === 'vat' ? 'VAT' : 'GST').')') : 'Total' }}</td>
                        <td class="num">₹ {{ $totalFormatted }}</td>
                    </tr>
                </tbody>
            </table>
        @else
            <table class="zbp-lines">
                <thead>
                    <tr>
                        <th>Particulars</th>
                        <th class="num" style="width:10rem">Debit (₹)</th>
                        <th class="num" style="width:10rem">Credit (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($allEntries as $l)
                        <tr>
                            <td><span class="zbp-drcr">{{ $l['dr_cr'] }}</span>{{ $l['ledger'] }}</td>
                            <td class="num">{{ $l['dr_cr'] === 'Dr' ? \App\Support\Money::indianFormat($l['amount']) : '' }}</td>
                            <td class="num">{{ $l['dr_cr'] === 'Cr' ? \App\Support\Money::indianFormat($l['amount']) : '' }}</td>
                        </tr>
                    @endforeach
                    <tr class="zbp-total-row">
                        <td class="num">Total</td>
                        <td class="num">₹ {{ $totalFormatted }}</td>
                        <td class="num">₹ {{ $totalFormatted }}</td>
                    </tr>
                </tbody>
            </table>
        @endif

        {{-- Amount in words (Indian lakh/crore) --}}
        <div class="zbp-words">
            <div class="zbp-label">Amount in words</div>
            <strong>{{ $totalWords }}</strong>
        </div>

        @if ($voucher->narration)
            <div class="zbp-narr"><strong>Narration:</strong> {{ $voucher->narration }}</div>
        @endif

        <div class="zbp-foot">
            <div>
                Generated by {{ $company['product'] ?? 'ZeroBook' }} · This is a computer-generated document.
            </div>
            <div class="zbp-sign">
                For {{ $company['company'] ?? 'ZeroBook Foundation Co.' }}
                <div class="zbp-sign-line">Authorised Signatory</div>
            </div>
        </div>
    </div>
</body>
</html>
