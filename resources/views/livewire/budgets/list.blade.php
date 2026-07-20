<div class="zb-report"
     x-data="budgetList({
        newUrl: @js(route('reports.budget-editor')),
        editUrl: @js(route('reports.budget-editor', ['budget' => '__id__', 'revise' => 1])),
        gatewayUrl: @js(route('gateway'))
     })">

    <div class="zb-report-head">
        <div class="zb-report-title">Budgets</div>
        <div class="zb-report-period">
            <a href="{{ route('reports.budget-editor') }}" class="zb-report-toggle"><span class="zb-kbd">N</span> New budget</a>
            <a href="{{ route('reports.budget-variance') }}" class="zb-report-toggle">Variance</a>
            <a href="{{ route('reports.budget-summary') }}" class="zb-report-toggle">Summary</a>
        </div>
    </div>

    @if ($flash)
        <div class="zb-flash" wire:key="flash">{{ $flash }}</div>
    @endif

    <div class="zb-panel">
        <div id="budget-list" tabindex="-1" class="zb-report-surface">
            <table class="zb-rtable">
                <thead>
                    <tr>
                        <th>Budget</th>
                        <th>Fiscal Year</th>
                        <th class="zb-vt-right">Lines</th>
                        <th>Primary</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($budgets as $b)
                        <tr class="zb-rrow zb-r-ledger" data-budget-row data-id="{{ $b['id'] }}"
                            :class="{ 'is-active': active === {{ $loop->index }} }"
                            @click="active = {{ $loop->index }}; open()"
                            @mousemove="active = {{ $loop->index }}">
                            <td>{{ $b['name'] }}</td>
                            <td class="muted">{{ $b['fy'] }}</td>
                            <td class="zb-vt-right">{{ $b['lines'] }}</td>
                            <td>
                                @if ($b['is_primary'])
                                    <span style="color:#0a7d33">● primary</span>
                                @else
                                    <button type="button" class="zb-linkish" wire:click="markPrimary({{ $b['id'] }})">make primary</button>
                                @endif
                            </td>
                            <td class="muted">{{ $b['created'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">No budgets yet. Press <span class="zb-kbd">N</span> to create one.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">↑</span><span class="zb-kbd">↓</span> move ·
            <span class="zb-kbd">Enter</span> revise · <span class="zb-kbd">N</span> new ·
            <span class="zb-kbd">P</span> mark primary · <span class="zb-kbd">Del</span> delete ·
            <span class="zb-kbd">Esc</span> gateway
        </p>
    </div>
</div>
