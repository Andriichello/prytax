# Invoice PDF — template v3 and its 13 styles

**State:** as built on 2026-10-24 (Laravel 13, Blade → dompdf 3.1.6).
**Spec:** `design.md` v3.3 §4 (lifecycle, snapshot) and §4.1 (the settings cascade, which decides what this document
prints); the functional rules are in `accounting.md`.
**History:** this file first held the instructions for layout v2 (the header and party blocks, the contract toggle).
That layout became the head and the strip of template v3, whose 13 styles came from Claude Design's handoff
(`storage/handoff/prytax-invoice-styles-handoff.md`, with reference renders; kept out of git). Template v2 never left
development and was dropped.

An invoice is printed from its snapshot, in one of 13 styles, in English or Ukrainian. Every style has the same
content and structure: the styles differ by their CSS, their decoration and their fonts.

---

## 0. Rules

1. **Render only from `snapshot`**, never from live models (`design.md` §4). Editing a profile, a client, a contract or
   an account never changes an issued document.
2. **Pass the language, never set it.** Every label goes through `trans("invoice.labels.…", [], $locale)` and every
   date through `DocumentDate::format($date, $locale)`. `App::setLocale()` is never called: requests set the
   application's language to their user's, and renders also run in queued jobs.
3. **Stored PDFs are the issued documents.** A generated file records the template it was rendered with, and
   re-rendering an invoice uses that template (§2). A template that issued documents in production is never changed;
   a new layout is a new template.
4. **The view holds no logic.** `InvoiceDocument` (the view model) carries the formatted texts, the measured column
   widths, the row and cell classes and the style's structure; the Blade partials only print them.
5. **dompdf's limits** (found while matching the reference renders):
   - No flexbox or grid, and floats break on long content: every row of columns is a `<table>`.
   - It ignores `<col>` widths and drops a cell's pt width under `table-layout: fixed`. Widths are set on the first
     row's cells (auto layout), and the renderer measures the columns with dompdf's own font metrics
     (`itemsColWidths()`, `kvKeyWidth`).
   - Positional selectors are unreliable. The view model emits classes instead: `odd` / `even` and `last` on item
     rows, `.col-last` on the last cell of a row, `.col-next` on the 2nd and 3rd cells of the strip.
   - `word-wrap: break-word`, not `overflow-wrap`.
   - dompdf makes a line `line-height × font height × font_height_ratio` high. Template v3 renders with
     `fontHeightRatio = 1000 / 1164`, so that a line of DejaVu is as high as its CSS `line-height`, as in browsers.
     Template v1 keeps dompdf's default of 1.1.
   - dompdf 3.1 looks a font up by its exact weight, so 600 is neither `normal` nor `bold`: each weight that a style
     uses is registered on its own (§5).
   - php-svg-lib reads `pt` as `px`, so the decoration SVGs have unitless sizes (A4 = `595.28 × 841.89`) and use only
     `rect`, `polygon`, `circle` and `line`.
   - Fonts are subset (`isFontSubsettingEnabled`). Embedding whole fonts would add megabytes to each PDF.

---

## 1. Files

| What | Where |
|---|---|
| Renderer: template choice, view model, fonts, page numbers | `app/Services/Invoices/InvoicePdfRenderer.php` |
| View model | `app/Services/Invoices/InvoiceDocument.php` |
| Snapshot | `InvoiceFreezer::snapshot()` |
| Styles (catalogue order, `label()`, `group()`, `cssClass()`) | `app/Enums/InvoiceStyle.php` |
| Where the note prints (`payment`, `other`) | `app/Enums/InvoiceNoteKind.php` |
| Template v3 and its partials | `resources/views/pdf/invoice/v3.blade.php`, `v3/{decor,head,strip,items,card}.blade.php` |
| Template v1 (schema 1, unchanged) | `resources/views/pdf/invoice/v1.blade.php` |
| CSS: shared rules, then one file per style | `resources/invoice-styles/base.css`, `resources/invoice-styles/{style}.css` |
| Decoration layers (SVG, A4) | `resources/invoice-decor/{style}.svg` |
| Fonts beyond DejaVu, with their OFL licences | `resources/fonts/IBMPlexMono-{Regular,SemiBold}.ttf`, `Unbounded-{SemiBold,Bold}.ttf` |
| Labels and built-in texts | `lang/en/invoice.php`, `lang/uk/invoice.php` |
| Document dates | `app/Helpers/DocumentDate.php` |
| Style preview | `app/Services/Invoices/InvoiceStylePreview.php`, `PreviewInvoiceFactory.php` |
| Thumbnails of the style picker | `app/Console/Commands/InvoiceStyleThumbnails.php` → `public/images/invoice-styles/` |
| The SPA's style catalogue | `resources/js/utils/invoiceStyles.ts` |

