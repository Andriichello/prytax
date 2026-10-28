{{--
    The frame of the books' PDFs (design.md §16.7): a portrait A4 page in DejaVu Sans, the book's title with the ФОП
    under it, then the book. BooksController numbers the pages at the bottom right.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 18mm 14mm 16mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #17191f; }
        h1 { margin: 0 0 2pt; font-size: 15pt; }
        .sub { margin: 0 0 10pt; color: #5a5f6b; }
        .band { margin: 0 0 10pt; padding: 7pt 9pt; background: #17191f; color: #ffffff; }
        .band td { padding: 0; }
        .band .label { color: #b9bcc6; font-size: 8pt; text-transform: uppercase; }
        table.book { width: 100%; border-collapse: collapse; }
        table.book th { padding: 4pt 6pt; border-bottom: 1px solid #d5d3cb; color: #5a5f6b; font-size: 8pt; font-weight: normal; text-align: right; }
        table.book th:first-child { text-align: left; }
        table.book td { padding: 3.5pt 6pt; border-bottom: 1px solid #eeede8; text-align: right; }
        table.book td:first-child { text-align: left; }
        tr.quarter td { background: #17191f; color: #ffffff; font-weight: bold; border-bottom: 0; }
        tr.month td { font-weight: bold; }
        tr.entry td { color: #4f5461; }
        tr.entry td:first-child { padding-left: 16pt; }
        .muted { color: #8e929c; }
        .num { white-space: nowrap; }
        .note { margin: 10pt 0 0; color: #5a5f6b; font-size: 8pt; }
        .card { margin: 0 0 9pt; page-break-inside: avoid; }
        .card h2 { margin: 0 0 3pt; font-size: 10pt; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="sub">{{ $profile->full_name }} · {{ __('ФОП group :group', ['group' => $profile->tax_group]) }} · {{ __('Tax number') }} {{ $profile->tax_id_masked }}</p>
    @yield('book')
</body>
</html>
