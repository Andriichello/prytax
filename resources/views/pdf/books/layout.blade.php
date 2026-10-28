{{--
    The frame of the books' PDFs (design.md §16.7), styled as the app's pages: a portrait A4 page with the PryTax
    wordmark and the day it was made, the book's title with the ФОП under it, then the book: a dark band on top (the
    page's hero card), uppercase section titles and white cards with rounded corners. Texts are in DejaVu Sans, the
    figures in IBM Plex Mono, as the app's numbers (PdfFonts installs it). The logo's mark is drawn with boxes, as an
    SVG image would bring in a font of its own. BooksController numbers the pages.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 14mm 14mm 17mm; }
        body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; line-height: 1.35; color: #17191f; }
        table { border-collapse: collapse; }
        .num { font-family: 'Plex Mono', 'DejaVu Sans Mono', monospace; white-space: nowrap; }
        .ink-2 { color: #4f5461; }
        .ink-3 { color: #5a5f6b; }
        .faint { color: #8e929c; }
        .positive { color: #17683f; }
        .warning { color: #8a4b00; }

        .brand { width: 100%; margin: 0 0 14pt; }
        .brand td { padding: 0; vertical-align: middle; }
        .mark { position: relative; width: 17pt; height: 17pt; border-radius: 5pt; background: #17191f; }
        .mark .sheet { position: absolute; left: 4.3pt; top: 3.3pt; width: 6.2pt; height: 8.2pt; border: 1.1pt solid #ffffff; border-radius: 1.5pt; }
        .mark .line { position: absolute; height: 1.1pt; background: #ffffff; }
        .brand .wordmark { padding-left: 7pt; font-size: 12pt; font-weight: bold; }
        .brand .wordmark span { color: #2b45b8; }
        .made { text-align: right; font-size: 7.5pt; color: #8e929c; }

        h1 { margin: 0; font-size: 18pt; font-weight: bold; }
        .sub { margin: 3pt 0 13pt; font-size: 9pt; color: #5a5f6b; }

        .hero { margin: 0 0 15pt; padding: 11pt 14pt 12pt; border-radius: 10pt; background: #17191f; color: #ffffff; }
        .hero table { width: 100%; }
        .hero td { padding: 0; vertical-align: baseline; }
        .eyebrow { font-size: 7pt; font-weight: bold; letter-spacing: 0.6pt; text-transform: uppercase; color: #c9cbd3; }
        .hero .share { text-align: right; font-size: 10pt; font-weight: bold; }
        .hero .big { padding: 5pt 0 8pt; font-size: 19pt; font-weight: bold; }
        .bar { position: relative; height: 5pt; margin: 0 0 7pt; border-radius: 3pt; background: #33363e; }
        .bar .part { position: absolute; top: 0; height: 5pt; }
        .bar .tick { position: absolute; top: -2pt; width: 1.2pt; height: 9pt; background: #6b6f7a; }
        .hero .foot td { font-size: 7.5pt; color: #c9cbd3; }

        .section { width: 100%; margin: 0 0 6pt; }
        .section td { padding: 0 2pt; font-size: 7.5pt; color: #5a5f6b; }
        .section .title { font-weight: bold; letter-spacing: 0.6pt; text-transform: uppercase; }
        .section .aside { text-align: right; }

        .card { border: 1px solid #e3e1da; border-radius: 10pt; background: #ffffff; }
        .card.tabular { padding-bottom: 5pt; }
        .note { margin: 12pt 2pt 0; font-size: 7.5pt; line-height: 1.45; color: #5a5f6b; }
    </style>
    @yield('styles')
</head>
<body>
    <table class="brand">
        <tr>
            <td style="width: 17pt">
                <div class="mark">
                    <div class="sheet"></div>
                    <div class="line" style="left: 6.5pt; top: 6.1pt; width: 4pt"></div>
                    <div class="line" style="left: 6.5pt; top: 8.1pt; width: 4pt"></div>
                    <div class="line" style="left: 6.5pt; top: 10.1pt; width: 1.5pt"></div>
                    <div class="line" style="left: 9.5pt; top: 10.1pt; width: 1pt"></div>
                </div>
            </td>
            <td class="wordmark">Pry<span>Tax</span></td>
            <td class="made">{{ __('Made on :date', ['date' => now('Europe/Kyiv')->format('d.m.Y')]) }}</td>
        </tr>
    </table>
    <h1>{{ $title }}</h1>
    <p class="sub">{{ $profile->full_name }} · {{ __('ФОП group :group', ['group' => $profile->tax_group]) }} · {{ __('Tax number') }} <span class="num">{{ $profile->tax_id_masked }}</span></p>
    @yield('book')
</body>
</html>
