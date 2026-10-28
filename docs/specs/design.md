# PryTax — ФОП Finance App — Design Spec (v5, «Minimal» with desktop, multi-profile on api-core)

Target: accounting + invoicing app for Ukrainian ФОПs (3 група, спрощена система, no employees), built on the `api-core`
package (organizations, users, Sanctum tokens, spatie roles/permissions, HTTP filtering/resources/pagination). Stack:
Laravel 12/13, MySQL 8, Vue 3 SPA (mobile-first with a desktop layout from 1024 px, Ukrainian and English UI) consuming
the API (Blade only for PDF/email templates). Functional rules live in `accounting.md`; this document is the
implementation of them. §16 fixes the phase-1 UI scope («Minimal»), §17 its desktop layout; the invoice PDF is
specified in `invoice.md`; backups and retention in `backups.md`; what is deferred or still to decide is in
`suggestions.md`.

### Changes in v5 (the third Minimal handoff and «Minimal (Desktop)»)

v5 applies Claude Design's handoffs of 2026-10-28 (`storage/handoff-28-10-2026/`, out of git): the mobile delta 3
(`prytax-minimal-handoff-3/MINIMAL_DELTA_3.md`, 31 boards) and the desktop layout
(`prytax-desktop-handoff/DESKTOP_APP.md`, 59 boards). The owner's messages #58–#86 are in their `CHAT_LOG_DELTA_3.md`;
the third bundle, the backups screens, is specified in `backups.md` §4.8.

- **ФОП switching** (§16.1): one ФОП at a time still, but the user can switch to another ФОП of the organization from
  Ще's «ФОП» card (`/profiles`, «ФОП організації») or the desktop sidebar. Replaces v4's «one ФОП per session».
- **Книги обліку** (§16.7): a new page with the tabs Доходи (the income book by month, with its receipts) and Податки
  (the year's taxes by quarter or by tax), opened from a 📖 button beside the bell on every root screen; PDF and XLSX
  exports.
- **Shared components and the consistency rules 1–10** (§16.6): one date picker (a single date or a period; a sheet on
  mobile, a popover on desktop), the 📖 | 🔔 capsule, one section heading, sentence-case status badges, filter chips
  showing their values, «Застосувати» in every drawer, the «Новий» pill, the full-screen modal header, the РНОКПП mask
  `••••4821`, the export block.
- **App language** (§16.6): the language sheet on Ще and the language pill on Вхід restyle the existing switch.
- **Desktop layout** (§17): from 1024 px a sidebar shell, records in a right-hand panel over their list, centred
  dialogs, anchored popovers, fixed-width tables. The same routes, API and data as mobile.

The owner's answers of 2026-10-28 (to the handoffs' open questions) are written into the sections they affect:
- the current ФОП stays the client's (§16.1); delta 3's `PUT /api/me/current-profile` isn't built;
- «+ Новий ФОП» keeps the existing flow: owners only, the full profile form, then Податки й сповіщення;
- English ships (it already did, v3.x); the books' PDF mirrors the screen;
- desktop filter popovers have «Скинути» / «Застосувати» as drawn; between 1024 and 1279 px the right-hand columns move
  under the main one; notifications are a popover on desktop, and `/alerts` stays a page for links;
- XLSX exports use `openspout/openspout` (approved).

### Changes in v4 («Minimal»)

v4 applies Claude Design's «Minimal» handoff (§16): the first product phase, one ФОП at a time, fewer options and
simpler forms. Its second handoff, of the same afternoon, makes Податки one tax ledger (§8.5). Earlier revisions are in
the git history.

- **UI scope** (§16.1): one ФОП per session, without a profile switcher, Огляд практики or an organization board; five
  tabs, Головна · Інвойси · Облік · Податки · Ще. The ЄСВ and Книга доходів tabs, the declaration screen and the tax
  views by month and by quarter are hidden. The backend keeps all of them.
- **Accordion forms** (§16.3): settings pages and record forms edit one group of fields at a time and save only that
  group; there is no page-level save.
- **Two invoice notes** (§4.1): a payment note (printed under the payment details) and an other note (printed under the
  act), each with its own switch and text and each resolved on its own. They replace the single note with its
  `note_kind`; both may print.
- **Payments without an invoice** (§4.2): `invoices.source = virtual`, shown as «Без інвойсу». It is created with its
  receipt, paid from the start, never numbered, sent or rendered. The receipt kinds `advance` and `other` are gone:
  every client receipt belongs to an invoice.
- **Tax payments** (§8.4): ЄП and ВЗ are paid only from a receipt, at the receipt's computed amounts; ЄСВ is paid per
  month. Податки is one tax ledger (§8.5): month by month, what is still owed (each receipt's ЄП and ВЗ, each month's
  ЄСВ) and every payment, with a summary of the chosen period. Taxes may be paid from foreign-currency ФОП
  accounts, and the ФОП keeps a list of payment accounts with a primary one (`taxes.payment_account_ids`).
- **Removed options:** `income.taxable_base` (the base is always the invoiced amount), `taxes.default_grouping`,
  `accounts.limit_window` (calendar windows only) and `accounts.limit_counts` (client receipts only); the refund action
  leaves the UI.
- Numbering is unchanged (client abbreviations, §5). Virtual invoices take no number.

The owner settled v4's open questions on 2026-10-25; the answers are written into the sections they affect.

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
- **Phase 1 shows one ФОП per session** (§16.1): the UI has no profile switcher and no organization board. The model
  above, the routes and the isolation are unchanged.
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
           Route::get('clients/{client}/invoice-defaults', ClientInvoiceDefaultsController::class);
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

