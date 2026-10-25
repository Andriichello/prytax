# PryTax: progress and handoff

Status as of the end of 2026-10-25: the «Minimal» redesign (design.md v4), its browser QA pass (ten fixes) and the
owner's decisions on it are **merged into `dev`** (fast-forward from `feat/minimal`, the user asked; `dev` is checked
out, 47 commits ahead of `origin/dev`, **not pushed**; `main` is unchanged); see "Minimal" and "QA of Minimal". Before
it, as of 2026-10-24, `main` = `dev` had everything: backend, frontend, the QA pass, its follow-ups (the user's
decisions, the frontend gaps, Ukrainian API messages, «Запам’ятати мене»), English and the SPA's translation keys,
and the invoice settings cascade with the 13 invoice styles. Read this file first when resuming, then `CLAUDE.md`,
`docs/specs/design.md` (v4: §14 is the Minimal phase, §16 its screens; `docs/specs/invoice.md` is the invoice PDF as
built; `docs/specs/suggestions.md` what is deferred or hidden). The current design handoff is «Minimal»:
`storage/handoff/MINIMAL_APP.md`, `storage/handoff/CHAT_LOG.md`, and the boards extracted in
`storage/handoff/prytax-minimal-handoff/` (`screens/png/NNN-M-*.png` by board number, `screens/html/M-*.html`,
`source/`). Its second handoff (2026-10-25, afternoon: Податки as one tax ledger) is `storage/handoff/latest/`
(`MINIMAL_DELTA_2026-10-25.md`, `CHAT_LOG_DELTA.md`), extracted in `storage/handoff/prytax-minimal-handoff-2/`; the
specs took it in on 2026-10-25 (design.md §8.5, §16.2, §16.5), and §14 steps 8–9 are built on branch
`feat/tax-ledger` (see "Tax ledger"). The earlier «Simplified» handoff is `storage/handoff/prytax-simplified-design-handoff.md` with
`storage/handoff/prytax-design-handoff/`. All of `storage/handoff/` is kept out of git, on disk only.

## Where things stand

| Phase | State |
|---|---|
| 1. Backend: migrations, models, services, API, tests | **Done and audited**, fast-forwarded into `main` |
| 2. Frontend: Vue screens of the "Simplified" design | **All 34 screens built**, merged into `dev` |
| 3. QA: specs review + browser flows per role | **Done and merged** (2026-10-21); see "QA pass" |
| 4. Follow-ups: decisions, frontend gaps, Ukrainian API, remember me | **Done and merged into `dev`** (2026-10-24); see "Follow-ups" |
| 5. English, then translation keys for more languages | **Done and merged into `dev`** (2026-10-24); see "English" |
| 6. Invoice settings cascade (design.md v3.3 §4.1) and the PDF's template v2 | **Done, merged and pushed** (2026-10-24); see "Invoice document" |
| 7. Invoice styles (13 PDF styles, template v3), the note in the cascade | **Done, merged and pushed** (2026-10-24); see "Invoice styles" |
| 8. «Minimal» (design.md v4): one ФОП per session, accordion forms, two notes, payments without an invoice, ЄП/ВЗ per receipt | **Merged into `dev`** (2026-10-25), not pushed; see "Minimal" |
| 9. QA of «Minimal»: every flow in the browser as owner, accountant and viewer, uk and en | **Merged into `dev`** (2026-10-25), 10 fixes + the owner's decisions; see "QA of Minimal" |
| 10. The second Minimal handoff: Податки as one tax ledger; ЄП, ВЗ, ЄСВ and ФОП untranslated in English | **Built on `feat/tax-ledger`** (2026-10-25), not merged, not pushed; see "Tax ledger" |

- **Branches:** `main` = `origin/main` = `origin/dev` = backend + frontend + QA pass + follow-ups + English +
  translation keys + the invoice settings and styles (pushed on 2026-10-24). **`dev`** (checked out) = that + the
  Minimal phase (specs, backend, SPA, demo, notes, the QA fixes, the owner's decisions; `git log --oneline
  origin/dev..dev`), fast-forwarded from `feat/minimal` on 2026-10-25 when the user asked; **not pushed**, and `main`
  not updated. The local `feat/minimal` and `feat/invoice-v2` branches are merged and still there. The remote
  `origin` is `github.com/Andriichello/prytax` (added by the user on 2026-10-24; `main` and `dev` track it); push
  only when the user asks.
- **api-core's `feat/remember-login`** (`remember` on `auth/login`, the `remember_token` migration, docs, tests; all
  658 api-core tests passed) is fast-forwarded into its `dev` (2026-10-24, the user asked; the branch was deleted),
  which is checked out in `/var/www/api-core`; its `main` = `dev`, both pushed to `origin`. PryTax runs
  api-core's working tree (path repository, symlinked), so keep api-core on `dev`. api-core's commits run GrumPHP
  (phpcs line length 120, phpmd: no boolean flag arguments, phpstan).
- `php8.5 artisan test --compact`: **413 tests**, all passing on 2026-10-25 on `feat/tax-ledger` (about 130 s, on MySQL `prytax_test`). Run
  it with a generous timeout (or in the background); two runs at once deadlock each other (so parallel agents must
  not run it). The frontend: `npm test` (Vitest, **563 tests**, incl. the translation guard
  `resources/js/i18n/i18n.test.ts`), `npm run typecheck` (clean), `npm run build`, all with Node 24 (below).
- **PHP and Node:** the system `php` is 8.4 (set for another project; leave it), but PryTax's vendor needs 8.5: run
  the server, artisan and tests with `php8.5`. The system `node` is 18, which can't run Vite/Vitest: prefix
  `PATH=~/.nvm/versions/node/v24.21.0/bin:$PATH` for `npm test`, `npm run typecheck`, `npm run build` and the browser
  scripts.
- The dev database `prytax` was rebuilt with the Minimal demo data on 2026-10-25 (`migrate:fresh --seed` + the
  `DemoSeeder`), and the roles seeder ran after the new `issue` permission. NBU rates get cached by previews (writes
  on read). `migrate:fresh` leaves old seeds' files in `storage/app/private/files` (orphans; harmless).
- The user asked for commits in logical parts, and (2026-10-24) for no `Co-Authored-By` Claude trailer in them.

### First steps of the next session

1. Ask the user whether to merge `feat/tax-ledger` into `dev`, push `dev` and fast-forward `main` (and delete the
   merged branches); don't push unasked. If ngrok is still set up in `.env` (see "Tax ledger"), ask whether to undo it.
   Then what is left: the open points of "QA of Minimal" (ask the user), docs/specs/suggestions.md §1 (kopeck
   remainders of ЄП/ВЗ per receipt, Appendix A, B2, B5, golden PDFs), XLSX exports and the yearly PDF summary (need a
   package, so approval), pixel polish against the boards, `SheetImporter`.
2. At the end of 2026-10-25 the dev server (8010, `php8.5`) and a headless Chrome (9223) were left running; check with
   `ss -ltnp | grep -E ':8010|:9223'` and start them if they're gone (commands under "Frontend" and "Checking the UI").
   `npm run build` after SPA changes; sign in as `olena@example.com` / `password` (the UI language is Ukrainian).
   Leave port 8000 (`/var/www/count-api`), the user's own Chrome and the `boost:mcp` servers alone.
3. Helper scripts kept on disk in `storage/handoff/tools/` (git-ignored): `act.mjs` (the browser step runner, see
   "Checking the UI"; it logs every API answer of 400 or more, and has `upload`, `url`, `clear-cookies`, and `tap X
   Y` for a real hit-tested tap), `i18n.py` (edits `i18n/uk` and `i18n/en` together: `rm AREA PATH`, `add AREA PARENT KEY UK EN`,
   `set AREA PATH UK EN`, values as TS literals), `i18n-prune.sh` (removes the keys the guard reports unused, then
   reruns it), `htmltext.py` (the text of a board's HTML: `python3 -I storage/handoff/tools/htmltext.py
   storage/handoff/prytax-minimal-handoff/screens/html/M-Taxes.html`).

## Tax ledger (2026-10-25, branch `feat/tax-ledger`)

The second Minimal handoff (`storage/handoff/latest/`, extracted in `storage/handoff/prytax-minimal-handoff-2/`) and
the owner's note that English keeps ЄП, ВЗ, ЄСВ and ФОП: specs first (design.md §8.5, §16.2, §16.5, §13, §14 steps
8–9), then the build, one commit per part, all on `feat/tax-ledger` from `dev` (not merged, not pushed).
- **Backend:** `Services/Taxes/TaxLedger` (obligations computed: a receipt's ЄП/ВЗ while it has no payment that
  didn't fail; ЄСВ per due month of every started quarter, the months ahead included, minus its non-failed payments;
  merged with the tax payments; the summary by the tax's quarter, the months by placement); `GET
  taxes/{year}/ledger?quarter=&tax_type=&status=` (lists as arrays or comma-separated; `TaxLedgerRequest`).
- **SPA:** `TaxesPage` rewritten (three badges, `TaxFilterSheet`, `TaxSummaryCard` with `TaxBar` / `TaxFigures`,
  `TaxLedgerMonth` / `TaxLedgerRow`); `utils/taxLedger.ts` (quarter-range taps, multi-selects, badges, URL
  `year`/`quarters=2-4|4|all`/`taxes`/`statuses`); new colour tokens (`sent-bar`, `tick`, `tick-on-ink`,
  `positive-on-ink`, `accent-line`, `accent-faint`); `TaxAccrualView`, `TaxHistoryView`, `amountsToPay` and
  `PeriodFilterSheet`'s `yearOnly` removed; old `?quarter=` links still open that quarter.
- **Decisions while building:** a receipt's ЄП/ВЗ is paid only by a payment that didn't fail (`receiptTaxPayment`):
  after a failed attempt the card and the receipt page show it unpaid, and the form records a new payment instead of
  opening the failed one (a fix, its own commit). ЄСВ rows read their quarter's deadline (19.01.2027), not the
  board's monthly ones. A payment without a date sits in the month its tax belongs to. Future quarters stay tappable
  in the drawer (the board has none disabled).
- **Step 9:** the English dictionaries, the API's English source strings (notifications, CSV headers, one validation
  message; `uk.json` re-keyed) and two tests write ЄП, ВЗ, ЄСВ and ФОП; «FOPs» became «ФОП» or «ФОП profiles». The
  guard allows these four words only, and fails on «single tax», «military levy», «SSC» or «FOP» in English. The
  invoice PDF keeps «Individual Entrepreneur».
- **Checks:** PHP suite 413 tests, `npm test` 560, `npm run typecheck`, `npm run build`; Податки and its drawer driven
  in headless Chrome in English (the signed-in user's saved language is `en`, and the dev database holds the user's
  records, so it wasn't switched to check Ukrainian).
- **Back keeps the place (the user's request, 2026-10-25):** going back to Інвойси showed a reload, and Податки lost
  its scroll (it wasn't kept alive, so it remounted empty and loaded again). Now `TaxesPage` is in `App.vue`'s
  `KEPT_ALIVE` with its filter frozen while hidden (as `useInvoiceFilters`), and `useLoader` / `usePagedList` load
  again on return only when `dataVersion` (`api/client.ts`: accepted writes, but `…/preview` and `…/propose`) moved.
  Checked over ngrok with 600 ms latency: no API calls on return, scroll kept; `composables/useLoader.test.ts` mounts
  a KeepAlive with an in-memory renderer (no DOM package). Інвойси's lost scroll didn't reproduce in desktop Chrome.
- **ngrok (2026-10-25, the user asked, to test from a phone):** `ngrok http 8010` runs in the background
  (`https://23dd-91-247-39-213.ngrok-free.app`); `.env` got `ASSET_URL` = that URL and the host appended to
  `SANCTUM_STATEFUL_DOMAINS` (the backup is in that session's scratchpad). While `ASSET_URL` is set, localhost loads
  its assets through the tunnel too: remove both when the tunnel stops.

## Minimal (2026-10-25, branch `feat/minimal`)

Claude Design's «Minimal» handoff (`storage/handoff/prytax-minimal-handoff.zip`, `MINIMAL_APP.md`, `CHAT_LOG.md`, out
of git) became design.md v4 (§14 lists the phase, §16 the screens) with the owner's answers of 2026-10-25 (no real
data, so options were dropped and the demo reseeded; ЄСВ due with its quarter; no ЄСВ exempt marking; tax payments
from a USD account carry a hand-typed original amount; one ФОП at a time, accountants switch by links; kopeck
remainders of per-receipt ЄП/ВЗ skipped for now). docs/specs/suggestions.md was rewritten (§1 deferred, §2 hidden).

