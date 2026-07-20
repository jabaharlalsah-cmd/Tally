<div class="zb-report">
    <div class="zb-report-head">
        <div class="zb-report-title">Lot Ledger</div>
        <div class="zb-report-period" style="gap:.6rem;align-items:flex-end">
            <div>
                <label for="ll-item">Stock item</label>
                <select id="ll-item" class="form-select zb-field" wire:model.live="stockItemId">
                    <option value="">— select an item —</option>
                    @foreach ($items as $it)
                        <option value="{{ $it->id }}">{{ $it->name }} ({{ $it->costing_method === 'weighted_average' ? 'Weighted Avg' : strtoupper($it->costing_method) }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="ll-asof">As of <span class="zb-kbd">F2</span></label>
                <input id="ll-asof" type="date" class="form-control zb-field" data-zb-noselect wire:model.live="asOf">
            </div>
        </div>
    </div>

    <div class="zb-panel">
        @if (! $item)
            <p class="zb-list-empty">
                Select a stock item above to view its FIFO / LIFO lot ledger — each surviving receipt
                tranche, how much is still on hand, at what rate, and its remaining value.
            </p>
        @elseif ($weightedAverage)
            <p class="zb-list-empty">No lots — this item uses weighted-average costing.</p>
        @else
            <p class="text-muted" style="font-size:.8rem;margin:-.2rem 0 .8rem">
                Remaining <strong>{{ strtoupper($method) }}</strong> lots for <strong>{{ $item->name }}</strong>
                as of <strong>{{ $asOfLabel }}</strong> — depletion replayed to that date, so it agrees with valuation.
            </p>

            <table class="zb-rtable">
                <thead>
                    <tr>
                        <th>Received</th><th>Voucher</th><th>Godown</th>
                        <th style="text-align:right">Original Qty</th>
                        <th style="text-align:right">Remaining Qty</th>
                        <th style="text-align:right">Rate</th>
                        <th style="text-align:right">Value</th>
                        <th>Source</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr>
                            <td>{{ $r['received_date'] }}</td>
                            <td><a href="{{ route('vouchers.alter', $r['voucher_id']) }}">{{ $r['voucher'] }}</a></td>
                            <td>{{ $r['godown'] }}</td>
                            <td style="text-align:right">{{ rtrim(rtrim(number_format($r['original'], 4), '0'), '.') }} {{ $unit }}</td>
                            <td style="text-align:right"><strong>{{ rtrim(rtrim(number_format($r['remaining'], 4), '0'), '.') }}</strong> {{ $unit }}</td>
                            <td style="text-align:right">{{ $r['rate'] }}</td>
                            <td style="text-align:right">{{ $r['value'] }}</td>
                            <td>{{ $r['source'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="zb-r-empty">No remaining lots for this item as of {{ $asOfLabel }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif

        <p class="text-muted zb-ws-hint" style="margin-top:.75rem">
            <span class="zb-kbd">F2</span> change date · <span class="zb-kbd">Esc</span> back
        </p>
    </div>
</div>