---

## 2. Templates and versions

`InvoicePdfRenderer::templateFor()` picks the template:

| Snapshot | Template of the invoice's generated file | Renders with |
|---|---|---|
| `schema_version` 1 (or none) | any | **v1**, as it always did |
| 2 (development only) | — | **v3** as `outlined`; its `footer_note` prints as a note of kind `other` |
| 3 | `v3`, or no file yet | **v3** |
| 2 or 3 | `v2` (development only) | **v3** |

A generated file stores `template_version` and `schema_version` in its metadata (api-core's `files.metadata`; this
is `invoice_files.template_version` in `design.md` §3). New renders use `TEMPLATE_VERSION = 'v3'`.

---

## 3. Data: snapshot v3

`InvoiceFreezer` writes the snapshot on freeze (`draft → ready`). It resolves the settings cascade (`design.md`
§4.1) and takes the parties' names and addresses in the invoice's language, so the template never decides anything
on its own.

```json
{
  "schema_version": 3,
  "money_format": "plain_dot",
  "locale": "en",
  "frozen_at": "2026-10-31T12:00:00+02:00",
  "profile": { "name": "Myrchuk Mykola", "address": "Kapushans'ka St, 42, 15\nUzhhorod, Ukraine",
               "tax_id": "1234500592", "tax_id_kind": "rnokpp", "registered_at": "2021-03-01",
               "email": "mykola@example.com", "phone": "+3801237654", "signature_file_id": null },
  "client": { "name": "Bring IT LLC", "country": "US", "vat_number": null, "tax_id": null,
              "address": { "lines": ["500 Market St, Suite 200"], "city": "New Jersey", "zip": "25902" },
              "email": "billing@bringit.example" },
  "contract": { "name": "Service Agreement", "number": "109-23", "subject": "IT services",
                "started_at": "2026-09-01", "ends_at": null, "payment_terms_days": 14 },
  "presentation": {
    "style": "outlined",
    "payment_purpose": "Payment for IT services by the invoice No. BRIT-2026-10-31-2 from Oct 31, 2026",
    "act_note": "Payment of this invoice confirms the successful delivery of services and serves as the Act of Acceptance. No additional signatures are required.",
    "show_contract": true,
    "note": "Please quote the invoice number in the transfer reference.",
    "note_kind": "payment"
  },
  "account": { "name": "Payoneer USD", "kind": "platform", "currency": "USD", "holder_name": "Myrchuk Mykola",
               "bank_name": "…", "iban": null, "swift": "…", "account_number": "…",
               "details": [{ "label": "Routing (ABA)", "value": "…" }] },
  "invoice": { "number": "BRIT-2026-10-31-2", "source": "generated", "period_start": "2026-10-01",
               "period_end": "2026-10-31", "issued_at": "2026-10-31", "due_at": "2026-11-14", "currency": "USD" },
  "items": [{ "position": 1, "project_code": "CRONE", "charge_type": "Billable", "description": "IT services …",
              "unit": "hour", "quantity": "80.00", "unit_price": "45.00", "amount": "3600.00" }],
  "totals": { "subtotal": "5213.00", "adjustment_amount": "0.00", "adjustment_label": null, "total": "5213.00" }
}
```

- `profile.name` and `profile.address`: the Latin name and the English address on `en` invoices, the Ukrainian ones on
  `uk` invoices. `client.name`: the client's `legal_name_uk` on `uk` invoices when it is set, otherwise its legal
  name.
- `presentation.style` is always a valid key. A level may still hold the key of a removed style; that key freezes as
  `outlined`.
- `presentation.act_note` is `null` when the act note is off. `presentation.note` is `null` when the note resolves to
  off or has no text. `note_kind` defaults to `other`.
- `presentation.payment_purpose` is already rendered: its placeholders are filled with the final number and the
  invoice's dates.
- The contract block is kept when `show_contract` is false. The PDF shows the contract section only when
  `show_contract && contract !== null`.
- Version 3 no longer writes `footer_note`; the note replaced it.
- External invoices are never rendered: their primary document is the uploaded PDF.

---

## 4. Layout (every style)

