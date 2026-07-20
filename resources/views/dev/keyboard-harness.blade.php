@extends('layouts.app')

@section('title', 'Keyboard Harness — ZeroBook')
@section('region', 'Dev · Keyboard Harness')

@section('content')
<div x-data="zbHarness" class="row g-3" style="max-width:1100px">

    {{-- ============ LEFT: interactive surfaces ============ --}}
    <div class="col-lg-7">

        {{-- Context trail --}}
        <div class="zb-panel">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="zb-context-trail">
                    <span class="text-muted">Context stack:</span>
                    <template x-for="(name, i) in $store.zb.trail()" :key="i">
                        <span class="zb-crumb" :class="{ 'is-top': i === $store.zb.trail().length - 1 }" x-text="name"></span>
                    </template>
                    <span x-show="$store.zb.trail().length === 0" class="text-muted">(root)</span>
                </div>
                <div class="text-muted" style="font-size:.78rem">
                    last key <span class="zb-kbd" x-text="lastKey"></span>
                </div>
            </div>
            <div class="text-muted" style="font-size:.78rem">
                Everything below runs 100% client-side. Open DevTools → Network and confirm it stays silent while you use the keyboard.
            </div>
        </div>

        {{-- Party form: field chaining + Ctrl+A commit --}}
        <form class="zb-panel" data-zb-form="party" x-on:zb:commit.prevent="commitParty()" @submit.prevent>
            <div class="zb-panel-title">
                Party Details
                <span class="text-muted" style="font-size:.72rem;font-weight:400">— Enter chains fields · Ctrl+A commits from anywhere</span>
            </div>

            <div class="zb-form-row">
                <label for="h-name">Name</label>
                <input id="h-name" class="form-control zb-field" data-zb-field data-zb-label="Name"
                       x-model="party.name" autocomplete="off" placeholder="Acme Traders">
            </div>
            <div class="zb-form-row">
                <label for="h-alias">Alias</label>
                <input id="h-alias" class="form-control zb-field" data-zb-field data-zb-label="Alias"
                       x-model="party.alias" autocomplete="off" placeholder="ACME">
            </div>
            <div class="zb-form-row">
                <label for="h-amount">Opening amount</label>
                <input id="h-amount" class="form-control zb-field" type="text" inputmode="decimal"
                       data-zb-field data-zb-label="Opening amount" x-model="party.amount" placeholder="0.00">
            </div>
            <div class="zb-form-row">
                <label for="h-type">Type</label>
                <select id="h-type" class="form-select zb-field" data-zb-field data-zb-label="Type" x-model="party.type">
                    <option>Sundry Debtor</option>
                    <option>Sundry Creditor</option>
                    <option>Bank Account</option>
                    <option>Cash-in-hand</option>
                </select>
            </div>
            <div class="zb-form-row">
                <label for="h-notes">Narration</label>
                <textarea id="h-notes" class="form-control zb-field" rows="2" data-zb-field data-zb-label="Narration"
                          x-model="party.notes" placeholder="Enter advances · Shift+Enter = newline"></textarea>
            </div>

            <div class="d-flex align-items-center gap-2 mt-2">
                <span class="zb-accepted-flash" x-show="partyAccepted" x-cloak x-transition>✔ Accepted</span>
                <span class="text-muted" style="font-size:.78rem">Last field → Enter commits, or Ctrl+A from any field.</span>
            </div>
        </form>

        {{-- Trigger for the nested sub-screens --}}
        <div class="zb-panel">
            <div class="zb-panel-title">Nested sub-screens (Esc-pop test)</div>
            <p class="text-muted" style="font-size:.82rem">
                Press <span class="zb-kbd">Alt+L</span> (or the button) to open <strong>Ledger List</strong> →
                <span class="zb-kbd">Enter</span> a ledger to open <strong>Ledger Detail</strong> →
                <span class="zb-kbd">Alt+A</span> to open <strong>Address Entry</strong>.
                That is 3 nested levels; <span class="zb-kbd">Esc</span> pops each and restores focus.
            </p>
            <button type="button" class="btn zb-btn-primary btn-sm" @click="openList()">
                Browse Ledgers <span class="zb-kbd" style="color:#fff;border-color:rgba(255,255,255,.5)">Alt+L</span>
            </button>
        </div>
    </div>

    {{-- ============ RIGHT: live engine log ============ --}}
    <div class="col-lg-5">
        <div class="zb-panel">
            <div class="zb-panel-title">Engine event log</div>
            <div class="zb-log">
                <template x-for="row in log" :key="row.n">
                    <div><span class="zb-log-key" x-text="'#' + row.n"></span>
                        <span :class="kindClass(row.kind)" x-text="' ' + row.msg"></span></div>
                </template>
            </div>
            <p class="text-muted mt-2" style="font-size:.74rem">
                Newest first. ctx = context push/pop, flow = field/menu movement, commit = form accepted, key = handled keystroke.
            </p>
        </div>
    </div>

    {{-- ============ Sub-screen 1: Ledger List (arrow nav + Enter drill) ============ --}}
    <div class="zb-subscreen zb-sub-1" x-show="showList" x-cloak x-transition.opacity>
        <div class="zb-subscreen-card">
            <div class="zb-subscreen-head">
                <span>Ledger List</span><span class="zb-kbd">↑ ↓ · Enter · Esc</span>
            </div>
            <div class="zb-subscreen-body">
                <p class="text-muted" style="font-size:.8rem">Arrow keys move the highlight; Enter opens the ledger.</p>
                <ul class="zb-list" id="h-ledger-list" data-zb-list tabindex="-1">
                    <template x-for="(lg, i) in ledgers" :key="lg">
                        <li class="zb-list-item" :class="{ 'is-active': i === listActive }"
                            @click="listActive = i; openDetail()" @mousemove="listActive = i" x-text="lg"></li>
                    </template>
                </ul>
            </div>
        </div>
    </div>

    {{-- ============ Sub-screen 2: Ledger Detail (nested form) ============ --}}
    <div class="zb-subscreen zb-sub-2" x-show="showDetail" x-cloak x-transition.opacity>
        <div class="zb-subscreen-card">
            <div class="zb-subscreen-head">
                <span x-text="'Ledger: ' + detail.name"></span><span class="zb-kbd">Alt+A · Ctrl+A · Esc</span>
            </div>
            <form class="zb-subscreen-body" data-zb-form="detail" x-on:zb:commit.prevent="commitDetail()" @submit.prevent>
                <div class="zb-form-row">
                    <label for="h-detail-name">Ledger name</label>
                    <input id="h-detail-name" class="form-control zb-field" data-zb-field data-zb-label="Ledger name" x-model="detail.name">
                </div>
                <div class="zb-form-row">
                    <label for="h-detail-under">Under group</label>
                    <select id="h-detail-under" class="form-select zb-field" data-zb-field data-zb-label="Under group" x-model="detail.under">
                        <option>Current Assets</option>
                        <option>Current Liabilities</option>
                        <option>Direct Expenses</option>
                        <option>Sales Accounts</option>
                    </select>
                </div>
                <div class="zb-form-row">
                    <label for="h-detail-open">Opening balance</label>
                    <input id="h-detail-open" type="text" inputmode="decimal" class="form-control zb-field"
                           data-zb-field data-zb-label="Opening balance" x-model="detail.opening" placeholder="0.00">
                </div>
                <p class="text-muted" style="font-size:.78rem;margin-top:.4rem">
                    <span class="zb-kbd">Alt+A</span> add address (deeper level) &middot;
                    <span class="zb-kbd">Ctrl+A</span> accept &middot; <span class="zb-kbd">Esc</span> back
                </p>
            </form>
        </div>
    </div>

    {{-- ============ Sub-screen 3: Address Entry (deepest nested form) ============ --}}
    <div class="zb-subscreen zb-sub-3" x-show="showAddress" x-cloak x-transition.opacity>
        <div class="zb-subscreen-card">
            <div class="zb-subscreen-head">
                <span>Address Entry</span><span class="zb-kbd">Ctrl+A · Esc</span>
            </div>
            <form class="zb-subscreen-body" data-zb-form="address" x-on:zb:commit.prevent="commitAddress()" @submit.prevent>
                <div class="zb-form-row">
                    <label for="h-addr-line1">Address</label>
                    <input id="h-addr-line1" class="form-control zb-field" data-zb-field data-zb-label="Address line" x-model="address.line1">
                </div>
                <div class="zb-form-row">
                    <label for="h-addr-city">City</label>
                    <input id="h-addr-city" class="form-control zb-field" data-zb-field data-zb-label="City" x-model="address.city">
                </div>
                <div class="zb-form-row">
                    <label for="h-addr-pin">PIN</label>
                    <input id="h-addr-pin" class="form-control zb-field" data-zb-field data-zb-label="PIN" x-model="address.pin">
                </div>
                <p class="text-muted" style="font-size:.78rem;margin-top:.4rem">
                    Deepest level (depth 4). <span class="zb-kbd">Ctrl+A</span> commits this form; <span class="zb-kbd">Esc</span> pops back through each level.
                </p>
            </form>
        </div>
    </div>
</div>
@endsection
