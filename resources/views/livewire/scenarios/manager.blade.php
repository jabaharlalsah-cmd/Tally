<div class="zb-report"
     x-data="scenarioManager({
        masterUrl: @js(route('reports.scenario-master')),
        impactUrl: @js($selectedId ? route('reports.scenario-impact', ['scenario' => $selectedId]) : ''),
        gatewayUrl: @js(route('gateway'))
     })">

    <div class="zb-report-head">
        <div class="zb-report-title">Scenario Manager</div>
        <div class="zb-report-period">
            <a href="{{ $selectedId ? route('reports.scenario-impact', ['scenario' => $selectedId]) : route('reports.scenario-impact') }}"
               class="zb-report-toggle"><span class="zb-kbd">F5</span> Impact</a>
            <a href="{{ route('reports.scenario-master') }}" class="zb-report-toggle"><span class="zb-kbd">Esc</span> Master</a>
        </div>
    </div>

    @if ($flash)<div class="zb-flash" wire:key="flash">{{ $flash }}</div>@endif

    @if ($error)
        <div class="zb-flash" style="color:#b42318" wire:key="err">
            {{ $error }}
            @if ($reasons)
                <ul style="margin:.4rem 0 0;padding-left:1.2rem">
                    @foreach ($reasons as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    <div class="zb-panel">
        <div id="scenario-manager" tabindex="-1" class="zb-report-surface" data-zb-form style="padding:1rem">
            <p class="muted" style="font-size:.85rem;max-width:640px">
                Select a scenario, review its provisional vouchers, then promote them into the real books.
                Promotion is <strong>one-way and permanent</strong> — it re-validates every voucher and, if any
                fails, changes nothing.
            </p>

            @if (count($scenarios))
                <div style="margin:1rem 0">
                    <select class="form-control zb-field" wire:model.live="scenarioId" style="width:20rem">
                        @if (! $selectedId)
                            <option value="" disabled selected>Select a scenario…</option>
                        @endif
                        @foreach ($scenarios as $s)
                            <option value="{{ $s['id'] }}">{{ $s['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            @else
                <p class="muted" style="margin:1rem 0">
                    No active scenarios. Create one in the
                    <a href="{{ route('reports.scenario-master') }}" class="zb-linkish">Scenario Master</a>.
                </p>
            @endif

            @if ($selected)
                <div style="margin:1rem 0 .5rem">
                    <div style="font-weight:600">{{ $selected->name }}</div>
                    @if ($selected->description)
                        <div class="muted" style="font-size:.8rem">{{ $selected->description }}</div>
                    @endif
                </div>

                @if (count($vouchers))
                    <table class="zb-rtable" style="max-width:820px">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>No.</th>
                                <th>Narration</th>
                                <th class="zb-vt-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($vouchers as $row)
                                <tr class="zb-rrow" wire:key="scn-v-{{ $row['id'] }}">
                                    <td class="muted">{{ $row['date'] }}</td>
                                    <td>{{ ucfirst($row['type']) }}</td>
                                    <td>{{ $row['number'] }}</td>
                                    <td>{{ $row['narration'] }}</td>
                                    <td class="zb-vt-right">{{ $money($row['amount']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="zb-promote-box"
                         style="margin-top:1.25rem;max-width:820px;padding:.9rem 1rem;border:1px solid #e6c34d;border-radius:8px;background:#fff5db;color:#6b4e05">
                        <div style="font-weight:600;margin-bottom:.35rem">⚠ Promote to the real books</div>
                        <p style="font-size:.82rem;margin:0 0 .7rem">
                            This permanently moves all {{ count($vouchers) }} voucher(s) above into your real
                            books and closes this scenario. Every voucher is re-validated first; if any fails,
                            nothing changes. <strong>This cannot be undone.</strong>
                        </p>
                        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
                            <input type="text" wire:model="confirm" placeholder="type PROMOTE"
                                   class="form-control zb-field" style="width:12rem">
                            <button type="button" class="btn btn-danger" wire:click="promote">Promote to real books</button>
                        </div>
                    </div>
                @else
                    <p class="muted">No provisional vouchers in this scenario yet.</p>
                @endif
            @endif
        </div>
        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">F5</span> impact · <span class="zb-kbd">Esc</span> master
        </p>
    </div>
</div>
