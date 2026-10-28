{{--
    The income book of a year (design.md §16.7) as the screen shows it: the dark band of the year's income against the
    group's limit (its share, a bar with ticks at 80 and 90 %, the limit and what is left), then «Книга доходів», a card
    with the started quarters, newest first, each a dark row with its total and the running total at its end, its
    months under it, and every receipt and refund of a month on a light row, with the running total after it.
--}}
@extends('pdf.books.layout', ['title' => __('Income book · :year', ['year' => $year])])

@php
    $roman = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV'];
    $share = (float) $book['limit']['percent'];
    $near = $share >= 80;
    // The limit is whole hryvnias, as the screen shows it: «10 091 049 ₴»
    $cap = $book['limit']['cap'];
    $cap = str_ends_with((string) $cap, '.00') ? $money->amount($cap, 0) . ' ₴' : $money->money($cap, 'UAH');
@endphp

@section('styles')
    <style>
        table.book { width: 100%; }
        table.book th { padding: 7pt 10pt 6pt; border-bottom: 1px solid #e3e1da; font-size: 7pt; font-weight: normal; text-align: right; color: #5a5f6b; }
        table.book th.first { text-align: left; }
        table.book td { padding: 6pt 10pt; border-top: 1px solid #eeede8; text-align: right; }
        table.book td.first { text-align: left; }
        tr.quarter td { padding-top: 7pt; padding-bottom: 7pt; border-top: 0; background: #17191f; color: #ffffff; font-weight: bold; }
        tr.quarter .label { font-size: 10pt; }
        tr.quarter .what { font-size: 7pt; font-weight: normal; color: #c9cbd3; }
        tr.month td { font-size: 9pt; }
        tr.month .count { font-size: 7pt; color: #8e929c; }
        tr.entry td { padding-top: 4.5pt; padding-bottom: 4.5pt; background: #f9f8f5; font-size: 8pt; color: #4f5461; }
        tr.entry td.first { padding-left: 22pt; }
        tr.entry .date { font-weight: bold; color: #17191f; }
    </style>
@endsection

@section('book')
    <div class="hero">
        <table>
            <tr>
                <td class="eyebrow">{{ __('Income since the start of the year') }}</td>
                <td class="share" @if ($near) style="color: #f2b66b" @endif>{{ __(':percent % of the limit', ['percent' => $book['limit']['percent']]) }}</td>
            </tr>
        </table>
        <div class="big num">{{ $money->money($book['ytd'], 'UAH') }}</div>
        <div class="bar">
            <div class="part" style="left: 0; width: {{ min(100, $share) }}%; border-radius: 3pt; background: {{ $near ? '#c27a12' : '#ffffff' }}"></div>
            <div class="tick" style="left: 80%"></div>
            <div class="tick" style="left: 90%"></div>
        </div>
        <table class="foot">
            <tr>
                <td>{{ __('group :group limit', ['group' => $profile->tax_group]) }} <span class="num">{{ $cap }}</span></td>
                <td style="text-align: right">{{ __('left') }} <span class="num">{{ $money->money($book['limit']['left'], 'UAH') }}</span></td>
            </tr>
        </table>
    </div>

    <table class="section">
        <tr>
            <td class="title">{{ __('Income book') }}</td>
            <td class="aside">{{ __('newest first') }}</td>
        </tr>
    </table>

    @if (!$book['has_receipts'])
        <div class="card ink-3" style="padding: 12pt 14pt">{{ __('No receipts in :year yet.', ['year' => $year]) }}</div>
    @else
        <div class="card tabular">
            <table class="book">
                <thead>
                    <tr>
                        <th class="first"></th>
                        <th>{{ __('Income, ₴') }}</th>
                        <th>{{ __('Since the start of the year') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($book['quarters'] as $quarter)
                        <tr class="quarter">
                            <td class="first">
                                <span class="label">{{ __('Q:number', ['number' => $quarter['quarter'], 'roman' => $roman[$quarter['quarter']]]) }}</span>
                                <span class="what">&nbsp;{{ $quarter['current'] ? __('total · current') : __('total') }}</span>
                            </td>
                            <td class="num">{{ $money->amount($quarter['total']) }}</td>
                            <td class="num">{{ $money->amount($quarter['ytd_end']) }}</td>
                        </tr>
                        @foreach ($quarter['months'] as $month)
                            <tr class="month">
                                <td class="first">
                                    {{ \Illuminate\Support\Str::ucfirst(\Carbon\CarbonImmutable::create($year, $month['month'])->locale(app()->getLocale())->isoFormat('MMMM')) }}
                                    @if ($month['state'] === 'ok')
                                        <span class="count num">· {{ $month['count'] }}</span>
                                    @else
                                        <span class="count">&nbsp;{{ $month['state'] === 'not_yet' ? __('none yet') : __('no income') }}</span>
                                    @endif
                                </td>
                                <td class="num @if ($month['state'] !== 'ok') faint @endif">{{ $month['state'] === 'ok' ? $money->amount($month['total']) : '—' }}</td>
                                <td class="num ink-2">{{ $money->amount($month['ytd_end']) }}</td>
                            </tr>
                            @foreach ($month['receipts'] as $receipt)
                                <tr class="entry">
                                    <td class="first">
                                        <span class="num date">{{ \Carbon\CarbonImmutable::parse($receipt['credited_on'])->format('d.m') }}</span>
                                        &nbsp;{{ $receipt['client'] }}
                                        @if ($receipt['invoice_number'])&nbsp;<span class="num faint">{{ $receipt['invoice_number'] }}</span>@endif
                                        @if ($receipt['kind'] === 'refund')&nbsp;<span class="warning">· {{ __('refund') }}</span>@endif
                                    </td>
                                    <td class="num">{{ $money->amount($receipt['amount_uah']) }}</td>
                                    <td class="num">{{ $money->amount($receipt['ytd_after']) }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <p class="note">{{ __('A free-form income book: each receipt on its credit date, in hryvnias at the NBU rate. A refund to a client reduces the income of the day of the refund.') }}</p>
@endsection
