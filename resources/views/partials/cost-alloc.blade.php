{{-- Cost-centre allocation sub-screen (Phase 5D). Opens over a cost-applicable
     ledger line at accept; the line amount must be fully allocated across cost
     centres. Analytical only — it never changes the accounting. 0 network.
     Lives inside the voucherScreen x-data scope. --}}
<div class="zb-subscreen zb-sub-1" x-show="showCostAlloc" x-cloak style="z-index:1286">
    <div class="zb-subscreen-card zb-cost-card">
        <div class="zb-subscreen-head">
            <span>Cost Allocation — <strong x-text="costTarget?.label"></strong>
                <span class="zb-bill-side" x-text="costTarget?.side"></span></span>
            <span class="zb-kbd">Ctrl+A · Esc</span>
        </div>

        <form class="zb-subscreen-body" data-zb-form="cost-alloc"
              x-on:zb:commit.prevent="costAccept()" @submit.prevent>

            <table class="zb-vtable zb-cost-table">
                <thead>
                    <tr>
                        <th>Cost Centre</th>
                        <th style="width:11rem" class="zb-vt-right">Amount</th>
                        <th style="width:2rem"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(row, i) in costRows" :key="row._uid">
                        <tr class="zb-vrow" :id="'cost-row-' + row._uid">
                            <td>
                                <div class="zb-combo"
                                     x-data="zbSelect({ id: 'costrow-' + row._uid, source: 'costCentres', sink: 'event', createType: null, initialId: row.cost_centre_id, initialLabel: row.cost_centre_label, label: 'Cost Centre', placeholder: 'Cost centre' })"
                                     @click.outside="close()">
                                    <input type="text" class="form-control zb-field zb-combo-input" x-ref="input"
                                           data-zb-field data-zb-combo-input data-zb-label="Cost centre"
                                           :value="open ? query : selLabel"
                                           @focus="onFocus()" x-on:zb-open="openList()"
                                           @input="query = $event.target.value; onInput()"
                                           autocomplete="off" spellcheck="false" placeholder="Cost centre">
                                    <div class="zb-combo-panel" x-show="open" x-ref="panel" x-cloak>
                                        <template x-for="(item, k) in filtered" :key="item.id">
                                            <div class="zb-combo-item" :class="{ 'is-active': k === active }"
                                                 @mousedown.prevent="pick(item)" @mousemove="active = k">
                                                <span class="zb-combo-item-name" x-text="item.name"></span>
                                                <span class="zb-combo-item-sub" x-text="item.path || ''"></span>
                                            </div>
                                        </template>
                                        <div class="zb-combo-empty" x-show="filtered.length === 0">No cost centre — create one in Masters</div>
                                    </div>
                                </div>
                            </td>
                            <td class="zb-vt-right">
                                <input type="text" inputmode="decimal" class="form-control zb-field zb-v-amt"
                                       data-zb-field data-zb-label="Amount" x-model="row.amount" placeholder="0.00">
                            </td>
                            <td class="zb-vt-x">
                                <button type="button" class="zb-vt-remove" title="Remove centre (Alt+R)"
                                        @click="costRows.length > 1 && costRows.splice(i, 1)" tabindex="-1">×</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr class="zb-vtotals">
                        <td class="zb-vt-right">Line amount</td>
                        <td class="zb-vt-right" x-text="fmt(costTargetAmount)"></td>
                        <td></td>
                    </tr>
                    <tr class="zb-bill-remain" :class="{ 'is-ok': Math.abs(costRemaining) < 0.005, 'is-off': Math.abs(costRemaining) >= 0.005 }">
                        <td class="zb-vt-right">
                            <span x-show="Math.abs(costRemaining) < 0.005">Fully allocated</span>
                            <span x-show="Math.abs(costRemaining) >= 0.005">Remaining to allocate</span>
                        </td>
                        <td class="zb-vt-right" x-text="fmt(costRemaining)"></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            <p class="zb-cost-note">Analytical tag only — cost allocation does not change the accounting (the Trial Balance is unaffected).</p>
            <p class="text-muted zb-ws-hint">
                <span class="zb-kbd">Alt+I</span> add centre · <span class="zb-kbd">Alt+R</span> remove ·
                <span class="zb-kbd">Ctrl+A</span> accept allocation · <span class="zb-kbd">Esc</span> cancel
            </p>
        </form>
    </div>
</div>
