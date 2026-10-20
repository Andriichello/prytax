# SUGGESTIONS.md — gaps, corrections, review items

Scope: reconciliation of the original brief (`ACCOUNTING.md` v1) with `fop-finance-app-design.md` v2.1, plus what both
missed. Priorities: **[v1-must]** correctness/legal, **[v1-should]** cheap now / expensive later, **[v2]** backlog.

---

## 1. Conflicts between the brief and the design — resolved

| #   | Brief said                                                                                           | Design says                                                                                          | Resolution in `ACCOUNTING.md` v2                                                                                                                                                                |
|-----|------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1.1 | "IMPORTANT: we pay by invoices, so invoice issue/sent date is used to split by month, quarter, year" | Taxable unit = receipt; period = quarter of `received_at`                                            | Design wins (п. 292.6 ПКУ). The habit survives as `tax_payments.allocation = payment`; period assignment never uses invoice dates. Cross-quarter example: invoice sent 28 Sep, paid 5 Oct → Q4. |
| 1.2 | Each invoice-payment record carries "amount on platform" + "amount on ФОП bank" + their dates        | Receipt on `payments`; own-account movements are `account_transfers` with M:N `transfer_allocations` | Design wins: partial and batched withdrawals, Payoneer → Wise → bank chains, money left on platform all become representable. Loss is per-leg fees, never `invoiced − landed`.                  |
| 1.3 | Vue.js frontend "with Blade templates"                                                               | Vue 3 SPA; Blade only for PDF/mail                                                                   | Design wins.                                                                                                                                                                                    |
| 1.4 | Statuses Draft / Ready / Sent / Paid                                                                 | + `cancelled`; `ready → draft` unfreeze                                                              | Design wins; `sent`/`paid` are terminal except `sent → cancelled`.                                                                                                                              |
| 1.5 | "Attach my PDF-invoice file" as an alternative to generation                                         | `invoices.source ∈ {generated, external}`, `invoice_files.is_primary`                                | Design wins; uploaded primary file suppresses rendering.                                                                                                                                        |
| 1.6 | Limit progress = "$X already received"                                                               | `limit_counts ∈ {receipts, all_inbound}`, calendar window                                            | Design wins; see §3.11 for rolling windows.                                                                                                                                                     |

---

## 2. Design elements that were absent from the brief (now in `ACCOUNTING.md` v2)

- Multi-profile tenancy (organization → profiles), accountant persona, roles `owner | accountant | viewer`, isolation
  rules (scoped bindings → 404, fail-closed global scope, Octane-safe context).
- Versioned `tax_rules` (rates, min wage, ЄСВ, income cap) and the income-cap tracker.
- Rate/tax snapshotting on payments; explicit rounding policy; `DECIMAL` + `brick/money`; no floats.
- Settings: `income.recognition`, `income.taxable_base`, numbering strategy/scope/reset/date source,
  `taxes.default_grouping`, `taxes.default_account_id`, `compliance.platform_repatriation`.
- Non-invoice taxable income (`payments.kind = other`).
- ЄСВ as a monthly obligation generated from rules, independent of invoices.
- Account kinds (`platform | fop_bank | foreign_bank`), derived balances, limit semantics.
- Transfers + allocations, FIFO proposal, per-leg fees, FX spread measured on one date, `still_on_platform`,
  `landed_on_fop_bank`; transfer loss vs net income as two separate metrics.
- Invoice snapshot with profile block; observer + policy lock; number allocation under row locks; yearly reset.
- Contract `description_template`, `default_hourly_rate`; projects/charge types as client children with text copies
  on items.
- `tax_periods` (declaration status + deadlines) and `DeadlineScheduler`; `tax_opening_balances`; running balance
  across quarters; outstanding/overpaid reconciliation; `paid_from_account_id`.
- Compliance alerts (platform repatriation, limits, cap, deadlines) — informational and dismissible.
- NBU rate provider with cache and manual override.
- One-off import of the 2025 sheet with total-matching validation.

---

## 3. Gaps missed by both documents

### 3.1 Passive income is not ЄП income **[v1-must]**

Design §2.9 gives "bank interest" as an example of `payments.kind = other`. Wrong: п. 292.1 ПКУ excludes passive income
(проценти, дивіденди, роялті, страхові виплати) from the single-tax base; the bank withholds ПДФО/ВЗ as tax agent.
Recording it as `other` would overstate income and ЄП/ВЗ.

