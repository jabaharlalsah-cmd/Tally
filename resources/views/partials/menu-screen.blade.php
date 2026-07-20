{{-- Shared sectioned menu screen — the Gateway and Display More Reports.

     Renders ONE flat row list with the section headings included as rows, which
     is what lets ↑/↓ wrap across section boundaries and a hot letter jump
     anywhere on the screen. Rendering each section as its own nested list, each
     with its own index, would quietly break both. See zbGateway() in
     resources/js/engine/components.js.

     The <li> keeps the existing .zb-menu-item class and active state, so every
     rule already written for the flat menu applies here untouched.

     Expects: $heading, $sub, $sections, $name, $focusEl, optionally $hubUrl. --}}
<div class="zb-gateway"
     x-data="zbGateway({
        sections: @js($sections),
        name: @js($name),
        label: @js($heading),
        focusEl: @js($focusEl),
        @isset($hubUrl) hubUrl: @js($hubUrl), @endisset
     })"
     tabindex="-1" id="{{ ltrim($focusEl, '#') }}">

    <h1 class="zb-gateway-heading">{{ $heading }}</h1>
    <p class="zb-gateway-sub">{!! $sub !!}</p>

    <ul class="zb-menu" role="menu" x-ref="list">
        <template x-for="row in rows" :key="row.type + ':' + (row.letter || '') + ':' + row.label">
            <li :class="row.type === 'header'
                            ? 'zb-menu-section'
                            : 'zb-menu-item' + (row.itemIndex === active ? ' is-active' : '')"
                :role="row.type === 'header' ? 'presentation' : 'menuitem'"
                @click="if (row.type === 'item') { active = row.itemIndex; choose(row.itemIndex); }"
                @mousemove="if (row.type === 'item') active = row.itemIndex">

                <template x-if="row.type === 'header'">
                    <span x-text="row.label"></span>
                </template>

                <template x-if="row.type === 'item'">
                    <span>
                        <span x-text="hotLabel(row).pre"></span><span class="zb-hot" x-text="hotLabel(row).hot"></span><span x-text="hotLabel(row).post"></span>
                    </span>
                </template>

                <template x-if="row.type === 'item'">
                    <span class="zb-menu-desc" x-text="row.desc"></span>
                </template>
            </li>
        </template>
    </ul>
</div>