ЄСВ is accrued per month but due per quarter: a ФОП may pay every month (the owner does), and each month's ЄСВ is
due with its quarter, by the 19th of the month after it (October's by 19.01.2027). The «Minimal» boards' monthly dates
(«жовтень — до 19.11», and the second handoff's «сплатити до 19.11.2026» on an unpaid ЄСВ row) are not used.

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
10. **Tax payments follow the receipt.** ЄП and ВЗ are paid per receipt, at the amounts computed for it
    (`allocation = payment`); ЄСВ is paid per month (`allocation = month`); `period_year/quarter` are always filled
    (§8.4).
11. **Every client receipt belongs to an invoice.** A payment the client made without one gets a virtual invoice (§4.2);
    the only receipts without an invoice are the synthetic `fx_gain` rows (§6.5). Passive income is not income and goes
    to `account_movements`; refunds are `refunds` rows (negative in reports).
12. **ЄСВ is a monthly obligation row** generated from `tax_rules`, `due | exempt`; paid state is derived.
13. **Periods lock.** Once a declaration is submitted the period is locked; writes into it need a correction note and
    mark the period and every later period of the year `corrected` (§8.3).
14. **Invoices have a `source`** (`generated | external | virtual`); uploaded primary files are never overwritten: a
    draft's uploaded PDF is deleted only when the user replaces it (the new upload's `primary=1` deletes it) or deletes
    it, never by a return to the draft; virtual invoices have no document at all (§4.2).
15. **Notifications inform, never block.** They are persisted rows with per-user read state; read only by an
    explicit tap, never by loading a page; shown on their own screen, never as cards on Головна (§9).
16. **Tenant key is `profile_id`** (§0).
17. **Formatting is presentation.** The API carries canonical decimal strings; how `1234.99` is displayed is a
    profile setting, separately for the app and for invoice PDFs (§10).
18. **Invoice presentation settings cascade, and freeze materialises them.** Style, language, payment purpose, act
    note, contract toggle and the two notes are `NULL`-means-inherit columns on `invoices` and `clients` over
    `profile_settings` keys and built-in defaults; drafts read them live, the freeze writes the resolved values into
    the snapshot, so editing a client never alters an issued document. Currency is prefilled from the same chain but
    stored concretely (§4.1).

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
                    -- the language root of the cascade is the column profiles.locale (§4.1; no setting key)
                    invoice.payment_purpose_template  = template ≤ 255, placeholders §4.1      (null → built-in for the locale)
                    invoice.act_note_enabled          = bool                                  (true)
                    invoice.act_note                  = text ≤ 500                            (null → built-in for the locale)
                    invoice.style                     = InvoiceStyle key                      (null → outlined)
                    invoice.payment_note_enabled      = bool                                  (null → off)
                    invoice.payment_note              = text ≤ 500                            (null)
                    invoice.other_note_enabled        = bool                                  (null → off)
                    invoice.other_note                = text ≤ 500                            (null)
                    invoice.show_contract             = bool                                  (true)
                    -- currency root of the cascade is the column profiles.default_currency (no setting key)
                    invoice.money_format              = money format enum (§10)               (plain_dot  → 2851.99)
                    display.money_format              = money format enum (§10)               (space_dot  → 1 234.99)
                    -- the taxable base is always the invoiced amount; income.recognition is not a phase-1 setting (§15)
                    income.fx_gain                    = ignore | include                      (ignore)
                    taxes.payment_account_ids         = [accounts.id] (§8.4: active; UAH, or a fop_bank in another currency) ([])
                    taxes.default_account_id          = one of taxes.payment_account_ids («Основний»)  (null)
                    taxes.esv_due_day                 = 1…28                                  (null → tax_rules.esv_due_day, 19)
                    compliance.platform_repatriation  = quarter_end | days:N | off            (quarter_end)
                    alerts.deadline_days              = int                                   (7)
                    alerts.ready_days                 = int                                   (14)   -- not on the phase-1 screens

accounts            [P] name, kind(enum platform|fop_bank|foreign_bank|personal), currency(char3),
                    holder_name, bank_name, iban ENC, swift, account_number ENC, details JSON,
                    limit_amount DECIMAL(15,2) NULL, limit_period(enum month|quarter|year) NULL,
                    is_active, sort_order
                    -- personal: non-business — excluded from limits, income, repatriation; valid transfer endpoint and tax source
                    -- progress = Σ payments.received_amount (kind = invoice) INTO id with received_at in the current
                    --            calendar month / quarter / year; client receipts only (v4 dropped limit_window, limit_counts)
account_opening_balances [P] account_id (RESTRICT), as_of DATE, amount DECIMAL(15,2)
                    UNIQUE(profile_id, account_id, as_of)
account_movements   [P] account_id (RESTRICT), direction(enum in|out), amount DECIMAL(15,2), booked_at DATE,
                    category(enum bank_fee|interest|expense|owner_withdrawal|other), note
                    -- non-taxable cash movements so the derived balance reconciles; never enters income
                    INDEX(account_id, booked_at)

clients             [P] name, abbreviation VARCHAR(10), legal_name, name_uk NULL, legal_name_uk NULL, country CHAR(2),
                    vat_number NULL, address JSON (lines, city, zip), tax_id NULL, email,
                    notes, is_active,
                    -- invoice defaults (§4.1); NULL = inherit from the profile
                    currency CHAR(3) NULL,                      -- prefill for new contracts and invoices
                    invoice_locale VARCHAR(5) NULL,             -- InvoiceLocale enum: en | uk
                    payment_purpose_template VARCHAR(255) NULL,
                    act_note_enabled BOOL NULL, act_note VARCHAR(500) NULL,
                    show_contract BOOL NULL,
                    invoice_style VARCHAR(32) NULL,             -- InvoiceStyle key
                    payment_note_enabled BOOL NULL, payment_note TEXT NULL,
                    other_note_enabled BOOL NULL, other_note TEXT NULL   -- replace invoice_note_enabled, invoice_note, invoice_note_kind
                    UNIQUE(profile_id, abbreviation)   -- [A-Z0-9]{1,10}, typed in any case, stored upper case;
                                                       -- inactive clients keep theirs: two clients never share a prefix (§5)
contracts           [P] client_id, name, number NULL, subject VARCHAR(100) NULL,   -- subject → {services} (§4.1)
                    started_at DATE NOT NULL, ends_at DATE NULL, currency,
                    payment_terms_days SMALLINT NULL, description_template, is_active
                    -- template: "IT services in accordance to the Service Agreement {name} dated {started_at:F j, Y}"
contract_rates      [child] contract_id, charge_type_id NULL, effective_from DATE, hourly_rate DECIMAL(15,2)
                    UNIQUE(contract_id, effective_from, (COALESCE(charge_type_id, 0)))   -- NULL charge_type = contract default
projects            [child of clients] client_id, code ("PROTO"), name, is_active
charge_types        [child of clients] client_id, name ("Billable","Overhead"), is_active

invoices            [P] client_id, contract_id NULL, receiving_account_id → accounts (RESTRICT),
                    number VARCHAR(64) NULL,                    -- NULL on unnumbered drafts and on virtual invoices
                    sequence_scope_key VARCHAR(64) NULL, sequence_number INT UNSIGNED NULL,   -- NULL pair = user-supplied number
                    source(enum generated|external|virtual) DEFAULT generated,                -- virtual: §4.2
                    period_start DATE, period_end DATE, issued_at DATE, due_at DATE NULL,
                    currency CHAR(3) NOT NULL,                  -- resolved at creation: contract → client → profile (§4.1)
                    -- overrides (§4.1); NULL = inherit from the client; locked with the invoice after freeze
                    locale VARCHAR(5) NULL, payment_purpose_template VARCHAR(255) NULL,
                    act_note_enabled BOOL NULL, act_note VARCHAR(500) NULL, show_contract BOOL NULL, style VARCHAR(32) NULL,
                    payment_note_enabled BOOL NULL, payment_note TEXT NULL, other_note_enabled BOOL NULL, other_note TEXT NULL,
                    subtotal DECIMAL(15,2), adjustment_amount DECIMAL(15,2) NOT NULL DEFAULT 0, adjustment_label NULL,
                    total DECIMAL(15,2),                        -- = subtotal + adjustment_amount; virtual: = its receipt's amount
                    status(enum draft|ready|sent|paid|cancelled), locked_at, sent_at, paid_at, cancelled_at,
                    snapshot JSON NULL,                         -- schema 1 (template v1) or 3 (template v3, invoice.md §3)
                    notes, timestamps, soft deletes             -- notes: internal, never printed («За що» on virtual ones)
                    UNIQUE(profile_id, number), INDEX(profile_id, client_id, status), INDEX(profile_id, issued_at)
                    CHECK (source <> 'virtual' OR (number IS NULL AND due_at IS NULL AND sent_at IS NULL AND snapshot IS NULL))
invoice_items       [child] invoice_id, position, project_id NULL, project_code VARCHAR, charge_type_id NULL,
                    charge_type VARCHAR, description TEXT, unit(enum hour|fixed|item),
                    quantity DECIMAL(10,2), unit_price DECIMAL(15,2), amount DECIMAL(15,2)
                    -- project_code / charge_type copied as text so the item survives lookup edits
invoice_files       [child] invoice_id, kind(enum generated|uploaded), is_primary BOOL, template_version NULL,
                    disk, path, original_name, mime, size, sha256, created_at
                    UNIQUE KEY uq_primary ((CASE WHEN is_primary THEN invoice_id END))   -- MySQL ≥ 8.0.13
invoice_sequences   [P] scope_key VARCHAR(64), last_number INT UNSIGNED     UNIQUE(profile_id, scope_key)

payments            [P] invoice_id NULL → invoices (RESTRICT), kind(enum invoice|fx_gain), description NULL,
                    -- v4: advance and other are gone; a receipt without an invoice gets a virtual invoice (§4.2)
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
                    --   a virtual invoice has exactly one receipt; received_currency = account.currency;
                    --   kind=fx_gain rows have invoice_id NULL and received_amount = 0 (no balance/limit effect)
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
                    -- exempt only where esv_mandatory = false (2022-03 … 2024-12); marking a month exempt by hand is
                    --   removed for now (v4)
                    -- paid = Σ tax_payments(social_contribution, status=paid) mapped to the month; higher amounts allowed
tax_payments        [P] tax_type(enum single_tax|military_levy|social_contribution),
                    amount DECIMAL(15,2), paid_at DATE NULL, status(enum sent|paid|failed),
                    allocation(enum payment|month|quarter),
                    payment_id NULL → payments (RESTRICT), period_year SMALLINT NOT NULL, period_quarter TINYINT NOT NULL,
                    period_month TINYINT NULL, paid_from_account_id NULL → accounts (RESTRICT), reference, note,
                    original_amount DECIMAL(15,2) NULL, original_currency CHAR(3) NULL,   -- what left a non-UAH account (§8.4)
                    receipt_tax_key VARCHAR(40) GENERATED ALWAYS AS
                        (CASE WHEN status <> 'failed' AND payment_id IS NOT NULL THEN CONCAT(payment_id, ':', tax_type) END) STORED
                    UNIQUE(receipt_tax_key)                    -- one non-failed ЄП and one ВЗ per receipt (§8.4)
                    CHECK (allocation <> 'payment' OR payment_id IS NOT NULL)
                    CHECK (allocation <> 'month'   OR period_month IS NOT NULL)
                    CHECK (allocation <> 'quarter' OR period_month IS NULL)
                    CHECK (amount > 0)
                    CHECK (status <> 'paid' OR paid_at IS NOT NULL)
                    CHECK ((original_amount IS NULL) = (original_currency IS NULL))
                    CHECK (original_amount IS NULL OR original_amount > 0)
                    INDEX(profile_id, tax_type, period_year, period_quarter, status), INDEX(paid_from_account_id, paid_at)
                    -- new rows (§8.4): ЄП/ВЗ → allocation = payment, amount computed from the receipt, period_* copied from
                    --   its received_at; ЄСВ → allocation = month, amount typed; quarter: no new rows
                    -- paid_from_account: active; UAH, or a fop_bank in another currency (Form Request); the form preselects
                    --   taxes.default_account_id; original_* required when its currency isn't UAH, NULL otherwise
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
activity_log        spatie/laravel-activitylog on clients, invoices, invoice_items, payments, refunds, account_transfers,
                    transfer_allocations, tax_payments, tax_periods, profile_settings
```

---

## 4. Invoice lifecycle

```
draft ──(freeze)──▶ ready ──▶ sent ──▶ paid        also ready ──▶ paid, and paid ──▶ sent | ready when uncovered
  ▲                  │
  └───(unfreeze)─────┘      ready | sent ──▶ cancelled

virtual: paid from creation, no transitions (§4.2)
```

| Transition | Preconditions | Effects |
|---|---|---|
| freeze `draft → ready` (`InvoiceFreezer`) | `generated`: items ≥ 1; `external`: an uploaded `is_primary` file and a typed number; never `virtual`. `receiving_account.is_active`; `receiving_account.currency = invoice.currency`; payment purpose renders without missing placeholders (§4.1) | Number: allocate if blank, **rebuild** if generator-allocated (§5); resolve the presentation settings (§4.1) and render the payment purpose with the final number; build `snapshot` (schema 3, `invoice.md` §3; `money_format` = the current `invoice.money_format`); set `locked_at`; `DB::afterCommit` → render the PDF as the generated primary file unless an uploaded primary exists |
| unfreeze `ready → draft` | No payments, no refunds | Delete **generated** files only (an uploaded PDF stays, decision 14); null `snapshot`, `locked_at`; keep `number`, `sequence_*` |
| `ready → sent` | — | `sent_at` (editable) |
| `ready \| sent → paid` | The receipts cover the total (`Σ payments.amount − Σ refunds.amount ≥ total`); suggested, confirmed manually | `paid_at` defaults to the last receipt's `received_at` |
| `paid → sent \| ready` | Automatic, when a receipt change (delete, reduce, move, refund) leaves it uncovered | back to `sent`, or `ready` when it was never sent; `paid_at` cleared |
| `ready \| sent → cancelled` | No money kept by receipts (move them to the replacing invoice; phase 1 has no refunds, §16.1) | `cancelled_at`; number never reused |

- Partial payment is derived (`paid / total`); overpayment shown as `paid > total`.
- Enforcement: `InvoiceObserver@updating/@deleting` and `InvoiceItemObserver` throw `InvoiceLockedException` unless
  `status = draft` or the dirty set ⊆ `{status, sent_at, paid_at, cancelled_at, notes}`. `InvoicePolicy@update`
  mirrors it. Updates carry `If-Unmodified-Since: updated_at` → 409 on a stale write.
- PDF is rendered only from `snapshot`; the file's `template_version` + `snapshot.schema_version` keep old invoices
  renderable after template changes.
- The override columns of §4.1 are outside the allowed dirty set, so they lock with the invoice; unfreeze makes them
  editable again and the next freeze resolves the cascade afresh.

### 4.1 Invoice settings cascade (`InvoiceSettingsResolver`)

Rationale:
- **`NULL` = inherit, resolved live on drafts, materialised on freeze.** One nullable column per setting and level
  keeps validation typed and the precedence trivially auditable; the snapshot (§2.5) already guarantees immutability,
  so live inheritance on drafts costs nothing and spares a "re-apply client defaults" action.
- **Columns, not a JSON blob**, on `clients` / `invoices`: five fixed settings, typed casts, Form Request rules per
  field, no schema-less drift. `profile_settings` (already key → JSON) carries the ФОП-wide level.
- **Booleans are nullable tri-state** (`NULL` inherit · `true` · `false`); "act note off" is `act_note_enabled = false`,
  never an empty `act_note` — `ConvertEmptyStringsToNull` would turn `''` into "inherit".
- **Currency is not live.** Items, totals, the receiving account, list filters and later receipts depend on it, so it is
  resolved once at creation into the existing `invoices.currency NOT NULL` and edited explicitly on a draft.

Resolution (first non-`NULL` wins; `source` is reported to the UI):

| Setting | invoice | client | profile | built-in |
|---|---|---|---|---|
| currency (at creation only) | — (stored result) | `contracts.currency` if a contract is picked, then `clients.currency` | `profiles.default_currency` | — |
| language | `invoices.locale` | `clients.invoice_locale` | `profiles.locale` (the ФОП's «Мова документа») | `en` |
| payment purpose template | `invoices.payment_purpose_template` | `clients.payment_purpose_template` | `invoice.payment_purpose_template` | `lang/{locale}/invoice.php` → `defaults.payment_purpose` |
| act note on/off | `invoices.act_note_enabled` | `clients.act_note_enabled` | `invoice.act_note_enabled` | `true` |
| act note text | `invoices.act_note` | `clients.act_note` | `invoice.act_note` | `lang/{locale}/invoice.php` → `defaults.act_note` |
| contract section | `invoices.show_contract` | `clients.show_contract` | `invoice.show_contract` | `true` |
| style | `invoices.style` | `clients.invoice_style` | `invoice.style` | `outlined` |
| payment note on/off | `invoices.payment_note_enabled` | `clients.payment_note_enabled` | `invoice.payment_note_enabled` | `false` |
| payment note text | `invoices.payment_note` | `clients.payment_note` | `invoice.payment_note` | — |
| other note on/off | `invoices.other_note_enabled` | `clients.other_note_enabled` | `invoice.other_note_enabled` | `false` |
| other note text | `invoices.other_note` | `clients.other_note` | `invoice.other_note` | — |

The style is a key of `InvoiceStyle` (13 styles, catalogue order; `VARCHAR(32)`, no MySQL `ENUM`, since styles will be
added): saving an unknown key is a 422, and a stored key of a removed style renders as `outlined` with a logged
warning.

**The two notes** («Примітка щодо оплати» and «Інша примітка») are independent; either, both or neither print. The
payment note prints under the payment details as PAYMENT NOTE; the other note prints on the right, under the act, as
OTHER NOTES (`invoice.md` §4). Each note is one setting of two fields: the first level whose `*_note_enabled` is
non-`NULL` owns it, and its text is read from that level only, never mixed across levels. The text is required when
that level switches the note on (`Enter the note text.`) and is at most 500 characters. They replace the single note
of v3.3 (`note_enabled`, `note`, `note_kind`, and the `invoice_note*` columns of clients): the migration moves each
level's note into its payment note when its kind was `payment`, and into its other note otherwise, keeping the switch.
External and virtual invoices have no style and no notes.

The built-in texts are taken in the **resolved** locale; a custom text at any level is used verbatim whatever the
locale (one string, not per-language variants).

Currency rules (`InvoiceSettingsResolver::currencyFor(Client, ?Contract): string`):
- Prefill on create; while the user has not touched the field, picking or changing the contract re-prefills it.
- Changing a draft's currency clears `receiving_account_id` when `account.currency ≠ currency`; prices are never
  converted; `contract_rates` prefill only when `invoice.currency = contract.currency`; a mismatch with the contract's
  currency returns a `warnings[]` entry (`currency_differs_from_contract`), not a 422.
- Default receiving account: the client's last invoice's account if `currency` matches and `is_active`, else the first
  active account in that currency by `sort_order`, else `NULL` (the freeze precondition catches it).
- `contracts.currency` is prefilled from the client's effective currency on contract creation.

Built-in texts (`lang/en/invoice.php`, `lang/uk/invoice.php`):

| Key | en | uk |
|---|---|---|
| `defaults.payment_purpose` | `Payment for {services} by the invoice No. {invoice.number} from {invoice.date}` | `Оплата за {services} згідно з інвойсом № {invoice.number} від {invoice.date}` |
| `defaults.services` | `services` | `послуги` |
| `defaults.act_note` | `Payment of this invoice confirms the successful delivery of services and serves as the Act of Acceptance. No additional signatures are required.` | `Оплата цього інвойсу підтверджує належне надання послуг і є актом приймання-передачі наданих послуг. Додаткові підписи не потрібні.` |

The English default uses `No.`, not `№`: `№` is outside the SWIFT X character set (MT103 field 70) and the SEPA
Latin set, and is dropped, transliterated to `?` or rejected by the payer's bank.

Payment purpose placeholders (`PaymentPurposeRenderer`, pattern `/\{([a-z_]+(?:\.[a-z_]+)?)\}/`):

| Placeholder | Value | Missing → |
|---|---|---|
| `{services}` | `contract.subject` | `defaults.services` of the locale |
| `{invoice.number}` | final number (preview number on drafts) | — |
| `{invoice.date}` | `issued_at` | — |
| `{period.start}` / `{period.end}` | `period_start` / `period_end` | — |
| `{contract.name}` / `{contract.number}` / `{contract.date}` | contract name / number / `started_at` | freeze 422 |
| `{client.name}` | client legal name for the locale | — |
| `{profile.name}` | ФОП name for the locale | — |

Dates format per locale: `en` → `M j, Y` (`Oct 31, 2026`), `uk` → `j F Y` with the genitive month (`31 жовтня
2026`), via
`Carbon::translatedFormat()` with the locale passed explicitly (never `App::setLocale()` — Octane, queued renders).

Validation and warnings:
- Save (Form Requests for client, invoice, profile settings): `payment_purpose_template` `nullable|string|max:255` +
  `PurposeTemplate` rule — every placeholder in the whitelist, else 422 naming the unknown ones; `act_note`
  `nullable|string|max:500`; `act_note_enabled`, `show_contract` `nullable|boolean`; `invoice_locale` / `locale`
  `nullable` + `Rule::enum(InvoiceLocale::class)`; `clients.currency` `nullable|size:3` + ISO 4217 check through
  `Brick\Money\Currency::of()`.
- Freeze: a `{contract.*}` placeholder without a contract, or with a `NULL` contract field → 422 on
  `payment_purpose_template` (`The payment purpose uses {contract.number}, but the invoice's contract has no
  number.`), message noting the level the template came from.
- Non-blocking `warnings[]` on the draft resource and the freeze response, only when `currency ≠ UAH`:
  `payment_purpose_too_long` (rendered length > 140) and `payment_purpose_charset` (outside
  `/^[A-Za-z0-9\/\-?:().,'+ ]*$/`, the SWIFT X set).

Resolver (built: `app/Services/Invoices/InvoiceSettingsResolver.php`): `forInvoice()` and `forClient()` resolve every
row of the table above into `{value, source, inherited}`, reading the profile level through the settings' raw values,
so an absent key is distinguishable from a stored one; `note()` resolves each note whole from the level that owns it;
`currencyFor(Client, ?Contract)` gives the creation currency. The locale column casts to `InvoiceLocale`, the switches
to `'boolean'` (Laravel keeps `NULL` for primitive casts, so the tri-state survives). `InvoiceLocale` is a backed enum
over `VARCHAR(5)` (no MySQL `ENUM`/`CHECK`), so `en_uk` (bilingual, §15) needs no `ALTER`.

The freeze materialises the resolved values into the snapshot's `presentation` (schema 3, `invoice.md` §3): `style`,
`payment_purpose` (rendered), `act_note` (`null` when off), `show_contract`, `payment_note` and `other_note` (`null`
when off). Party fields are resolved for the locale, so the template never branches on data. The contract block is
kept even when `show_contract = false` (the PDF shows the section iff `show_contract && contract !== null`).
Version-1 snapshots keep rendering with the v1 template; version 2 never left development and renders with v3.
The two notes change schema 3 in place, since no invoice was issued outside development.

API (§10 conventions apply):
- Client and invoice resources expose the override columns raw (`null` = inherit). `PATCH` with a field present and
  `null` (or `''`) clears the override; an absent field is unchanged.
- Invoices and clients take `?append=effective_settings` (`{ "locale": { "value": "en", "source": "client",
  "inherited": true }, … }`); invoices also `payment_purpose_preview` and `warnings`. Profiles take
  `?append=effective_invoice_settings,invoice_texts` (the built-in texts per language).
- `GET invoices/defaults?client_id=&contract_id=&period_end=` gives a new invoice's form its effective settings, its
  `currency` and its `receiving_account_id`.

Migrations: the cascade (`2026_10_24_133741_add_invoice_settings_to_clients_contracts_and_invoices`) and the style
and note (`2026_10_24_204757_add_invoice_style_and_note_to_clients_and_invoices`) are built; v4 adds one that splits
the note into the two notes (above) and drops `note_kind`.

### 4.2 Payments without an invoice (`source = virtual`)

A client sometimes pays under the contract with no invoice: an advance, a retainer. The receipt is still income and
still needs an invoice row, so it gets a **virtual** invoice, a record kept only for the books, which the client never
sees. The UI always calls it «Без інвойсу» (never "virtual" or "mock").

**Model.** `invoices.source = virtual` (`InvoiceSource::Virtual`). The handoff proposed a new `kind` column; the
existing `source` discriminator takes the value instead, so nothing else is renamed.
- `number`, `due_at`, `sent_at` and `snapshot` are `NULL`, and it has no files and no items (CHECKs in §3, the policy).
- `client_id` and `contract_id` are required; `currency` is the contract's.
- `total` = `subtotal` = its receipt's `amount`; `receiving_account_id` is the receipt's account.
- `issued_at` = `paid_at` = the receipt's `received_at` (the payment date), which places it in the lists; they follow
  the receipt's date when it changes.
- `period_start` / `period_end`, the service period, are required, as on any invoice.
- `notes` holds «За що», the user's own reminder, never printed.

**Lifecycle.**
- **Created** with its receipt in one transaction: `POST payments` (and `payments/preview`) takes `virtual_invoice
  {client_id, contract_id, period_start, period_end, notes?}` instead of `invoice_id`; `PATCH payments/{payment}`
  takes it to change them.
- **One receipt** per virtual invoice. Editing the receipt's amount changes the total with it. Deleting the receipt
  deletes the virtual invoice.
- **Status `paid` from the start**, with no transition. It never takes a number from a counter.
- **Denied** by the policy: freeze, unfreeze, send, pay, cancel, PDF and download, files, items.

**Where it counts.** Queries that must leave virtual invoices out use explicit scopes (such as `documents()`), never a
global scope, because the books must see them.
- **Left out of:** the overdue and "ready without receipts" notifications, «до оплати» totals (`invoices/outstanding`,
  Головна's subtitle), numbering and counters, and the client stats' outstanding amount.
- **Included in:** receipts, taxes, Облік and Податки, the income book and the CSV exports (as the document
  «Без інвойсу»), the client's and the contract's paid totals, and Облік's quarter income, where it counts as invoiced
  and received.
- **Lists:** Головна and Інвойси show it (§16.5); the status filter has the option «Без інвойсу», which matches
  `source = virtual`, while the five statuses match only the other invoices.

**Actions on the record** («Якщо клієнт попросить інвойс»):
- **«Оформити як інвойс»** (`POST invoices/{invoice}/issue`): in one transaction `source → generated`, a number from
  the client's counter by the date rule (§5, e.g. `KS-2026-11-30-8`), the snapshot and the PDF; the status stays
  `paid`. The issue date stays the payment date, and the period stays as entered. The invoice gets one `fixed` item
  for the whole amount, its description expanded from the contract's `description_template`.
- **«Прив’язати до виставленого інвойсу»** (`PATCH payments/{payment} {invoice_id}`): the receipt moves to a `ready`,
  `sent` or `paid` invoice in its currency (the screen offers the client's ones) (the existing receipt move, with its coverage and paid/reopen rules), and
  the virtual invoice is deleted in the same transaction. No other receipt can join a virtual invoice.

**Screens** (§16.5): «Інвойс для надходження» (board 041), «Нове надходження» without an invoice (042), the list rows
(002, 003, 043), the record «Без інвойсу» (044).

## 5. Numbering

`InvoiceNumberGenerator` (interface) → `DateSequenceGenerator` (`{YYYY-MM-DD}-{n}`, date from
`invoice.numbering.date_source`) and `SequenceGenerator` (`{n}`), each prefixed with `{ABBREVIATION}-` — the client's
`abbreviation` — when `invoice.numbering.prefix = client_abbreviation` (the default). Scope key = `global` |
`client:{id}`, suffixed `:{year}` when `reset = yearly`; sequences are per profile.

On Нумерація інвойсів (§16.5) the settings read: Префікс — Абревіатура клієнта (`client_abbreviation`) / Без префікса
(`none`); Формат — Дата + номер (`date_sequence`, `АБР-РРРР-ММ-ДД-n`) / Лише номер (`sequence`, `АБР-n`); Дата в
номері — Кінець періоду (`period_end`) / Дата виставлення (`issued_at`); Лічильник — Для клієнта (`per_client`) / Один
на ФОП (`global`); Скидання — Ніколи / Щороку. «Поточні лічильники» show each client's last issued number (the
sequence's next number − 1) and move a counter forward only.

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
- Virtual invoices never take a number; «Оформити як інвойс» (§4.2) allocates one exactly as a freeze does.
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
- Every client receipt names an invoice. A payment without one is recorded together with a virtual invoice (§4.2);
  `kind = advance` and `kind = other` are no longer accepted.
- A receipt can move to another invoice (`PATCH invoice_id`, with the coverage and paid/reopen rules of §4); that is
  how a virtual invoice's receipt is attached to an issued one.
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
period from `refunded_at`, `Σ refunds ≤ payment.amount`, period lock applies, invoice `paid_amount` reduced. Phase 1
has no refund action in the UI (§16.1); the model, the API and the reports keep refunds.

### 6.7 Compliance

Platform receipt whose `landed_on_fop_bank < received_amount` past the rule in `compliance.platform_repatriation`
(quarter end of `received_at` | `received_at + N days` | off) → warning; dismissible per payment
(`repatriation_ack_at`); never blocking.

## 7. Accounting view and exports

Per profile, per quarter (`accounting/{year}/{quarter}`): receipts and refunds by `received_at`, grouped by month (the
default), by invoice, or not at all. The phase-1 screen (Облік, §16.5) opens with the quarter summary:
- the yearly cap: YTD income against `income_cap_uah`, with ticks at 80 % and 90 %;
- the quarter's income per currency, as invoiced against received over the invoices issued in the quarter (the
  figures of the former Огляд практики, `PracticeOverview`), and the quarter's taxable income in UAH;
- ЄСВ · ЄП · ВЗ owed for the quarter, with what is paid.

Then come the receipt cards (§16.4), with their ЄП and ВЗ rows (the per-receipt hints) and, on demand, the fee and the
net. Owed ЄП/ВЗ is `YTD(q) − YTD(q−1)` (§8.1). The cross-checks (the standalone figure, Σ hints and their kopeck
deltas) stay in the API and the declaration figures, but phase 1 doesn't show them.

Exports: CSV of the quarter, of the year and of the income book (Облік доходів, п. 296.1), with the profile's decimal
separator. XLSX and the yearly PDF summary need a package (approval first). The 12-month ЄСВ table (`esv/{year}`) and
the monthly income table (`income-book/{year}`) stay in the API; their screens are hidden in phase 1.

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

`failed` excluded. `balance > 0` overpaid / carried forward; `< 0` outstanding. Phase 1 shows the reconciliation on
Облік's quarter summary (each tax owed, with what is paid, §7), and the deadline notifications read it (§9). Податки
shows the ledger instead (§8.5). The ledger's figures are sums of the receipts' and the months' own amounts, so its
Нараховано for ЄП and ВЗ can differ from `owed` by the kopecks of §8.1. The views by month and by quarter, the first
handoff's quarter card (Нараховано · Сплачено · До сплати) and `taxes.default_grouping` are gone.

### 8.3 Tax periods, deadlines, locks

- `DeadlineScheduler` (daily, idempotent) creates `tax_periods` for every active profile for the current and next
  quarter. `BusinessDayCalendar` (weekends + `holidays`): `declaration_due = rollForward(q_end + 40 d)`;
  `tax_due = rollForward(q_end + 40 d + 10 d)` (anchored on the un-rolled statutory date);
  `esv_due = rollForward(first day of month after q + esv_due_day − 1)`. `is_annual = (quarter = 4)`.
- `EsvObligationScheduler` creates `esv_obligations` rows per month from `tax_rules` (`amount_due = esv_monthly`,
  `status = due`, or `exempt` when `esv_mandatory = false`). Marking a month exempt by hand (the ЄСВ tab's switch,
  `PATCH esv-obligations/{id}`) is removed for now.
- `PeriodLock`: `declaration_status → submitted|accepted` sets `locked_at`. `PaymentRecorder`, `RefundRecorder` and
  the FX-gain writes call `assertOpen(profile, date)` inside their transaction (tax payments are never blocked: paying
  after declaring must work); a locked
  period throws `PeriodLockedException` (409) unless the request carries `correction_note`, in which case the write
  proceeds, the period and every later period of the same year get `declaration_status = corrected`, and an alert
  "уточнююча декларація required for Q…" is raised naming each affected quarter.
- `DeclarationFigures` (screen + endpoint): income YTD, ЄП YTD, ЄП previously declared (= `previous_ytd`), ЄП to pay
  (= `owed`); same for ВЗ; annual: ЄСВ months. Reads `QuarterLiability` only.
- **Phase 1 hides the declaration screen** (§16.1), so nothing moves a declaration to `submitted` from the UI:
  periods don't lock, and no correction note is asked for, until the screen returns.

### 8.4 Tax payments (`TaxPaymentRecorder`)

- **ЄП and ВЗ are paid from a receipt, never as a free amount.** `payment_id` is required and `allocation = payment`
  (the API sets the allocation by the tax; `TaxPaymentRecorder` still takes month and quarter allocations from code,
  such as the demo and a future import).
  The period is copied from the receipt's `received_at`. The amount is the receipt's own `single_tax_uah` /
  `military_levy_uah`, set by the server; a typed `amount` is a 422. A saved tax payment keeps its amount when the
  receipt is edited later, and the difference shows in the reconciliation. A kopeck remainder between Σ hints and the
  quarter's owed amount is left as it is for now (`suggestions.md` §1).
- **At most one non-failed ЄП and one non-failed ВЗ per receipt** (`UNIQUE(receipt_tax_key)`, §3). A failed attempt
  and a later paid one coexist (the history's 11 → 12 серпня).
- **ЄСВ is paid per month.** `period_year` and `period_month` are required and `allocation = month`. The amount is typed
  (> 0), prefilled with the month's `esv_monthly` («Мінімальний внесок за місяць — 1 902.34 ₴ … Можна більше»). More
  than one payment per month is allowed, as a top-up. Each month is due with its quarter (`tax_periods.esv_due_at`;
  October → 19.01.2027).
- **Status:** `paid` reduces the debt; `sent` is pending (Податки's «Надіслано», §8.5) and reduces nothing; `failed`
  counts nowhere. `paid_at` is required for `sent` and `paid`.
- **Account** (`paid_from_account_id`): an active account in UAH, or an active `fop_bank` account in another currency.
  From a foreign-currency account the bank first sells the currency into the ФОП's UAH account and pays from there;
  the record names the account the money left. The form offers `taxes.payment_account_ids`, primary first
  (`taxes.default_account_id`, «Основний»). Each account in the settings shows its route: `UAH → бюджет`, or
  `USD → продаж банком → {the same bank's UAH ФОП account} · UAH → бюджет`.
- **The original amount.** From a foreign-currency account the user types by hand what left it: `original_amount`
  in `original_currency`, the account's currency. The bank's rate differs from the NBU's, so nothing is fetched or
  derived from the NBU. The form shows the bank's rate it implies (`amount / original_amount`). The account's tracked
  balance falls by `original_amount`; a UAH account's falls by `amount`, as before.
- **Requisites and purpose** come from `tax_payment_requisites` (the copy block of the form). ЄП:
  `*;101;{РНОКПП};Єдиний податок, IV кв. 2026;{ПІБ}`; ВЗ the same with its own name; ЄСВ: `…;ЄСВ за жовтень 2026;…`.
- **Entry points:** the tax rows are the only ones.
  - A receipt's ЄП or ВЗ row opens Сплата податку for that receipt and tax: the existing record for editing, else a new
    one, prefilled. The row can be on Облік's receipt cards, the receipt page, the «Без інвойсу» record or Податки's
    ledger.
  - The two are paid one by one. The pay row «Сплатити {ЄП і ВЗ | ЄП | ВЗ} за цим надходженням» is removed (the
    owner, 2026-10-25, and the second handoff).
  - An ЄСВ row of the ledger opens Сплата ЄСВ: an unpaid one on its month, a payment for editing. Every month of a
    started quarter has a row, so ЄСВ can be paid ahead. «+ ЄСВ» and «Сплатити ЄСВ за {місяць}» are gone.
  - A failed payment's row opens that payment.
  - A user who can't record tax payments gets no link to a new one.
- **Податки** lists the payments together with what is still owed, in one ledger (§8.5). The first handoff's «Історія
  сплат» mode is folded into it.
- ЄП/ВЗ rows by month or by quarter are no longer created. There is no real data, so the demo data is reseeded
  without them.

### 8.5 Tax ledger (`TaxLedger`, Податки)

Податки is one ledger, month by month. It merges the **obligations** (what is to be paid) with the **tax payments**
(what was sent, paid or failed), under a summary of the chosen period (§16.5). One service, `TaxLedger`, builds it
for the endpoint below. The receipt page, the receipt cards and the tax payment form follow its rule for a receipt's
tax (the SPA's `receiptTaxPayment`): the payment that didn't fail, else unpaid, however many attempts failed. So a
receipt's ЄП and ВЗ read the same everywhere, and an unpaid row records a new payment.

**Obligations** are computed, never stored:
- **ЄП and ВЗ per receipt:** a receipt's `single_tax_uah` / `military_levy_uah` (when not zero) is an obligation of the
  receipt's quarter.
  - It is listed as **unpaid** while the receipt has no non-failed payment of that tax.
  - Once there is one, that payment stands for it, at the payment's own amount. A later edit of the receipt shows on
    Облік's reconciliation (§8.2), not as a ledger row, like the kopecks of `suggestions.md` §1.
- **ЄСВ per month:** every `due` month of every quarter that has started, including the quarter's months still ahead.
  Only months the app tracks count (from the tax opening balances, within the ФОП's registration).
  - The unpaid amount is `amount_due` minus the month's non-failed ЄСВ payments, listed as **unpaid** while above zero.
  - A month paid in part shows its payment and the unpaid rest. A month paid above the minimum shows only its payments.
  - Exempt months owe nothing.

**Statuses:** `unpaid` is the virtual status of an obligation; `failed`, `sent` and `paid` are the payments' own.

**Every item has two periods:**
- **Its quarter** is the quarter the tax belongs to:
  - ЄП/ВЗ: the receipt's (`received_at`; a payment's `period_year` / `period_quarter`);
  - ЄСВ: the quarter of its month.
- **Its month** is where the list places it:
  - an unpaid ЄП/ВЗ: the month the receipt came in;
  - an unpaid ЄСВ: the month it is for;
  - a payment: the month of `paid_at`, or the month its tax belongs to when it has no date.

The summary groups by quarter, the list by month. «ЄСВ за вересень» paid on 19.10 counts in III кв.'s summary, and
sits in Жовтень's card.

**Filters.** All three apply to the list, the month headers and the summary:
- the period: one year and a contiguous range of its quarters; an empty range is the whole year (§16.2);
- the taxes: any of ЄП · ВЗ · ЄСВ;
- the statuses: any of Не сплачено · Не пройшов · Надіслано · Сплачено.

**Summary** («Підсумок за період»). Per tax, it counts the items whose quarter is in the period and whose tax and
status are selected:
- the sums: `accrued = Σ unpaid + Σ sent + Σ paid`, `sent = Σ sent`, `paid = Σ paid`;
- failed payments are in no sum. Their total is reported apart (`failed`), for the screen's red line;
- a tax is left out when it isn't selected, or when it has nothing in the period (no accrued, no failed);
- `due_at`, while some counted item isn't paid yet (unpaid or sent): the earliest deadline among them. That is the
  quarter's `tax_due_at` for ЄП and ВЗ, and the quarter's `esv_due_at` for ЄСВ (§1);
- when all of `accrued` is paid, the screen shows «✓ закрито» instead;
- the total, for the «Усього податків» band, sums the taxes shown. The screen shows it only for two taxes or more.

**List** («Податки за місяцями»):
- **Months:** a card for every month of the period with a row that passes the filters, newest first. Every month of a
  started quarter has its ЄСВ row, so the current quarter's months ahead are listed too.
- **Order inside a month:**
  1. unpaid: ЄСВ first, then ЄП/ВЗ by the receipt's date, newest first (ЄП before ВЗ);
  2. failed;
  3. sent;
  4. paid.

  Within each status, the newest come first.
- **The month's header** sums its own rows: accrued (all but the failed ones), sent and paid.

**API.** `GET taxes/{year}/ledger?quarter=&tax_type=&status=`, each a list, as an array or comma-separated
(`quarter=3,4`). No quarter is the whole year; the statuses are `unpaid`, `failed`, `sent` and `paid`:

```
{ summary: { total: {accrued, sent, paid},
             rows:  [{tax_type, accrued, sent, paid, failed, due_at}] },
  months:  [{ month: '2026-12', accrued, sent, paid,
              items: [{ kind: obligation | payment, tax_type, amount, status, tax_payment_id,
                        receipt: {id, invoice_number, client, received_at}, period_month,
                        paid_at, account, due_at, quarter: {year, quarter} }] }] }
```

## 9. Notifications (`AlertCollector` → `NotificationSync`)

`AlertCollector` evaluates the rules below per profile (after the writes that can change them, and in a daily job).
`NotificationSync` upserts one `notifications` row per `dedupe_key` (e.g. `limit:{account_id}:80`,
`deadline:{tax_period_id}:tax`, `repatriation:{payment_id}`, `cap:90`), sets `resolved_at` when the condition no
longer holds, and never deletes rows. Read state is per user in `notification_reads` and is written **only** by
`POST notifications/{id}/read`, which the client calls when the user taps the mark-read icon, the notification's
action, or the row itself — never on listing or on opening the Сповіщення screen. Unread = unresolved and not read by
the current user; the count is shown on the bell of the root screens (§16.2). Dismissing a repatriation alert
(`repatriation_ack_at`) resolves its notification. Phase 1 shows notifications only on their own screen — none on
Головна (§16). While the declaration screen and the ЄСВ tab are hidden, the notifications that link to them
(`/taxes/declaration/{year}/{quarter}`, `/accounting/esv?year=`) open Податки on that quarter instead.

| Notification | Trigger | Severity |
|---|---|---|
| Deadline | `*_due_at` within `alerts.deadline_days` or past while `balance < 0` | warning / error |
| ЄСВ month | obligation `due`, unpaid past `esv_due_at` | warning |
| Platform repatriation | §6.7 | warning (dismissible) |
| Account limit | progress ≥ 80 % / ≥ 100 % | warning / error |
| Income cap | YTD ≥ 80 % / 90 % / 100 %; shows `excess_tax` | warning / error |
| Correction needed | `declaration_status = corrected` (can't arise while the declaration screen is hidden) | error |
| Contract | `ends_at` within 30 days / past with open invoices | info |
| Invoice overdue | `due_at < today`, not fully paid; never a virtual invoice | info |
| Ready without receipts | `ready` older than `alerts.ready_days` days | info |

Summary figures live on their own screens in phase 1: YTD vs cap, quarter invoiced/received and quarter taxes on
Облік's quarter summary; balances, inbound and outbound rows on the account screen; the current and previous quarter's
invoices on Головна (§16). The Сповіщення screen filters only «Усі» and «Непрочитані». The organization-wide board
(one row per profile) is a later phase.

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
  matched on `issued_at`) **or** `issued_from` / `issued_to`, plus `client_id[]`, `currency[]`, `status[]`; the
  status option «Без інвойсу» matches `source = virtual` (§4.2). No free-text search in phase 1. Filters are mirrored
  in the URL query so a filtered list is linkable.
- `POST` payments/refunds/transfers/tax-payments accept `Idempotency-Key`; replay returns the stored response.
- `PATCH` on invoices requires `If-Unmodified-Since` = `updated_at`; mismatch → 409.
- Cascading overrides on clients and invoices (§4.1): an explicit `null` clears the override, an absent key leaves it;
  effective values with their `source` only on `show` resources and `clients/{client}/invoice-defaults`, never on lists.
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
(`forDate()`) · `TaxLiabilityCalculator` (`QuarterLiability`) · `TaxReconciler` · `TaxLedger` (§8.5) ·
`TaxPaymentRecorder` · `PeriodLock` ·
`DeadlineScheduler` + `BusinessDayCalendar` · `EsvObligationScheduler` · `DeclarationFigures` · `InvoiceFreezer` ·
`InvoiceNumberGenerator` (`allocate`, `preview`, `rebuild`) · `InvoiceSettingsResolver` (`forInvoice`, `forClient`,
`currencyFor`; §4.1) · `PaymentPurposeRenderer` (`render`, `unknownPlaceholders`, `warnings`; §4.1) ·
`InvoicePdfRenderer` · `InvoiceStylePreview` · `MoneyFormatter` · `VirtualInvoices` (create with the receipt, `issue`,
`attach`; §4.2) · `AlertCollector` + `NotificationSync` · `PracticeOverview` (Облік's quarter summary, §7) ·
`ClientStats` (§16) · `AccountingExporter` · `SheetImporter`.

## 12. Stack and packages

api-core · Laravel 12/13 · MySQL 8 (≥ 8.0.13 for functional indexes) · Vue 3 + TypeScript + Tailwind, Sanctum SPA/token
auth · `brick/money` · `barryvdh/laravel-dompdf` with a bundled Cyrillic font (`spatie/laravel-pdf` + Chromium if
flex/grid is needed) · `openspout/openspout` for XLSX (v5; streams rows, no other dependencies) ·
`spatie/laravel-activitylog` · `spatie/laravel-permission` (via api-core) · Form Requests + Policies. **Not**
`spatie/laravel-settings` (per-profile keys need custom work; own table + DTO is less code). The SPA adds no package
for v5: the popovers, the date picker and the panels are the app's own components.

## 13. Tests (PHPUnit, Vitest)

Rounding identities (`|Σ hints − standalone| ≤ 0.01 × n`, `owed` vs standalone kopeck delta) · YTD delta across a
rate change · numbering under concurrency incl. first-row race, prefix rebuild, year rollover, user number preserved,
the client abbreviation prefix and its uniqueness per profile ·
lock enforcement (observer + policy + 409) · quarter assignment of cross-quarter receipts · holdings with a fee hop and
a cross-currency hop; allocation bounds; FIFO and exact-match proposals · fee/spread/net math; `receipt_fx_spread`;
`fx_gain` synthetic row lifecycle · one wire → several invoices (`receipt_group_id`, pro-rata fee) · refunds reduce
income and `paid_amount` · period lock with and without `correction_note`; cascade of `corrected` · deadline calendar
(10 May 2026 → 11 May; ЄП stays 20 May; ЄСВ 19th) · ЄСВ exempt months · NBU provider with HTTP fakes (number parsing,
failure path) · invoice settings cascade: each setting resolved at all four levels with the right `source`, `null`
clears an override, client change reaches inheriting drafts but not frozen invoices, override columns rejected on a
frozen invoice, re-freeze re-resolves; currency prefill chain, account cleared on currency change, rate prefill skipped
on a currency mismatch; purpose template: unknown placeholder 422 on save, missing contract value 422 on freeze,
`too_long` / `charset` warnings only for non-UAH; built-in texts follow the resolved locale, custom ones verbatim ·
PDF golden files per `schema_version`, per `money_format`, per locale (`en`, `uk`), with the contract section on/off and
the act note on/off; v1 goldens unchanged · `MoneyFormatter` for all six formats
and both separators on input · notifications: listing never writes `notification_reads`, `read` endpoint does; unread
count per user; dedupe/resolve cycle · invoice list filters and cursor paging · `ClientStats` and `PracticeOverview`
figures against fixtures · **profile isolation** (cross-profile id → 404; fail-closed scope; console context) ·
idempotent replays.

Added by v4 («Minimal»):
- **Notes:** each note resolves on its own at every level; both on; the 500-character cap and the required text;
  the migration from `note` + `note_kind`; the PDF prints each in its place, and the details span the card without
  the act and the other note.
- **Numbering:** every prefix × format × date × counter × reset combination; the back-to-draft rules; a typed number
  kept; a virtual invoice takes no number.
- **Tax payments:** ЄП/ВЗ require `payment_id` and reject a typed amount; ЄСВ requires the month; one non-failed
  ЄП and ВЗ per receipt, a failed one beside a paid one allowed; `sent` doesn't reduce the debt and `failed` is
  excluded; an account in a foreign currency accepted only when it is a `fop_bank` one, and only with the original
  amount, which lowers its balance; ЄСВ months due with their quarter; marking a month exempt is gone.
- **Tax ledger** (§8.5):
  - the summary groups by the tax's quarter: «ЄСВ за вересень» paid on 19.10 counts in III кв., not IV;
  - each column under each status filter; failed payments in no sum, but reported;
  - unpaid rows placed in the receipt's month or the ЄСВ month;
  - every month of a started quarter listed, the months ahead included;
  - the order inside a month: unpaid, failed, sent, paid;
  - the month headers summed from their own rows;
  - the receipt page and the ledger agree.
- **Virtual invoices:** created with the receipt and deleted with it; freeze, send, PDF, download and cancel denied;
  left out of overdue notifications and «до оплати», included in taxes, Облік and the client's totals; `issue` →
  numbered, issued on the payment date, one `fixed` item from the contract's template, PDF, still paid; `attach` →
  the receipt moved and the record gone; the service period required; receipts of kind `advance` / `other` rejected.
- **Account limits:** client receipts only, calendar windows only.
- **SPA (Vitest where it's logic, the browser for the rest):** the accordion (one group open, the rest inert,
  Скасувати restores, Зберегти sends only the group, errors stay in the group); a text card switched on opens its
  editor, and Готово saves nothing until Зберегти; the filter drawer's draft, apply and reset, one quarter, the year
  bounds; Новий переказ's balance tap; Облік «+ Надходження» → the picker → each branch; the bank's rate shown from
  the typed original amount.
- **Податки (SPA):**
  - the quarter range: every rule of §16.2, and a change of year keeping the range;
  - the multi-selects: removing the last option leaves none, which shows all, as all chosen does; the badge labels;
  - the summary: the band only for two taxes or more, the bar widths (`paid / accrued`, `sent / accrued`), the ticks
    (months for ЄСВ, quarters for the others), green and amber only above zero;
  - navigation: an unpaid ЄП/ВЗ row opens the receipt's payment prefilled, an unpaid ЄСВ row that month; no pay row on
    any receipt card.
- **Translations:** the guard accepts ЄП, ВЗ, ЄСВ and ФОП in English texts and still rejects any other Cyrillic.
- **Acceptance:** every screen (boards 001–044) and edit state (045–085) matches its PNG at 390 px within font
  rendering, and so do the second handoff's Податки renders (its eight summary states included); hidden features are
  unreachable; the PHP and frontend suites, Pint, the type check and the build pass.

Added by v5:
- **ФОП switching:** switching changes every profile-scoped list; the status lines of `/profiles` (unread
  notifications, taxes to pay, overdue invoices, the next ЄСВ due date) against fixtures; an inactive ФОП opens and
  reads; another organization's ФОП can't be opened (404, as before).
- **Книги, Доходи:** newest first; a quarter row from its first day at 0.00; «без доходу» and «ще немає» months; the
  running total carried over; the year's figures equal Облік's for the same quarters; a refund reduces the income of
  its own date.
- **Книги, Податки:** the figures equal `taxes/{year}/ledger` for the year; ЄСВ accrued for every month of a started
  quarter; a quarter = cumulative − previous; sent shown, not counted as paid; failed ignored; the grouping and the
  year in the URL. Exports: the PDF renders both tabs; the XLSX holds real numbers.
- **Date picker:** single — Сьогодні / Вчора, future days disabled on receipt dates, typing still works; range — start
  then end, a tap before the start restarts, a third tap starts anew, the presets, the day count; arrow keys and Enter.
- **Rules:** the badge casing and the РНОКПП mask helper (unit tests).
- **Desktop (§17):** at ≥ 1024 px the desktop shell renders, below it the mobile one; a deep link to `/invoices/:id`
  opens the list with the panel; Esc and a click on the scrim close the panel and give the focus back to the row; one
  panel at a time, «←» back to the previous record; «✎ Змінити» only on editable records; Облік's ЄП/ВЗ badges under
  their amounts, never over Дохід; one filter popover at a time with its draft; the pickers stay inside the viewport.
- **Acceptance (v5):** every board of delta 3 at 390 px and of the desktop handoff at 1440 px matches its PNG within
  font rendering; no horizontal scroll from 1024 to 1440 px; the sidebar fits 900 px.

## 14. Build order

The v3 build is done: the foundation, accounts, clients and contracts, invoices with the cascade and the 13 styles,
receipts and transfers, periods and the accounting view, tax payments and notifications. v4 («Minimal») goes in this
order; report to the owner wherever the code differs from §3, §4.2 or §8.4. No invoice or receipt exists outside
development, so the migrations drop the removed options without converting rows, and `DemoSeeder` is updated and the
demo data reseeded.

1. **Shell:** one ФОП per session (the switcher, Огляд практики and the organization board unreachable); the five tabs
   and the top bar with the bell; sticky root headers; the filter badge row with its drawer (§16.2).
2. **Components:** the accordion form and its group card (one open, the rest inert, per-group save), the text preview
   card and the source label (§16.3); the invoice and tax status badges and the «Без інвойсу» marker; the receipt card
   and the month card (§16.4).
3. **Settings and forms as accordions:** Профіль ФОП, Нумерація інвойсів, Документ інвойсу, Податки й сповіщення,
   Рахунок, Договір, Клієнт, the invoice editor. Drop the page-level saves and the removed options
   (`income.taxable_base`, `taxes.default_grouping`, `limit_window`, `limit_counts`, marking ЄСВ months exempt); add
   the payment accounts.
4. **The two notes** (§4.1): the migration, the cascade, the snapshot, template v3 (`invoice.md`), the SPA's preview.
5. **Taxes** (§8.4): the tax-payment rules, the unique key and the original amount; Податки (first in two modes,
   which step 8 replaces); Сплата ЄП / ВЗ and Сплата ЄСВ; the receipt page's «Сплата податків»; the receipt cards of
   Облік.
6. **Virtual invoices** and the receipt entry flow (§4.2): `source = virtual`, `POST payments` with `virtual_invoice`,
   `issue`, `attach`; «Інвойс для надходження», the receipt form without an invoice, the record.
7. **Lists and summaries:** Головна (two quarters, every status) and Інвойси with virtual rows and the status option;
   Облік's quarter summary; the Рахунки caption («Надходження за …»).
8. **Податки as the tax ledger** (the second handoff, §8.5):
   - `TaxLedger` and `taxes/{year}/ledger`, with the receipt page and the receipt cards reading it;
   - the screen: three filters, the summary and the month cards (§16.2, §16.5);
   - «Нарахування», «Історія сплат», «+ ЄСВ» and their receipt cards leave Податки.
9. **Untranslated names** (§16.5): the English dictionaries write ЄП, ВЗ, ЄСВ and ФОП as the Ukrainian ones do, and
   the translation guard allows them.

All nine steps are built (2026-10-25).

v5 goes in the handoffs' order: delta 3 first (desktop reuses its components), then desktop, then the backups screens
(`backups.md` §4.8).

10. **Shared components** (§16.6): the date picker (single and range; sheet and popover), the 📖 | 🔔 capsule, the
    section heading, the badge casing, the export block, the «Новий» pill, the modal header, the РНОКПП mask. Then rules
    1–10 across the screens, and every date field on the picker.
11. **ФОП switching** (§16.1): the status line of each ФОП (`profiles?append=status`), Ще's «ФОП» card, `/profiles`.
12. **App language** (§16.6): the Ще row with its sheet, the Вхід pill with its sheet.
13. **Книги обліку** (§16.7): `books/{year}/income` and `books/{year}/taxes` on `IncomeBook` and `TaxLedger`; the page,
    its tabs, grouping and states; the PDF and XLSX exports (`openspout/openspout`), which also give Облік its XLSX.
14. **Desktop** (§17): the shell (sidebar, page header, the breakpoint, the shell's popovers); the overlays (record
    panel, dialog, popover, the filter popovers); the list pages; the record panels and the forms in them; the settings
    and entity pages; Вхід and the date-picker popovers.

Later: `SheetImporter` for the 2025 sheet (`--profile=`, `--dry-run`: external invoices, payments, transfers, tax
payments, ЄСВ months paid or exempt; totals must reconcile to the sheet within the rounding policy), the yearly PDF
summary.

## 15. Deferred (later phases)

Hidden from the phase-1 UI but kept in the backend, and the options v4 removed: `suggestions.md` §2.

`profile_user` per-profile ACL · bank/platform statement imports and auto-matching (Monobank, PrivatBank, Payoneer,
Wise) · recurring invoices / "Повторити інвойс" and time-tracking import · invoice e-mailing (`invoice_emails`),
share sheet and overdue reminders · push / e-mail / Telegram delivery of notifications · client portal / share links ·
`tax_balance_checks` against the ІКП · ДПС XML declaration export · separate signed Acts of acceptance (the
invoice-as-act note of §4.1 covers the common case) · bilingual PDF template (`InvoiceLocale::EnUk`, no schema
change) and per-language custom texts ·
expenses as a feature (beyond `account_movements`) · other tax schemes (`tax_scheme`: 3 % + ПДВ, 2 група) ·
multi-organization users (api-core `organization_user` pivot) · free-text search on lists · passkeys / 2FA /
self-signup · organization board (the desktop layout came in v5, §17) · a Гроші tab, four-bucket tax balances and a
hero card (ideas of an earlier design review).

**Income recognition on ФОП-account arrival** (`income.recognition = fop_bank_receipt`), designed in v3 and removed
from phase 1 by the design prompts. Kept here so it can be restored additively: `payments.received_at DATE NULL` +
`recognition_status(enum recognised|pending)` + `CHECK (recognition_status <> 'recognised' OR received_at IS NOT
NULL)`; snapshot columns nullable while pending; `POST payments/{payment}/recognise {received_at}` computes the
snapshot; a platform receipt is saved `pending` and excluded from every liability; the landing allocation on a
`fop_bank` account proposes the date; tranches across quarters are split by the user into sibling rows sharing
`receipt_group_id` (never automatically); an "awaiting recognition" notification and an `is_pending` filter on the
receipt list; the setting and the toggle on the receipt form return at the same time.

## 16. Phase-1 UI scope («Minimal»)

Phase 1 follows the «Minimal» page of Claude Design's canvas. Its handoff is
`storage/handoff/prytax-minimal-handoff.zip`, on disk only and kept out of git. It holds `MINIMAL_APP.md`, the owner's
requests in `CHAT_LOG.md`, 85 boards at 390 pt (`screens/png`), their static HTML (exact sizes, colours and Ukrainian
copy) and the canvas sources with the state logic. Boards 001–044 are screens; 045–085 are the open states of the
accordion forms, not routes.

The second handoff, of the same afternoon, is in `storage/handoff/latest/`: `MINIMAL_DELTA_2026-10-25.md`, the owner's
messages #39–#57 in `CHAT_LOG_DELTA.md`, and `prytax-minimal-handoff-2.zip`, extracted in
`storage/handoff/prytax-minimal-handoff-2/`.
- **What it changes:** Податки becomes one ledger. Its boards are `M-Taxes`, `M-TaxesFilters` and the spec board
  `M-TaxesSummaryStates`, and they replace boards 010 and 011 (`M-TaxesHistory` is gone). The «Без інвойсу» record
  (`M-VInvoiceDetail`) loses its pay row.
- **Reference logic:** `source/M-Taxes.dc.html` holds the quarter range, the multi-selects and the aggregation.
- **Precedence:** where the two handoffs differ, the second one wins.

The third handoff (2026-10-28) is in `storage/handoff-28-10-2026/prytax-minimal-handoff-3/`: `MINIMAL_DELTA_3.md`, 31
boards at 2× (9 new: `M-ProfileSwitch`, `M-Books-Income`, `M-Books-Taxes`, `M-Books-TaxesByTax`, `M-BooksStates`,
`M-DatePicker`, `M-DateRangePicker`, `M-AppLanguage`, `M-LoginLanguage`; 22 changed, which replace their earlier
renders), the static HTML and the canvas sources (`source/M-DateRangePicker.dc.html` holds the range's tap logic). It
wins over the first two; the other 64 boards of handoff 1 are unchanged.

«Minimal» is a stripped-down copy of the earlier «Simplified» design, which the current app was built from: one ФОП at
a time, fewer controls, simpler forms. Where they differ, Minimal wins. The app is mobile-first, with a bottom tab bar
and bottom sheets for secondary flows. It is in Ukrainian and English (the SPA's dictionaries), and the boards' copy is
the Ukrainian one.

### 16.1 Scope

- **One ФОП at a time, switchable inside the organization** (v5; v4 had one ФОП per session). There is still no
  «Огляд усіх ФОП», and the identity chip (initials, «Коваленко О. П.», «ФОП · 3 група») stays static: no chevron, no
  link. The switch is Ще's «ФОП» card (mobile) or the sidebar's (desktop, §17), which opens «ФОП організації»
  (`/profiles`; a popover on desktop). Tapping a ФОП makes it current for the whole app and lands on Головна; an
  inactive one opens too, to read its history.
  - **The current ФОП is the client's** (the owner, 2026-10-28): remembered per user on the device, as before; the
    API keeps `profiles/{profile}/…` and its per-resource isolation, and delta 3's `PUT /api/me/current-profile` isn't
    built. On a first visit it is the one whose e-mail is the user's, else the first active one. A `?profile=ID` link
    still switches it. After a switch the SPA drops what it holds of the previous ФОП (kept pages, filters) and goes to
    `/`.
  - **«+ Новий»** on «ФОП організації» opens the existing new-profile form (owners only, as accountants can't create
    profiles; decision 21), which then lands on Податки й сповіщення.
  - The backend keeps every ФОП (§0).
- **Tabs:** Головна · Інвойси · Облік · Податки · Ще. Сповіщення opens from the bell, Книги обліку from the book beside
  it (§16.7).
- **Hidden** (routes, navigation and links removed; the backend stays):
  - Огляд практики, whose card is now on Облік;
  - Облік's ЄСВ and Книга доходів tabs and its declaration button, so declarations aren't filed in the app (§8.3);
  - Податки by month and by quarter, and the tax-period calendar button;
  - the round upload button of Інвойси (an external invoice starts from the new invoice's source switch);
  - Облік's download button in the header (CSV / XLSX sit at the foot of the list).
- **Removed options:**
  - the receipt types «Аванс» and «Інший дохід» (§4.2);
  - «Оформити повернення клієнту» on the receipt (§6.6);
  - the account limit's «Усі зарахування» and «Ковзне» (§3);
  - the tax base «Фактично отримана сума»;
  - «Показувати податки за» (Надходж. / Місяць / Квартал);
  - Податки's «Нарахування | Історія сплат» switch, its «+ ЄСВ» and «Сплатити ЄСВ за {місяць}», and every «До сплати»
    figure and «+ X надіслано» note (the ledger replaces them, §8.5);
  - the receipt card's pay row «Сплатити {ЄП і ВЗ | ЄП | ВЗ} за цим надходженням» (§16.4);
  - the single invoice note with its «Де друкувати» (§4.1);
  - every page-level «Зберегти» on settings pages (§16.3).
- **Built, not on the boards, kept:** Комісії й чистий дохід (`/fees`, reached from Перекази's losses card), one
  receipt for several invoices, the budget requisites on Податки й сповіщення, account opening balances and movements.
  ЄСВ months can't be marked exempt for now (§8.3).

### 16.2 Root screens, filters and badges

- **Root screens** (Головна, Інвойси, Облік, Податки, Сповіщення, Ще), top to bottom:
  1. The top bar, which scrolls away: the identity chip, and the 📖 | 🔔 capsule (v5): one white capsule, 44 px high
     with a 1 px border, holding two 44 px icon buttons split by a hairline. The bell keeps its unread count and opens
     Сповіщення; the book opens Книги обліку (`/books?tab=taxes` from Податки, `/books?tab=income` elsewhere). The
     books page itself, a pushed page, has no capsule.
  2. A sticky block: the title, an optional action, an optional segmented switch and the filter row. Once stuck, it
     gets a 1 px rule and a soft shadow.
  3. The content.
- **Actions:** an accent pill with «+»: «+ Новий» on Інвойси, «+ Надходження» on Облік. Податки has none: ЄСВ is
  paid from its rows (§8.4). List headers' pills read «Новий» with a 20 px plus (Інвойси, Рахунки, Клієнти, ФОП
  організації; rule 6); Клієнти's opens the client form.
- **The filter row** is one horizontally scrolling row of badges. The period badge comes first: dark when set, with a
  calendar icon. The others are white with a chevron, and show the current value, not the filter's name: «Усі
  клієнти», «Усі статуси», «Усі валюти» (rule 4).
- **The drawer.** Any badge opens one bottom drawer, «Фільтри», with «Скинути» and a sticky «Застосувати» (always that
  word, rule 5: Інвойси's «Показати N інвойсів» is gone). The drawer edits a draft: Застосувати commits it, and closing
  it discards it. Книги обліку have no drawer (§16.7).
- **Облік:** one year (`‹ 2026 ›`, the arrows disabled at the bounds of the data) and one quarter (I–IV кв.), by
  default the current ones; and «Групувати»: По місяцях · По інвойсах · Без групування.
- **Податки** (§8.5) has three badges, and each opens the same drawer:
  - **Період** (dark, with the calendar icon): one year and a contiguous range of its quarters.
    - **Labels:** «IV кв. 2026», «II–IV кв. 2026», or «2026 · увесь рік».
    - **Default:** the current quarter.
    - **The year** (`‹ 2026 ›`): changing it keeps the selected quarters.
    - **The whole year** is an empty range. It is drawn as all four quarters in a lighter «included» style, not as
      unselected.
    - **A tap on a quarter** (`tapQ_*` in the handoff's `M-Taxes.dc.html`):

      | Selected | Tap | Result |
      |---|---|---|
      | the whole year | a quarter `q` | `[q]` |
      | `[s..e]` | `q` outside it | `[min(s,q)..max(e,q)]`, the gap filled: IV, then II → II–IV |
      | `[s..e]`, two or more | `s` or `e` | that end trimmed: II–IV, then II → III–IV |
      | `[s..e]` | `q` strictly inside | the later part kept, `[q+1..e]`: II–IV, then III → IV |
      | `[q]` | `q` | the whole year |

    - **The hint** under the quarters: «Увесь рік. Натисніть квартал, щоб обрати лише його.» for the whole year, else
      «Обрано II–IV кв. Інший квартал розширює діапазон; обраний — прибирає його. Прибрати єдиний — увесь рік.»
  - **Податки:** ЄП · ВЗ · ЄСВ, several at once, all of them by default. None chosen means all, as all chosen does
    (the owner, 2026-10-25): removing the last one leaves none chosen. The badge reads «Усі податки» for both, or the
    chosen ones («ЄП, ВЗ»). The hint is «Можна обрати кілька. Не обрано жодного — показуються всі.»
  - **Статус:** Не сплачено · Не пройшов · Надіслано · Сплачено, with the same rules: none chosen means all. The badge
    reads «Усі статуси» for both, or the chosen ones («Не пройшов, Надіслано»). The hint is «Підсумок рахує лише обрані
    статуси. Не обрано жодного — показуються всі.»
  - **The URL** mirrors the filters (§10): `year`, `quarters` (`2-4`, `4`, or `all` for the whole year), `taxes` and
    `statuses` (comma-separated, left out when all are chosen, empty when none is). A link without `quarters` opens the current quarter
    of the current year, or a whole other year; an old link's `quarter` names one quarter. The first handoff's
    `?mode=` is gone.
- **Інвойси** keeps its filters (years, quarters, months or a range; clients; statuses; currencies), plus the status
  «Без інвойсу» after Скасовано; its drawer confirms with «Застосувати».
- **Status badges** are in sentence case everywhere (rule 2):
  - invoices: Чернетка (grey) · Готовий (accent-soft) · Надіслано {дд.мм} (amber) · Оплачено {дд.мм} (green) ·
    Скасовано {дд.мм} (red-soft, the number struck through);
  - tax payments: Надіслано {дд.мм} (amber) · Сплачено {дд.мм} (green) · Не пройшов (red, the amount struck through) ·
    Не сплачено (no fill, dashed border, muted). «Не сплачено» is also the ledger's status of an obligation, and a value
    of Податки's status filter. v4's lowercase tax badges are gone;
  - virtual invoices: «Без інвойсу», a white marker with a dashed border, in place of the number.

### 16.3 Accordion forms

**Used by** Профіль ФОП, Нумерація інвойсів, Документ інвойсу, Податки й сповіщення, Клієнт (форма), Рахунок, Договір
and the invoice editor.

**Closed group:** a card with the title, a blue «Змінити», and read-only label/value rows.

**Open group:**
- **Opening:** «Змінити» turns the card into an editor in place: a 2 px accent border, a «Редагування» chip, the fields,
  then [Скасувати] [Зберегти].
- **One at a time:** only one group is open. Every other group and every other block of the page (banners, switches,
  footers, hints) is dimmed (`opacity .45`) and inert until Зберегти or Скасувати. The dark «Наступний номер» card of
  Нумерація never dims.
- **Saving:** Зберегти sends only the group's fields, as a `PATCH` of that subset of the settings, the profile or the
  record. Validation errors keep the group open, with the errors inline.
- **Cancelling:** Скасувати restores the values.
- **No page-level save.** Creation forms keep their own primary action (Зберегти надходження, Зберегти сплату,
  Зафіксувати).
- **The invoice editor** saves its draft group by group («Чернетка зберігається після кожної групи»). The first saved
  group creates the draft, and each later one is a `PATCH` with `If-Unmodified-Since`.

**Text cards** (Призначення платежу, Примітка «акт», Примітка щодо оплати, Інша примітка):
- **View:** the title with its source line, then «Так буде в інвойсі» and the rendered text, its placeholders filled as
  the style preview fills them. The purpose adds `n / 140` and, outside UAH, «Довше за 140 символів: у SWIFT- чи
  SEPA-переказі банк платника може його обрізати.»
- **Without a switch** (the purpose): «Змінити» sits top right.
- **With a switch** (the act, both notes): the switch sits top right. The preview shows only when the switch is on,
  with «Змінити» at its bottom right. Switching the card on opens the editor at once.
- **Editing:** an auto-growing textarea (2–8 rows), with an inherited value as its placeholder. The purpose adds
  «+ Підстановка» (the placeholder sheet) and a «Приклад» card; the notes add «До 500 символів.».
- **Buttons:** [Скинути] (only when the level has its own value) … [Скасувати] [Готово]. Готово keeps the text in the
  group's draft, and the group's Зберегти saves it.

**Source labels** sit inside the field's card under its title, or on a select's label line, never in a row of their
own: «Типово», «Типово: увімкнено», «Типово: без примітки», «Як у ФОП: English», «Як у клієнта», «Як у клієнта:
вимкнено», «Власне · Скинути», «Власний · у ФОП: Outlined». «Скинути» is an accent action inline in that line.

**Deep link:** the client page's «Документ інвойсу → Змінити» opens the client form with «Валюта, мова, договір і
стиль» open (`#invoice-defaults`).

### 16.4 Receipt card

One component, for Облік and the «Без інвойсу» record. Податки lists its own rows (§16.5).
- **Header** (links to the receipt): the invoice number in mono, or the «Без інвойсу» marker; the amount in the
  receipt's currency, on a green pill; the client; the rate (`1 USD = 41.7350 ₴`); the credit date; the UAH amount, large.
- **Tax rows** ЄП and ВЗ: the label (a 44 px column) and the amount on the left, the status badge on the right. A dashed
  rule runs above the first row and solid ones between the others. Each row opens that tax's payment (§8.4).
- **No pay row:** «Сплатити {ЄП і ВЗ | ЄП | ВЗ} за цим надходженням» is removed (the owner, 2026-10-25, and the
  second handoff). The ЄП and ВЗ rows are the only way to pay a tax or to see its payment.
- **Fee rows,** on Облік with «Комісії й чисто» on: «Ком.» and the fee, then «Чисто» and the net in green, laid out the
  same way, without a badge.
- **Month card:** a dark header (the month, «N надходження», the UAH total on the right), then the receipts, 6 px apart.
  Податки uses the same card with its own header and rows (§16.5).

### 16.5 Screens

Board numbers are in brackets.

| Screen | Phase-1 content and behaviour |
|---|---|
| Вхід (001, v5 `M-Login`, `M-LoginLanguage`) | E-mail and password, «Запам'ятати мене». **The language pill** «🌐 Українська ⌄» sits top right (44 px, white, 1 px border) and opens the language sheet (§16.6) with the note «Запам’ятаємо на цьому пристрої. Після входу мову можна змінити в «Ще › Мова застосунку».» |
| Головна (002) | **Sections:** «IV квартал · 2026» and «III квартал · 2026», the current and the previous quarter by `issued_at`. Each lists every invoice of its quarter, any status, newest first. The count reads «N інвойсів», or «N записи» when a virtual one is among them. **Row:** the number («наст. {next number}» on drafts) and the status badge with its date; the client and the total; «Період …» and «сплатити до {due}», or «прострочено, до {due}» in red when unpaid past due. Only Sent invoices show the paid bar (`3 100.00 · 80 %`). **Subtitle:** «Відкриті інвойси · X до оплати» (ready and sent, per currency, virtual ones excluded). Link «Усі інвойси». |
| Інвойси (003, 004) | Grouped by the month of `issued_at` («IV кв. · Жовтень · 2026», its quarter first), each with its count as Головна's quarters have it («N інвойсів», «N записи» with a virtual one; the last month on screen counts once loaded whole), with infinite scroll; the sticky block holds the title, «+ Новий» and the badges. Rows exactly as on Головна (one component): period, «сплатити до» or «прострочено, до» in red, the dated status, the paid bar of a sent invoice (the owner, 2026-10-25). **A virtual row** reads «Без інвойсу» + «Оплачено {дд.мм}», then the client and the amount, then «Період …»: no date after the marker, no contract, no note. |
| Фільтри інвойсів (012) | As built, plus the status «Без інвойсу». |
| Облік (005–007) | **Header:** the sticky title with «+ Надходження» (→ Інвойс для надходження) and the badges (period, grouping). **«Підсумок кварталу»** (§7): the black income-limit band (`%`, YTD income, «з 10 091 049 ₴», ticks at 80/90 %), then «Дохід IV кв.» per currency (a bar, «отримано X з Y»), «Дохід для податків: N ₴», and «Податки IV кв.»: ЄСВ · ЄП · ВЗ, each with «сплачено». **«Надходження»:** the switches «Податки» (on) and «Комісії й чисто» (off), which apply at once; the month cards; CSV / XLSX at the foot. |
| Сповіщення (008, 009) | A sticky header with only «Усі · N» and «Непрочитані · N». A notification becomes read when it is tapped, on its action, or on ✓ (§9). |
| Податки (second handoff: `M-Taxes`, `M-TaxesFilters`, `M-TaxesSummaryStates`; replaces 010, 011) | §8.5, at `/taxes`. **Header:** the sticky title and the badges Період · Податки · Статус (§16.2); no action and no mode switch. **«Підсумок за період»:** one white card. **The «Усього податків» band** comes first, only when two or more taxes show. It is black, edge to edge, like Облік's income-limit band: the paid share on the right, saying what it means («57.3 % сплачено», paid ÷ accrued; rule 3); a 10 px bar, paid in light green then sent in amber, with a tick per quarter; and the three columns on dark. **Then a row per tax,** ЄСВ, ЄП, ВЗ in that order: (1) the name, with the due label on the right: the earliest deadline not yet paid («до 19.02.2027» for ЄП and ВЗ, «до 19.01.2027» for ЄСВ, its quarter's, rather than the board's «жовтень — до 19.11»), or «✓ закрито» in green when all is paid. (2) An 8 px bar: paid in green (`#17683F`, never blue), then sent in amber. Tick marks overhang it like the 80/90 % ones, splitting it per month of the period for ЄСВ and per quarter for ЄП and ВЗ (12 and 4 for the whole year). (3) Three columns: **Нараховано** · **Надіслано** (amber above zero) · **Сплачено** (green above zero). (4) When «Не пройшов» is selected and an attempt failed, a red «7 945.41 не пройшло (не враховано)». There is no «До сплати» and no «+ X надіслано» anywhere. **«Податки за місяцями»:** month cards, newest first. The dark header has the month and the same three columns, summed from the card's rows (the failed ones left out). **A row** has three lines. (1) The tax (a 44 px label), the amount (bold mono; struck through and grey when failed) and the status badge. (2) The invoice number in mono · the client (ЄП, ВЗ; the «Без інвойсу» marker for a virtual one), or «за жовтень» (ЄСВ). (3) «надійшло 19 жовтня · сплатити до 19.02.2027» on an unpaid ЄП/ВЗ; «сплатити до 19.01.2027» on an unpaid ЄСВ (its quarter's deadline, not the board's 19.11.2026); «12 жовтня · ПриватБанк ФОП · UAH» on a payment (`Monobank ФОП · USD → UAH` from a foreign one). A row opens its payment (§8.4). **Footnote:** «У кожному місяці: спершу несплачене (ЄП і ВЗ — у місяці надходження, ЄСВ — у місяці, за який він), далі «Не пройшов», «Надіслано» й «Сплачено». «Надіслано» борг не зменшує; «Не пройшов» ніде не враховується.» **Empty:** «Немає сплат за обраний період і податки.» **The spec board** `M-TaxesSummaryStates` draws the summary's eight states: nothing paid or sent; sent only; all sent; part paid; paid, sent and unpaid over two quarters; all paid; a failed payment; one tax. |
| Новий інвойс (013, 014, 072–079) | **Top:** the source switch «Створити в PryTax \| Зовнішній PDF»; the number preview card («BL-2026-10-31-14», «Присвоюється під час фіксації: абревіатура клієнта, кінець періоду й лічильник клієнта.», «Ввести власний»). **Groups:** Клієнт і договір (the contract's terms: currency, rate, payment days) · Період і дати (quick picks 01–14, 15–31, the month, other; issued = the period's end; due by the contract's terms) · Отримання коштів (active accounts in the currency, the last invoice's one first) · Документ (Стиль · Мова · Показувати договір · Призначення платежу · Примітка «акт» · Примітка щодо оплати · Інша примітка) · Позиції (not a group; dimmed while a group is open; «+ Знижка або коригування») · Внутрішня примітка. **Footer:** «Чернетка зберігається після кожної групи» and «Зафіксувати». The board has no currency field: the currency follows the contract, else the client. |
| Зовнішній інвойс, Рядок, Фіксація (018, 015, 016) | As built, with prefixed numbers. |
| Інвойс (017) | As built, with prefixed numbers. |
| Нове надходження (019) | No type switch: a receipt always has an invoice. It opens from an invoice («Додати надходження», with that invoice) or from Облік through «Інвойс для надходження». |
| Інвойс для надходження (041) | The search «Номер або клієнт»; «Відкриті інвойси», each with its remainder or «прострочено · {amount}»; «або»; the dashed «Без інвойсу — Оплата за договором, інвойсу немає» with «Створимо запис лише для обліку…». An open invoice → Нове надходження; «Без інвойсу» → the form without an invoice; ✕ back to Облік. |
| Нове надходження без інвойсу (042) | §4.2. The invoice field shows the dashed marker («Оплата за договором · лише для обліку»). Then Клієнт; Договір («обов’язково, якщо інвойсу немає», whose currency and rates it takes); «Сума від клієнта» (the tax base, typed); «За що (необов’язково)»; «Період з / по», required (the board's «Період необов’язковий» is overruled). Then the usual crediting block (account, received amount, date, fee), «Буде збережено» with ЄП and ВЗ, and an info box: no PDF, number or due date; the counter untouched; counted like any receipt; can be issued or attached later. |
| Надходження (020) | As built, without the refund action. **«Сплата податків»** always lists ЄП and ВЗ. A paid one shows the amount, the badge and «12.10.2026 · ПриватБанк ФОП · UAH». An unpaid one shows the computed amount, a dashed «Не сплачено» and the faded placeholder line «дд.мм.рррр · Рахунок · № платіжки» (opacity .4). Each row opens its tax payment; there is no «+ Додати». The caption reads «ЄП і ВЗ з цього надходження завжди тут. Натисніть, щоб записати чи змінити сплату. ЄСВ — окремо, у «Податки».» |
| Запис «Без інвойсу» (043, 044; v5 `M-VInvoiceDetail`) | §4.2. **The header's label** is the object's type, «Запис» (rule 8; it read «Інвойси»). **Header:** the marker with «Оплачено {дд.мм}», «Оплата за договором», the client. **Body:** the amount card («Сума від клієнта = сума надходжень»); Договір, Дата оплати, Рахунок отримання, Період послуг, За що; «Надходження» with the receipt card, without a pay row (the second handoff's `M-VInvoiceDetail`); the info box «Це запис лише для обліку». **Actions,** under «Якщо клієнт попросить інвойс»: «Оформити як інвойс» and «Прив’язати до виставленого інвойсу». |
| Перекази (021) | As built; the losses card leads to Комісії. |
| Новий переказ (022) | The source account's balance («8 217.50 USD») is bold accent. Tapping it sets Відправлено to the whole balance and clears Отримано. |
| Сплата ЄП / ВЗ (023) | §8.4. From top to bottom: the receipt card's header, read-only; Податок, ЄП \| ВЗ; Сума, locked, with «5 % від 130 246.50 ₴ · нараховано з надходження»; Дата сплати; Статус (Надіслано \| Сплачено \| Не пройшов); «Сплачено з рахунку», with the primary account's hint and the foreign-currency hint; from a foreign-currency account, the amount that left it, typed by hand («Списано, USD»), with the bank's rate it implies; the requisites with copy buttons and the purpose; Номер платіжки; «Зберегти сплату». |
| Сплата ЄСВ (024) | §8.4. Місяць (`‹ Жовтень 2026 ›`, its quarter's deadline «сплатити до 19.01.2027» rather than the board's 19.11, and what is already paid for the month before); Сума, editable, with the minimum's hint; then the date, the status, the account (with the original amount from a foreign-currency one), the requisites (purpose «ЄСВ за жовтень 2026») and the payment-order number. |
| Рахунки (025) | As built. A limit's caption reads «Надходження за місяць / квартал / рік» (the board's «Усі зарахування за місяць» is a leftover). |
| Рахунок, Рахунок (форма) (026, 027, 080–082) | The page as built. **The form's groups:** Основне (name, kind, currency) · Реквізити для інвойсу · Ліміт (a switch, the amount, Місяць / Квартал / Рік), plus an «Активний» switch card. |
| Клієнти, Клієнт (028, 029) | As built. The client page's «Документ інвойсу» card leads to the form's `#invoice-defaults`. |
| Клієнт (форма) (030, 062–071) | **Groups:** Основне (Назва, Абревіатура with «Префікс номерів: BL-РРРР-ММ-ДД-n. Латиниця й цифри, до 10.», Юридична назва, Назва українською, Країна) · Адреса в інвойсі · Податкові дані й пошта · Валюта, мова, договір і стиль · Тексти в інвойсі (the four text cards) · Внутрішня примітка. |
| Договір (031, 083–085) | **Groups:** Основне · Опис позиції в інвойсі (closed: the rendered line; open: the template, the placeholder chips and «Так виглядатиме в інвойсі») · Погодинні ставки, plus an «Активний» switch card. |
| Ще (032, v5 `M-More`) | **The «ФОП» card** (v5) on top, under the heading «ФОП»: the avatar, the full name («Коваленко Олена Петрівна»), «ФОП · 3 група · РНОКПП ••••4821»; under a divider the row «⇄ Змінити ФОП» with «{N} в організації» (every ФОП of the organization, active or not) and a chevron, to `/profiles`. **Groups:** Гроші й контрагенти (Рахунки · Перекази та комісії · Клієнти й договори) · Налаштування ФОП (Профіль і реквізити ФОП · Податки й сповіщення) · Інвойси (Нумерація інвойсів · Документ інвойсу, which shows the style's name) · Організація (Користувачі й ролі · Резервні копії (`backups.md` §4.8) · «🌐 Мова застосунку» with «Українська» and a chevron, opening the language sheet (§16.6) · Вийти). The old inline language switch goes. |
| Профіль ФОП (033, 045–048; v5 `M-ProfileEdit*`: the РНОКПП masked `••••4821`) | **Banner** (static): «3 група · спрощена система · без ПДВ · без працівників». **Groups:** Податкова особа (ПІБ, ПІБ латиницею, РНОКПП, дата реєстрації, the passport instead of РНОКПП) · Адреса й контакти · Інвойси (валюта, мова документа, підпис у PDF) · Формат сум (the app's and the invoices', with live samples). Plus an «Активний ФОП» switch card. |
| Нумерація інвойсів (034, 053–055) | **The dark «Наступний номер» card** stays exactly as built and never dims. **Groups:** Формат номера · Лічильник · Поточні лічильники (§5). In read mode the counters show the edit layout with disabled inputs, with «Останній виданий номер; наступний інвойс клієнта отримає +1.». **Then:** the rules text, and the link «Примітки в інвойсі → Документ інвойсу». |
| Документ інвойсу (035, 056–061) | **Intro:** «Типові налаштування інвойсів ФОП. Клієнт чи окремий інвойс можуть мати власні.» **Groups:** Стиль, мова й договір · Тексти в інвойсі (the four text cards). **Footnote:** «Змінює нові інвойси й чернетки; зафіксовані не змінюються.» The style picker, the preview and the placeholder sheet (036–038) stay as built. |
| Податки й сповіщення (039, 049–052) | **Groups:** База оподаткування (Сума за інвойсом, a fixed card citing ІПК 462/ІПК/99-00-24-03-03-06; Курсовий дохід: Не враховувати / Враховувати) · Сплата податків (the payment accounts with their routes and one «Основний», «+ Додати»; ЄСВ до N числа; §8.4) · Вхідні сальдо · Сповіщення (Кошти на платформах: кінець кв. / через N днів / вимкнено; терміни за N дн.). The budget requisites stay as built (not on the board). |
| Користувачі (040) | As built («Користувачі працюють з ФОП …», the three roles). |
| ФОП організації (v5 `M-ProfileSwitch`) | `/profiles`, pushed from Ще with a back arrow. **Header:** «ФОП організації», the «Новий» pill (owners only), the meta «{N} активні · {N} неактивний». **Search** «Пошук за ПІБ або РНОКПП», on the client. **Sections** «Активні · N», «Неактивні · N». **Row:** the avatar, the full name, «РНОКПП ••••4821», a status line and a chevron. The status line: «{N} сповіщення · {X} ₴ податків до сплати» (neutral), «без сповіщень · податки сплачено» (green), «{N} прострочений інвойс · ЄСВ до {дд.мм}» (red when an invoice is overdue). The current ФОП has a blue check badge on its avatar and a soft accent background (no «Поточний» label). An inactive ФОП has a dashed avatar outline and «Неактивний · закрито {дата}». **Footnote:** «Перемикання змінює ФОП для всього застосунку: інвойси, облік, податки й налаштування. Користувачі й ролі — спільні для організації. Неактивного ФОП можна відкрити, щоб переглянути історію.» The figures come from `profiles?append=status` (§16.1). |
| Книги обліку (v5 `M-Books-Income`, `M-Books-Taxes`, `M-Books-TaxesByTax`, `M-BooksStates`) | §16.7, at `/books?tab=income\|taxes&year=&group=quarter\|tax`. |

**Display rules** that apply everywhere:
- Amounts follow `display.money_format` (default `1 234.99`), with tabular figures and no wrapping.
- The currency code follows the amount (`3 892.50 USD`), and `₴` follows UAH amounts.
- Secondary figures are one step lighter and smaller than the primary ones.
- Invoice numbers are in mono.
- **ЄП, ВЗ, ЄСВ and ФОП are never translated** (the owner, 2026-10-25). The English UI writes them as the Ukrainian
  one does («ЄСВ for October», «ФОП profile»), never «single tax», «military levy», «SSC» or «sole proprietor».
  - The translation guard (`resources/js/i18n/i18n.test.ts`) allows these four words, and only these, in English
    texts.
  - The invoice PDF is the client's document and keeps its English wording («Individual Entrepreneur», `invoice.md`).
- Plain section headers (uppercase captions on the page, without a background) separate a period's parts with « · »:
  «IV квартал · 2026», «IV кв. · Жовтень · 2026», «Жовтень · 2026» (the owner, 2026-10-25).
- Lists of records run from the newest to the oldest (the owner, 2026-10-25): invoices, receipts, tax payments,
  transfers and their receipts, contracts, notifications, and the groups they fall in (months, invoices). Not
  records, and kept in their own order: a receipt's money flow (in the order the money moved), an invoice's items,
  and entities such as clients and accounts (by name, by kind). CSV exports stay chronological.
- The app doesn't zoom on phones and tablets (the owner, 2026-10-25): neither a pinch nor a double tap, nor a focused
  field. Downloaded PDFs open in the device's viewer, which zooms.
- Going back to Інвойси, Перекази or Податки from a record opened there returns to the same place, scroll and filters
  kept, without loading again (the owner, 2026-10-25). Only when something was saved meanwhile do they read their data
  again, quietly, keeping the place.
- A tab returns to its screen as it was left: its filters, and on Інвойси and Податки its scroll too, without loading
  again (the owner, 2026-10-25). The first visit opens it fresh, and signing in starts afresh.

### 16.6 Shared components (v5, delta 3 §3–§5)

Rules 1–10 of delta 3 are component rules, not one-off fixes; desktop (§17) reuses every component of this section.

| Rule | Component |
|---|---|
| 1 | **Section heading:** one style everywhere, an uppercase 13 px muted label with an optional meta text on the right, and no hairline after it. |
| 2 | **Status badges** in sentence case (§16.2). |
| 3 | The «Усього податків» band says what its percent is: «57.3 % сплачено». |
| 4, 5 | Filter chips show their values; every drawer confirms with «Застосувати» (§16.2). |
| 6 | **The list-header pill** «Новий» with a 20 px plus (Інвойси, Рахунки, Клієнти, ФОП організації). «+ Надходження» keeps its label. |
| 7 | **The full-screen modal header:** the title on the left, ✕ on the right (Позиція, Підстановка, Інвойс для надходження, Перегляд стилю). |
| 8 | **A record's header label** is its type: Інвойс, Надходження, Рахунок, Клієнт, Запис. |
| 9 | **The РНОКПП mask** is `••••4821` (four dots and the last four digits) everywhere: Профіль ФОП, Ще, ФОП організації. One helper. |
| 10 | **The export block:** the heading «Експорт», then secondary buttons (48 px, radius 14) in a 2-up grid: CSV / XLSX on Облік, PDF / XLSX on the books. Every secondary button has the radius 14. |

**The date picker** (`M-DatePicker`, `M-DateRangePicker`): one component with two modes, used by every date field
(receipts, transfers, tax payments, the invoice dates and service period, contracts, the period of «Без інвойсу»). It
replaces `DatePickerSheet`. The field stays a typed text field (`дд.мм.рррр`, mono); the picker opens on a tap.
- **Mobile:** a bottom sheet titled with the field's label, ✕ top right, a sticky «Готово». The field being edited
  gets a 2 px accent border in the form above; no other field looks focused.
- **Single:** the chips «Сьогодні», «Вчора»; the month header `‹ Жовтень 2026 ›`; a Monday-first grid. Selected: a
  filled accent circle; today: an accent ring; disabled: grey and inert. Disabled days are per field: receipt dates
  disable the future, with «Майбутні дати недоступні — кошти ще не зараховано.». A tap selects and updates the field
  live; «Готово» closes.
- **Range:** the preset chips Цей місяць · Наступний місяць · Минулий місяць · Цей квартал; one month grid. The first
  tap sets the start, the second the end; a tap before the start restarts; a third tap starts a new period. The days
  between are a soft accent band rounding into the filled ends. The summary «Період 01.11 – 30.11.2026 · 30 дн.», or
  «Оберіть кінець періоду» (accent) while only the start is set; the note «Торкніться початку, потім кінця. Третій
  дотик починає новий період.». Both fields update live. The rules are `renderVals()` of
  `source/M-DateRangePicker.dc.html`.
- **Locale:** month names in the nominative in headers («Жовтень 2026»), the genitive in sentences («20 жовтня 2026»);
  weeks start on Monday.
- **Accessibility:** the grid is `role="grid"`, each day a button labelled «20 жовтня 2026»; arrow keys move the
  focus, Enter selects; the sheet traps the focus and gives it back to the field.

**App language** (`M-AppLanguage`, `M-LoginLanguage`): the language is the user's, as built (`users.locale`; the
device's choice before signing in, which becomes the account's on sign-in; English is complete). v5 changes the look
only: a bottom sheet «Мова застосунку» with two radio rows, «UK · Українська · Ukrainian» and «EN · English ·
Англійська» (the chosen one accent-soft with a check), and the note «Лише інтерфейс і лише для вас. Мова інвойсів — у
«Документ інвойсу» та в картці клієнта.» (Ще), or the sign-in note (§16.5 Вхід). Choosing applies at once and closes
the sheet. Invoice PDFs keep their own language.

### 16.7 Книги обліку (v5, delta 3 §2)

A pushed page with a back arrow, no capsule and no filter drawer (the owner, #68). The year and the grouping are its
only controls.
- **Header:** «Книги обліку», the sub-line «Коваленко О. П. · ФОП 3 група»; on the right the year chip «🗓 2026 ⌄»
  (from the ФОП's registration year to the current one). Under it the tabs Доходи | Податки. The URL keeps `tab`,
  `year` and, on Податки, `group`.
- **The foot of each tab:** the export block (§16.6) with PDF and XLSX for the tab and the year.

**Доходи** (`M-Books-Income`), the income book of п. 296.1:
1. The black band, Облік's component: «Дохід з початку року» and «{x} % ліміту», the amount, the bar with the 80/90 %
   ticks, «ліміт 3 групи 10 091 049 ₴ · залишилось …».
2. The heading «Книга доходів», «нові вгорі».
3. The table, newest first; columns (month, its header invisible but kept) · «Дохід, ₴» · «З початку року».
   - **Quarter rows:** black, «IV кв.» with «разом · поточний» or «разом» under it, the quarter's total and the running
     total at its end.
   - **Month rows:** a chevron, the month and «· N» (receipts), its total and the running total at its end. They expand
     to their receipts; the current month is open.
   - **Receipt rows:** the credit date `дд.мм` and the client, the UAH amount and the running total after it; a tap
     opens the receipt.
4. The footnote «Книга обліку доходів у довільній формі: кожне надходження за датою зарахування, у гривнях за курсом
   НБУ. Повернення коштів клієнту зменшує дохід дня повернення.»

Income is a receipt's UAH amount at the NBU rate of its credit date (the base of the taxes and the limit), virtual
receipts included; a refund reduces the income of its own date; the running total starts on 1 January.

**Податки** (`M-Books-Taxes`, `M-Books-TaxesByTax`), the year to date on `TaxLedger` (§8.5), so the figures match
Податки:
1. The black band: «Податки з початку року» and «{x} % сплачено»; the bar (paid `#7FD3A0`, sent `#F2B66B`); the columns
   Нараховано · Надіслано · Сплачено.
2. «Групувати»: Квартал | Податок (Квартал by default).
   - **Квартал:** «Наростаючим підсумком», newest first, one card per cumulative period («I–IV кв. 2026» with a
     «поточний» chip, then «I–III кв.» …). The title has the period and the total accrued to its end; the columns «За
     {q} кв.» · «Наростаючим» · «Сплачено»; rows ЄСВ, ЄП, ВЗ. «Сплачено» is green above zero, a sent amount a small amber
     «+8 122.67» under it. Footnote: «ЄП і ВЗ — як у декларації: наростаючим підсумком з початку року, а за квартал =
     наростаючим − попередній період. ЄСВ — {minimum} ₴ за кожен місяць кварталу. «+ сума» під сплаченим — надіслано,
     банк ще не підтвердив.»
   - **Податок:** «По податках · 2026» with the legend (сплачено, надіслано, не сплачено); one card per tax (ЄСВ, ЄП, ВЗ)
     with the nearest due label, a segmented bar (ЄСВ by month, ЄП and ВЗ by quarter; each segment paid, sent or grey),
     the three columns and a small table «За квартал» · «Наростаючим», newest first.
   - The tax cards don't navigate; payments are made on Податки.

ЄП and ВЗ of a quarter are the sums over the receipts credited in it; ЄСВ is accrued for every month of every started
quarter, with or without income. Sent and paid come from the tax payments, by the period the obligation belongs to,
not by the payment's date; failed payments count nowhere.

**Early and empty states** (`M-BooksStates`): only months that have started, a past month without income reading «без
доходу» with «—» and the current one «ще немає»; a quarter row from the quarter's first day, even at 0.00, the running
total carried over; a year without receipts shows «Надходжень за {year} ще немає» instead of the table, without a
button, the bands staying; ЄП and ВЗ of a quarter without a receipt show 0.00 in grey with «ЄП і ВЗ за I кв.
з’являться з першим надходженням у кварталі.»; a year before the registration shows a card with the registration date
and a button to the first year.

**API** (profile-scoped, as every report): `GET profiles/{profile}/books/{year}/income` (the band, the quarters newest
first with their months and receipts), `GET profiles/{profile}/books/{year}/taxes` (the totals, the cumulative periods,
the taxes with their segments), `GET profiles/{profile}/books/{year}/{income|taxes}/export?format=pdf|xlsx`. The PDF is
Blade → dompdf, a portrait A4 table mirroring the screen (the owner: a mirror will do), the quarter rows dark. The XLSX
has one sheet, real numbers.

## 17. Desktop layout (v5, «Minimal (Desktop)»)

The handoff is `storage/handoff-28-10-2026/prytax-desktop-handoff/`: `DESKTOP_APP.md`, 59 boards at 1440 px
(`screens/png`, `008-D-RecordPanels` is the rules board), the static HTML and the canvas sources. Most boards put the
mobile board's content into a desktop container, so the mobile component in a desktop container is the target. It is
the same product, API and data: no desktop-only features or endpoints. For layout and overlays the desktop handoff
wins; for domain rules §16 does.

### 17.1 Layout switch

- From **1024 px** (Tailwind `lg`) the desktop layout; below it the mobile one, unchanged. The boards are 1440 px wide;
  between 1024 and 1440 the sidebar keeps 248 px and the content is fluid. **Under 1280 px** the right-hand columns
  (Головна's 360 px column, the 300 px side notes) move under the main column (the owner, 2026-10-28). No horizontal
  scroll from 1024 to 1440.
- **Routes are the same.** A record's route renders a full page on mobile and a **panel over the list it belongs to**
  on desktop, the list staying mounted under it: nested routes with the panel as the list's child, so a deep link to
  `/invoices/:id` opens Інвойси with the panel.

### 17.2 Shell

- **Sidebar**, 248 px, white, a 1 px right border, full height (it replaces the tab bar and Ще): the logo (a 32 px ink
  tile and «PryTax», «Tax» in accent); the ФОП card (avatar, «Коваленко О. П.», «ФОП · 3 група», then «⇄ Змінити ФОП»
  with the count and a chevron, opening the ФОП popover); the main nav Головна · Інвойси · Облік · Податки · Книги;
  «Гроші й контрагенти»: Рахунки · Перекази та комісії · Клієнти й договори (counts on the right); «Налаштування»:
  Профіль і реквізити · Податки й сповіщення · Нумерація інвойсів · Документ інвойсу · Користувачі й ролі · Резервні
  копії (`backups.md` §4.8); the footer: the language row «🌐 Українська · UK ⌄» (the language popover), the e-mail and
  role, «Вийти» in red. Items are 36 px high at least, radius 10, a 20 px icon; the active one accent-soft
  (`#E9EDFB`), text `#1F338C` at 600, `aria-current="page"`. It fits 900 px high; below that the nav scrolls and the
  footer stays.
- **Page header** (padding 28 40 20): an optional 40 px round back button, the 30 px title, an optional muted sub-line;
  on the right the page's actions (one accent pill, 44 px: «+ Новий інвойс», «+ Надходження», «+ Новий»; optional
  secondary buttons), then the 📖 | 🔔 capsule (the book to `/books`, `?tab=taxes` from Податки; the bell opens the
  notifications popover).
- **Content:** padding 0 40 48, 20 px gaps; settings and detail pages are one 760 px column with an optional 300 px
  side note «Як це працює» (grey, sticky).

### 17.3 Overlays (one rule set, `008 D-RecordPanels`)

| Pattern | For | Behaviour |
|---|---|---|
| **Panel** on the right | Viewing, creating and editing records: інвойс, запис без інвойсу, надходження, переказ, сплата податку | Over the list it came from, which stays dimmed. Closes on ✕, Esc or a click on the scrim. One at a time: going to a related record replaces the content and adds «←». 560 px; the invoice editor 760 px. |
| **Dialog**, centred | Confirmations and short steps inside a record: «Зафіксувати інвойс?», Позиція, «Інвойс для надходження», Підстановка | Over the panel or page, with its own «Скасувати» and primary action; closing it keeps the panel. 480–560 px. |
| **Popover** | Filters (one at a time), the date pickers, «⋯ Інші дії», notifications, the ФОП switcher, the language | Anchored to its button, no dimming, kept inside the viewport; Esc or a click outside closes it. |
| **Page** | Lists and sections (Головна, Інвойси, Облік, Податки, Книги, Рахунки, Клієнти, the settings) and the pages of an account and a client (they hold lists) | A sidebar item, or «←» in the header. Records on them open as panels. |

**Panel anatomy:** the scrim `rgba(23,25,31,.38)`; the panel on paper with the shadow `-16px 0 40px rgba(23,25,31,.2)`.
The header (white, a bottom border): an optional «←» (the kicker then names the record it goes back to); the kicker (the
record's type, 12 px muted), the title (an invoice number in 22 px mono bold, an amount, a route or a name), the
sub-line (client · date) and the badges; «✎ Змінити» (40 px, radius 12) only on editable records (надходження, запис
без інвойсу, переказ, сплата податку; a frozen invoice has none, its «Повернути в чернетку» is in ⋯); ⋯ «Інші дії»; ✕.
The body scrolls (padding 20 24, gaps 16, children never shrink). The footer (white, a top border) has one primary
action and at most one secondary, side by side (`flex 1` / `1.4`), or none. Closing with unsaved changes asks
«Відкинути зміни?» in a dialog. The focus moves into the panel, is trapped, and returns to the row that opened it;
`role="dialog"`, `aria-modal`, labelled by the title.

**Which panel where:** an Інвойси row → Інвойс (status steps, the payment card, details, receipts, the PDF row, the
note; footer «+ Додати надходження»; ⋯ Завантажити PDF · Надіслати ще раз · Завантажити власний PDF · Позначити
оплаченим · Повернути в чернетку · Скасувати інвойс, each disabled with its reason where it can't be done); a virtual
row → Запис без інвойсу; «Додати надходження» → Нове надходження with «←» to the invoice; an Облік row → Надходження;
Облік's «+ Надходження» → the dialog «Інвойс для надходження» → Нове надходження or the form without an invoice; a
Перекази row → Переказ, «+ Новий» → Новий переказ; a Податки row → the payment form when unpaid, else Сплата податку
(view, with «Позначити сплаченим»); «+ Новий інвойс» → the editor (760 px); its source switch → Зовнішній інвойс (760 px,
[Зберегти] [Зафіксувати]). In panels ЄП and ВЗ of a receipt are two cells side by side, each with its label, the amount
and the status badge under it (#81).

### 17.4 Screens

Only what differs from mobile; copy, data and logic are mobile's.
- **Головна:** two columns. Left, the invoices by quarter as a table (Номер · Клієнт · період · Сума · Статус; under the
  client the period and «сплатити до …» or «прострочено, до …»; «3 100.00 · 80 %» under a sent one's amount). Right,
  360 px: the income-limit band, «Податки IV кв.» (ЄСВ, ЄП, ВЗ with due dates, «Усі податки»), and the latest
  notifications.
- **Інвойси:** the chips «🗓 2026 ⌄» (dark) · «Усі клієнти ⌄» · «Усі статуси ⌄» · «Усі валюти ⌄» and the search «Номер
  або клієнт» on the right; a table per quarter (Номер · Клієнт · Період · Сума · Оплата · Статус; Оплата «до 14.11»,
  «прострочено, до 19.10» in red, or «—»; the virtual marker in place of the number). Rows open panels; no split view.
- **Filters:** a chip opens its own popover under it (one at a time): Інвойси's statuses as checkboxes; Облік's period
  as a year and a quarter; Податки's as a year and a quarter range (§16.2's rules). The footer «Скинути» and
  «Застосувати»: the popover edits a draft, Застосувати commits it, Esc discards it.
- **Облік:** the chips «🗓 IV кв. 2026 ⌄», «По місяцях ⌄», the switches on the right; «Підсумок кварталу» as three cards
  in a row; «Надходження» with CSV, XLSX (and the backups' «Архів ZIP») right-aligned on its heading; month cards with a
  dark header and a table: Інвойс `minmax(0,1fr)` · Дата 96 · Сума 130 · Курс НБУ 96 · Дохід ₴ 150 · ЄП 5 % 136 · ВЗ 1 %
  136, gap 16. ЄП and ВЗ cells: the amount right-aligned with the badge under it. Fee rows use the same grid.
- **Податки:** «Підсумок за період» as a table card (tax · bar · Нараховано · Надіслано · Сплачено; the black total row
  first); «Податки за місяцями» as month cards with rows: tax · amount · reference · when · badge.
- **Книги:** the tabs left and the year chip right under the header; Доходи has the band with the note beside it, PDF
  and XLSX right-aligned just above the table, and the invoice number after the client in receipt rows; Податки's
  cumulative cards in two columns, the tax cards three across.
- **Shell popovers:** Сповіщення (about 420 px, the title, a settings icon, ✕; «Усі · N» | «Непрочитані · N»; the
  mobile items, scrolling inside); ФОП організації (about 380 px; the count line, the search, the two lists, «+ Новий
  ФОП»); Мова застосунку (280 px, above the sidebar row; the note «Лише інтерфейс. Мова інвойсів — у «Документ
  інвойсу» та в картці клієнта.»). `/alerts` stays a page for links.
- **Вхід:** split: left, flexible, ink, with the logo, «Облік доходів, інвойси та податки для ФОП 3 групи», a sub-line
  and «© 2026 PryTax»; right, 560 px, the mobile form at 400 px; the language pill top right (its note «Запам’ятаємо в
  цьому браузері. Після входу — у бічному меню внизу.»).
- **Records and forms:** Новий інвойс is the mobile accordion in the 760 px panel (the number preview atop the body;
  the footer «Чернетка зберігається після кожної групи» and «Зафіксувати»); Позиція and Зафіксувати are 560 px dialogs;
  Стиль інвойсу is a page (the style grid three across, grouped, with «Як у ФОП»; on the right, 360 px and sticky, the
  preview with `‹ name n/13 ›`, the language, «Відкрити PDF», and [Переглянути] [Обрати …] side by side); «Інвойс для
  надходження» a 520 px dialog; Підстановка a 560 px dialog.
- **Date pickers** (popover variant): single, 320 px, under the field, kept 24 px inside the viewport, one month, the
  footer links «Сьогодні», «Вчора» (and «майбутні — недоступні» on receipt dates); range, 720 px: «Швидко» with the
  presets as a 168 px column, two months side by side with one `‹ ›` pair, the footer «Період … · N дн.», «Початок,
  потім кінець», «Готово».
- **Lists and entity pages:** Рахунки's groups flow into two columns (a group never splits); Перекази, Клієнти and
  Користувачі are 880 px columns; Рахунок and Клієнт are pages whose header holds «←», the name and the capsule, with
  «✎ Змінити» to the right of the item's name.
- **Settings** (Профіль, Податки й сповіщення, Нумерація, Документ інвойсу, the client, account and contract forms): the
  accordion in the 760 px column with the side note, the same rules as mobile; they keep the blue «Змінити» per group
  (the pen button is for records and entity pages).
- **States boards** `058 D-BooksStates`, `059 D-TaxesSummaryStates`: the desktop arrangement of §16.7's states and
  Податки's summary states.

### 17.5 Components

On top of §16.6's: `AppShell` (`Sidebar`, `PageHeader`); `RecordPanel` (kicker, title, sub, badges, `canEdit` and the
edit target, the menu items, back, the body and the footer; Esc and the scrim, the unsaved-changes guard, the focus
trap); `Dialog`; `Popover` (anchored, flipping and shifting to stay in the viewport; used by the filter popovers, the
date picker, the menu, notifications, the ФОП switcher and the language); `DataTable` (a CSS grid with fixed numeric
columns and a flexible first one, numbers right-aligned in mono tabular figures); `TaxCells`; `useBreakpoint()` with
`lg` = 1024, by which a route renders a page or a panel.

### 17.6 As built (2026-10-28, branch `feat/desktop`)

- **Layout switch:** `useBreakpoint()` (`isDesktop` from 1024 px, `isWide` from 1280 px). `App.vue` renders the
  sidebar and the page; `TabScreen` and `SubScreen` render the desktop page header (and `SubScreen` its column: 760 px,
  880 px for lists, or the whole width; its `aside` slot is the side note). Sidebar pages (`meta.section`) have no back
  button.
- **Panels:** a record's route (`meta.panel`: `of`, `wide`, `dialog`) renders in `RecordPanel` over a background
  route — the page it was opened from (`panelBackground` in the router), kept while one record leads to another, or
  `meta.panel.of` for a deep link —, which a `RouterView :route` keeps mounted under the scrim (`loadRouteLocation`
  loads it first). This replaces the handoff's nested routes with the same behaviour. Closing goes back through the
  history when the page came from there. «Інвойс для надходження» is the 520 px dialog variant. The record pages
  render their mobile content in the panel with the panel's header (the kind of record over the title, ← to the
  previous record, ✕); the full header anatomy (badges, «✎ Змінити» and ⋯ in the header) is not done yet, and the
  pages keep their own.
- **Overlays:** `BottomSheet` and `FullScreenSheet` are centred dialogs on desktop (520 and 560 px); `Popover` (anchored,
  flipped, kept 24 px inside the viewport, one at a time) carries the shell's popovers (notifications in `BarActions`,
  the ФОП switcher and the language in `Sidebar`), the filter popovers (`FilterSurface`, the sheets' `only` part) and
  the date pickers (`DatePickerPopover`).
- **Pages:** Головна (tables by quarter, `HomeAside`), Інвойси (`InvoiceTable` by quarter), Облік (the summary's three
  cards, the switches on the filter row, `ReceiptTable`), Податки (the summary table, one-line ledger rows), Книги
  (the whole width, the cards in grids), the settings with their side notes, Рахунки in two columns, Перекази,
  Клієнти and Користувачі at 880 px, Вхід split.
- **Not done:** Інвойси's search «Номер або клієнт» (no search API, §15); the status filter as a checkbox list (it
  keeps its chips); «✎ Змінити» beside the name on Рахунок and Клієнт; Книги's tabs beside the year chip.
