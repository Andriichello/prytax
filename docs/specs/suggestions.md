# Open decisions, hidden features and backlog

What is still to decide or to build, checked against the code on 2026-10-30. What was decided is in `design.md`
(v5.2), `accounting.md`, `invoice.md`, `backups.md` and `builds.md`, and the earlier review rounds that led there are
in the git history of this file.

---

## 1. Deferred and pending decisions

The «Minimal» handoff's questions were settled by the owner on 2026-10-25, and those of the handoffs of 2026-10-28 on
that day; the answers are written into `design.md`, `backups.md` and `accounting.md`. These are left for later:

- **Kopeck remainders of ЄП/ВЗ** (deferred on 2026-10-25). ЄП and ВЗ are paid only per receipt, at each receipt's
  rounded amount, while the quarter owes the year-to-date figure. The two can differ by a kopeck or so (the demo's
  III кв.: Δ −0.01). An underpaid kopeck can't be paid without a free amount, and it raises the tax-debt notification.
  One way: «Доплатити 0.01» on the quarter's row, a payment with `allocation = quarter` for exactly the remainder,
  computed and not typed. An overpaid kopeck carries forward as now. Податки's «Нараховано» sums the receipts'
  amounts (`design.md` §8.5), so it can differ from Облік's quarter figure by the same kopeck.
- **A — the period of a refund.** Today a refund reduces income in its own period (п. 292.11, `design.md` §6.6). The
  alternative is to reduce the **original** receipt's period at the original rate, with a reason enum. The refund UI
  is hidden in phase 1, so this can wait.
- **B2 — fee currency and net income.** `fee_currency` already follows `currency_sent` (`TransferRecorder` sets it
  from the source account). Left: UAH as the canonical currency of net income.
- **B5 — a receipt's date moving to another quarter.** Cascade `tax_payments.period_*` of its tax payments, and the
  dates of a virtual invoice. It matters more now that every ЄП/ВЗ payment hangs on a receipt and copies its period
  (`design.md` §8.4).
- **Golden PDF files** per schema version, money format and language (`invoice.md` §9).
- **Restoring into the same organization** (`backups.md` §5.3). The archive of a ФОП the organization still has, a
  deleted one in its 30 days' grace included, can't be imported beside it while `UNIQUE(organization_id,
  tax_id_hash)` stands: purge the original first, or allow one *active* profile per РНОКПП. Restoring the desktop app
  from an in-app archive waits for it too (`builds.md` §4.6).
- **The GCS adapter** for production's backups disk (`backups.md` §3, §7): a new package, so approval first. Until
  then every installation keeps its archives on a local disk.
- **Інвойси's search.** The desktop board draws «Номер або клієнт» on Інвойси (`design.md` §17.4), while
  `accounting.md` keeps the list without a search box, narrowed by filters. There is no search API, and it isn't built
  (`design.md` §17.6).

## 2. Hidden in phase 1, and the options v4 removed

**Hidden:** the backend, the data and the API stay, and only the UI's routes, navigation and links go. To restore one,
bring back its screen.
- «Огляд усіх ФОП» (the organization board) and Огляд практики (its card lives on Облік). Switching between the
  organization's ФОП is back since v5, as «ФОП організації» (`design.md` §16.1).
- Облік's ЄСВ tab (`esv/{year}`) and the declaration screen with its status workflow (`declarations/{year}/{quarter}`,
  `tax-periods`), and with it the period lock and corrections. Облік's Книга доходів tab (`income-book/{year}`) is
  replaced by Книги обліку's Доходи (`books/{year}/income`, `design.md` §16.7).
- The CSV of the year (`accounting/{year}/export`) and of the income book (`income-book/{year}/export`): Облік exports
  its quarter's CSV and its period archive, Книги обліку their PDFs.
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

- **XLSX exports** of Облік's quarter and of both books (`design.md` §7, §16.7; `openspout/openspout` is approved):
  blocked. Composer can't install the package on the development machine (it fails to authenticate against GitHub),
  so the owner is to run `composer require openspout/openspout`; and the desktop app's bundled PHP has no xmlwriter or
  xmlreader, which openspout may need, so it must be checked there before it ships (`builds.md` §4.1). Облік's export
  hint on a phone already names XLSX («CSV і XLSX — таблиця надходжень…»).