- Change the example to platform bonuses/cashback (arguably taxable) and document that interest is not entered.
- If the user wants interest visible for cash-flow, add `payments.is_taxable BOOL DEFAULT 1` and exclude
  `is_taxable = 0` rows from every liability query; or keep it out of `payments` entirely (recommended for v1).

### 3.2 Refunds reduce income **[v1-must]**

п. 292.11 (5): money returned to a client reduces income in the period of the refund. Neither document has an entity.

- Add `refunds [P]`: `payment_id` (RESTRICT), `amount`, `currency`, `refunded_at`, `from_account_id`, `nbu_rate`
  (NBU on `refunded_at`), `uah_amount` (negative in reports), `period_*`, `reason`.
- Liability queries become `Σ payments.uah_amount − Σ refunds.uah_amount` per period; the accounting view shows the
  refund as a negative row on `refunded_at`, not as an edit of the original receipt.
- Invoice `paid_amount` subtracts refunds.

### 3.3 ЄСВ: exempt months and voluntary higher base **[v1-must]**

- 2022-03-01 … 2024-12-31 ЄСВ was voluntary for ФОП (Закон 2120-IX); mandatory again from 2025-01-01 (Закон 4015-IX),
  with territorial exemptions still in force. The 2025 import and any accountant client with history needs
  `esv_obligations [P] (year, month, amount_due, status ∈ {due, paid, exempt}, note)` instead of a purely computed
  obligation. Generate rows from `tax_rules`; let the user set `exempt`.
- Allow `tax_payments.amount > esv_monthly` for a voluntarily higher base (max 15 × min wage); do not flag it as
  overpayment — show "paid above minimum".
- `tax_rules.esv_mandatory BOOL` for the historical window.

### 3.4 One wire, many invoices; advances **[v1-should]**

Real case: a client pays invoices #11, #12 and #13 with one transfer. Design is 1:1 `payments.invoice_id`.

- Option A (minimal): split into N payment rows sharing `receipt_group_id` (UUID) and `received_amount` pro-rata; the
  receipt fee is split pro-rata. UI: "record one receipt for several invoices" creates the group.
- Option B (clean): `payment_invoices (payment_id, invoice_id, amount)` M:N; `payments.invoice_id` dropped. More
  queries change; allocations and tax payments stay per payment.
- Advances (money before an invoice exists): income on receipt date regardless. Add `kind = advance` with
  `invoice_id NULL`, linkable later; or treat as Option B with zero allocations until an invoice is issued.
  Recommendation: Option A now (schema-additive), Option B if accountants need it.

### 3.5 `personal` account kind and opening balances **[v1-should]**

Design allows taxes from a personal card (`paid_from_account_id` nullable) but has no account to hold it, and derived
balances start from zero with no outflows except taxes — they will never match a statement.

- Add `accounts.kind = personal` (non-business; excluded from limits, income and repatriation logic; valid
  transfer endpoint so "withdraw own funds" is a transfer `fop_bank → personal`).
- Add `account_opening_balances [P] (account_id, as_of, amount)`; balance = opening + flows since `as_of`.
- Add a generic `account_movements [P] (account_id, amount, direction, booked_at, category ∈ {bank_fee, expense,
  owner_withdrawal, other}, note)` so the balance can reconcile without modelling expenses as a feature. Label the
  balance "tracked" until this exists.

### 3.6 Period locking after submission **[v1-must]**

Nothing stops adding/editing a receipt dated inside a quarter whose declaration is `submitted/accepted`.

- `tax_periods.locked_at`; `PaymentRecorder`/`TransferRecorder`/`RefundRecorder` reject rows whose `received_at`
  falls into a locked period unless the request carries `correction_note`; on acceptance set
  `declaration_status = corrected` and raise a "уточнююча декларація required" alert.
- Cumulative declarations mean a Q1 change also changes Q2–Q4 YTD figures — the alert must name every affected
  quarter.

### 3.7 Deadline calendar details **[v1-must]**

- Non-business-day roll-over: declarations → next business day (п. 49.20); payments → next banking day (п. 57.1).
- During martial law public holidays are working days (Закон 2136-IX) — the holiday calendar is weekends-only until
  it ends; keep a `holidays` table so it can be filled later.
