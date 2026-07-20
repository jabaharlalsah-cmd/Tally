<div class="zb-notes-register"
     x-data="{
        init() {
            this.$store.zb.pushContext({
                name: 'notes-register',
                label: 'Notes Register',
                focusEl: '#notes-surface',
                actions: [
                    { key: 'escape', label: 'Back', hidden: true, run: () => (window.location.href = @js(route('gateway'))) }
                ],
                onEsc: () => (window.location.href = @js(route('gateway')))
            });
            this.$nextTick(() => { const el = document.querySelector('#notes-surface'); if (el) el.focus(); });
        }
     }">

    <div class="zb-gateway" style="max-width:960px">
        <h1 class="zb-gateway-heading">Notes Register</h1>
        <p class="zb-gateway-sub">Debit &amp; Credit Notes · FY {{ $fyLabel }} · <span class="zb-kbd">Esc</span> back to Gateway.</p>

        {{-- Filters --}}
        <div class="zb-form-row" style="gap:1rem;flex-wrap:wrap;align-items:end;margin-bottom:1rem">
            <div>
                <label for="nr-kind" style="display:block;font-size:.78rem;color:#5c6b63">Type</label>
                <select id="nr-kind" class="form-control zb-field" wire:model.live="kind">
                    <option value="all">All Notes</option>
                    <option value="credit_note">Credit Notes (Sales Return)</option>
                    <option value="debit_note">Debit Notes (Purchase Return)</option>
                </select>
            </div>
            <div>
                <label for="nr-party" style="display:block;font-size:.78rem;color:#5c6b63">Party</label>
                <select id="nr-party" class="form-control zb-field" wire:model.live="partyId">
                    <option value="">— all parties —</option>
                    @foreach ($parties as $p)
                        <option value="{{ $p['id'] }}">{{ $p['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="nr-from" style="display:block;font-size:.78rem;color:#5c6b63">From <span class="zb-kbd">F2</span></label>
                <input type="date" id="nr-from" class="form-control zb-field" data-zb-noselect wire:model.live="from">
            </div>
            <div>
                <label for="nr-to" style="display:block;font-size:.78rem;color:#5c6b63">To</label>
                <input type="date" id="nr-to" class="form-control zb-field" data-zb-noselect wire:model.live="to">
            </div>
        </div>

        <div id="notes-surface" tabindex="-1">
            <table class="zb-table" style="width:100%;border-collapse:collapse;font-size:.9rem">
                <thead>
                    <tr style="text-align:left;color:#5c6b63;font-size:.74rem;text-transform:uppercase;letter-spacing:.04em">
                        <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0">Date</th>
                        <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0">Type</th>
                        <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0">No.</th>
                        <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0">Party</th>
                        <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0">Against</th>
                        <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0;text-align:right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr>
                            <td style="padding:.5rem .6rem;border-bottom:1px solid #eef2f0">{{ $r['date_label'] }}</td>
                            <td style="padding:.5rem .6rem;border-bottom:1px solid #eef2f0">{{ $r['type_label'] }}</td>
                            <td style="padding:.5rem .6rem;border-bottom:1px solid #eef2f0">
                                <a href="{{ route('vouchers.alter', $r['id']) }}" style="color:#0B6E4F;font-weight:600">{{ $r['display_number'] }}</a>
                            </td>
                            <td style="padding:.5rem .6rem;border-bottom:1px solid #eef2f0">{{ $r['party'] }}</td>
                            <td style="padding:.5rem .6rem;border-bottom:1px solid #eef2f0;color:#5c6b63">{{ $r['reference'] ?? '—' }}</td>
                            <td style="padding:.5rem .6rem;border-bottom:1px solid #eef2f0;text-align:right">{{ number_format($r['amount'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="padding:1rem .6rem;color:#5c6b63">No Debit/Credit Notes in this period.</td></tr>
                    @endforelse
                </tbody>
                @if (count($rows))
                    <tfoot>
                        <tr style="font-weight:700">
                            <td colspan="5" style="padding:.6rem;text-align:right">Total ({{ count($rows) }})</td>
                            <td style="padding:.6rem;text-align:right">{{ number_format($total, 2) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
