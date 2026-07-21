@extends('layouts.app')

@section('title', 'List of Accounts — ZeroBook')
@section('region', 'Masters · List of Accounts')

@section('content')
    <div class="zb-loa" id="zb-loa" tabindex="-1"
         x-data="listOfAccounts({
            hubUrl: @js(route('masters.index')),
            groupsUrl: @js(route('masters.groups')),
            ledgersUrl: @js(route('masters.ledgers')),
            symbol: @js(baseSymbol())
         })"
         x-init="$store.masters.seed(@js($groups), @js($ledgers))">

        <h1 class="zb-gateway-heading">List of Accounts</h1>
        <p class="zb-gateway-sub">
            The whole chart of accounts, grouped by nature.
            <span class="zb-kbd">&uarr;</span> <span class="zb-kbd">&darr;</span> move &middot;
            <span class="zb-kbd">&rarr;</span> expand &middot; <span class="zb-kbd">&larr;</span> collapse &middot;
            <span class="zb-kbd">Enter</span> alter &middot; <span class="zb-kbd">Esc</span> back
        </p>

        <div class="zb-panel">
            <div class="zb-loa-tree" x-ref="tree">
                <template x-for="(row, i) in rows" :key="row.key">
                    <div class="zb-loa-row"
                         :class="{
                            'is-active': i === selected,
                            'is-nature': row.kind === 'nature',
                            'is-ledger': row.kind === 'ledger',
                            'is-retired': row.retired
                         }"
                         :style="'padding-left:' + (0.6 + row.depth * 1.15) + 'rem'"
                         @click="toggleRow(i)" @dblclick="drill()" @mousemove="selected = i">

                        {{-- Disclosure marker. Rows with nothing under them get a
                             blank of the same width so labels stay in one column. --}}
                        <span class="zb-loa-caret"
                              x-text="row.hasChildren ? (isOpen(row.key) ? '▾' : '▸') : ''"></span>

                        <span class="zb-loa-label" x-text="row.label"></span>

                        <span class="zb-loa-tags">
                            <span class="zb-reserved-tag" x-show="row.reserved" x-cloak>reserved</span>
                            <span class="zb-retired-tag" x-show="row.retired" x-cloak>retired</span>
                        </span>

                        <span class="zb-loa-sub" x-text="row.sub"></span>
                    </div>
                </template>

                <div class="zb-list-empty" x-show="rows.length === 0" x-cloak>
                    Nothing to show.
                </div>
            </div>
        </div>

        {{-- Retired masters are hidden by default, but the count is always on
             screen: a silently filtered list is how someone concludes their data
             has vanished. --}}
        <p class="text-muted zb-ws-hint" x-show="retiredCount > 0" x-cloak>
            <span x-text="retiredCount"></span> retired
            <span x-text="retiredCount === 1 ? 'master is' : 'masters are'"></span>
            <span x-text="showRetired ? 'shown' : 'hidden'"></span>.
            <button type="button" class="zb-linkbutton"
                    @click="showRetired = !showRetired; selected = 0"
                    x-text="showRetired ? 'Hide them' : 'Show them'"></button>
            <span class="zb-kbd">Alt+I</span>
        </p>
    </div>
@endsection
