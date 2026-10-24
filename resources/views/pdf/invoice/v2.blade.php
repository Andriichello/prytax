{{--
    The PDF of an invoice (template v2, invoice.md), rendered from a snapshot of version 2 only. $doc: the
    InvoiceDocument, which InvoicePdfRenderer builds of it, in the snapshot's language. dompdf has neither flexbox nor
    grid, so every row of columns is a table. It ignores <col> widths, and a fixed layout drops a cell's width in pt,
    so the widths are on the first row's cells: percentages, or the ones the renderer measured (content widths, which
    the cells' padding adds to).
--}}
@php
    $t = fn (string $key, array $replace = []) => trans("invoice.labels.$key", $replace, $doc->locale);
@endphp
<!doctype html>
<html lang="{{ $doc->locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $t('title') }} {{ $doc->number }}</title>
    <style>
        @font-face {
            font-family: 'PlexMonoSemi';
            font-style: normal;
            font-weight: normal;
            src: url('{{ resource_path('fonts/IBMPlexMono-SemiBold.ttf') }}') format('truetype');
        }

        @page { margin: 30pt; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 7.5pt; line-height: 11.5pt; color: #17191F; }
        table { border-collapse: collapse; }
        .mono { font-family: 'DejaVu Sans Mono', monospace; }
        .sec { color: #4F5461; }

        table.layout { width: 100%; }
        table.layout td { padding: 0; vertical-align: top; }
        table.layout td.right { text-align: right; }
        table.layout tr.row-parties td { padding-top: 16pt; }
        table.layout tr.row-contract td { padding-top: 12pt; }

        .doc-title { font-size: 16.5pt; line-height: 20pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em; }
        .doc-number-line { margin-top: 2pt; line-height: 15pt; white-space: nowrap; }
        .doc-number-label { font-family: 'DejaVu Sans Mono', monospace; font-size: 7.5pt; color: #4F5461; }
        .doc-number { font-family: 'PlexMonoSemi', monospace; font-size: 11.5pt; color: #17191F; }
        .meta { color: #4F5461; white-space: nowrap; }

        .label { font-size: 6pt; line-height: 8pt; font-weight: bold; color: #5A5F6B; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 4.3pt; }
        .label.role { margin: 1pt 0 3pt; }

        .party-name { font-size: 9.5pt; line-height: 13pt; font-weight: bold; color: #17191F; }
        .party-name-lg { font-size: 12pt; line-height: 15pt; font-weight: bold; color: #17191F; margin-bottom: 1pt; }
        .party-details { font-size: 7.5pt; line-height: 11.5pt; color: #5A5F6B; }
        .period-dates { font-size: 8.5pt; line-height: 13pt; color: #17191F; white-space: nowrap; }

        .items-wrap { margin-top: 22.5pt; }
        table.items { width: 100%; }
        table.items th { font-size: 6pt; line-height: 8pt; font-weight: bold; color: #5A5F6B; text-transform: uppercase; letter-spacing: 0.04em; text-align: left; vertical-align: top; padding: 0 3pt 6pt; border-bottom: 0.75pt solid #D5D3CB; }
        table.items td { padding: 5.25pt 3pt 5.3pt; vertical-align: top; border-bottom: 0.75pt solid #EEEDE8; }
        table.items .no { padding-left: 3pt; padding-right: 14pt; }
        table.items .desc { padding-left: 0; }
        table.items .num { padding-left: 32pt; text-align: right; }
        table.items td.num { white-space: nowrap; }

        table.totals { width: 45%; margin: 17.2pt 0 0 55%; }
        table.totals td { padding: 3pt; }
        table.totals td.right { text-align: right; white-space: nowrap; }
        table.totals tr.total td { padding: 8.5pt 3pt 0; font-size: 9.8pt; line-height: 12pt; font-weight: bold; border-top: 0.75pt solid #17191F; }

        .card { margin-top: 17pt; border: 0.75pt solid #E3E1DA; border-radius: 4pt; padding: 8.6pt 9.4pt 7pt; }
        .card .label { margin-bottom: 2pt; }
        table.card-cols { width: 100%; }
        table.card-cols td { padding: 0; vertical-align: top; }
        table.card-cols td.details { padding-right: 12pt; }
        table.card-cols td.purpose { padding-left: 12pt; border-left: 0.75pt solid #EEEDE8; }

        table.kv { width: 100%; }
        table.kv td { padding: 0; vertical-align: top; }
        table.kv td.k { white-space: nowrap; color: #5A5F6B; padding-right: 12pt; }
        table.kv td.v { color: #17191F; word-wrap: break-word; }

        .purpose-text { color: #17191F; word-wrap: break-word; }
        .card-section { margin-top: 9pt; padding-top: 9pt; border-top: 0.75pt solid #EEEDE8; }

        .footer-note { margin-top: 19.5pt; color: #4F5461; }
        .signature { margin-top: 19.5pt; }
    </style>
</head>
<body>
    <table class="layout">
        <tr class="row-head">
            <td style="width: 60%">
                <div class="party-name-lg">{{ $doc->profile['name'] }}</div>
                <div class="label role">{{ $t('entrepreneur') }}</div>
                @foreach ($doc->profile['addressLines'] as $line)
                    <div class="party-details">{{ $line }}</div>
                @endforeach
                <div class="party-details">{{ $doc->profile['taxIdLabel'] }}: {{ $doc->profile['taxId'] }}</div>
                @if ($doc->profile['email'])
                    <div class="party-details">{{ $doc->profile['email'] }}</div>
                @endif
                @if ($doc->profile['phone'])
                    <div class="party-details">{{ $doc->profile['phone'] }}</div>
                @endif
            </td>
            <td class="right" style="width: 40%">
                <div class="doc-title">{{ $t('title') }}</div>
                <div class="doc-number-line">
                    <span class="doc-number-label">{{ $t('number') }}</span>
                    <span class="doc-number">{{ $doc->number }}</span>
                </div>
                <div class="meta">{{ $t('issued') }}: <span class="mono">{{ $doc->issuedAt }}</span></div>
                @if ($doc->dueAt)
                    <div class="meta">{{ $t('due') }}: <span class="mono">{{ $doc->dueAt }}</span></div>
                @endif
            </td>
        </tr>
        <tr class="row-parties">
            <td>
                <div class="label">{{ $t('bill_to') }}</div>
                <div class="party-name-lg">{{ $doc->client['name'] }}</div>
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
            <td class="right">
                <div class="label">{{ $t('period') }}</div>
                <div class="period-dates">{{ $doc->periodStart }} – {{ $doc->periodEnd }}</div>
            </td>
        </tr>
        @if ($doc->showContract)
            <tr class="row-contract">
                <td colspan="2">
                    <div class="label">{{ $t('contract') }}</div>
                    <div>
                        <span class="party-name">{{ $doc->contractTitle }}</span>
                        <span class="party-details">{{ $t('contract_dated', ['date' => $doc->contractDate]) }}</span>
                    </div>
                </td>
            </tr>
        @endif
    </table>

    <div class="items-wrap">
        <table class="items">
            <thead>
                <tr>
                    <th class="no" style="width: {{ $doc->itemsColWidths['no'] }}pt">{{ $t('col_no') }}</th>
                    <th class="desc">{{ $t('col_desc') }}</th>
                    <th class="num" style="width: {{ $doc->itemsColWidths['qty'] }}pt">{{ $t('col_qty') }}</th>
                    <th class="num" style="width: {{ $doc->itemsColWidths['price'] }}pt">{{ $t('col_price', ['cur' => $doc->currency]) }}</th>
                    <th class="num" style="width: {{ $doc->itemsColWidths['amount'] }}pt">{{ $t('col_amount', ['cur' => $doc->currency]) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($doc->items as $item)
                    <tr>
                        <td class="no mono">{{ $item['position'] }}</td>
                        <td class="desc">
                            @if ($item['codeLine'] !== '')
                                <div class="sec">{{ $item['codeLine'] }}</div>
                            @endif
                            <div>{{ $item['description'] }}</div>
                        </td>
                        <td class="num mono">{{ $item['qty'] }}</td>
                        <td class="num mono">{{ $item['price'] }}</td>
                        <td class="num mono">{{ $item['amount'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            @foreach ($doc->adjustments as $row)
                <tr><td>{{ $row['label'] }}</td><td class="right mono">{{ $row['value'] }}</td></tr>
            @endforeach
            <tr class="total"><td>{{ $t('total') }}</td><td class="right mono">{{ $doc->total }}</td></tr>
        </table>
    </div>

    <div class="card">
        <table class="card-cols">
            <tr>
                <td class="details" style="width: 50%">
                    <div class="label">{{ $t('payment_details') }}</div>
                    <table class="kv">
                        @foreach ($doc->paymentDetails as $row)
                            <tr>
                                <td class="k" style="width: {{ $doc->kvKeyWidth }}pt">{{ $row['label'] }}</td>
                                <td class="v">@if ($row['mono'])<span class="mono">{{ $row['value'] }}</span>@else{{ $row['value'] }}@endif</td>
                            </tr>
                        @endforeach
                    </table>
                </td>
                <td class="purpose" style="width: 50%">
                    <div class="label">{{ $t('payment_purpose') }}</div>
                    <div class="purpose-text">{{ $doc->paymentPurpose }}</div>
                    @if ($doc->actNote !== null)
                        <div class="card-section">
                            <div class="label">{{ $t('act_of_acceptance') }}</div>
                            <div class="purpose-text">{{ $doc->actNote }}</div>
                        </div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    @if ($doc->footerNote)
        <div class="footer-note">{!! nl2br(e($doc->footerNote)) !!}</div>
    @endif

    @if ($doc->signature)
        <div class="signature">
            <img src="{{ $doc->signature }}" alt="" style="height: 36pt"><br>
            <span class="sec">{{ $doc->profile['name'] }}</span>
        </div>
    @endif
</body>
</html>
