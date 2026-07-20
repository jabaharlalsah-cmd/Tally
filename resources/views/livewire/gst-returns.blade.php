<div class="zb-gst-returns"
     x-data="{
        init() {
            this.$store.zb.pushContext({
                name: 'gst-returns',
                label: 'GST Returns',
                focusEl: '#gr-period',
                actions: [
                    { key: 'escape', label: 'Back', hidden: true, run: () => (window.location.href = @js(route('gateway'))) }
                ],
                onEsc: () => (window.location.href = @js(route('gateway')))
            });
            this.$nextTick(() => { const el = document.querySelector('#gr-period'); if (el) el.focus(); });
        }
     }">

    <div class="zb-gateway" style="max-width:1000px">
        <h1 class="zb-gateway-heading">GST Returns</h1>
        <p class="zb-gateway-sub">
            GSTR-1 (outward supplies) &amp; GSTR-3B (summary) · JSON for the GST portal ·
            <span class="zb-kbd">Esc</span> back to Gateway.
        </p>

        {{-- Period selector --}}
        <div class="zb-form-row" style="gap:1rem;flex-wrap:wrap;align-items:end;margin-bottom:1rem">
            <div>
                <label for="gr-period" style="display:block;font-size:.78rem;color:#5c6b63">Return period</label>
                <select id="gr-period" class="form-control zb-field" wire:model.live="period">
                    @foreach ($periods as $code => $label)
                        <option value="{{ $code }}">{{ $label }} ({{ $code }})</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if ($flash)
            <div style="margin:.5rem 0 1rem;padding:.6rem .9rem;border-radius:8px;background:rgba(11,110,79,.08);border:1px solid rgba(11,110,79,.4);font-weight:600">
                ✔ {{ $flash }}
            </div>
        @endif

        @if ($renderError)
            {{-- The one blocking condition: no company GSTIN. --}}
            <div style="margin:.5rem 0 1rem;padding:.75rem .9rem;border-radius:8px;background:rgba(176,60,40,.08);border:1px solid rgba(176,60,40,.45)">
                <strong>Cannot generate returns.</strong><br>
                {{ $renderError }}
                @if (str_contains($renderError, 'GSTIN'))
                    <div style="margin-top:.4rem"><a href="{{ route('features') }}" style="color:#0B6E4F;font-weight:600">Open Company Features (F11) →</a></div>
                @endif
            </div>
        @endif

        @if ($preview)
            @php $g1 = $preview['gstr1']; $g3 = $preview['gstr3b']; @endphp

            <p style="font-size:.85rem;color:#5c6b63;margin:.2rem 0 1rem">
                Filing as <strong style="color:#14231E">{{ $preview['gstin'] }}</strong> for
                <strong style="color:#14231E">{{ $periodLabel }}</strong>.
                Review the figures below before you upload — this is exactly what the JSON contains.
            </p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;align-items:start">

                {{-- ══════════ GSTR-1 ══════════ --}}
                <div style="border:1px solid #dde5e0;border-radius:10px;padding:1rem 1.1rem">
                    <h2 style="margin:0 0 .1rem;font-size:1.05rem">GSTR-1
                        @if (isset($filings['gstr1']) && $filings['gstr1']->arn)
                            <span style="font-size:.68rem;font-weight:700;color:#0B6E4F;background:rgba(11,110,79,.1);padding:.15rem .45rem;border-radius:.35rem;vertical-align:middle">FILED</span>
                        @endif
                    </h2>
                    <p style="margin:0 0 .8rem;font-size:.78rem;color:#5c6b63">Outward supplies, invoice level</p>

                    <table class="zb-table" style="width:100%;border-collapse:collapse;font-size:.88rem">
                        <tbody>
                            <tr><td style="padding:.35rem 0">B2B invoices <span style="color:#8B9A92">({{ $g1['b2b_parties'] }} registered {{ \Illuminate\Support\Str::plural('party', $g1['b2b_parties']) }})</span></td><td style="text-align:right;font-weight:600">{{ $g1['b2b_invoices'] }}</td></tr>
                            <tr><td style="padding:.35rem 0">B2CL invoices <span style="color:#8B9A92">(large, inter-state)</span></td><td style="text-align:right;font-weight:600">{{ $g1['b2cl_invoices'] }}</td></tr>
                            <tr><td style="padding:.35rem 0">B2CS summary rows</td><td style="text-align:right;font-weight:600">{{ $g1['b2cs_rows'] }}</td></tr>
                            <tr><td style="padding:.35rem 0">CDNR credit notes <span style="color:#8B9A92">(registered)</span></td><td style="text-align:right;font-weight:600">{{ $g1['cdnr_notes'] }}</td></tr>
                            <tr><td style="padding:.35rem 0">CDNUR credit notes <span style="color:#8B9A92">(unregistered)</span></td><td style="text-align:right;font-weight:600">{{ $g1['cdnur_notes'] }}</td></tr>
                            <tr><td style="padding:.35rem 0">HSN summary rows</td><td style="text-align:right;font-weight:600">{{ $g1['hsn_rows'] }}</td></tr>
                            <tr><td style="padding:.35rem 0">Document ranges <span style="color:#8B9A92">(Table 13)</span></td><td style="text-align:right;font-weight:600">{{ $g1['doc_ranges'] }}</td></tr>
                            <tr style="border-top:1px solid #dde5e0"><td style="padding:.5rem 0 .2rem">Total taxable value</td><td style="text-align:right;font-weight:700">{{ number_format($g1['total_taxable'], 2) }}</td></tr>
                            <tr><td style="padding:.2rem 0">Total tax</td><td style="text-align:right;font-weight:700">{{ number_format($g1['total_tax'], 2) }}</td></tr>
                        </tbody>
                    </table>

                    <button type="button" class="zb-btn-primary" wire:click="downloadGstr1"
                            style="margin-top:.9rem;width:100%;background:#0B6E4F;color:#fff;border:none;padding:.55rem;border-radius:.5rem;font-weight:600;cursor:pointer">
                        Generate GSTR-1 JSON
                    </button>
                    @if (isset($filings['gstr1']) && $filings['gstr1']->arn)
                        <p style="margin:.5rem 0 0;font-size:.76rem;color:#5c6b63">
                            ARN <strong>{{ $filings['gstr1']->arn }}</strong> · filed {{ $filings['gstr1']->filed_at?->format('d-M-Y') }}
                        </p>
                    @endif
                </div>

                {{-- ══════════ GSTR-3B ══════════ --}}
                <div style="border:1px solid #dde5e0;border-radius:10px;padding:1rem 1.1rem">
                    <h2 style="margin:0 0 .1rem;font-size:1.05rem">GSTR-3B
                        @if (isset($filings['gstr3b']) && $filings['gstr3b']->arn)
                            <span style="font-size:.68rem;font-weight:700;color:#0B6E4F;background:rgba(11,110,79,.1);padding:.15rem .45rem;border-radius:.35rem;vertical-align:middle">FILED</span>
                        @endif
                    </h2>
                    <p style="margin:0 0 .8rem;font-size:.78rem;color:#5c6b63">Summary of liability &amp; input credit</p>

                    <table class="zb-table" style="width:100%;border-collapse:collapse;font-size:.88rem">
                        <tbody>
                            <tr><td style="padding:.35rem 0">3.1(a) Outward taxable value</td><td style="text-align:right;font-weight:600">{{ number_format($g3['outward_taxable'], 2) }}</td></tr>
                            <tr><td style="padding:.35rem 0;padding-left:1rem;color:#5c6b63">Output IGST</td><td style="text-align:right">{{ number_format($g3['output_igst'], 2) }}</td></tr>
                            <tr><td style="padding:.35rem 0;padding-left:1rem;color:#5c6b63">Output CGST</td><td style="text-align:right">{{ number_format($g3['output_cgst'], 2) }}</td></tr>
                            <tr><td style="padding:.35rem 0;padding-left:1rem;color:#5c6b63">Output SGST</td><td style="text-align:right">{{ number_format($g3['output_sgst'], 2) }}</td></tr>
                            <tr style="border-top:1px solid #eef2f0"><td style="padding:.35rem 0">4(C) Net ITC available</td><td style="text-align:right;font-weight:600">{{ number_format($g3['itc_total'], 2) }}</td></tr>
                            <tr><td style="padding:.35rem 0;padding-left:1rem;color:#5c6b63">ITC IGST / CGST / SGST</td><td style="text-align:right">{{ number_format($g3['itc_igst'], 2) }} / {{ number_format($g3['itc_cgst'], 2) }} / {{ number_format($g3['itc_sgst'], 2) }}</td></tr>
                            <tr><td style="padding:.35rem 0">3.2 Inter-state to unregistered</td><td style="text-align:right;font-weight:600">{{ $g3['inter_state_unreg_rows'] }} {{ \Illuminate\Support\Str::plural('row', $g3['inter_state_unreg_rows']) }}</td></tr>
                            <tr style="border-top:1px solid #dde5e0"><td style="padding:.5rem 0 .2rem;font-weight:700">Net payable (output − ITC)</td><td style="text-align:right;font-weight:700;color:#8A6D00">{{ number_format($g3['net_payable'], 2) }}</td></tr>
                        </tbody>
                    </table>

                    <button type="button" class="zb-btn-primary" wire:click="downloadGstr3b"
                            style="margin-top:.9rem;width:100%;background:#0B6E4F;color:#fff;border:none;padding:.55rem;border-radius:.5rem;font-weight:600;cursor:pointer">
                        Generate GSTR-3B JSON
                    </button>
                    <p style="margin:.5rem 0 0;font-size:.74rem;color:#8B9A92">
                        The GSTR-3B JSON carries no tax-payment section — offset the liability on the portal.
                    </p>
                    @if (isset($filings['gstr3b']) && $filings['gstr3b']->arn)
                        <p style="margin:.35rem 0 0;font-size:.76rem;color:#5c6b63">
                            ARN <strong>{{ $filings['gstr3b']->arn }}</strong> · filed {{ $filings['gstr3b']->filed_at?->format('d-M-Y') }}
                        </p>
                    @endif
                </div>
            </div>

            {{-- ══════════ ARN log ══════════ --}}
            <div style="margin-top:1.4rem;border:1px solid #dde5e0;border-radius:10px;padding:1rem 1.1rem">
                <h2 style="margin:0 0 .1rem;font-size:1rem">Record a filing</h2>
                <p style="margin:0 0 .8rem;font-size:.78rem;color:#5c6b63">
                    After you upload the JSON, the portal returns an Acknowledgement Reference Number. Keep it here.
                </p>
                <div class="zb-form-row" style="gap:.75rem;flex-wrap:wrap;align-items:end">
                    <div>
                        <label for="gr-arntype" style="display:block;font-size:.78rem;color:#5c6b63">Return</label>
                        <select id="gr-arntype" class="form-control zb-field" wire:model="arnType">
                            <option value="gstr1">GSTR-1</option>
                            <option value="gstr3b">GSTR-3B</option>
                        </select>
                    </div>
                    <div style="flex:1;min-width:16rem">
                        <label for="gr-arn" style="display:block;font-size:.78rem;color:#5c6b63">ARN</label>
                        <input type="text" id="gr-arn" class="form-control zb-field" wire:model="arn"
                               placeholder="e.g. AA1234567890ABC" autocomplete="off">
                    </div>
                    <button type="button" wire:click="recordArn"
                            style="background:#fff;color:#084C37;border:1px solid #Cfd6d0;padding:.5rem 1rem;border-radius:.5rem;font-weight:600;cursor:pointer">
                        Record ARN
                    </button>
                </div>
            </div>

            <p style="margin-top:1.2rem;font-size:.78rem;color:#8B9A92;line-height:1.6">
                <strong>How to file:</strong> generate the JSON → open the GST portal's Returns Offline Tool (or the
                portal's own JSON import) → import the file → review the summary the tool shows → upload to the portal →
                paste the ARN above. ZeroBook does not submit returns on your behalf.
            </p>
        @endif
    </div>
</div>
