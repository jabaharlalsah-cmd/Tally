{{-- Phase 12C-2 — shared header (group + F2 period) and the adjustments panel. --}}
<div class="zb-form-row" style="gap:.6rem;align-items:flex-end;margin-bottom:.8rem">
    <div>
        <label for="gr-group">Group</label>
        <select id="gr-group" class="form-select zb-field" wire:model.live="groupId">
            @foreach ($groups as $g)
                <option value="{{ $g->id }}">{{ $g->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="gr-from">From</label>
        <input id="gr-from" type="date" class="form-control zb-field" wire:model.blur="from">
    </div>
    <div>
        <label for="gr-to">To</label>
        <input id="gr-to" type="date" class="form-control zb-field" wire:model.blur="to">
    </div>
    <span class="text-muted" style="font-size:.74rem;padding-bottom:.4rem"><span class="zb-kbd">F2</span> period · <span class="zb-kbd">↑/↓</span> rows · <span class="zb-kbd">Enter</span> expand company detail</span>
</div>

@if ($panel)
    {{-- The audit trail: what the consolidation removed, and what it could not. --}}
    <div class="zb-panel" style="margin-bottom:.9rem;background:color-mix(in srgb, var(--zb-evergreen, #0B6E4F) 4%, transparent)">
        <div class="zb-panel-title" style="font-size:.8rem">Consolidation Adjustments — audit trail</div>
        <div style="display:flex;flex-wrap:wrap;gap:1.2rem;font-size:.78rem">
            <span>Inter-company sales eliminated: <strong>{{ \App\Services\BalanceService::money($panel['complete']['categories']['sales']) }}</strong></span>
            <span>Purchases: <strong>{{ \App\Services\BalanceService::money($panel['complete']['categories']['purchases']) }}</strong></span>
            <span>Receivables: <strong>{{ \App\Services\BalanceService::money($panel['complete']['categories']['receivables']) }}</strong></span>
            <span>Payables: <strong>{{ \App\Services\BalanceService::money($panel['complete']['categories']['payables']) }}</strong></span>
            @if ($panel['complete']['categories']['other'] > 0)
                <span>Other legs: <strong>{{ \App\Services\BalanceService::money($panel['complete']['categories']['other']) }}</strong></span>
            @endif
            <span>Unrealised profit on inter-company stock: <strong>{{ \App\Services\BalanceService::money($panel['unrealised']['total']) }}</strong></span>
            <span>Tagged vouchers eliminated: <strong>{{ $panel['complete']['voucher_count'] }}</strong></span>
        </div>
        @if ($panel['complete']['mismatch'] !== 0)
            <p style="color:#b45309;font-size:.76rem;margin:.5rem 0 0">⚠ Elimination legs are asymmetric by {{ \App\Services\BalanceService::money(abs($panel['complete']['mismatch'])) }} — one side of an inter-company flow appears unposted. The Trial Balance reflects this rather than hiding it.</p>
        @endif
        @if (count($panel['unrealised']['unmatched']) > 0)
            <p style="color:#b45309;font-size:.76rem;margin:.5rem 0 0">
                ⚠ {{ count($panel['unrealised']['unmatched']) }} inter-company stock lot(s) with UNMATCHED source
                ({{ \App\Services\BalanceService::money($panel['unrealised']['unmatched_value']) }} at receipt rate) — listed, NOT eliminated:
                @foreach ($panel['unrealised']['unmatched'] as $u)
                    {{ $u['item'] }} × {{ rtrim(rtrim(number_format($u['remaining'], 4), '0'), '.') }} in {{ $u['company'] }} (from {{ $u['source_company'] }}){{ $loop->last ? '.' : ';' }}
                @endforeach
                Resolve via the Lot Provenance report.
            </p>
        @endif
        @if (count($panel['untagged']) > 0)
            <p style="color:#b45309;font-size:.76rem;margin:.5rem 0 0">
                ⚠ {{ count($panel['untagged']) }} UNACCOUNTED inter-company voucher(s) — untagged (pre-group history, or tag-exempt
                revaluation journals), NOT eliminated:
                @foreach ($panel['untagged'] as $u)
                    {{ $u['company'] }} · {{ $u['type'] }} №{{ $u['number'] }} of {{ \Carbon\Carbon::parse($u['date'])->format('d-M-Y') }} ({{ \App\Services\BalanceService::money($u['amount_paise']) }}){{ $loop->last ? '.' : ';' }}
                @endforeach
            </p>
        @endif
    </div>
@endif
