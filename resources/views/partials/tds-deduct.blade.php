{{-- TDS deduction panel (Phase 10A). Renders below the entry grid on a Payment
     voucher when TDS is switched on (F11). Engaging it turns the ordinary two-line
     Payment into three lines:

         Dr Expense (the taxable amount)  ·  Cr TDS Payable  ·  Cr Bank (the net)

     Everything shown here is computed client-side from a bootstrapped year-to-date
     cache — zero network while typing. TdsService recomputes the deduction on the
     server at accept and rejects the voucher if the posted TDS line disagrees.
     Lives inside the voucherScreen x-data scope. --}}
<div class="zb-tds" x-show="showTdsPanel" x-cloak>

    <div class="zb-tds-head">
        <label class="zb-tds-engage">
            <input type="checkbox" x-model="tdsOn" @change="updateContextLabel()">
            <span>Deduct TDS <span class="zb-kbd">Alt+T</span></span>
        </label>
        <span class="zb-tds-hint" x-show="!tdsOn" x-cloak>Tax deducted at source, withheld from the vendor and paid to the government.</span>
        <span class="zb-tds-badge" x-show="tdsEngaged && tdsDeductedPaise > 0" x-cloak
              x-text="'Withholding ' + fmt(tdsDeducted)"></span>
        <span class="zb-tds-badge is-nil" x-show="tdsEngaged && tdsDeductedPaise === 0" x-cloak>Below threshold — no TDS</span>
    </div>

    <div class="zb-tds-body" x-show="tdsOn" x-cloak>

        <div class="zb-form-row">
            <label for="v-tds-deductee">Deductee (paid to)</label>
            <div style="flex:1">
                <div class="zb-combo"
                     x-data="zbSelect({ id: 'vtdsdeductee', source: 'tdsDeductees', sink: 'event', createType: null, initialId: tdsDeducteeId, initialLabel: tdsDeducteeLabel, label: 'Deductee', placeholder: 'Vendor tagged as a TDS deductee' })"
                     @click.outside="close()">
                    <input type="text" id="v-tds-deductee" class="form-control zb-field zb-combo-input" x-ref="input"
                           data-zb-field data-zb-combo-input data-zb-label="Deductee"
                           :value="open ? query : selLabel"
                           @focus="onFocus()" x-on:zb-open="openList()"
                           @input="query = $event.target.value; onInput()"
                           autocomplete="off" spellcheck="false" placeholder="Vendor tagged as a TDS deductee">
                    <div class="zb-combo-panel" x-show="open" x-ref="panel" x-cloak>
                        <template x-for="(item, k) in filtered" :key="item.id">
                            <div class="zb-combo-item" :class="{ 'is-active': k === active }"
                                 @mousedown.prevent="pick(item)" @mousemove="active = k">
                                <span class="zb-combo-item-name" x-text="item.name"></span>
                                <span class="zb-combo-item-sub" x-text="item.path || ''"></span>
                            </div>
                        </template>
                        <div class="zb-combo-empty" x-show="filtered.length === 0">
                            No deductee — tag a party ledger with a PAN and a default section
                        </div>
                    </div>
                </div>
                <span class="zb-tds-206aa" x-show="tdsNoPan" x-cloak>
                    No PAN on record — Section 206AA applies, deducted at <span x-text="tdsRate + '%'"></span>.
                </span>
            </div>
        </div>

        <div class="zb-form-row">
            <label for="v-tds-section">Section</label>
            <div style="flex:1">
                <div class="zb-combo"
                     x-data="zbSelect({ id: 'vtdssection', source: 'tdsSections', sink: 'event', createType: null, initialId: tdsSectionId, initialLabel: tdsSectionLabel, label: 'TDS Section', placeholder: 'e.g. 393-194J — professional fees' })"
                     @click.outside="close()">
                    <input type="text" id="v-tds-section" class="form-control zb-field zb-combo-input" x-ref="input"
                           data-zb-field data-zb-combo-input data-zb-label="TDS section"
                           :value="open ? query : selLabel"
                           @focus="onFocus()" x-on:zb-open="openList()"
                           @input="query = $event.target.value; onInput()"
                           autocomplete="off" spellcheck="false" placeholder="e.g. 393-194J — professional fees">
                    <div class="zb-combo-panel" x-show="open" x-ref="panel" x-cloak>
                        <template x-for="(item, k) in filtered" :key="item.id">
                            <div class="zb-combo-item" :class="{ 'is-active': k === active }"
                                 @mousedown.prevent="pick(item)" @mousemove="active = k">
                                <span class="zb-combo-item-name" x-text="item.name"></span>
                                <span class="zb-combo-item-sub" x-text="item.path || ''"></span>
                            </div>
                        </template>
                        <div class="zb-combo-empty" x-show="filtered.length === 0">
                            No section in force this year — add one in Masters → TDS Sections
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- The live arithmetic. Every figure here is what the server will independently
             recompute; if the two ever disagree, the post is refused, not silently fixed. --}}
        <table class="zb-vtable zb-tds-table" x-show="tdsCanEngage" x-cloak>
            <tbody>
                <tr>
                    <td>Taxable base <span class="zb-tds-sub" x-show="tdsGstPaise > 0" x-cloak>(excludes <span x-text="fmt(tdsGstPaise / 100)"></span> GST — TDS falls on the pre-tax value)</span></td>
                    <td class="zb-vt-right" x-text="fmt(tdsBasePaise / 100)"></td>
                </tr>
                <tr>
                    <td>Rate <span class="zb-tds-sub" x-show="tdsNoPan" x-cloak>(Section 206AA — no PAN)</span></td>
                    <td class="zb-vt-right" x-text="tdsRate + '%'"></td>
                </tr>
                <tr class="zb-tds-deducted">
                    <td>TDS deducted</td>
                    <td class="zb-vt-right" x-text="fmt(tdsDeducted)"></td>
                </tr>
                <tr class="zb-vtotals">
                    <td>Net paid to <span x-text="tdsDeducteeLabel || 'the vendor'"></span></td>
                    <td class="zb-vt-right" x-text="fmt(tdsNetPaid)"></td>
                </tr>
            </tbody>
        </table>

        <p class="zb-tds-reason" x-show="tdsCanEngage" x-cloak x-text="tdsReason"></p>

        <p class="zb-tds-shape" x-show="tdsEngaged && tdsDeductedPaise > 0" x-cloak>
            Posts as three lines: <strong>Dr</strong> the expense <span x-text="fmt(tdsBasePaise / 100)"></span> ·
            <strong>Cr</strong> TDS Payable <span x-text="fmt(tdsDeducted)"></span> ·
            <strong>Cr</strong> bank <span x-text="fmt(tdsNetPaid)"></span>.
            The server recomputes the deduction and refuses the voucher if it disagrees.
        </p>
        <p class="zb-tds-shape" x-show="tdsEngaged && tdsDeductedPaise === 0 && tdsCanEngage" x-cloak>
            Nothing is withheld yet, but this payment still counts towards the threshold —
            the aggregate is carried forward, and the payment that crosses the line deducts on all of it.
        </p>
    </div>
</div>