- **Backend:** options removed (`accounts.limit_window/limit_counts`, `income.taxable_base`, `taxes.default_grouping`),
  `taxes.payment_account_ids`; two notes (payment and other) instead of one; virtual invoices
  (`invoices.source = virtual`, `VirtualInvoices`, `POST payments` with `virtual_invoice`, `POST invoices/{id}/issue`,
  the status filter `virtual`); tax payments: ЄП/ВЗ only per receipt at its amounts (one per tax), ЄСВ by month,
  `original_amount/original_currency` from a foreign-currency account; ЄСВ exempt marking removed.
- **SPA:** the static top bar and sticky root headers (filter badges, `PeriodFilterSheet`); removed (their routes
  redirect, names kept): the ФОП switcher sheet, Огляд практики, ЄСВ, Книга доходів and the declaration page;
  accordion forms (`AccordionGroup`, `ReadRow`, `Dimmable`, `SwitchCard`; one group open, each saving its own fields)
  for Документ інвойсу, Рахунок, Клієнт, the invoice editor (the draft saves per group), Профіль, Нумерація,
  Податки й сповіщення (payment accounts with routes) and Договір; Інвойс для надходження, the receipt form without
  an invoice, the «Без інвойсу» record (issue or attach); receipt cards (`ReceiptTaxCard`, `MonthCard`); Облік (the
  quarter's summary, receipts by month / invoice / none, fees on demand); Податки (Нарахування | Історія сплат);
  Сплата ЄП / ВЗ (`tax-payment(-new)`) and Сплата ЄСВ (`esv-payment(-new)`); Головна (two quarters, every status);
  the transfer form's tappable balance. The refund action is gone from the receipt page.
- **Decisions made while building** (beyond the specs): the receipt form without an invoice hides the client's fee;
  «Прив’язати» offers the record's client's issued invoices only (noted in design.md §4.2); a tax payment's form is
  `tax-payment-new?payment=&tax=` / `tax-payment/{id}` for ЄП/ВЗ and `esv-payment-new?year=&month=|quarter=` /
  `esv-payment/{id}` for ЄСВ; Податки in «Історія сплат» lists the payments by `paid_at` (`?mode=history`); Облік
  keeps its CSV exports (no XLSX: a package needs approval); the opening balances save as a group (unreadable amounts
  stop it before any request); Головна also lists drafts without an issue date by their period's end.
- **Checks:** PHP suite (404 tests), `npm test` (529), `npm run typecheck`, `npm run build`, and every screen driven in
  the browser against its board. The translation guard (`resources/js/i18n/i18n.test.ts`) fails on unused keys and on
  Cyrillic in English texts, but for ЄП, ВЗ, ЄСВ and ФОП, which English keeps as they are (since step 9): run
  `storage/handoff/tools/i18n-prune.sh` after removing UI.

## QA of Minimal (2026-10-25, branch `feat/minimal`)

Every screen of §16.5 swept as owner, accountant and viewer (no errors; forms send the viewer to the record), then the
flows driven in headless Chrome with the network watched: invoice by accordion groups → freeze → PDF → sent → receipt
with a platform fee → ЄП paid from UAH, ВЗ sent from the USD account (original amount, bank rate) → paid; receipt
without an invoice → record → issue as invoice; attach Kestrel's advance to KS-2026-10-15-7; ЄСВ for a month; transfer
with the tapped balance and FIFO allocations; every settings accordion (save, cancel, 422 inline); style picker and
pdf.js preview; client cascade (language, note) into a new invoice; filters of Інвойси, Облік, Податки; notifications;
external PDF with the duplicate check, return to draft, delete; cancel refused with receipts; client create with a
duplicate abbreviation; sign-in errors; English. The demo was reseeded afterwards. Fixed (one commit each):

- `payments/preview` of an edited payment without an invoice was a **500** (`PaymentRecorder::reopens()` read a null
  coverage; a virtual invoice never reopens). Test `testPreviewsAnEditOfAPaymentWithoutAnInvoice`.
- Deleting such a receipt warned «Інвойс повернеться до статусу «Готовий»»; it now says the record goes with it.
- Viewers saw «Сплатити ЄП і ВЗ…» and unpaid tax rows linking to forms they can't open; English tax labels overflowed
  the 44 px column («Military1 643.79») and the pay row read «Pay the Military levy» (now a sentence per tax).
- The language select of a client/invoice offered «Власне» as its inherit option (now «Як у ФОП: English»).
- Accountants couldn't upload the PDF signature (it sat in the owner-only «Інвойси» group of Профіль ФОП).
- Нумерація: a counter moved back got the API's «уже на 9» while the screen shows 8 (last issued); told locally now.
- The invoice editor asked `invoices/defaults` with the previous client's contract (a swallowed 422); item cards
  showed typed `12,5 × 50` until reloaded.
- Податки showed 0.00 everywhere for an untracked quarter (Коваленко's 2025: the opening balances of 01.01.2026 start
  tracking); it says «не відстежуються» now.
- Signing out re-mounted the screen without a user (`App.vue`'s `KeepAlive` key) and fired 401/404 requests; a
  screen for signed-in users now renders only with a user.

The user's decisions on the open points (2026-10-25, done, specs updated: design.md decision 14, §4, §8.4, §16.4,
§16.5; suggestions.md §2):
- **No pay row:** «Сплатити ЄП і ВЗ за цим надходженням» is removed for now; ЄП and ВЗ are paid one by one from their
  rows (`ReceiptTaxCard` lost `payRow`; restore it from git history).
- **No refunds** is fine: the cancel sheet and the API's refusal only ask to move the receipts.
- **An uploaded PDF goes only when replaced or deleted:** `InvoiceFileController` deletes the uploaded primary that a
  new `primary=1` upload replaces; an external draft's form deletes its PDF (✕, confirmed); a return to the draft keeps
  it (it always did; the sheet now says so). Test `testDeletesTheUploadedPdfOfADraftOnlyWhenReplacedOrDeleted`.

Still open (not changed):
- ВЗ of a quarter (1 % of the quarter) and Σ per receipt differ by a kopeck (suggestions.md §1, known).
- «Прив’язати» offers the client's paid invoices with 0.00 left too (attaching overpays them).
- Облік's summary of an untracked quarter shows ЄСВ 0.00 owed with 5 280.00 paid; Податки now says «не відстежуються».
- Minor: a field's API error stays until the next save (app-wide); the ЄСВ month stepper doesn't update the URL;
  «Без інвойсу» appears in the status filter only while such a record exists (facets).

## QA pass (2026-10-21, branch `dev-qa-fixes`)

Five read-only reviews compared the frontend with design.md, accounting.md, the handoff and the mocks (about 100
findings); four agents fixed them in separate worktrees (cherry-picked onto the branch), and the app was then driven
in headless Chrome as owner, accountant and viewer. Fixed, among others: inviting users (api-core rejected
`locale: 'uk'`) and the missing `/accept-invitation` page; a transfer edit wiping its allocations; receipt edits
previewed as new receipts (`payments/preview` takes `payment_id`); deletes in declared quarters (the note goes as the
`correction_note` query); `corrected` declarations shown as done; tax debt counted twice across quarters
(`utils/taxes.ts`); the full РНОКПП in viewers' payment purpose; lists cut at 50 (`api.all()`); stale counts, races
in previews, unsaved settings overwritten; an accountant's profile edits silently dropped. Added: one receipt for
several invoices, moving a receipt / linking an advance, refund delete and manual rate, account opening balances
and movements, the late-money note on Рахунки (`late_holdings`), a ФОП's closing date, ЄСВ months marked exempt,
`/change-password` (api-core's required update), `/no-profile`, a start-up error screen, route permissions
(`meta.can`: forms a role can't save send it to the record or the list), Ukrainian texts for every API and Laravel
validation message (`utils/errors.ts`).

Verified in the browser (all passing): every screen per role without errors or failed requests; invoice create →
freeze → PDF → sent → receipt → paid; refund and its delete; transfer with the exact-match proposal, edited without
losing allocations; tax payment of a quarter's own debt; declaration submitted → receipt in the locked quarter with a
note → "Потрібна уточнююча" + notification; invite → accept → sign in; external invoice with PDF, currency switch,
duplicate check; one wire for two invoices (pro-rata fee); account movement; ЄСВ exemption; reading a notification
lowers the bell; money format switch; accountant's locked identity fields; unfreeze keeps the number.

Decisions taken in the pass (tell the user if they disagree):
- The ISO code stays **after** the amount (`3 892.50 USD`, handoff §5 and the mocks; design §16 says before).
- Головна orders a month's invoices by the period's start, **latest first** (as the mock).
- Податки opens on `taxes.default_grouping` (default `payment`), so the demo ФОП open on "Надходження", not the
  mock's quarters. Δ in the reconciliation stays owed − hints (the two mocks disagree).
- The invoice editor asks with `window.confirm` before leaving unsaved changes.

Everything left open by the pass was settled in "Follow-ups" below.

## Follow-ups (2026-10-21, merged into `dev` on 2026-10-24)

The user decided: (b) a paid invoice returns to unpaid; Податки keeps the specs' default view (`payment`); add a fee
statistics screen; and go on with the rest. Done, each verified in tests and in headless Chrome:

- **A paid invoice, which a receipt change leaves uncovered, returns to `sent`** (or `ready`, when it was paid without
  being sent; `paid_at` cleared): deleting, reducing or moving a receipt, or a refund (`InvoiceLifecycle::
  reopenIfUncovered()`, inside the write's transaction; transitions `paid → sent|ready`). `payments/preview` names it
  in `reopens_invoice`; the receipt form, the delete sheet and the refund sheet warn first.
- **Комісії** (`/fees?year=`, from Ще → "Комісії й чистий дохід"): the year's income, fees, spread and net income, by
  quarter, account (with currency, linked), client (linked) and route, loss % coloured as on Перекази (`FeeRow`).
  `fees/{year}`'s groups now carry `currency`.
- **Back returns to the same place:** `App.vue` keeps `InvoicesPage` and `TransfersPage` alive (`KeepAlive`, keyed by
  the user); on return `usePagedList` re-reads the pages shown and `useLoader` reloads (both on `onActivated`); the
  invoice filters follow the URL only while the list is shown.
- **Links to another ФОП's record:** a bare record link opened from outside the app (first navigation) is looked up
  across the user's profiles (`useCurrentProfile().locate()`, routes' `meta.record`); `?profile=ID` on any path
  switches the profile and is dropped from the URL.
- **Dates are typed `дд.мм.рррр`** whatever the device's language (`DateField` is a text input, `parseDisplayDate()`
  takes `4.1.2026`, `14/10/2026`, `14102026`), with a calendar under a button; a wrong or out-of-range date is told
  after leaving the field. Since 2026-10-25 the calendar is the app's own `components/form/DatePickerSheet.vue` (the
  user found the native one ugly; no package): a bottom sheet with Monday-first weeks (`calendarWeeks()`,
  `weekdayNames()` in `utils/dates.ts`), today ringed, the chosen day filled, `min`/`max` disabled, a months view
  behind the title, «Сьогодні».
- **Vitest** (approved by the user; `vitest@5.0.3`, pinned to a release a few weeks old): `vitest.config.js` (alias
  only, not Vite's config), `npm test`. Installing with Node 22's npm crashed (arborist `edgesOut`); Node 24's npm 11
  worked (it also rewrote the lockfile's flags of optional platform packages, harmless).
- **Monthly taxes:** `taxes/{year}/months` spreads a quarter's own payments over its months, the earliest first (each
  takes what it still owes after its own payments; the last month keeps the rest; paid before sent), `from_quarter`
  per month; the view marks "за кв.".
- **Ukrainian API:** api-core's `locale` middleware on the `api` group (since 2026-10-24 `api-core.locales = ['uk',
  'en']` and the user's own `locale` decides, see "English"); requests without a user or a header stay English, so
  tests assert English; `lang/uk.json` (every `__()` of `app/` — `LocalizationTest` scans them —, the invitation and reset
  e-mails, Laravel's mail layout) and `lang/uk/{validation,auth,passwords}.php` (messages without the field's name,
  as they show under the field). `utils/errors.ts` now shows any Cyrillic message as is and translates only
  api-core's English-only messages (suspended account, required password update, invitation link, several
  organizations, throttling); other English falls back to a text by status. Write new messages through `__()` and add
  them to `lang/uk.json` (the test fails otherwise).
- **E-mail links follow `APP_URL`** (api-core's default): `.env.example` no longer sets the 5173 URLs; the local `.env`
  now has `APP_URL=http://localhost:8010` and `SANCTUM_STATEFUL_DOMAINS=localhost:8010,127.0.0.1:8010` (the old one
  is backed up only in that session's scratchpad). The invitation e-mail was checked: Ukrainian, link on 8010.
- **«Запам’ятати мене»** on Вхід (checked by default, as the mock): sends `remember`, which api-core's
  `feat/remember-login` branch turns into the remember cookie; the published migration
  `2026_10_21_010000_add_remember_token_to_users_table` adds `users.remember_token`. Verified: with it, a dropped
  session signs in again from the cookie; without it, back to Вхід.

## English (2026-10-24, merged into `dev`)

The user asked for English, switchable with Ukrainian. On 2026-10-24 (later, branch `feat/i18n-keys`, the user's
request "so that we can have more languages added later") the SPA moved from Ukrainian texts as keys to translation
keys; the API keeps Laravel's English-keyed `__()` (the user agreed: `lang/xx.json` already adds a language there).

- **SPA:** `resources/js/i18n/index.ts` — `t('invoices.editor.issueDate', { name })` looks the key up in the
  dictionary of the current language: `i18n/uk/*.ts` (the default language and the source of the keys) and
  `i18n/en/*.ts`, one file per area (`core`, `shell`, `invoices`, `money`, `accounting`, `clients`, `settings`), keys
  `area.screen.name` (shared words in `core.common`). Keys are typed (`MessageKey`, so vue-tsc rejects an unknown
  one), and each English file `satisfies Translation<typeof uk>` (vue-tsc rejects a missing or extra key). A key
  missing in a language falls back to Ukrainian. No package (vue-i18n would need approval). `t()` must run at render
  (computed, getters, templates), never in a frozen constant, since Інвойси and Перекази stay alive.
  `components/ui/Translated.vue` fills a translated sentence's `{name}` with slots (markup inside a sentence).
  Plurals are entries by the categories of `Intl.PluralRules` (`{ one, few, many }` in Ukrainian, `{ one, other }` in
  English), picked by `t(key, { count })` (`core.counts.*`). Month names come from `Intl` (`utils/dates.ts`), quarters
  (`core.quarters`, `core.quarterNames`), weekdays and short months from the dictionaries; statuses, kinds of
  accounts, `TAX_TYPES` (Single tax, Military levy, SSC) and country names follow the language; dates stay
  дд.мм.рррр. `utils/errors.ts` shows an API message when it is in the screens' language (`LETTERS`: a language with
  letters of its own, Ukrainian's Cyrillic, proves it), and translates api-core's English-only ones by key. The guard
  `i18n/i18n.test.ts` fails on Ukrainian outside the dictionaries (comments skipped; mark Ukrainian data
  `// i18n-ignore`, or a block with `i18n-ignore-start`/`-end`), on a key the dictionary lacks, a non-literal key, an
  unused key, a language missing a key or having an extra one, a lost placeholder, or a plural without exactly its
  language's forms.
- **Adding a language:** copy `i18n/en/` to `i18n/xx/` and translate it, add `xx` to `LANGUAGES` in `i18n/index.ts`
  (and to `LETTERS` in `utils/errors.ts`, when it has letters of its own), then the API side: `lang/xx.json`,
  `lang/xx/{validation,auth,passwords}.php`, `api-core.locales`, and `AlertCard`'s `OPENING_ACTIONS` (the API's
  "Open" in that language).
