{{-- Calculator pane — toggled with Ctrl+N (or Alt+N fallback) --}}
<div class="zb-calc" x-data="zbCalc" x-show="calc.open" x-cloak
     x-transition.opacity role="dialog" aria-label="Calculator">
    <div class="zb-calc-head">
        <span><i class="ti ti-calculator"></i> Calculator</span>
        <span class="zb-kbd">Ctrl+N</span>
    </div>
    <div class="zb-calc-body">
        <input id="zb-calc-input" class="zb-calc-input" type="text"
               x-model="calc.expr" @input="onInput()"
               placeholder="e.g. 1200*18/100 + (50-5)"
               autocomplete="off" spellcheck="false" inputmode="text">
        <div class="zb-calc-result" :class="{ 'is-error': calc.error }"
             x-text="calc.error ? calc.error : (calc.result !== '' ? calc.result : '0')"></div>

        <template x-if="calc.tape.length">
            <div class="zb-calc-tape">
                <template x-for="(row, i) in calc.tape" :key="i">
                    <div class="zb-calc-tape-row">
                        <span x-text="row.expr"></span>
                        <strong x-text="'= ' + row.value"></strong>
                    </div>
                </template>
            </div>
        </template>

        <div class="zb-calc-hint">Enter = add to tape &middot; Esc / Ctrl+N = close &middot; + &minus; &times; &divide; % ( )</div>
    </div>
</div>
