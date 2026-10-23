# PryTax — ФОП Finance App — Design Spec (v3.2, multi-profile on api-core)

Target: accounting + invoicing app for Ukrainian ФОПs (3 група, спрощена система, no employees), built on the `api-core`
package (organizations, users, Sanctum tokens, spatie roles/permissions, HTTP filtering/resources/pagination). Stack:
Laravel 12/13, MySQL 8, Vue 3 SPA (mobile-first, Ukrainian UI) consuming the API (Blade only for PDF/email
templates). Functional rules live in `ACCOUNTING.md`; this document is the implementation of them. §16 fixes the
phase-1 ("Simplified") UI scope; the full redesign brief is `claude/prytax-design-review.md`.

### Changes from v3.1 (v3.2 — client abbreviations in invoice numbers)

- `clients.abbreviation VARCHAR(10) NOT NULL`: upper-case Latin letters and digits (`BL`), `UNIQUE(profile_id,
  abbreviation)` — unique among all the profile's clients, inactive ones included; required for a new client (§3).
- `invoice.numbering.prefix = client_abbreviation | none` (default `client_abbreviation`), and the counter is the
  client's own by default (`invoice.numbering.scope = per_client`): the default number is
  `{ABBREVIATION}-{YYYY-MM-DD}-{n}`, such as `BL-2026-10-18-13` — Brightline Labs' 13th invoice, for the period ending
  on 18 October 2026 (§5).
- Counters stay keyed by the client's id, so a changed abbreviation keeps its counter; numbers already given keep
  their prefix, and a re-freeze rebuilds the prefix together with the date (§5).

### Changes from v3 (v3.1 — phase-1 UI scope)

- `income.recognition` removed from phase 1: income is recognised on the first receipt only; `payments.received_at`
  is `NOT NULL` again, `recognition_status` / `recognise()` / "awaiting recognition" are gone. The
  `fop_bank_receipt` design is preserved in §15 for a later phase.
- Notifications are persisted (`notifications`, `notification_reads`): per-user read state, marked read only by an
  explicit tap (icon, action or row), never by visiting the page; unread count on Огляд практики and the tab badge;
  nothing on Головна (§9).
- Money display format is a profile setting, separate for the app (`display.money_format`, default `1 234.99`) and
  for invoices (`invoice.money_format`, default `2851.99`); `MoneyFormatter`; the format is stored in the invoice
  snapshot (§10).
- Invoice list: cursor-paginated infinite scroll, structured filters (period multi-select or date range, clients,
  currencies, statuses), no free-text search (§10, §16).
- New read endpoints: `overview/{year}/{quarter}` (Огляд практики) and `clients/{client}/stats` (§16).
- Login: e-mail + password only.
- Screen-level rules from the design prompts (Головна = open invoices only, bottom tab bar, receipt create/detail
  layout, no ЄП/ВЗ on invoice receipt cards, account page rows, Облік row/summary layout, client stats) in §16.
- Not yet applied, pending confirmation: `claude/prytax-design-review.md` Appendix A (refund reduces the **original**
  period at the original rate) and Appendix B items B1 (largest-remainder allocation shares), B2 (`fee_currency =
  currency_sent`, UAH-canonical net income), B5 (cascade `tax_payments.period_*` when `received_at` changes), B6
  (PDF rendered on demand if missing). Next revision.

### Changes from v2.1

- Receipt holdings on intermediate accounts are pro-rata of `amount_received` in the account's currency (fee and
  cross-currency hops), not Σ inbound `allocation.amount` (§6.2).
- Quarter obligation = year-to-date delta; standalone quarter and per-payment sums are cross-checks only (§8.1).
- `income.recognition = fop_bank_receipt` → `payments.received_at` nullable, `recognition_status`, explicit
  `recognise()` step; a receipt is never split into taxable events automatically. *(Superseded in v3.1: deferred to
  §15.)*
- Re-freeze rebuilds a `date_sequence` prefix; `invoices.sequence_scope_key` / `sequence_number` (§4, §5).
- Passive income (bank interest) is not income — moved to `account_movements`; `refunds` reduce income (п. 292.11).
- `esv_obligations` table (exempt months, 2022-03-01 … 2024-12-31 voluntary window); `tax_rules` gains
  `effective_to`, `esv_mandatory`, `esv_due_day`, `excess_rate`.
- `tax_periods.locked_at` + `PeriodLock`; business-day roll-over with a `holidays` table; ЄП/ВЗ deadline anchored on
  the statutory declaration date.
- `accounts.kind = personal`, `limit_window`, `account_opening_balances`, `account_movements`.
- `payments.receipt_group_id` (one wire / several invoices or tranches), `kind = advance | fx_gain`, `receipt_rate`.
- `tax_payment_requisites`; `clients.country / vat_number / *_uk`; `contract_rates`; `contracts.payment_terms_days`;
  `invoices.adjustment_amount`.
- Schema hygiene: generated `period_*` columns, functional unique index for `is_primary`, race-free sequence
  allocation, encrypted PII with HMAC lookup, CHECKs, optimistic locking, idempotency keys, activity log; own
  `profile_settings` instead of `spatie/laravel-settings`.

---

## 0. Tenancy model

```
organization (api-core)  1 ──── N  profiles (= one ФОП each; tax identity + settings)
       │                                 │
       └── N users (api-core)            └── N accounts / clients / invoices / payments / transfers / tax_* …
```

- **`organization`** = whoever runs the app: a solo developer, or an accountant's practice. Owned by api-core; not
  extended with columns.
- **`profile`** = one ФОП: tax identity (ПІБ, РНОКПП, address), settings, and every domain record. `profile_id` is the
  tenant key on domain tables; `organization_id` is not duplicated on them. Solo use = 1 organization / 1 profile;
  accountant = 1 organization / N profiles.
- **Why profiles live inside an organization**: api-core's `users.organization_id` is single-valued and
  `UNIQUE(organization_id, email)` makes a user a member of exactly one organization, so an accountant's client ФОПs
  must be inside the accountant's organization. "Organization per ФОП" remains expressible as one profile.
- **Users never own domain data.** `user_id` appears only as `created_by_user_id` / `updated_by_user_id` (audit, FK
  `SET NULL`). Access = organization membership + role. Three fixed roles — `owner | accountant | viewer` — as global
  spatie role rows (assignment is per user, so no `teams` feature is needed; enable it only if custom roles or the v2
  `profile_user` ACL ever require permissions per organization). v1: every user of an organization sees every
  profile; per-profile ACL is v2.
- **Global tables** (no tenant): `exchange_rates`, `tax_rules`, `holidays`.

### Scoping mechanism

1. Routes nest under the profile and use scoped bindings:
   ```php
   Route::middleware(['auth:sanctum', ResolveProfile::class])
       ->prefix('profiles/{profile}')
       ->scopeBindings()
       ->group(function (): void {
           Route::apiResource('accounts', AccountController::class);
           Route::apiResource('accounts.movements', AccountMovementController::class)->shallow();
           Route::apiResource('clients', ClientController::class);
           Route::apiResource('clients.contracts', ContractController::class)->shallow();
           Route::apiResource('clients.projects', ProjectController::class)->shallow();
           Route::apiResource('clients.charge-types', ChargeTypeController::class)->shallow();
           Route::apiResource('invoices', InvoiceController::class);
           Route::post('invoices/{invoice}/freeze', [InvoiceStatusController::class, 'freeze']);
           Route::post('invoices/{invoice}/unfreeze', [InvoiceStatusController::class, 'unfreeze']);
           Route::apiResource('invoices.payments', PaymentController::class)->shallow();
           Route::apiResource('payments.refunds', RefundController::class)->shallow();
           Route::apiResource('transfers', AccountTransferController::class);
           Route::post('transfers/propose', [AllocationProposalController::class, 'store']);
           Route::apiResource('tax-periods', TaxPeriodController::class)->only(['index', 'show', 'update']);
           Route::apiResource('tax-payments', TaxPaymentController::class);
           Route::get('clients/{client}/stats', ClientStatsController::class);
           Route::get('accounting/{year}/{quarter}', AccountingViewController::class);
           Route::get('overview/{year}/{quarter}', PracticeOverviewController::class);
           Route::get('declaration/{year}/{quarter}', DeclarationFiguresController::class);
           Route::apiResource('notifications', NotificationController::class)->only(['index', 'show']);
           Route::post('notifications/{notification}/read', [NotificationReadController::class, 'store']);
       });
   ```
   Scoped bindings resolve `{invoice}` through `Profile::invoices()`, so a foreign id is a 404 before controller code
   runs.