- ЄСВ "до 20 числа": ДПС reads the last day as the 19th. `tax_rules.esv_due_day` default 19.
- 2026 example to use in tests: 10 May 2026 is a Sunday → declaration due 11 May.

### 3.8 FX gain on currency sale **[v1-should]**

ДПС position (ЗІР, кат. 107.01.03): the positive difference between the UAH actually received when selling foreign
currency and the UAH value at the NBU rate on the income date is itself income of the single-tax payer. The design's
`fx_spread` already computes this quantity (negative spread = gain). Verify the current position at implementation
time, then:

- `profile_settings.income.fx_gain ∈ {ignore, include}` (default `ignore`, labelled).
- When `include`, a negative `fx_spread` on a `fop_bank` FX transfer creates a synthetic `payments` row
  (`kind = fx_gain`, `invoice_id NULL`, UAH) dated `transfer.received_at`.

### 3.9 Income cap crossing **[v1-should]**

Beyond the alert: compute `excess = max(0, YTD − cap)` and show `excess × 15 %` (п. 293.4) and the quarter from which
the general system applies (п. 293.8). Store `tax_rules.excess_rate`.

### 3.10 FX on receipt (platform → UAH card directly) **[v1-should]**

Payoneer can pay out straight to a UAH `fop_bank` account; then `received_currency ≠ currency` on the **receipt**, not
on a transfer. Design sets `receipt_fee = NULL` and loses the spread. Add `payments.receipt_rate` (effective
`received_amount / amount`) and compute `receipt_fx_spread = amount × nbu_rate − received_amount` (UAH), reported
alongside transfer spread.

### 3.11 Rolling limit windows **[v1-should]**

Platform limits are often rolling 12 months, not calendar years. `accounts.limit_window ∈ {calendar, rolling}`;
rolling = `[today − period, today]`.

### 3.12 ДПС payment requisites **[v1-should]**

Paying ЄП/ВЗ/ЄСВ needs the right budget IBAN (differs by region and tax), recipient, ЄДРПОУ and a purpose string.
`tax_payment_requisites [P] (tax_type, iban, recipient, edrpou, purpose_template, effective_from)`; the tax-payment
form shows a copy-ready block (and optionally a QR for the banking app). Requisites change — hence `effective_from`.

### 3.13 ІКП balance checks **[v2]**

`tax_balance_checks [P] (tax_type, as_of, dps_balance, app_balance, delta, note)` — manual entry of the balance from
the ДПС electronic cabinet against the app's running balance. Cheap, and it is what an accountant actually does.

### 3.14 Declaration helper / export **[v1-should] / [v2]**

v1: a "declaration figures" screen per quarter with the cumulative values in declaration order (income YTD, ЄП YTD,
ЄП previously declared, ЄП to declare now; same for ВЗ; ЄСВ months for the annual Додаток 1) and an "already
declared" lock that feeds §3.6. v2: XML in the current ДПС form schema for upload to the electronic cabinet (form
code changes with the form — resolve at implementation time).

### 3.15 Облік доходів export **[v1-should]**

п. 296.1 requires income records in free form with **monthly** totals. Provide a monthly income table per year and a
CSV/XLSX/PDF export that an inspector can read: date, document, UAH amount, currency amount, rate, monthly total,
cumulative total.

### 3.16 Invoice content for foreign clients **[v1-should]**

- `clients.country` as a column (not only inside `address` JSON) and `clients.vat_number`; a per-client/per-invoice
  footer note ("Services not subject to Ukrainian VAT; reverse charge applies" for EU B2B; "VAT not registered").
- Bilingual (EN/UK) template option; `clients.name_uk` / `legal_name_uk` for the Ukrainian version.
- Payment terms: `contracts.payment_terms_days` → `invoices.due_at` default.
- Contract rate history: `contract_rates (contract_id, effective_from, hourly_rate)` instead of one
  `default_hourly_rate`; `charge_types.default_rate` (Overhead vs Billable may differ).
- Optional Act of acceptance (Акт) rendered from the same snapshot **[v2]**.

### 3.17 Sending invoices **[v2]**

Mark `sent` by actually sending: Mailable with the primary file attached, `invoice_emails (invoice_id, to, cc,
subject, message_id, sent_at)`; `sent_at` set from the log. Reminders for overdue invoices.

