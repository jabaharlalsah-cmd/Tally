{{-- Phase 12A — Select Company (F1). Every active company in the tenant; --}}
{{-- keyboard-navigable, filter-by-typing; Enter switches and reloads the Gateway. --}}
{{-- Phase 16 — a name that matches nothing can be created here, without leaving the flow. --}}
<div class="zb-goto-backdrop" x-data="zbCompanyPicker" x-show="$store.zb.companyPicker.open" x-cloak
     x-transition.opacity @mousedown.self="close()" role="dialog" aria-label="Select Company">
    <div class="zb-goto">

        {{-- Create posts a REAL form (not fetch): landing on a fresh Gateway under the new
             company is the only correct cache invalidation — same contract as switching. --}}
        <form x-ref="createForm" method="POST" action="{{ route('company.create') }}" style="display:none">
            @csrf
            <input type="hidden" name="name" x-ref="createName">
        </form>

        <div class="zb-goto-input-wrap">
            <i class="ti ti-building"></i>
            <input id="zb-company-input" class="zb-goto-input" type="text"
                   x-model="query" @input="onQuery()"
                   @keydown.enter="if (filtered.length === 0 && query.trim()) { $refs.createName.value = query.trim(); $refs.createForm.submit(); }"
                   placeholder="Select company… type to filter"
                   autocomplete="off" spellcheck="false">

            {{-- Uses whatever is typed; with an empty box it opens the Companies screen. --}}
            <button type="button" class="zb-kbd" title="Create a new company"
                    style="border:0;font-family:inherit;cursor:pointer;white-space:nowrap"
                    @click="query.trim()
                        ? ($refs.createName.value = query.trim(), $refs.createForm.submit())
                        : window.location = '{{ route('companies') }}'">+ Add company</button>

            <span class="zb-kbd">Esc</span>
        </div>

        <div class="zb-goto-results" x-ref="list">
            <template x-if="filtered.length === 0">
                <div>
                    <div class="zb-goto-empty">No company matches “<span x-text="query"></span>”</div>

                    {{-- The fast path: create exactly what was typed. --}}
                    <div class="zb-goto-item" x-show="query.trim().length > 0" style="cursor:pointer"
                         @click="$refs.createName.value = query.trim(); $refs.createForm.submit()">
                        <i class="ti ti-plus"></i>
                        <span>Create company “<span x-text="query.trim()"></span>”</span>
                        <span class="zb-goto-item-sub">Enter</span>
                    </div>
                </div>
            </template>

            <template x-for="(c, i) in filtered" :key="c.id">
                <div class="zb-goto-item" :class="{ 'is-active': i === active }"
                     @click="active = i; select()" @mousemove="active = i">
                    <i class="ti" :class="c.id === currentId ? 'ti-building-store' : 'ti-building'"></i>
                    <span x-text="c.name"></span>
                    <span class="zb-goto-item-sub" x-text="c.id === currentId ? 'current' : c.slug"></span>
                </div>
            </template>
        </div>
    </div>
</div>
