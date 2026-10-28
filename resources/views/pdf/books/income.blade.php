{{--
    The income book of a year (design.md §16.7) as the screen shows it: the year's income against the limit, then the
    started quarters, newest first, each a dark row with its total and the running total at its end, its months under
    it, and every receipt and refund of a month with the running total after it.
--}}
@extends('pdf.books.layout', ['title' => __('Income book · :year', ['year' => $year])])

@php
    $roman = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV'];
@endphp

@section('book')
    <table class="band" width="100%">
        <tr>
            <td class="label">{{ __('Income since the start of the year') }}</td>
            <td style="text-align: right" class="label">{{ __(':percent % of the limit', ['percent' => $book['limit']['percent']]) }}</td>
        </tr>
        <tr>
            <td style="font-size: 14pt; font-weight: bold" class="num">{{ $money->money($book['ytd'], 'UAH') }}</td>
            <td style="text-align: right" class="num">{{ __('limit :cap · :left left', ['cap' => $money->money($book['limit']['cap'], 'UAH'), 'left' => $money->money($book['limit']['left'], 'UAH')]) }}</td>
        </tr>
    </table>

    @if (!$book['has_receipts'])
        <p>{{ __('No receipts in :year yet.', ['year' => $year]) }}</p>
    @else
        <table class="book">
            <thead>
                <tr>
                    <th></th>
                    <th>{{ __('Income, ₴') }}</th>
                    <th>{{ __('Since the start of the year') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($book['quarters'] as $quarter)
                    <tr class="quarter">
                        <td>{{ __('Q:number', ['number' => $quarter['quarter'], 'roman' => $roman[$quarter['quarter']]]) }} · {{ $quarter['current'] ? __('total · current') : __('total') }}</td>
                        <td class="num">{{ $money->amount($quarter['total']) }}</td>
                        <td class="num">{{ $money->amount($quarter['ytd_end']) }}</td>
                    </tr>
                    @foreach ($quarter['months'] as $month)
                        <tr class="month">
                            <td>{{ \Illuminate\Support\Str::ucfirst(\Carbon\CarbonImmutable::create($year, $month['month'])->locale(app()->getLocale())->isoFormat('MMMM')) }} · {{ $month['count'] }}</td>
                            @if ($month['state'] === 'ok')
                                <td class="num">{{ $money->amount($month['total']) }}</td>
                            @else
                                <td class="muted">{{ $month['state'] === 'not_yet' ? __('none yet') : __('no income') }}</td>
                            @endif
                            <td class="num">{{ $money->amount($month['ytd_end']) }}</td>
                        </tr>
                        @foreach ($month['receipts'] as $receipt)
                            <tr class="entry">
                                <td><span class="num">{{ \Carbon\CarbonImmutable::parse($receipt['credited_on'])->format('d.m') }}</span> · {{ $receipt['client'] }}@if ($receipt['invoice_number']) · <span class="num">{{ $receipt['invoice_number'] }}</span>@endif @if ($receipt['kind'] === 'refund') · {{ __('refund') }}@endif</td>
                                <td class="num">{{ $money->amount($receipt['amount_uah']) }}</td>
                                <td class="num">{{ $money->amount($receipt['ytd_after']) }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="note">{{ __('A free-form income book: each receipt on its credit date, in hryvnias at the NBU rate. A refund to a client reduces the income of the day of the refund.') }}</p>
@endsection
