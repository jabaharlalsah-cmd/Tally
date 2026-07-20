{{-- TDS challan capture (Phase 10B). Appears on a Payment that DEBITS the TDS Payable
     ledger — a remittance of withheld tax to the government. Captures the real bank
     challan identity the Form 26Q return needs: BSR code, the 5-digit challan serial from
     the bank stamp, and the deposit date. The deposited amount is the Dr TDS Payable line
     itself (server-derived). Lives inside the voucherScreen x-data scope. --}}
<div class="zb-tds zb-challan" x-show="showChallanPanel" x-cloak>
    <div class="zb-tds-head">
        <span class="zb-tds-engage"><strong>TDS Challan</strong></span>
        <span class="zb-tds-hint">Remittance to the government — record the bank challan for the Form 26Q return.</span>
        <span class="zb-tds-badge" x-show="challanReady" x-cloak>✓ ready for 26Q</span>
    </div>
    <div class="zb-tds-body">
        <div class="zb-form-row">
            <label for="v-challan-bsr">BSR code</label>
            <input id="v-challan-bsr" class="form-control zb-field" data-zb-field data-zb-label="BSR code"
                   x-model="challanBsr" autocomplete="off" inputmode="numeric" maxlength="7"
                   placeholder="7-digit BSR code of the receiving bank branch">
        </div>
        <div class="zb-form-row">
            <label for="v-challan-no">Bank challan number</label>
            <input id="v-challan-no" class="form-control zb-field" data-zb-field data-zb-label="Challan number"
                   x-model="challanNumber" autocomplete="off" inputmode="numeric" maxlength="5"
                   placeholder="5-digit challan serial from the bank stamp">
        </div>
        <div class="zb-form-row">
            <label for="v-challan-date">Deposit date</label>
            <input id="v-challan-date" type="date" class="form-control zb-field" data-zb-field data-zb-label="Deposit date"
                   x-model="challanDate" :placeholder="date">
        </div>
        <p class="zb-tds-shape">
            The deposited amount is the <strong>Dr TDS Payable</strong> line above. This challan discharges the
            quarter’s deductions oldest-first, and appears as one Challan Detail record in the 26Q return.
        </p>
    </div>
</div>
