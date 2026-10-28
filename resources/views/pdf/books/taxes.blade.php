{{--
    The taxes of a year (design.md §16.7) as the screen's «Квартал» grouping shows them: the dark band of the year's
    taxes (the share paid, a bar of what is paid and sent, accrued, sent and paid), then each cumulative period of the
    started quarters, newest first, as a card, two a row: ЄСВ, ЄП and ВЗ for its last quarter, cumulative since I кв.
    and paid (green once it covers the cumulative), with a sent amount under the paid one.
--}}
@extends('pdf.books.layout', ['title' => __('Taxes · :year', ['year' => $year])])

@php
    $names = ['social_contribution' => 'ЄСВ', 'single_tax' => 'ЄП', 'military_levy' => 'ВЗ'];
    $roman = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV'];
    $totals = $book['totals'];
    $accrued = (float) $totals['accrued'];
    $paidWidth = $accrued > 0 ? min(100, (float) $totals['paid'] / $accrued * 100) : 0;
    $sentWidth = $accrued > 0 ? min(100 - $paidWidth, (float) $totals['sent'] / $accrued * 100) : 0;
    $above = fn ($amount): bool => (float) $amount > 0;
@endphp

@section('styles')
    <style>
        .hero .figures { margin-top: 1pt; }
        .hero .figures td { width: 25%; }
        .hero .figures .label { font-size: 7pt; color: #c9cbd3; }
        .hero .figures .value { font-size: 10.5pt; font-weight: bold; }
        table.grid { width: 100%; }
        table.grid td.cell { width: 50%; padding: 0 0 9pt; vertical-align: top; }
        table.grid td.left { padding-right: 4.5pt; }
        table.grid td.right { padding-left: 4.5pt; }
        .period { padding: 0 0 3pt; }
        .period table { width: 100%; }
        .period .head td { padding: 8pt 9pt 2pt; vertical-align: baseline; }
        .period .title { font-size: 10pt; font-weight: bold; }
        .period .badge { padding: 1pt 4pt; border-radius: 4pt; background: #e9edfb; font-size: 6.5pt; font-weight: bold; color: #1f338c; }
        .period .total { text-align: right; font-size: 8.5pt; color: #4f5461; }
        .period .labels td { padding: 5pt 9pt 4pt; text-align: right; font-size: 6.5pt; color: #5a5f6b; }
        .period .row td { padding: 5pt 9pt; border-top: 1px solid #eeede8; text-align: right; font-size: 8.5pt; vertical-align: top; }
        .period .row td.tax { text-align: left; font-size: 9pt; font-weight: bold; }
        .period .row .cumulative { font-weight: bold; }
        .period .row .sent { font-size: 7pt; color: #6e3b00; }
    </style>
@endsection

@section('book')
    <div class="hero">
        <table>
            <tr>
                <td class="eyebrow">{{ __('Taxes since the start of the year') }}</td>
                <td class="share">{{ __(':percent % paid', ['percent' => $totals['percent']]) }}</td>
            </tr>
        </table>
        <div class="bar" style="margin-top: 8pt; margin-bottom: 9pt">
            <div class="part" style="left: 0; width: {{ $paidWidth }}%; border-radius: 3pt 0 0 3pt; background: #7fd3a0"></div>
            <div class="part" style="left: {{ $paidWidth }}%; width: {{ $sentWidth }}%; background: #e9b44c"></div>
        </div>
        <table class="figures">
            <tr>
                <td><div class="label">{{ __('Accrued') }}</div><div class="value num">{{ $money->amount($totals['accrued']) }}</div></td>
                <td><div class="label">{{ __('Sent') }}</div><div class="value num" style="color: #f2b66b">{{ $money->amount($totals['sent']) }}</div></td>
                <td><div class="label">{{ __('Paid') }}</div><div class="value num" style="color: #7fd3a0">{{ $money->amount($totals['paid']) }}</div></td>
                <td></td>
            </tr>
        </table>
    </div>

    <table class="section">
        <tr>
            <td class="title">{{ __('Cumulative total') }}</td>
            <td class="aside">{{ __('newest first') }}</td>
        </tr>
    </table>

    <table class="grid">
        @foreach (array_chunk($book['cumulative'], 2) as $pair)
            <tr>
                @foreach ($pair as $side => $period)
                    <td class="cell {{ $side === 0 ? 'left' : 'right' }}">
                        <div class="card period">
                            <table class="head">
                                <tr>
                                    <td>
                                        <span class="title">{{ $period['to_quarter'] === 1 ? __('Q1 :year', ['year' => $year]) : __('Q1–Q:number :year', ['number' => $period['to_quarter'], 'roman' => $roman[$period['to_quarter']], 'year' => $year]) }}</span>
                                        @if ($period['current'])&nbsp;<span class="badge">{{ __('current') }}</span>@endif
                                    </td>
                                    <td class="total num">{{ $money->money($period['total'], 'UAH') }}</td>
                                </tr>
                            </table>
                            <table>
                                <tr class="labels">
                                    <td></td>
                                    <td>{{ __('For Q:number', ['number' => $period['to_quarter'], 'roman' => $roman[$period['to_quarter']]]) }}</td>
                                    <td>{{ __('Cumulative') }}</td>
                                    <td>{{ __('Paid') }}</td>
                                </tr>
                                @foreach ($period['rows'] as $row)
                                    <tr class="row">
                                        <td class="tax">{{ $names[$row['tax_type']] }}</td>
                                        <td class="num {{ $above($row['quarter']) ? '' : 'faint' }}">{{ $money->amount($row['quarter']) }}</td>
                                        <td class="num cumulative {{ $above($row['cumulative']) ? '' : 'faint' }}">{{ $money->amount($row['cumulative']) }}</td>
                                        <td class="num {{ $above($row['paid']) && (float) $row['paid'] >= (float) $row['cumulative'] ? 'positive' : ($above($row['cumulative']) ? '' : 'faint') }}">
                                            {{ $money->amount($row['paid']) }}
                                            @if ($above($row['sent']))<div class="sent num">+{{ $money->amount($row['sent']) }}</div>@endif
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        </div>
                    </td>
                @endforeach
                @if (count($pair) === 1)
                    <td class="cell right"></td>
                @endif
            </tr>
        @endforeach
    </table>

    <p class="note">{{ __('ЄП and ВЗ as in the declaration: cumulative since the start of the year, and for a quarter = cumulative − the previous period. ЄСВ is :esv ₴ for each month of a quarter. «+ amount» under the paid one is sent, not yet confirmed by the bank.', ['esv' => $money->amount($book['esv_monthly'] ?? '0')]) }}</p>
@endsection
