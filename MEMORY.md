# PryTax: progress and handoff

Status as of 2026-10-20. Read this file first when resuming, then `CLAUDE.md`, `docs/specs/design.md` (§16 is the
phase-1 UI scope) and `docs/prytax-simplified-design-handoff.md` (the design handoff; the screens are in
`docs/prytax-design-handoff/`).

## Where things stand

| Phase | State |
|---|---|
| 1. Backend: migrations, models, services, API, tests | **Done** on branch `backend-phase-1` (32 commits on top of `main`, not merged, not pushed) |
| 2. Frontend: Vue screens of the "Simplified" design | **Not started** |
| 3. QA in the browser, going through scenarios like a tester | **Not started** |

- `php artisan test --compact`: **96 tests, 382 assertions, all passing** (about 40 s, on MySQL `prytax_test`).
- The dev database `prytax` is seeded with the demo practice (see "Demo data").
- The user asked for commits in logical parts (docs, then migrations by domain, models, services, HTTP layer,
  tests). Keep doing that, with the `Co-Authored-By` trailer from the system reminder.

## Decisions taken (and where the code departs from the spec)

1. **api-core's building blocks replace some tables of the spec** (CLAUDE.md requires api-core's base classes):
   - profile settings are api-core settings (`HasSettings` on `Profile`, `settings` table), not `profile_settings`;
   - invoice files are api-core files (`HasFiles`, collections `generated` / `uploaded`, `signature` on profiles) with
     `invoices.primary_file_id`, not `invoice_files`;
   - invoice counters are api-core `Sequence`s owned by the profile (keys `invoices:client:{id}`, `invoices:global`,
     with `:{year}` for yearly resets), not `invoice_sequences`;
   - the activity log is api-core's `Audited` trait; `created_by_user_id` comes from `TracksCreator`. There is no
     `updated_by_user_id` column: the audit log records who changed what.
