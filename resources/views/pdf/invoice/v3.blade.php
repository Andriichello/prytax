{{--
    The PDF of an invoice (template v3, docs/specs/invoice.md), rendered from a snapshot of version 2 or 3. $doc: the
    InvoiceDocument, which InvoicePdfRenderer builds of it, in the snapshot's language and style; $css: the shared rules
    and the style's own. Every style shares this structure, and differs by its CSS class and its few extra elements
    ($doc->layout). The fonts beyond DejaVu (Plex Mono, Unbounded) are installed with dompdf by the renderer.

    dompdf has neither flexbox nor grid, so every row of columns is a table; it ignores <col> widths, so the widths are
    on the first row's cells. The page's top and bottom margins are 30pt, and the root box pulls the top one in, so the
    decoration, which sits in a page-sized frame, and the bands reach the page's edges, while a continuation page keeps
    its margins. The decoration comes first, so it stays on the first page. The renderer numbers the pages.
--}}
@php
    $t = fn (string $key, array $replace = []) => trans("invoice.labels.$key", $replace, $doc->locale);
    $layout = $doc->layout;
@endphp
<!doctype html>
<html lang="{{ $doc->locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $t('title') }} {{ $doc->number }}</title>
    <style>
        @page { margin: 30pt 0; }
        body { margin: 0; font-family: 'DejaVu Sans', sans-serif; }

        {!! $css !!}
    </style>
</head>
<body>
    <div class="inv {{ $doc->style->cssClass() }}{{ $layout['boxed'] ? ' boxed' : '' }}">
        @include('pdf.invoice.v3.decor')

        @if ($layout['wrapped'])
            <div class="content">
        @endif

        @if ($layout['ruleDouble'])
            <div class="rule-double"></div>
        @endif

        @if ($layout['banded'])
            <div class="band">@include('pdf.invoice.v3.head')</div>
        @else
            @include('pdf.invoice.v3.head')
        @endif

        @if ($layout['ruleHead'])
            <div class="rule-head"></div>
        @endif

        @if ($layout['ruleUnderHead'])
            <div class="rule-under-head"></div>
        @endif

        <div class="box">@include('pdf.invoice.v3.strip')</div>

        @include('pdf.invoice.v3.items')
        @include('pdf.invoice.v3.card')

        @if ($doc->signature)
            <div class="signature"><img src="{{ $doc->signature }}" alt=""></div>
        @endif

        @if ($layout['wrapped'])
            </div>
        @endif
    </div>
</body>
</html>