- **The language is the user's:** `users.locale` (`PATCH auth/me`, which `useSession().switchLocale()` sends), shown on
  Ще and on the sign-in screens (`LanguageSwitch`); before signing in, the device's last choice (localStorage
  `prytax.locale`), which the client sends as `Accept-Language`; a language chosen on the sign-in screen becomes the
  account's. A migration moved existing users from api-core's default `en` to `uk`; demo users are `uk`; an
  invitation goes in the inviter's language.
- **API:** English is the source of `__()`, Ukrainian is `lang/uk.json` (`LocalizationTest` scans `__()` and
  `trans_choice()`). Notifications: `AlertCollector` gives each a `texts` closure; `AlertSync` renders it in every
  locale into `alerts.texts` (the columns keep the Ukrainian ones); `AlertResource` returns the request's language
  (rows synced before fall back to the columns: run `prytax:sync-alerts`). CSV exports name their columns in the
  request's language.
- **Stays Ukrainian on purpose:** tax payments' purposes (the treasury's), an FX gain's stored description, the
  treasury recipient example and the ІПК consultation number on the tax settings.

## Invoice document (2026-10-24, branch `feat/invoice-v2`, merged)

The user updated `docs/specs` (design.md v3.3 §4.1, accounting.md, new `invoice.md`; committed on 2026-10-24 when
the user asked, with `invoice.md` rewritten to describe template v3 as built) and handed off the PDF layout in `storage/handoff/prytax-invoice-v3-handoff.md` (+ `invoice-v3-handoff/refs/`,
the target renders at 110 dpi). "v3" is the design iteration; in code it is template **v2**.