2. **Tenancy**: `profile_id` on domain tables, `BelongsToProfile` (global scope, fails closed), `CurrentProfile`
   (Laravel Context, like api-core's `CurrentOrganization`), `ResolveProfile` middleware (alias `profile`, ranked
   after api-core's middleware), and every profile controller extends `TenantController` with
   `$parents = ['profile', …]`. A profile of another organization, or another profile's record, is a plain 404.
3. **Notifications of a profile are `alerts` / `alert_reads`** (api-core already owns `notifications`, which are per
   user). `AlertCollector` evaluates the 9 rules of design §9; `AlertSync` upserts by `dedupe_key`, resolves, never
   deletes; read only via `POST alerts/{id}/read`. Alert texts are Ukrainian; `action_route` holds SPA paths (see
   "Frontend").
4. **Pagination** is api-core's `page[number]` / `page[size]`, not a cursor; infinite scroll pages by number.
5. **`paid_amount`** of invoices is derived on read (`InvoiceQuery::withPaidAmount()`), never stored.
6. **Invoice transitions**: draft → ready → sent → paid, ready/sent → cancelled, ready → draft (no receipts), and
   also **ready → paid** (the spec only has sent → paid). Paying needs the receipts to cover the total.
7. **A frozen invoice changes its notes only** (`InvoiceWriter`, 409 otherwise); `If-Unmodified-Since` on `PATCH`
   gives 409 on a stale write.
8. **Tax payments are not blocked by the period lock** (the spec says `TaxPaymentRecorder` calls `assertOpen`, but
   that would block paying taxes after declaring). Receipts, refunds and FX gains are locked.
9. **Account balances count `sent` and `paid` tax payments** (the money left the account); the tax reconciliation
   counts `paid` only, shows `sent` as pending, ignores `failed`.
10. **Money format defaults** follow design.md: app `space_dot` (`1 234.99`), invoices `plain_dot` (`2851.99`). The
    handoff's ProfileEdit mock shows `2 851.99` for both; design.md won.
11. **The practice overview is org-level**: `GET /api/overview/{year}/{quarter}` (the spec had it under a profile).
12. **Repatriation notice** = money a receipt still holds on a platform or foreign account past the rule
    (`compliance.platform_repatriation`), not "landed < received" (fees would always trigger that).
13. **Refunds** leave from an account in the receipt's currency only.
14. **API error messages** are English through `__()`, ready for a `lang/uk.json`; api-core's own messages are English
    too. The frontend must show Ukrainian.
15. **Dependencies added (approved by the user)**: `barryvdh/laravel-dompdf` (invoice PDFs, DejaVu Sans, font
    subsetting on) and `brick/math` (all money math, `PryTax\Helpers\Decimal`, half-up). CLAUDE.md forbids other new
    dependencies without approval, which matters for the frontend (Vue is not installed yet).

## Not done yet in the backend (spec items left for later)

- `SheetImporter` (import of the 2025 Google sheet, design §14 step 7): no sheet format to build against.
- XLSX exports and the yearly PDF summary (CSV exports exist; XLSX needs a package and approval).
- A UI action "one receipt for several invoices" (the `receipt_group_id` column exists, nothing groups yet).
- design.md "pending" items: refund on the original period (Appendix A), B1, B2, B5, B6 (B6 partly: `invoices/{id}/pdf`
  re-renders a missing generated PDF).
- Performance: `AlertSync` runs a full collection after every write (`syncAfterCommit`). Fine for one ФОП, but worth
  debouncing or queueing later. The seeder pauses it with `AlertSync::paused()`.

## Map of the code (namespace `PryTax\`, directory `app/`)

| Area | Files |
|---|---|
| Migrations | `database/migrations/2026_10_20_1000xx…1025xx_*` (26 tables; tax rules 2025/2026 are inserted by their migration) |
| Enums | `app/Enums/*` (`InvoiceStatus`, `PaymentKind`, `TaxType`, `MoneyFormat` with separators, …) |
| Helpers | `Decimal` (money math), `MoneyFormatter` (6 formats, parsing either separator), `CurrentProfile` |
| Models | `app/Models/*` + `Traits/BelongsToProfile`; queries in `app/Queries/*` (`TenantQuery` base) |
| Services | `Rates/NbuRateProvider`; `Taxes/*` (calendar, deadlines, ЄСВ months, `PeriodLock`, `TaxLiabilityCalculator`, `TaxReconciler`, `TaxPaymentRecorder`); `Payments/*` (`PaymentSnapshotter`, `PaymentRecorder`, `RefundRecorder`, `PaymentFlow`, `RepatriationRule`); `Transfers/*` (`HoldingCalculator`, `AllocationProposer`, `TransferRecorder`, `TransferMetrics`); `Accounts/*` (balance, limits, statement); `Invoices/*` (numbers, writer, freezer, PDF, lifecycle); `Reports/*` (Облік, Книга доходів, ЄСВ, tax breakdowns, declaration, client stats, practice overview, CSV); `Alerts/*` |
| HTTP | `Http/Controllers/*` (`TenantController` base), `Http/Requests/*` (trait `ScopesToProfile`: profile-scoped rules, money normalization in `overrides()`), `Http/Resources/*`, `Http/Filters/*` (`period`, `unread`), `Http/Middleware/*` (`profile`, `idempotent`), `Policies/*`, `Repositories/*` (write through the services) |
| Roles | `config/api-core.php`: owner (all + profiles + users), accountant (all financial), viewer (read, plus marking notifications read) |
| Console | `prytax:fetch-rates`, `prytax:schedule-tax-periods`, `prytax:sync-alerts`, scheduled in `routes/console.php` |
| PDF | `resources/views/pdf/invoice.blade.php`, rendered from the invoice snapshot only |
| Tests | `tests/Concerns/ActsInProfiles.php` (helpers); base `TestCase` seeds roles and calls `Http::preventStrayRequests()` |

## API (all under `/api`, `auth:sanctum` + `password.updated` + `organization`)

- Auth: api-core's `auth/*` (login with e-mail and password; the SPA uses the session cookie).
- Org level: `profiles` (CRUD + restore), `overview/{year}/{quarter}`, `exchange-rates` (list, manual `POST`),
  `exchange-rates/{currency}/{date}` (fetches the NBU rate, 422 when unavailable).
- `profiles/{profile}/…` (middleware `profile`):
  - `settings` (GET/PATCH), `signatures` (files);
  - `accounts` (CRUD, `?append=balance,limit_progress`), `accounts/{id}/statement?year=`,
    `accounts/{account}/opening-balances`, `accounts/{account}/movements`;
  - `clients` (CRUD, `?append=outstanding`, counts of contracts and invoices), `clients/{id}/stats`,
    `clients/{client}/contracts` (rates inline), `…/projects`, `…/charge-types`;
  - `invoices` (CRUD; filters `filter[period][in]=2026,2026-Q4,2026-10`, `filter[issued_at][gte|lte]`,
    `filter[client_id][in]`, `filter[currency][in]`, `filter[status][in]`; default sort `-issued_at,-id`),
    `invoices/{id}/freeze|unfreeze|send|pay|cancel`, `invoices/{id}/pdf`, `invoices/counters`,
    `invoices/number-preview?client_id=&period_end=`, `invoices/{invoice}/files` (upload `primary=1`, duplicates by
    SHA-256 reported);
  - `payments` (CRUD, `POST payments/preview`, `payments/{id}/flow`, `payments/{id}/acknowledge-repatriation`),
    `payments/{payment}/refunds`; `transfers` (CRUD with `allocations`), `POST transfers/propose`,
    `transfers/stats?year=`; `tax-payments` (CRUD). These POSTs take `Idempotency-Key`;
  - `tax-periods` (index/show/update: declaration status locks), `tax-opening-balances`, `tax-payment-requisites`,
    `esv-obligations` (index/update);
  - `alerts` (index with `read_at`, `filter[category]`, `filter[unread]`), `alerts/counts`, `POST alerts/{id}/read`;
  - reports: `accounting/{year}/{quarter}` (+ `/export` CSV), `esv/{year}`, `income-book/{year}` (+ `/export`),
    `taxes/{year}?tax_type=`, `taxes/{year}/months`, `taxes/{year}/{quarter}/payments`,
    `declarations/{year}/{quarter}`.
- Amounts are strings with 2 decimals, rates with 4, dates `YYYY-MM-DD`. Inputs accept `2 851,99` too.

## Demo data

```bash
php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder   # about 16 s
```

Organization "Практика Коваленко" with `olena@example.com` (owner), `maria@example.com` (accountant) and
`taras@example.com` (viewer), password `password`. Four ФОП: Коваленко О. П. (the mock's, exact), Мельник А. С.
(Q4 2 150 of 4 300 USD received), Бондар І. В. (80 % of the cap, II кв. needs an уточнююча декларація), Савчук Т. О.
(inactive, closed 31.03.2026). Verified against the mock (and by `tests/Feature/Database/Seeders/DemoSeederTest.php`):

- III кв.: 503 118.73 ₴, YTD 1 487 175.04, ЄП 25 155.93 = 74 358.75 − 49 202.82, checks 25 155.94 (Δ −0.01), ВЗ 5 031.19.
- IV кв.: 352 960.01 ₴, YTD 1 840 135.05, ЄП 17 648.00 (hints 17 648.01), ВЗ 3 529.60, ЄСВ year 22 828.08, limit 18.2 %.
- ЄП balance today −18 413.69 (III кв. −765.69, IV кв. 8 218.96 pending); all monthly totals of the income book.
- Balances: Wise USD 8 217.50, Wise EUR 1 540.00, Payoneer 312.40, ПриватБанк UAH 286 410.37, ПриватБанк USD 2 650.00.
- Receipt of 05.10.2026: 164 379.11 ₴, ЄП 8 218.96, ВЗ 1 643.79 (fees and net income are tested in `PaymentFlowTest`).
- Known gap: Payoneer's limit is at 76 % (the mock shows 84 %), so no limit notice; the 2026-10-15-7 overdue invoice,
  the Kestrel contract ending, and two platform balances past quarter end are the notices.

## Frontend (next phase): what is known

- Stack wanted: Vue 3 (+ TypeScript per design.md, Tailwind 4 is installed), rendered inside a Blade shell by Laravel
  (handoff §2), mobile-first single column max ~480 px. **Vue, vue-router and friends are not installed yet:
  ask the user before adding them** (CLAUDE.md). `resources/views/welcome.blade.php` is still Laravel's default.
- Sanctum SPA sessions: `SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:8000`, `statefulApi()` is on. Serving the
  SPA from Laravel (same origin) is simplest; GET `/sanctum/csrf-cookie` before login.
- Design tokens: `docs/prytax-design-handoff/tokens.css`; fonts Geologica and IBM Plex Mono (local copies in
  `docs/prytax-design-handoff/screens/html/fonts`); components and screens: handoff §4 and §6; Облік behaviour: §7.
- SPA paths that the API's notifications link to (`alerts.action_route`): `/invoices/{id}`, `/accounts/{id}`,
  `/transfers/new?from={accountId}`, `/clients/{clientId}/contracts/{id}`, `/taxes?year=`,
  `/taxes/declaration/{year}/{quarter}`, `/accounting/{year}/{quarter}`, `/accounting/esv?year=`. Keep the routes
  consistent with these, or change both.
- Persist the Облік switches and grouping per user in localStorage (handoff §9 open question 1).

## Conventions to keep

- Code in `PryTax\`; class docblocks with a description, `@author Andrii Prykhodko <andriichello@gmail.com>` and
  `@package`; every property and method documented; `vendor/bin/pint --dirty --format agent` before committing.
- Tests: PHPUnit, camelCase `testDoesSomething` methods with docblocks, `RefreshDatabase`, factories and the
  `ActsInProfiles` helpers; NBU rates are cached with `cacheRates()` or faked with `Http::fake()` per test.
- Work on a feature branch and commit in logical parts; don't push or merge without the user asking.
