{{-- Bill-wise allocation sub-screen (Phase 5C). Opens over a bill-wise ledger
     line/party at accept; the line amount must be fully allocated across bill
     references. Lives inside the voucherScreen x-data scope. 0 network. --}}
<div class="zb-subscreen zb-sub-1" x-show="showBillAlloc" x-cloak style="z-index:1284">
    <div class="zb-subscreen-card zb-bill-card">
        <div class="zb-subscreen-head">
            <span>Bill-wise Details — <strong x-text="billTarget?.label"></strong>
                <span class="zb-bill-side" x-text="billTarget?.side"></span></span>
            <span class="zb-kbd">Ctrl+A · Esc</span>
        </div>

        <form class="zb-subscreen-body" data-zb-form="bill-alloc"
              x-on:zb:commit.prevent="billAccept()" @submit.prevent>

            <table class="zb-vtable zb-bill-table">
                <thead>
                    <tr>
                        <th style="width:8.5rem">Method of Adj</th>
                        <th>Ref. Name</th>
                        <th style="width:9rem">Due Date</th>
                        <th style="width:9rem" class="zb-vt-right">Amount</th>
                        <th style="width:2rem"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(row, i) in billRows" :key="i">
                        <tr class="zb-vrow">
                            <td>
                                <select class="form-select zb-field" data-zb-field data-zb-label="Method"
                                        :id="'bill-row-' + i + '-type'"
                                        x-model="row.ref_type" @change="onBillRefTypeChange(i)">
                                    <option value="new">New Ref</option>
                                    <option value="against">Against Ref</option>
                                    <option value="advance">Advance</option>
                                    <option value="onaccount">On Account</option>
                                </select>
                            </td>
                            <td>
                                {{-- Against Ref → pick from the party's open bills (0 network) --}}
                                <select x-show="row.ref_type === 'against'" class="form-select zb-field"
                                        data-zb-field data-zb-label="Against bill"
                                        x-model="row.ref_name" @change="onBillAgainstPick(i)">
                                    <option value="">— pick open bill —</option>
                                    <template x-for="b in againstOptions()" :key="b.ref_name">
                                        <option :value="b.ref_name"
                                                x-text="b.ref_name + '  (' + b.side + ' ' + fmt(b.pending) + ' pending)'"></option>
                                    </template>
                                </select>
                                {{-- otherwise a free-text ref name --}}
                                <input x-show="row.ref_type !== 'against'" type="text" class="form-control zb-field"
                                       data-zb-field data-zb-label="Ref name" x-model="row.ref_name"
                                       autocomplete="off" placeholder="e.g. bill / invoice no">
                            </td>
                            <td>
                                <input type="date" class="form-control zb-field" data-zb-noselect
                                       :disabled="row.ref_type !== 'new' && row.ref_type !== 'advance'"
                                       x-model="row.due_date" data-zb-label="Due date">
                            </td>
                            <td class="zb-vt-right">
                                <input type="text" inputmode="decimal" class="form-control zb-field zb-v-amt"
                                       data-zb-field data-zb-label="Amount" x-model="row.amount" placeholder="0.00">
                            </td>
                            <td class="zb-vt-x">
                                <button type="button" class="zb-vt-remove" title="Remove ref (Alt+R)"
                                        @click="billRows.length > 1 && billRows.splice(i, 1)" tabindex="-1">×</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr class="zb-vtotals">
                        <td colspan="3" class="zb-vt-right">Line amount</td>
                        <td class="zb-vt-right" x-text="fmt(billTargetAmount)"></td>
                        <td></td>
                    </tr>
                    <tr class="zb-bill-remain" :class="{ 'is-ok': Math.abs(billRemaining) < 0.005, 'is-off': Math.abs(billRemaining) >= 0.005 }">
                        <td colspan="3" class="zb-vt-right">
                            <span x-show="Math.abs(billRemaining) < 0.005">Fully allocated</span>
                            <span x-show="Math.abs(billRemaining) >= 0.005">Remaining to allocate</span>
                        </td>
                        <td class="zb-vt-right" x-text="fmt(billRemaining)"></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            <p class="text-muted zb-ws-hint">
                <span class="zb-kbd">Alt+I</span> add ref · <span class="zb-kbd">Alt+R</span> remove ·
                <span class="zb-kbd">Ctrl+A</span> accept allocation · <span class="zb-kbd">Esc</span> cancel
            </p>
        </form>
    </div>
</div>
