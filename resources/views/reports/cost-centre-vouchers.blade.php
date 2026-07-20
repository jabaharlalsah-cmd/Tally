@extends('layouts.app')
@section('title', 'Cost Centre Vouchers — ZeroBook')
@section('region', $region)

@section('content')
    <div class="zb-daybook"
         x-data="ledgerVouchers({
            title: 'Cost Centre — ' + @js($centreName),
            alterUrl: @js(route('vouchers.alter', ['voucher' => '__id__'])),
            backUrl: @js(url()->previous())
         })">

        <div class="zb-panel">
            <div class="zb-panel-title">
                Cost Centre Vouchers · <span class="zb-r-ledgername">{{ $centreName }}</span>
                <span class="text-muted" style="font-size:.72rem;font-weight:400"> — {{ $fromLabel }} to {{ $toLabel }}</span>
            </div>

            <ul id="lv-list" tabindex="-1" class="zb-list zb-daybook-list">
                @forelse ($rows as $i => $row)
                    <li data-voucher-row data-voucher-id="{{ $row['voucher_id'] }}"
                        class="zb-list-item zb-daybook-item" :class="{ 'is-active': listActive === {{ $i }} }"
                        @click="listActive = {{ $i }}; drill()" @mousemove="listActive = {{ $i }}">
                        <span class="zb-db-date">{{ $row['date'] }}</span>
                        <span class="zb-db-type zb-db-{{ $row['type_label'] === 'Sales' ? 'sales' : ($row['type_label'] === 'Receipt' ? 'receipt' : ($row['type_label'] === 'Purchase' ? 'purchase' : 'payment')) }}">{{ $row['type_label'] }}</span>
                        <span class="zb-db-no">{{ $row['display_number'] }}</span>
                        <span class="zb-db-particulars">{{ $row['ledger'] }}</span>
                        <span class="zb-db-amount">{{ $row['side'] }} {{ $row['amount'] }}</span>
                    </li>
                @empty
                    <li class="zb-list-empty">No vouchers for this cost centre in the period.</li>
                @endforelse
            </ul>

            <p class="text-muted zb-ws-hint">
                <span class="zb-kbd">↑</span><span class="zb-kbd">↓</span> move · <span class="zb-kbd">Enter</span> open voucher ·
                <span class="zb-kbd">Esc</span> back
            </p>
        </div>
    </div>
@endsection