A4 portrait, in points; the body is DejaVu Sans 7.5 pt on 11.5 pt lines, the ink `#17191F`.

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ (the style's decoration: rules, bands, shapes or its SVG layer — page 1 only) │
│ INVOICE                                                    Myrchuk Mykola    │ head 45 % | 55 %
│ No. BRIT-2026-10-31-2                             INDIVIDUAL ENTREPRENEUR    │
│                                           Kapushans'ka St, 42, 15, Uzhhorod  │
│                        Tax ID: 1234500592 | mykola@example.com | +3801237654 │
│ ┌──────────────────────────────────────────────────────────────────────────┐ │
│ │ BILL TO                       │ PERIOD             │ ISSUED              │ │ strip 50 | 30 | 20 %
│ │ Bring IT LLC                  │ Oct 1, 2026 –      │ Oct 31, 2026        │ │  (boxed, except
│ │ 500 Market St, Suite 200      │ Oct 31, 2026       │ DUE                 │ │   Simplified)
│ │ New Jersey 25902              │                    │ Nov 14, 2026        │ │
│ │ US                            │                    │                     │ │
│ ├──────────────────────────────────────────────────────────────────────────┤ │
│ │ CONTRACT                                                                 │ │ only when shown
│ │ Service Agreement No. 109-23 dated Sep 1, 2026                           │ │
│ └──────────────────────────────────────────────────────────────────────────┘ │
│ #   DESCRIPTION                              QTY    PRICE, USD   AMOUNT, USD │ items
│ 1   CRONE · Billable                                                         │  even rows shaded
│     IT services in accordance to …         80.00 h       45.00       3600.00 │
│                                                  SUBTOTAL / ADJUSTMENT …     │ only with an adjustment
│                                            TOTAL                 5213.00 USD │
│ ┌ PAYMENT DETAILS ───────────────────┬ ACT OF ACCEPTANCE ──────────────────┐ │ card 50 | 50 %
│ │ Beneficiary   Myrchuk Mykola       │ Payment of this invoice confirms …  │ │
│ │ …                                  │ ─────────────────────────────────── │ │
│ │ Currency      USD                  │ OTHER NOTES          (kind `other`) │ │
│ │ Purpose       Payment for IT …     │                                     │ │
│ │ ────────────────────────────────── │                                     │ │
│ │ PAYMENT NOTE       (kind `payment`)│                                     │ │
│ └────────────────────────────────────┴─────────────────────────────────────┘ │
│ (signature image)                                                            │
│                                                                Page 1 of 1   │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Head** (`v3/head`):
- Left: `.doc-title` (the title, upper-cased by CSS), then `.doc-number-line`: `No.` / `№` in DejaVu Sans Mono and the
  number in `PlexMonoSemi` at 11.5 pt.
- Right: the ФОП's name (`.party-name-lg`), then the role ("Individual Entrepreneur" / "фізична особа-підприємець",
  as a label), the address lines, and **one** contacts line. The contacts are the tax ID (or the passport, by
  `tax_id_kind`), the e-mail and the phone, each in `span.ci`, joined by `span.sp` (` | `).
- In the banded styles the head sits in a `.band`.

**Strip** (`v3/strip`):
- BILL TO: the client's name (`.party-name-lg`), its address lines (the lines, then `city zip`, then the country), and
  `VAT:` and `Tax ID:` rows when they are set.
- PERIOD: two lines, `{start} –` and `{end}`, each `nowrap`.
- ISSUED, and DUE when there is a due date: each a label with a mono value.
- CONTRACT: its own row across the full width, with a top border. It reads `{name} No. {number}`, the number part only
  when the contract has one, then `dated {started_at}`. It appears only when `showContract`.

**Items** (`v3/items`):
- Columns: `#`, Description, Qty, `Price, {cur}`, `Amount, {cur}`.
- The description has the line `{project_code} · {charge_type}` above it, in the secondary colour, when either is set.
- Qty is the quantity followed by its unit (`h` / `fixed` / `pcs`, or `год` / `фікс.` / `шт.`).
- The number, quantity, price and amount columns are as wide as their longest value, or as the longest word of their
  header, in the style's header font, since a header may wrap. The description takes the rest.
