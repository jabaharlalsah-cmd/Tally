<div class="zb-ws">
    @if ($flash)
        <div class="zb-ws-flash is-ok" x-data="{s:true}" x-show="s" x-init="setTimeout(()=>s=false,3500)" x-cloak>{{ $flash }}</div>
    @endif

    <div class="zb-gateway" style="max-width:820px">
        <h1 class="zb-gateway-heading">Currencies</h1>
        <p class="zb-gateway-sub">One base (reporting) currency · foreign currencies carry a daily exchange rate</p>

        {{-- Currency list --}}
        <table class="zb-rtable" style="margin-top:.6rem">
            <thead>
                <tr><th>Code</th><th>Name</th><th>Symbol</th><th class="zb-vt-right">Latest rate</th><th></th></tr>
            </thead>
            <tbody>
                @foreach ($currencies as $c)
                    <tr class="zb-rrow">
                        <td>
                            <strong>{{ $c['code'] }}</strong>
                            @if ($c['is_base'])<span class="zb-fx-code" style="margin-left:.4rem">BASE</span>@endif
                        </td>
                        <td>{{ $c['name'] }}</td>
                        <td>{{ $c['symbol'] }}</td>
                        <td class="zb-vt-right">
                            @if ($c['is_base']) 1.000000 @elseif ($c['latest'] !== null) {{ number_format($c['latest'], 6) }} @else <span class="text-muted">— no rate —</span> @endif
                        </td>
                        <td class="zb-vt-right">
                            @unless ($c['is_base'])
                                <button type="button" class="btn btn-sm" wire:click="makeBase({{ $c['id'] }})">Make base</button>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.4rem;margin-top:1.2rem">
            {{-- Add a currency --}}
            <div>
                <div class="zb-subhead">Add a currency</div>
                <div class="zb-form-row">
                    <label>Code (ISO)</label>
                    <input class="form-control zb-field" wire:model="code" maxlength="3" autocomplete="off" placeholder="USD" style="text-transform:uppercase">
                </div>
                @error('code') <div class="zb-field-error">{{ $message }}</div> @enderror
                <div class="zb-form-row">
                    <label>Name</label>
                    <input class="form-control zb-field" wire:model="name" maxlength="60" autocomplete="off" placeholder="US Dollar">
                </div>
                @error('name') <div class="zb-field-error">{{ $message }}</div> @enderror
                <div class="zb-form-row">
                    <label>Symbol</label>
                    <input class="form-control zb-field" wire:model="symbol" maxlength="8" autocomplete="off" placeholder="$">
                </div>
                <div class="zb-form-row">
                    <label>Decimal places</label>
                    <input type="number" class="form-control zb-field" wire:model="decimal_places" min="0" max="4" style="max-width:6rem">
                </div>
                <button type="button" class="btn btn-sm zb-btn-primary" wire:click="addCurrency">Add currency</button>
            </div>

            {{-- Record a rate --}}
            <div>
                <div class="zb-subhead">Record an exchange rate</div>
                <div class="zb-form-row">
                    <label>Currency</label>
                    <select class="form-select zb-field" wire:model.live="rateCurrencyId">
                        @foreach ($nonBase as $c)
                            <option value="{{ $c->id }}">{{ $c->code }} — {{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="zb-form-row">
                    <label>Date</label>
                    <input type="date" class="form-control zb-field" wire:model="rateDate">
                </div>
                <div class="zb-form-row">
                    <label>Rate (in base currency)</label>
                    <input type="number" step="0.000001" class="form-control zb-field" wire:model="rateValue" placeholder="e.g. 83.500000">
                </div>
                @error('rateValue') <div class="zb-field-error">{{ $message }}</div> @enderror
                <button type="button" class="btn btn-sm zb-btn-primary" wire:click="addRate">Record rate</button>

                @if (! empty($history))
                    <div class="zb-subhead" style="margin-top:1rem">Rate history</div>
                    <table class="zb-rtable">
                        <thead><tr><th>Date</th><th class="zb-vt-right">Rate</th></tr></thead>
                        <tbody>
                            @foreach ($history as $h)
                                <tr class="zb-rrow"><td>{{ $h['date_label'] }}</td><td class="zb-vt-right">{{ number_format($h['rate'], 6) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        <p class="text-muted zb-ws-hint" style="margin-top:1rem">
            A rate is the base-currency value of one foreign unit (1 USD = ₹83.50 → 83.500000). Rates apply on-or-after
            their date until superseded. <span class="zb-kbd">Esc</span> back
        </p>
    </div>
</div>
