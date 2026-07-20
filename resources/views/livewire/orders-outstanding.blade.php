<div class="zb-orders-outstanding"
     x-data="{
        init() {
            this.$store.zb.pushContext({
                name: 'orders-outstanding',
                label: @js($heading),
                focusEl: '#oo-surface',
                actions: [
                    { key: 'escape', label: 'Back', hidden: true, run: () => (window.location.href = @js(route('gateway'))) }
                ],
                onEsc: () => (window.location.href = @js(route('gateway')))
            });
            this.$nextTick(() => { const el = document.querySelector('#oo-surface'); if (el) el.focus(); });
        }
     }">

    <div class="zb-gateway" style="max-width:1040px">
        <h1 class="zb-gateway-heading">{{ $heading }}</h1>
        <p class="zb-gateway-sub">
            Commitments still to be {{ $scope === 'purchase' ? 'received' : 'delivered' }} · no accounting impact ·
            <a href="{{ route('reports.orders-outstanding', ['scope' => $otherScope]) }}" style="color:#0B6E4F;font-weight:600">{{ $otherLabel }} →</a>
            · <span class="zb-kbd">Esc</span> back to Gateway.
        </p>

        <div id="oo-surface" tabindex="-1">
            <table class="zb-table" style="width:100%;border-collapse:collapse;font-size:.9rem">
                <thead>
                    <tr style="text-align:left;color:#5c6b63;font-size:.74rem;text-transform:uppercase;letter-spacing:.04em">
                        <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0">Order</th>
                        <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0">Date</th>
                        <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0">{{ $partyLabel }}</th>
                        <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0">Item</th>
                        <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0;text-align:right">Ordered</th>
                        <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0;text-align:right">{{ $scope === 'purchase' ? 'Received' : 'Delivered' }}</th>
                        <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0;text-align:right">Pending</th>
                        <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0;text-align:right">Rate</th>
                        <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0;text-align:right">Pending Value</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        @foreach ($r['lines'] as $i => $ln)
                            <tr>
                                @if ($i === 0)
                                    <td style="padding:.5rem .6rem;border-bottom:1px solid #eef2f0;vertical-align:top" rowspan="{{ count($r['lines']) }}">
                                        <a href="{{ route('vouchers.alter', $r['id']) }}" style="color:#0B6E4F;font-weight:600">{{ $r['display_number'] }}</a>
                                    </td>
                                    <td style="padding:.5rem .6rem;border-bottom:1px solid #eef2f0;vertical-align:top" rowspan="{{ count($r['lines']) }}">{{ $r['date_label'] }}</td>
                                    <td style="padding:.5rem .6rem;border-bottom:1px solid #eef2f0;vertical-align:top" rowspan="{{ count($r['lines']) }}">{{ $r['party'] }}</td>
                                @endif
                                <td style="padding:.5rem .6rem;border-bottom:1px solid #eef2f0">{{ $ln['item'] }}</td>
                                <td style="padding:.5rem .6rem;border-bottom:1px solid #eef2f0;text-align:right">{{ rtrim(rtrim(number_format($ln['ordered'], 4), '0'), '.') }} {{ $ln['unit'] }}</td>
                                <td style="padding:.5rem .6rem;border-bottom:1px solid #eef2f0;text-align:right">{{ rtrim(rtrim(number_format($ln['delivered'], 4), '0'), '.') }}</td>
                                <td style="padding:.5rem .6rem;border-bottom:1px solid #eef2f0;text-align:right;font-weight:700;color:#b07800">{{ rtrim(rtrim(number_format($ln['pending'], 4), '0'), '.') }}</td>
                                <td style="padding:.5rem .6rem;border-bottom:1px solid #eef2f0;text-align:right">{{ number_format($ln['rate'], 2) }}</td>
                                <td style="padding:.5rem .6rem;border-bottom:1px solid #eef2f0;text-align:right">{{ number_format($ln['pending_value'], 2) }}</td>
                            </tr>
                        @endforeach
                    @empty
                        <tr><td colspan="9" style="padding:1rem .6rem;color:#5c6b63">No outstanding {{ $typeLabel }}s — everything ordered has been {{ $scope === 'purchase' ? 'received' : 'delivered' }}.</td></tr>
                    @endforelse
                </tbody>
                @if (count($rows))
                    <tfoot>
                        <tr style="font-weight:700">
                            <td colspan="8" style="padding:.6rem;text-align:right">Total pending value ({{ count($rows) }} order{{ count($rows) === 1 ? '' : 's' }})</td>
                            <td style="padding:.6rem;text-align:right">{{ number_format($totalValue, 2) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
