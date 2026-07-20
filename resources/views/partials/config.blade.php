{{-- F12 Configuration overlay — context-aware. --}}
<div class="zb-goto-backdrop" x-data="zbConfig()" x-show="open" x-cloak
     @click.self="$store.zb.escape()" style="z-index:1310">
    <div class="zb-config" @click.stop>
        <div class="zb-config-head">
            <span>F12 · Configure</span>
            <span class="zb-config-ctx" x-text="ctxLabel"></span>
        </div>
        <div id="config-surface" tabindex="-1" class="zb-config-body">
            <template x-if="!options.length">
                <div class="zb-goto-empty">No configurable options for this screen.</div>
            </template>
            <template x-for="(o, i) in options" :key="o.key">
                <div class="zb-config-item" :class="{ 'is-active': i === active }"
                     @click="active = i; toggle()" @mousemove="active = i">
                    <span x-text="o.label"></span>
                    <span class="zb-feature-toggle" :class="{ 'is-on': val(o.key) }" x-text="val(o.key) ? 'Yes' : 'No'"></span>
                </div>
            </template>
        </div>
        <div class="zb-config-foot">
            <span class="zb-kbd">↑</span><span class="zb-kbd">↓</span> move ·
            <span class="zb-kbd">Enter</span> toggle · <span class="zb-kbd">Esc</span> close
        </div>
    </div>
</div>
