{{--
    The PDF of an invoice, rendered from its snapshot only (schema_version 1), never from live relations.
    $snapshot: the invoice's snapshot; $money: a MoneyFormatter of its money format; $signature: a data URI or null.
--}}
@php
    $invoice = $snapshot['invoice'] ?? [];
    $profile = $snapshot['profile'] ?? [];
    $client = $snapshot['client'] ?? [];
    $account = $snapshot['account'] ?? [];
    $contract = $snapshot['contract'] ?? null;
    $totals = $snapshot['totals'] ?? [];
    $currency = $invoice['currency'] ?? '';
    $date = fn (?string $value) => $value ? \Carbon\Carbon::parse($value)->format('M j, Y') : '—';
    $units = ['hour' => 'h', 'fixed' => 'fixed', 'item' => 'pcs'];
    $address = $client['address'] ?? [];
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice['number'] ?? '' }}</title>
    <style>
        @page { margin: 36px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #17191F; }
        h1 { font-size: 22px; margin: 0 0 4px; letter-spacing: -0.02em; }
        .muted { color: #4F5461; }
        .num { font-family: 'DejaVu Sans Mono', monospace; white-space: nowrap; }
        table { width: 100%; border-collapse: collapse; }
        .parties td { vertical-align: top; width: 50%; padding: 0 12px 0 0; }
        .label { font-size: 8px; text-transform: uppercase; letter-spacing: 0.06em; color: #5A5F6B; margin-bottom: 3px; }
        .items th { text-align: left; font-size: 8px; text-transform: uppercase; color: #5A5F6B; border-bottom: 1px solid #D5D3CB; padding: 6px 4px; }
        .items td { border-bottom: 1px solid #EEEDE8; padding: 7px 4px; vertical-align: top; }
        .right { text-align: right; }
        .totals td { padding: 4px; }
        .total td { font-size: 13px; font-weight: bold; border-top: 1px solid #17191F; padding-top: 8px; }
        .box { border: 1px solid #E3E1DA; border-radius: 6px; padding: 10px 12px; }
        .section { margin-top: 22px; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td>
                <h1>Invoice</h1>
                <div class="num">No. {{ $invoice['number'] ?? '' }}</div>
            </td>
            <td class="right">
                <div class="muted">Issued: <span class="num">{{ $date($invoice['issued_at'] ?? null) }}</span></div>
                @if (!empty($invoice['due_at']))
                    <div class="muted">Due: <span class="num">{{ $date($invoice['due_at']) }}</span></div>
                @endif
                <div class="muted">Service period:
                    <span class="num">{{ $date($invoice['period_start'] ?? null) }} – {{ $date($invoice['period_end'] ?? null) }}</span>
                </div>
            </td>
        </tr>
    </table>

    <table class="parties section">
        <tr>
            <td>
                <div class="label">From</div>
                <strong>Individual Entrepreneur {{ $profile['full_name_en'] ?: $profile['full_name'] ?? '' }}</strong><br>
                @if (!empty($profile['address_en'] ?? $profile['address'] ?? null))
                    {!! nl2br(e($profile['address_en'] ?: $profile['address'])) !!}<br>
                @endif
                Tax ID: <span class="num">{{ $profile['tax_id'] ?? '' }}</span><br>
                @if (!empty($profile['email'])) {{ $profile['email'] }}<br> @endif
                @if (!empty($profile['phone'])) {{ $profile['phone'] }} @endif
            </td>
            <td>
                <div class="label">Bill to</div>
                <strong>{{ $client['legal_name'] ?: $client['name'] ?? '' }}</strong><br>
                @foreach (($address['lines'] ?? []) as $line) {{ $line }}<br> @endforeach
                @if (!empty($address['city']) || !empty($address['zip']))
                    {{ trim(($address['city'] ?? '') . ' ' . ($address['zip'] ?? '')) }}<br>
                @endif
                @if (!empty($client['country'])) {{ $client['country'] }}<br> @endif
                @if (!empty($client['vat_number'])) VAT: <span class="num">{{ $client['vat_number'] }}</span><br> @endif
                @if (!empty($client['tax_id'])) Tax ID: <span class="num">{{ $client['tax_id'] }}</span> @endif
            </td>
        </tr>
    </table>

    @if ($contract)
        <div class="section muted">
            Contract: {{ $contract['name'] }}@if (!empty($contract['number'])) No. {{ $contract['number'] }}@endif
            dated {{ $date($contract['started_at'] ?? null) }}
        </div>
    @endif

    <table class="items section">
        <thead>
            <tr>
                <th style="width: 18px">#</th>
                <th>Description</th>
                <th class="right">Qty</th>
                <th class="right">Price, {{ $currency }}</th>
                <th class="right">Amount, {{ $currency }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach (($snapshot['items'] ?? []) as $item)
                <tr>
                    <td class="num">{{ $item['position'] }}</td>
                    <td>
                        @if (!empty($item['project_code']) || !empty($item['charge_type']))
                            <div class="muted">{{ collect([$item['project_code'] ?? null, $item['charge_type'] ?? null])->filter()->implode(' · ') }}</div>
                        @endif
                        {{ $item['description'] }}
                    </td>
                    <td class="right num">{{ $money->amount($item['quantity']) }} {{ $units[$item['unit']] ?? '' }}</td>
                    <td class="right num">{{ $money->amount($item['unit_price']) }}</td>
                    <td class="right num">{{ $money->amount($item['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals section" style="width: 45%; margin-left: 55%">
        @if (($totals['adjustment_amount'] ?? '0.00') !== '0.00')
            <tr><td>Subtotal</td><td class="right num">{{ $money->amount($totals['subtotal'] ?? 0) }}</td></tr>
            <tr><td>{{ $totals['adjustment_label'] ?: 'Adjustment' }}</td><td class="right num">{{ $money->amount($totals['adjustment_amount']) }}</td></tr>
        @endif
        <tr class="total"><td>Total</td><td class="right num">{{ $money->money($totals['total'] ?? 0, $currency) }}</td></tr>
    </table>

    <div class="section box">
        <div class="label">Payment details</div>
        @if (!empty($account['holder_name'])) Beneficiary: {{ $account['holder_name'] }}<br> @endif
        @if (!empty($account['bank_name'])) Bank: {{ $account['bank_name'] }}<br> @endif
        @if (!empty($account['iban'])) IBAN: <span class="num">{{ $account['iban'] }}</span><br> @endif
        @if (!empty($account['account_number'])) Account number: <span class="num">{{ $account['account_number'] }}</span><br> @endif
        @if (!empty($account['swift'])) SWIFT / BIC: <span class="num">{{ $account['swift'] }}</span><br> @endif
        @foreach (($account['details'] ?? []) as $detail)
            {{ $detail['label'] ?? '' }}: <span class="num">{{ $detail['value'] ?? '' }}</span><br>
        @endforeach
        Currency: {{ $account['currency'] ?? $currency }}
    </div>

    @if (!empty($snapshot['footer_note']))
        <div class="section muted">{!! nl2br(e($snapshot['footer_note'])) !!}</div>
    @endif

    @if ($signature)
        <div class="section">
            <img src="{{ $signature }}" alt="" style="height: 48px"><br>
            <span class="muted">{{ $profile['full_name_en'] ?: $profile['full_name'] ?? '' }}</span>
        </div>
    @endif
</body>
</html>
