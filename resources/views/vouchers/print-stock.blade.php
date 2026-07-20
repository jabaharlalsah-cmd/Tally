<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $documentTitle }} · {{ $voucher->displayNumber() }} — ZeroBook</title>
    <style>
        :root {
            --evergreen: #0B6E4F; --evergreen-700: #084C37; --ink: #14231E;
            --muted: #5F726A; --faint: #8B9A92; --line: #E4E7E3;
            --line-strong: #Cfd6d0; --tint: #E6F2EC;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #eef1ee; color: var(--ink);
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif; -webkit-font-smoothing: antialiased; }
        .zbp-toolbar { max-width: 720px; margin: 1rem auto 0; display: flex; justify-content: flex-end; gap: .5rem; padding: 0 .5rem; }
        .zbp-btn { border: 1px solid var(--line-strong); background: #fff; color: var(--evergreen-700);
            padding: .45rem .9rem; border-radius: .5rem; font-size: .85rem; font-weight: 600; text-decoration: none; cursor: pointer; }
        .zbp-btn-primary { background: var(--evergreen); border-color: var(--evergreen); color: #fff; }
        .zbp-doc { max-width: 720px; margin: .75rem auto 2rem; background: #fff; border: 1px solid var(--line);
            border-radius: .75rem; padding: 2rem 2.25rem; }
        .zbp-head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid var(--evergreen); padding-bottom: 1rem; }
        .zbp-brand { font-size: 1.5rem; font-weight: 800; color: var(--evergreen); letter-spacing: -.02em; }
        .zbp-brand small { display: block; font-size: .6rem; font-weight: 600; letter-spacing: .18em; color: var(--faint); text-transform: uppercase; margin-top: .1rem; }
        .zbp-company { text-align: right; font-size: .82rem; color: var(--muted); }
        .zbp-company strong { display: block; color: var(--ink); font-size: 1rem; }
        .zbp-title { text-align: center; background: var(--tint); color: var(--evergreen-700); font-weight: 700;
            letter-spacing: .12em; text-transform: uppercase; padding: .6rem; border-radius: .4rem; margin: 1.25rem 0; }
        .zbp-meta { display: flex; justify-content: space-between; font-size: .85rem; color: var(--muted); margin-bottom: 1rem; }
        .zbp-meta strong { color: var(--ink); }
        table.zbp-lines { width: 100%; border-collapse: collapse; font-size: .88rem; }
        table.zbp-lines th { text-align: left; background: var(--evergreen); color: #fff; padding: .5rem .6rem; font-weight: 600; }
        table.zbp-lines th.num, table.zbp-lines td.num { text-align: right; }
        table.zbp-lines td { padding: .5rem .6rem; border-bottom: 1px solid var(--line); }
        .zbp-dir { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; padding: .1rem .4rem; border-radius: .3rem; }
        .zbp-dir-in { background: var(--tint); color: var(--evergreen-700); }
        .zbp-dir-out { background: #FCEBE9; color: #8A2B22; }
        .zbp-narr { margin-top: 1.25rem; font-size: .85rem; color: var(--muted); }
        .zbp-narr .zbp-label { font-size: .62rem; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; color: var(--faint); }
        .zbp-note { margin-top: 1rem; font-size: .74rem; color: var(--faint); }
        @media print { body { background: #fff; } .zbp-toolbar { display: none; } .zbp-doc { border: none; margin: 0; max-width: none; } }
    </style>
</head>
<body>
    <div class="zbp-toolbar">
        <a href="{{ route('daybook') }}" class="zbp-btn">← Day Book</a>
        <button type="button" class="zbp-btn zbp-btn-primary" onclick="window.print()">Print (Ctrl+P)</button>
    </div>

    <div class="zbp-doc">
        <div class="zbp-head">
            <div class="zbp-brand">ZeroBook <small>Keeping the books at zero difference</small></div>
            <div class="zbp-company">
                <strong>{{ $company['company'] ?? 'ZeroBook Foundation Co.' }}</strong>
                Inventory Movement — no ledger effect
            </div>
        </div>

        <div class="zbp-title">{{ $documentTitle }}</div>

        <div class="zbp-meta">
            <div>{{ $summary }}</div>
            <div>Voucher No: <strong>{{ $voucher->displayNumber() }}</strong> · Date: <strong>{{ $voucher->date->format('d-M-Y') }}</strong></div>
        </div>

        <table class="zbp-lines">
            <thead>
                <tr>
                    <th style="width:3rem">#</th>
                    <th>Stock Item</th>
                    <th>Godown</th>
                    <th style="width:5rem">In/Out</th>
                    <th class="num" style="width:6rem">Qty</th>
                    <th class="num" style="width:7rem">Rate (₹)</th>
                    <th class="num" style="width:8rem">Value (₹)</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $i => $r)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $r['item'] }}</td>
                        <td>{{ $r['godown'] }}</td>
                        <td><span class="zbp-dir zbp-dir-{{ $r['direction'] }}">{{ $r['direction'] === 'in' ? 'In' : 'Out' }}</span></td>
                        <td class="num">{{ rtrim(rtrim(number_format($r['qty'], 4), '0'), '.') }}@if($r['unit']) {{ $r['unit'] }}@endif</td>
                        <td class="num">{{ \App\Support\Money::indianFormat($r['rate']) }}</td>
                        <td class="num">{{ \App\Support\Money::indianFormat($r['value']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center;color:var(--muted)">Stock-take — counted quantity matched book; no adjustment.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($voucher->narration)
            <div class="zbp-narr"><span class="zbp-label">Narration</span><br>{{ $voucher->narration }}</div>
        @endif

        <p class="zbp-note">
            Rate/Value shown is the weighted-average cost. This is an internal stock document — it moves quantity and value
            only and posts nothing to the ledgers, so it does not appear in the Trial Balance, P&amp;L or Balance Sheet.
        </p>
    </div>
</body>
</html>
