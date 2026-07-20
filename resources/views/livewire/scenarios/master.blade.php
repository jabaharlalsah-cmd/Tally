<div class="zb-report"
     x-data="scenarioMaster({ gatewayUrl: @js(route('gateway')), managerUrl: @js(route('reports.scenario-manager')) })">

    <div class="zb-report-head">
        <div class="zb-report-title">Scenarios</div>
        <div class="zb-report-period">
            <a href="{{ route('reports.scenario-manager') }}" class="zb-report-toggle">Manager ›</a>
            <a href="{{ route('gateway') }}" class="zb-report-toggle"><span class="zb-kbd">Esc</span> Back</a>
        </div>
    </div>

    @if ($flash)<div class="zb-flash" wire:key="flash">{{ $flash }}</div>@endif

    <div class="zb-panel">
        <div id="scenario-master" tabindex="-1" class="zb-report-surface" data-zb-form style="padding:1rem">
            <p class="muted" style="font-size:.85rem;max-width:640px">
                A scenario is a named set of provisional what-if vouchers, excluded from your real books
                until you promote it. Post vouchers against a scenario, review their impact, then promote
                them into the real books from the Manager.
            </p>

            <div class="zb-create-row" style="display:flex;gap:.5rem;align-items:center;margin:1rem 0;flex-wrap:wrap">
                <input type="text" id="scn-new-name" wire:model="newName" placeholder="New scenario name"
                       class="form-control zb-field" style="width:16rem">
                <input type="text" wire:model="newDescription" placeholder="Description (optional)"
                       class="form-control zb-field" style="width:18rem">
                <button type="button" class="btn" wire:click="create"><span class="zb-kbd">F9</span> Create</button>
            </div>
            @error('newName')<div class="zb-flash" style="color:#b42318">{{ $message }}</div>@enderror

            <table class="zb-rtable" style="max-width:820px">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th class="zb-vt-right">Provisional vouchers</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr class="zb-rrow" wire:key="scn-{{ $r['id'] }}">
                            <td>
                                {{ $r['name'] }}
                                @if ($r['description'])
                                    <div class="muted" style="font-size:.72rem">{{ $r['description'] }}</div>
                                @endif
                            </td>
                            <td class="zb-vt-right">{{ $r['vouchers'] }}</td>
                            <td>
                                @if ($r['active'])
                                    <span style="color:#0a7d33">● Active</span>
                                @else
                                    <span class="muted">○ Inactive</span>
                                @endif
                            </td>
                            <td class="muted">{{ $r['created'] }}</td>
                            <td>
                                <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
                                    <button type="button" class="zb-linkish"
                                            wire:click="setActive({{ $r['id'] }}, {{ $r['active'] ? 'false' : 'true' }})">
                                        {{ $r['active'] ? 'Deactivate' : 'Activate' }}
                                    </button>
                                    <input type="text" class="form-control zb-field" style="width:9rem"
                                           wire:model="confirmName.{{ $r['id'] }}" placeholder="type name to delete">
                                    <button type="button" class="btn btn-danger" wire:click="remove({{ $r['id'] }})">Delete</button>
                                    @if ($r['vouchers'] > 0)
                                        <span class="muted" style="font-size:.72rem;color:#b42318">
                                            {{ $r['vouchers'] }} vouchers will be deleted
                                        </span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">No scenarios yet. Press <span class="zb-kbd">F9</span> to create one.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="text-muted zb-ws-hint"><span class="zb-kbd">F9</span> new scenario · <span class="zb-kbd">Esc</span> gateway</p>
    </div>
</div>
