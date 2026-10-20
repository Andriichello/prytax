# PryTax: progress and handoff

Status as of 2026-10-20 (backend merged into `main`; frontend started on `dev`). Read this file first when resuming, then `CLAUDE.md`,
`docs/specs/design.md` (§16 is the phase-1 UI scope) and `docs/prytax-simplified-design-handoff.md` (the design
handoff; the screens are in `docs/prytax-design-handoff/`).

## Where things stand

| Phase | State |
|---|---|
| 1. Backend: migrations, models, services, API, tests | **Done and audited**, fast-forwarded into `main` (not pushed; there is no remote) |
| 2. Frontend: Vue screens of the "Simplified" design | **In progress** on branch `dev`: foundation and section «Вхід і головна» done |
| 3. QA in the browser, going through scenarios like a tester | **Not started** |

- `php artisan test --compact`: **286 tests** (283 backend + `SpaRouteTest`), all passing on 2026-10-20 (about 85 s, on MySQL `prytax_test`). Run it in the
  foreground with a generous timeout; two test runs at once on `prytax_test` deadlock each other.
- The dev database `prytax` was rebuilt with the demo data on 2026-10-20, after the audit (schema is current).
- The user asked for commits in logical parts, with the `Co-Authored-By` trailer from the system reminder.

### First steps of the next session

1. Continue phase 2 on `dev`, section by section in canvas order: Інвойси (S-Invoices with infinite scroll and the
   filters screen, S-InvoiceEdit/Item/Freeze/Detail/External) → Надходження → Рахунки й клієнти → Облік → Податки →
   Ще. Routes not built yet render `ComingSoonPage` (see `resources/js/router/index.ts`).
2. Before each screen: its PNG in `docs/prytax-design-handoff/screens/png/`, its export in `screens/html/` for sizes.

## The audit (2026-10-20)

Six parallel reviews compared the backend with design.md, accounting.md, suggestions.md and the handoff, and every
finding was fixed with tests, except the items under "Not done yet". The user decided four questions:

- **Numbering**: one counter per ФОП by default (`invoice.numbering.scope = global`); per client stays a setting.
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
   the dedupe key. Read only via `POST alerts/{id}/read`. Texts are Ukrainian; `action_route` holds SPA paths.
   Writes of contracts, accounts, clients, tax opening balances and settings sync them (`TenantRepository::$syncsAlerts`).
4. **Pagination** is api-core's `page[number]` / `page[size]` (20 by default, 50 at most, `config/pagination.php`),
   not a cursor; infinite scroll pages by number.
5. **`paid_amount`** of invoices is derived on read (`InvoiceQuery::withPaidAmount()`), never stored.
6. **Invoice transitions**: draft → ready → sent → paid, ready/sent → cancelled, ready → draft (no receipts), and also
   **ready → paid**. Paying needs the receipts to cover the total. **Cancelling needs no money kept by receipts**
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
24. **API error messages** are English through `__()`, ready for a `lang/uk.json`; api-core's are English too. The
    frontend must show Ukrainian.
25. **Dependencies added (approved by the user)**: `barryvdh/laravel-dompdf` and `brick/math`. CLAUDE.md forbids other
    new dependencies without approval.

## Not done yet in the backend

- `SheetImporter` (import of the 2025 Google sheet, design §14 step 7): no sheet format to build against.
- XLSX exports and the yearly PDF summary (CSV exists; XLSX needs a package and approval).
- "Запам'ятати мене" on login: api-core's session login never sets a remember cookie (needs an api-core change).
  "Забули пароль?" works; its e-mail links to `{frontend}/reset-password`, a route the SPA must have.
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
php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder   # about 16 s; drops the dev data
```

Organization "Практика Коваленко" with `olena@example.com` (owner), `maria@example.com` (accountant) and
`taras@example.com` (viewer), password `password`. Four ФОП: Коваленко О. П. (the mock's, exact; tracked from 2025),
Мельник А. С. (Q4 2 150 of 4 300 USD received), Бондар І. В. (80 % of the cap, II кв. needs an уточнююча
декларація), Савчук Т. О. (inactive, closed 31.03.2026). The demo profiles are tracked from their first seeded year,
and no tax payment is dated after today. Verified against the mock (and by `DemoSeederTest`):

- III кв.: 503 118.73 ₴, YTD 1 487 175.04, ЄП 25 155.93 = 74 358.75 − 49 202.82, checks 25 155.94 (Δ −0.01), ВЗ 5 031.19.
- IV кв.: 352 960.01 ₴, YTD 1 840 135.05, ЄП 17 648.00 (hints 17 648.01), ВЗ 3 529.60, ЄСВ year 22 828.08, limit 18.2 %.
- ЄП balance today −18 413.69 (III кв. −765.69, IV кв. 8 218.96 pending); all monthly totals of the income book.
- Balances: Wise USD 8 217.50, Wise EUR 1 540.00, Payoneer 312.40, ПриватБанк UAH 286 410.37, ПриватБанк USD 2 650.00.
- Receipt of 05.10.2026: 164 379.11 ₴, ЄП 8 218.96, ВЗ 1 643.79 (fees and net income are tested in `PaymentFlowTest`).
- Known differences from the mock: Payoneer's limit is at 76 % (mock 84 %), so no limit notice; the overview's IV кв.
  income per currency follows the spec (invoices issued in the quarter), so it differs from the mock's figures.

## Frontend (phase 2, branch `dev`)

- **Stack (approved 2026-10-20):** Vue 3.5, vue-router 5, TypeScript 6.0 (not 7: vue-tsc needs the JS API), vue-tsc,
  `@vitejs/plugin-vue`; Tailwind 4; HTTP through `fetch`, no axios, no Pinia (module-level composables).
  `npm run typecheck` (vue-tsc), `npm run build`. **Node 22 is required** (Vite 8); the system's `node` is 18, so use
  nvm (`.nvmrc`), e.g. `PATH=~/.nvm/versions/node/v22.23.3/bin:$PATH`.
- **Served by Laravel:** `routes/web.php` answers every path outside `api/`, `sanctum/` and `up` with
  `resources/views/app.blade.php`, which only hosts `#app` (no data passed, so a Capacitor build can reuse the app;
  see the user's plan below). `SpaRouteTest` covers it; `TestCase` calls `withoutVite()`.
