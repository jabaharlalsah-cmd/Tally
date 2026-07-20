@extends('layouts.app')
@section('title', 'Bill Vouchers — ZeroBook')
@section('region', $region)

@section('content')
    <div class="zb-daybook"
         x-data="ledgerVouchers({
            title: 'Bill Vouchers — ' + @js($refName),
            alterUrl: @js(route('vouchers.alter', ['voucher' => '__id__'])),
            backUrl: @js(url()->previous())
         })">

        <div class="zb-panel">
            <div class="zb-panel-title">
                Bill Vouchers · <span class="zb-r-ledgername">{{ $ledgerName }}</span>
                <span class="text-muted" style="font-size:.72rem;font-weight:400"> — Ref “{{ $refName }}”</span>
            </div>

            <ul id="lv-list" tabindex="-1" class="zb-list zb-daybook-list">
                @forelse ($rows as $i => $row)
                    <li data-voucher-row data-voucher-id="{{ $row['voucher_id'] }}"
                        class="zb-list-item zb-daybook-item" :class="{ 'is-active': listActive === {{ $i }} }"
                        @click="listActive = {{ $i }}; drill()" @mousemove="listActive = {{ $i }}">
                        <span class="zb-db-date">{{ $row['date'] }}</span>
                        <span class="zb-db-type zb-db-{{ $row['type_label'] === 'Sales' ? 'sales' : ($row['type_label'] === 'Receipt' ? 'receipt' : 'payment') }}">{{ $row['type_label'] }}</span>
                        <span class="zb-db-no">{{ $row['display_number'] }}</span>
                        <span class="zb-db-particulars">{{ $row['ref_type'] }}</span>
                        <span class="zb-db-amount">{{ $row['side'] }} {{ $row['amount'] }}</span>
                    </li>
                @empty
                    <li class="zb-list-empty">No vouchers behind this bill.</li>
                @endforelse
            </ul>

            <p class="text-muted zb-ws-hint">
                <span class="zb-kbd">↑</span><span class="zb-kbd">↓</span> move · <span class="zb-kbd">Enter</span> open voucher ·
                <span class="zb-kbd">Esc</span> back
            </p>
        </div>
    </div>
@endsection