- **Cascade** (`Services/Invoices/InvoiceSettingsResolver`): language, payment purpose template, act note on/off and
  text, contract section — invoice → client → ФОП → built-in, null = inherit; each resolved setting is `{value,
  source, inherited}` (`effective_settings` appends on invoices and clients, `effective_invoice_settings` and
  `invoice_texts` — the built-in texts per language — on profiles). Departures from the spec: the ФОП's language is the
  existing `profiles.locale` (no `invoice.locale` setting; the owner changes it); `clients.currency` existed (the
  migration doesn't add it); the new-invoice form uses the existing `invoices/defaults` (it returns
  `effective_settings`) instead of `clients/{client}/invoice-defaults`; the footer note keeps its own client → ФОП
  fallback. A new invoice's currency falls back to `profiles.default_currency` (was `USD`).
- **Payment purpose** (`PaymentPurposeRenderer`): whitelisted placeholders (422 on save for others), `{contract.*}`
  without a value → 422 on freeze naming the template's level; `warnings` outside UAH (`payment_purpose_too_long` >
  140, `payment_purpose_charset` outside SWIFT X) plus `currency_differs_from_contract`. Dates: `Helpers/DocumentDate`
  (`M j, Y` / `d.m.Y`), shared with the PDF. The SPA mirrors it in `utils/invoiceDocument.ts` for live previews.
- **Snapshot v2** (`InvoiceFreezer`): `locale`, profile `name`/`address` and client `name` in it, contract `subject`,
  `presentation{payment_purpose, act_note (null when off), show_contract}`. Version-1 snapshots render with
  `pdf/invoice/v1.blade.php` (moved unchanged); `InvoicePdfRenderer::templateFor()` picks the template, files record
  `template_version` (`'v2'`; old ones have `1`).
- **Template v2** (`pdf/invoice/v2.blade.php` + `InvoiceDocument` view model): matches the four refs within a few px.
  dompdf quirks found: it ignores `<col>` widths and drops a cell's pt width in `table-layout: fixed`, so widths sit
  on the first row's cells (auto layout); it multiplies line heights by the font's height × `font_height_ratio`, so v2
  renders with `fontHeightRatio = 1000/1164` (lines as high as their CSS `line-height`, as in browsers; v1 keeps the
  default). Column widths are measured with dompdf's font metrics. The number is IBM Plex Mono SemiBold
  (`resources/fonts/IBMPlexMono-SemiBold.ttf`, OFL licence beside it), registered via `@font-face`; dompdf caches it in
  `storage/fonts/` (create it on deploy). No PDF golden files: tests assert the HTML, the template choice, the widths
  and the embedded font (`IBMPlexMono-SmBld`).
- **Screens:** `components/invoice/InvoiceDocumentFields.vue` on Клієнт ("Інвойси за замовчуванням", with the
  currency), the draft invoice (collapsed "Документ") and Більше → Документ інвойсу; Договір got its subject.
- Verified: 345 PHP tests, 414 Vitest tests, typecheck, build, a 42-screen sweep in uk and en, a client save in the
  browser (restored after).

## Invoice styles (2026-10-24, branch `feat/invoice-v2`, merged)

