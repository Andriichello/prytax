{{-- The head: the title and the number, and the ФОП with its address and one line of contacts --}}
<table class="layout head">
    <tr class="row-head">
        <td style="width: 45%">
            <div class="doc-title">@if ($doc->layout['mark'])<span class="mark">{{ $doc->profile['monogram'] }}</span>@endif{{ $t('title') }}</div>
            <div class="doc-number-line"><span class="doc-number-label">{{ $t('number') }}</span> <span class="doc-number">{{ $doc->number }}</span></div>
        </td>
        <td class="right" style="width: 55%">
            <div class="party-name party-name-lg">{{ $doc->profile['name'] }}</div>
            <div class="label role">{{ $t('entrepreneur') }}</div>
            @foreach ($doc->profile['addressLines'] as $line)
                <div class="party-details">{{ $line }}</div>
            @endforeach
            @if ($doc->profile['contacts'])
                <div class="party-details contacts">@foreach ($doc->profile['contacts'] as $index => $contact)@if ($index)<span class="sp"> | </span>@endif<span class="ci">{{ $contact }}</span>@endforeach</div>
            @endif
        </td>
    </tr>
</table>