2. `ResolveProfile` loads `{profile}` (organization-scoped by api-core's trait on `Profile`), re-checks
   `organization_id === $request->user()->organization_id`, stores it in a `CurrentProfile` **scoped** singleton
   (`$this->app->scoped(...)`, Octane-safe).
3. `BelongsToProfile` trait on every `[P]` table: global scope `where profile_id = CurrentProfile::id()`, **fails
   closed** (`where 1 = 0`) without a profile, auto-fills `profile_id` on create. Console/jobs run inside
   `CurrentProfile::run($profile, fn () => …)`.
4. Cross-table consistency MySQL cannot CHECK (payment ↔ invoice ↔ account same profile; transfer endpoints same
   profile; tax payment ↔ payment same profile; refund ↔ payment) is asserted in the recording services and covered
   by isolation tests.

### Naming

Accountant's clients are **always** `profiles`; `clients` are invoice counterparties. If the accountant UI finds this
confusing, rename the table to `customers` and keep "Clients" as the UI label.

---

## 1. Tax rules the system encodes

| Rule | Value | Source |
|---|---|---|
| ЄП | 5 % of income | п. 293.3 ПКУ |
| ВЗ | 1 % of income, 2025-01-01 → 31 Dec of the year martial law ends (`tax_rules.effective_to`) | Закон 4015-IX |
| ЄСВ | 22 % × min wage, monthly: 2025 → 1 760.00; 2026 (min wage 8 647) → 1 902.34. Mandatory from 2025-01-01; voluntary 2022-03-01 … 2024-12-31 (`esv_mandatory`), territorial exemptions continue → `esv_obligations.status = exempt`. Higher voluntary payment allowed (≤ 15 × min wage) | Закон 2464-VI, 2120-IX, 4015-IX |
| Income cap | 1 167 × min wage: 2025 → 9 336 000; 2026 → 10 091 049. Excess taxed at `excess_rate` 15 %; general system from next quarter | п. 291.4, 293.4, 293.8 |
| Income date | Date funds are received; platforms: date of crediting on the platform (ДПС position) | п. 292.6 |
| FX conversion | NBU official rate on the income date, 4 decimals; UAH rounded to kopecks | п. 292.5 |
| Commission withheld on the way in | Income = full invoiced amount | ІПК 462/ІПК/99-00-24-03-03-06 від 01.03.2023 |
| Commission paid by client on top | Not income | — |
| Passive income (interest, dividends, royalties) | **Not** ЄП income; never a `payments` row (`account_movements.category = interest`) | п. 292.1 |
| Refunds | Reduce income in the period of the refund (`refunds` table) | п. 292.11 (5) |
| FX gain on currency sale | ДПС position (ЗІР 107.01.03): positive difference vs NBU is income. Off by default (`income.fx_gain`) | verify at implementation |
| Platform funds | Should reach a Ukrainian IBAN by quarter end; alert only, configurable, dismissible | ДПС/НБУ practice |
| Declaration | Quarterly, cumulative; due quarter end + 40 calendar days; Q4 = annual with Додаток 1 (ЄСВ) | п. 49.18.2, 296.3 |
| ЄП + ВЗ payment | Statutory declaration deadline + 10 calendar days (anchored on the **un-rolled** date) | п. 295.3 |
| ЄСВ payment | "до 20 числа" of the month after the quarter → `esv_due_day` default 19 | ч. 8 ст. 9 Закону 2464-VI |
| Roll-over | Declaration → next business day (п. 49.20); payments → next banking day (п. 57.1); martial law: holidays are working days (Закон 2136-IX) → `holidays` empty until then | ПКУ |

Computed calendar (before roll-over): Q1 10 May / 20 May / 19 Apr · Q2 9 Aug / 19 Aug / 19 Jul · Q3 9 Nov / 19 Nov /
19 Oct · Q4 9 Feb / 19 Feb / 19 Jan. 10 May 2026 is a Sunday → declaration 11 May, ЄП still 20 May.

All numbers live in `tax_rules`; `profiles.tax_group` supports `3` only in v1.

---

## 2. Key design decisions

1. **The taxable unit is a receipt, not an invoice.** Period = quarter of `payments.received_at`. "By invoice" is a
   sub-grouping and a tax-payment allocation mode, never a period assignment (invoice sent 28 Sep, paid 5 Oct → Q4).
2. **Snapshots, never recomputation.** `payments` stores rate, UAH, taxes, `tax_rule_id` on save; recomputed
   only when `amount`, `currency` or `received_at` of that row change. `exchange_rates` is a cache; `tax_rules` rows
   referenced by any payment are immutable.
3. **Rounding.** `uah_amount = ROUND_HALF_UP(amount × rate, 2)`; period income = `Σ uah_amount − Σ refunds.uah_amount`;
   per-payment taxes `ROUND_HALF_UP(uah_amount × pct, 2)` are hints; **YTD liability** `ROUND_HALF_UP(Σ YTD × pct, 2)`
   is the declaration figure and the quarter's obligation is `YTD(q) − YTD(q−1)`. Standalone `ROUND(Σ_q × pct)` and
   Σ hints are shown as cross-checks with their kopeck deltas (§8.1).
4. **Money arithmetic.** `DECIMAL(15,2)` amounts, `DECIMAL(12,4)` rates, `brick/money` + `brick/math`; API carries
   amounts and rates as strings. No floats.
5. **Invoice immutability by snapshot + state machine** (§4). `ready → draft` allowed without receipts; `sent`/`paid`
   never go back; `cancelled` + new invoice instead.
6. **Receipts and own-account transfers are different entities.** Receipt (client → my account) is 1:1 with the taxable
   event and lives on `payments`; own-account movements are `account_transfers` linked by `transfer_allocations`
   (M:N with amounts). Loss is Σ per-leg fees, never `invoiced − landed`; FX spread is measured on one date (§6.4).
7. **Holdings are pro-rata of what arrived.** A payment's balance on an account = receipt there + Σ inbound
   `alloc.amount / T.amount_sent × T.amount_received` − Σ outbound `alloc.amount`, in that account's currency (§6.2).
8. **Income is recognised on the first receipt** (the ДПС position): `received_at` is the date the money landed on
   the account named in the receipt, the snapshot is computed on save, and a receipt is exactly one taxable event. The
   alternative "recognise on arrival at the ФОП account" is designed but deferred (§15).
9. **Accounts are one table**, `kind ∈ {platform, fop_bank, foreign_bank, personal}`, one row per currency; receipt
   destinations, transfer endpoints and tax-payment sources; derived balance from opening balance + flows.
10. **Tax payments: one table, three allocation modes**; `period_year/quarter` always filled.
11. **Non-invoice income** that is taxable (platform bonus) is `payments.kind = other`; passive income is not income and
    goes to `account_movements`; refunds are `refunds` rows (negative in reports).
12. **ЄСВ is a monthly obligation row** generated from `tax_rules`, `due | exempt`; paid state is derived.
13. **Periods lock.** Once a declaration is submitted the period is locked; writes into it need a correction note and
    mark the period and every later period of the year `corrected` (§8.3).
14. **Invoices have a `source`** (`generated | external`); uploaded primary files are never overwritten.
15. **Notifications inform, never block.** They are persisted rows with per-user read state; read only by an
    explicit tap, never by loading a page; shown on their own screen, never as cards on Головна (§9).
17. **Formatting is presentation.** The API carries canonical decimal strings; how `1234.99` is displayed is a
    profile setting, separately for the app and for invoice PDFs (§10).
16. **Tenant key is `profile_id`** (§0).

---

## 3. Domain model (MySQL 8)

Legend: `[P]` = carries `profile_id` + `BelongsToProfile` (top-level resource); `[child]` = reached via parent
(nested scoped binding); `[global]` = no tenant. `ENC` = Laravel `encrypted` cast. Dates are `DATE`, Europe/Kyiv.
All `[P]` financial tables carry `created_by_user_id NULL`, `updated_by_user_id NULL` (FK `SET NULL`), timestamps.

```
-- api-core (unchanged): organizations, users(organization_id), roles/permissions, personal_access_tokens(organization_id)

profiles            organization_id → organizations (RESTRICT), tax_group(enum '3') DEFAULT '3',
                    full_name, full_name_en, tax_id VARCHAR(20) ENC, tax_id_kind(enum rnokpp|passport) DEFAULT rnokpp,
                    tax_id_hash CHAR(64)                       -- HMAC-SHA256(app key, tax_id); UNIQUE(organization_id, tax_id_hash)
                    registered_at DATE NULL, address, address_en, email, phone, default_currency(char3), locale,
                    signature_path NULL, is_active, timestamps, soft deletes
profile_settings    [P] key VARCHAR(64), value JSON     UNIQUE(profile_id, key)
                    -- read through ProfileSettings DTO (typed getters + defaults, per-request cache); no spatie/laravel-settings
                    invoice.numbering.prefix          = client_abbreviation | none            (client_abbreviation)
                    invoice.numbering.strategy        = date_sequence | sequence             (date_sequence)
                    invoice.numbering.scope           = per_client | global                   (per_client)
                    invoice.numbering.reset           = never | yearly                        (never)
                    invoice.numbering.date_source     = period_end | issued_at                (period_end)
                    invoice.footer_note               = text                                  (null)   -- VAT / reverse-charge note
                    invoice.money_format              = money format enum (§10)               (plain_dot  → 2851.99)
                    display.money_format              = money format enum (§10)               (space_dot  → 1 234.99)
                    income.taxable_base               = invoiced | received                   (invoiced)
                    -- income.recognition is NOT a phase-1 setting (first receipt only; see §15)
                    income.fx_gain                    = ignore | include                      (ignore)
                    taxes.default_grouping            = payment | month | quarter             (payment)
                    taxes.default_account_id          = accounts.id  (fop_bank, UAH, active)  (null)
                    compliance.platform_repatriation  = quarter_end | days:N | off            (quarter_end)
                    alerts.deadline_days              = int                                   (7)

accounts            [P] name, kind(enum platform|fop_bank|foreign_bank|personal), currency(char3),
                    holder_name, bank_name, iban ENC, swift, account_number ENC, details JSON,
                    limit_amount DECIMAL(15,2) NULL, limit_period(enum month|quarter|year) NULL,
                    limit_window(enum calendar|rolling) DEFAULT calendar,
                    limit_counts(enum receipts|all_inbound) DEFAULT receipts,
                    is_active, sort_order
                    -- personal: non-business — excluded from limits, income, repatriation; valid transfer endpoint and tax source
                    -- progress(receipts)    = Σ payments.received_amount (kind ∈ invoice|advance|other) INTO id IN window
                    -- progress(all_inbound) = receipts + Σ account_transfers.amount_received INTO id IN window
                    -- window: calendar → current month/quarter/year; rolling → [today − period, today]
account_opening_balances [P] account_id (RESTRICT), as_of DATE, amount DECIMAL(15,2)
                    UNIQUE(profile_id, account_id, as_of)
account_movements   [P] account_id (RESTRICT), direction(enum in|out), amount DECIMAL(15,2), booked_at DATE,
                    category(enum bank_fee|interest|expense|owner_withdrawal|other), note
                    -- non-taxable cash movements so the derived balance reconciles; never enters income
                    INDEX(account_id, booked_at)

clients             [P] name, abbreviation VARCHAR(10), legal_name, name_uk NULL, legal_name_uk NULL, country CHAR(2),
                    vat_number NULL, address JSON (lines, city, zip), tax_id NULL, email, invoice_footer_note NULL,
                    notes, is_active
                    UNIQUE(profile_id, abbreviation)   -- [A-Z0-9]{1,10}, typed in any case, stored upper case;
                                                       -- inactive clients keep theirs: two clients never share a prefix (§5)
contracts           [P] client_id, name, number NULL, started_at DATE NOT NULL, ends_at DATE NULL, currency,
                    payment_terms_days SMALLINT NULL, description_template, is_active
                    -- template: "IT services in accordance to the Service Agreement {name} dated {started_at:F j, Y}"
contract_rates      [child] contract_id, charge_type_id NULL, effective_from DATE, hourly_rate DECIMAL(15,2)
                    UNIQUE(contract_id, effective_from, (COALESCE(charge_type_id, 0)))   -- NULL charge_type = contract default
projects            [child of clients] client_id, code ("PROTO"), name, is_active
charge_types        [child of clients] client_id, name ("Billable","Overhead"), is_active

invoices            [P] client_id, contract_id NULL, receiving_account_id → accounts (RESTRICT), number VARCHAR(64),
                    sequence_scope_key VARCHAR(64) NULL, sequence_number INT UNSIGNED NULL,   -- NULL pair = user-supplied number
                    source(enum generated|external) DEFAULT generated,
                    period_start DATE, period_end DATE, issued_at DATE, due_at DATE NULL, currency,
                    subtotal DECIMAL(15,2), adjustment_amount DECIMAL(15,2) NOT NULL DEFAULT 0, adjustment_label NULL,
                    total DECIMAL(15,2),                        -- = subtotal + adjustment_amount
                    status(enum draft|ready|sent|paid|cancelled), locked_at, sent_at, paid_at, cancelled_at,
                    snapshot JSON NULL,                         -- {schema_version, money_format, profile, client, contract, account, items, totals, footer_note}
                    notes, timestamps, soft deletes
                    UNIQUE(profile_id, number), INDEX(profile_id, client_id, status), INDEX(profile_id, issued_at)
invoice_items       [child] invoice_id, position, project_id NULL, project_code VARCHAR, charge_type_id NULL,
                    charge_type VARCHAR, description TEXT, unit(enum hour|fixed|item),
                    quantity DECIMAL(10,2), unit_price DECIMAL(15,2), amount DECIMAL(15,2)
                    -- project_code / charge_type copied as text so the item survives lookup edits
invoice_files       [child] invoice_id, kind(enum generated|uploaded), is_primary BOOL, template_version NULL,
                    disk, path, original_name, mime, size, sha256, created_at
                    UNIQUE KEY uq_primary ((CASE WHEN is_primary THEN invoice_id END))   -- MySQL ≥ 8.0.13
invoice_sequences   [P] scope_key VARCHAR(64), last_number INT UNSIGNED     UNIQUE(profile_id, scope_key)

payments            [P] invoice_id NULL → invoices (RESTRICT), kind(enum invoice|advance|other|fx_gain), description NULL,
                    receipt_group_id CHAR(36) NULL,            -- one wire covering several invoices / tranches of one receipt
                    currency(char3), amount DECIMAL(15,2),     -- taxable base, invoice currency
                    client_fee_amount DECIMAL(15,2) NULL,      -- paid by client on top; not income
                    received_to_account_id → accounts (RESTRICT), received_amount DECIMAL(15,2), received_currency char3,
                    receipt_rate DECIMAL(12,4) NULL,           -- received_amount / amount when received_currency ≠ currency
                    received_at DATE NOT NULL,                 -- tax date = date landed on received_to_account (first receipt)
                    nbu_rate DECIMAL(12,4), nbu_rate_date DATE, exchange_rate_id NULL,
                    uah_amount DECIMAL(15,2), tax_rule_id,
                    single_tax_uah DECIMAL(15,2), military_levy_uah DECIMAL(15,2),
                    period_year    SMALLINT GENERATED ALWAYS AS (YEAR(received_at))    STORED,
                    period_quarter TINYINT  GENERATED ALWAYS AS (QUARTER(received_at)) STORED,
                    period_month   TINYINT  GENERATED ALWAYS AS (MONTH(received_at))   STORED,
                    repatriation_ack_at TIMESTAMP NULL
                    CHECK (kind <> 'invoice' OR invoice_id IS NOT NULL)
                    INDEX(profile_id, period_year, period_quarter), INDEX(invoice_id),
                    INDEX(received_to_account_id, received_at), INDEX(receipt_group_id)
                    -- service assertions: invoice.profile_id = account.profile_id = profile; invoice.status ∈ ready|sent|paid;
                    --   received_currency = account.currency; kind=fx_gain rows have received_amount = 0 (no balance/limit effect)
refunds             [P] payment_id → payments (RESTRICT), amount DECIMAL(15,2), currency char3, refunded_at DATE,
                    from_account_id → accounts (RESTRICT), nbu_rate DECIMAL(12,4), exchange_rate_id NULL,
                    uah_amount DECIMAL(15,2), tax_rule_id, reason,
                    period_year/quarter/month GENERATED from refunded_at
                    INDEX(profile_id, period_year, period_quarter), INDEX(payment_id)
                    -- Σ refunds.amount per payment ≤ payment.amount; reduces invoice paid_amount and period income

account_transfers   [P] from_account_id → accounts, to_account_id → accounts (both RESTRICT; same profile asserted),
                    amount_sent DECIMAL(15,2), currency_sent char3,
                    amount_received DECIMAL(15,2), currency_received char3,
                    fee_amount DECIMAL(15,2) NULL, fee_currency char3 NULL,
                    nbu_rate DECIMAL(12,4) NULL,               -- FX transfers only: NBU(currency_sent, received_at)
                    sent_at DATE NULL, received_at DATE, reference, note
                    INDEX(profile_id, received_at), INDEX(from_account_id), INDEX(to_account_id)
                    -- same currency: fee_amount defaults to amount_sent − amount_received; if given, must equal it
                    -- FX: fee_amount explicit; fx_spread = amount_sent × nbu_rate − amount_received (UAH leg only)
transfer_allocations [child] transfer_id → account_transfers (CASCADE), payment_id → payments (RESTRICT),
                    amount DECIMAL(15,2)                       -- in currency_sent
                    UNIQUE(transfer_id, payment_id), INDEX(payment_id)
                    -- Σ per transfer ≤ amount_sent; Σ out of X for P ≤ holding(P, X) (§6.2)

exchange_rates      [global] currency char3, rate_date DATE, rate DECIMAL(12,4), source(enum nbu|manual), fetched_at
                    UNIQUE(currency, rate_date)
holidays            [global] date DATE (uq), name                 -- empty while martial law lasts
tax_rules           [global] effective_from DATE (uq), effective_to DATE NULL,
                    single_tax_rate DECIMAL(6,4), military_levy_rate DECIMAL(6,4), excess_rate DECIMAL(6,4),
                    min_wage DECIMAL(10,2), esv_monthly DECIMAL(10,2), esv_mandatory BOOL, esv_due_day TINYINT,
                    income_cap_uah DECIMAL(15,2)
                    rows: 2025-01-01 (0.05, 0.01, 0.15, 8000, 1760.00, true, 19, 9336000)
                          2026-01-01 (0.05, 0.01, 0.15, 8647, 1902.34, true, 19, 10091049)
                    -- earlier rows only if history before 2025 is imported (esv_mandatory = false for 2022-03-01 … 2024-12-31)
                    -- observer: a row referenced by any payment/refund/esv_obligation is immutable

tax_periods         [P] year SMALLINT, quarter TINYINT, is_annual BOOL,
                    declaration_status(enum none|filled|submitted|rejected|accepted|corrected),
                    declaration_submitted_at NULL, declaration_due_at DATE, tax_due_at DATE, esv_due_at DATE,
                    locked_at TIMESTAMP NULL, notes
                    UNIQUE(profile_id, year, quarter)
                    -- liability is COMPUTED; this row holds status, deadlines and the lock
esv_obligations     [P] year SMALLINT, month TINYINT, tax_rule_id, amount_due DECIMAL(10,2), status(enum due|exempt), note
                    UNIQUE(profile_id, year, month)
                    -- paid = Σ tax_payments(social_contribution, status=paid) mapped to the month; higher amounts allowed
tax_payments        [P] tax_type(enum single_tax|military_levy|social_contribution),
                    amount DECIMAL(15,2), paid_at DATE NULL, status(enum sent|paid|failed),
                    allocation(enum payment|month|quarter),
                    payment_id NULL → payments (RESTRICT), period_year SMALLINT NOT NULL, period_quarter TINYINT NOT NULL,
                    period_month TINYINT NULL, paid_from_account_id NULL → accounts (RESTRICT), reference, note
                    CHECK (allocation <> 'payment' OR payment_id IS NOT NULL)
                    CHECK (allocation <> 'month'   OR period_month IS NOT NULL)
                    CHECK (allocation <> 'quarter' OR period_month IS NULL)
                    CHECK (amount > 0)
                    CHECK (status <> 'paid' OR paid_at IS NOT NULL)
                    INDEX(profile_id, tax_type, period_year, period_quarter, status), INDEX(paid_from_account_id, paid_at)
                    -- allocation=payment: period_* copied from the payment's received_at (service assertion)
                    -- paid_from_account: currency = UAH and is_active (Form Request); UI defaults to taxes.default_account_id
tax_opening_balances [P] tax_type, as_of DATE, amount DECIMAL(15,2)      UNIQUE(profile_id, tax_type, as_of)
tax_payment_requisites [P] tax_type, iban, recipient_name, recipient_code, purpose_template, effective_from DATE
                    UNIQUE(profile_id, tax_type, effective_from)
                    -- copy-ready block on the tax-payment form; purpose_template placeholders {tax_id} {period} {full_name}

notifications       [P] type VARCHAR(48), severity(enum info|warning|error), dedupe_key VARCHAR(120),
                    title, body, payload JSON, action_route VARCHAR(120) NULL, created_at, resolved_at NULL
                    UNIQUE(profile_id, dedupe_key), INDEX(profile_id, resolved_at, created_at)
                    -- upserted by NotificationSync from AlertCollector rules; resolved when the condition clears; never deleted
notification_reads  notification_id → notifications (CASCADE), user_id → users (CASCADE), read_at
                    PRIMARY KEY(notification_id, user_id)
                    -- read state is per user; written only by POST notifications/{id}/read (explicit tap), never by listing

idempotency_keys    user_id, key VARCHAR(64), request_hash CHAR(64), response_status, response_body JSON, created_at
                    UNIQUE(user_id, key); purged after 24 h
activity_log        spatie/laravel-activitylog on invoices, invoice_items, payments, refunds, account_transfers,
                    transfer_allocations, tax_payments, tax_periods, profile_settings
```

---

## 4. Invoice lifecycle

```
draft ──(freeze)──▶ ready ──▶ sent ──▶ paid
  ▲                  │
  └───(unfreeze)─────┘      ready | sent ──▶ cancelled
```

| Transition | Preconditions | Effects |
|---|---|---|
| freeze `draft → ready` (`InvoiceFreezer`) | `generated`: items ≥ 1; `external`: an uploaded `is_primary` file. `receiving_account.is_active` | Number: allocate if blank, **rebuild** if generator-allocated (§5); build `snapshot` (profile, client, contract, account incl. details, items, `subtotal`, `adjustment_*`, `total`, footer note, `money_format` = current `invoice.money_format`, `schema_version`); set `locked_at`; `DB::afterCommit` → render PDF to `invoice_files(generated, is_primary)` unless an uploaded primary exists |
| unfreeze `ready → draft` | No payments, no refunds | Delete **generated** files only; null `snapshot`, `locked_at`; keep `number`, `sequence_*` |
| `ready → sent` | — | `sent_at` (editable) |
| `sent → paid` | Suggested when `Σ payments.amount − Σ refunds.amount ≥ total`; confirmed manually | `paid_at` defaults to the last receipt's `received_at` |
| `ready \| sent → cancelled` | — | `cancelled_at`; number never reused |
| `paid → *` | never | — |

- Partial payment is derived (`paid / total`); overpayment shown as `paid > total`.
- Enforcement: `InvoiceObserver@updating/@deleting` and `InvoiceItemObserver` throw `InvoiceLockedException` unless
  `status = draft` or the dirty set ⊆ `{status, sent_at, paid_at, cancelled_at, notes}`. `InvoicePolicy@update`
  mirrors it. Updates carry `If-Unmodified-Since: updated_at` → 409 on a stale write.
- PDF is rendered only from `snapshot`; `invoice_files.template_version` + `snapshot.schema_version` keep old invoices
  renderable after template changes.

## 5. Numbering

`InvoiceNumberGenerator` (interface) → `DateSequenceGenerator` (`{YYYY-MM-DD}-{n}`, date from
`invoice.numbering.date_source`) and `SequenceGenerator` (`{n}`), each prefixed with `{ABBREVIATION}-` — the client's
`abbreviation` — when `invoice.numbering.prefix = client_abbreviation` (the default). Scope key = `global` |
`client:{id}`, suffixed `:{year}` when `reset = yearly`; sequences are per profile.

| prefix | strategy | scope | Number |
|---|---|---|---|
| `client_abbreviation` | `date_sequence` | `per_client` | `BL-2026-10-18-13` — **the default**: the client's 13th invoice |
| `client_abbreviation` | `sequence` | `per_client` | `BL-13` |
| `client_abbreviation` | `date_sequence` | `global` | `BL-2026-10-18-41` — the profile's 41st invoice |
| `none` | `date_sequence` | `global` | `2026-10-18-41` |
| `none` | `date_sequence` / `sequence` | `per_client` | `2026-10-18-13` / `13` — two clients' numbers can collide (see below) |

The scope key carries the client's **id**, never its abbreviation, so a changed abbreviation keeps its counter. The
abbreviation is read when the number is formatted (preview, freeze, re-freeze), not stored on the invoice apart from
the number itself.

Allocation (race-free, including the first row of a scope):

```sql
INSERT INTO invoice_sequences (profile_id, scope_key, last_number) VALUES (?, ?, LAST_INSERT_ID(1))
ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1);
SELECT LAST_INSERT_ID();   -- 1 when the scope row was just created, last_number + 1 otherwise
```

`last_number` is the last allocated `{n}` for the scope (the column is not an auto-increment, so `LAST_INSERT_ID(expr)`
carries the allocated value on both paths). Fallback if the driver misbehaves: `lockForUpdate()->first()` with a
single retry on the unique violation of the first insert.

Rules:
- Draft shows a preview (`next: BL-2026-10-14-13`) computed without consuming the sequence.
- On freeze the invoice stores `sequence_scope_key` and `sequence_number`; a user-typed number leaves both NULL and
  bypasses the generator (`UNIQUE(profile_id, number)` guards collisions, 422 on conflict).
- **Re-freeze** (`InvoiceNumberGenerator::rebuild`): if `sequence_*` are set and the scope key computed from the
  current dates equals the stored one, rebuild the abbreviation and the date from the current client and date source
  with the same `{n}` (`BL-2026-10-14-13` → `BL-2026-10-15-13`); if the scope key changed (yearly reset, year moved,
  another client), allocate a new `{n}` in the new scope and leave the old one as a gap; user-typed numbers are never
  touched.
- Gaps after unfreeze/cancel are acceptable.
- **Abbreviations** (`clients.abbreviation`): required for a new client, 1–10 upper-case Latin letters and digits
  (typed in any case, stored upper case), unique within the profile among all its clients, inactive ones included —
  so with the prefix, per-client counters never give two clients one number. The client form suggests one from the
  initials of the name (legal forms such as LLC or GmbH left out; the first three letters of a one-word name) until it
  is typed. It may be changed later: numbers already given keep the old prefix, drafts and re-freezes take the new
  one. Clients created before abbreviations existed get one derived the same way, made unique with a digit suffix.
- A generated number can still collide: without the prefix, per-client counters give two clients the same number
  for the same date; with it, only when an abbreviation was given up and another client took it. The freeze then
  answers 422 (`Another invoice has the number … already.`) and the invoice stays a draft; the numbering screen warns
  about `none` with `per_client`.

## 6. Receipts, transfers, holdings and loss metrics

### 6.1 Recording a receipt (`PaymentRecorder`)

- Asserts profile consistency, `invoice.status ∈ ready|sent|paid`, `received_currency = account.currency`,
  `PeriodLock::assertOpen(received_at)` (§8.3).
- `received_at` = the date the money landed on `received_to_account` (platform date when a platform was involved,
  bank date for a direct wire) — the first receipt, which is the ДПС position. The rate and tax snapshot are
  computed on save; one receipt = one taxable event. There is no recognition toggle in phase 1 (§15).
- Editing `amount`, `currency` or `received_at` re-runs the snapshot (subject to the period lock on both the old and
  the new period).
- `kind = advance`: `invoice_id NULL` at creation, linkable to an invoice later (`PATCH` sets `invoice_id`, kind →
  `invoice`); income is recognised on receipt regardless.
- One wire for several invoices: N rows with one `receipt_group_id`, `received_amount` and the receipt fee split
  pro-rata by `amount` (UI action "record one receipt for several invoices").

### 6.2 Holdings (`HoldingCalculator`, used by `AllocationProposer` and `TransferRecorder`)

In the currency of account X:

```
holding(P, X) = receipt(P on X)                                   -- received_amount if received_to_account_id = X
              + Σ_{T: to=X}   alloc(T,P).amount / T.amount_sent × T.amount_received
              − Σ_{T: from=X} alloc(T,P).amount
```

A $1 000 receipt on Payoneer moved to Wise with a $15 fee holds $985 on Wise; Payoneer USD → Wise EUR leaves a EUR
holding on Wise that bounds the next hop (whose `currency_sent` is EUR). Invariants enforced by `TransferRecorder`:
`Σ alloc per transfer ≤ amount_sent`; `Σ alloc out of X for P ≤ holding(P, X)`; both accounts same profile; same-currency
fee rule; FX fee explicit.

### 6.3 Allocation proposal (`AllocationProposer`)

Given `(from_account, amount_sent)`: first an exact-match proposal (one receipt whose holding equals `amount_sent`),
then FIFO over receipts with `holding > 0` on the source account ordered by `received_at`, capped at `amount_sent`.
Both proposals are returned; the user edits before saving.

### 6.4 Fees, spread, metrics

Per payment P:
- `receipt_fee` = `amount − received_amount` (same currency; else NULL).
- `receipt_fx_spread` = `amount × nbu_rate − received_amount` when `received_currency = UAH ≠ currency` (platform
  paying out straight to a UAH account); NULL otherwise.
- `transfer_fee_share` = Σ_T `alloc(T,P).amount / T.amount_sent × T.fee_amount`; aggregated in UAH at NBU on
  `T.received_at`.
- `transfer_loss` = `receipt_fee + transfer_fee_share` — fees only.
- `landed_on_fop_bank` = Σ over `fop_bank` accounts of (receipt there + inbound pro-rata amounts).
- `still_on_platform` = `holding(P, received_to_account)` for platform receipts — cash position, not loss.
- `net_income` = `received_amount − transfer_fee_share − (single_tax_uah + military_levy_uah) / nbu_rate` (payment
  currency; ЄСВ excluded).

Per FX transfer T (UAH leg): `fx_spread = amount_sent × NBU(currency_sent, T.received_at) − amount_received`;
effective rate `amount_received / amount_sent` shown next to NBU. NBU drift between receipt and withdrawal is excluded
by design.

### 6.5 FX gain (`income.fx_gain = include`)

When `fx_spread(T) < 0` and `T.to_account.kind = fop_bank`, `TransferRecorder` creates/updates a synthetic
`payments` row: `kind = fx_gain`, `currency = UAH`, `amount = −fx_spread`, `received_amount = 0`,
`received_to_account_id = T.to_account_id`, `received_at = T.received_at`, description referencing T. Deleted with T.
Excluded from limits and balances; included in income and taxes.

### 6.6 Refunds (`RefundRecorder`)

`refunds` row per returned amount: NBU rate on `refunded_at`, `uah_amount` negative in every income aggregation,
period from `refunded_at`, `Σ refunds ≤ payment.amount`, period lock applies, invoice `paid_amount` reduced.

### 6.7 Compliance

Platform receipt whose `landed_on_fop_bank < received_amount` past the rule in `compliance.platform_repatriation`
(quarter end of `received_at` | `received_at + N days` | off) → warning; dismissible per payment
(`repatriation_ack_at`); never blocking.

## 7. Accounting view and exports

Per profile, per quarter, rows by `received_at` (payments and refunds), sub-groupable by invoice or month. Row:
date · invoice number (prominent) + client / description · `uah_amount` bold with `USD 516.27 @ 42.2297` beneath
(omitted for UAH); then, under a dashed divider, two lines of two equal columns: `ЄП · ВЗ` and `комісії · чистий
дохід` (§16). Summary: Σ income; **owed** ЄП/ВЗ (`YTD(q) − YTD(q−1)`), standalone `ROUND(Σ_q × pct)` and Σ hints as
cross-checks with kopeck deltas; ЄСВ = Σ `esv_obligations(status = due)` of the quarter; YTD income/liabilities; YTD
vs cap (%) — laid out one figure per row, never squeezed (§16).
Also: 12-row ЄСВ table per year; monthly income table (п. 296.1); exports CSV/XLSX (quarter, year, Облік доходів) and
a yearly PDF summary. Organization board: one row per profile with the footer and deadline status.

## 8. Liabilities, periods, locks, reconciliation

### 8.1 `TaxLiabilityCalculator::forQuarter(profile, year, q)` → `QuarterLiability`

```
ytd_income          = Σ payments.uah_amount − Σ refunds.uah_amount   (1 Jan … end of q)
previous_ytd_income = same through end of q−1 (0 for Q1)
ytd.{ep, vz}        = ROUND_HALF_UP(ytd_income × rate, 2)
previous_ytd.{ep,vz}= ROUND_HALF_UP(previous_ytd_income × rate, 2)
owed.{ep, vz}       = ytd − previous_ytd                             -- AUTHORITATIVE (declaration + ІКП figure)
standalone.{ep, vz} = ROUND_HALF_UP(quarter_income × rate, 2)        -- cross-check
hints_sum.{ep, vz}  = Σ single_tax_uah / military_levy_uah           -- cross-check
esv_owed            = Σ esv_obligations.amount_due WHERE status = due AND month ∈ q
cap                 = {ytd_income, income_cap_uah, pct, excess = max(0, ytd − cap), excess_tax = ROUND(excess × excess_rate, 2)}
```

Per-month and per-payment views reuse the same inputs. A rate that changes mid-year (`effective_to`, e.g. ВЗ ending)
splits the YTD figure into tax-rule segments: `ytd = ROUND_HALF_UP(Σ_segments (segment_income × segment_rate), 2)`,
where each payment's segment is its stored `tax_rule_id`; with one rule per year this collapses to the formula above.

### 8.2 Reconciliation (`TaxReconciler`)

```
paid_q    = Σ tax_payments.amount WHERE status = paid AND tax_type = t AND maps to q   (any allocation)
pending_q = Σ … WHERE status = sent
balance_q = opening_balance(t) + Σ_{k ≤ q} (paid_k − owed_k)       -- across year boundaries
```

`failed` excluded. `balance > 0` overpaid / carried forward; `< 0` outstanding. Views by payment (own tax payments
per row; month/quarter-level ones as "covers N payments"), by month, by quarter; default `taxes.default_grouping`.

### 8.3 Tax periods, deadlines, locks

- `DeadlineScheduler` (daily, idempotent) creates `tax_periods` for every active profile for the current and next
  quarter. `BusinessDayCalendar` (weekends + `holidays`): `declaration_due = rollForward(q_end + 40 d)`;
  `tax_due = rollForward(q_end + 40 d + 10 d)` (anchored on the un-rolled statutory date);
  `esv_due = rollForward(first day of month after q + esv_due_day − 1)`. `is_annual = (quarter = 4)`.
- `EsvObligationScheduler` creates `esv_obligations` rows per month from `tax_rules` (`amount_due = esv_monthly`,
  `status = due`, or `exempt` when `esv_mandatory = false`).
- `PeriodLock`: `declaration_status → submitted|accepted` sets `locked_at`. `PaymentRecorder`, `RefundRecorder` and
  `TaxPaymentRecorder` call `assertOpen(profile, date)`; a locked
  period throws `PeriodLockedException` (409) unless the request carries `correction_note`, in which case the write
  proceeds, the period and every later period of the same year get `declaration_status = corrected`, and an alert
  "уточнююча декларація required for Q…" is raised naming each affected quarter.
- `DeclarationFigures` (screen + endpoint): income YTD, ЄП YTD, ЄП previously declared (= `previous_ytd`), ЄП to pay
  (= `owed`); same for ВЗ; annual: ЄСВ months. Reads `QuarterLiability` only.

## 9. Notifications (`AlertCollector` → `NotificationSync`)

`AlertCollector` evaluates the rules below per profile (after the writes that can change them, and in a daily job).
`NotificationSync` upserts one `notifications` row per `dedupe_key` (e.g. `limit:{account_id}:80`,
`deadline:{tax_period_id}:tax`, `repatriation:{payment_id}`, `cap:90`), sets `resolved_at` when the condition no
longer holds, and never deletes rows. Read state is per user in `notification_reads` and is written **only** by
`POST notifications/{id}/read`, which the client calls when the user taps the mark-read icon, the notification's
action, or the row itself — never on listing or on opening the Сповіщення screen. Unread = unresolved and not read by
the current user; the count is shown on the tab badge and on Огляд практики. Dismissing a repatriation alert
(`repatriation_ack_at`) resolves its notification. Phase 1 shows notifications only on their own screen — none on
Головна (§16).

| Notification | Trigger | Severity |
|---|---|---|
| Deadline | `*_due_at` within `alerts.deadline_days` or past while `balance < 0` | warning / error |
| ЄСВ month | obligation `due`, unpaid past `esv_due_at` | warning |
| Platform repatriation | §6.7 | warning (dismissible) |
| Account limit | progress ≥ 80 % / ≥ 100 % | warning / error |
| Income cap | YTD ≥ 80 % / 90 % / 100 %; shows `excess_tax` | warning / error |
| Correction needed | `declaration_status = corrected` | error |
| Contract | `ends_at` within 30 days / past with open invoices | info |
| Invoice overdue | `due_at < today`, not fully paid | info |
| Ready without receipts | `ready` older than N days | info |

Summary figures live on their own screens in phase 1: YTD vs cap, quarter invoiced/received, quarter taxes and the
unread count on Огляд практики; balances, inbound and outbound rows on the account screen; open invoices on Головна
(§16). The organization-wide board (one row per profile) and the richer Home of the redesign brief are later phases.

## 10. API conventions and security

- Money and rates as strings in `JsonResource`s; Form Requests validate decimals as strings (`decimal:0,2`, `0,4`);
  dates `YYYY-MM-DD`. The API never formats money.
- **Money display format** (`MoneyFormatter`, Vue composable + the PDF renderer): `display.money_format` for the app
  (default `space_dot`) and `invoice.money_format` for PDFs (default `plain_dot`); values and samples:
  `plain_dot 2851.99` · `space_dot 2 851.99` · `comma_dot 2,851.99` · `plain_comma 2851,99` · `space_comma 2 851,99`
  · `dot_comma 2.851,99`. Grouping uses U+202F (narrow no-break space) so an amount never wraps. Inputs are
  `inputmode="decimal"`, accept `.` or `,` as the decimal separator and ignore grouping characters; the client
  normalises to the canonical `1234.99` before sending. The invoice snapshot stores the `money_format` in force at
  freeze so a PDF re-renders identically after the setting changes.
- **Auth**: e-mail + password only (Sanctum SPA cookie; tokens for integrations). No self-signup variants, social
  login, passkeys or 2FA in phase 1.
- **List endpoints**: cursor pagination through api-core (`cursor`, `per_page` ≤ 50, default 20) for infinite scroll.
  Invoices: default order `issued_at DESC, id DESC`; filters `period[]` (`2026`, `2026-Q4`, `2026-10`, any mix,
  matched on `issued_at`) **or** `issued_from` / `issued_to`, plus `client_id[]`, `currency[]`, `status[]`. No
  free-text search in phase 1. Filters are mirrored in the URL query so a filtered list is linkable.
- `POST` payments/refunds/transfers/tax-payments accept `Idempotency-Key`; replay returns the stored response.
- `PATCH` on invoices requires `If-Unmodified-Since` = `updated_at`; mismatch → 409.
- Deletes: hard delete only when nothing references the row (FKs `RESTRICT`) and the period is open; otherwise a
  reversing row. Soft deletes only on `profiles` and `invoices`.
- Encrypted casts: `profiles.tax_id`, `accounts.iban`, `accounts.account_number`; lookup/uniqueness via `tax_id_hash`.
- Files on a private disk; signed URLs (15 min); mime/size validation; `sha256` dedupe.
- No PII in logs; auth rate-limited by api-core.
- `NbuRateProvider`: `GET …/statdirectory/exchange?valcode={CUR}&date=YYYYMMDD&json`; rate extracted from the raw
  body with `preg_match('/"rate":\s*([0-9.]+)/')` → `BigDecimal::of()`; cached in `exchange_rates`; `FetchNbuRates`
  nightly for every currency used by active profiles; on failure the form exposes a manual-rate field
  (`source = manual`) and the failure is logged — never a silent default.

## 11. Services

`CurrentProfile` · `ResolveProfile` · `BelongsToProfile` · `ProfileSettings` (DTO + repository) · `NbuRateProvider` +
`FetchNbuRates` · `PaymentRecorder` (`record`, `recordGroup`) · `RefundRecorder` · `TransferRecorder` ·
`AllocationProposer` · `HoldingCalculator` · `AccountBalanceCalculator` · `AccountLimitCalculator` · `TaxRuleResolver`
(`forDate()`) · `TaxLiabilityCalculator` (`QuarterLiability`) · `TaxReconciler` · `TaxPaymentRecorder` · `PeriodLock` ·
`DeadlineScheduler` + `BusinessDayCalendar` · `EsvObligationScheduler` · `DeclarationFigures` · `InvoiceFreezer` ·
`InvoiceNumberGenerator` (`allocate`, `preview`, `rebuild`) · `InvoicePdfRenderer` · `MoneyFormatter` ·
`AlertCollector` + `NotificationSync` · `PracticeOverview` (§16: cap, quarter invoiced/received, quarter taxes,
unread count) · `ClientStats` (§16) · `AccountingExporter` · `SheetImporter`.

## 12. Stack and packages

api-core · Laravel 12/13 · MySQL 8 (≥ 8.0.13 for functional indexes) · Vue 3 + TypeScript + Tailwind, Sanctum SPA/token
auth · `brick/money` · `barryvdh/laravel-dompdf` with a bundled Cyrillic font (`spatie/laravel-pdf` + Chromium if
flex/grid is needed) · `spatie/laravel-activitylog` · `spatie/laravel-permission` (via api-core) · Form Requests +
Policies. **Not** `spatie/laravel-settings` (per-profile keys need custom work; own table + DTO is less code).

## 13. Tests (Pest)

Rounding identities (`|Σ hints − standalone| ≤ 0.01 × n`, `owed` vs standalone kopeck delta) · YTD delta across a
rate change · numbering under concurrency incl. first-row race, prefix rebuild, year rollover, user number preserved,
the client abbreviation prefix and its uniqueness per profile ·
lock enforcement (observer + policy + 409) · quarter assignment of cross-quarter receipts · holdings with a fee hop and
a cross-currency hop; allocation bounds; FIFO and exact-match proposals · fee/spread/net math; `receipt_fx_spread`;
`fx_gain` synthetic row lifecycle · one wire → several invoices (`receipt_group_id`, pro-rata fee) · refunds reduce
income and `paid_amount` · period lock with and without `correction_note`; cascade of `corrected` · deadline calendar
(10 May 2026 → 11 May; ЄП stays 20 May; ЄСВ 19th) · ЄСВ exempt months · NBU provider with HTTP fakes (number parsing,
failure path) · PDF golden files per `schema_version` and per `money_format` · `MoneyFormatter` for all six formats
and both separators on input · notifications: listing never writes `notification_reads`, `read` endpoint does; unread
count per user; dedupe/resolve cycle · invoice list filters and cursor paging · `ClientStats` and `PracticeOverview`
figures against fixtures · **profile isolation** (cross-profile id → 404; fail-closed scope; console context) ·
idempotent replays.

## 14. Build order

1. api-core install; `profiles`, `profile_settings` + DTO; `CurrentProfile` / `ResolveProfile` / `BelongsToProfile`;
   roles; `tax_rules`, `holidays` seed; `exchange_rates` + `NbuRateProvider` + `FetchNbuRates`.
2. Accounts (+ opening balances, movements, limits with calendar/rolling windows), clients, contracts (+ rates),
   projects, charge types.
3. Invoices + items + adjustment, numbering (allocate/preview/rebuild), freeze/unfreeze/snapshot/PDF, files with
   functional unique, state machine, optimistic locking.
4. Payments (record, receipt groups, advances), refunds, transfers + allocations, `HoldingCalculator`,
   `AllocationProposer`, fee/spread/net metrics, fx-gain, balances, idempotency; `ClientStats`.
5. `tax_periods` + deadlines + business-day calendar, `esv_obligations`, accounting view (§16 layout), exports.
6. Tax payments (3 allocations), requisites, `QuarterLiability`, reconciliation, opening balances, period locks and
   corrections, declaration figures, `PracticeOverview`, notifications (`AlertCollector` + `NotificationSync`, read
   endpoint).
7. `SheetImporter` for the 2025 sheet (`--profile=`, `--dry-run`): external invoices, payments, transfers, tax
   payments, ЄСВ months (paid/exempt); totals must reconcile to the sheet within the rounding policy.

Phase-1 screens (§16) are built alongside the step that provides their data: login + settings (incl. money format)
with step 1, Інвойси list/detail with step 3, receipt create/detail and account page with step 4, Облік with step 5,
Огляд практики and Сповіщення with step 6.

## 15. Deferred (later phases)

`profile_user` per-profile ACL · bank/platform statement imports and auto-matching (Monobank, PrivatBank, Payoneer,
Wise) · recurring invoices / "Повторити інвойс" and time-tracking import · invoice e-mailing (`invoice_emails`),
share sheet and overdue reminders · push / e-mail / Telegram delivery of notifications · client portal / share links ·
`tax_balance_checks` against the ІКП · ДПС XML declaration export · Acts of acceptance; bilingual PDF template ·
expenses as a feature (beyond `account_movements`) · other tax schemes (`tax_scheme`: 3 % + ПДВ, 2 група) ·
multi-organization users (api-core `organization_user` pivot) · free-text search on lists · passkeys / 2FA /
self-signup · organization board and desktop layout · the redesign brief's Гроші tab, four-bucket balances and hero
card.

**Income recognition on ФОП-account arrival** (`income.recognition = fop_bank_receipt`), designed in v3 and removed
from phase 1 by the design prompts. Kept here so it can be restored additively: `payments.received_at DATE NULL` +
`recognition_status(enum recognised|pending)` + `CHECK (recognition_status <> 'recognised' OR received_at IS NOT
NULL)`; snapshot columns nullable while pending; `POST payments/{payment}/recognise {received_at}` computes the
snapshot; a platform receipt is saved `pending` and excluded from every liability; the landing allocation on a
`fop_bank` account proposes the date; tranches across quarters are split by the user into sibling rows sharing
`receipt_group_id` (never automatically); an "awaiting recognition" notification and an `is_pending` filter on the
receipt list; the setting and the toggle on the receipt form return at the same time.

## 16. Phase-1 UI scope ("Simplified")

Phase 1 is the first implementable cut: every fundamental flow, no secondary options. The full redesign brief
(`claude/prytax-design-review.md` — Гроші tab, four-bucket tax balance, hero card, share sheet, desktop layout, …) is
the target for later phases; where the two differ, phase 1 follows this section. Mobile-first (390 px frames),
Ukrainian UI, bottom tab bar, bottom sheets for secondary flows; the invoice screens of the mock are kept as they are.

| Screen | Phase-1 content and behaviour |
|---|---|
| Login | E-mail + password only. |
| Navigation | Bottom tab bar on every top-level screen; the square navigation buttons at the top of Головна are removed. Tabs follow the Simplified mock (working set: Головна · Інвойси · Облік · Огляд · Ще → Рахунки, Клієнти, Сповіщення, Налаштування). |
| Головна | **Open invoices only** (`ready`, `sent`), grouped by month of `period_end` (newest month first), ordered by `period_start` inside the month; row = number · client · period · total · status chip. No notification cards, no summary widgets — everything else has its own page. |
| Огляд практики | One quarter at a time with `‹ ›` navigation like a calendar (`GET overview/{year}/{quarter}`). Full-width rows in this order under the ФОП name: (1) **yearly cap** progress bar — YTD income vs `income_cap_uah` (the bar that used to sit on Головна); (2) **quarter income** — invoiced vs received for the quarter's invoices: `invoiced = Σ total` and `received = Σ paid_amount` (net of refunds) over invoices with `issued_at ∈ q` and `status ∉ {draft, cancelled}`, per currency, e.g. `отримано $7 899.00 з $10 000.00`; (3) **taxes** — three columns ЄСВ · ЄП · ВЗ showing the quarter's `owed` (§8.1) with paid / outstanding; (4) **unread notifications** count. Removed: nearest deadline, "declarations sent", "total credit" and "total notifications" cards. |
| Сповіщення | Persisted notifications (§9), newest first, unread emphasised. Each row: mark-as-read icon button · title/body · action. Opening the screen or scrolling the list **never** marks anything read; a tap on the icon, the action or the row calls `POST notifications/{id}/read`. |
| Інвойси (list) | Infinite scroll on cursor pagination (§10). Row = number · client · period · status chip · total in the invoice currency. **No** "не оплачено" text and no outstanding amount in the row. **No text input**; a filter bar instead: period (year / quarter / month, multi-select) *or* a calendar range on `issued_at`; clients; currencies; statuses — all multi-select, reflected in the URL. |
| Інвойс (detail) | Kept as in the mock. Receipt cards at the bottom show date · received amount · commission (receipt fee) · landing account; **no ЄП / ВЗ** on the cards — those live on the receipt detail and in Облік. |
| Надходження — create | Invoice block on two lines: line 1 invoice number, semibold, primary blue; line 2 client name, light weight (long names may wrap). No income-recognition toggle (§15). |
| Надходження — detail | Header, **above** a light dashed divider: title = amount in the **payment currency** (`USD 3 892.50`), beneath it the UAH amount in light text, then the period badge (`IV кв. 2026 · жовтень`). **Below** the divider: invoice number (semibold, primary blue), client (light). The exchange rate is not in the header; it stays in the "Курс НБУ" row of the details table, together with the "Шлях коштів" block. |
| Рахунок (detail) | Two full-width rows, no background fill: **Надходження** — inbound (client receipts + transfers in) in success green; **Перекази** — outbound (transfers out, and tax payments when paid from this account) in danger red. Balance and limit progress above them. |
| Облік (quarter) | Each income row: a dashed divider above the tax block; line 1 `ЄП · ВЗ`, line 2 `комісії · чистий дохід`, each line two equal flex columns (`flex: 1 1 0`). The summary card may grow: YTD income on its own row; quarter income on its own row; "Перевірки" (cross-checks, §8.1) as a header with each value right-aligned on the following line; the four closing sections each as a full-width row — nothing squeezed onto one line. |
| Клієнт (detail) | Stats block (`GET clients/{client}/stats`), per currency: **paid all-time** · **paid this year** · **paid previous year** · **outstanding** = `Σ (total − paid_amount)` over the client's `ready` / `sent` invoices, shown as **"Усе оплачено"** in success green with a check icon when it is 0. Paid = Σ `payments.amount` − Σ `refunds.amount` on the client's invoices, bucketed by `received_at` / `refunded_at` year. Below: contracts, projects/charge types, recent invoices. |
| Клієнт (form) | Required **Абревіатура** (`BL`, the prefix of the client's invoice numbers, §5) under the name: suggested from the name while it is untouched, upper case, Latin letters and digits, up to 10; a duplicate within the ФОП is an error of the field. The list and the client page show it beside the name. |
| Налаштування | Profile data · invoice numbering (prefix, format, date, counter, reset, current counters) · **money format for the app and for invoices** (§10) · default tax account · repatriation rule · deadline days. No income-recognition option. |

Display rules that apply everywhere in phase 1: amounts use `display.money_format` (default `1 234.99`) with
`tabular-nums` and `nowrap`; the invoice currency is shown as an ISO code before the amount (`USD 3 892.50`), UAH as
`₴` after it; secondary figures (original currency under a UAH amount, UAH under a currency amount) are one step
lighter and smaller than the primary; invoice numbers are semibold in the primary colour wherever they lead a block.
