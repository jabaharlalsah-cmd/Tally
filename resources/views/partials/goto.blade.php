{{-- Go To universal navigator — opened with Alt+G. Fully keyboard-operable. --}}
<div class="zb-goto-backdrop" x-data="zbGoto" x-show="$store.zb.goto.open" x-cloak
     x-transition.opacity @mousedown.self="close()" role="dialog" aria-label="Go To">
    <div class="zb-goto">
        <div class="zb-goto-input-wrap">
            <i class="ti ti-search"></i>
            <input id="zb-goto-input" class="zb-goto-input" type="text"
                   x-model="query" @input="onQuery()"
                   placeholder="Go To… type to search screens &amp; tools"
                   autocomplete="off" spellcheck="false">
            <span class="zb-kbd">Esc</span>
        </div>
        <div class="zb-goto-results" x-ref="list">
            <template x-if="filtered.length === 0">
                <div class="zb-goto-empty">No matches for “<span x-text="query"></span>”</div>
            </template>
            <template x-for="(d, i) in filtered" :key="d.label">
                <div class="zb-goto-item" :class="{ 'is-active': i === active }"
                     @click="active = i; select()" @mousemove="active = i">
                    <i class="ti" :class="d.icon || 'ti-arrow-right'"></i>
                    <span x-text="d.label"></span>
                    <span class="zb-goto-item-sub" x-text="d.sub"></span>
                </div>
            </template>
        </div>
    </div>
</div>
