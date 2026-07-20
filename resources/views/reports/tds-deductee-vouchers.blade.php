@extends('layouts.app')
@section('title', 'TDS Deductions — ZeroBook')
@section('region', $region)

@section('content')
    <div class="zb-daybook"
         x-data="ledgerVouchers({
            title: @js($sectionCode . ' — ' . $deducteeName),
            alterUrl: @js(route('vouchers.alter', ['voucher' => '__id__'])),
            backUrl: @js(url()->previous())
         })">

        <div class="zb-panel">
            <div class="zb-panel-title">
                TDS Deductions · <span class="zb-r-ledgername">{{ $deducteeName }}</span>
                <span class="text-muted" style="font-size:.72rem;font-weight:400">
                    — under {{ $sectionCode }} ({{ $sectionLabel }}) · {{ $fromLabel }} to {{ $toLabel }}
                </span>
            </div>

            <ul id="lv-list" tabindex="-1" class="zb-list zb-daybook-list">
                @forelse ($rows as $i => $row)
                    <li data-voucher-row data-voucher-id="{{ $row['voucher_id'] }}"
                        class="zb-list-item zb-daybook-item zb-tds-drill-item" :class="{ 'is-active': listActive === {{ $i }} }"
                        @click="listActive = {{ $i }}; drill()" @mousemove="listActive = {{ $i }}">
                        <span class="zb-db-date">{{ $row['date'] }}</span>
                        <span class="zb-db-type zb-db-payment">Payment</span>
                        <span class="zb-db-no">{{ $row['display_number'] }}</span>
                        <span class="zb-db-particulars">
                            Paid {{ $row['payment'] }}
                            {{-- The base the deduction was COMPUTED on differs from the payment on the
                                 voucher that crosses a threshold — that difference IS the catch-up. --}}
                            @if ($row['base'] !== $row['payment'])
                                <span class="zb-tds-sub">· computed on {{ $row['base'] }}</span>
                            @endif
                            <span class="zb-tds-sub">· {{ $row['rate'] }}</span>
                        </span>
                        <span class="zb-db-amount">{{ $row['deducted'] }}</span>
                    </li>
                    @if ($row['reason'])
                        <li class="zb-tds-drill-reason">{{ $row['reason'] }}</li>
                    @endif
                @empty
                    <li class="zb-list-empty">No TDS deductions for this deductee and section in the period.</li>
                @endforelse
            </ul>

            <p class="text-muted zb-ws-hint">
                <span class="zb-kbd">↑</span><span class="zb-kbd">↓</span> move · <span class="zb-kbd">Enter</span> open voucher ·
                <span class="zb-kbd">Esc</span> back
            </p>
        </div>
    </div>
@endsection
