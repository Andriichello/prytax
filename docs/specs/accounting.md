I am building an accounting and invoicing application for Ukrainian "ФОП 3 група на спрощеній системі без найманих
працівників" — software developers, designers and other sole entrepreneurs who work under service agreements, issue
invoices to (mostly foreign) clients and receive money through Payoneer, Wise, Mercury or direct bank wires. I am one
of them myself and the app replaces the Google Sheet I keep today, but it also has to work for an accountant who runs
the books of several such ФОПs under one login. The stack is Laravel 12/13 on MySQL 8 with a Vue 3 SPA (TypeScript,
Tailwind) consuming a JSON API; Blade is used only for PDF and e-mail templates. It is built on my own `api-core`
package, which already provides organizations, users, Sanctum auth, spatie roles/permissions and the HTTP filtering,
resources and pagination layer, so none of that is designed here. Money is handled with `brick/money` and `brick/math`
and there are no floats anywhere in the domain. The companion `fop-finance-app-design.md` (v3.3) holds the schema,
services and build order for everything described below, and `SUGGESTIONS.md` holds the open decisions.

An organization from api-core is whoever runs the app: me, or an accountant's practice. Inside it there are one or
more profiles, and a profile is one ФОП — the tax identity, its settings and every domain record. `profile_id` is the
tenant key on all domain tables (accounts, clients, contracts, invoices, payments, transfers, tax periods, tax
payments); `organization_id` is not duplicated on them. A user belongs to exactly one organization and never owns
domain data: `user_id` appears only as `created_by_user_id` for audit. Access is organization membership plus a role —
`owner` (everything, including profiles and users), `accountant` (all financial operations on every profile) or
`viewer` (read-only). In v1 every user of an organization sees every profile of it; per-profile access through a
`profile_user` pivot is v2. Every domain route is nested under `/api/profiles/{profile}/…` with scoped route-model
binding, so an id that belongs to another profile is a 404 before any controller code runs; the resolved profile is
kept in a request-scoped (Octane-safe) `CurrentProfile` context, every tenant model has a global scope on it that fails
closed (`WHERE 1 = 0`) when no profile is set, console commands and jobs set the context explicitly, and the
consistency MySQL cannot check (a payment's invoice and account belong to the same profile, a transfer's two accounts
belong to the same profile, a tax payment's payment belongs to the same profile) is asserted in the recording services
and covered by tests. One naming rule: the accountant's clients are always called profiles; the word "client" is
reserved for my invoice counterparties.

The system has to encode the rules of 3 група, and all the numbers live in a versioned `tax_rules` table (one row per
`effective_from`; the resolver takes the latest row effective on the date in question), never in code. Єдиний податок
(ЄП) is 5 % of income (п. 293.3 ПКУ). Військовий збір (ВЗ) is 1 % of income from 2025-01-01 until the end of the year
in which martial law ends (Закон 4015-IX). ЄСВ is a fixed monthly amount of 22 % of the minimum wage — 1 760.00 in
2025 and 1 902.34 in 2026 (min wage 8 647) — mandatory again from 2025-01-01 after the voluntary martial-law window of
2022-03-01 … 2024-12-31 (Закон 2120-IX, 4015-IX), which historical data must be able to mark as exempt. The yearly
income cap is 1 167 minimum wages — 9 336 000 in 2025, 10 091 049 in 2026 (п. 291.4) — and crossing it means 15 % on
the excess and a forced move to the general system from the next quarter (п. 293.4, 293.8). Income is recognised on
the date the money is received (п. 292.6), and for platforms the ДПС position is the date of crediting on the platform,
not the later date on the ФОП bank account. Foreign-currency income is converted at the NBU official rate of the
receipt date (п. 292.5), 4 decimals, and the UAH amount is rounded to kopecks. A commission withheld by the bank or
the platform on the way in is still my income, so income equals the full invoiced amount (ІПК 462/ІПК/99-00-24-03-03-06
від 01.03.2023); a fee the client pays on top is not income. Passive income — bank interest, dividends, royalties — is
not ЄП income at all (п. 292.1) and is never entered as income; a refund to a client reduces income in the period of
the refund (п. 292.11). Money sitting on a platform should reach a Ukrainian IBAN by the end of the reporting quarter,
otherwise ДПС may treat it as personal income (18 % ПДФО + 5 % ВЗ) — positions on this vary, so it is an alert, never
a block. The declaration is quarterly and cumulative (наростаючим підсумком), due 40 calendar days after the quarter
(п. 49.18.2, 296.3), the Q4 one being the annual declaration with Додаток 1 for ЄСВ; ЄП and ВЗ are due 10 calendar days
after the declaration deadline (п. 295.3); ЄСВ is due "до 20 числа" of the month after the quarter, which ДПС reads as
the 19th, so the app defaults to the 19th and keeps it configurable. That gives 10 May / 20 May / 19 Apr for Q1,
9 Aug / 19 Aug / 19 Jul for Q2, 9 Nov / 19 Nov / 19 Oct for Q3 and 9 Feb / 19 Feb / 19 Jan for Q4. A declaration
deadline that falls on a weekend rolls to the next business day (п. 49.20) — 10 May 2026 is a Sunday, so that
declaration is due 11 May — while the ЄП/ВЗ deadline is counted from the statutory date and stays 20 May; a payment
deadline on a weekend rolls to the next banking day (п. 57.1); and during martial law public holidays are working days
(Закон 2136-IX), so the calendar is weekends-only until that changes. `profiles.tax_group` exists with the single
value `3` so that other groups can be added later without touching the domain tables.

Invoices and payments can be in USD, EUR, UAH or any other ISO currency. Amounts are `DECIMAL(15,2)`, rates
`DECIMAL(12,4)`, and the API serialises both as strings (`"516.27"`, `"42.2297"`), never as JSON numbers. The rounding
policy is fixed: per payment `uah_amount = ROUND_HALF_UP(amount × nbu_rate, 2)`; period income is the sum of those
already-rounded rows (the order a Книга обліку uses); per-payment ЄП and ВЗ are `ROUND_HALF_UP(uah_amount × rate, 2)`
and serve only as allocation hints; the year-to-date liability — the declaration figure — is
`ROUND_HALF_UP(Σ uah_amount from 1 January to quarter end × rate, 2)`, and the amount owed for a quarter is the
difference between that and the previous quarter's year-to-date liability, because that difference is what the
declaration reports for the quarter and what ДПС posts to the integrated card. The standalone
`ROUND_HALF_UP(Σ uah_amount of the quarter × rate, 2)` and the sum of the per-payment hints are both shown next to it
as cross-checks; either can differ from the owed amount by a kopeck, and the UI shows those deltas rather than hiding
them. Invoice line amounts are `ROUND_HALF_UP(quantity × unit_price, 2)` and the invoice total is their sum. Everything that depends on a rate or a rule is snapshotted on the row at creation (`nbu_rate`, `nbu_rate_date`,
`exchange_rate_id`, `uah_amount`, `tax_rule_id`, `single_tax_uah`, `military_levy_uah`, `period_year`,
`period_quarter`, `period_month`) and is recomputed only when the amount, currency or receipt date of that row is
edited; the `exchange_rates` table is a cache of NBU data plus manual overrides, not the truth for historical rows, and
a new `tax_rules` row never changes existing payments.

I fill in my tax data once per ФОП as the profile: full name in Ukrainian and in Latin transliteration (invoices to
foreign clients are in English), РНОКПП (`tax_id`, stored encrypted, unique within the organization), the date of state
registration, address in both languages, e-mail, phone, default currency, locale, an optional signature image embedded
in generated PDFs, and an `is_active` flag so a closed ФОП drops out of dashboards and deadline generation. The profile
also owns its settings (`profile_settings`, key → JSON, read through a typed accessor with defaults so a missing row
never breaks anything): the invoice numbering strategy, scope, reset and date source described below; how money
amounts are displayed, as two separate settings — `display.money_format` for the application and
`invoice.money_format` for the generated invoices — each choosing the decimal separator ("." or ",") and the
thousands grouping (none, space, comma or dot), so `2851.99`, `2 851.99`, `2,851.99`, `2851,99`, `2 851,99` or
`2.851,99`, with the application defaulting to `1 234.99` and invoices to plain `2851.99` (formatting is display
only: the API always carries `1234.99`, inputs accept either separator, and a frozen invoice remembers the format it
was rendered with); `income.taxable_base` (`invoiced` by default, or `received` labelled as the user's own risk);
`taxes.default_grouping` (`payment`, `month` or `quarter`); `taxes.default_account_id` (the UAH ФОП account taxes are
usually paid from — must be `fop_bank`, UAH and active); `compliance.platform_repatriation` (`quarter_end`, `days:N`
or `off`); `alerts.deadline_days` (7); and the ФОП-wide invoice defaults that clients and invoices can override
(described with the clients below) — `invoice.locale` (`en` or `uk`, `en` by default), `invoice.payment_purpose_template`,
`invoice.act_note_enabled` (on), `invoice.act_note` and `invoice.show_contract` (on), with the profile's
`default_currency` as the ФОП-wide currency. There is deliberately no income-recognition option in this phase (see the
payments section below).

Then I create the places money can land — Payoneer, Wise, Mercury, my Ukrainian ФОП accounts, a European bank
account — as rows of one `accounts` table with `kind` `platform`, `fop_bank` or `foreign_bank`, one row per currency
(Wise USD and Wise EUR are two accounts; only `fop_bank` counts as "landed in Ukraine"). Each carries what a client
needs to pay me (holder, bank, IBAN, SWIFT, account number, and a JSON `details` block for correspondent banks,
routing numbers or Payoneer receiving details — IBAN and account number stored encrypted) and all of it is printed into
the invoice. An account may have a limit: `limit_amount`, `limit_period` (`month`, `quarter` or `year`, calendar
window) and `limit_counts` (`receipts` — client money only, which is what Payoneer's $10 000 per year is about — or
`all_inbound`, which also counts my own transfers in, for bank inbound limits). The accounts list shows `$X / $10 000`
with a percentage where a limit exists and just `$X` where it does not, with alerts at 80 % and 100 %. An account is
at the same time a receipt destination, a transfer endpoint and a tax-payment source, so it also has a derived balance
(receipts in + transfers in − transfers out − tax payments out) which is labelled a tracked-flows balance, because
expenses and personal withdrawals are out of scope in v1 and it will not match the bank statement. Inactive accounts
cannot be chosen for new invoices or receipts but keep their history; nothing that was ever referenced can be deleted,
every FK to an account is `RESTRICT`.

Clients are my invoice counterparties: `name`, `legal_name`, address as JSON (lines, city, zip, country), their tax
id, e-mail and notes; they are deactivated, never deleted. Each client also has an `abbreviation` — one to ten
upper-case Latin letters and digits, such as `BL` for Brightline Labs — which prefixes its invoice numbers. It is
required, unique among the clients of the profile (inactive ones included, so two clients never share a prefix) and
suggested from the initials of the name when I create the client; I can change it later, and the numbers already given
keep the prefix they were given with. Under a client I keep its service agreements as contracts:
a name, an optional number, an optional `subject` (what the agreement is for, such as "IT services", used in the
payment purpose below), a required start date, an optional end date, the contract currency, an optional default
hourly rate and a `description_template` such as "IT services in accordance to the Service Agreement {name} dated
{started_at:F j, Y}", which is expanded into the line-item description when an item is created (the item keeps the
expanded text). Also under a client live two small lookups the client uses for its own bookkeeping: projects with an
inner code like "PROTO" and charge types like "Billable" or "Overhead". Invoice items reference both by id and copy
`project_code` and `charge_type` as text, so a locked invoice is unaffected by later renames.

Each client also carries the defaults for the invoices I issue to it, and every invoice can override them, so the
usual case costs nothing and the exception is one change on the invoice. Five settings work this way, and each is
resolved from the most specific level that has a value: the invoice, then the client, then the ФОП-wide default in
the profile settings, then the built-in default. An empty value means "inherit from the level above", so clearing an
override on an invoice or a client returns it to inheriting. The invoice form shows each setting with where its
value comes from ("як у клієнта", "як у ФОП"), and the client form shows the same for its own level. Changing a
client's defaults affects new invoices and the drafts that inherit them; it never changes a frozen invoice, because
the freeze writes the resolved values into the snapshot, and an unfrozen invoice resolves them again on its next
freeze.

The currency is the client's `currency` (`USD` for a US client), the currency its contracts and invoices start with.
A contract keeps its own currency, which is more specific than the client's, so a new invoice takes the contract's
currency when a contract is picked, otherwise the client's, otherwise the profile's `default_currency`. Unlike the
other four settings, the currency is not inherited live: the invoice stores a concrete currency when it is created,
because its prices, totals, receiving account and later receipts all depend on it. It can still be changed while the
invoice is a draft; the receiving account is then cleared if it is in another currency, the prices are not converted
(they stay the numbers I typed), and hourly rates are suggested from the contract only when the invoice and the
contract are in the same currency, because the rates are in the contract's currency. An invoice in a currency other
than its contract's gets a warning, not a block. The default receiving account is the one from the client's last
invoice only if it is in the invoice's currency and active; otherwise the first active account in that currency.

The language of the invoice is `en` or `uk` (English by default). It decides the labels, the date format and which of
the bilingual fields are printed — my Latin name and English address or the Ukrainian ones, the client's legal name
or its Ukrainian legal name when one is filled in — and the language in which a contract's description template is
expanded when a line item is created (month names in `{started_at:F j, Y}`). Text I typed myself — item descriptions,
the contract name, notes, a custom payment purpose or act note — is printed as I typed it and never translated, and changing the
language of a draft does not rewrite its existing items. A bilingual invoice is a later phase.

The payment purpose is the reference the client types into the transfer so the money can be matched to the invoice,
printed in the payment details. It is a template whose placeholders are filled when the invoice is frozen (the draft
shows a preview with the previewed number): `{services}`, `{invoice.number}`, `{invoice.date}`, `{period.start}`,
`{period.end}`, `{contract.name}`, `{contract.number}`, `{contract.date}`, `{client.name}` and `{profile.name}`, dates in
the invoice's language. `{services}` is the contract's `subject`, or the word "services" ("послуги") when there is no
contract or no subject. The built-in default follows "Payment for ____ (the service) by the invoice/contract № ___ from
___ (its date)" in its invoice form: "Payment for {services} by the invoice No. {invoice.number} from {invoice.date}",
and in Ukrainian "Оплата за {services} згідно з інвойсом № {invoice.number} від {invoice.date}"; a client paid against
the contract instead gets "Payment for {services} by the contract No. {contract.number} from {contract.date}". An
unknown placeholder is rejected when the template is saved; a template that uses a contract placeholder cannot be
frozen on an invoice without a contract or whose contract lacks that value — the freeze names the missing placeholder.
International transfers carry at most 140 characters of reference in a restricted Latin character set (SWIFT field 70,
SEPA remittance information), so for an invoice in a currency other than UAH the form warns, without blocking, when
the rendered purpose is longer or uses other characters — which is why the English default writes "No." and not "№",
which those systems drop or reject.

The act-of-acceptance note is printed on the invoice under the payment details. It is on by default with "Payment of
this invoice confirms the successful delivery of services and serves as the Act of Acceptance. No additional
signatures are required." (in Ukrainian: "Оплата цього інвойсу підтверджує належне надання послуг і є актом
приймання-передачі наданих послуг. Додаткові підписи не потрібні."). Its text can be replaced and it can be switched
off per client or per invoice — switched off for clients who sign separate acts, which the note would contradict. A
custom text is printed as written, in whatever language it was written. The note works only if the contract itself
says that payment of an invoice confirms acceptance of the services; it restates that clause, it does not create it,
and the invoice must still carry what a primary document needs (the parties, the date, the content and volume of the
services and the amount) — to be confirmed with an accountant.

The contract section ("Contract: Service Agreement No. 109-23 dated Sep 1, 2026") is shown by default and can be
switched off per client or per invoice; an invoice without a contract never shows it. Hiding it only hides the section:
the contract stays linked to the invoice and in its snapshot, and line-item descriptions and a payment purpose that
mention the contract still do.

Invoice creation starts by picking a client — its contracts, projects and charge types become selectable, the currency
defaults from the contract or else the client, the receiving account from the client's last invoice in that currency,
the language, payment purpose, act note and contract section from the client's defaults, and the description from
the contract's template — then a service period `period_start … period_end` with `issued_at` defaulting to `period_end`,
then the receiving account (filtered by currency and `is_active`), then the line items. The unique number follows
`{CLIENT_ABBREVIATION}-{LAST_DATE_OF_THE_RANGE}-{INVOICE_NUMBER_OF_THE_CLIENT}` by default (BL-2026-10-14-13: the
invoice is to Brightline Labs, for the period ending on 14 October 2026, and it is that client's invoice #13), and the
mechanism is configured in settings → invoices → numbering: `invoice.numbering.prefix` is `client_abbreviation` (the
number starts with the client's abbreviation — the default) or `none`, `invoice.numbering.strategy` is
`date_sequence` (`{YYYY-MM-DD}-{n}`) or `sequence` (just `{n}`), `invoice.numbering.date_source` is `period_end` or
`issued_at`, `invoice.numbering.scope` is `per_client` (the counter is per client — the default) or `global` (one
counter for the whole profile), and `invoice.numbering.reset` is `never` or `yearly`. So `sequence` gives `BL-13`, a
global counter `BL-2026-10-14-41`, and without the prefix the numbers are `2026-10-14-13` or `13` — then two clients'
counters can arrive at the same number on the same date, which the freeze refuses, so the prefix is what makes
per-client counters safe. Counters are rows of `invoice_sequences` keyed by `global`, `client:{id}` or the same with a
`:{year}` suffix — by the client's id, not its abbreviation, so changing the abbreviation keeps the count —
incremented inside a transaction under `SELECT … FOR UPDATE`, so two concurrent freezes can never get the same
number. While the invoice is a draft the number is only previewed (`next: BL-2026-10-14-13`); it is allocated when the
invoice is frozen, unless I typed my own number, which bypasses the generator — `UNIQUE(profile_id, number)` guards
collisions either way. Line items carry `position`, the project and charge type (id + text copy), the description, a
`unit` of `hour`, `fixed` or `item`, `quantity` (two decimals), `unit_price` and the computed `amount`; in my case it is
almost always `PROTO · Billable · "IT services in accordance to the Service Agreement dated April 13, 2023" · 86.50 h ×
45.00 = 3 892.50`, and from the code and charge type the client knows whom to bill on their end and how time was spent
per project.

Invoices have a `source`: `generated` ones need at least one item, the total is computed and the PDF is rendered by
the system; `external` ones (historical invoices, client self-billing) have optional items, a total entered directly
and an uploaded PDF as the primary document. Uploaded files are kept in `invoice_files` with `kind` `generated` or
`uploaded`, an `is_primary` flag (exactly one primary file per frozen invoice), storage metadata and a `sha256` for
duplicate detection, on a private disk served through signed short-lived URLs; if I upload my own PDF for a generated
invoice and mark it primary, nothing is rendered. Once an invoice exists I can download it as PDF and move it through
the statuses `draft`, `ready`, `sent`, `paid`, plus `cancelled`. The move `draft → ready` is the freeze: the number is
allocated if blank, a JSON `snapshot` is built with the profile block, client, contract, receiving account with all its
details, items, totals, the resolved language, payment purpose (rendered), act-of-acceptance note and contract-section
flag, and a `schema_version`, the PDF is rendered only from that snapshot (never from live relations,
so later edits to the profile, client or account do not alter issued documents) and `locked_at` is set. From `ready`
an invoice can go back to `draft` as long as it has no receipts: generated files are deleted, uploads are untouched,
snapshot and `locked_at` are cleared, and the number is kept (gaps are acceptable). Because the draft can then change
its dates, the invoice remembers the `sequence_scope_key` and `sequence_number` its number was allocated from: on the
next freeze a generator-allocated number is rebuilt from the client's current abbreviation and the current date source
with the same `{n}` (`BL-2026-10-14-13` becomes `BL-2026-10-15-13` if `period_end` moved a day), a new `{n}` is
allocated only if the scope key itself changed (another client, or a yearly reset and the date moved into another
year), and a number the user typed is never touched.
`ready → sent` sets `sent_at`,
`sent → paid` is suggested automatically once the receipts cover the total and confirmed manually with `paid_at`
defaulting to the last receipt date, `ready` or `sent` can become `cancelled` (the number is never reused; a
replacement is a new invoice), and `paid` never changes again. Invoices and their items in `ready`, `sent`, `paid` or
`cancelled` cannot be edited: `InvoiceObserver` and `InvoiceItemObserver` throw `InvoiceLockedException` on any update
or delete unless the status is `draft` or the dirty set is within `status`, `sent_at`, `paid_at`, `cancelled_at` and
`notes`, and `InvoicePolicy@update` mirrors the rule so the API answers before the observer is reached.

Once there is a frozen invoice I record the payments for it, and a payment is a receipt: one credit of client money to
one of my accounts, which is also exactly one taxable event. If the client pays in parts there are several payment
rows on the invoice, each with its own date, rate and quarter, and the invoice shows a bar `USD 3 100.00 / 3 892.50
(80 %)` until it is covered and `USD 3 892.50 paid` after that; an overpayment is shown as `paid > total`. Each payment
stores the invoiced `amount` and `currency` (the taxable base), the optional `client_fee_amount` the client paid on
top (recorded, but never part of the income), and the receipt itself: `received_to_account_id`, `received_amount`,
`received_currency` and `received_at`. The difference between the invoiced and the received amount in the same
currency is the receipt fee — what the client's bank or the platform took on the way in; when the currencies differ it
is NULL. `received_at` is the tax date: the day the money landed on the account named in the receipt — the platform
when a platform was involved, the ФОП bank account when the client wired directly — which is the ДПС position, and the
rate and tax snapshot are computed the moment the payment is saved, so one receipt is always exactly one taxable
event. An alternative mode that recognises income only on the day the money reaches the Ukrainian ФОП account
("визнати дохід на дату надходження на ФОП-рахунок") was designed and is deliberately left out of this phase — not
in the settings, not on the receipt screens; the design keeps it in reserve so it can be added later without
reshaping the data. The `PaymentRecorder` asserts that the invoice and the account belong to the
current profile and that the invoice is `ready`, `sent` or `paid`, fetches the NBU rate for the currency and the
receipt date, computes and stores the UAH amount, ЄП, ВЗ and the period columns, warns (without blocking) when the
receipts exceed the total and suggests the `paid` status when they cover it. Non-invoice taxable income such as a platform bonus is a payment row with
`kind = other` and `invoice_id = NULL`, taxed and reported identically; bank interest is passive income and is not
recorded. In v1 one receipt belongs to one invoice; a single wire that covers several invoices is an open item in
`SUGGESTIONS.md`.

Money then has to reach my Ukrainian ФОП bank account, usually through one or more of my own accounts (Payoneer →
Wise → bank, Payoneer USD → ФОП UAH), and these movements are not fields on the payment but their own records:
`account_transfers` between two of my accounts (`from_account_id`, `to_account_id`, `amount_sent`/`currency_sent`,
`amount_received`/`currency_received`, `fee_amount`/`fee_currency`, `sent_at`, `received_at`, reference, note), linked
to the receipts they carry through `transfer_allocations` (transfer, payment, amount in the sent currency). This is
what makes partial withdrawals (the remainder stays on the platform as "unallocated", it is not lost) and batched
withdrawals (one wire covering several receipts) representable. When I record a transfer out of an account, the
`AllocationProposer` proposes allocations FIFO over that account's receipts (and amounts previously allocated into it)
that still have an unallocated remainder, oldest first, capped at `amount_sent`, and I can edit the proposal before
saving. The `TransferRecorder` enforces that both accounts belong to the same profile, that for a same-currency
transfer `fee_amount` equals `amount_sent − amount_received` (it defaults to that), that for an FX transfer the fee is
explicit and the rest of the gap is spread, that the allocations of a transfer never exceed `amount_sent`, and that
what is allocated out of an account for a payment never exceeds what that payment holds on that account. What a
payment holds on an account is defined in that account's currency: its `received_amount` if the account is where it
was received, plus, for every inbound transfer, its pro-rata share of what actually arrived —
`allocation.amount / transfer.amount_sent × transfer.amount_received` — minus whatever was already allocated out of
that account for it. So a $1 000 receipt moved Payoneer → Wise with a $15 fee holds $985 on Wise, not $1 000, and a
Payoneer USD → Wise EUR hop leaves a EUR holding on Wise that the next hop (whose `currency_sent` is EUR) is bounded
by; the FIFO proposal works from the same figure, never from the sum of inbound `allocation.amount`. Loss is never
inferred as "invoiced minus landed"; it is the sum of explicit per-leg fees: per payment, `receipt_fee` plus its
`transfer_fee_share` (its allocation's pro-rata share of each transfer's fee) is the transfer loss, `landed_on_fop_bank`
is the sum of allocations that reached a `fop_bank` account traced through intermediate accounts, and
`still_on_platform` is `received_amount` minus what was allocated out of the receiving account — a cash position, not
a loss. For an FX transfer the spread is measured on one date so that NBU drift between receipt and withdrawal is
excluded: `fx_spread = amount_sent × NBU rate of currency_sent on transfer.received_at − amount_received` on the UAH
leg, with the effective rate `amount_received / amount_sent` shown next to the NBU rate. From this I get the statistics
I want: fees and spread by account, by route (`from → to` pairs) and by client per period, so I can see which platform
or payment flow is best for me; the sheet's `NET`/`LOST` columns conflated two things, and the app reports transfer
loss (fees only) separately from net income (per payment `received_amount − transfer_fee_share − (ЄП + ВЗ) / nbu_rate`
in the payment currency with ЄСВ excluded because it is not attributable to a payment; per period `Σ uah_amount −
fees − ЄП − ВЗ − ЄСВ` in UAH, and for a single-currency profile also in that currency with ЄСВ converted at the
period-end NBU rate and labelled an estimate).

Then the accounting. All receipts are split into the tax periods of 3 група by `received_at` — the date the funds
arrived on the platform, or on the ФОП bank account when they were sent there directly — and each payment is one row
of the quarter's table: the date, the invoice number (prominent) with the client or the description for other income,
the UAH amount in bold with the original currency, amount and NBU rate beneath it in smaller, lighter text
(`USD 516.27 @ 42.2297`, omitted for UAH receipts), the ЄП and ВЗ computed for that payment, its fees and its net. The
rows can be sub-grouped by invoice or by month inside the quarter. The quarter footer shows the income total in UAH,
the ЄП and ВЗ owed for the quarter (the year-to-date liability minus the previous quarter's — the declaration's
figure) with the standalone `ROUND(Σ × 5 %, 2)` / `ROUND(Σ × 1 %, 2)` and the per-payment sums beside it and their
kopeck deltas, the ЄСВ for the quarter as the sum of its three monthly obligations, the cumulative year-to-date income
and liabilities and the year-to-date income against the cap with a percentage. Next to it there
is a 12-row table of monthly ЄСВ obligations per year, generated from `tax_rules` for every active profile
independently of invoices and income, each `due`, `paid` or `exempt` and allowing a voluntarily higher payment, and a
monthly income table — the free-form record п. 296.1 asks for — both exportable to CSV/XLSX together with the quarter
and the year.

Taxes are shown from the accounting with three ways of aggregating them — by payment, by month and by quarter — with
the default taken from `taxes.default_grouping`. I pay per invoice, and that habit is preserved at the tax-payment
level, but it is not how periods are split: the period of a receipt is always the quarter of its `received_at`, never
the invoice issue or sent date, because an invoice sent on 28 September and paid on 5 October is Q4 income. Liability
is never stored — `TaxLiabilityCalculator` computes it from the payments on demand with one indexed query per quarter
(`profile_id`, `period_year`, `period_quarter`) per payment (the stored hints), per month and per quarter as
cross-checks, and year-to-date as the authoritative series: the obligation of a quarter is
`YTD(quarter) − YTD(previous quarter)`, Q1's being its own year-to-date figure, and that is the number every
reconciliation below is measured against so the running balance stays aligned with what ДПС posted. Each quarter has
a `tax_periods` row created by `DeadlineScheduler` for every
active profile that holds only status and deadlines: `declaration_due_at`, `tax_due_at` and `esv_due_at` computed by
the rules above including the weekend roll-over, `declaration_status` of `none`, `filled`, `submitted`, `rejected`,
`accepted` or `corrected`, `declaration_submitted_at`, notes, and an annual flag on Q4. When the app is adopted
mid-history, `tax_opening_balances` (tax type, as-of date, amount — e.g. ЄП +51.00 overpaid at 2026-01-01) seed the
running balances.

The trickiest part is tracking the tax payments themselves, and since there are three ways of aggregating there are
three ways of recording them in one `tax_payments` table through `allocation`: `payment` requires `payment_id` and
copies the period columns from that payment's `received_at`, `month` requires `period_year` and `period_month`,
`quarter` requires `period_year` and `period_quarter`; `period_year` and `period_quarter` are always filled so quarter
reconciliation is one indexed query, and the combinations are enforced with CHECK constraints. Every row has
`tax_type` (`single_tax`, `military_levy` or `social_contribution`), the UAH `amount`, `paid_at`, a `status` of `sent`,
`paid` or `failed`, an optional `paid_from_account_id` (taxes may be paid from any account including a personal card;
when it is set the account must be UAH and active, and the form defaults to `taxes.default_account_id`), a reference
and a note. One payment may have several tax payments (instalments) and a month- or quarter-level tax payment covers
many payments. `TaxReconciler` then compares, per profile, quarter and tax type, the quarter's obligation (the
year-to-date delta for ЄП and ВЗ; for ЄСВ the sum of the monthly obligations) with the sum of `paid` tax payments
mapped to that quarter in any allocation mode,
shows `sent` ones as pending without counting them, excludes `failed` ones entirely, and carries a running balance —
opening balance plus the accumulated paid-minus-owed over all quarters up to this one — across quarter and year
boundaries, positive meaning overpaid and carried forward, negative meaning outstanding. The by-payment view shows
each receipt with its own tax payments and the month/quarter-level ones as a "covers N payments" line under the group;
the by-month and by-quarter views show owed, paid, pending and balance with the deadline status of the period.

The app also watches for things I would otherwise miss and tells me about them as notifications that inform and
never block: a deadline within `alerts.deadline_days` or past while the liability is not covered; an ЄСВ month still
`due` after its quarter deadline; platform funds not repatriated by the rule in `compliance.platform_repatriation` (a
platform receipt whose allocations have not landed on a `fop_bank` account by quarter end, or N days, or never
checked — dismissible per payment through `repatriation_ack_at`); an account limit at 80 % or 100 %; year-to-date
income at 80 %, 90 % or 100 % of the cap; a contract expiring within 30 days or expired while it still has open
invoices; overdue invoices (`due_at` passed, not fully paid); and `ready` invoices without receipts for too long.
Notifications are stored, not recomputed on every page: one row per condition that stays until the condition clears,
with a read state kept per user. A notification becomes read only when I act on it — tap the mark-as-read icon, tap
its action, or tap the notification itself; merely opening the notifications page or loading the list never marks
anything read. They are shown on their own page, with the unread count on the practice overview and the tab badge,
and never as cards on the home screen. An organization-wide board with one row per profile for the accountant comes
in a later phase.

The first implementation phase is a deliberately simplified version of the designed screens — all the fundamental
functionality, none of the secondary options — mobile-first, in Ukrainian, with a navigation bar at the bottom
instead of navigation buttons at the top. Login is e-mail and password only. The home screen ("Головна") shows only
the open invoices (`ready` and `sent`), grouped by month and ordered by their period inside the month — everything
else lives on its own page. The practice overview ("Огляд практики") is one quarter at a time, with ‹ › buttons to
move between quarters like a calendar, and shows under the ФОП name a set of full-width rows: the yearly limit as a
progress bar (year-to-date income against the income cap), the quarter's income as invoiced versus received for the
invoices issued in that quarter (so I can see "$10 000 invoiced, $7 899 received so far"), the quarter's taxes in
three columns — ЄСВ, ЄП, ВЗ — and the number of unread notifications; it does not show the nearest deadline, a
"declarations sent" card, a "total credit" card or a notifications total. The invoices list is an infinite scroll
with no free-text search box — the system already knows every client, number and status, so the list is narrowed by
filters instead: a period (year, quarter, month, several at once) or a start–end calendar range, clients, currencies
and statuses; the rows show the number, client, period, status and total and do not carry a "not paid" label or an
outstanding amount. The invoice page itself stays as designed, except that the receipt cards at its bottom show the
date, the received amount, the commission and where the money landed, and no longer show ЄП and ВЗ. When I record a
receipt, the invoice is shown on two lines — the number in semibold primary blue, the client name underneath in a
light weight, because client names can be long. On the receipt's own page the header shows, above a light dashed
divider, the amount in the payment's currency as the title (`USD 3 892.50`), the UAH amount beneath it in light
text and the period badge (quarter, year and month); below the divider come the invoice number (semibold blue) and
the client (light); the exchange rate is not in the header — it stays in the "Курс НБУ" row of the details table.
The account page shows "Надходження" (everything that came in, green) and "Перекази" (everything that went out,
red) as two full-width rows without background fill. On the "Облік" page each income row separates its tax block
with a dashed divider and lays it out as two lines of two equal columns — ЄП and ВЗ, then commissions and net income
— and the summary card is allowed to be as tall as it needs: year-to-date income on its own row, the quarter's income
on its own row, the cross-checks as headers with the values on the next line at the end of it, and the four closing
sections each as a separate row. The client page opens with the client's numbers, per currency: paid all-time, paid
this year, paid last year, and the amount invoiced but not yet paid — or "Усе оплачено" in green with a check icon
when there is nothing outstanding. Amounts everywhere follow `display.money_format`, which defaults to `1 234.99`.

A few technical constraints hold throughout. The API is `/api/profiles/{profile}/…` REST resources using api-core
filtering, resources and pagination, Form Requests for validation and Policies for roles and lock enforcement, with
dates as `YYYY-MM-DD`. Auth is Sanctum — SPA cookie for the Vue app, tokens for integrations. Invoice updates carry an
`updated_at` precondition and answer 409 on a stale write (two accountants on one profile), and payment creation
accepts an idempotency key so a double click is one receipt. `NbuRateProvider` calls
`https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange?valcode=USD&date=YYYYMMDD&json`, parses the rate
without going through a float, caches it into `exchange_rates` by currency and date, is pre-filled by a nightly job
for every currency the active profiles use, accepts manual rows with `source = manual`, and on failure surfaces a
validation error with a manual-rate field rather than a silent default. PDFs are Blade → dompdf with a Cyrillic-capable
font bundled, rendered synchronously on freeze (one page, under a second), with `spatie/laravel-pdf` + Chromium as the
upgrade path if flex/grid layouts are ever needed. Financial rows carry `created_by_user_id` and an activity log
(invoices, payments, transfers, tax payments); `tax_id`, IBAN and account numbers use encrypted casts; no PII goes to
logs. Pest tests cover rounding and the kopeck deltas, numbering under concurrency, lock enforcement, quarter
assignment of cross-quarter receipts, allocation bounds and the FIFO proposal, fee and spread math, deadline
roll-over, the NBU provider against HTTP fakes, the invoice-settings cascade at every level, PDF golden files per
snapshot version and per language, and above all profile isolation
— a cross-profile id is a 404 and a query without a profile context returns nothing.

Finally, a one-off artisan command with `--profile=` and `--dry-run` imports my 2025 Google Sheet ("2025 - payments"
and "2025 - taxes") into external invoices, payments, transfers with allocations and tax payments, then prints the
computed quarter totals next to the sheet's totals; any kopeck difference must be explainable by the rounding policy
or the import fails, and the 2025 ЄСВ months must be importable as paid or exempt. Out of scope for this phase —
expenses, VAT and other tax groups, employees, bank and platform API integrations, a client portal, recurring
invoices, sending invoices from the app, e-mail or Telegram delivery of notifications, free-text search, ДПС XML
export, per-profile access and the "recognise income on ФОП-account arrival" mode — are listed with the remaining
open decisions in `SUGGESTIONS.md`; refunds, one wire for several invoices, the `personal` account kind with opening
balances, period locking, FX gain and ДПС payment requisites are already in the design spec (v3.3).
