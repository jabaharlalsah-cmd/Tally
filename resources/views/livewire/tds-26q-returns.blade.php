<div class="zb-report" x-data="{}">
    <div class="zb-report-head">
        <div class="zb-report-title">Form 26Q — Quarterly TDS Returns</div>
        <div class="zb-report-period">
            <label>Fiscal year (start)</label>
            <input type="number" class="form-control zb-field" data-zb-noselect style="max-width:7rem"
                   wire:model.live="fyStart" min="2026" max="2099">
            <span>FY {{ $fyLabel }}</span>
        </div>
    </div>

    @unless ($enabled)
        <div class="zb-panel">
            <p class="zb-list-empty">
                TDS is switched off for this company. Turn it on with <span class="zb-kbd">F11</span> →
                <a href="{{ route('features') }}">Company Features</a>.
            </p>
        </div>
    @else
    <div class="zb-panel">
        @unless ($hasProfile)
            <div class="zb-tds-recon" style="border-left-color: var(--rust)">
                The deductor’s filing identity (TAN, address, responsible person) is not set. A 26Q cannot be filed
                without it — set it in <a href="{{ route('features') }}">Company Features → TDS</a>.
            </div>
        @endunless

        @if ($flash) <div class="zb-ws-flash is-ok" x-data="{s:true}" x-show="s" x-init="setTimeout(()=>s=false,4000)">{{ $flash }}</div> @endif
        @if ($error) <div class="zb-field-error" style="margin:.5rem 0">{{ $error }}</div> @endif

        <table class="zb-rtable zb-tds-rtable">
            <thead>
                <tr>
                    <th>Quarter</th>
                    <th>Period</th>
                    <th class="zb-vt-right">TDS deducted (₹)</th>
                    <th>Status</th>
                    <th>Records</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($quarters as $q)
                    <tr class="zb-rrow">
                        <td><strong>{{ $q['label'] }}</strong></td>
                        <td class="text-muted">{{ $q['months'] }}</td>
                        <td class="zb-vt-right">{{ $q['tds'] }}</td>
                        <td>
                            @switch($q['status'])
                                @case('filed')<span class="zb-tds-badge" style="color:var(--evergreen-700);background:var(--evergreen-tint)">Filed · {{ $q['token'] }}</span>@break
                                @case('ready')<span class="zb-tds-badge" style="color:var(--evergreen-600);background:var(--evergreen-tint)">Ready to file</span>@break
                                @case('draft')<span class="zb-tds-badge">Draft — needs fixing</span>@break
                                @default<span class="text-muted">No deductions</span>
                            @endswitch
                        </td>
                        <td class="text-muted">
                            @if ($q['records'])
                                {{ $q['records']['CD'] }} challan · {{ $q['records']['DD'] }} deductee
                            @else — @endif
                        </td>
                        <td class="zb-vt-right">
                            @if ($q['has_tds'] && $q['status'] !== 'draft')
                                <button type="button" class="btn btn-sm zb-btn-primary" wire:click="download({{ $q['quarter'] }})">
                                    Download .txt
                                </button>
                            @endif
                        </td>
                    </tr>
                    @if (! empty($q['issues']))
                        <tr><td colspan="6" class="zb-tds-drill-reason">
                            @foreach ($q['issues'] as $iss)· {{ $iss }}<br>@endforeach
                        </td></tr>
                    @endif
                @endforeach
            </tbody>
        </table>

        <div class="zb-tds-recon">
            <strong>How to file.</strong> Download the quarter’s <code>.txt</code>, then have your CA validate it with the
            official <strong>File Validation Utility</strong> (Protean RPU/FVU) — importing the <code>.csi</code> file from
            TIN (Challan Status Inquiry) to verify the challans — which produces the <code>.fvu</code> to upload to the
            e-filing portal. Record the acknowledgement token below once accepted.
        </div>

        {{-- Token recorder --}}
        <div class="zb-gst-profile" style="margin-top:1rem;max-width:560px">
            <div class="zb-subhead">Record a filing acknowledgement</div>
            <div class="zb-form-row">
                <label>Quarter</label>
                <select class="form-select zb-field" wire:model="tokenQuarter" style="max-width:8rem">
                    <option value="1">Q1</option><option value="2">Q2</option>
                    <option value="3">Q3</option><option value="4">Q4</option>
                </select>
            </div>
            <div class="zb-form-row">
                <label>Token / provisional receipt no.</label>
                <input class="form-control zb-field" wire:model="tokenNo" maxlength="15" autocomplete="off" placeholder="15-digit token from the portal">
            </div>
            @error('tokenNo') <div class="zb-field-error">{{ $message }}</div> @enderror
            <div class="zb-form-row">
                <label>Receipt no. (optional)</label>
                <input class="form-control zb-field" wire:model="receiptNo" maxlength="30" autocomplete="off">
            </div>
            <button type="button" class="btn btn-sm zb-btn-primary" wire:click="recordToken">Record token</button>
        </div>

        <p class="text-muted zb-ws-hint" style="margin-top:.75rem">
            Form 140 · FVU 1.1 (FY 2026-27+) · <span class="zb-kbd">Esc</span> back
        </p>
    </div>
    @endunless
</div>
