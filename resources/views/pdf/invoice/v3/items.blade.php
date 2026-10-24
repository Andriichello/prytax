{{-- The items, every other one shaded, then the subtotal and the adjustment, when there is one, and the total --}}
<div class="items-wrap">
    <table class="items">
        <thead>
            <tr>
                <th class="no" style="width: {{ $doc->itemsColWidths['no'] }}pt">{{ $t('col_no') }}</th>
                <th class="desc">{{ $t('col_desc') }}</th>
                <th class="r" style="width: {{ $doc->itemsColWidths['qty'] }}pt">{{ $t('col_qty') }}</th>
                <th class="r" style="width: {{ $doc->itemsColWidths['price'] }}pt">{{ $t('col_price', ['cur' => $doc->currency]) }}</th>
                <th class="r col-last" style="width: {{ $doc->itemsColWidths['amount'] }}pt">{{ $t('col_amount', ['cur' => $doc->currency]) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doc->items as $item)
                <tr class="{{ $item['rowClass'] }}">
                    <td class="mono no">{{ $item['position'] }}</td>
                    <td class="desc">
                        @if ($item['codeLine'] !== '')
                            <div class="sec">{{ $item['codeLine'] }}</div>
                        @endif
                        <div>{{ $item['description'] }}</div>
                    </td>
                    <td class="r mono">{{ $item['qty'] }}</td>
                    <td class="r mono">{{ $item['price'] }}</td>
                    <td class="r mono col-last">{{ $item['amount'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($doc->subtotals)
        <table class="subtotals">
            @foreach ($doc->subtotals as $row)
                <tr><td>{{ $row['label'] }}</td><td class="mono">{{ $row['value'] }}</td></tr>
            @endforeach
        </table>
    @endif

    <table class="total"@if ($doc->subtotals) style="margin-top: 8pt"@endif>
        <tr><td>{{ $t('total') }}</td><td class="mono col-last" style="text-align: right; white-space: nowrap">{{ $doc->total }}</td></tr>
    </table>
</div>
