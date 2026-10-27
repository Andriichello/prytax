{!! $manifest['format'] !!} · format {{ $manifest['format_version'] }} · {{ $manifest['snapshot_at'] }}

УКРАЇНСЬКОЮ
===========

Це резервна копія даних одного ФОП з PryTax на момент {{ $manifest['snapshot_at'] }} (UTC).
@if (!$manifest['restorable'])
Це експорт за період ({{ $manifest['scope']['from'] ?? '' }} – {{ $manifest['scope']['to'] ?? '' }}), а не повна копія: з нього не можна відновити дані.
@endif

Що всередині:
- manifest.json — опис копії: коли зроблена, скільки рядків у кожному файлі, контрольні суми та підсумки
  (доходи за кварталами, суми інвойсів, сплачені податки).
- schema.json — опис кожної таблиці та її колонок.
- data/*.csv — по одному файлу на таблицю, рядки впорядковані за id. data/core/ — налаштування, лічильники
  нумерації, файли (і журнал змін, якщо його включено); data/reference/ — довідники, на які посилаються дані
  (податкові правила, курси НБУ, користувачі).
- documents/ — PDF інвойсів, завантажені документи та підпис (якщо їх включено): шлях кожного вказано
  в колонці archive_path файлу data/core/files.csv.

Формат CSV: UTF-8 з BOM, кома як роздільник, лапки "…" для значень з комою, лапками чи переносом рядка.
Порожнє поле без лапок — немає значення (NULL); "" — порожній текст. Суми — з крапкою, без пробілів.
Дати — РРРР-ММ-ДД; моменти часу — UTC у форматі 2026-10-27T02:37:11Z. Так/ні — 1/0.

Як відкрити в Excel: Дані → З тексту/CSV, кодування UTF-8, роздільник «кома». Якщо просто відкрити файл
подвійним клацанням, Excel з українськими налаштуваннями чекає «;» і покаже все в одній колонці.

Копія містить персональні дані (РНОКПП, IBAN, адреси) у відкритому вигляді: зберігайте її в безпечному місці.

IN ENGLISH
==========

This is a backup of one sole proprietor's (ФОП) data in PryTax as of {{ $manifest['snapshot_at'] }} (UTC).
@if (!$manifest['restorable'])
It is an export of a period ({{ $manifest['scope']['from'] ?? '' }} – {{ $manifest['scope']['to'] ?? '' }}), not a full backup: it can't be restored.
@endif

Contents:
- manifest.json — what the backup is: when it was taken, the rows of each file, checksums and totals
  (income by quarter, invoice totals, taxes paid).
- schema.json — every table and its columns.
- data/*.csv — one file per table, rows ordered by id. data/core/ — settings, numbering counters, files (and the
  audit log, when included); data/reference/ — the reference rows the data points to (tax rules, NBU rates, users).
- documents/ — invoice PDFs, uploaded documents and the signature (when included): the archive_path column of
  data/core/files.csv names each one.

CSV: UTF-8 with a BOM, comma-separated, "…" quoting for values with a comma, a quote or a line break. An empty
unquoted field is NULL; "" is an empty string. Amounts with a dot and no grouping. Dates YYYY-MM-DD; points in time
in UTC as 2026-10-27T02:37:11Z. Booleans 1/0.

Opening in Excel: Data → From Text/CSV, encoding UTF-8, delimiter comma. Excel in locales that expect ";" shows a
double-clicked file in one column.

The backup holds personal data (tax id, IBANs, addresses) in plain text: keep it somewhere safe.

Files:
@foreach ($manifest['files'] as $file)
- {!! $file['path'] !!}: {{ $file['rows'] }}
@endforeach
- documents/: {{ $manifest['documents']['count'] }}
