@php($__cache = ['groups' => $masters['groups'], 'ledgers' => $masters['ledgers']])
<div class="zb-report" data-zb-form
     x-data="budgetEditor({
        masters: @js($__cache),
        reviseMode: @js($reviseMode),
        listUrl: @js(route('reports.budget-list'))
     })">

    <div class="zb-report-head">
        <div class="zb-report-title">{{ $reviseMode ? 'Revise Budget' : 'New Budget' }}</div>
        <div class="zb-report-period">
            <span class="muted">FY {{ $this->fyLabel }}</span>
            @unless ($reviseMode)
                <input type="number" class="form-control zb-field" style="width:6rem" wire:model.blur="fyStart"
                       wire:change="setFy($event.target.value)" min="2000" max="2100" title="Fiscal year start">
            @endunless
        </div>
    </div>

    <div class="zb-panel">
        <div id="budget-editor" tabindex="-1" class="zb-report-surface" style="padding:1rem">

            {{-- header / identity --}}
            <div class="zb-budget-head" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1rem">
                @unless ($reviseMode)
                    <div>
                        <label style="display:block;font-size:.78rem" class="muted">Budget name</label>
                        <input type="text" class="form-control zb-field" wire:model="name" data-zb-noselect
                               placeholder="e.g. FY {{ $this->fyLabel }} Budget" style="min-width:16rem">
                        @error('name')<div style="color:#b23b32;font-size:.78rem">{{ $message }}</div>@enderror
                    </div>
                    <label style="display:flex;align-items:center;gap:.4rem;font-weight:400">
                        <input type="checkbox" wire:model="isPrimary" style="width:auto"> Make primary
                    </label>
                @else
                    <div>
                        <label style="display:block;font-size:.78rem" class="muted">Budget</label>
                        <strong>{{ $name }}</strong>
                    </div>
                    <div>
                        <label style="display:block;font-size:.78rem" class="muted">Effective from</label>
                        <input type="date" class="form-control zb-field" wire:model="effectiveFrom" data-zb-noselect>
                    </div>
                    <div>
                        <label style="display:block;font-size:.78rem" class="muted">Revision note</label>
                        <input type="text" class="form-control zb-field" wire:model="revisionNote" data-zb-noselect placeholder="why (optional)">
                    </div>
                @endunless
            </div>

            {{-- add-line pickers (create only) --}}
            @unless ($reviseMode)
                <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:.8rem">
                    <div data-combo-id="budget-add-ledger" style="min-width:14rem">
                        <label style="display:block;font-size:.78rem" class="muted">Add a ledger target</label>
                        <x-master-select id="budget-add-ledger" source="ledgers" sink="event" label="Ledger" placeholder="Ledger…" />
                    </div>
                    <div data-combo-id="budget-add-group" style="min-width:14rem">
                        <label style="display:block;font-size:.78rem" class="muted">Add a group target</label>
                        <x-master-select id="budget-add-group" source="groups" sink="event" label="Group" placeholder="Group…" />
                    </div>
                </div>
            @endunless

            @error('lines')<div style="color:#b23b32;font-size:.82rem;margin-bottom:.5rem">{{ $message }}</div>@enderror
            @error('save')<div style="color:#b23b32;font-size:.82rem;margin-bottom:.5rem">{{ $message }}</div>@enderror

            {{-- the grid --}}
            <div style="overflow-x:auto">
                <table class="zb-rtable zb-budget-grid">
                    <thead>
                        <tr>
                            <th style="min-width:11rem">Target</th>
                            <th>Method</th>
                            <th class="zb-vt-right">Annual</th>
                            @foreach ($this->monthLabels as $ml)
                                <th class="zb-vt-right">{{ $ml }}</th>
                            @endforeach
                            @unless ($reviseMode)<th></th>@endunless
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lines as $i => $line)
                            <tr class="zb-rrow" wire:key="line-{{ $line['target_kind'] }}-{{ $line['target_id'] }}">
                                <td>
                                    {{ $line['name'] }}
                                    <span class="muted" style="font-size:.72rem">({{ $line['target_kind'] }} · {{ $line['nature'] }})</span>
                                </td>
                                <td>
                                    <select class="form-control zb-field" data-zb-noselect
                                            wire:model="lines.{{ $i }}.allocation_method" wire:change="recompute({{ $i }})" style="width:auto">
                                        <option value="even">Even</option>
                                        <option value="custom">Custom</option>
                                        <option value="seasonal">Seasonal</option>
                                    </select>
                                </td>
                                <td class="zb-vt-right">
                                    <input type="number" step="0.01" min="0" class="form-control zb-field zb-vt-right" style="width:7rem"
                                           wire:model.blur="lines.{{ $i }}.annual" wire:change="recompute({{ $i }})"
                                           @if ($line['allocation_method'] === 'custom') readonly @endif>
                                </td>
                                @for ($m = 0; $m < 12; $m++)
                                    <td class="zb-vt-right">
                                        <input type="number" step="0.01" min="0" class="form-control zb-field zb-vt-right" style="width:5.5rem"
                                               wire:model.blur="lines.{{ $i }}.months.{{ $m }}" wire:change="recompute({{ $i }})"
                                               @if ($line['allocation_method'] !== 'custom') readonly @endif>
                                    </td>
                                @endfor
                                @unless ($reviseMode)
                                    <td><button type="button" class="zb-linkish" style="color:#b23b32" wire:click="removeLine({{ $i }})">✕</button></td>
                                @endunless
                            </tr>
                        @empty
                            <tr><td colspan="{{ $reviseMode ? 15 : 16 }}" class="muted">
                                @if ($reviseMode) This budget has no lines. @else Pick a ledger or group above to add a target line. @endif
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div style="margin-top:1rem;display:flex;gap:.6rem;align-items:center">
                <button type="button" class="btn" wire:click="save"><span class="zb-kbd">F9</span> Save budget</button>
                <a href="{{ route('reports.budget-list') }}" class="zb-report-toggle">Cancel</a>
            </div>
        </div>
        <p class="text-muted zb-ws-hint">
            @if ($reviseMode)
                Historical months before the effective date stay locked; targets from that month forward are revised.
            @else
                Pick targets, set each line's method &amp; amounts.
            @endif
            <span class="zb-kbd">F9</span> save · <span class="zb-kbd">Esc</span> back
        </p>
    </div>
</div>