- Even rows are shaded in every style.
- With a non-zero adjustment, `table.subtotals` (Subtotal, then the adjustment's own label or "Adjustment") sits 8 pt
  above the total. The total bar is one row: the label and `{amount} {currency}`.

**Card** (`v3/card`):
- Left, PAYMENT DETAILS: a key–value grid of beneficiary, bank, IBAN, account number and SWIFT / BIC (when set), the
  account's own details as they were typed, the currency, and finally Purpose (the payment purpose). The keys share
  one measured width. A note of kind `payment` follows as a `.card-section` titled PAYMENT NOTE.
- Right: ACT OF ACCEPTANCE when the act note is on, then OTHER NOTES for a note of kind `other` (a `.card-section` when
  the act is shown too).
- With nothing on the right, the details take the card's full width.
- Notes print as typed, line breaks kept. Nothing prints under the card except the signature.

**Signature:** the profile's signature image, when it has one, 36 pt high, 14 pt under the card.

**Pages:**
- The page margins are `30pt 0`. The root `.inv` is pulled up by them (`margin-top: -30pt`, padding `30pt 30pt 0`,
  height `781.89pt`), so that bands and decoration reach the page's edges.
- The decoration is drawn first, in a page-sized `.frame`, so it stays on page 1. Continuation pages keep the
  margins.
- A 25-item invoice runs to 3 pages.

**Page numbers:**
- Every page reads "Page 1 of 2" / "Сторінка 1 з 2" at 7 pt. The label is in the pager's font and the numbers are in
  DejaVu Sans Mono.
- They are right-aligned 30 pt from the page's edge. The height and the colour come from the style's `.pager` rules.
- They are drawn by `numberPages()` with dompdf's `page_script()` after the render, once the total is known.

---

## 5. Styles

Catalogue order: the picker's grid and the preview's arrows follow it. The SPA keeps the same list in
`utils/invoiceStyles.ts`, which `InvoiceStyleCatalogueTest` holds equal to the enum.

| Key | Name | Group | Root class | Decoration | Fonts beyond DejaVu Sans |
|---|---|---|---|---|---|
| `outlined` *(built-in default)* | Outlined | classic | `s-outlined` | double rule on top, rule at the foot | DejaVu Serif |
| `simplified` | Simplified | classic | `s-simplified` | heavy rule under the head; strip not boxed | — |
| `colored` | Colored | classic | `s-colored` | blue head band, foot band | — |
| `vivid` | Vivid | classic | `s-vivid` | top bar | — |
| `monochrome` | Monochrome | classic | `s-mono` | — | — |
| `freeform` | Free-form | geometric | `s-free` | six shapes (circles, ring, square, dot) | Unbounded |
| `freeform_mono` | Free-form Mono | geometric | `s-ffmono` | six shapes, monochrome | Unbounded |
| `freeform_facets` | Facets | geometric | `s-fffacets` | SVG layer | Unbounded |
| `freeform_blocks` | Blocks | geometric | `s-ffblocks` | SVG layer | Unbounded |
| `andriichello` | Andriichello | andriichello | `s-andrii` | charcoal head band, monogram, SVG contribution grid | Plex Mono |
| `andriichello_schema` | Schema | andriichello | `s-schema` | navy head band with a grid (SVG layer) | Plex Mono |
| `andriichello_sakura` | Sakura | andriichello | `s-sakura` | blossoms (SVG layer) | DejaVu Serif |
| `andriichello_carpathians` | Carpathians | andriichello | `s-carp` | forest head band with ridges (SVG layer) | Unbounded |

The group labels are Класичні / Classic, Геометрія / Geometric and Andriichello. The style names are not translated.

`InvoicePdfRenderer::layout()` gives each style's structure, which the template follows:

| Flag | Styles |
|---|---|
| `banded`: head in a `.band` | colored, andriichello, andriichello_schema, andriichello_carpathians |
| `wrapped`: content in a `.content` box over the decoration | all but outlined, simplified, colored |
| `ruleUnderHead` | all but outlined, simplified, colored, andriichello |
| `ruleHead` / `ruleDouble` | simplified / outlined |
| `boxed`: the strip in a box | all but simplified |
| `mark`: the monogram in the title | andriichello |
| `frame`: elements of the decoration | outlined `rule-foot` · colored `foot-band` · vivid `topbar` · freeform, freeform_mono `shape c1 c2 c3 ring sq dot` |
| `layer`: `resources/invoice-decor/{key}.svg` as a data URI | the six styles that have the file |

**Monogram** (andriichello): the first letters of the second and the first word of the ФОП's name in the invoice's
language, upper-cased (`Prykhodko Andrii` → `AP`, `Приходько Андрій` → `АП`). A name of one word gives its first two
letters.

**Unknown style:** a stored key that the enum no longer has renders as `outlined` and logs a warning. Saving an
unknown key at any level is a 422.

**Adding a style:**
1. Add an enum case with its `label()`, `group()` and `cssClass()`, and `resources/invoice-styles/{key}.css`.
2. Add its entries in `layout()`, and in `headerFont()` and `families()` when it uses a font beyond DejaVu.
3. Add the decoration SVG when it has one.
4. Add the style to `utils/invoiceStyles.ts`, then run `invoice:style-thumbnails --style={key}`.

---

## 6. Fonts

| Family | `normal` | `600` | `bold` | Used for |
|---|---|---|---|---|
| DejaVu Sans, DejaVu Sans Mono, DejaVu Serif | bundled with dompdf | — | dompdf's | text, mono values (dates, amounts, account numbers), the serif of Outlined and Sakura; all Cyrillic |
| `PlexMonoSemi` | IBM Plex Mono SemiBold | — | — | the invoice number, in every style |
| `Plex Mono` | IBM Plex Mono Regular | SemiBold | SemiBold | andriichello, andriichello_schema |
| `Unbounded` | Unbounded SemiBold | SemiBold | Bold | the geometric styles, andriichello_carpathians |

- The TTFs are static (not variable) and licensed under SIL OFL 1.1; the licences sit beside them in
  `resources/fonts/`.
- They are installed into dompdf's font directory (`storage/fonts/prytax_{family}_{weight}.{ttf,ufm}`) by
  `installedMetrics()` rather than declared with `@font-face`. That way they measure before any PDF has used them.
  A font is installed again when its TTF is newer than the installed copy.
- The installed metrics carry a `FontHeightOffset`, so that a line of Plex Mono (height 1300) or Unbounded (1240) is
  as high as one of DejaVu (1164). `PlexMonoSemi` keeps its own height, since the number shares its line with DejaVu,
  as it does in browsers.
- `storage/fonts/` must be writable wherever PDFs are rendered.

---

## 7. Texts, dates, money

Labels (`invoice.labels.*`) are written in normal case. CSS upper-cases the title and the section labels, so `uk` gets
`ІНВОЙС` and `ПЛАТНИК` without separate strings. The built-in payment purpose and act note (`invoice.defaults.*`)
are in `design.md` §4.1.

| Key | en | uk |
|---|---|---|
| `title` · `number` | Invoice · No. | Інвойс · № |
| `issued` · `due` | Issued · Due | Дата · Сплатити до |
| `entrepreneur` | Individual Entrepreneur | фізична особа-підприємець |
| `tax_id` · `passport` | Tax ID · Passport | РНОКПП · Паспорт |
| `bill_to` · `vat` · `client_tax_id` | Bill to · VAT · Tax ID | Платник · VAT · Податковий номер |
| `period` · `contract` · `contract_dated` | Period · Contract · dated :date | Період · Договір · від :date |
| `col_no` · `col_desc` · `col_qty` | # · Description · Qty | # · Опис · К-сть |
| `col_price` · `col_amount` | Price, :cur · Amount, :cur | Ціна, :cur · Сума, :cur |
| `subtotal` · `adjustment` · `total` | Subtotal · Adjustment · Total | Проміжний підсумок · Коригування · Разом |
| `unit.hour` · `unit.fixed` · `unit.item` | h · fixed · pcs | год · фікс. · шт. |
| `payment_details` · `purpose_short` | Payment details · Purpose | Платіжні реквізити · Призначення |
| `beneficiary` · `bank` · `iban` · `account_number` · `swift` · `currency` | Beneficiary · Bank · IBAN · Account number · SWIFT / BIC · Currency | Отримувач · Банк · IBAN · Номер рахунку · SWIFT / BIC · Валюта |
| `act_of_acceptance` | Act of acceptance | Акт приймання-передачі |
| `payment_note` · `other_notes` | Payment note · Other notes | Примітка до оплати · Інші примітки |
| `page_of` | Page :page of :total | Сторінка :page з :total |

- **Dates** (`DocumentDate`, shared by the PDF and `PaymentPurposeRenderer`): `en` is `M j, Y` (`Oct 31, 2026`); `uk`
  is `j F Y`, with the month in the genitive (`31 жовтня 2026`). The SPA's `documentDate()` in
  `utils/invoiceDocument.ts` writes them the same way, through `Intl`, for live previews.
- **Money:** in the snapshot's `money_format` (`MoneyFormatter`). Amounts appear without a currency, since the column
  headers name it; the total reads `5213.00 USD`.
- **Typed text** (descriptions, the contract's name, notes, a custom payment purpose or act note) prints exactly as
  typed and is never translated.

---

## 8. Style preview and thumbnails

**Preview:** `GET /api/profiles/{profile}/invoices/style-preview` returns `application/pdf`, inline, with `no-store`.
- **Parameters:** `style` (required), and optionally `locale`, `client_id` and `invoice_id`.
- **Errors:** an unknown style, or a client or invoice of another profile, is a 422.
- **Access:** the same as listing invoices (`viewAny`).
- **Data** (`InvoiceStylePreview`): the given invoice when it is a generated one; else the client's latest
  non-cancelled generated invoice; else the ФОП's. Its snapshot is built in memory as a freeze would build it now,
  with the asked style and language, and its number or the preview number. Every other setting resolves as usual.
  The PDF is rendered with v3 and nothing is saved.
- **Without any invoice:** `PreviewInvoiceFactory` makes a demo invoice. It is the fixture of the reference renders:
  BRIT-2026-10-31-2 to Bring IT LLC, three items in USD through Payoneer, with the contract, the act and another note.
  Its ФОП is Myrchuk Mykola, or Prykhodko Andrii in the Andriichello group.
- **In the SPA:** `InvoiceStylePreview.vue` draws page 1 with `pdfjs-dist` (6.4.299, a lazily loaded chunk), so the
  server needs no rasterizer. "Відкрити PDF" opens the blob in a new tab.

**Thumbnails:** `php artisan invoice:style-thumbnails [--style=key]`.
- It renders the demo invoice in every style and language with the real renderer.
- It rasterizes page 1 to 316 px wide with `pdftoppm` (poppler-utils; needed in development only), into
  `public/images/invoice-styles/{key}-{locale}.png`. The 26 files are committed.
- The picker shows them in the invoice language of the level being edited.
- Run it again after a change to a style, the template or the fonts.

---

## 9. Tests and checks

- **`InvoicePdfRendererTest`:**
  - template choice: schema 1 → v1; a new render records v3; a `v2` file renders again with v3; schema 2 renders as
    `outlined`, with its footer note as another note;
  - the snapshot's money format;
  - the v3 layout; alternating `odd` / `even` rows; the root class of each of the 13 styles; an unknown style as
    `outlined`, logged;
  - the note: `payment` under the details, `other` under the act, `other` alone on the right; the details at full
    width without a right side; the contract section left out when it is off or there is no contract;
  - the monogram; Ukrainian labels, units and dates; the item columns fitted to their longest values;
  - the fonts embedded per style.
- **Elsewhere:**
  - `InvoiceStyleCatalogueTest`: the SPA's catalogue equals the enum.
  - The invoice API tests: the style and note validation, the preview on the client's latest invoice and on the demo
    invoice, and the preview's 422s.
  - `InvoiceSettingsResolverTest`: the style and the note at each level, with the note read whole from the level
    that owns it. The invoice API test of the freeze checks the snapshot's `presentation`, with no `footer_note`.
  - `InvoiceNoteMigrationTest`: the ФОП's footer note moved into its note.
- **No golden PDF files yet.** `design.md` §13 and `accounting.md` ask for them per schema version and language, and
  they are still to do. Until then, compare page 1 by eye with Claude Design's targets
  (`storage/handoff/invoice-styles-handoff/pdf/refs/{style}-{en,uk}.png`, at 110 dpi):
  `pdftoppm -r 110 -png -f 1 -l 1 <file>.pdf out`. On 2026-10-24 all 26 matched within 1–3 px.
- `pdffonts <file>.pdf` lists the subsets: `IBMPlexMono-SmBld` (the number) everywhere, Plex Mono in the andriichello
  styles, Unbounded in the geometric ones.

---

## 10. Deploying

- Run the migrations: `2026_10_24_204757_add_invoice_style_and_note_to_clients_and_invoices` moves the old footer
  notes (`clients.invoice_footer_note`, the `invoice.footer_note` setting) into each level's note, switched on, kind
  `other`, and drops them.
- `storage/fonts/` must be writable.
- `pdftoppm` is needed only to draw the thumbnails again, and those are committed.

## 11. Not built yet

- The bilingual template (`en_uk`), and custom texts per language.
- Golden PDF files (§9).
- E-mailing invoices, and separate signed acts of acceptance.
