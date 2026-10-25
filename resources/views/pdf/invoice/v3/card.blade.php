{{--
    The payment card: the payment details with the purpose, and the payment note under them; on the right, the act of
    acceptance and the other note. Without either of those, the details take the card's width. Both notes may print.
--}}
<div class="card">
    <table class="card-cols">
        <tr>
            <td class="details{{ $doc->hasCardRight() ? '' : ' full' }}" style="width: {{ $doc->hasCardRight() ? '50%' : '100%' }}">
                <div class="lbl">{{ $t('payment_details') }}</div>
                <table class="kv">
                    @foreach ($doc->paymentDetails as $index => $row)
                        <tr>
                            <td class="k"@if ($index === 0) style="width: {{ $doc->kvKeyWidth }}pt"@endif>{{ $row['label'] }}</td>
                            <td class="v">@if ($row['mono'])<span class="mono">{{ $row['value'] }}</span>@else{{ $row['value'] }}@endif</td>
                        </tr>
                    @endforeach
                    <tr class="purpose-row">
                        <td class="k">{{ $t('purpose_short') }}</td>
                        <td class="v">{{ $doc->paymentPurpose }}</td>
                    </tr>
                </table>
                @if ($doc->paymentNote !== null)
                    <div class="card-section">
                        <div class="lbl">{{ $t('payment_note') }}</div>
                        <div class="purpose-text">{!! nl2br(e($doc->paymentNote)) !!}</div>
                    </div>
                @endif
            </td>
            @if ($doc->hasCardRight())
                <td class="purpose act" style="width: 50%">
                    @if ($doc->actNote !== null)
                        <div>
                            <div class="lbl">{{ $t('act_of_acceptance') }}</div>
                            <div class="purpose-text">{!! nl2br(e($doc->actNote)) !!}</div>
                        </div>
                    @endif
                    @if ($doc->otherNote !== null)
                        <div @class(['card-section' => $doc->actNote !== null])>
                            <div class="lbl">{{ $t('other_notes') }}</div>
                            <div class="purpose-text">{!! nl2br(e($doc->otherNote)) !!}</div>
                        </div>
                    @endif
                </td>
            @endif
        </tr>
    </table>
</div>
