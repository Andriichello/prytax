# PryTax: progress and handoff

Status as of 2026-10-21 (late): backend, frontend and the QA pass are merged into `main` (= `dev` = `dev-qa-fixes`);
the follow-ups of the QA pass (the user's decisions, the frontend gaps, Ukrainian API messages, «Запам’ятати мене») are
on `dev-followups`, not merged. Read this file first when resuming, then `CLAUDE.md`, `docs/specs/design.md` (§16 is
the phase-1 UI scope) and `docs/prytax-simplified-design-handoff.md` (the design handoff; the screens are in
`docs/prytax-design-handoff/`).

## Where things stand

| Phase | State |
|---|---|
| 1. Backend: migrations, models, services, API, tests | **Done and audited**, fast-forwarded into `main` (not pushed; there is no remote) |
| 2. Frontend: Vue screens of the "Simplified" design | **All 34 screens built** on branch `dev` (not merged) |
| 3. QA: specs review + browser flows per role | **Done and merged** (2026-10-21); see "QA pass" |
| 4. Follow-ups: decisions, frontend gaps, Ukrainian API, remember me | **Done** on `dev-followups` (15 commits, not merged); see "Follow-ups" |

- **Branches:** `main` = `dev` = `dev-qa-fixes` = backend + frontend + QA pass (fast-forwarded on 2026-10-21, the
  user asked). `dev-followups` = `main` + this session's 15 commits, checked out, **not merged** (merge only when the
  user asks). `backend-phase-1` and `dev-qa-fixes` are merged and can go. No git remote exists, so nothing is pushed.
- **api-core is on branch `feat/remember-login`** in `/var/www/api-core` (2 commits on its `dev`: `remember` on
  `auth/login`, the `remember_token` migration, docs, tests; all 658 api-core tests pass), **not merged, not pushed**.
  PryTax runs api-core's working tree (path repository, symlinked), so that branch must stay checked out until it is
  merged into api-core's `dev`. api-core's commits run GrumPHP (phpcs line length 120, phpmd: no boolean flag
  arguments, phpstan).
- `php artisan test --compact`: **300 tests**, all passing on 2026-10-21 (about 90 s, on MySQL `prytax_test`). Run it
  in the foreground with a generous timeout; two runs at once deadlock each other (so parallel agents must not run
  it). The frontend: `npm test` (Vitest, 28 tests of `resources/js/utils/*.test.ts`), `npm run typecheck`,
  `npm run build`.
- The dev database `prytax` was rebuilt with the demo data at the end of 2026-10-21 (after the QA flows). NBU rates
  get cached by previews (writes on read). `migrate:fresh` leaves old seeds' files in `storage/app/private/files`
  (orphans; harmless).
- The user asked for commits in logical parts, with the `Co-Authored-By` trailer from the system reminder.

### First steps of the next session

1. **Ask the user** about merging `dev-followups` into `dev`/`main`, and api-core's `feat/remember-login` into its
   `dev` (and pushing it). Then what is left: XLSX exports and the yearly PDF summary (need a package, so approval),
   pixel polish against the mocks, design.md's "pending" items (Appendix A, B2, B5), `SheetImporter`.
2. Start the dev server on port 8010 (command under "Frontend"), `npm run build` (or `npm run dev`), sign in as
   `olena@example.com` / `password`.

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

## Follow-ups (2026-10-21, branch `dev-followups`)

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
  takes `4.1.2026`, `14/10/2026`, `14102026`), with the native calendar under a button (`showPicker()`); a wrong or
  out-of-range date is told after leaving the field.
- **Vitest** (approved by the user; `vitest@5.0.3`, pinned to a release a few weeks old): `vitest.config.js` (alias
  only, not Vite's config), `npm test`. Installing with Node 22's npm crashed (arborist `edgesOut`); Node 24's npm 11
  worked (it also rewrote the lockfile's flags of optional platform packages, harmless).
- **Monthly taxes:** `taxes/{year}/months` spreads a quarter's own payments over its months, the earliest first (each
  takes what it still owes after its own payments; the last month keeps the rest; paid before sent), `from_quarter`
  per month; the view marks "за кв.".
- **Ukrainian API:** api-core's `locale` middleware on the `api` group, `api-core.locales = ['uk']` (so users' stored
  `en` never overrides the SPA's `Accept-Language: uk`; requests without the header stay English, so tests assert
  English); `lang/uk.json` (every `__()` of `app/` — `LocalizationTest` scans them —, the invitation and reset
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
24. **API messages are Ukrainian for the SPA** (`Accept-Language: uk`, `api-core.locales = ['uk']`, `lang/uk*`) and
    English without the header; api-core's own error messages stay English by its design, and the SPA translates
    those few (`utils/errors.ts`).
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
  `@vitejs/plugin-vue`; Tailwind 4; HTTP through `fetch`, no axios, no Pinia (module-level composables). No other
  packages may be added without asking. `npm run typecheck` (vue-tsc), `npm run build`, `npm run dev`. **Node 22 is
  required** (Vite 8); the system's `node` is 18: use nvm (`.nvmrc`), e.g. `PATH=~/.nvm/versions/node/v22.23.3/bin:$PATH`.
- **Served by Laravel:** `routes/web.php` answers every path outside `api/`, `sanctum/` and `up` with
  `resources/views/app.blade.php`, which only hosts `#app` (no data passed, so a Capacitor build can reuse the app).
  `SpaRouteTest` covers it; `TestCase` calls `withoutVite()`.
- **Dev server:** port 8000 on this machine is another project (`/var/www/count-api`) — leave it alone. Serve PryTax
  from `public/` on 8010 (run in the background): `cd public && PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8010
  ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`. The local `.env` says 8010 now, so no
  env overrides are needed.
- **`resources/js/`:**
  - `api/client.ts` (fetch, CSRF cookie, 419 retry, 401 → sign-in, api-core bracketed queries, `Accept-Language: uk`,
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
- **Built (all handoff screens):** Вхід, "Забули пароль?", `/reset-password`; Головна; the ФОП switcher sheet;
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
- **Checking the UI in a browser** (the scripts live in a session's scratchpad, which goes away; the latest copies,
  `cdp.mjs`, `act.mjs`, `sweep.mjs`, were in `/tmp/claude-1000/-var-www-prytax/4e9a9efe-…/scratchpad`; run them with
  Node 22, e.g. `PATH=~/.nvm/versions/node/v22.23.3/bin:$PATH node act.mjs steps.txt`): start
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
- Frontend: match the screens' HTML exports (`docs/prytax-design-handoff/screens/html`) for sizes; Ukrainian UI
  texts; `<script setup lang="ts">` with a docblock on each component; every number in `num`; keep option arrays of
  selects/segments in the script (inline `list.map(… ({ value, label: MAP[x] }))` in a template trips vue-tsc with
  "Property 'value' does not exist on type Record<…>").
- Tool gotcha: `\u…` escapes typed into tool inputs arrive as the characters themselves, so invisible U+00A0/U+202F
  can land in sources. Write escapes via Python `chr(92)`, and run
  `grep -rnP "[\x{00A0}\x{202F}\x{2009}]" resources/js` before committing (it must find nothing).
