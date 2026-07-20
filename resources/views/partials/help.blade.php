{{-- Keyboard help — opened with F1 from anywhere, closed with Esc.
     Every row is read live from the engine's own registry, so a shortcut
     cannot be added to the app and forgotten here. Labels carry the
     platform's own modifier symbols (Ctrl/Alt on Windows, ⌘/⌥ on a Mac). --}}
<div class="zb-help-backdrop" x-data="zbHelp" x-show="$store.zb.help.open" x-cloak
     x-transition.opacity @mousedown.self="close()" role="dialog" aria-label="Keyboard shortcuts">
    <div class="zb-help" id="zb-help-panel" tabindex="-1">

        <header class="zb-help-head">
            <h2><i class="ti ti-keyboard"></i> Keyboard shortcuts</h2>
            <span class="zb-help-close" @click="close()">
                <span class="zb-kbd">Esc</span> close
            </span>
        </header>

        <div class="zb-help-body">
            <template x-for="g in groups" :key="g.label">
                <section class="zb-help-group">
                    <h3 x-text="g.label"></h3>
                    <dl>
                        <template x-for="a in g.items" :key="g.label + ':' + a.key">
                            <div class="zb-help-row">
                                <dt><span class="zb-kbd" x-text="a.hint"></span></dt>
                                <dd x-text="a.label"></dd>
                            </div>
                        </template>
                    </dl>
                </section>
            </template>

            {{-- The keys the browser keeps for itself. Empty in the desktop
                 build and nearly empty once installed, so it is rendered only
                 when there is genuinely something to warn about. --}}
            <template x-if="fallbacks.length > 0">
                <section class="zb-help-group zb-help-reserved">
                    <h3>Keys your browser keeps</h3>
                    <p class="zb-help-note">
                        A browser tab cannot take these. Install ZeroBook as an app
                        (or use ZeroBook Desktop) and most of them become available.
                    </p>

                    {{-- Chromium-family browsers give us a real prompt; Safari
                         has no such API, so it gets the manual recipe instead. --}}
                    <template x-if="installable">
                        <button type="button" class="zb-help-install" @click="install()">
                            <i class="ti ti-download"></i> Install for full keyboard support
                        </button>
                    </template>
                    <template x-if="!installable && !installed && installHintText">
                        <p class="zb-help-note"><strong x-text="installHintText"></strong></p>
                    </template>
                    <dl>
                        <template x-for="f in fallbacks" :key="f.key">
                            <div class="zb-help-row">
                                <dt><span class="zb-kbd" x-text="f.key"></span></dt>
                                <dd>
                                    <span x-text="f.purpose"></span>
                                    <em>use <span x-text="f.alternate"></span></em>
                                </dd>
                            </div>
                        </template>
                    </dl>
                </section>
            </template>
        </div>

    </div>
</div>