- **Backups' in-app notices** in Сповіщення, through api-core's per-user `notifications` (`backups.md` §4.8): a run
  with a failed ФОП, the organization's archive ready, a period's archive ready, a deleted ФОП's final copy. Meanwhile
  the organization archive's banner polls on its page, the period archive's sheet waits for its export and downloads
  it, and owners get e-mails by the policy («Лист власникам»), which the desktop app writes to its log. The texts of
  both archives already promise a notification («Готуємо у фоні: архів з’явиться в сповіщеннях…»).
- **The desktop record panel** (`design.md` §17.3, §17.6): its header is built (the kind, the title, the sub-line, the
  badges, «✎ Змінити», ⋯ and ✕). Left: ⋯ opens its menu as a centred dialog, not a popover; a tax payment opens its
  form rather than the view panel of board 037; Інвойси's status filter keeps its chips rather than a checkbox list;
  Книги's tabs aren't beside the year chip.
- **Deleting a ФОП** has no screen. The API's `DELETE profiles/{id}` (owners) takes the final copy and starts the 30
  days' grace, and Резервні копії lists the ФОП under «Видалені ФОП» with «Відновити ФОП»; closing a ФОП is Профіль
  ФОП's «Активний ФОП» switch.
- **The yearly PDF summary** (`design.md` §7, §14): not built, and not specified beyond its name. dompdf, which renders
  the invoices and Книги обліку's PDFs, is installed, so it no longer waits on a package.
- **`SheetImporter`** for the 2025 Google Sheet (`design.md` §14): waiting on the sheet's format.
- **Backups:** what `backups.md` §7 lists as not built — Layer A (infrastructure; nothing is deployed), the GCS adapter
  (§1), the final organization bundle and its e-mail, an organization delete endpoint — and the tests it lists that
  aren't written.
- **Builds:** Docker, SQLite and the desktop app have their open list in `builds.md` §5. Also: the desktop app writes
  its mail to its log (`builds.md` §4.2), so its invitations, «Забули пароль?» and the backups' e-mails reach nobody.
- **Open points of the «Minimal» QA** (2026-10-25), unchanged:
  - «Прив’язати до виставленого інвойсу» offers the client's paid invoices with nothing left to pay too, and
    attaching to one overpays it.
  - Облік's summary of a quarter before tracking starts (the opening balances) shows ЄСВ 0.00 owed beside what was
    paid.
  - A field's API error stays until the next save (app-wide); the ЄСВ month stepper doesn't update the URL; «Без
    інвойсу» appears in Інвойси's status filter only while such a record exists (facets).
  - The kopeck between a quarter's ВЗ and its sum per receipt is §1's first item.
- **Found while the specs were brought up to date** (2026-10-30), to fix or to decide:
  - Restoring in the app: its refusals with values in them stay in English (the 32 MB upload limit is gone since
    `backups.md` v1.10: the archive goes up in pieces); a deleted ФОП's refusal says to purge it first, which an owner can't
    do before its grace ends (`backups.md` §5.1, §5.3).
  - Ще's «Вийти» on a phone signs out at once, while the sidebar's and the rail's ask first.
  - Texts: Облік's export hint names XLSX (above); Рахунки's side note says withdrawals aren't tracked, while an
    account's «Рух коштів» records them, as Довідка says.
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
- Free-text search on lists (Інвойси's, §1); passkeys and 2FA; the organization board. Registering an organization is
  built, behind `PRYTAX_REGISTRATION` and without e-mail verification (`builds.md` §1.3), and so is the desktop layout
  (`design.md` §17).
- **Mobile:** iOS and Android through Capacitor around the same SPA (tokens in secure storage, downloads through the
  Filesystem/Share plugins).

## 5. UX notes

- An onboarding path: profile → the first `fop_bank` UAH account → a client → a contract → the first invoice. A new
  ФОП lands on Податки й сповіщення (opening balances) today, and Довідка's «Перші кроки» lists the steps with links
  (Профіль ФОП, Рахунки, Податки й сповіщення, Клієнти й договори, Документ інвойсу, Перший інвойс), but nothing leads
  a new ФОП through them.
- Every notification could carry a "why" with its legal reference (`design.md` §1).
