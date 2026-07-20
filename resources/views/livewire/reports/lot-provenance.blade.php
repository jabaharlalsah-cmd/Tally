<div class="zb-ws" style="max-width:1040px;margin:0 auto">
    <div class="zb-panel">
        <div class="zb-panel-title">Inter-Company Lot Provenance</div>

        @if (! $enabled && count($rows) === 0)
            <p class="text-muted" style="font-size:.85rem">
                This company is not in a company group — no inter-company inventory is being traced.
                Lots appear here once a group exists and goods are purchased from a groupmate.
            </p>
        @else
            <p class="text-muted" style="font-size:.8rem;margin:-.2rem 0 .8rem">
                @if ($groupName) Group: <strong>{{ $groupName }}</strong> · @endif
                Remaining inter-company inventory as of <strong>{{ $asOfLabel }}</strong>, FIFO by receipt date —
                the trace Phase 12C-2's unrealised-profit elimination consumes. Valuation stays weighted-average; this is provenance only.
                @if ($unmatchedCount > 0)
                    <span style="color:#b45309">· {{ $unmatchedCount }} lot(s) UNMATCHED (source voucher ambiguous — consolidation will list them rather than misvalue).</span>
                @endif
            </p>

            <div class="zb-form-row" style="gap:.6rem;align-items:flex-end;margin-bottom:.8rem">
                <div>
                    <label for="lp-item">Stock item</label>
                    <select id="lp-item" class="form-select zb-field" wire:model.live="stockItemId">
                        <option value="">— all items with lots —</option>
                        @foreach ($items as $it)
                            <option value="{{ $it->id }}">{{ $it->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="lp-asof">As of</label>
                    <input id="lp-asof" type="date" class="form-control zb-field" wire:model.live="asOf">
                </div>
            </div>

            <table class="zb-rtable">
                <thead>
                    <tr>
                        <th>Item</th><th>Godown</th><th>Source company</th><th>Received</th>
                        <th>Receiving voucher</th><th style="text-align:right">Original</th>
                        <th style="text-align:right">Remaining</th><th style="text-align:right">Transfer price</th>
                        <th style="text-align:right">Source cost</th><th style="text-align:right">Unrealised/unit hint</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr>
                            <td>{{ $r['item'] }}</td>
                            <td>{{ $r['godown'] }}</td>
                            <td>{{ $r['source_company'] }}</td>
                            <td>{{ $r['received_date'] }}</td>
                            <td>
                                <a href="{{ route('vouchers.alter', $r['receiving_voucher_id']) }}">{{ $r['receiving_voucher'] }}</a>
                                @if ($r['source_voucher_id'])
                                    <div class="text-muted" style="font-size:.72rem">source vch #{{ $r['source_voucher_id'] }} in {{ $r['source_company'] }} — switch company (F3) to open</div>
                                @else
                                    <div style="font-size:.72rem;color:#b45309">source unmatched</div>
                                @endif
                            </td>
                            <td style="text-align:right">{{ rtrim(rtrim(number_format($r['original'], 4), '0'), '.') }} {{ $r['unit'] }}</td>
                            <td style="text-align:right"><strong>{{ rtrim(rtrim(number_format($r['remaining'], 4), '0'), '.') }}</strong> {{ $r['unit'] }}</td>
                            <td style="text-align:right">{{ $r['received_rate'] }}</td>
                            <td style="text-align:right">{{ $r['source_cost'] ?? '—' }}</td>
                            <td style="text-align:right">{{ $r['unrealised_hint'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-muted">No remaining inter-company lots{{ $stockItemId ? ' for this item' : '' }} as of {{ $asOfLabel }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif
    </div>
</div>
