# Open decisions, hidden features and backlog

What is still to decide or to build. What was decided is in `design.md` (v4) and `accounting.md`, and the earlier
review rounds that led there are in the git history of this file.

---

## 1. Deferred and pending decisions

The «Minimal» handoff's questions were settled by the owner on 2026-10-25 and are written into `design.md` and
`accounting.md`. These are left for later:

- **Kopeck remainders of ЄП/ВЗ** (deferred on 2026-10-25). ЄП and ВЗ are paid only per receipt, at each receipt's
  rounded amount, while the quarter owes the year-to-date figure. The two can differ by a kopeck or so (the demo's
  III кв.: Δ −0.01). An underpaid kopeck can't be paid without a free amount, and it raises the tax-debt notification.
  One way: «Доплатити 0.01» on the quarter's row, a payment with `allocation = quarter` for exactly the remainder,
  computed and not typed. An overpaid kopeck carries forward as now. Податки's «Нараховано» sums the receipts'
  amounts (`design.md` §8.5), so it can differ from Облік's quarter figure by the same kopeck.
- **A — the period of a refund.** Today a refund reduces income in its own period (п. 292.11, `design.md` §6.6). The
  alternative is to reduce the **original** receipt's period at the original rate, with a reason enum. The refund UI
  is hidden in phase 1, so this can wait.
- **B2 — fee currency and net income.** Set `fee_currency = currency_sent` on transfers, and make UAH the canonical
  currency of net income.
- **B5 — a receipt's date moving to another quarter.** Cascade `tax_payments.period_*` of its tax payments, and the
  dates of a virtual invoice. It matters more now that every ЄП/ВЗ payment hangs on a receipt and copies its period
  (`design.md` §8.4).
- **Golden PDF files** per schema version, money format and language (`invoice.md` §9).

## 2. Hidden in phase 1, and the options v4 removed

**Hidden:** the backend, the data and the API stay, and only the UI's routes, navigation and links go. To restore one,
bring back its screen.
- The profile switcher, «Огляд усіх ФОП» (the organization board) and Огляд практики (its card lives on Облік).
- Облік's ЄСВ tab (`esv/{year}`), Книга доходів (`income-book/{year}`) and the declaration screen with its status
  workflow (`declarations/{year}/{quarter}`, `tax-periods`), and with it the period lock and corrections.
- Податки by month and by quarter (`taxes/{year}/months`, `taxes/{year}?…`) and the tax-period calendar button.
- The refund action (`payments/{payment}/refunds`). The owner is fine without refunds (2026-10-25): cancelling an
  invoice asks only to move its receipts to the invoice that replaces it.
- Податки's two modes from the first «Minimal» handoff, which the ledger replaces (`design.md` §8.5). The receipts
  with their taxes are on Облік.
  - «Нарахування»: the quarter's card (Нараховано · Сплачено · До сплати, from `taxes/{year}` and `TaxReconciler`) and
    the receipts by month (`taxes/{year}/{quarter}/payments`).
  - «Історія сплат», with its «+ ЄСВ» and «Сплатити ЄСВ за {місяць}».
- The pay row «Сплатити {ЄП і ВЗ | ЄП | ВЗ} за цим надходженням» of the receipt cards. The owner removed it on
  2026-10-25, and the second handoff confirmed it: the ЄП and ВЗ rows are the only entry point. To restore it, bring
  back `ReceiptTaxCard`'s `payRow` (git history).

**Removed:** dropped from the schema or the API. To restore one, re-add it.
- Receipts of kind `advance` and `other` (virtual invoices replace them).
- ЄП/ВЗ payments as a free amount, by month or by quarter (`allocation = month | quarter` for those taxes).
- `accounts.limit_window` (rolling windows: `[today − period, today]`) and `accounts.limit_counts = all_inbound` (own
  transfers counted, for bank inbound limits).
- `income.taxable_base = received` (the received amount as the tax base, at the user's own risk).
- `taxes.default_grouping`.
- Marking an ЄСВ month exempt by hand (`PATCH esv-obligations/{id}` and its switch); the `exempt` status stays for the
  voluntary window of 2022–2024.

## 3. Not built yet (phase-1 scope)

- XLSX exports and the yearly PDF summary: they need a package, so approval first.
- `SheetImporter` for the 2025 Google Sheet (`design.md` §14): waiting on the sheet's format.
- Known gaps of the build:
  - a refund saved at a manual rate can't be refreshed (delete it and record it again);
  - a re-rendered PDF reads the signature by its live file id;
  - `tax_id_hash` is an HMAC keyed by `APP_KEY`, so rotating the key breaks tax-id uniqueness;
  - enum columns are `VARCHAR`s without CHECK constraints, and `TaxRule`'s immutability rests on model events;
  - personal data can reach `laravel.log` through a failed query's bindings;
  - `AlertSync` runs a full collection after every write (to be debounced or queued later).

## 4. Backlog (later phases)

- `profile_user` pivot (per-profile access; a ФОП owner as `viewer` of their own profile in the accountant's
  organization); multi-organization users (needs an api-core `organization_user` pivot).
- Bank and platform imports (Monobank API, PrivatBank Autoclient CSV, Payoneer/Wise statements), auto-matched to
  receipts and transfers by amount, date and reference.
- Recurring invoices ("Повторити інвойс", monthly retainers) and time-tracking import (Toggl/Clockify CSV).
- Sending invoices from the app (`invoice_emails`, `sent_at` from the log) and overdue reminders; a client portal or
  signed share links.
- Notifications by e-mail, Telegram or push, with per-user preferences.
- Expenses and owner withdrawals as features (P&L, not tax), beyond `account_movements`.
- Other tax schemes (3 група 3 % + ПДВ, 2 група; `profiles.tax_scheme`).
- The declaration as ДПС XML; ІКП balance checks (`tax_balance_checks`: the cabinet's balance against the app's).
- Separate signed acts of acceptance; the bilingual invoice (`en_uk`) and custom texts per language.
- Income recognition on arrival at the ФОП account (`design.md` §15).
- Free-text search on lists; passkeys, 2FA, self-signup; the organization board; a desktop layout.
- **Mobile:** iOS and Android through Capacitor around the same SPA (tokens in secure storage, downloads through the
  Filesystem/Share plugins).

## 5. UX notes

- An onboarding path: profile → the first `fop_bank` UAH account → a client → a contract → the first invoice. A new
  ФОП lands on the tax settings (opening balances) today.
- Every notification could carry a "why" with its legal reference (`design.md` §1).
