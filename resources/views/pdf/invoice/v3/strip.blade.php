{{-- The strip: the client, the period, the dates, and the contract in a row of its own --}}
<table class="layout strip">
    <tr class="row-strip">
        <td style="width: 50%">
            <div class="label">{{ $t('bill_to') }}</div>
            <div class="party-name party-name-lg">{{ $doc->client['name'] }}</div>
            @foreach ($doc->client['addressLines'] as $line)
                <div class="party-details">{{ $line }}</div>
            @endforeach
            @if ($doc->client['vatNumber'])
                <div class="party-details">{{ $t('vat') }}: {{ $doc->client['vatNumber'] }}</div>
            @endif
            @if ($doc->client['taxId'])
                <div class="party-details">{{ $t('client_tax_id') }}: {{ $doc->client['taxId'] }}</div>
            @endif
        </td>
        <td class="col-next" style="width: 30%">
            <div class="label">{{ $t('period') }}</div>
            <div class="period-dates"><span class="nw">{{ $doc->periodStart }} –</span><br><span class="nw">{{ $doc->periodEnd }}</span></div>
        </td>
        <td class="right col-next" style="width: 20%">
            <div class="label">{{ $t('issued') }}</div>
            <div class="strip-value mono">{{ $doc->issuedAt }}</div>
            @if ($doc->dueAt)
                <div class="label gap">{{ $t('due') }}</div>
                <div class="strip-value mono">{{ $doc->dueAt }}</div>
            @endif
        </td>
    </tr>
    @if ($doc->showContract)
        <tr class="row-contract">
            <td colspan="3">
                <div class="label">{{ $t('contract') }}</div>
                <div><span class="party-name">{{ $doc->contractTitle }}</span> <span class="party-details">{{ $t('contract_dated', ['date' => $doc->contractDate]) }}</span></div>
            </td>
        </tr>
    @endif
</table>