### 3.18 Notifications **[v2]**

Email/Telegram N days before each deadline, at limit/cap thresholds, at quarter end for unrepatriated platform funds,
at contract expiry. Per-user notification preferences.

### 3.19 Exports **[v1-should]**

Accounting view, tax reconciliation and invoice list as CSV/XLSX (the accountant will want it), plus a yearly PDF
summary. Reuse the sheet's column layout for the first year to ease the transition.

---

## 4. Review of design v2.1 — schema and implementation

| #    | Item                                                          | Suggestion                                                                                                                                                                                                                                                                                                                                                                                                          |
|------|---------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 4.1  | `payments.period_year/quarter/month` denormalised in code     | MySQL 8 stored generated columns: `period_year SMALLINT AS (YEAR(received_at)) STORED`, `period_quarter TINYINT AS (QUARTER(received_at)) STORED`, `period_month TINYINT AS (MONTH(received_at)) STORED`; indexable, cannot drift. `tax_payments.period_*` stay plain columns — there the value depends on `allocation`, not on one date.                                                                           |
| 4.2  | `invoice_files.is_primary` "partial unique via service check" | Functional unique index: `UNIQUE KEY uq_primary ((CASE WHEN is_primary THEN invoice_id END))` (MySQL ≥ 8.0.13). Enforced at the DB, service check becomes a nicer error message.                                                                                                                                                                                                                                    |
| 4.3  | `invoice_sequences` via `lockForUpdate()->firstOrCreate()`    | First allocation for a new scope races: two concurrent `firstOrCreate` → one hits `UNIQUE(profile_id, scope_key)`. Use `INSERT … ON DUPLICATE KEY UPDATE next_number = LAST_INSERT_ID(next_number + 1)` then `LAST_INSERT_ID()`, or catch the unique violation and retry once inside the transaction.                                                                                                               |
| 4.4  | `tax_rules`                                                   | Add `effective_to DATE NULL` (ВЗ ends with martial law), `esv_mandatory BOOL`, `esv_due_day TINYINT`, `excess_rate DECIMAL(6,4)`. Seed via migration + `TaxRuleSeeder`; forbid editing rows that any payment references.                                                                                                                                                                                            |
| 4.5  | `invoices.subtotal` vs `total`                                | With no VAT/discount they are always equal. Either drop `subtotal` or add `discount_amount`/`adjustment_amount` so `total` has a reason to differ. Decide before the snapshot schema freezes.                                                                                                                                                                                                                       |
| 4.6  | `spatie/laravel-settings` "keyed by profile"                  | The package models settings as global classes; per-tenant keys need custom repository work. Own `profile_settings (profile_id, key, value JSON)` + a `ProfileSettings` DTO with typed getters/defaults + a `ProfileSettingsRepository` with per-request cache is less code and testable. Drop the dependency.                                                                                                       |
| 4.7  | `invoices.snapshot`                                           | Add `schema_version` inside the JSON and `template_version` on `invoice_files` so old invoices still render after template changes. Validate the snapshot against a JSON schema in tests.                                                                                                                                                                                                                           |
| 4.8  | `NbuRateProvider`                                             | NBU returns `"rate": 41.2345` as a JSON number; `json_decode` yields a float and `JSON_BIGINT_AS_STRING` does not cover floats. Extract from the raw body with `preg_match('/"rate":\s*([0-9.]+)/')` → `BigDecimal::of()`. Nightly job fetches rates for every currency used by active profiles so `received_at` lookups are cache hits; on API failure the form offers a manual rate (`source = manual`) and logs. |
| 4.9  | API money                                                     | `JsonResource`s emit amounts as strings (`"516.27"`), rates as 4-decimal strings; Form Requests accept strings and validate with a decimal rule; never cast to `float`.                                                                                                                                                                                                                                             |
| 4.10 | Encrypted PII                                                 | `tax_id`, `iban`, `account_number` with `encrypted` casts. Encrypted columns cannot carry `UNIQUE(organization_id, tax_id)` — add `tax_id_hash CHAR(64)` (HMAC) with the unique index.                                                                                                                                                                                                                              |
| 4.11 | `profiles.tax_id CHAR(10)`                                    | Individuals without РНОКПП use passport series/number; `VARCHAR(20)` + a flag. Rare; cheap to allow now.                                                                                                                                                                                                                                                                                                            |
| 4.12 | `payments.received_to_account_id` currency                    | Validate `received_currency = account.currency` in the Form Request (the column is redundant with the account; keep it for the snapshot, assert equality).                                                                                                                                                                                                                                                          |
| 4.13 | Invoice updates                                               | Carry `If-Unmodified-Since`/`updated_at` precondition → 409 on stale write (two accountants). `POST payments` with `Idempotency-Key` header stored for 24 h (double-click = one receipt).                                                                                                                                                                                                                           |
| 4.14 | Deletion policy                                               | Payments/transfers/tax payments: hard delete only when nothing references them (FKs RESTRICT) and the period is not locked; otherwise require a reversing row. `spatie/laravel-activitylog` on invoices, payments, transfers, tax payments, settings. Add `updated_by_user_id`.                                                                                                                                     |
| 4.15 | spatie roles per organization                                 | With three fixed roles (`owner`, `accountant`, `viewer`) global role rows are fine — assignment is per user anyway. Enable the package's `teams` feature (`team_id = organization_id`, context set in middleware) only if organizations ever get custom roles or the v2 `profile_user` ACL is built on permissions.                                                                                                 |
| 4.16 | PDF on freeze                                                 | Keep synchronous (dompdf, one page); wrap freeze in a transaction with the file write **after** commit (`DB::afterCommit`) so a failed render does not leave a `ready` invoice without a file; email sending is queued.                                                                                                                                                                                             |
| 4.17 | `tax_payments` CHECKs                                         | Add `CHECK (allocation <> 'quarter' OR period_month IS NULL)` and `CHECK (amount > 0)`; `CHECK (status <> 'paid' OR paid_at IS NOT NULL)`.                                                                                                                                                                                                                                                                          |
| 4.18 | Indexes                                                       | `invoices (profile_id, client_id, status)`, `invoices (profile_id, issued_at)`, `transfer_allocations (payment_id)`, `refunds (profile_id, period_year, period_quarter)`, `esv_obligations (profile_id, year, month) UNIQUE`.                                                                                                                                                                                       |
| 4.19 | `accounts` soft delete                                        | Not needed — `is_active` + RESTRICT FKs. Same for `clients`, `contracts`. Keep soft deletes only on `profiles` and `invoices`.                                                                                                                                                                                                                                                                                      |
| 4.20 | `payments` dates vs platform timezones                        | Statement dates in UTC can differ from Kyiv by a day around midnight; the user enters the date as shown on the statement — document it, and show the NBU rate date explicitly next to the rate so a mismatch is visible.                                                                                                                                                                                            |
| 4.21 | `AllocationProposer` FIFO                                     | Also propose by exact amount match before FIFO (one transfer = one receipt is the common case); expose both proposals.                                                                                                                                                                                                                                                                                              |
| 4.22 | Tests                                                         | Add property-based tests for the rounding identity `                                                                                                                                                                                                                                                                                                                                                                |Σ per-payment tax − period tax| < 0.01 × n` and for allocation bound invariants; golden-file PDF tests per `schema_version`. |

