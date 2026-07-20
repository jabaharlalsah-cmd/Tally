<div class="zb-report">
    <div class="zb-report-head">
        <div class="zb-report-title">Forex Revaluation — Unrealised Gain / Loss</div>
        <div class="zb-report-period">
            <label>As of <span class="zb-kbd">F2</span></label>
            <input type="date" class="form-control zb-field" data-zb-noselect wire:model.blur="asOf">
        </div>
    </div>

    @unless ($enabled)
        <div class="zb-panel">
            <p class="zb-list-empty">
                Multi-currency is off. Turn it on with <span class="zb-kbd">F11</span> →
                <a href="{{ route('features') }}">Company Features</a> (Enterprise plan).
            </p>
        </div>
    @else
    <div class="zb-panel">
        @if ($flash) <div class="zb-ws-flash is-ok" x-data="{s:true}" x-show="s" x-init="setTimeout(()=>s=false,5000)" x-cloak>{{ $flash }}</div> @endif
        @if ($error) <div class="zb-field-error" style="margin:.5rem 0">{{ $error }}</div> @endif

        @if (! empty($reval['missing_rates']))
            <div class="zb-fx-override" style="border-color:var(--rust);background:rgba(176,58,46,.06)">
                No current rate for {{ implode(', ', $reval['missing_rates']) }} as of {{ $reval['as_of'] }}.
                Add one in the <a href="{{ route('masters.currencies') }}">Currency master</a> to revalue those bills.
            </div>
        @endif

        @forelse ($reval['currencies'] as $cur)
            <div class="zb-subhead" style="margin-top:.8rem">
                {{ $cur['code'] }} · current rate {{ number_format($cur['current_rate'], 6) }}
            </div>
            <table class="zb-rtable zb-tds-rtable">
                <thead>
                    <tr>
                        <th>Party · Bill</th>
                        <th class="zb-vt-right">Foreign</th>
                        <th class="zb-vt-right">Booked (₹)</th>
                        <th class="zb-vt-right">Revalued (₹)</th>
                        <th class="zb-vt-right">Unrealised (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($cur['rows'] as $r)
                        <tr class="zb-rrow">
                            <td>{{ $r['party'] }} <span class="text-muted">· {{ $r['ref_name'] }}</span></td>
                            <td class="zb-vt-right">{{ $r['foreign_label'] }}</td>
                            <td class="zb-vt-right">{{ $r['booked'] }}</td>
                            <td class="zb-vt-right">{{ $r['revalued'] }}</td>
                            <td class="zb-vt-right" style="color:{{ $r['is_gain'] ? 'var(--evergreen-600)' : 'var(--rust)' }}">
                                {{ $r['is_gain'] ? '+' : '−' }}{{ $r['unrealised'] }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="zb-cc-grand">
                        <td class="zb-vt-right" colspan="4">Unrealised for {{ $cur['code'] }}</td>
                        <td class="zb-vt-right">{{ ($cur['unrealised_paise'] >= 0 ? '+' : '−').\App\Services\BalanceService::money(abs($cur['unrealised_paise'])) }}</td>
                    </tr>
                </tfoot>
            </table>
        @empty
            <p class="zb-list-empty">No open foreign-currency bills to revalue as of {{ $reval['as_of'] }}.</p>
        @endforelse

        @if (! empty($reval['currencies']))
            <div class="zb-report-status" style="margin-top:.8rem">
                <span class="zb-report-fy">
                    Net unrealised as of {{ $reval['as_of'] }}:
                    <strong style="color:{{ $reval['is_net_gain'] ? 'var(--evergreen-700)' : 'var(--rust)' }}">
                        {{ $reval['is_net_gain'] ? 'gain ' : 'loss ' }}{{ $reval['total_unrealised'] }}
                    </strong>
                </span>
            </div>
            <div class="zb-fx-override" style="border-color:var(--evergreen);background:var(--evergreen-tint)">
                <p style="margin:0 0 .5rem;font-size:.8rem">
                    Revaluation is a period-end estimate. Review the figures, then post the balancing Journal
                    (<strong>Dr/Cr</strong> the foreign party ledgers vs <strong>Foreign Exchange Gain / Loss</strong>).
                    It posts through the normal path — the Trial Balance stays balanced.
                </p>
                <button type="button" class="btn btn-sm zb-btn-primary" wire:click="postRevaluation"
                        onclick="return confirm('Post the forex revaluation journal for this date?')">
                    Generate + post revaluation journal
                </button>
            </div>
        @endif

        <p class="text-muted zb-ws-hint" style="margin-top:.75rem">
            <span class="zb-kbd">F2</span> change date · <span class="zb-kbd">Esc</span> back
        </p>
    </div>
    @endunless
</div>
