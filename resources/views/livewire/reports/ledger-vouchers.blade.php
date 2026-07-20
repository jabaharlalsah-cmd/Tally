<div class="zb-daybook"
     x-data="ledgerVouchers({
        title: 'Ledger Vouchers — ' + @js($ledgerName),
        alterUrl: @js(route('vouchers.alter', ['voucher' => '__id__'])),
        backUrl: @js(url()->previous())
     })">

    <div class="zb-panel">
        <div class="zb-panel-title">
            Ledger Vouchers · <span class="zb-r-ledgername">{{ $ledgerName }}</span>
            <span class="text-muted" style="font-size:.72rem;font-weight:400"> — {{ $fromLabel }} to {{ $toLabel }}</span>
        </div>

        <div class="zb-lv-summary">
            <span>Opening: <strong>{{ $openingStr }} {{ $openingSide }}</strong></span>
            <span>Closing: <strong>{{ $closingStr }} {{ $closingSide }}</strong></span>
        </div>

        <ul id="lv-list" tabindex="-1" class="zb-list zb-daybook-list">
            @forelse ($rows as $i => $row)
                <li data-voucher-row data-voucher-id="{{ $row['voucher_id'] }}"
                    class="zb-list-item zb-daybook-item" :class="{ 'is-active': listActive === {{ $i }} }"
                    @click="listActive = {{ $i }}; drill()" @mousemove="listActive = {{ $i }}">
                    <span class="zb-db-date">{{ $row['date'] }}</span>
                    <span class="zb-db-type zb-db-{{ $row['type'] }}">{{ $row['type_label'] }}</span>
                    <span class="zb-db-no">{{ $row['display_number'] }}</span>
                    <span class="zb-db-particulars">{{ $row['particulars'] }}</span>
                    <span class="zb-db-amount">{{ $row['dr_cr'] }} {{ $row['amount_str'] }}</span>
                    <span class="zb-db-amount zb-lv-running">{{ $row['running_str'] }} {{ $row['running_side'] }}</span>
                </li>
            @empty
                <li class="zb-list-empty">No vouchers for this ledger in the period.</li>
            @endforelse
        </ul>

        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">↑</span><span class="zb-kbd">↓</span> move · <span class="zb-kbd">Enter</span> open voucher ·
            <span class="zb-kbd">Esc</span> back
        </p>
    </div>
</div>
