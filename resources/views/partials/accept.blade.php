{{-- Tally's "Accept? Yes or No" gate — the confirmation every master creation
     passes through. State and keys both live in the engine ($store.zb.accept,
     pushed context 'zb.accept'), so this markup is display only: Y/Enter and
     N/Esc are bound there, not here. Opened via $store.zb.askAccept(). --}}
{{-- No x-transition: a fade defers the toggle, and this prompt has to be on
     screen the instant it takes the keyboard — the same reason the delete
     confirms are plain x-show. --}}
<div class="zb-modal-backdrop zb-accept-backdrop" x-show="$store.zb.accept.open" x-cloak
     role="dialog" aria-modal="true" aria-label="Accept?">
    <div class="zb-modal zb-accept">
        <div class="zb-modal-head" x-text="$store.zb.accept.title"></div>
        <div class="zb-modal-body">
            <p class="zb-accept-q" x-text="$store.zb.accept.body"></p>
            <p class="text-muted zb-accept-hint">
                <span class="zb-kbd">Y</span> / <span class="zb-kbd">Enter</span> yes &middot;
                <span class="zb-kbd">N</span> / <span class="zb-kbd">Esc</span> no
            </p>
        </div>
        <div class="zb-modal-foot">
            <button type="button" id="zb-accept-no" class="btn btn-sm"
                    @click="$store.zb.accept.no()">No</button>
            <button type="button" id="zb-accept-yes" class="btn btn-sm zb-btn-primary"
                    @click="$store.zb.accept.yes()">Yes</button>
        </div>
    </div>
</div>
