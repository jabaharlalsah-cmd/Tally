{{-- One side of a two-column report (Balance Sheet / P&L). --}}
<div class="zb-rcol">
    <div class="zb-rcol-head">{{ $heading }}</div>
    <table class="zb-rtable">
        <tbody>
            @foreach ($colRows as $row)
                <tr data-key="{{ $row['key'] }}" class="zb-rrow zb-r-{{ $row['kind'] }}"
                    x-show="isVisible('{{ $row['key'] }}')"
                    :class="{ 'is-active': activeKey === '{{ $row['key'] }}' }"
                    @click="activeKey='{{ $row['key'] }}'; enter()"
                    @mousemove="activeKey='{{ $row['key'] }}'">
                    <td style="padding-left: {{ 0.5 + $row['depth'] * 1.25 }}rem">
                        @if ($row['kind'] === 'group' && $row['collapsible'])
                            <span class="zb-r-caret" x-text="(detailed || expanded['{{ $row['key'] }}']) ? '▾' : '▸'"></span>
                        @endif
                        {{ $row['label'] }}
                    </td>
                    <td class="zb-vt-right">{{ $row['amount'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
