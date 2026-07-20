<div class="zb-vat-return"
     x-data="{
        init() {
            this.$store.zb.pushContext({
                name: 'vat-return',
                label: 'VAT Return',
                focusEl: '#vr-period',
                actions: [
                    { key: 'escape', label: 'Back', hidden: true, run: () => (window.location.href = @js(route('gateway'))) }
                ],
                onEsc: () => (window.location.href = @js(route('gateway')))
            });
            this.$nextTick(() => { const el = document.querySelector('#vr-period'); if (el) el.focus(); });
        }
     }">

    <div class="zb-gateway" style="max-width:1020px">
        <h1 class="zb-gateway-heading">VAT Return · मूल्य अभिवृद्धि कर विवरण</h1>
        <p class="zb-gateway-sub">
            अनुसूची-१० (Schedule 10) · Inland Revenue Department, Nepal ·
            <span class="zb-kbd">Esc</span> back to Gateway.
        </p>

        {{-- Period + carry-forward --}}
        <div class="zb-form-row" style="gap:1rem;flex-wrap:wrap;align-items:end;margin-bottom:1rem">
            <div>
                <label for="vr-period" style="display:block;font-size:.78rem;color:#5c6b63">कर अवधि · Return period (BS)</label>
                <select id="vr-period" class="form-control zb-field" wire:model.live="period">
                    @foreach ($periods as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="vr-cf" style="display:block;font-size:.78rem;color:#5c6b63">Box 6 · गत महिनाको बाँकी क्रेडिट</label>
                <input type="number" id="vr-cf" class="form-control zb-field" min="0" step="1"
                       wire:model.live.debounce.500ms="carryForward" placeholder="0">
            </div>
        </div>

        @if ($flash)
            <div style="margin:.5rem 0 1rem;padding:.6rem .9rem;border-radius:8px;background:rgba(11,110,79,.08);border:1px solid rgba(11,110,79,.4);font-weight:600">
                ✔ {{ $flash }}
            </div>
        @endif

        @if ($renderError)
            <div style="margin:.5rem 0 1rem;padding:.75rem .9rem;border-radius:8px;background:rgba(176,60,40,.08);border:1px solid rgba(176,60,40,.45)">
                <strong>Cannot generate the VAT return.</strong><br>
                {{ $renderError }}
                @if (str_contains($renderError, 'F11'))
                    <div style="margin-top:.4rem"><a href="{{ route('features') }}" style="color:#0B6E4F;font-weight:600">Open Company Features (F11) →</a></div>
                @endif
            </div>
        @endif

        @if ($return && $preview)
            @php $b = $return['boxes']; $h = $return['header']; @endphp

            <p style="font-size:.85rem;color:#5c6b63;margin:.2rem 0 1rem">
                Filing as PAN <strong style="color:#14231E">{{ $h['pan'] }}</strong> for
                <strong style="color:#14231E">{{ $preview['period_label'] }}</strong>
                (FY {{ $h['fiscal_year'] }}; Gregorian {{ $h['gregorian_from'] }} → {{ $h['gregorian_to'] }}).
                @if ($filing && $filing->submission_ref)
                    <span style="font-size:.68rem;font-weight:700;color:#0B6E4F;background:rgba(11,110,79,.1);padding:.15rem .45rem;border-radius:.35rem;margin-left:.35rem">FILED</span>
                @endif
            </p>

            {{-- ══════════ headline figures ══════════ --}}
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.9rem;margin-bottom:1.2rem">
                @php
                    $cards = [
                        ['कर लाग्ने विक्री', 'Taxable sales', $preview['taxable_sales'], '#14231E'],
                        ['संकलन गरेको कर', 'Output VAT', $preview['output_vat'], '#14231E'],
                        ['तिरेको कर क्रेडिट', 'Input VAT', $preview['input_vat'], '#14231E'],
                        [$preview['is_payable'] ? 'तिर्नु पर्ने कर' : 'बाँकी क्रेडिट', $preview['is_payable'] ? 'Net VAT payable' : 'Credit carried forward', abs($preview['net_vat']), $preview['is_payable'] ? '#8A6D00' : '#0B6E4F'],
                    ];
                @endphp
                @foreach ($cards as [$np, $en, $amt, $colour])
                    <div style="border:1px solid #dde5e0;border-radius:10px;padding:.75rem .9rem">
                        <div style="font-size:.78rem;color:#5c6b63">{{ $np }}</div>
                        <div style="font-size:.68rem;color:#8B9A92;margin-bottom:.25rem">{{ $en }}</div>
                        <div style="font-size:1.25rem;font-weight:700;color:{{ $colour }}">रु {{ number_format($amt) }}</div>
                    </div>
                @endforeach
            </div>

            {{-- ══════════ Schedule 10, box by box ══════════ --}}
            <div style="border:1px solid #dde5e0;border-radius:10px;padding:1rem 1.1rem">
                <h2 style="margin:0 0 .1rem;font-size:1.02rem">{{ $return['form']['title_np'] }}</h2>
                <p style="margin:0 0 .9rem;font-size:.78rem;color:#5c6b63">
                    {{ $return['form']['id'] }} · {{ $return['form']['legal_basis_np'] }} — transcribe these boxes into the portal.
                </p>

                <table class="zb-table" style="width:100%;border-collapse:collapse;font-size:.88rem">
                    <thead>
                        <tr style="text-align:left;color:#5c6b63;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em">
                            <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0;width:3.5rem">बुँदा</th>
                            <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0">विवरण</th>
                            <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0;text-align:right">{{ $return['columns']['value']['np'] }}</th>
                            <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0;text-align:right">{{ $return['columns']['credit']['np'] }}</th>
                            <th style="padding:.5rem .6rem;border-bottom:1px solid #dde5e0;text-align:right">{{ $return['columns']['debit']['np'] }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (['1','1.1','1.2','1.3','2','2.1','2.2','2.3','2.4','3','3.1','4'] as $key)
                            @php $row = $b[$key]; $isSection = ($row['type'] ?? '') === 'section'; @endphp
                            <tr @if($isSection) style="background:#f4f7f5;font-weight:700" @endif>
                                <td style="padding:.45rem .6rem;border-bottom:1px solid #eef2f0">{{ $isSection ? $key : $key }}</td>
                                <td style="padding:.45rem .6rem;border-bottom:1px solid #eef2f0">
                                    {{ $row['np'] }}
                                    <span style="color:#8B9A92;font-size:.76rem">· {{ $row['en'] }}</span>
                                </td>
                                <td style="padding:.45rem .6rem;border-bottom:1px solid #eef2f0;text-align:right;{{ array_key_exists('value',$row) ? '' : 'background:#f0f2f0' }}">{{ array_key_exists('value',$row) ? number_format($row['value']) : '' }}</td>
                                <td style="padding:.45rem .6rem;border-bottom:1px solid #eef2f0;text-align:right;{{ array_key_exists('credit',$row) ? '' : 'background:#f0f2f0' }}">{{ array_key_exists('credit',$row) ? number_format($row['credit']) : '' }}</td>
                                <td style="padding:.45rem .6rem;border-bottom:1px solid #eef2f0;text-align:right;{{ array_key_exists('debit',$row) ? '' : 'background:#f0f2f0' }}">{{ array_key_exists('debit',$row) ? number_format($row['debit']) : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <table style="width:100%;border-collapse:collapse;font-size:.9rem;margin-top:.9rem">
                    <tbody>
                        @foreach (['5','6','7'] as $key)
                            @php $row = $b[$key]; @endphp
                            <tr @if($key === '7') style="font-weight:700" @endif>
                                <td style="padding:.4rem 0;width:2.5rem">{{ $key }}.</td>
                                <td style="padding:.4rem 0">{{ $row['np'] }} <span style="color:#8B9A92;font-size:.76rem">· {{ $row['en'] }}</span></td>
                                <td style="padding:.4rem 0;text-align:right;{{ $key === '7' ? 'color:'.($row['amount'] >= 0 ? '#8A6D00' : '#0B6E4F') : '' }}">
                                    @if (isset($row['sign'])) {{ $row['sign'] }} @endif रु {{ number_format(abs($row['amount'])) }}
                                </td>
                            </tr>
                        @endforeach
                        <tr>
                            <td style="padding:.4rem 0">8.</td>
                            <td style="padding:.4rem 0">{{ $b['8']['np'] }} <span style="color:#8B9A92;font-size:.76rem">· {{ $b['8']['en'] }}</span></td>
                            <td style="padding:.4rem 0;text-align:right;color:#8B9A92">रु {{ number_format($b['8']['amount']) }}</td>
                        </tr>
                        <tr>
                            <td style="padding:.4rem 0">10.</td>
                            <td style="padding:.4rem 0">{{ $b['10']['np'] }} <span style="color:#8B9A92;font-size:.76rem">· {{ $b['10']['en'] }} + {{ $b['10']['voucher_no_np'] }}</span></td>
                            <td style="padding:.4rem 0;text-align:right;color:#8B9A92">रु {{ number_format($b['10']['amount']) }}</td>
                        </tr>
                    </tbody>
                </table>

                <p style="margin:.75rem 0 .2rem;font-size:.82rem;color:#5c6b63">
                    9. {{ $b['9']['np'] }} <span style="color:#8B9A92">· {{ $b['9']['en'] }} — nothing ticked (a taxpayer decision)</span>
                </p>

                {{-- box 11 --}}
                <h3 style="margin:1rem 0 .35rem;font-size:.92rem">11. {{ $b['11']['np'] }}</h3>
                <table style="width:100%;border-collapse:collapse;font-size:.86rem">
                    <tbody>
                        @foreach ($b['11']['rows'] as $row)
                            <tr>
                                <td style="padding:.3rem 0">{{ $row['np'] }} <span style="color:#8B9A92;font-size:.76rem">· {{ $row['en'] }}</span></td>
                                <td style="padding:.3rem 0;text-align:right;font-weight:600">{{ $row['count'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <button type="button" wire:click="download"
                        style="margin-top:1rem;background:#0B6E4F;color:#fff;border:none;padding:.55rem 1.2rem;border-radius:.5rem;font-weight:600;cursor:pointer">
                    Generate VAT Return
                </button>
                <p style="margin:.5rem 0 0;font-size:.74rem;color:#8B9A92">
                    The IRD portal accepts no return file — this download is a transcription copy of the boxes above.
                </p>
            </div>

            {{-- ══════════ submission reference ══════════ --}}
            <div style="margin-top:1.3rem;border:1px solid #dde5e0;border-radius:10px;padding:1rem 1.1rem">
                <h2 style="margin:0 0 .1rem;font-size:1rem">Record a filing</h2>
                <p style="margin:0 0 .8rem;font-size:.78rem;color:#5c6b63">
                    After you submit on the portal it returns a submission reference. Keep it here.
                </p>
                <div class="zb-form-row" style="gap:.75rem;flex-wrap:wrap;align-items:end">
                    <div style="flex:1;min-width:16rem">
                        <label for="vr-ref" style="display:block;font-size:.78rem;color:#5c6b63">Submission reference</label>
                        <input type="text" id="vr-ref" class="form-control zb-field" wire:model="submissionRef"
                               placeholder="e.g. 07123456789" autocomplete="off">
                    </div>
                    <button type="button" wire:click="recordSubmissionRef"
                            style="background:#fff;color:#084C37;border:1px solid #Cfd6d0;padding:.5rem 1rem;border-radius:.5rem;font-weight:600;cursor:pointer">
                        Record
                    </button>
                </div>
                @if ($filing && $filing->submission_ref)
                    <p style="margin:.6rem 0 0;font-size:.78rem;color:#5c6b63">
                        Filed with reference <strong>{{ $filing->submission_ref }}</strong> on {{ $filing->filed_at?->format('d-M-Y') }}.
                    </p>
                @endif
            </div>

            <p style="margin-top:1.2rem;font-size:.78rem;color:#8B9A92;line-height:1.6">
                <strong>How to file:</strong> sign in at <em>taxpayerportal.ird.gov.np</em> → VAT → <em>E-VAT Return Entry</em> →
                register to get a submission number → key the boxes above into the VAT Return Data Entry form → Save →
                tick the validation box → Submit → paste the submission reference here.
                ZeroBook does not submit returns on your behalf.
            </p>
        @endif
    </div>
</div>