---

## 5. UX notes

- Onboarding wizard: profile → first `fop_bank` UAH account → client → contract → first invoice; nothing else is
  reachable until a profile exists.
- Receipt form in one screen: amount invoiced, amount landed, account, date, client fee; show the resolved NBU rate
  and UAH/ЄП/ВЗ live before saving.
- Transfer form: pick source account → list of unallocated receipts with checkboxes and editable amounts (FIFO
  pre-ticked) → fee → landed amount; show computed spread for FX legs.
- Quarter page = accounting rows + footer + reconciliation + deadlines on one screen; the accountant board links to it
  per profile.
- Every alert has a "why" tooltip with the legal reference from `ACCOUNTING.md §3`.

---

## 6. v2 backlog

- `profile_user` pivot (per-profile ACL; ФОП owner as `viewer` of his own profile in the accountant's organization).
- Bank/platform imports: Monobank API, PrivatBank Autoclient CSV, Payoneer/Wise statement CSV → auto-matched
  receipts and transfers (by amount, date, reference).
- Recurring invoices (monthly retainers) and time-tracking import (Toggl/Clockify CSV) into line items.
- Client portal / signed share link for the invoice PDF.
- Expenses and owner withdrawals as first-class features (P&L, not tax).
- Other tax schemes: 3 група 3 % + ПДВ, 2 група fixed ЄП (`profiles.tax_scheme`).
- ДПС XML export of the declaration; ІКП balance checks (§3.13).
- Acts of acceptance; bilingual invoices.
- Notifications (§3.18); invoice emailing (§3.17).
- Multi-organization users (would need an api-core change: `organization_user` pivot instead of
  `users.organization_id`).

---

## 7. Second-review findings — applied to ACCOUNTING.md and design spec v3

All four are confirmed defects. The functional text is corrected and the schema/service deltas below are in
`fop-finance-app-design.md` v3 (§§4–6, §8.1). Everything in §3 tagged v1-must / v1-should and the whole of §4 is also
in v3; §6 items stay deferred.

| #   | Finding                                                                                                                                                                                                          | Verdict                                                                  | Fix in ACCOUNTING.md                                                                                                                                                                                                                                                                                              | Delta for the design spec                                                                                                                                                                                                                                                                                                                                                                                                                |
|-----|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 7.1 | Multi-hop allocations bounded by Σ inbound `allocation.amount` ignore the fee and the currency of the hop ($1 000 out of Payoneer lands $985 on Wise; USD → EUR hop)                                             | Correct — the v2.1 wording "receipt or allocated-in" was under-specified | Holding of payment P on account X defined in X's currency: receipt on X + Σ inbound `alloc.amount / T.amount_sent × T.amount_received` − Σ outbound `alloc.amount`; proposer and recorder both use it                                                                                                             | `AllocationProposer`/`TransferRecorder`: replace "allocated-in" with the pro-rata formula; add a Pest case for a fee hop and for a cross-currency hop; `landed_on_fop_bank` already uses pro-rata `amount_received`, keep it                                                                                                                                                                                                             |
| 7.2 | Standalone `ROUND(Σ_q × rate)` vs incremental `ROUND(YTD_q × rate) − ROUND(YTD_q−1 × rate)` can differ by 0.01; v2.1 called the standalone figure "authoritative"                                                | Correct — the declaration and the ІКП carry the incremental figure       | Quarter obligation = YTD delta (Q1 = its own YTD); standalone quarter and per-payment sums shown as cross-checks with their kopeck deltas                                                                                                                                                                         | `TaxLiabilityCalculator::forQuarter()` returns `{ytd, previous_ytd, owed = ytd − previous_ytd, standalone, per_payment_sum}`; `TaxReconciler` consumes `owed`; the "declaration figures" screen (§3.14) reads the same DTO                                                                                                                                                                                                               |
| 7.3 | `fop_bank_receipt` derives `received_at` from a later transfer, but the snapshot is stored at payment creation; partial withdrawals across quarters would silently split one receipt into several taxable events | Correct, and it exposed a contradiction already in v2.1                  | Under `fop_bank_receipt`, `received_at` is a manual "bank arrival date", nullable; unrecognised payments carry no snapshot and are excluded from liabilities and flagged; the landing allocation proposes the date, the user confirms; tranches across quarters = the user splits the receipt into N payment rows | `payments.received_at DATE NULL`, `nbu_rate`/`uah_amount`/`single_tax_uah`/`military_levy_uah`/`tax_rule_id`/`period_*` all NULL until recognised (generated period columns handle NULL); `payments.recognition_status ∈ {recognised, pending}`; `PaymentRecorder::recognise(Payment, date)`; a "split receipt" action creating sibling rows that share `receipt_group_id` (see §3.4); dashboard alert "N payments awaiting recognition" |
| 7.4 | After `ready → draft` with `date_sequence`, editing `period_end`/`issued_at` leaves a stale date prefix in the kept number                                                                                       | Correct                                                                  | On re-freeze a generator-allocated number is rebuilt from the current date source with the same `{n}`; a new `{n}` only when the scope key changed (yearly reset, year moved); user-typed numbers untouched                                                                                                       | `invoices.sequence_scope_key VARCHAR NULL`, `invoices.sequence_number INT NULL` (both NULL for user-supplied numbers); `InvoiceNumberGenerator::rebuild(Invoice)`; Pest cases: prefix rebuild, year rollover under `reset = yearly`, user number preserved                                                                                                                                                                               |

---
