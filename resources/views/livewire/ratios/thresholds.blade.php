<div class="zb-report"
     x-data="ratioThresholds({ backUrl: @js(route('reports.ratio-dashboard')) })">

    <div class="zb-report-head">
        <div class="zb-report-title">Ratio Thresholds</div>
        <div class="zb-report-period">
            <button type="button" class="zb-report-toggle" wire:click="restoreDefaults">Restore defaults</button>
            <a href="{{ route('reports.ratio-dashboard') }}" class="zb-report-toggle"><span class="zb-kbd">Esc</span> Back</a>
        </div>
    </div>

    @if ($flash)<div class="zb-flash">{{ $flash }}</div>@endif

    <div class="zb-panel">
        <div id="ratio-thresholds" tabindex="-1" class="zb-report-surface" data-zb-form style="padding:1rem">
            <p class="muted" style="font-size:.85rem;max-width:640px">
                Colour bands are per-company and transparent — set them to your industry's norms. For a
                higher-is-better ratio, a value ≥ green is green, ≥ amber is amber, else red. For a
                lower-is-better ratio (e.g. Debtor Days, Debt-to-Equity), ≤ green is green, ≤ amber is amber.
            </p>
            <table class="zb-rtable" style="max-width:680px">
                <thead>
                    <tr><th>Ratio</th><th>Direction</th><th class="zb-vt-right">Green</th><th class="zb-vt-right">Amber</th></tr>
                </thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr class="zb-rrow" wire:key="th-{{ $r['key'] }}">
                            <td>{{ $r['label'] }} <span class="muted" style="font-size:.72rem">({{ $r['unit'] === '%' ? '%' : ($r['unit'] === 'd' ? 'days' : 'ratio') }})</span></td>
                            <td class="muted" style="font-size:.78rem">{{ $r['direction'] === 'higher_better' ? '↑ higher is better' : '↓ lower is better' }}</td>
                            <td class="zb-vt-right">
                                <input type="number" step="0.01" class="form-control zb-field zb-vt-right" style="width:6rem"
                                       wire:model.blur="bands.{{ $r['key'] }}.green_min">
                            </td>
                            <td class="zb-vt-right">
                                <input type="number" step="0.01" class="form-control zb-field zb-vt-right" style="width:6rem"
                                       wire:model.blur="bands.{{ $r['key'] }}.amber_min">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="margin-top:1rem">
                <button type="button" class="btn" wire:click="save"><span class="zb-kbd">F9</span> Save thresholds</button>
            </div>
        </div>
        <p class="text-muted zb-ws-hint"><span class="zb-kbd">F9</span> save · <span class="zb-kbd">Esc</span> back</p>
    </div>
</div>
