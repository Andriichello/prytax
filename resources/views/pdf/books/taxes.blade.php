{{--
    The taxes of a year (design.md §16.7) as the screen's «Квартал» grouping shows them: the year's totals, then each
    cumulative period of the started quarters, newest first, with ЄСВ, ЄП and ВЗ for its last quarter, cumulative
    since I кв. and paid; a sent amount under the paid one.
--}}
@extends('pdf.books.layout', ['title' => __('Taxes · :year', ['year' => $year])])

@php
    $names = ['social_contribution' => 'ЄСВ', 'single_tax' => 'ЄП', 'military_levy' => 'ВЗ'];
    $roman = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV'];
@endphp

@section('book')
    <table class="band" width="100%">
        <tr>
            <td class="label">{{ __('Taxes since the start of the year') }}</td>
            <td style="text-align: right" class="label">{{ __(':percent % paid', ['percent' => $book['totals']['percent']]) }}</td>
        </tr>
        <tr>
            <td class="num">{{ __('Accrued') }}: {{ $money->amount($book['totals']['accrued']) }}</td>
            <td style="text-align: right" class="num">{{ __('Sent') }}: {{ $money->amount($book['totals']['sent']) }} · {{ __('Paid') }}: {{ $money->amount($book['totals']['paid']) }}</td>
        </tr>
    </table>

    @foreach ($book['cumulative'] as $period)
        <div class="card">
            <h2>{{ $period['to_quarter'] === 1 ? __('Q1 :year', ['year' => $year]) : __('Q1–Q:number :year', ['number' => $period['to_quarter'], 'roman' => $roman[$period['to_quarter']], 'year' => $year]) }}@if ($period['current']) · {{ __('current') }}@endif <span class="num" style="float: right">{{ $money->amount($period['total']) }}</span></h2>
            <table class="book">
                <thead>
                    <tr>
                        <th></th>
                        <th>{{ __('For Q:number', ['number' => $period['to_quarter'], 'roman' => $roman[$period['to_quarter']]]) }}</th>
                        <th>{{ __('Cumulative') }}</th>
                        <th>{{ __('Paid') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($period['rows'] as $row)
                        <tr>
                            <td>{{ $names[$row['tax_type']] }}</td>
                            <td class="num">{{ $money->amount($row['quarter']) }}</td>
                            <td class="num">{{ $money->amount($row['cumulative']) }}</td>
                            <td class="num">
                                {{ $money->amount($row['paid']) }}
                                @if ((float) $row['sent'] > 0)<br><span class="muted">+{{ $money->amount($row['sent']) }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach

    <p class="note">{{ __('ЄП and ВЗ as in the declaration: cumulative since the start of the year, and for a quarter = cumulative − the previous period. ЄСВ is :esv ₴ for each month of a quarter. «+ amount» under the paid one is sent, not yet confirmed by the bank.', ['esv' => $money->amount($book['esv_monthly'] ?? '0')]) }}</p>
@endsection