- **Layout of `resources/js/`:** `api/client.ts` (fetch, CSRF, 419 retry, 401 → sign-in, api-core bracketed queries,
  `Accept-Language: uk`), `api/types.ts` (every response, checked against real ones), `composables/` (`useSession`,
  `useCurrentProfile`: profiles + the current one, remembered per user in localStorage, settings and money format;
  `useLoader`: load per source, stale answers dropped), `utils/` (`money`, `dates`, `plural`, `statuses`, `errors`:
  Ukrainian texts for the API's English messages, `text`: figures in API texts set in mono), `components/layout`
  (TabScreen, SubScreen, TopHeader, SubHeader, TabBar, AuthLayout), `components/ui` (Icon with the designs' icons,
  Avatar, BottomSheet, Chip, StatusBadge, ProgressBar, PeriodStepper, PrimaryButton, LoadState, EmptyState),
  `components/form/TextField`, `pages/` and `router/index.ts` (guards: session, then the current profile).
- **Theme:** the tokens are Tailwind colours (`bg-paper`, `text-ink-2`, `border-line`, `bg-accent`, …; the default
  palette is removed), sizes `text-11` … `text-30` in px, radii `rounded-card` / `-control` / `-button` / `-badge`,
  the `num` utility for every number (mono, tabular, nowrap). Fonts are bundled from `resources/fonts`.
- **The design's renders don't show Geologica:** the export's `fonts.css` points at `fonts/fonts/…`, so the PNGs and
  HTML fall back to system fonts. The app uses Geologica and IBM Plex Mono as specified, so it looks a bit wider.
- **Done:** Вхід (no "Запам'ятати мене": api-core has no remember-me), "Забули пароль?" and `/reset-password`,
  Головна, the ФОП switcher sheet, Сповіщення (read on tap, card, action or ✓), Огляд практики (`?year&quarter`,
  `?inactive=1`).
- **Dev server:** port 8000 on this machine is another project (`/var/www/count-api`). Serve PryTax elsewhere, e.g.
  from `public/`: `APP_URL=http://localhost:8010 SANCTUM_STATEFUL_DOMAINS=localhost:8010,127.0.0.1:8010
  php -S 127.0.0.1:8010 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`.
- **The API answers in English.** The SPA translates the common messages (`utils/errors.ts`); real Ukrainian needs
  api-core's `locale` middleware, `api-core.locales = ['uk', 'en']` and `lang/uk` (backend work, not done).
- Sanctum SPA sessions: GET `/sanctum/csrf-cookie` before login. Send `If-Unmodified-Since` (the invoice's
  `updated_at`) with every invoice PATCH, and an `Idempotency-Key` with receipt/refund/transfer/tax POSTs.
- SPA paths, which the API links to (`alerts.action_route`, the reset e-mail): `/invoices/{id}`, `/accounts/{id}`,
  `/payments/{id}`, `/transfers/{id}`, `/transfers/new?from={accountId}`, `/clients/{clientId}/contracts/{id}`,
  `/taxes?year=`, `/taxes/declaration/{year}/{quarter}`, `/accounting/{year}/{quarter}`, `/accounting/esv?year=`,
  `/reset-password`. Keep the routes consistent with these, or change both.
- Persist the Облік switches and grouping per user in localStorage (handoff §9 open question 1).
- A new profile without records is tracked from the start of its creation year; the onboarding should offer tax
  opening balances (dated on a quarter's first day) so earlier quarters aren't reported as unpaid.
- **Mobile later:** the user wants iOS and Android; the plan is Capacitor around this same app (a second Vite config
  with an `index.html` entry, api-core's access/refresh tokens in secure storage instead of the session cookie,
  downloads through the Filesystem/Share plugins). Keep the app free of Blade-injected data and keep sign-in and
  downloads behind `api/client.ts` and one helper.
- **API facts found while typing it:** `period.id` is null in `accounting/{y}/{q}` and `declarations/{y}/{q}` for
  untracked quarters (don't link to `tax-periods/null`); a few timestamps carry `+03:00` instead of `Z` (alert
  `read_at`, `declaration_submitted_at`, the snapshot's `frozen_at`); `quarter_balance` means the quarter's own
  balance in `taxes/{year}` but the running one in `taxes/{year}/months`; statement tax entries put the raw tax type
  in `title`; items come only with `?include=items`.

## Conventions to keep

- Code in `PryTax\`; class docblocks with a description, `@author Andrii Prykhodko <andriichello@gmail.com>` and
  `@package`; every property and method documented; `vendor/bin/pint --dirty --format agent` before committing.
- Tests: PHPUnit, camelCase `testDoesSomething` methods with docblocks, `RefreshDatabase`, factories and the
  `ActsInProfiles` helpers; NBU rates are cached with `cacheRates()` or faked with `Http::fake()` per test.
- An Eloquent collection's `except()` takes model ids, not keys: use `->toBase()` before keying by another column.
- Work on a feature branch and commit in logical parts; don't push or merge without the user asking.