From Claude Design's `storage/handoff/prytax-invoice-styles-handoff.md` (+ `invoice-styles-handoff/`: screens, the
styles' reference HTML/CSS, decor SVGs, 110 dpi refs, thumbnails). The user decided: **template v2 dropped** (it never
left this branch) — v1 + v3 only; **`pdfjs-dist` 6.4.299 approved** (pinned, lazy chunk, only the style preview);
design.md §4.1 (and §3's columns) edited, committed with the user's spec edits.

- **Cascade:** `style` (`InvoiceStyle`, 13 cases in catalogue order, `label()`/`group()`/`cssClass()`; string columns,
  an unknown stored key renders as `outlined`) and the **note** (`note_enabled`/`note`/`note_kind` `payment|other`):
  the first level whose `note_enabled` is non-null owns the note whole (`InvoiceSettingsResolver::note()`); the
  `NoteTextRequired` rule (data-aware, implicit) wants its text when on. Columns `clients.invoice_style`,
  `invoice_note_enabled`, `invoice_note`, `invoice_note_kind`; `invoices.style`, `note_enabled`, `note`, `note_kind`;
  settings `invoice.style`, `invoice.note*`. The migration moved `clients.invoice_footer_note` and the
  `invoice.footer_note` setting into each level's note (on, `other`) and dropped them; today only one of them printed
  (client's ?? ФОП's), so no merging was needed. `clients.legal_name_uk` existed; the form edits it now.
- **Snapshot v3:** `presentation.style`, `note` (null when off), `note_kind`; no `footer_note`. Ukrainian document dates
  are month words (`31 жовтня 2026`, `DocumentDate` `j F Y`; the SPA's `documentDate()` the same via `Intl`).
- **Preview:** `GET profiles/{p}/invoices/style-preview?style=&locale=&client_id=&invoice_id=` (`InvoiceStylePreview`,
  viewAny of invoices): the given generated invoice, else the client's or the ФОП's latest non-cancelled one, snapshot
  built in memory with the asked style/locale, rendered with v3, nothing saved; no invoice → `PreviewInvoiceFactory`.
  The catalogue is a frontend constant (`utils/invoiceStyles.ts`), kept equal to the enum by `InvoiceStyleCatalogueTest`.
- **Template v3** (`pdf/invoice/v3.blade.php` + `v3/{decor,head,strip,items,card}`; CSS `resources/invoice-styles/`,
  decor SVGs `resources/invoice-decor/`; `InvoiceDocument` view model): matches the 26 refs within 1–3 px. dompdf
  findings: fonts beyond DejaVu (Plex Mono Regular/SemiBold, Unbounded SemiBold/Bold, static TTFs, OFL) are installed
  into dompdf's font dir by `InvoicePdfRenderer::installedMetrics()` (once, again when the TTF is newer), with a
  `FontHeightOffset` so their lines are as high as DejaVu's; weight 600 is its own weight in dompdf 3.1 (registered
  separately); page numbers via `page_script()` (the `.pager` rule gives colour/font/bottom); pages have 30 pt top and
  bottom margins and the root is pulled up so bands reach the edge, with all decoration in a page-sized frame drawn
  first (so it stays on page 1); decor SVGs need unitless sizes (php-svg-lib read pt as px). Thumbnails:
  `php artisan invoice:style-thumbnails` (needs `pdftoppm`, dev only) → `public/images/invoice-styles/` (committed).
  `storage/fonts/` must be writable on deploy.
- **SPA:** `InvoiceDocumentFields` (style row → `InvoiceStylePicker` → `InvoiceStylePreview`, both on
  `FullScreenSheet`; `PurposePlaceholderSheet` replaces the chips; note block), `TextAreaField` `autogrow` (2–8 rows,
  the placeholder counts), `useOverlay` (the page lock and Escape stack, shared by BottomSheet and FullScreenSheet),
  `api.blob()`. Client page "Документ інвойсу" card → form `#invoice-defaults`; Нумерація's note became a link row;
  Ще shows the ФОП's style; Профіль's language hint.
- Verified: 383 PHP tests, 439 Vitest tests, typecheck, build; a 42-screen sweep in uk and en; the picker, the
  pdf.js preview (arrows, language), the placeholder sheet and the note in headless Chrome (nothing saved).

## The audit (2026-10-20)

Six parallel reviews compared the backend with design.md, accounting.md, suggestions.md and the handoff, and every
finding was fixed with tests, except the items under "Not done yet". The user decided four questions:

- **Numbering**: one counter per ФОП by default (`invoice.numbering.scope = global`); per client stays a setting.
  *Superseded on 2026-10-23 (the user's request, design.md v3.2):* every client has an `abbreviation`, unique per
  profile, and numbers are `{abbreviation}-{date}-{n}` with the client's own counter by default (`BL-2026-10-18-13`;
  `invoice.numbering.prefix = client_abbreviation`, `scope = per_client`).
- **Manual exchange rates** live only on the record they were entered for (`rate_is_manual` on payments, refunds and
  transfers); `exchange_rates` caches NBU rates only.
- **ЄСВ paid above a quarter's minimum carries forward as credit** (design.md said "paid above minimum", not
  overpayment; the user chose the carry). The ЄСВ table takes its balances from the reconciler, so both agree.
- **Obligations are tracked from the later of the registration and the profile's first records** (`TaxTracking`).

## Decisions taken (and where the code departs from the spec)

1. **api-core's building blocks replace some tables of the spec** (CLAUDE.md requires api-core's base classes):
   - profile settings are api-core settings (`HasSettings` on `Profile`, `settings` table), not `profile_settings`;
     their changes are written to the audit log by `ProfileSettingController` (api-core's `Setting` isn't audited);
   - invoice files are api-core files (`HasFiles`, collections `generated` / `uploaded`, `signature` on profiles) with
     `invoices.primary_file_id`, not `invoice_files`;
   - invoice counters are api-core `Sequence`s owned by the profile (keys `invoices:global`, `invoices:client:{id}`,
     with `:{year}` for yearly resets), not `invoice_sequences`;
   - the activity log is api-core's `Audited` trait; `created_by_user_id` comes from `TracksCreator`. There is no
     `updated_by_user_id` column: the audit log records who changed what.
2. **Tenancy**: `profile_id` on domain tables, `BelongsToProfile` (global scope, fails closed), `CurrentProfile`
   (Laravel Context, like api-core's `CurrentOrganization`), `ResolveProfile` middleware (alias `profile`, ranked
   after api-core's middleware), and every profile controller extends `TenantController` with
   `$parents = ['profile', …]`. A profile of another organization, or another profile's record, is a plain 404
   (tested for every resource in `ProfileIsolationTest`).
3. **Notifications of a profile are `alerts` / `alert_reads`** (api-core already owns `notifications`). `AlertCollector`
   evaluates design §9's rules plus two more (a manual rate, which differs from the NBU's published since; in
   December, missing tax rules of the next year); `AlertSync` upserts by `dedupe_key`, resolves, never deletes. A
   notification, which returns or grows more severe, is dated anew and unread by everyone. A tax debt is one notice
   per tax, at the latest quarter due (its running balance holds the earlier debts). Unpaid ЄСВ past its deadline is
   a warning, ЄП/ВЗ an error. Limits and the cap are compared exactly (80/90/100 %); a calendar limit window is in
   the dedupe key. Read only via `POST alerts/{id}/read`. Texts are in every language (`alerts.texts`; the columns
   hold the Ukrainian ones); `action_route` holds SPA paths.
   Writes of contracts, accounts, clients, tax opening balances and settings sync them (`TenantRepository::$syncsAlerts`).
4. **Pagination** is api-core's `page[number]` / `page[size]` (20 by default, 50 at most, `config/pagination.php`),
   not a cursor; infinite scroll pages by number.
