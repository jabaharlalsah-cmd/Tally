@php($__boot = $this->bootData())
<div class="zb-voucher"
     {{-- Real user typing arms the unsaved-work guard; framework-dispatched
          events do not (isTrusted). See hasUnsavedWork() in vouchers/screen.js. --}}
     @input="noteUserInput($event)" @change="noteUserInput($event)"
     :class="{ 'zb-ws-scenario': scenariosEnabled && !!scenarioId }"
     x-data="voucherScreen(Object.assign(@js($__boot), {
        dayBookUrl: @js(route('daybook')),
        gatewayUrl: @js(route('gateway')),
        printUrl: @js(route('vouchers.print', ['voucher' => '__id__']))
     }))">

    <div class="zb-ws-flash" x-show="flash" x-cloak :class="flashKind === 'warn' ? 'is-warn' : 'is-ok'" x-text="flash"></div>

    {{-- header --}}
    <div class="zb-voucher-head">
        <div class="zb-voucher-title">
            <span class="zb-voucher-type" x-text="typeLabel()"></span>
            <span class="zb-voucher-mode" x-show="isInvoiceType" x-cloak
                  x-text="showInvoice ? 'as Invoice' : 'as Voucher'"></span>
            <span class="zb-voucher-no" x-text="'No. ' + displayNumber()"></span>
            <span class="zb-voucher-edit" x-show="editId" x-cloak>· altering</span>
        </div>
        <div class="zb-voucher-date">
            <button type="button" class="zb-mode-toggle" x-show="isInvoiceType" x-cloak
                    @click="toggleInvoiceMode()"
                    x-text="invoiceToggleLabel() + ' (Ctrl+V)'" title="Toggle As Invoice / As Voucher (Ctrl+V)"></button>
            <button type="button" class="zb-mode-toggle" x-show="showInvoice && stockEnabled" x-cloak
                    @click="toggleItemMode()"
                    x-text="itemToggleLabel() + ' (Ctrl+I)'" title="Toggle Item / Accounting invoice (Ctrl+I)"></button>
            {{-- Phase 15C — provisional scenario tag. Shown only when the scenarios feature is on;
                 picking one turns this voucher into a what-if that stays OUT of the real books. --}}
            <template x-if="scenariosEnabled && scenarios.length">
                <span class="zb-ws-scn-pick" :class="{ 'is-on': !!scenarioId }" title="Post this voucher into a what-if scenario (kept out of your real books until promoted)">
                    <label for="v-scenario">Scenario</label>
                    <select id="v-scenario" class="form-control zb-field" data-zb-noselect x-model="scenarioId">
                        <option value="">— Real books —</option>
                        <template x-for="s in scenarios" :key="s.id">
                            <option :value="s.id" x-text="s.name"></option>
                        </template>
                    </select>
                </span>
            </template>
            <label for="v-date-dd">Date <span class="zb-kbd">F2</span></label>
            {{-- Tally-order DD-MM-YYYY, one input per segment: a native <input type="date">
                 cannot be advanced segment-by-segment from script, and Enter must chain
                 day → month → year → the first field of the entry form. --}}
            <div class="zb-datefield" data-zb-datefield :class="{ 'is-bad': !dateOk }">
                <input type="text" id="v-date-dd" class="zb-datefield-seg" data-datepart="dd"
                       data-zb-label="Day" aria-label="Day" placeholder="DD"
                       inputmode="numeric" maxlength="2" autocomplete="off"
                       :value="dp.dd" @input="onDatePartInput('dd', $event)"
                       @keydown.backspace="onDateBackspace('dd', $event)"
                       @keydown.arrow-left="onDateArrow('dd', -1, $event)"
                       @keydown.arrow-right="onDateArrow('dd', 1, $event)"
                       @blur="onDateBlur()">
                <span class="zb-datefield-sep">-</span>
                <input type="text" id="v-date-mm" class="zb-datefield-seg" data-datepart="mm"
                       data-zb-label="Month" aria-label="Month" placeholder="MM"
                       inputmode="numeric" maxlength="2" autocomplete="off"
                       :value="dp.mm" @input="onDatePartInput('mm', $event)"
                       @keydown.backspace="onDateBackspace('mm', $event)"
                       @keydown.arrow-left="onDateArrow('mm', -1, $event)"
                       @keydown.arrow-right="onDateArrow('mm', 1, $event)"
                       @blur="onDateBlur()">
                <span class="zb-datefield-sep">-</span>
                <input type="text" id="v-date-yyyy" class="zb-datefield-seg is-year" data-datepart="yyyy"
                       data-zb-label="Year" aria-label="Year" placeholder="YYYY"
                       inputmode="numeric" maxlength="4" autocomplete="off"
                       :value="dp.yyyy" @input="onDatePartInput('yyyy', $event)"
                       @keydown.backspace="onDateBackspace('yyyy', $event)"
                       @keydown.arrow-left="onDateArrow('yyyy', -1, $event)"
                       @keydown.arrow-right="onDateArrow('yyyy', 1, $event)"
                       @blur="onDateBlur()">
            </div>
            <span class="zb-voucher-fy" x-text="'FY ' + fyLabel"></span>
        </div>
    </div>

    {{-- Phase 15C — the provisional banner + subtle tint when a scenario is selected. --}}
    <div class="zb-ws-scn-banner" x-show="scenariosEnabled && !!scenarioId" x-cloak>
        <span>⚠ Provisional entry — this voucher is a
            <strong x-text="(scenarios.find(s => String(s.id) === String(scenarioId)) || {}).name"></strong>
            what-if and will <strong>not</strong> appear in your real books until the scenario is promoted.</span>
    </div>

    @once
        <style>
            .zb-ws-scn-pick { display:inline-flex; align-items:center; gap:.3rem; }
            .zb-ws-scn-pick.is-on select { background:#fff5db; border-color:#d9a520; color:#6b4e05; font-weight:600; }
            .zb-ws-scenario .zb-voucher-body { box-shadow: inset 0 0 0 2px #e6c34d; background: linear-gradient(0deg, rgba(255,245,219,.35), rgba(255,245,219,.35)); }
            .zb-ws-scn-banner { margin:.1rem 0 .6rem; padding:.4rem .7rem; border-radius:8px; font-size:.82rem; background:#fff5db; border:1px solid #e6c34d; color:#6b4e05; }
        </style>
    @endonce

    {{-- entry form --}}
    <form class="zb-panel zb-voucher-body" data-zb-form="voucher" x-on:zb:commit.prevent="accept()" @submit.prevent>

        {{-- ============ AS-INVOICE mode — Sales / Purchase / Debit & Credit Notes ============ --}}
        <div class="zb-invoice" x-show="showInvoice" x-cloak>
            {{-- Phase 8A — a prominent heading so a Note is never mistaken for an invoice. --}}
            <div class="zb-note-banner" x-show="isNoteType" x-cloak
                 style="margin:-.1rem 0 .9rem;padding:.5rem .85rem;border-radius:8px;background:rgba(11,110,79,.08);border:1px solid rgba(11,110,79,.4);font-weight:700;font-size:1.02rem">
                <span x-text="noteHeading()"></span>
                <span style="font-weight:500;color:#5c6b63"> · <span x-text="noteSideLabel()"></span></span>
            </div>

            {{-- Phase 8A — reference the original invoice being adjusted (optional; pre-fills items). --}}
            <div class="zb-form-row zb-invoice-ref" x-show="isNoteType" x-cloak>
                <label for="v-refvoucher" x-text="type === 'credit_note' ? 'Against sale invoice' : 'Against purchase invoice'"></label>
                <select id="v-refvoucher" class="form-control zb-field" data-zb-noselect
                        :value="referenceVoucherId" @change="applyReference($event.target.value)">
                    <option value="">— free-standing (no reference) —</option>
                    <template x-for="ri in referenceOptions" :key="ri.id">
                        <option :value="ri.id" x-text="ri.display_number + ' · ' + ri.date_label + ' · ' + (ri.party_label || '') + ' · ' + ri.amount"></option>
                    </template>
                </select>
            </div>

            {{-- Phase 8B double-stock safeguard (client half) — a Sales/Purchase invoice --}}
            {{-- may bill against a Delivery/Receipt Note that ALREADY moved the goods.    --}}
            {{-- Picking one pre-fills the delivered items and marks that THIS invoice     --}}
            {{-- will NOT move stock again (the server enforces & asserts the same).       --}}
            <div class="zb-form-row zb-invoice-ref" x-show="invoiceCanReferenceDelivery && !isNoteType" x-cloak>
                <label for="v-delref" x-text="noteLabelForDelivery()"></label>
                <select id="v-delref" class="form-control zb-field" data-zb-noselect
                        :value="referenceVoucherId" @change="applyDeliveryReference($event.target.value)">
                    <option value="">— none (this invoice moves the stock itself) —</option>
                    <template x-for="dn in invoiceDeliveryOptions" :key="dn.id">
                        <option :value="dn.id" x-text="dn.display_number + ' · ' + dn.date_label + ' · ' + (dn.party_label || '') + ' · ' + dn.pending_label"></option>
                    </template>
                </select>
            </div>
            <div class="zb-note-banner" x-show="invoiceSkipsStock" x-cloak
                 style="margin:-.35rem 0 .8rem;padding:.4rem .8rem;border-radius:8px;background:rgba(176,120,0,.09);border:1px solid rgba(176,120,0,.4);font-size:.9rem">
                <strong>Stock already moved</strong> by <span x-text="referenceLabel"></span> — this invoice posts the accounting only and will <strong>not</strong> move stock again.
            </div>

            {{-- Party A/c name (shared) --}}
            <div class="zb-form-row zb-invoice-party">
                <label x-text="partyFieldLabel()"></label>
                <div style="flex:1">
                    @include('partials.voucher-ledger-combo', ['comboId' => "'vparty'", 'initId' => 'partyLedgerId', 'initLabel' => 'partyLedgerLabel', 'col' => 'party', 'dataLine' => ''])
                    <span class="zb-line-bal" x-show="partyLedgerId" x-cloak>Current: <span x-text="balFor(partyLedgerId)"></span></span>
                    {{-- Phase 12B — derived inter-company marker on the invoice party --}}
                    <span class="zb-ic-badge" x-show="lineInterCompanyId({ ledger_id: partyLedgerId })" x-cloak
                          x-text="'Inter-Company → ' + lineInterCompanyName({ ledger_id: partyLedgerId })"></span>
                </div>
            </div>

            {{-- Reference / supplier invoice no. + reference date (shared) --}}
            <div class="zb-form-row zb-invoice-ref">
                <label for="v-refno">Ref / Supplier Inv. No.</label>
                <input type="text" id="v-refno" class="form-control zb-field zb-invoice-refno"
                       data-zb-field data-col="refno" data-zb-label="Reference No"
                       x-model="referenceNo" autocomplete="off" placeholder="e.g. INV-2045">
                <label for="v-refdate" class="zb-invoice-ref-datelbl">Ref Date</label>
                <input type="date" id="v-refdate" class="form-control zb-field zb-invoice-refdate"
                       data-zb-field data-zb-noselect data-col="refdate" data-zb-label="Reference Date"
                       x-model="referenceDate">
            </div>

            {{-- ---------- ITEM INVOICE: stock lines (Qty × Rate = Amount) ---------- --}}
            <div x-show="showItemInvoice" x-cloak>
                <table class="zb-vtable zb-item-table">
                    <thead>
                        <tr>
                            <th>Stock Item (name)</th>
                            <th style="width:12rem">Godown</th>
                            <th style="width:7rem" class="zb-vt-right">Qty</th>
                            <th style="width:9rem" class="zb-vt-right">Rate</th>
                            <th style="width:11rem" class="zb-vt-right">Amount</th>
                            <th style="width:2rem"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(it, idx) in items" :key="it.uid">
                            <tr class="zb-vrow">
                                <td>
                                    @include('partials.voucher-item-combo', ['comboId' => "'istem-' + it.uid", 'initId' => 'it.stock_item_id', 'initLabel' => 'it.stock_item_label', 'dataLine' => ':data-item="idx"'])
                                </td>
                                <td>
                                    @include('partials.voucher-godown-combo', ['comboId' => "'igod-' + it.uid", 'initId' => 'it.godown_id', 'initLabel' => 'it.godown_label', 'dataLine' => ':data-item="idx"'])
                                </td>
                                <td class="zb-vt-right">
                                    <input type="text" inputmode="decimal" class="form-control zb-field zb-v-amt"
                                           data-zb-field :data-item="idx" data-col="qty" data-zb-label="Quantity"
                                           x-model="it.qty" placeholder="0">
                                </td>
                                <td class="zb-vt-right">
                                    <input type="text" inputmode="decimal" class="form-control zb-field zb-v-amt"
                                           data-zb-field :data-item="idx" data-col="rate" data-zb-label="Rate (selling)"
                                           x-model="it.rate" placeholder="0.00">
                                </td>
                                <td class="zb-vt-right zb-item-amt">
                                    <span x-text="fmt(itemAmount(it))"></span>
                                    <span class="zb-vt-muted" x-show="itemUnitSymbol(it)" x-text="'/' + itemUnitSymbol(it)"></span>
                                </td>
                                <td class="zb-vt-x">
                                    <button type="button" class="zb-vt-remove" title="Remove item (Alt+R)"
                                            @click="removeItem(idx)" tabindex="-1">×</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot>
                        {{-- the single revenue ledger Σ item amounts post to --}}
                        <tr class="zb-item-ledger-row">
                            <td colspan="2">
                                <label class="zb-item-ledger-lbl" x-text="invoiceLedgerLabel() + ':'"></label>
                                @include('partials.voucher-ledger-combo', ['comboId' => "'vitemledger'", 'initId' => 'itemLedgerId', 'initLabel' => 'itemLedgerLabel', 'col' => 'itemledger', 'dataLine' => ''])
                            </td>
                            <td class="zb-vt-right" colspan="2">Taxable Value</td>
                            <td class="zb-vt-right" x-text="fmt(itemSubtotal)"></td>
                            <td></td>
                        </tr>
                        {{-- item-sourced tax lines (each item's own rate; computed live) --}}
                        <template x-for="(t, ti) in taxDisplayLines" :key="'itax-' + ti">
                            <tr class="zb-vtax">
                                <td colspan="4" class="zb-vt-right"><span x-text="t.label"></span> @ <span x-text="t.rate"></span>%</td>
                                <td class="zb-vt-right" x-text="fmt(t.amount)"></td>
                                <td></td>
                            </tr>
                        </template>
                        <tr class="zb-vtotals zb-vgrand" x-show="taxOn" x-cloak>
                            <td colspan="4" class="zb-vt-right" x-text="'Invoice Total (incl. ' + taxRegimeLabel() + ')'"></td>
                            <td class="zb-vt-right" x-text="fmt(invoiceGrandTotal)"></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                <p class="zb-invoice-note">
                    <span x-show="taxOn" x-cloak><span class="zb-gst-badge" x-text="supplyKindLabel()"></span> · </span>
                    Item invoice — the <strong>selling</strong> rate above becomes revenue; the
                    <strong>cost</strong> (weighted-average) is computed by the server and posted to the stock ledger.
                </p>
            </div>

            {{-- ---------- ACCOUNTING INVOICE: ledger allocation lines ---------- --}}
            <div x-show="showAcctInvoice" x-cloak>
                <table class="zb-vtable zb-invoice-table">
                    <thead>
                        <tr>
                            <th><span x-text="invoiceLedgerLabel()"></span> (particulars)</th>
                            <th style="width:12rem" class="zb-vt-right">Amount</th>
                            <th style="width:2rem"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(line, idx) in lines" :key="line.uid">
                            <tr class="zb-vrow" :class="{ 'is-active': idx === lineActive }">
                                <td>
                                    @include('partials.voucher-ledger-combo', ['comboId' => "'vline-' + line.uid", 'initId' => 'line.ledger_id', 'initLabel' => 'line.ledger_label', 'col' => 'ledger', 'dataLine' => ':data-line="idx"'])
                                    <span class="zb-line-bal" x-show="line.ledger_id" x-cloak>Bal: <span x-text="balFor(line.ledger_id)"></span></span>
                                </td>
                                <td class="zb-vt-right">
                                    <input type="text" inputmode="decimal" class="form-control zb-field zb-v-amt"
                                           data-zb-field :data-line="idx" data-col="amount" data-zb-label="Amount"
                                           x-model="line.amount" placeholder="0.00">
                                </td>
                                <td class="zb-vt-x">
                                    <button type="button" class="zb-vt-remove" title="Remove line (Alt+R)"
                                            @click="removeLine(idx)" tabindex="-1">×</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot>
                        <tr class="zb-vtotals">
                            <td class="zb-vt-right">Taxable Value</td>
                            <td class="zb-vt-right" x-text="fmt(invoiceTotal)"></td>
                            <td></td>
                        </tr>
                        <template x-for="(t, ti) in taxDisplayLines" :key="'tax-' + ti">
                            <tr class="zb-vtax">
                                <td class="zb-vt-right">
                                    <span x-text="t.label"></span> @ <span x-text="t.rate"></span>%
                                </td>
                                <td class="zb-vt-right" x-text="fmt(t.amount)"></td>
                                <td></td>
                            </tr>
                        </template>
                        <tr class="zb-vtotals zb-vgrand" x-show="taxOn" x-cloak>
                            <td class="zb-vt-right" x-text="'Invoice Total (incl. ' + taxRegimeLabel() + ')'"></td>
                            <td class="zb-vt-right" x-text="fmt(invoiceGrandTotal)"></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                <p class="zb-invoice-note">
                    <span x-show="taxOn" x-cloak><span class="zb-gst-badge" x-text="supplyKindLabel()"></span> · </span>
                    Accounting invoice (ledger amounts, no stock movement).
                    <span x-show="stockEnabled">Press <span class="zb-kbd">Ctrl+I</span> for an item invoice.</span>
                </p>
            </div>

            <p class="zb-single-hint"
               x-text="'Posts as double-entry: ' + invoicePartySide() + ' ' + (partyLedgerLabel || 'party') + '  •  ' + invoiceLedgerSide() + ' the ' + invoiceLedgerLabel().toLowerCase() + '(s)' + (taxOn ? ' + ' + invoiceLedgerSide() + ' the ' + taxRegimeLabel() + ' ledger(s)' : '')"></p>
        </div>

        {{-- ============ Phase 8B — INVENTORY-WORKFLOW vouchers ============ --}}
        {{-- Sales/Purchase Order · Delivery/Receipt Note · Rejections In/Out. Item     --}}
        {{-- lines only — NO ledger side, NO tax, NO Trial-Balance impact. Orders are    --}}
        {{-- commitments; Delivery/Receipt Notes & Rejections move real stock.           --}}
        <div class="zb-invoice zb-workflow" x-show="showWorkflow" x-cloak>
            <div class="zb-note-banner" x-cloak
                 style="margin:-.1rem 0 .9rem;padding:.5rem .85rem;border-radius:8px;background:rgba(11,110,79,.08);border:1px solid rgba(11,110,79,.4);font-weight:700;font-size:1.02rem">
                <span x-text="workflowHeading()"></span>
                <span style="font-weight:500;color:#5c6b63"> · <span x-text="workflowSubLabel()"></span></span>
            </div>

            {{-- Reference the Order (for a Delivery/Receipt Note) or the Note (for a --}}
            {{-- Rejection) this fulfils; picking one pre-fills party + PENDING items. --}}
            <div class="zb-form-row zb-invoice-ref" x-show="workflowHasReference" x-cloak>
                <label for="v-wfref" x-text="workflowReferenceLabel()"></label>
                <select id="v-wfref" class="form-control zb-field" data-zb-field data-zb-noselect data-col="wfref" data-zb-label="Reference"
                        :value="referenceVoucherId" @change="applyWorkflowReference($event.target.value)">
                    <option value="">— free-standing (no reference) —</option>
                    <template x-for="ro in workflowReferenceOptions" :key="ro.id">
                        <option :value="ro.id" x-text="ro.display_number + ' · ' + ro.date_label + ' · ' + (ro.party_label || '') + ' · ' + ro.pending_label"></option>
                    </template>
                </select>
            </div>

            {{-- Party A/c name (customer for Sales-side, supplier for Purchase-side). --}}
            <div class="zb-form-row zb-invoice-party">
                <label x-text="workflowPartyLabel()"></label>
                <div style="flex:1">
                    @include('partials.voucher-ledger-combo', ['comboId' => "'vparty'", 'initId' => 'partyLedgerId', 'initLabel' => 'partyLedgerLabel', 'col' => 'party', 'dataLine' => ''])
                    <span class="zb-line-bal" x-show="partyLedgerId" x-cloak>Current: <span x-text="balFor(partyLedgerId)"></span></span>
                </div>
            </div>

            {{-- Optional external document reference (order no., challan no., …). --}}
            <div class="zb-form-row zb-invoice-ref">
                <label for="v-wf-refno">Ref / Doc No.</label>
                <input type="text" id="v-wf-refno" class="form-control zb-field zb-invoice-refno"
                       data-zb-field data-col="wfrefno" data-zb-label="Reference No"
                       x-model="referenceNo" autocomplete="off" placeholder="e.g. SO-1042 / Challan 88">
                <label for="v-wf-refdate" class="zb-invoice-ref-datelbl">Ref Date</label>
                <input type="date" id="v-wf-refdate" class="form-control zb-field zb-invoice-refdate"
                       data-zb-field data-zb-noselect data-col="wfrefdate" data-zb-label="Reference Date"
                       x-model="referenceDate">
            </div>

            {{-- Item grid: Qty × Rate = Amount. NO revenue ledger, NO tax, NO balance. --}}
            <table class="zb-vtable zb-item-table">
                <thead>
                    <tr>
                        <th>Stock Item (name)</th>
                        <th style="width:12rem">Godown</th>
                        <th style="width:7rem" class="zb-vt-right">Qty</th>
                        <th style="width:9rem" class="zb-vt-right">Rate</th>
                        <th style="width:11rem" class="zb-vt-right">Amount</th>
                        <th style="width:2rem"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(it, idx) in items" :key="it.uid">
                        <tr class="zb-vrow">
                            <td>
                                @include('partials.voucher-item-combo', ['comboId' => "'wstem-' + it.uid", 'initId' => 'it.stock_item_id', 'initLabel' => 'it.stock_item_label', 'dataLine' => ':data-item="idx"'])
                            </td>
                            <td>
                                @include('partials.voucher-godown-combo', ['comboId' => "'wgod-' + it.uid", 'initId' => 'it.godown_id', 'initLabel' => 'it.godown_label', 'dataLine' => ':data-item="idx"'])
                            </td>
                            <td class="zb-vt-right">
                                <input type="text" inputmode="decimal" class="form-control zb-field zb-v-amt"
                                       data-zb-field :data-item="idx" data-col="qty" data-zb-label="Quantity"
                                       x-model="it.qty" placeholder="0">
                            </td>
                            <td class="zb-vt-right">
                                <input type="text" inputmode="decimal" class="form-control zb-field zb-v-amt"
                                       data-zb-field :data-item="idx" data-col="rate" data-zb-label="Rate"
                                       x-model="it.rate" placeholder="0.00">
                            </td>
                            <td class="zb-vt-right zb-item-amt">
                                <span x-text="fmt(itemAmount(it))"></span>
                                <span class="zb-vt-muted" x-show="itemUnitSymbol(it)" x-text="'/' + itemUnitSymbol(it)"></span>
                            </td>
                            <td class="zb-vt-x">
                                <button type="button" class="zb-vt-remove" title="Remove item (Alt+R)"
                                        @click="removeItem(idx)" tabindex="-1">×</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr class="zb-vtotals">
                        <td class="zb-vt-right" colspan="4" x-text="isOrderType ? 'Order Value' : 'Total Value'"></td>
                        <td class="zb-vt-right" x-text="fmt(itemSubtotal)"></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            <p class="zb-invoice-note">
                <template x-if="isOrderType">
                    <span>This is a <strong>commitment only</strong> — it records no stock and no accounting entry.
                        Deliver against it with a <span class="zb-kbd" x-text="$store.zb.hint(type === 'sales_order' ? 'alt+f8' : 'alt+f9')"></span>
                        <span x-text="type === 'sales_order' ? 'Delivery Note' : 'Receipt Note'"></span>.</span>
                </template>
                <template x-if="isStockWorkflowType">
                    <span>This <strong>moves stock</strong> at the server-computed weighted-average cost, but posts
                        <strong>no accounting entry</strong>. The bill (Sales/Purchase invoice) comes separately.</span>
                </template>
            </p>
        </div>

        {{-- ============ DOUBLE-ENTRY (as-voucher) mode ============ --}}
        <table class="zb-vtable" x-show="showDouble" x-cloak>
            <thead>
                <tr>
                    <th style="width:4.5rem">Dr/Cr</th>
                    <th>Particulars (ledger)</th>
                    <th style="width:11rem" class="zb-vt-right">Debit</th>
                    <th style="width:11rem" class="zb-vt-right">Credit</th>
                    <th style="width:2rem"></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(line, idx) in lines" :key="line.uid">
                    <tr class="zb-vrow" :class="{ 'is-active': idx === lineActive }">
                        <td>
                            <select class="form-select zb-field zb-v-drcr" :data-line="idx" data-col="drcr"
                                    data-zb-field data-zb-label="Dr/Cr" x-model="line.dr_cr">
                                <option value="Dr">Dr</option>
                                <option value="Cr">Cr</option>
                            </select>
                        </td>
                        <td>
                            @include('partials.voucher-ledger-combo', ['comboId' => "'vline-' + line.uid", 'initId' => 'line.ledger_id', 'initLabel' => 'line.ledger_label', 'col' => 'ledger', 'dataLine' => ':data-line="idx"'])
                            <span class="zb-line-bal" x-show="line.ledger_id" x-cloak>Bal: <span x-text="balFor(line.ledger_id)"></span></span>
                            {{-- Phase 12B — DERIVED inter-company marker (the ledger's link decides; not editable). --}}
                            <span class="zb-ic-badge" x-show="lineInterCompanyId(line)" x-cloak
                                  x-text="'Inter-Company → ' + lineInterCompanyName(line)"></span>
                            {{-- Phase 11 — foreign amount + rate on a foreign-currency ledger line. --}}
                            <div class="zb-fx-line" x-show="lineIsForeign(line)" x-cloak>
                                <span class="zb-fx-code" x-text="lineCurrencyCode(line)"></span>
                                <input type="number" step="0.0001" class="form-control zb-field zb-fx-inp"
                                       :data-line="idx" data-col="foreign" data-zb-label="Foreign amount"
                                       x-model="line.foreign_amount" placeholder="foreign amt" @input="ensureLineRate(line)">
                                <span class="zb-fx-x">×</span>
                                <input type="number" step="0.000001" class="form-control zb-field zb-fx-inp"
                                       :data-line="idx" data-col="rate" data-zb-label="Rate"
                                       x-model="line.exchange_rate" placeholder="rate">
                                <span class="zb-fx-eq">= <span x-text="baseSymbol"></span><span x-text="lineInrLabel(line)"></span></span>
                            </div>
                        </td>
                        <td class="zb-vt-right">
                            <input type="text" inputmode="decimal" class="form-control zb-field zb-v-amt"
                                   data-zb-field x-show="line.dr_cr === 'Dr' && !lineIsForeign(line)" :data-line="idx" data-col="amount" data-zb-label="Debit amount"
                                   x-model="line.amount" placeholder="0.00">
                            <span class="zb-fx-inr" x-show="line.dr_cr === 'Dr' && lineIsForeign(line)" x-cloak x-text="lineInrLabel(line)"></span>
                            <span class="zb-vt-muted" x-show="line.dr_cr !== 'Dr'">—</span>
                        </td>
                        <td class="zb-vt-right">
                            <input type="text" inputmode="decimal" class="form-control zb-field zb-v-amt"
                                   data-zb-field x-show="line.dr_cr === 'Cr' && !lineIsForeign(line)" :data-line="idx" data-col="amount" data-zb-label="Credit amount"
                                   x-model="line.amount" placeholder="0.00">
                            <span class="zb-fx-inr" x-show="line.dr_cr === 'Cr' && lineIsForeign(line)" x-cloak x-text="lineInrLabel(line)"></span>
                            <span class="zb-vt-muted" x-show="line.dr_cr !== 'Cr'">—</span>
                        </td>
                        <td class="zb-vt-x">
                            <button type="button" class="zb-vt-remove" title="Remove line (Alt+R)"
                                    @click="removeLine(idx)" tabindex="-1">×</button>
                        </td>
                    </tr>
                </template>
            </tbody>
            <tfoot>
                <tr class="zb-vtotals">
                    <td></td>
                    <td class="zb-vt-right">Totals</td>
                    <td class="zb-vt-right" x-text="fmt(totalDr)"></td>
                    <td class="zb-vt-right" x-text="fmt(totalCr)"></td>
                    <td></td>
                </tr>
                <tr class="zb-vdiff" :class="{ 'is-balanced': balanced, 'is-off': difference !== 0 }">
                    <td></td>
                    <td class="zb-vt-right">
                        <span x-show="difference === 0 && totalDr > 0">Balanced</span>
                        <span x-show="difference !== 0" x-text="(difference > 0 ? 'Credit short by ' : 'Debit short by ') + fmt(Math.abs(difference))"></span>
                        <span x-show="difference === 0 && totalDr === 0">Difference</span>
                    </td>
                    <td class="zb-vt-right" colspan="2" x-text="fmt(Math.abs(difference))"></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        {{-- ============ SINGLE-ENTRY mode (F12) — Payment/Receipt/Contra ============ --}}
        <div class="zb-single" x-show="single" x-cloak>
            <div class="zb-form-row zb-single-acct">
                <label x-text="accountLabel()"></label>
                <div style="flex:1">
                    @include('partials.voucher-ledger-combo', ['comboId' => "'vacct'", 'initId' => 'accountLedgerId', 'initLabel' => 'accountLedgerLabel', 'col' => 'account', 'dataLine' => ''])
                    <span class="zb-line-bal" x-show="accountLedgerId" x-cloak>Current: <span x-text="balFor(accountLedgerId)"></span></span>
                </div>
            </div>

            <table class="zb-vtable">
                <thead>
                    <tr>
                        <th>Particulars (ledger)</th>
                        <th style="width:12rem" class="zb-vt-right">Amount</th>
                        <th style="width:2rem"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(line, idx) in lines" :key="line.uid">
                        <tr class="zb-vrow">
                            <td>
                                @include('partials.voucher-ledger-combo', ['comboId' => "'vline-' + line.uid", 'initId' => 'line.ledger_id', 'initLabel' => 'line.ledger_label', 'col' => 'ledger', 'dataLine' => ':data-line="idx"'])
                                <span class="zb-line-bal" x-show="line.ledger_id" x-cloak>Bal: <span x-text="balFor(line.ledger_id)"></span></span>
                            </td>
                            <td class="zb-vt-right">
                                <input type="text" inputmode="decimal" class="form-control zb-field zb-v-amt"
                                       data-zb-field :data-line="idx" data-col="amount" data-zb-label="Amount"
                                       x-model="line.amount" placeholder="0.00">
                            </td>
                            <td class="zb-vt-x">
                                <button type="button" class="zb-vt-remove" title="Remove line (Alt+R)"
                                        @click="removeLine(idx)" tabindex="-1">×</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr class="zb-vtotals">
                        <td class="zb-vt-right">Total</td>
                        <td class="zb-vt-right" x-text="fmt(singleTotal)"></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            <p class="zb-single-hint" x-text="'Posts as double-entry: ' + accountSide() + ' ' + (accountLedgerLabel || 'account') + '  •  ' + partSide() + ' the particulars above'"></p>
        </div>

        {{-- Phase 11 — forex rate-override banner: a foreign line at a rate that differs
             from the recorded rate needs an explicit confirmation + reason. --}}
        <div class="zb-fx-override" x-show="hasForeignLine && forexRateWarning" x-cloak>
            <label class="zb-fx-override-check">
                <input type="checkbox" x-model="forexRateOverride">
                <span>A rate differs from the recorded rate — post at this contract rate</span>
            </label>
            <input type="text" class="form-control zb-field" x-show="forexRateOverride" x-cloak
                   x-model="forexRateReason" placeholder="Reason (e.g. forward contract at this rate)" maxlength="191">
        </div>

        {{-- ============ TDS deduction (Phase 10A) — Payment vouchers ============
             Sits below whichever entry layout a Payment uses (double-entry or single),
             because the deduction is derived from the DEBITS, not from the layout. --}}
        @include('partials.tds-deduct')

        {{-- ============ TDS challan (Phase 10B) — a remittance debiting TDS Payable ============ --}}
        @include('partials.tds-challan')

        {{-- ============ STOCK JOURNAL (Phase 6C) — transfer / consumption ============ --}}
        <div class="zb-single zb-stockv" x-show="showStockJournal" x-cloak>
            <div class="zb-form-row">
                <label>Type of movement</label>
                <select class="form-select zb-field" style="max-width:22rem" data-zb-field data-col="mvmode" data-zb-label="Movement type" x-model="mv.mode">
                    <option value="transfer">Transfer — between godowns</option>
                    <option value="consumption">Consumption / Issue — out of stock</option>
                </select>
            </div>
            <div class="zb-form-row">
                <label>Stock Item</label>
                <div style="flex:1">
                    @include('partials.voucher-item-combo', ['comboId' => "'mvitem'", 'initId' => 'mv.item_id', 'initLabel' => 'mv.item_label', 'col' => 'mvitem', 'dataLine' => ''])
                </div>
            </div>
            <template x-if="sjIsTransfer">
                <div>
                    <div class="zb-form-row">
                        <label>From Godown (source)</label>
                        <div style="flex:1">
                            @include('partials.voucher-godown-combo', ['comboId' => "'mvfrom'", 'initId' => 'mv.from_godown_id', 'initLabel' => 'mv.from_godown_label', 'col' => 'mvfrom', 'dataLine' => ''])
                        </div>
                    </div>
                    <div class="zb-form-row">
                        <label>To Godown (destination)</label>
                        <div style="flex:1">
                            @include('partials.voucher-godown-combo', ['comboId' => "'mvto'", 'initId' => 'mv.to_godown_id', 'initLabel' => 'mv.to_godown_label', 'col' => 'mvto', 'dataLine' => ''])
                        </div>
                    </div>
                </div>
            </template>
            <template x-if="!sjIsTransfer">
                <div class="zb-form-row">
                    <label>Godown</label>
                    <div style="flex:1">
                        @include('partials.voucher-godown-combo', ['comboId' => "'mvgod'", 'initId' => 'mv.godown_id', 'initLabel' => 'mv.godown_label', 'col' => 'mvgod', 'dataLine' => ''])
                    </div>
                </div>
            </template>
            <div class="zb-form-row">
                <label>Quantity</label>
                <input type="text" inputmode="decimal" class="form-control zb-field" style="max-width:12rem"
                       data-zb-field data-col="mvqty" data-zb-label="Quantity" x-model="mv.qty" placeholder="0">
            </div>
            <p class="zb-single-hint"
               x-text="sjIsTransfer ? 'Moves stock at its weighted-average cost — the item total and the average are unchanged; only the godown location moves. No ledger entry.' : 'Issues stock out at its weighted-average cost — a pure quantity/value reduction. No ledger entry.'"></p>
        </div>

        {{-- ============ PHYSICAL STOCK (Phase 6C) — stock-take reconciliation ============ --}}
        <div class="zb-single zb-stockv" x-show="showPhysicalStock" x-cloak>
            <div class="zb-form-row">
                <label>Stock Item</label>
                <div style="flex:1">
                    @include('partials.voucher-item-combo', ['comboId' => "'mvitem'", 'initId' => 'mv.item_id', 'initLabel' => 'mv.item_label', 'col' => 'mvitem', 'dataLine' => ''])
                </div>
            </div>
            <div class="zb-form-row">
                <label>Godown</label>
                <div style="flex:1">
                    @include('partials.voucher-godown-combo', ['comboId' => "'mvgod'", 'initId' => 'mv.godown_id', 'initLabel' => 'mv.godown_label', 'col' => 'mvgod', 'dataLine' => ''])
                </div>
            </div>
            <div class="zb-form-row">
                <label>Book Quantity (as of date)</label>
                <span class="zb-ps-book" x-text="mv.book_qty === null ? '— (choose an item)' : mv.book_qty"></span>
            </div>
            <div class="zb-form-row">
                <label>Counted Quantity</label>
                <input type="text" inputmode="decimal" class="form-control zb-field" style="max-width:12rem"
                       data-zb-field data-col="mvcounted" data-zb-label="Counted quantity" x-model="mv.counted_qty" placeholder="0">
            </div>
            <p class="zb-single-hint zb-ps-variance" x-show="physVariance !== null" x-cloak
               :class="{ 'is-off': physVariance !== 0 }" x-text="physVarianceLabel()"></p>
            <p class="zb-invoice-note">
                Adjusts the godown to the counted quantity, valued at the item's weighted-average rate
                (a stock-take correction never disturbs the average). No ledger entry.
            </p>
        </div>

        <div class="zb-form-row zb-voucher-narr">
            <label for="v-narration">Narration</label>
            <textarea id="v-narration" class="form-control zb-field" data-col="narration"
                      data-zb-label="Narration" x-model="narration" rows="2"
                      placeholder="Narration (Enter to accept)"></textarea>
        </div>

        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">Enter</span> next · <span class="zb-kbd">Alt+I</span> add line ·
            <span class="zb-kbd">Alt+R</span> remove line · <span class="zb-kbd">Alt+C</span> create ledger ·
            <span class="zb-kbd">F4</span>–<span class="zb-kbd">F9</span> switch type ·
            <span x-show="isInvoiceType"><span class="zb-kbd">Ctrl+V</span> invoice/voucher · </span>
            <span class="zb-kbd">Alt+P</span> print · <span class="zb-kbd">Ctrl+A</span> accept
        </p>
    </form>

    {{-- inline quick-create sub-screens --}}
    @include('partials.quick-ledger')
    @include('partials.quick-group')
    @include('partials.quick-stock-item')

    {{-- bill-wise allocation sub-screen (Phase 5C) --}}
    @include('partials.bill-alloc')

    {{-- cost-centre allocation sub-screen (Phase 5D) --}}
    @include('partials.cost-alloc')

    {{-- cancel confirm (alter) --}}
    <div class="zb-modal-backdrop" x-show="confirmingCancel" x-cloak style="z-index:1290">
        <div class="zb-modal">
            <div class="zb-modal-head">Cancel Voucher</div>
            <div class="zb-modal-body">Cancel this voucher and remove its postings? This cannot be undone.</div>
            <div class="zb-modal-foot">
                <button type="button" class="btn btn-sm" @click="$store.zb.escape()">Esc — No</button>
                <button type="button" class="btn btn-sm zb-btn-primary" @click="doCancel()">Enter — Yes</button>
            </div>
        </div>
    </div>
</div>
