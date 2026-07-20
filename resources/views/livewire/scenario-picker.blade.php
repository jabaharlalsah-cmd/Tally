<div class="zb-scn" wire:key="scenario-picker">
    @if ($options)
        <div class="zb-scn-bar">
            <span class="zb-scn-title">Scenarios</span>
            @foreach ($options as $o)
                <button type="button" wire:click="toggle({{ $o['id'] }})"
                        class="zb-scn-chip{{ in_array($o['id'], $selected, true) ? ' is-on' : '' }}"
                        title="Include this scenario's provisional vouchers in reports">
                    <span class="zb-scn-dot"></span>{{ $o['name'] }}
                </button>
            @endforeach
            @if ($selected)
                <button type="button" wire:click="clearAll" class="zb-scn-chip zb-scn-reset"
                        title="Back to the real books">Real books only</button>
            @endif
            <a href="{{ route('reports.scenario-master') }}" class="zb-scn-manage">Manage&nbsp;›</a>
        </div>

        @if ($labels)
            <div class="zb-scn-banner" role="status">
                <span class="zb-scn-warn">⚠</span>
                Viewing <strong>with scenarios</strong>: {{ implode(', ', $labels) }}.
                Figures include <strong>provisional what-if</strong> vouchers — not your real books.
            </div>
        @endif
    @endif
</div>

@once
    <style>
        .zb-scn { margin: 0 0 .6rem; }
        .zb-scn-bar { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem;
            padding: .35rem .55rem; border: 1px dashed var(--zb-border, #d7dbe0); border-radius: 8px;
            background: var(--zb-surface-2, #f7f8fa); font-size: .8rem; }
        .zb-scn-title { font-weight: 600; color: var(--zb-muted, #6b7280); text-transform: uppercase;
            letter-spacing: .04em; font-size: .68rem; margin-right: .2rem; }
        .zb-scn-chip { display: inline-flex; align-items: center; gap: .35rem; cursor: pointer;
            border: 1px solid var(--zb-border, #d7dbe0); background: var(--zb-surface, #fff);
            color: var(--zb-text, #1f2430); border-radius: 999px; padding: .16rem .6rem; font-size: .78rem;
            line-height: 1.4; }
        .zb-scn-chip:hover { border-color: #b58a1f; }
        .zb-scn-dot { width: .5rem; height: .5rem; border-radius: 50%; background: var(--zb-border, #cbd0d6); }
        .zb-scn-chip.is-on { background: #fff5db; border-color: #d9a520; color: #6b4e05; font-weight: 600; }
        .zb-scn-chip.is-on .zb-scn-dot { background: #d9a520; }
        .zb-scn-reset { border-style: dashed; color: var(--zb-muted, #6b7280); }
        .zb-scn-manage { margin-left: auto; font-size: .76rem; text-decoration: none;
            color: var(--zb-accent, #2563eb); white-space: nowrap; }
        .zb-scn-banner { margin-top: .4rem; padding: .4rem .7rem; border-radius: 8px; font-size: .8rem;
            background: #fff5db; border: 1px solid #e6c34d; color: #6b4e05; }
        .zb-scn-warn { font-weight: 700; }
    </style>
@endonce