5. **`paid_amount`** of invoices is derived on read (`InvoiceQuery::withPaidAmount()`), never stored.
6. **Invoice transitions**: draft → ready → sent → paid, ready/sent → cancelled, ready → draft (no receipts), and also
   **ready → paid**. Paying needs the receipts to cover the total; a paid invoice, which a receipt change leaves
   uncovered, returns to sent (or ready, when never sent) by itself (the user's decision of 2026-10-21). **Cancelling needs no money kept by receipts**
   (move them to the replacing invoice, or refund them); refunded receipts stay with their cancelled invoice. Every
   status change locks the invoice row and re-checks the status (a double tap can't number an invoice twice).
7. **A frozen invoice changes its notes only** (409 otherwise). **`PATCH invoices` requires `If-Unmodified-Since`**
   (the `updated_at` the client edited; 428 without it, 409 when stale, to the second, under a row lock).
8. **Invoice documents**: the snapshot is an encrypted column (it copies the РНОКПП and IBAN), hidden from lists
   (`?append=snapshot`), left out of the audit log. A frozen invoice's primary document can't be replaced; only a
   generated PDF is rendered again when missing. External invoices keep their entered total (items describe them)
   and need a typed number to be frozen. A deleted draft frees its number (`invoices.active_number`, generated).
9. **Tax payments are not blocked by the period lock** (paying after declaring must work). Receipts, refunds and FX
   gains are; the lock is checked inside the write's transaction, so a failed write marks nothing corrected.
   Declarations are cumulative, so income written into an open quarter, while a later quarter of its year is declared,
   needs a correction note too.
10. **Declaration statuses** move none → filled → submitted → accepted | rejected; rejected → filled | submitted;
    accepted → corrected only by a correction; corrected → submitted. Submitting needs the quarter to have ended.
11. **Account balances count `sent` and `paid` tax payments** (dated by `paid_at`, which sent and paid ones require);
    the tax reconciliation counts `paid` only, shows `sent` as pending, ignores `failed`. A transfer leaves its
    account on `sent_at` when known.
12. **Money format defaults** follow design.md: app `space_dot` (`1 234.99`), invoices `plain_dot` (`2851.99`). CSV
    exports use the profile's decimal separator, with `;` for a decimal comma.
13. **The practice overview is org-level**: `GET /api/overview/{year}/{quarter}`; it shows every profile active in
    the quarter (closed ones too), every inactive one with `?with_inactive=1`.
14. **Repatriation notice** = money a receipt still holds on a platform or foreign-bank account past the rule
    (`compliance.platform_repatriation`); the account page marks the same money late.
15. **Refunds** leave from an account in the receipt's currency, take the money from what the receipt holds there
    (so it can't be transferred on), never precede the receipt, and never refund an FX gain.
16. **Exchange rates**: the NBU's rate is always preferred; a manual rate is taken only when the NBU is unavailable,
    never cached, and a receipt saved at one takes the NBU's rate on its next update. FX transfers with a UAH leg need
    a rate (NBU or manual). The nightly fetch covers currencies of accounts, contracts and invoices, retries days of
    manual rates, and caches days of foreign transfer fees. Reads never call the NBU (cached rates only).
17. **FX spread excludes the explicit fee** (accounting.md "the rest of the gap is spread"; design.md's formula
    counted the fee twice in losses). FX gains follow; they are taxed under the "received" base too.
18. **Income above the cap** is taxed at the excess rate (15 %) instead of 5 % in ЄП owed and the declaration
    (п. 293.4); ВЗ stays 1 % of all income.
19. **Tracking** (`Services/Taxes/TaxTracking`): tax periods and ЄСВ months exist from the earliest tax opening
    balance, or else from 1 January of the year of the first receipt/refund/tax payment (or of the profile's
    creation), never before `registered_at`, until `closed_at`, up to the next quarter. Other quarters get an unsaved
    period, so reading a year creates nothing. ЄСВ months follow changes of the registration or closure. Tax opening
    balances are dated on a quarter's first day; a later one re-bases the running balance.
20. **Tax rules end with their year** (`effective_to`), so a year without rules owes no ЄСВ and takes no receipts,
    rather than reusing last year's figures. **The 2027 rules must be added by a migration before 1 January 2027**
    (a notice reminds in December). `taxes.esv_due_day` is a profile setting, defaulting to the rule's day (19).
21. **Roles**: owners do everything; accountants do every financial operation and **configure** profiles (settings,
    signatures, see the full РНОКПП) but can't change a profile's identity or create profiles; viewers only read
    (plus marking notifications read) and see the masked РНОКПП only.
22. **Personal accounts** take no receipts and have no tracked limits; rolling limit windows are `[today − period, today]`.
23. **The app runs in Europe/Kyiv**; signed file URLs last 15 minutes; CORS exposes `Content-Disposition` and
    `Idempotent-Replayed`.
24. **API messages are in the user's own language** (`users.locale`, `uk` or `en`; before signing in the request's
    `Accept-Language`; `lang/uk*`), English without either; api-core's own error messages stay English by its
    design, and the SPA translates those few into Ukrainian (`utils/errors.ts`).
25. **Dependencies added (approved by the user)**: `barryvdh/laravel-dompdf` and `brick/math`. CLAUDE.md forbids other
    new dependencies without approval.

## Not done yet in the backend

- `SheetImporter` (import of the 2025 Google sheet, design §14 step 7): no sheet format to build against.
- XLSX exports and the yearly PDF summary (CSV exists; XLSX needs a package and approval).
- "Забули пароль?" works; its e-mail links to `{APP_URL}/reset-password`, a route the SPA has. (Ukrainian messages
  and «Запам’ятати мене» are done, see "Follow-ups".)
- design.md "pending" items: Appendix A (a refund reduces the original period), B2 (fee currency / UAH-canonical net
  income), B5 (cascade `tax_payments.period_*` when a receipt's date changes). B1 is used for receipt groups only.
- A refund saved at a manual rate can't be refreshed (refunds have no update; delete and record it again).
- A re-rendered PDF reads the signature by its live file id (deleting the signature changes a re-render).
- `tax_id_hash` is an HMAC keyed by `APP_KEY`; rotating the key would break the uniqueness of tax ids.
- Enum columns are VARCHARs without CHECK constraints; `TaxRule`'s immutability is guarded by model events only.
- PII may reach `laravel.log` through bindings of a failed query (clients' tax ids, e-mails).
- Performance: `AlertSync` runs a full collection after every write. Fine for one ФОП; debounce or queue later.

## Map of the code (namespace `PryTax\`, directory `app/`)

| Area | Files |
|---|---|
| Migrations | `database/migrations/2026_10_20_1000xx…1025xx_*` (26 tables; tax rules 2025/2026 are inserted by their migration) |
| Enums | `app/Enums/*` (`InvoiceStatus`, `PaymentKind`, `TaxType`, `MoneyFormat` with separators, …) |
| Helpers | `Decimal` (money math, `split()` by the largest remainder), `MoneyFormatter` (6 formats, parsing either separator), `CurrentProfile` |
| Models | `app/Models/*` + `Traits/BelongsToProfile`; queries in `app/Queries/*` (`TenantQuery` base); every model has a factory |
| Services | `Rates/NbuRateProvider`; `Taxes/*` (`TaxTracking`, calendar, `DeadlineScheduler`, `EsvObligationScheduler`, `PeriodLock`, `TaxLiabilityCalculator`, `TaxReconciler`, `TaxPaymentRecorder`); `Payments/*` (`PaymentSnapshotter`, `PaymentRecorder` incl. `recordGroup`, `RefundRecorder`, `PaymentFlow`, `RepatriationRule`); `Transfers/*` (`HoldingCalculator`, `AllocationProposer`, `TransferRecorder`, `TransferMetrics`); `Accounts/*` (balance, limits, statement); `Invoices/*` (numbers, writer, freezer, PDF, lifecycle); `Reports/*` (Облік, Книга доходів, ЄСВ, tax breakdowns, declaration, client stats, practice overview, `FeeStatistics`, `AccountingExporter`); `Alerts/*` |
| HTTP | `Http/Controllers/*` (`TenantController` base), `Http/Requests/*` (trait `ScopesToProfile`: profile-scoped rules, money normalization in `overrides()`), `Http/Resources/*`, `Http/Filters/*` (`period`, `unread`), `Http/Middleware/*` (`profile`, `idempotent`), `Policies/*`, `Repositories/*` (write through the services; `TenantRepository` maps FK errors to 422 and syncs notifications) |
| Roles | `config/api-core.php`: owner (all + profiles + users), accountant (all financial, `configure` profiles), viewer (read, plus marking notifications read) |
| Console | `prytax:fetch-rates`, `prytax:schedule-tax-periods`, `prytax:sync-alerts`, scheduled in `routes/console.php` |
| PDF | `resources/views/pdf/invoice.blade.php`, rendered from the invoice snapshot only |
| Tests | `tests/Concerns/ActsInProfiles.php` (helpers incl. `declareQuarter()`); base `TestCase` seeds roles and calls `Http::preventStrayRequests()` |

## API (all under `/api`, `auth:sanctum` + `password.updated` + `organization`)

- Auth: api-core's `auth/*` (login with e-mail and password; the SPA uses the session cookie).
- Org level: `profiles` (CRUD + restore; `?append=unread_alerts_count,needs_correction`), `overview/{year}/{quarter}`
  (`?with_inactive=1`), `exchange-rates` (list of cached NBU rates), `exchange-rates/{currency}/{date}` (fetches the
  NBU rate, 422 when unavailable).
- `profiles/{profile}/…` (middleware `profile`):
  - `settings` (GET/PATCH; incl. `alerts.ready_days`, `taxes.esv_due_day`), `signatures` (files);
  - `accounts` (CRUD, `?append=balance,limit_progress`), `accounts/{id}/statement?year=`,
    `accounts/{account}/opening-balances`, `accounts/{account}/movements`;
  - `clients` (CRUD, `?append=outstanding`, counts of contracts and invoices), `clients/{id}/stats`,
    `clients/{client}/contracts` (rates inline), `…/projects`, `…/charge-types`;
  - `invoices` (CRUD; filters `filter[period][in]=2026,2026-Q4,2026-10`, `filter[issued_at][gte|lte]`,
    `filter[client_id][in]`, `filter[currency][in]`, `filter[status][in]`; default sort `-issued_at,-id`;
    `?append=number_preview,can_unfreeze,snapshot`), `invoices/{id}/freeze|unfreeze|send|pay|cancel`,
    `invoices/{id}/pdf`, `invoices/counters` (GET; PATCH `{scope_key, next_number}` moves a counter forward only),
    `invoices/number-preview?client_id=&period_end=`, `invoices/defaults?client_id=&contract_id=&period_end=` (what a
    new invoice's form starts with), `invoices/outstanding` ("X до оплати" per currency), `invoices/facets` (years,
    currencies, statuses, clients of the filters), `invoices/duplicates?sha256=` (before an external PDF's invoice
    exists), `invoices/{invoice}/files` (upload `primary=1` on drafts only, duplicates by SHA-256 reported);
  - `payments` (CRUD; PATCH answers `meta.invoice` coverage), `POST payments/preview` (incl. `locked_quarter`),
    `POST payments/group` (one wire for several invoices), `payments/{id}/flow` (incl. refunds, `receipt_fx_spread`),
    `payments/{id}/acknowledge-repatriation`, `payments/{payment}/refunds`; `transfers` (CRUD with `allocations`),
    `POST transfers/propose`, `transfers/stats?year=`; `tax-payments` (CRUD). These POSTs take `Idempotency-Key`;
  - `tax-periods` (index/show/update: declaration statuses), `tax-opening-balances`, `tax-payment-requisites`
    (+ `tax-payment-requisites/current?tax_type=&period_year=&period_quarter=|period_month=&date=` with `purpose`
    filled in), `esv-obligations` (index/update: due/exempt);
  - `alerts` (index with `read_at`, `filter[category]`, `filter[unread]`), `alerts/counts`, `POST alerts/{id}/read`;
  - reports: `accounting/{year}/{quarter}` (+ `/export` CSV), `accounting/{year}/export` (the year's receipts and
    taxes), `esv/{year}`, `income-book/{year}` (+ `/export`, per document), `fees/{year}` (fees and spread by quarter,
    account, client, route; net income), `taxes/{year}?tax_type=`, `taxes/{year}/months`,
    `taxes/{year}/{quarter}/payments` (statuses paid/covered/pending/unpaid, refund rows), `declarations/{year}/{quarter}`
    (incl. `esv_quarter`, `income_cap`).
- Amounts are strings with 2 decimals, rates with 4, dates `YYYY-MM-DD`. Inputs accept `2 851,99` too.

## Demo data

```bash
php8.5 artisan migrate:fresh --seed && php8.5 artisan db:seed --class=DemoSeeder   # about 30 s; drops the dev data
```

Organization "Практика Коваленко" with `olena@example.com` (owner), `maria@example.com` (accountant) and
`taras@example.com` (viewer), password `password`. Four ФОП: Коваленко О. П. (the mock's, exact; tracked from 2025),
Мельник А. С. (Q4 2 150 of 4 300 USD received), Бондар І. В. (80 % of the cap, II кв. needs an уточнююча
декларація), Савчук Т. О. (inactive, closed 31.03.2026). The demo profiles are tracked from their first seeded year,
and no tax payment is dated after today. Verified against the mock (and by `DemoSeederTest`):

- III кв.: 503 118.73 ₴, YTD 1 487 175.04, ЄП 25 155.93 = 74 358.75 − 49 202.82, checks 25 155.94 (Δ −0.01), ВЗ 5 031.19.
- IV кв. (with Kestrel's advance of 500 USD without an invoice, 19.10): 373 967.51 ₴, YTD 1 861 142.55, ЄП 18 698.38
  (hints 18 698.39), ВЗ 3 739.68, limit 18.4 %.
- Taxes (Minimal): ЄП and ВЗ paid per receipt (Nordwave's external invoice from ПриватБанк USD at the bank's 41.20, so
  19.82 + 3.96 USD left it), ЄСВ by month on the 19th of the next one; no quarter allocations. ЄП balance today
  −18 647.36 (III кв. +51.02, the opening +51.00 and kopeck remainders; IV кв. 8 218.96 pending).
- Balances: Wise USD 8 717.50, Wise EUR 1 540.00, Payoneer 312.40, ПриватБанк UAH 286 410.37, ПриватБанк USD 2 626.22.
- Receipt of 05.10.2026: 164 379.11 ₴, ЄП 8 218.96, ВЗ 1 643.79 (fees and net income are tested in `PaymentFlowTest`).
- Known differences from the mock: Payoneer's limit is at 76 % (mock 84 %), so no limit notice; the overview's IV кв.
  income per currency follows the spec (invoices issued in the quarter), so it differs from the mock's figures.

## Frontend (phase 2, branch `dev`)

- **Stack (approved 2026-10-20):** Vue 3.5, vue-router 5, TypeScript 6.0 (not 7: vue-tsc needs the JS API), vue-tsc,
  `@vitejs/plugin-vue`; Tailwind 4; HTTP through `fetch`, no axios, no Pinia (module-level composables). No other
  packages may be added without asking. `npm run typecheck` (vue-tsc), `npm run build`, `npm run dev`. **Node 22+ is
  required** (Vite 8); the system's `node` is 18: use nvm, now `PATH=~/.nvm/versions/node/v24.21.0/bin:$PATH`.
- **Served by Laravel:** `routes/web.php` answers every path outside `api/`, `sanctum/` and `up` with
  `resources/views/app.blade.php`, which only hosts `#app` (no data passed, so a Capacitor build can reuse the app).
  `SpaRouteTest` covers it; `TestCase` calls `withoutVite()`.
- **Dev server:** port 8000 on this machine is another project (`/var/www/count-api`) — leave it alone. Serve PryTax
  from `public/` on 8010 (run in the background): `cd public && PHP_CLI_SERVER_WORKERS=4 php8.5 -S 127.0.0.1:8010
  ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`. The local `.env` says 8010 now, so no
  env overrides are needed.
- **`resources/js/`:**
  - `api/client.ts` (fetch, CSRF cookie, 419 retry, 401 → sign-in, api-core bracketed queries, `Accept-Language` of the screens,
    `apiUrl()` for downloads, `newIdempotencyKey()` for receipt/refund/transfer/tax POSTs); `api/types.ts` (every
    response, checked against 342 real ones; enums: `ItemUnit` is `hour|fixed|item`, `NumberingScope` is
    `per_client|global`).
  - `composables/`: `useSession` (`can(action, resource)` from `{action} models.{resource}` permissions),
    `useCurrentProfile` (profiles + the current one, remembered per user in localStorage `prytax.profile.{userId}`,
    settings, money format; first visit prefers the profile whose e-mail is the user's), `useLoader` (load per source,
    stale answers dropped), `usePagedList` (infinite lists), `useStoredState` (per-user view preferences, e.g. Облік's
    grouping and switches), `useInvoiceFilters` (filters in the URL).
  - `utils/`: `money` (format per profile setting with U+202F groups and U+2212 minus, exact BigInt sums, half-up
    `multiplyAmounts` / `multiplyDecimals`, `parseAmount`, `percentOf` capped at 99 until paid, `formatCompact` $/€),
    `dates` (strings, never Date parsing; Kyiv today; Ukrainian months incl. genitive), `plural`, `statuses` (badge
    labels/tones, `TAX_TYPES`), `errors` (Ukrainian texts and patterns for the API's English messages),
    `text` (figures in API texts set in mono), `accounts`, `clients`, `contracts` (`rateOn` = `Contract::rateFor`,
    `describeContract` = `Contract::describe` with PHP date letters).
  - `components/layout` (TabScreen with title-action, SubScreen with `heading`/`muted`/`subtitle`/footer, TopHeader,
    SubHeader, TabBar, AuthLayout); `components/ui` (Icon + `icons.ts`, Avatar, BottomSheet with `lead`/`action`/
    `footer` slots, Chip, StatusBadge, ProgressBar, PeriodStepper, Stepper, SegmentedControl, SwitchTile, InfoRow,
    CopyRow, SectionTitle, Primary/SecondaryButton, ConfirmSheet, LoadState, EmptyState, InfiniteSentinel);
    `components/form` (TextField, SelectField, DateField, TextAreaField); domain components under `invoice/`,
    `payment/`, `transfer/`, `accounting/`, `taxes/`, `client/`, `overview/`, `alert/`, `profile/`.
  - `pages/` by section (`auth`, `invoices`, `payments`, `transfers`, `accounts`, `clients`, `accounting`, `taxes`,
    `settings`, plus Home, Alerts, Overview, NotFound); `router/index.ts` (guards: session, then the current profile;
    `taxes-by-payment` redirects to `/taxes?view=receipts`).
- **Theme:** the tokens are Tailwind colours (`bg-paper`, `paper-light`, `text-ink-2`…`ink-4`, `border-line`,
  `bg-accent`, `handle`, `rail`, `overlay`, …; the default palette is removed), sizes `text-11` … `text-32` in px,
  radii `rounded-card` / `-control` / `-button` / `-badge`, and the `num` utility for every number (mono, tabular,
  nowrap; override with `whitespace-normal!`). Fonts are bundled from `resources/fonts`.
- **The design's renders don't show Geologica:** the export's `fonts.css` points at `fonts/fonts/…`, so the PNGs and
  HTML fall back to system fonts. The app uses Geologica and IBM Plex Mono as specified, so it looks a bit wider.
- **Built (all handoff screens of «Simplified»; «Minimal» replaced or removed several, see "Minimal"):** Вхід, "Забули пароль?", `/reset-password`; Головна; the ФОП switcher sheet;
  Сповіщення; Огляд практики; Інвойси (infinite list, filters sheet with live count, URL filters), invoice page
  (actions, receipts, PDF), editor (generated and external PDF with SHA-256 duplicate check, items sheet, adjustment,
  freeze sheet, delete draft); receipts (form with live `payments/preview`, manual rate only when the NBU has none,
  correction note for declared quarters, "mark the invoice paid"), receipt page (money flow, totals, tax payments,
  refund sheet); transfers (list with route losses, form with FIFO / exact allocations, page); accounts (list, page
  with statement and copyable requisites, form with limits); clients (list, page with stats, projects and work types
  sheets, form), contracts (template preview, rates sheet); Облік (quarters grouped none / month / invoice with
  remembered switches, summary with kopeck checks, CSV links), ЄСВ, Книга доходів; Податки (quarter / month /
  receipt views, tax payment form with budget requisites, declaration with status changes); Ще; settings (profile
  with signature and money formats, numbering and counters, taxes and notifications incl. opening balances and
  budget requisites — the latter not in the mock, added because ФОП 2–4 have none), users (invite, roles, resend,
  delete). A new ФОП lands on the taxes settings (opening balances). Forms hide saving from roles that can't save.
  Each screen was compared with its mock in headless Chrome; Облік, Податки and the declaration show the mock's
  figures exactly.
- **Frontend gaps** (as of 2026-10-21, after the follow-ups): (1) XLSX exports and the yearly PDF summary aren't
  built (no backend; a package needs approval); (2) PNG/HTML pixel polish wasn't done screen by screen beyond the
  comparison above. The others (cross-profile links, date format, JS tests, monthly spread, remember me, scroll on
  back) were done in "Follow-ups".
- **Checking the UI in a browser** (the step runner is kept in `storage/handoff/tools/act.mjs`; run it with Node 24,
  e.g. `PATH=~/.nvm/versions/node/v24.21.0/bin:$PATH SHOTS=/some/dir/ node storage/handoff/tools/act.mjs steps.txt`;
  steps: `go /path`, `wait MS`, `click TEXT`, `dclick TEXT`, `fill LABEL => VALUE` (dates as `дд.мм.рррр`), `shot NAME`
  (full page), `view NAME` (viewport), `text`, `expect REGEX`, `eval JS`, `scroll Y`; an accordion's «Змінити» is
  clicked with `eval [...document.querySelectorAll('button')].filter(b => b.innerText.trim() === 'Змінити')[N].click()`): start
  `google-chrome --headless=new --remote-debugging-port=9223 --user-data-dir=<scratch dir> about:blank` in the
  background, and drive it with a small Node 22 script (built-in `WebSocket`, no packages) over the DevTools protocol:
  `Emulation.setDeviceMetricsOverride` (390 × 844, scale 2, mobile), `Page.navigate`, `Runtime.evaluate` (fill inputs
  with the native value setter + an `input` event, click), `DOM.setFileInputFiles` for uploads,
  `Page.captureScreenshot` (with `captureBeyondViewport` and a clip of the scroll height for full pages). Sign in via
  the form at `/login`; switch the ФОП through the header chip or the localStorage key above. Useful: a step runner
  reading lines like `go /path`, `click TEXT`, `dclick TEXT` (inside `[role=dialog]` — `#app` is inert while a sheet
  is open), `fill LABEL => VALUE`, `form` (each field's label and value), `expect REGEX`, `upload SELECTOR => FILE`;
  accept `Page.javascriptDialogOpening`; clear the in-flight request set on each navigation (a `fetch` made through
  `Runtime.evaluate` may never report finishing). The mail driver is `log`: invitation links are in `laravel.log`.
- **API facts found while typing it:** `period.id` is null in `accounting/{y}/{q}` and `declarations/{y}/{q}` for
  untracked quarters (don't link to `tax-periods/null`); a few timestamps carry `+03:00` instead of `Z` (alert
  `read_at`, `declaration_submitted_at`, the snapshot's `frozen_at`); `quarter_balance` means the quarter's own
  balance in `taxes/{year}` but the running one in `taxes/{year}/months`; statement tax entries put the raw tax type
  in `title`; items come only with `?include=items`; `GET exchange-rates/{currency}/{date}` and `payments/preview`
  fetch and cache NBU rates (writes on read); user routes come from api-core (`/api/users`, roles always included).
- Sanctum SPA sessions: GET `/sanctum/csrf-cookie` before login. Send `If-Unmodified-Since` (the invoice's
  `updated_at`) with every invoice PATCH, and an `Idempotency-Key` with receipt/refund/transfer/tax POSTs.
- SPA paths, which the API links to (`alerts.action_route`, the reset e-mail): `/invoices/{id}`, `/accounts/{id}`,
  `/payments/{id}`, `/transfers/{id}`, `/transfers/new?from={accountId}`, `/clients/{clientId}/contracts/{id}`,
  `/taxes?year=`, `/taxes/declaration/{year}/{quarter}`, `/accounting/{year}/{quarter}`, `/accounting/esv?year=`,
  `/reset-password`, `/accept-invitation`. Keep the routes consistent with these, or change both.
- **Mobile later:** the user wants iOS and Android; the plan is Capacitor around this same app (a second Vite config
  with an `index.html` entry, api-core's access/refresh tokens in secure storage instead of the session cookie,
  downloads through the Filesystem/Share plugins). Keep the app free of Blade-injected data and keep sign-in and
  downloads behind `api/client.ts` and one helper.

## Conventions to keep

- Code in `PryTax\`; class docblocks with a description, `@author Andrii Prykhodko <andriichello@gmail.com>` and
  `@package`; every property and method documented; `vendor/bin/pint --dirty --format agent` before committing.
- Tests: PHPUnit, camelCase `testDoesSomething` methods with docblocks, `RefreshDatabase`, factories and the
  `ActsInProfiles` helpers; NBU rates are cached with `cacheRates()` or faked with `Http::fake()` per test.
- An Eloquent collection's `except()` takes model ids, not keys: use `->toBase()` before keying by another column.
- Work on a feature branch and commit in logical parts; don't push or merge without the user asking.
- Lists of records are newest first everywhere (the user, 2026-10-25; design.md §16.5's display rules): sort by the
  record's date descending with `-id` last (`sort: '-received_at,-id'`), and order groups (months) the same way.
- The dev database now holds the user's own test records too (2026-10-25): don't reseed it without asking.
- Frontend: match the screens' HTML exports (`storage/handoff/prytax-design-handoff/screens/html`) for sizes; UI
  texts through `t('area.screen.name')`, with the Ukrainian in `i18n/uk/*.ts` and the English in `i18n/en/*.ts` (see "English"); `<script setup lang="ts">` with a docblock on each component; every number in `num`; keep option arrays of
  selects/segments in the script (inline `list.map(… ({ value, label: MAP[x] }))` in a template trips vue-tsc with
  "Property 'value' does not exist on type Record<…>").
- Tool gotcha: `\u…` escapes typed into tool inputs arrive as the characters themselves, so invisible U+00A0/U+202F
  can land in sources. Write escapes via Python `chr(92)`, and run
  `grep -rnP "[\x{00A0}\x{202F}\x{2009}]" resources/js` before committing (it must find nothing).
