<div class="zb-daybook"
     x-data="dayBook({
        gatewayUrl: @js(route('gateway')),
        alterUrl: @js(route('vouchers.alter', ['voucher' => '__id__'])),
        printUrl: @js(route('vouchers.print', ['voucher' => '__id__']))
     })">

    <div class="zb-ws-flash is-ok" x-show="flash" x-cloak x-text="flash"></div>

    <div class="zb-panel">
        <div class="zb-panel-title">
            Day Book <span class="text-muted" style="font-size:.75rem;font-weight:400">· FY {{ $fyLabel }}</span>
        </div>

        <div class="zb-daybook-period">
            <label>Period <span class="zb-kbd">F2</span></label>
            <input type="date" id="daybook-from" class="form-control zb-field" data-zb-noselect wire:model.blur="from">
            <span>to</span>
            <input type="date" id="daybook-to" class="form-control zb-field" data-zb-noselect wire:model.blur="to">
        </div>

        <ul id="daybook-list" tabindex="-1" class="zb-list zb-daybook-list">
            @forelse ($voucherRows as $i => $row)
                <li data-voucher-row data-voucher-id="{{ $row['id'] }}" data-voucher-label="{{ $row['display_number'] }}"
                    class="zb-list-item zb-daybook-item" :class="{ 'is-active': listActive === {{ $i }} }"
                    @click="listActive = {{ $i }}; drill()" @mousemove="listActive = {{ $i }}">
                    <span class="zb-db-date">{{ $row['date_label'] }}</span>
                    <span class="zb-db-type zb-db-{{ $row['type'] }}">{{ $row['type_label'] }}</span>
                    <span class="zb-db-no">{{ $row['display_number'] }}</span>
                    <span class="zb-db-particulars">
                        @if ($row['is_stock'])
                            <span class="zb-db-line zb-db-stock">{{ $row['stock_summary'] }}</span>
                        @else
                            @foreach ($row['lines'] as $line)
                                <span class="zb-db-line">
                                    <span class="zb-db-drcr">{{ $line['dr_cr'] }}</span>
                                    {{ $line['ledger'] }}
                                </span>@if(!$loop->last)<span class="zb-db-sep">·</span>@endif
                            @endforeach
                        @endif
                    </span>
                    <span class="zb-db-amount">@if ($row['is_stock'])<span class="zb-db-stockmark">stock</span>@else{{ number_format($row['amount'], 2) }}@endif</span>
                </li>
            @empty
                <li class="zb-list-empty">No vouchers in this period. Press <span class="zb-kbd">F5</span> to make a Payment.</li>
            @endforelse
        </ul>

        <p class="text-muted zb-ws-hint">
            <span class="zb-kbd">↑</span><span class="zb-kbd">↓</span> move · <span class="zb-kbd">Enter</span> open/alter ·
            <span class="zb-kbd">Alt+P</span> print · <span class="zb-kbd">Alt+D</span> cancel · <span class="zb-kbd">F2</span> period · <span class="zb-kbd">Esc</span> back
        </p>
    </div>

    {{-- cancel confirm --}}
    <div class="zb-modal-backdrop" x-show="confirming" x-cloak style="z-index:1290">
        <div class="zb-modal">
            <div class="zb-modal-head">Cancel Voucher</div>
            <div class="zb-modal-body">
                Cancel “<strong x-text="confirming?.label"></strong>” and remove its postings?
            </div>
            <div class="zb-modal-foot">
                <button type="button" class="btn btn-sm" @click="$store.zb.escape()">Esc — No</button>
                <button type="button" class="btn btn-sm zb-btn-primary" @click="doCancel()">Enter — Yes</button>
            </div>
        </div>
    </div>
</div>
