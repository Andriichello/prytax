{{-- The style's decoration on the first page: rules, bands and shapes, or its SVG layer, in a page-sized frame --}}
@if ($doc->layout['frame'] || $doc->layout['layer'])
    <div class="frame">
        @if ($doc->layout['layer'])
            <img class="shape layer" src="{{ $doc->layout['layer'] }}" alt="">
        @endif
        @foreach ($doc->layout['frame'] as $class)
            <div class="{{ $class }}"></div>
        @endforeach
    </div>
@endif
