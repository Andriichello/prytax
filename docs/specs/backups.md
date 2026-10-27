# PryTax — Backups, data safety & retention (design v1.5)

Companion to `docs/specs/design.md` (v4). Covers (1) protecting the platform's data against loss,
(2) per-organization backups / exports that owners configure and download, and (3) data retention and deletion
(§9). Stack: Laravel 13, PHP 8.5, MySQL 8, PHPUnit 12, queue workers, scheduler with `->onOneServer()`, Google Cloud
Storage (private buckets).

### Changes in v1.5 (second review against the code)

- **Ids inside values are rewritten on import** — a new `rewrite()` treatment, guarded by a test (§4.2 "Ids inside
  values", §5.1): the snapshot's `profile.signature_file_id` (`InvoicePdfRenderer::signature()` looks the file up by id
  without scoping, so a restored invoice would draw the source profile's signature), the account ids of the
  `taxes.payment_account_ids` / `taxes.default_account_id` settings, and the client id in `sequences.key` /
  `invoices.sequence_scope_key` (`invoices:client:{id}[:year]`). The renderer also scopes the lookup to the invoice's
  profile.
- **`alerts` and `audit_logs` are export-only** (`exportOnly()`): exported and purged, never imported. AlertSync rebuilds
  alerts; the audit history keeps its original ids as a read-only record (§2.3, §4.2, §5.1).
- **Morph owners are the non-reference tables only.** Counting `users`, `tax_rules` and `exchange_rates` as owners
  contradicted the order test (references come last) and would export and purge a user's own settings or files with a
  ФОП (§4.2).
- **Reference rows are matched on import, never inserted** (`matchOn()`): `tax_rules` by `effective_from`,
  `exchange_rates` by `(currency, rate_date)`, `users` by e-mail within the target organization, else NULL (§4.2, §5.1).
- `invoices.active_number` (stored generated) marked `generated()`; generated columns are now derived from
  `information_schema.COLUMNS.EXTRA` by a test, like the FKs (§4.2).
- api-core's `notifications` (`notifiable_*`, per-user) added to `ApiCoreTables::EXCLUDED`, else the completeness test
  fails on day one; retention family `communication` (§2.3, §9.2, §11.1).
- **The run (§4.3):** `WithoutOverlapping` instead of `ShouldBeUnique` (which silently drops a manual or pre-deletion
  run dispatched while another is pending); a retry resumes its run and skips profiles already done; the content hash
  is decided before any document is fetched; the snapshot is committed before documents and uploads; uploads are
  verified with Flysystem's `checksum()`, not a GCS-specific crc32c.
- **`profiles.data_changed_at` dropped**, with the observers on api-core's `Setting` / `File` / `Sequence` it needed:
  `content_hash` alone decides `skip_unchanged` (seconds per profile at this size) (§4.1, §4.3).
- **Daily / weekly / monthly is chosen when pruning**, from `created_at`, not tagged when written; a retention or
  frequency change then applies to existing backups. `tier` remains only for manual / automatic / export (§4.1, §4.3).
- **No automatic backup "before a write into a locked period"**: queued, it would run after the write; the audit log
  and Layer A's point-in-time recovery cover it. `SheetImporter` runs its safety backup synchronously (§2.2).
- **D11 relaxed:** `format_version` is bumped for breaking registry changes only (a dropped or renamed column, a changed
  treatment); an added column changes `registry_hash` only, and the importer fills missing columns with their defaults.
- **No `maennchen/zipstream-php`**: bundles are built with `ZipArchive` (ext-zip, libzip 1.7.3, AES-256 supported) on the
  worker's disk. The only package to approve is the GCS Flysystem adapter (§4.5, §7).
- **Storage access (§3.3, §6):** in one deployable, "the writer cannot delete" doesn't hold (the owner's DELETE, the
  retention purge and the pruner all delete). The guarantee is the bucket's soft delete plus the DR copy.
- **User removal goes through api-core's delete** (soft delete, tokens revoked, linked accounts handed over), then
  anonymizes; `users.password` is NOT NULL, so it gets an unusable random hash instead of NULL (§9.3, §9.5, §11.1).
- **The engine may use api-core** (CLAUDE.md requires its base classes for models, policies and endpoints, and the
  extracted package requires api-core anyway); the dependency rules are a **PHPUnit** test, not Pest `arch()` (D13, §10.3).
- §5.3 no longer promises a side-by-side restore in the same organization, which `UNIQUE(organization_id,
  tax_id_hash)` rules out; that is now an **open decision** (§5.3).
- The audit tagger decides from the entry's columns, never by loading `$entry->subject` (gone for a hard-deleted
  record) (§4.2).
- Rolling cutoffs with a Prunable model use Laravel's `model:prune` (`idempotency_keys` already does,
  `routes/console.php`) (§9.2, §9.5).
- Names fixed: `QuarterLiability` → `TaxReconciler` / `TaxLiabilityCalculator`; `core/api-core` → `andriichello/api-core`
  (and the committed path repository noted, §12); "activity log" → `audit_logs`; §2.2's `include_audit_log` selects by
  `metadata.profile_id`.

### Changes in v1.4 (api-core facts confirmed against the code)

- **Audit open decision resolved:** PryTax tags every audit entry of profile data with `metadata.profile_id` in an
  `AuditLog::creating` listener (no api-core or schema change). `audit_logs` is selected by that key, not by subject,
  so the deletion history of hard-deleted records is exported and purged with its profile. The "sweep after 1 095
  days" alternative is rejected: api-core's `audit.retention_days` is table-wide and would delete live profiles'
  history too (§4.2, §11.1).
- **Sixth strategy `TableSpec::profileJson()`** for a profile id inside a JSON column (`audit_logs.metadata`).
- **Confirmed:** `files.disk` / `files.path` (each file is read and deleted through its own row's disk, never a
  fixed bucket); the audit actor is the polymorphic `causer_*` (`users.csv` reads it through the morph alias);
  aliases have the form `models.<name>` (`models.profiles`, `models.invoices`, `models.users`).
- **Morph map hardening:** `Relation::requireMorphMap()` in PryTax's `AppServiceProvider` (today the map is not
  enforced, so an unmapped model silently writes its class name), plus a boot-time guard against alias collisions
  between api-core's and PryTax's maps (a future `PryTax\Models\File` would silently replace `Core\Models\File` as
  `models.files`) (§4.2).
- Organization purge deletes `audit_logs` by `organization_id` (covers user- and organization-level entries) (§9.3).

### Changes in v1.3 (registry aligned with the real schema)

- **Table list corrected.** The spec's `profile_settings`, `invoice_files`, `invoice_sequences`, `notifications` /
  `notification_reads`, `activity_log`, `profiles.signature_path` and `updated_by_user_id` don't exist. The real tables:
  api-core's `settings`, `files`, `sequences`, `audit_logs`; PryTax's `alerts` / `alert_reads`; `invoices.primary_file_id`
  → `files`; the signature is a `files` row owned by the profile (§2.3, §4.2, §11.1).
- **Fifth row-selection strategy `TableSpec::morph()`** for the api-core tables that reach their owner through a
  type/id pair (`model_*`, `fileable_*`, `subject_*`) and carry no `profile_id`: rows whose type is the morph alias of a
  registered table and whose id is in that table's exported rows (subquery, same snapshot). No `profile_id` column is
  added to `audit_logs`. The completeness test now also finds every `*_type` + `*_id` pair (§4.2).
- **Morph map enforced** (`Relation::enforceMorphMap`) so type columns hold stable aliases, not class names (§4.2).
- **`invoices.snapshot` is encrypted** (`encrypted:array`; it copies the РНОКПП and IBAN) and is exported decrypted
  as JSON. Encrypted columns: `profiles.tax_id`, `accounts.iban`, `accounts.account_number`, `invoices.snapshot`. A test
  derives this list from the models' casts, so a new encrypted column can never be exported as ciphertext (D6, §4.2).
- **Insert order fixed and now verified by a test from `information_schema`:** `charge_types` before
  `contract_rates`; `account_transfers` before `payments` (`payments.source_transfer_id`); reference tables (`users`)
  last. The `invoices.primary_file_id` ↔ `files` cycle is broken with a **deferred column**: NULL on insert, set after
  all tables; nulled first on purge (§4.2, §5.1, §9.5).
- **Open decision:** audit rows of hard-deleted records are reachable by no strategy (§4.2 "Orphaned morph rows").

### Changes in v1.2

- Backups and retention are one **module inside the PryTax app**: generic engine in `PryTax\DataLifecycle\`,
  PryTax's definitions in `PryTax\Backups\` and `PryTax\Retention\`, connected through four contracts; boundary
  enforced by Pest `arch()` tests from day one (D13, §10).
- **api-core (`Core\`) is not changed.** PryTax registers the few api-core tables it backs up or cleans up
  (`users`, tokens, sessions, role assignments, `organizations`) and owns the user-removal handler; the column
  snapshot covers those tables, so an api-core upgrade that changes them fails PryTax's CI until reviewed (D14, §11).
- Not a composer package yet. Extraction to a sibling package `api-data-lifecycle` (never into api-core) when a
  second app needs it; the plan and the dependency-cycle trade-off are in §11.2.
- Local development against api-core: git dependency with tags, git-ignored local path override, branch alias (§12).
- `BackupTableRegistry` (static class) → `PryTax\Backups\PryTaxTableRegistry` implementing
  `PryTax\DataLifecycle\Contracts\TableRegistry`; references updated throughout.

### Changes in v1.1

Adopted from two reference guides of another system ("Backup Registry & Versioning", "Data Retention"), adapted
to a single Laravel deployable:
- Tenant strategies and column policies in the registry, made explicit (`byProfile` / `byParent` / `reference`;
  `all` / `include` / `exclude`) (§4.2).
- A **registry definition hash** stored on every backup and checked in CI, instead of a database-backed version
  registry synced by cron (D11, §4.2).
- **Data retention**: families × actions × periods, deletion runs with `due_at`, atomic claim, per-family results;
  organization / profile / user offboarding with a lock-first step and a grace period; FK-ordered purge that reuses
  the backup registry in reverse (D12, §9).

---

## 0. Decisions in one table

| # | Question | Decision |
|---|---|---|
| D1 | Are per-organization CSV archives our backup? | **No — two layers.** Layer A (platform disaster recovery) = database-native backups + point-in-time recovery + an independent nightly dump, owned by us and not configurable. Layer B (tenant backups/exports) = per-profile CSV archives that organizations schedule and download. CSV archives cannot restore a whole platform quickly or consistently; database backups cannot give an owner their own data. Both are needed. |
| D2 | Full vs quarter-by-quarter vs year-by-year vs invoice-by-invoice? | **Scheduled backups are always a full snapshot of the profile.** A ФОП's whole history is a few MB (KB without PDFs), so incremental or partial backups add complexity and restore risk for no saving. Period slicing (year / quarter / date range) exists as an **on-demand export** (for an accountant or a tax audit) and is marked as not restorable. Invoice-by-invoice backups are not offered: thousands of tiny objects, no restore value. Inside every archive, documents are already organised per invoice. |
| D3 | Format | **CSV, one file per table**, RFC 4180, UTF-8 with BOM, plus `manifest.json`, `schema.json`, `README.txt` and a `documents/` folder with the invoice PDFs and uploads. |
| D4 | "Zipped and gzipped"? | **Only `.zip` (deflate).** Gzipping a zip saves ~0 % (the content is already compressed) and turns a double-click archive into a two-step one on Windows/macOS. |
| D5 | Unit of storage | **One archive per profile per run.** The organization archive ("one zip, a folder per profile") is **assembled on demand** from the profile archives of the same run, so nothing is stored twice and all profiles in it share one snapshot instant. |
| D6 | Encrypted columns (`profiles.tax_id`, `accounts.iban`, `accounts.account_number`, `invoices.snapshot` — the snapshot copies the РНОКПП and IBAN) | **Exported decrypted**, the snapshot as JSON (ciphertext is useless outside the app's `APP_KEY`). The list is derived from the models' `encrypted*` casts by a test, never maintained by hand. The archive is therefore sensitive: private bucket, signed short-lived download URLs, downloads audited, an optional password set at download time. |
| D7 | Who configures / downloads | **Owner** configures the policy and downloads. **Accountant** can download (they run the books) and trigger a manual backup. **Viewer**: no access (bulk export of personal data). |
| D8 | Retention | **Grandfather-father-son** per organization (keep N daily / N weekly / N monthly + manual), enforced by an app-side pruner; a bucket lifecycle rule is the hard ceiling. **The latest completed backup of a profile is never pruned.** |
| D9 | Restore | Phase 1: ops-only `artisan` import **into a new profile** (never in place), plus an automated export→import round-trip test. Later: self-service "restore as a new ФОП". No in-place overwrite, ever. |
| D10 | `APP_KEY` | Layer A backups are useless without the key that encrypted the columns. It lives in Secret Manager with every historical version; key rotation uses `APP_PREVIOUS_KEYS`. |
| D11 | How is the backup definition versioned? | **In code, with a hash.** The registry is deployed atomically with the migrations, so a database copy of it plus an hourly sync (the reference design) would only add a window where code and registry disagree. Each backup stores `registry_hash` (sha256 of the normalized registry + column lists). **`format_version` is bumped for breaking changes only** (a dropped or renamed column, a changed treatment, a removed table); CI compares the column snapshot with the committed one and fails on a breaking change without a bump. An added column changes `registry_hash` only: the importer fills columns missing from an older archive with their defaults. Backups are never produced with an *old* definition; old *archives* are read through importer upcasters. |
| D13 | Module or scattered code? | **A module in the app**, not a composer package: engine `PryTax\DataLifecycle\` (generic, no knowledge of accounting; built on api-core's base classes), definitions `PryTax\Backups\` + `PryTax\Retention\` (PryTax-specific), four contracts between them, a PHPUnit test for the direction of dependencies (§10). |
| D14 | Does api-core take part? | **No.** api-core (`Core\`) stays free of backup code, migrations and GCS/zip dependencies. PryTax registers the api-core tables it needs and owns the user-removal handler (§11). Extraction later goes to a sibling package, never into api-core. |
| D12 | Data retention & deletion | **Families × actions × periods in config**, executed by deletion runs with per-family jobs and a `due_at`. Accounting data is never deleted on a timer while the organization exists; offboarding = lock immediately → final export → grace period → FK-ordered purge (DB rows, documents, backups). Credentials and technical data have short rolling cutoffs (§9). |

---

## 1. Layer A — platform disaster recovery (not tenant-configurable)

Target: **RPO ≤ 5 min, RTO ≤ 1 h** for the database; **no loss** of uploaded documents.

### 1.1 Database (Cloud SQL for MySQL; equivalents noted for self-managed MySQL)

- **Automated daily backups**, retention 30 (Cloud SQL allows 1–365) and **point-in-time recovery** (binary-log
  based) enabled. Self-managed: `xtrabackup` nightly + binlog shipping to GCS.
- **Deletion protection** on the instance, and a **final backup** configured, so deleting the instance by mistake
  keeps a backup (Cloud SQL: final backups default 30 days, 1–365). Automated backups are otherwise deleted on a
  rolling basis after instance deletion.
- **Independent logical dump, outside the instance's blast radius**: nightly `mysqldump --single-transaction
  --routines --triggers --hex-blob` (or `gcloud sql export sql`) from a read replica to a **separate bucket in a
  separate GCP project** (`prytax-dr-<env>`), with a **bucket retention policy (Bucket Lock)** of 35 days. This
  survives a compromised app service account, an accidental instance or project deletion, and a bad migration that
  replicates to the replica.
- **Quarterly restore drill**: restore the latest dump into a scratch instance, run `php artisan prytax:verify-db`
  (row counts, FK orphans, `TaxReconciler` balances for a sample of profiles), record the duration (this is your real
  RTO).

### 1.2 Documents (invoice PDFs, uploaded invoices, signature images)

- Documents bucket (`prytax-files-<env>`): private, uniform bucket-level access, public access prevention enforced,
  **soft delete 30 days** (configurable 7–90; covers deletes and overwrites), and object versioning off (soft delete
  covers it).
- Generated PDFs are regenerable from `invoices.snapshot`; **uploaded** files and signatures are not, which is
  why the bucket needs protection, not just the database.
- The nightly DR job also copies new objects to the DR project's bucket (Storage Transfer Service, daily,
  incremental).

### 1.3 Keys and secrets

- `APP_KEY` and every previous key in **Secret Manager** (versions never destroyed). Without them, `tax_id`, `iban`
  and `account_number` in any database backup are unreadable, and `tax_id_hash` (HMAC with the app key) cannot be
  recomputed.
- Key rotation: new key in `APP_KEY`, old keys in `APP_PREVIOUS_KEYS`; a re-encrypt job, then old keys stay in
  Secret Manager for as long as any backup encrypted with them exists (35 days for Layer A).

---

## 2. Layer B — organization backups (what owners see)

### 2.1 Concepts

| Term | Meaning |
|---|---|
| **Backup policy** | One per organization: schedule, retention, what to include, which profiles. |
| **Run** | One execution for an organization at a single snapshot instant (scheduled or manual). Produces one archive per included profile. |
| **Profile archive** | `.zip` of one ФОП's full data at the run's instant. The stored unit. |
| **Organization bundle** | `.zip` with a folder per profile, built on demand from one run's profile archives; temporary (24 h). |
| **Period export** | On-demand, filtered by year / quarter / range; same format; `restorable: false`. Not part of retention. |

### 2.2 Policy options (owner-configurable)

| Setting | Values | Default |
|---|---|---|
| `enabled` | on / off | on |
| `frequency` | `daily` · `weekly` (+ weekday) · `monthly` (+ day 1–28) | `daily` |
| Time | Not user-chosen. A platform window of 01:00–05:00 Europe/Kyiv, with a deterministic per-organization offset (`crc32(org_id) % 240` minutes) to spread load. | — |
| `retention_daily` / `_weekly` / `_monthly` | 0–31 / 0–12 / 0–36 | 7 / 4 / 12 |
| `include_documents` | invoice PDFs + uploads + signature | on |
| `include_audit_log` | `audit_logs` rows tagged `metadata.profile_id` = the profile (incl. entries of hard-deleted records, §4.2) | off |
| `profiles` | `all` (incl. new ones automatically) or a selected list | `all` |
| `skip_unchanged` | skip a profile whose data did not change since its last completed backup | on |
| `notify` | `failures` · `always` · `never` (e-mail to owners) | `failures` |

Manual backups ("Створити копію зараз"): owner/accountant, max 5 per organization per day, kept until deleted
(cap 20 per organization; the oldest manual one is pruned beyond that).

Automatic safety backups (no user setting), tier `automatic`, kept 30 days:
- **before `SheetImporter` writes** — synchronously (`dispatchSync`), so the import starts only after the backup exists;
- **when a profile is soft-deleted** — queued is fine: the data stays in place for the whole grace period (§9.3).

No automatic backup is taken for a write into a locked period with a `correction_note`: a queued backup would run
after the write it is meant to precede, and a synchronous one would slow every correction. The audit log records the
change with its old values, and Layer A's point-in-time recovery covers a mistaken correction.

Inactive profiles (`is_active = false`, a closed ФОП) are still included. `skip_unchanged` makes them free, and the
ФОП must keep records for 1 095 days (п. 44.3 ПКУ), so monthly retention up to 36 is offered for that reason.

### 2.3 What a profile archive contains

```
prytax_kovalenko-olena_p42_2026-10-27T0237Z.zip
├── manifest.json
├── schema.json
├── README.txt                    (UA + EN: what each file is, CSV conventions, how to open in Excel)
├── data/                         (file order = insert order, §4.2)
│   ├── profile.csv               (1 row; tax_id decrypted; tax_id_hash omitted)
│   ├── accounts.csv              (iban, account_number decrypted)
│   ├── account_opening_balances.csv
│   ├── account_movements.csv
│   ├── clients.csv
│   ├── projects.csv
│   ├── charge_types.csv
│   ├── contracts.csv
│   ├── contract_rates.csv
│   ├── invoices.csv              (snapshot decrypted → JSON string; primary_file_id is a deferred column;
│   │                              soft-deleted rows included, deleted_at set)
│   ├── invoice_items.csv
│   ├── account_transfers.csv
│   ├── payments.csv              (source_transfer_id → account_transfers)
│   ├── refunds.csv
│   ├── transfer_allocations.csv
│   ├── tax_periods.csv
│   ├── esv_obligations.csv
│   ├── tax_payments.csv
│   ├── tax_opening_balances.csv
│   ├── tax_payment_requisites.csv
│   ├── alerts.csv                (export-only: not imported, AlertSync rebuilds them)
│   ├── core/                     (api-core tables, selected through their owner — §4.2 morph strategy)
│   │   ├── settings.csv          (rows owned by the profile or any other exported record)
│   │   ├── sequences.csv         (invoice numbering counters of exported owners)
│   │   ├── files.csv             (metadata + archive_path of the file inside documents/)
│   │   └── audit_logs.csv        (only if include_audit_log; export-only, original ids kept)
│   └── reference/
│       ├── tax_rules.csv         (rows referenced by payments/refunds/esv_obligations)
│       ├── exchange_rates.csv    (rows referenced via exchange_rate_id)
│       └── users.csv             (id, name, email of users referenced by created_by_user_id and audit_logs)
└── documents/                    (one entry per exported files row)
    ├── invoices/2026-10-14-13/2026-10-14-13.pdf            (generated; primary via invoices.primary_file_id)
    ├── invoices/NW-2026-031/NW-2026-031.pdf                (uploaded; primary)
    └── profile/signature.png                               (files row owned by the profile)
```

**Excluded on purpose:** `users` beyond the referenced subset, roles, tokens, `idempotency_keys`,
`alert_reads` (per-user read state), api-core's `notifications` (per-user), generated columns (`period_*` — recomputed on import; kept in CSV
**as informational columns** marked `generated: true` in `schema.json` for human readers), `tax_id_hash`.

Every table that carries `profile_id` **or a `*_type` + `*_id` pair** must be either registered in the export registry
or listed in an `EXCLUDED` constant with a reason; a test fails otherwise (§4.2), so a new table can never silently be
missing from backups.

### 2.4 Organization bundle layout

```
prytax_org-<slug>_run-<run_id>_2026-10-27T0237Z.zip
├── manifest.json                 (run: instant, profiles[], per-profile archive sha256, totals)
└── profiles/
    ├── kovalenko-olena_p42/      (contents of that profile archive, unchanged — manifest, data/, documents/)
    ├── melnyk-andrii_p57/
    └── bondar-iryna_p63/
```

Folder name = `slug(full_name_en)` + `_p{profile_id}` (stable, unique, no tax id in names). Reference tables
stay inside each profile folder, so any folder can be copied out and is self-contained.

### 2.5 CSV conventions (fixed; documented in README and `schema.json`)

- RFC 4180: comma separator, `"` quoting, `\r\n` rows, header row with the database column names (snake_case).
- **UTF-8 with BOM** (Excel otherwise mangles Cyrillic).
- Decimals as stored: `1234.99` (dot, no grouping) — never formatted with `display.money_format`. Rates: 4 dp.
- Dates `YYYY-MM-DD`; timestamps ISO 8601 UTC `2026-10-27T02:37:11Z`.
- `NULL` = empty unquoted field; empty string = `""`. Readers that don't distinguish lose nothing important
  (`schema.json` marks nullability).
- Booleans `1` / `0`; enums as their string value; JSON columns as compact JSON strings.
- Rows ordered by `id`.
- Excel note in README: open via *Data → From Text/CSV* (UTF-8, comma) in Ukrainian locales, where Excel expects
  `;`. **Machine-readability wins** — no locale-specific CSV variant. A human-friendly XLSX ("Облік доходів" per
  year from `AccountingExporter`) can be added under `reports/` later.

### 2.6 `manifest.json`

```json
{
  "format": "prytax-backup",
  "format_version": 1,
  "registry_hash": "sha256 of the normalized table registry (tables, strategies, column lists)",
  "schema_version": "2026_10_27_000000",
  "app_version": "1.4.2",
  "kind": "scheduled",
  "restorable": true,
  "scope": { "type": "full" },
  "snapshot_at": "2026-10-27T02:37:11Z",
  "run_id": "01JBX…",
  "organization": { "id": 7, "name": "…" },
  "profile": { "id": 42, "full_name": "Коваленко Олена Петрівна", "tax_id_last4": "4821" },
  "files": [
    { "path": "data/payments.csv", "rows": 37, "sha256": "…", "bytes": 18234 }
  ],
  "documents": { "count": 41, "bytes": 3448121 },
  "checks": {
    "income_uah_by_quarter": { "2026-Q1": "486210.40", "2026-Q2": "497845.91", "2026-Q3": "503118.73" },
    "invoices_total_by_currency": { "USD": "41650.00", "EUR": "1540.00" },
    "tax_payments_paid_uah": "142233.12"
  },
  "content_hash": "sha256 of all data/*.csv hashes in path order (excludes snapshot_at)"
}
```

`checks` are recomputed after an import; any mismatch fails the import (§5). `content_hash` drives
`skip_unchanged`.

### 2.7 Period exports (year / quarter / range)

Same archive format, `scope: {type: "period", from, to}`, `restorable: false`. Contents = the closure needed to read
the period on its own:
- **Always full:** profile, settings, accounts, clients, contracts (+ rates), projects, charge types, requisites,
  reference tables (small, and needed for meaning).
- **Period-filtered:** payments with `received_at ∈ range`; refunds with `refunded_at ∈ range`; invoices referenced
  by those payments **plus** invoices with `issued_at ∈ range`; their items/files/documents; transfers with
  `received_at ∈ range` + their allocations + the payments those allocations reference (marked `in_scope = 0`);
  tax payments, tax periods and ЄСВ obligations whose period is in range.
- Built on demand (queued), stored under `tmp/` for 24 h, never pruned by policy, never counted in retention.

---

## 3. Storage (GCS)

### 3.1 Buckets

| Bucket | Purpose | Settings |
|---|---|---|
| `prytax-backups-<env>` | Layer B archives | Region `europe-central2` (or the app's region); Standard class; private; uniform access; public access prevention; **soft delete 30 days**; lifecycle: delete `org/*/profile/*` objects older than 1 125 days (36 months + margin — the hard ceiling); delete `tmp/*` after 1 day |
| `prytax-files-<env>` | documents | §1.2 |
| `prytax-dr-<env>` (separate project) | Layer A dumps + document copies | Bucket Lock 35 days; nobody in the app project has access |

### 3.2 Object layout (no personal data in object names)

```
org/{org_id}/profile/{profile_id}/{yyyy}/{mm}/{backup_ulid}.zip
org/{org_id}/runs/{run_ulid}/manifest.json
tmp/bundles/{run_ulid}/{random}.zip
tmp/exports/{export_ulid}.zip
```

### 3.3 Access

- **One identity in phase 1.** PryTax is one deployable, and deleting is part of its normal work: the owner's
  `DELETE …/backups/{backup}` (web), the retention purge (§9, worker) and `PruneBackups` (scheduler). A separate
  "writer that cannot delete" plus an impersonated pruner would not be a boundary: whatever lets the scheduler
  impersonate the pruner (`iam.serviceAccountTokenCreator`) lets a compromised worker do the same. The app's service
  account has `storage.objectAdmin` on the backups bucket.
- **What protects existing backups** is the bucket's **soft delete (30 days)**, which no app identity can bypass, plus
  the DR copy in another project (§1.1–1.2). A real writer/pruner split needs the pruner to run as its own deployment
  (a Cloud Run job with its own service account); revisit when that is worth its operations cost.
- **Downloads**: V4 signed URLs, valid 10 min, `response-content-disposition: attachment; filename="…"`, issued only
  by the API after a policy check; every issue is logged in `backup_downloads`.
- Encryption at rest: Google-managed by default; CMEK (Cloud KMS) if a customer requires it.

### 3.4 Cost order of magnitude

A ФОП with ~50 invoices/year: CSV data ≈ 0.5–2 MB raw → 100–300 KB zipped; documents ≈ 4 MB/year. With default
retention (7 + 4 + 12 ≈ 23 archives/profile), even an accountant with 50 profiles × 5 years of documents stays at
single-digit GB: cents to a few dollars a month. `skip_unchanged` removes most of the remainder for inactive
profiles. Cost is not a reason to slice backups.

---

## 4. Implementation (Laravel)

### 4.1 Tables

```
backup_policies     organization_id → organizations (CASCADE) UNIQUE,
                    enabled BOOL, frequency(enum daily|weekly|monthly), weekday TINYINT NULL, month_day TINYINT NULL,
                    retention_daily TINYINT, retention_weekly TINYINT, retention_monthly TINYINT,
                    include_documents BOOL, include_audit_log BOOL, profiles_mode(enum all|selected),
                    skip_unchanged BOOL, notify(enum failures|always|never),
                    next_run_at TIMESTAMP, last_run_at TIMESTAMP NULL, updated_by_user_id NULL, timestamps
                    -- api-core's organizations is not extended; policy lives here
backup_policy_profiles  backup_policy_id (CASCADE), profile_id (CASCADE)   PK(both)
backup_runs         id ULID, organization_id, kind(enum scheduled|manual|automatic|export),
                    scope JSON {type: full|period, from, to}, status(enum queued|running|completed|partial|failed),
                    snapshot_at TIMESTAMP NULL, triggered_by_user_id NULL, reason VARCHAR NULL,
                    started_at, finished_at, error TEXT NULL
                    INDEX(organization_id, created_at)
backups             id ULID, run_id → backup_runs, organization_id, profile_id → profiles (RESTRICT),
                    status(enum completed|skipped_unchanged|failed|pruned),
                    tier(enum scheduled|manual|automatic|export),   -- daily/weekly/monthly is chosen when pruning
                    disk, object_path NULL, bytes, sha256 CHAR(64), content_hash CHAR(64),
                    row_counts JSON, format_version, registry_hash CHAR(64), schema_version, include_documents BOOL,
                    expires_at TIMESTAMP NULL, pruned_at NULL, error TEXT NULL, timestamps
                    INDEX(organization_id, profile_id, created_at), INDEX(status, expires_at)
backup_downloads    id, backup_id NULL, run_id NULL, user_id, kind(enum profile|bundle|export), ip, user_agent, created_at
```

**`skip_unchanged` is decided by `content_hash` alone.** No `profiles.data_changed_at` column and no observers: a
"last changed" timestamp would need hooks in `BelongsToProfile`, in every child model and on api-core's `Setting`,
`File` and `Sequence`, and anything they miss (a query-builder write, a counter moved under a row lock) would skip a
profile whose data did change, since the timestamp gates the hash. Writing a profile's CSVs takes seconds at this size,
so the run writes them, compares the hash with the last completed backup's, and stops there for an unchanged profile,
before fetching any document (§4.3). No `profile_id` column is added to any api-core table (§4.2 morph strategy).

### 4.2 Export registry

The engine owns the `TableSpec` builder and the contract; PryTax owns the list (§10).

```php
namespace PryTax\Backups;

use PryTax\DataLifecycle\Contracts\TableRegistry;
use PryTax\DataLifecycle\Registry\TableSpec;

final class PryTaxTableRegistry implements TableRegistry
{
    /** @return list<TableSpec> in FK-safe insert order (verified by a test, see below) */
    public function tables(): array
    {
        return [
            TableSpec::root('profiles', 'id')->file('profile')
                ->decrypt('tax_id')->omit('tax_id_hash'),
            TableSpec::profile('accounts')->decrypt('iban', 'account_number'),
            TableSpec::profile('account_opening_balances'),
            TableSpec::profile('account_movements'),
            TableSpec::profile('clients'),
            TableSpec::child('projects', parent: 'clients', fk: 'client_id'),
            TableSpec::child('charge_types', parent: 'clients', fk: 'client_id'),      // before contract_rates
            TableSpec::profile('contracts'),
            TableSpec::child('contract_rates', parent: 'contracts', fk: 'contract_id'), // → charge_types
            TableSpec::profile('invoices')->withTrashed()->period('issued_at')
                ->decrypt('snapshot', json: true)                                       // encrypted:array
                ->rewrite('snapshot', json: '$.profile.signature_file_id', to: 'files') // id inside a value (below)
                ->rewrite('sequence_scope_key', pattern: '/client:(\d+)/', to: 'clients')
                ->deferred('primary_file_id')                                           // → files (cycle, below)
                ->generated('active_number'),                                           // IF(deleted_at IS NULL, number, NULL)
            TableSpec::child('invoice_items', parent: 'invoices', fk: 'invoice_id'),
            TableSpec::profile('account_transfers')->period('received_at'),             // before payments
            TableSpec::profile('payments')->period('received_at')                       // source_transfer_id → transfers
                ->generated('period_year', 'period_quarter', 'period_month'),
            TableSpec::profile('refunds')->period('refunded_at')
                ->generated('period_year', 'period_quarter', 'period_month'),
            TableSpec::child('transfer_allocations', parent: 'account_transfers', fk: 'transfer_id'), // → payments
            TableSpec::profile('tax_periods'),
            TableSpec::profile('esv_obligations'),
            TableSpec::profile('tax_payments'),
            TableSpec::profile('tax_opening_balances'),
            TableSpec::profile('tax_payment_requisites'),
            TableSpec::profile('alerts')->exportOnly(),                                 // AlertSync rebuilds them

            ...ApiCoreTables::owned(),        // settings, sequences, files, audit_logs — after all their owners (§11.1)

            // reference tables last: selected from the rows exported above, matched (never inserted) on import
            TableSpec::reference('tax_rules', via: ['payments.tax_rule_id', 'refunds.tax_rule_id', 'esv_obligations.tax_rule_id'])
                ->matchOn('effective_from'),
            TableSpec::reference('exchange_rates', via: ['payments.exchange_rate_id', 'refunds.exchange_rate_id'])
                ->matchOn('currency', 'rate_date'),
            ...ApiCoreTables::references(),   // users, matched by e-mail within the target organization
        ];
    }

    public const EXCLUDED = [
        'alert_reads'      => 'per-user read state',
        'idempotency_keys' => 'transient, 24 h',
    ];
}
```

**Completeness test.** The union of
- tables with a `profile_id` column,
- every registered `child` table, and
- tables with a `<name>_type` + `<name>_id` column pair (`information_schema.COLUMNS`)

must be ⊆ registry ∪ `PryTaxTableRegistry::EXCLUDED` ∪ `ApiCoreTables::EXCLUDED`, otherwise the test fails with
the table name.

**Tenant strategies** (named after the reference design, mapped to PryTax):

| Strategy | Used by | SQL |
|---|---|---|
| `TableSpec::root` | `profiles` | `WHERE profiles.id = ?` |
| `TableSpec::profile` | every `[P]` table | `WHERE t.profile_id = ?` |
| `TableSpec::child(parent, fk)` | `[child]` tables | `JOIN parent ON t.fk = parent.id WHERE parent.profile_id = ?` (chains resolve recursively) |
| `TableSpec::morph(name)` | api-core `settings` (`model_*`), `sequences` (`model_*`), `files` (`fileable_*`) | `WHERE (t.{name}_type = 'models.profiles' AND t.{name}_id IN (SELECT id FROM profiles WHERE id = ?)) OR (t.{name}_type = 'models.invoices' AND t.{name}_id IN (SELECT id FROM invoices WHERE profile_id = ?)) OR …` — one branch per registered **non-reference** table that has a morph alias, each subquery being **that owner's own selection** |
| `TableSpec::profileJson(column, path)` | api-core `audit_logs` (`metadata.profile_id`, written by PryTax — see "Audit entries" below) | `WHERE CAST(t.metadata->>'$.profile_id' AS UNSIGNED) = ?` — not indexed; fine for an export or a purge |
| `TableSpec::reference(via)` | global lookup tables | `WHERE id IN (SELECT DISTINCT fk FROM <exported via-tables>)` — only referenced rows, never the whole table. On import they are **matched, never inserted** (`matchOn()`, §5.1) |

**Morph strategy details.**
- **Owners are derived, not listed.** For each registered **root, profile or child** table, the engine looks up its
  morph alias in the morph map (`Relation::morphMap()`: alias → model → `getTable()`). Every such table with an alias
  is a possible owner of every morph table. A new owner type (a `files` row on a client, a `settings` row on an
  account) is covered without touching the registry.
- **Reference tables are never owners**, although `users` (`models.users`), `tax_rules` and `exchange_rates` have
  aliases. A user's own setting or file is not ФОП data: as an owner, `users` would put it into the archive of every
  ФОП that references the user, and the profile purge (which deletes morph rows through the same branches) would
  delete it. It would also contradict the order test, since reference tables come after the morph tables. No api-core
  model has `HasSettings` or `HasFiles` today, so this is latent, and the rule keeps it that way.
- **Subqueries, not id lists.** Each branch reuses the owner's predicate inside the same consistent snapshot, so no
  id sets are held in memory, and period exports (§2.7) automatically restrict morph rows to in-scope owners.
- **Morph map required, and collision-safe.** Confirmed: api-core maps its 7 models and PryTax its 24
  (`AppServiceProvider::MODELS`), every write goes through `getMorphClass()`, and type columns hold aliases of the
  form `models.<name>` (`models.profiles`, `models.invoices`, `models.users`). Two gaps, both to close before real data
  exists:
  1. **The map is not required** (`Relation::requiresMorphMap()` is false), so a model left out of `MODELS`
     silently writes its class name. PryTax's `AppServiceProvider` calls `Relation::requireMorphMap()` after both maps
     are registered; an unmapped model then throws. Today only `PryTax\Models\IdempotencyKey` is unmapped and never
     used in a morph column; run the full suite after the change in case a package model trips it.
  2. **Aliases come from the last namespace segment**, so a future `PryTax\Models\File` would also be `models.files`
     and silently replace api-core's `Core\Models\File` (the morph map is an alias → class array; the later
     registration wins). PryTax registers its map through a guard that throws at boot if an alias already exists
     with a different class, and a test asserts the merged map is one-to-one.
  Stable aliases matter beyond backups: with class names, moving `PryTax\DataLifecycle` into a package (§11.2) or
  renaming a model would orphan every `files`, `settings` and `audit_logs` row pointing at it.
- **Import:** `{name}_id` is remapped through the id map of the table its alias resolves to.
- **Purge:** morph tables come after their owners in insert order, so in reversed order they are deleted **before**
  the owners, while the owner subqueries still return rows.
- **Rows owned by records outside the profile** (an organization-level or user-level setting) match no branch and
  are not part of a profile archive, which is correct.

**Orphaned morph rows.** A morph row whose owner was **hard-deleted** matches no branch: it is neither exported nor
purged with the profile. For `settings`, `sequences` and `files`, deleting the owner must also delete its morph rows
(the owner's delete path, backed by a `technical` sweep of rows whose owner no longer exists, §9.2). `audit_logs` is
different: the entry of a deleted record is the deletion history itself. That is why audit entries are selected by
`metadata.profile_id` instead of by subject.

**Audit entries: `metadata.profile_id` (decided).** Confirmed `audit_logs` columns: `organization_id`, `causer_*`,
`subject_*`, `source`, `event`, `name`, `description`, `status_code`, `changes`, `metadata` (JSON, nullable, array
cast), `created_at`. Entries written by `Audited` leave `metadata` empty; auth events put the IP address and browser
in it. api-core blocks updating and deleting audit entries but not creating, and `AuditLog::record()` saves through
Eloquent. So PryTax adds, in its own service provider, with no api-core or schema change:

```php
AuditLog::creating(function (AuditLog $entry): void {
    $profileId = ProfileAuditTagger::resolve($entry);   // null → not profile data, leave untouched
    if ($profileId !== null) {
        $entry->metadata = [...($entry->metadata ?? []), 'profile_id' => (int) $profileId];
    }
});
```

`ProfileAuditTagger::resolve()`:
- The subject is a `Profile` → the subject's own id (covers profile creation and `ProfileSettingController`, where
  nothing is current yet).
- Otherwise `CurrentProfile::id()` (set on every profile request and carried into queued jobs; `BelongsToProfile`
  already refuses saves outside the current profile) — **but only if the subject belongs to profile data**: its table
  is a non-reference table of the registry (a `[P]` table, a child, or a `Core\` morph table such as `settings`/`files`
  whose owner is profile data). An update of the user's own name or of the organization during a profile request
  stays untagged; otherwise it would be exported into a ФОП's archive and deleted with that ФОП.
- It merges into existing `metadata` (never overwrites `ip` / browser keys) and stores an **integer** (the selection
  casts `->>` to `UNSIGNED`; a string id would still match, a JSON number is what the tagger writes).
- It has to happen in `creating`: entries can't be changed afterwards.
- It decides **from the entry's columns only** (`subject_type` → alias → table; `subject_id` for a `Profile`) plus
  `CurrentProfile::id()`. It never loads `$entry->subject`: `AuditLog::record()` fills the columns, not the relation, so
  that would be a query per entry, and for a hard-deleted record (the case this tagging exists for) it returns NULL.

This covers child records too (`invoice_items`, `contract_rates`, `transfer_allocations`), which have no `profile_id`,
so `changes.old.profile_id` (present only on deletes of models that have the column, and absent from update entries
that don't touch it) could never place them.

- **Selection:** `TableSpec::profileJson('audit_logs', 'metadata', '$.profile_id')`, not indexed. Add a functional index
  only if the export or purge shows up in slow-query logs.
- **Purge:** query builder (deleting through the model throws), same as every other purge query.
- **Untagged entries** (organization-level, user-level, auth events) belong to the organization: they go when the
  organization is purged (`WHERE organization_id = ?`, §9.3), never with a profile.
- **api-core's `audit.retention_days` stays off (null) for PryTax.** It applies to the whole table and would delete
  the audit history of live profiles; PryTax's retention (§9) decides when audit entries go. A test asserts the
  config value.

**Insert order is verified, not trusted.** A test reads `information_schema.KEY_COLUMN_USAGE` for every FK between
registered tables and asserts that the referenced table comes **before** the referencing one, unless the column is
declared `deferred()`. Morph tables must come after every registered non-reference table with a morph alias (their
possible owners). Reference tables must come after the tables in their `via` list. The two hand-ordering mistakes found in review (`contract_rates` →
`charge_types`, `payments.source_transfer_id` → `account_transfers`) would both have failed this test.

**Deferred columns break FK cycles.** `invoices.primary_file_id` → `files`, while the file points back at the invoice
through `fileable_*`. `deferred('primary_file_id')`:
- **Export:** unchanged (the column is in the CSV).
- **Import:** inserted as NULL; after all tables are in, `UPDATE invoices SET primary_file_id = <remapped files id>`.
  So the column must be nullable, which it is (drafts have no file).
- **Purge:** the first step is `UPDATE invoices SET primary_file_id = NULL WHERE profile_id = ?`; then the reversed
  order deletes `files` before `invoices` without a RESTRICT violation.

**Generated columns are derived, not listed by hand.** A test reads `information_schema.COLUMNS` (`EXTRA` containing
`GENERATED`) for every registered table and fails unless each such column is declared `generated()`. Today:
`payments.period_*`, `refunds.period_*` and `invoices.active_number`. They are exported as informational columns and
skipped on import (MySQL rejects a value for a generated column).

**Ids inside values are rewritten on import.** Remapping foreign keys and morph `*_id` columns isn't enough: some ids
sit inside other values, and left alone they point at the **source** profile's rows after a restore.

| Where | Value | Treatment | Without it |
|---|---|---|---|
| `invoices.snapshot` | `$.profile.signature_file_id` | `rewrite(json:, to: 'files')` | `InvoicePdfRenderer::signature()` does `File::query()->find($id)` without scoping: a re-rendered restored invoice draws the source profile's signature (another organization's file after a move, §9.3) |
| `settings.value` of `taxes.payment_account_ids`, `taxes.default_account_id` | account ids (array / int) | `rewrite(json:, to: 'accounts', when: key)` | the restored profile pays taxes from the source profile's accounts, and the settings fail their own validation |
| `sequences.key`, `invoices.sequence_scope_key` | `invoices:client:{id}[:year]` | `rewrite(pattern: '/client:(\d+)/', to: 'clients')` | the restored clients' counters start again at 1 and new numbers collide with restored ones; a restored invoice that is unfrozen and frozen again gets a new number (its scope key no longer matches) |

`rewrite()` takes a JSON path (`json:`) or a regex with one id group (`pattern:`), the table whose id map applies
(`to:`), and optionally the rows it applies to (`when:`, e.g. a settings key). An id missing from that map fails the
import, never keeps the old value. A test walks the profile's setting definitions and the snapshot's keys and fails on
a key ending in `_id` / `_ids` that has no `rewrite()`, so a new id-valued setting can't be forgotten. In addition,
`InvoicePdfRenderer::signature()` scopes its lookup to the files of the invoice's profile, so a stale id renders no
signature rather than another profile's.

**Export-only tables.** `exportOnly()` tables are exported (for the owner to read) and purged with the profile, but the
importer skips them, and the row-count check leaves them out:
- `alerts` — derived data: AlertSync rebuilds them (`prytax:sync-alerts`) after the import. Their `dedupe_key`,
  `action_route` (`/invoices/{id}`) and `payload` carry ids that would all need rewriting for nothing.
- `audit_logs` — a read-only record of the source profile: its `subject_id`s, `changes` (old foreign keys) and the
  ids of hard-deleted records have no meaning in the target, and rewriting history would make it untrustworthy.

**Encrypted columns are derived from the models.** A test walks every model whose table is registered (PryTax and
`Core\`) and collects attributes cast as `encrypted`, `encrypted:array`, `encrypted:collection`, `encrypted:object`,
`AsEncryptedArrayObject` or `AsEncryptedCollection`. Each must have a `decrypt()` treatment in the registry, with
`json: true` for the array/collection/object casts; otherwise the test fails. Today that yields `profiles.tax_id`,
`accounts.iban`, `accounts.account_number` and `invoices.snapshot`.

**Column policy: `all` with explicit exceptions, guarded by a column snapshot.** An *exclude* list leaks any new
sensitive column automatically; an *include* list silently drops new columns from backups. Neither fails safe, so
the registry uses `all()` plus `omit()` / `decrypt()` / `generated()` / `only()`, and CI holds a committed snapshot
`tests/Backup/registry.snapshot.json` (per table: column names + their treatment). Any migration that adds, renames
or drops a column on a registered table fails the snapshot test until the developer reviews the column (export
as-is, omit, or decrypt) and regenerates the snapshot; the regenerated snapshot changes `registry_hash`. Comparing it
with the committed one also tells the kind of change: a dropped or renamed column, a changed treatment or a removed
table is breaking and requires the `format_version` bump (with an upcaster); an added column is not (D11). The reference design's "exclude PII" rule does **not** apply here: an owner's
export must contain their own PII to be useful and restorable. Only credentials, secrets and derived values are
omitted.

**Registry hash** = `sha256(json_encode(normalize($registry->tables()), JSON_UNESCAPED_UNICODE |
JSON_UNESCAPED_SLASHES))` with keys sorted, computed when a run starts (cheap; nothing to cache). Stored on `backups`
and in the manifest; the importer keys its upcaster chain on `format_version` and logs `registry_hash` for diagnosis.

Not adopted from the reference design: a `backup_modules` / `backup_module_versions` table pair, the hourly sync
event, per-domain module listeners, and producing backups with a historical definition. With ~25 tables in one
deployable, one registry class is easier to review, and an old definition can reference columns that no longer exist.

### 4.3 The run

```
Scheduler (every 5 min, onOneServer)
  └─ DispatchDueBackups: policies WHERE enabled AND next_run_at <= now()
       → backup_runs row (queued), then RunOrganizationBackup::dispatchFor($policy->organization_id, $run->id)
         ->onQueue('backups')                       // api-core AsyncJob: the scheduler has no current organization
       → next_run_at = next slot (frequency + per-org offset)
  Manual and automatic runs create their backup_runs row the same way and dispatch the same job.

RunOrganizationBackup($runId)  (AsyncJob; middleware WithoutOverlapping("backups:{org}")->releaseAfter(60);
                                tries 3; backoff 5/15/45 min; timeout 15 min)
  0. Load the run; a run already completed/partial/failed → return. Profiles that already have a backups row
     for this run (completed or skipped) are skipped, so a retry resumes the run instead of duplicating it.
  1. backup_runs row → running
  2. Data, inside one consistent snapshot on a dedicated read connection (replica if present), opened with
     DB::connection('backup')->unprepared(…) — DB::transaction() can't open it:
       SET TRANSACTION ISOLATION LEVEL REPEATABLE READ;
       START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY;
     snapshot_at = SELECT UTC_TIMESTAMP(6)
     For each included profile not yet done in this run:
       - ProfileArchiveWriter::writeData($profile, $spec)  → local tmp .zip with CSVs + manifest (§4.4)
       - skip_unchanged and content_hash equals the last completed backup's → delete tmp,
         backups(status=skipped_unchanged); no document is fetched
       - a single profile failure → that backups row failed, continue the others; run = partial
  3. COMMIT the snapshot. Only database reads happen inside it; no object storage I/O holds it open.
  4. For each profile written in step 2:
       - ProfileArchiveWriter::addDocuments($archive)  → documents listed in step 2, each read through its
         files row's own disk
       - upload (resumable) to org/{org}/profile/{p}/yyyy/mm/{ulid}.zip; verify with the disk's
         checksum() (Flysystem; md5 on GCS and locally) against the local file
       - insert backups(status=completed, tier = the run's kind)
  5. Write runs/{run}/manifest.json; run → completed|partial|failed
  6. Notify per policy (mail to owners on failure/partial; in-app status on the Backups screen)

PruneBackups (daily, onOneServer)
  per organization × profile, among status=completed, tier=scheduled — grandfather-father-son from created_at:
    keep the newest backup of each of the last retention_daily days that have one,
         the newest of each of the last retention_weekly ISO weeks,
         the newest of each of the last retention_monthly months;
  plus manual (cap 20/org, the oldest pruned beyond it), automatic < 30 days,
  and ALWAYS the newest completed backup of the profile
  → delete object, status=pruned, pruned_at
```

**Why GFS is computed when pruning, not tagged when writing.** A tier stamped at write time ("first of the month →
monthly") goes stale: with `skip_unchanged`, one archive often stands for several days or months of unchanged data,
and an owner who changes retention or frequency expects it to apply to the backups that exist. Selecting the newest
backup per day / week / month bucket at prune time handles both, and needs no tier column for scheduled backups.

**Why `WithoutOverlapping`, not `ShouldBeUnique`.** `ShouldBeUnique` discards a dispatch while another job with the same
key is queued or running: a manual run, or the automatic backup taken when a profile is deleted, would silently never
happen. `WithoutOverlapping` keeps them apart and releases the second back onto the queue.

Data size makes one snapshot transaction per organization fine (seconds). Guardrail: `max_execution_time` per
statement on the backup connection, and the job aborts the run, not the database, if the snapshot exceeds 10 min.

### 4.4 Writing the archive

```php
final class ProfileArchiveWriter
{
    /** Step 2 of the run, inside the snapshot: CSVs, manifest, schema, README; the documents are only listed. */
    public function writeData(Profile $profile, RunSpec $spec, ConnectionInterface $db): DataArchive
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'pbk');
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::OVERWRITE);

        foreach ($this->registry->tables() as $table) {          // injected PryTax\DataLifecycle\Contracts\TableRegistry
            if (! $table->enabledFor($spec)) {
                continue;
            }

            $csv = fopen('php://temp/maxmemory:8388608', 'w+');   // spills to disk past 8 MB
            fwrite($csv, "\xEF\xBB\xBF");
            fputcsv($csv, $table->columns($db), ',', '"', '');      // '' escape = strict RFC 4180 (PHP 8.4-safe)

            $rows = 0;
            foreach ($table->query($db, $profile, $spec)->lazyById(1000, $table->qualifiedKey(), 'id') as $row) {
                fputcsv($csv, $table->serialize($row), ',', '"', '');   // decrypt, NULL→'', bool→1/0, json→string
                $rows++;
            }

            rewind($csv);
            $zip->addFromString($table->archivePath(), stream_get_contents($csv));
            $zip->setCompressionName($table->archivePath(), ZipArchive::CM_DEFLATE);
            fclose($csv);
            $this->manifest->addFile($table->archivePath(), $rows);
        }

        // exported `files` rows (morph): disk, path, archive_path, size — the manifest's documents count and bytes
        $documents = $spec->includeDocuments ? $this->documents->listFor($profile, $spec, $db) : [];
        $this->manifest->documents($documents);

        $zip->addFromString('manifest.json', $this->manifest->toJson($this->checks->compute($profile, $db, $spec)));
        $zip->addFromString('schema.json', SchemaDescriber::json());
        $zip->addFromString('README.txt', view('backups.readme')->render());
        $zip->close();

        return new DataArchive($zipPath, $this->manifest->contentHash(), $documents);
    }

    /** Step 4 of the run, after the snapshot is committed, only for a profile that wasn't skipped. */
    public function addDocuments(DataArchive $archive): ArchiveResult
    {
        $zip = new ZipArchive();
        $zip->open($archive->path);

        foreach ($archive->documents as $doc) {
            $local = $this->documents->fetchToTemp($doc);                       // through the row's own disk
            $zip->addFile($local, $doc->archivePath);
            $zip->setCompressionName($doc->archivePath, ZipArchive::CM_STORE);  // PDFs/PNGs: already compressed
        }

        $zip->close();

        return new ArchiveResult($archive->path, hash_file('sha256', $archive->path), $archive->contentHash);
    }
}
```

Notes:
- Queries go through the query builder with explicit `profile_id` / parent joins (not Eloquent), so the
  `BelongsToProfile` scope and `CurrentProfile` context are not involved and nothing leaks across profiles.
  `serialize()` decrypts with `Crypt::decryptString()` (what the `encrypted*` casts use); for `json: true` columns
  (`invoices.snapshot`) it validates the decrypted value as JSON and writes it compact. It fails the profile's
  backup on a decrypt error rather than exporting ciphertext.
- Isolation test: export profile A of an organization with profiles A and B and assert no B id appears in any CSV.
- `content_hash` covers the CSVs only. A replaced document changes its `files` row (`path`, `sha256`, `size`), so the
  hash still sees it without fetching anything.
- A document whose object is missing at fetch time (deleted after the snapshot) fails that profile's backup, which the
  next run repeats; an archive never silently lacks a file its `files.csv` lists.
- `ZipArchive` writes to the worker's local disk (MIG instance disk; archives are small), then a streamed resumable
  upload through the disk's Flysystem driver. The engine takes disk names from `config/data-lifecycle.php`, so
  development and tests use a local disk (`Storage::fake()`), and only production needs the GCS adapter.

### 4.5 Organization bundle (on demand)

`POST …/backup-runs/{run}/bundle` → `BuildOrganizationBundle` job: builds the bundle with `ZipArchive` on the
worker's local disk; for each `backups` row of the run (completed, or the last completed one for skipped profiles,
which is labelled with its own `snapshot_at` in the bundle manifest), downloads the profile archive to a temp file,
iterates its entries and re-adds them under `profiles/{folder}/` (documents with `CM_STORE`), then uploads the bundle
to `tmp/bundles/{run}/…zip`. No `maennchen/zipstream-php`: one run's archives of even an accountant's 50 profiles with
5 years of documents stay around 1 GB (§3.4), which a worker's disk holds, and ext-zip is already installed. Then a notification "Архів організації
готовий" and a download link (signed URL, 10 min, regenerated on each click for 24 h).

### 4.6 Download with a password (optional)

`POST …/backups/{backup}/download { password? }`. Without a password → signed URL to the stored object. With a password
→ a queued job re-packs the archive into `tmp/` with AES-256 entry encryption (`ZipArchive::setEncryptionName(…,
ZipArchive::EM_AES_256, $password)`), the password is never stored, and the link is valid 10 min. Stored archives are
not password-protected: scheduled runs would have to keep the password, which defeats the purpose.

### 4.7 API (organization-level; not under `profiles/{profile}`)

```
GET    /api/organization/backup-policy                     owner, accountant (read)
PUT    /api/organization/backup-policy                     owner
GET    /api/organization/backups?profile_id=&tier=&cursor  owner, accountant
POST   /api/organization/backup-runs                       owner, accountant   {profiles: all|[ids]} → 202 {run_id}
GET    /api/organization/backup-runs/{run}                 status + per-profile results
POST   /api/organization/backups/{backup}/download         owner, accountant   {password?} → {url, expires_at} | 202
POST   /api/organization/backup-runs/{run}/bundle          owner, accountant   → 202
GET    /api/organization/backup-runs/{run}/bundle          → {status, url?, expires_at?}
POST   /api/profiles/{profile}/exports                     owner, accountant   {from, to} → 202 (period export)
GET    /api/profiles/{profile}/exports/{export}            → {status, url?}
DELETE /api/organization/backups/{backup}                  owner; manual tier only
```

Scoping: `backups` / `backup_runs` use api-core's organization scope (an organization bundle has no single profile).
Policies: `BackupPolicyPolicy`, `BackupPolicy@download` (owner|accountant, same organization). Rate limits:
manual runs 5/day/org, downloads 30/hour/user. Every download → `backup_downloads` + an `audit_logs` entry
(`AuditLog::record('backup_downloaded', $backup)`).

### 4.8 UI (Ще → Організація → Резервні копії; owner and accountant)

- Status card: "Остання копія: сьогодні 02:37 · 3 ФОП · 4,2 МБ" · next scheduled run · last failure if any.
- Policy (owner only): frequency, weekday/day, retention preset (Стандарт 7/4/12 · Мінімум 3/0/3 · Для архіву
  7/4/36) with custom numbers, include documents, include audit log, profiles.
- Buttons: "Створити копію зараз", "Завантажити все (ZIP)" (the bundle of the latest run).
- List by run (newest first): date · kind chip (scheduled / manual / automatic) · per-ФОП rows (size, status: completed / без змін / помилка) ·
  download per ФОП.
- Period export lives on the ФОП's Облік screen ("Експорт за період"), not here.

---

## 5. Restore

### 5.1 Phase 1 — ops tool

```
php artisan prytax:backup:import {archive} --organization={id} [--dry-run]
```

1. Validate `manifest.json` (`format`, `format_version` ≤ supported, `restorable = true`), file sha256s, `schema.json`.
   Older `format_version`s go through upcasters (`BackupUpcaster_v1_to_v2`, …).
2. **Reference rows are matched first, never inserted** (`matchOn()`): `tax_rules` by `effective_from`,
   `exchange_rates` by `(currency, rate_date)` (both unique), `users` by e-mail among the target organization's users.
   A missing tax rule or exchange rate fails the import (rules come with migrations; a rate can be fetched first); a
   user without a match maps to NULL (`created_by_user_id` is nullable), so an import never creates accounts.
3. One DB transaction: insert tables in registry order with **id remapping** (`old_id → new_id` maps per table, FK
   columns rewritten from the maps, reference columns from step 2); `profiles` becomes a **new** profile in the target
   organization; encrypted columns re-encrypted with the current key; `tax_id_hash` recomputed; generated columns
   skipped; morph `*_id` columns remapped through their owner's id map; `rewrite()` columns rewritten (§4.2 "Ids inside
   values"); deferred columns (`invoices.primary_file_id`) set in a final pass; `sequences` restored, with their keys
   rewritten, so numbering continues per client; `exportOnly()` tables (`alerts`, `audit_logs`) skipped; documents
   uploaded to the files disk and `files.disk` / `files.path` rewritten. After the commit, AlertSync runs for the new
   profile.
4. Verification inside the transaction: row counts = manifest (export-only tables excluded); recompute `checks`
   (income by quarter, invoice totals, tax paid) = manifest; otherwise roll back.
5. Constraint: `UNIQUE(organization_id, tax_id_hash)` means the same ФОП cannot be imported into an organization
   where it still exists (soft-deleted rows count too). Import into another organization, or purge the original
   first. The tool reports this before writing.

### 5.2 Continuous verification

- CI: fixture profile → export → import into a fresh organization → assert every `TaxLiabilityCalculator` result and
  `TaxReconciler` balance, every holding, and every invoice PDF hash (a re-render draws the restored profile's own
  signature) is identical; the restored settings name the restored accounts; a new invoice of a restored client
  continues its counter.
- Weekly `VerifyRandomBackup` job: pick a random completed archive, import with `--dry-run` into a scratch
  schema, record pass/fail in `backups.verified_at`; a failure alerts ops.

### 5.3 Later — self-service

"Відновити з архіву як новий ФОП" (owner): upload or pick an archive → dry-run report (counts, checks, conflicts) →
confirm → new profile. In-place restore is never offered.

**Open decision: restoring into the same organization.** `UNIQUE(organization_id, tax_id_hash)` counts soft-deleted
profiles, so while the original exists (even deleted, during its 30-day grace) its archive can't be restored next to
it. That is the usual case ("my data got messed up, give me last week's"), and comparing the two side by side is
impossible as the schema stands. Options:
1. **Keep the constraint**: a same-organization restore replaces the original — the original is purged first
   (immediately, after its own final backup), then the archive is imported. Simple, but no side-by-side comparison.
2. **Relax it to one active profile per РНОКПП**: a stored generated `active_tax_id_hash` (NULL when the profile is
   soft-deleted or archived), unique per organization — the pattern `invoices.active_number` already uses. The restored
   copy is imported archived (or the original is archived on confirm), so both exist and only one is active.
   Everything that assumes one profile per РНОКПП (lookups by `tax_id_hash`, duplicates checks) must then use the
   active one.

---

## 6. Security & privacy checklist

- Archives contain РНОКПП, IBANs, client addresses: private buckets only, no personal data in object names or URLs,
  signed URLs ≤ 10 min, no links in e-mails (e-mail says "готово, відкрийте PryTax").
- Owner/accountant only; viewer excluded; every download audited (user, IP, UA, time).
- Existing archives are protected by the bucket's 30-day soft delete, which the app's identity can't bypass, and by
  the DR copy in another project with Bucket Lock (§3.3); the app itself can delete, as owners and retention need it to.
- Organization, profile and user deletion follow §9: lock first, final export, grace period, FK-ordered purge
  including documents and backups. Keeping records 1 095 days is the ФОП's obligation; the final export is how they
  meet it.
- No secrets in archives (tokens are excluded; the profile's `settings` rows hold no credentials — if a setting
  ever stores one, it gets an `omit()` or a dedicated exclusion, enforced by review of the column snapshot).
- Logs: run ids and sizes only, never row content.

---

## 7. Build order

1. **Layer A** before the first real user: Cloud SQL backups + PITR + deletion protection + final backup;
   nightly independent dump to the DR project; documents bucket soft delete; `APP_KEY` in Secret Manager; one
   restore drill.
2. Module skeleton (`PryTax\DataLifecycle\` + the dependency test, §10) + `PryTaxTableRegistry` (incl.
   `ApiCoreTables`) + `ProfileArchiveWriter` + registry completeness test + column snapshot + isolation test + CI
   round-trip with `prytax:backup:import`. Before any real data: `Relation::requireMorphMap()`, the alias-collision
   guard, the audit tagger. Package to approve: the GCS Flysystem adapter (only production needs it).
3. Manual backup + download (profile archive) — the phase-1 user feature.
4. `backup_policies` + scheduler + `skip_unchanged` + GFS pruner + failure e-mails.
5. Organization bundle on demand; password-protected download.
6. Period exports.
7. Weekly random verification; self-service restore as a new ФОП.

Retention (§9) runs in parallel: the technical rolling cutoffs (§9.2, "global" rows) and the user-removal flow
ship with phase 1; the organization / profile deletion runs ship with step 3 (they need the final export).

## 8. Tests (PHPUnit)

Registry completeness (every `profile_id` table covered) · profile isolation of exports · CSV conventions (BOM,
RFC 4180 quoting of commas/quotes/newlines in descriptions, NULL vs `""`, decimals unformatted, Cyrillic
round-trip) · decrypted columns exported and `tax_id_hash` omitted · consistent snapshot (a concurrent write during
the run is either fully in or fully out) · `skip_unchanged` by `content_hash`, with no document fetched for a skipped
profile · GFS selection at prune time across day/week/month boundaries, and after a retention change · pruning keeps
the newest completed backup and respects caps · a manual run dispatched during a scheduled one runs after it (not
dropped) · a retried run doesn't duplicate the profiles it already finished · signed URL only for authorized roles;
viewer 403; cross-organization 404 · bundle folder layout and manifest · period export closure (cross-period invoice,
transfer allocations referencing out-of-scope payments) · import round-trip equality and rollback on a checks mismatch ·
upcaster from `format_version` 1 · column snapshot fails on an unreviewed column · a breaking registry change without a
`format_version` bump fails CI, an added column doesn't need one.

Retention (§9): every table is assigned to a family (completeness test, like the backup registry) · purge order
deletes a fully populated fixture profile (incl. soft-deleted invoices, refunds, allocations, `fx_gain` rows) without
an FK violation and leaves zero rows with its `profile_id` · purge bypasses observers (locked invoices) and writes
no `audit_logs` rows · documents of the purged profile are gone from the files bucket, other profiles' untouched ·
the claim is atomic (two workers, one job, one execution) · re-running a failed job is idempotent · cancel during grace
restores access · user removal frees `UNIQUE(organization_id, email)` and keeps `created_by_user_id` references ·
global cutoffs delete only rows past the cutoff.

Registry vs schema (v1.3): FK order from `information_schema` (referenced before referencing unless deferred; morph
after owners; references after their `via` tables) · `*_type`/`*_id` tables covered by the completeness test ·
encrypted casts ⇔ `decrypt()` treatments (incl. `invoices.snapshot` as JSON) · morph selection exports a profile's
signature and invoice files but not another profile's or an organization-level setting · a hard-deleted owner's morph
rows are not exported, except audit entries · deferred `primary_file_id` round-trips through import and is nulled
before purge · `requireMorphMap` is on (a model without an alias throws in tests) · the merged morph map is
one-to-one and registering a colliding alias throws at boot.

Audit tagging (v1.4): an entry for a `[P]` record, a child record (`invoice_items`) and a profile-owned `settings`
row gets `metadata.profile_id` = current profile · creating a profile tags with the subject's id · updating the user
or the organization during a profile request stays untagged · existing `metadata` keys (IP, browser) are kept · the
audit entry of a hard-deleted tax payment is exported and purged with its profile · untagged entries go only with the
organization purge · `audit.retention_days` is null · documents are read through each `files` row's own disk.

Restore and registry (v1.5): every generated column of a registered table (`information_schema`) is `generated()` ·
every `_id` / `_ids` key of the profile's setting definitions and of the snapshot has a `rewrite()` · after an import,
the snapshot's `signature_file_id`, the tax payment account settings and the sequence keys point at the restored rows ·
a re-rendered restored invoice draws its own profile's signature, and a stale id renders none · reference rows are
matched (`tax_rules`, `exchange_rates`, `users` by e-mail), an import creates no user, and a missing rule fails it ·
`alerts` and `audit_logs` are exported but not imported, and AlertSync rebuilds the alerts · a `settings` row owned by
a user is in no ФОП's archive and survives a profile purge · api-core's `notifications` is classified.

Module (§10–11): the three dependency rules · every `Core\` table in the database is classified (registered, excluded or
assigned a retention family) · the column snapshot fails after a simulated api-core migration that adds a column to
`users` · the engine's own tests run with an in-memory fake registry (no PryTax tables), proving it is generic.

---

## 9. Data retention & deletion

Adapted from the reference "Data Retention" guide. Kept: families, actions, runs → jobs with `due_at`, atomic
claim, per-family results, lock-first erasure, FK ordering incl. soft-deleted rows, bulk queries instead of
hydration. Not adopted: admin-editable policies with region matching and `due_at` recalculation on policy update
(policies are config, changed by deploy), the JSON exceptions engine (marked "planned" there; arbitrary column
filters on deletion queries are a footgun), and the event-per-handler indirection (a Laravel job per family is
enough).

### 9.1 Principles

- **Accounting data is never deleted on a timer** while its organization and profile exist. Its owner deletes it
  by deleting the profile or the organization; the platform only purges after an explicit offboarding.
- **Lock first, purge later.** Offboarding immediately blocks access and stops schedules, then purges after a grace
  period during which it can be cancelled.
- **Offer the export before deleting.** Every profile or organization deletion produces a final backup (§2.2
  "automatic") that the owner can download during the grace period.
- **One registry, three uses.** `TableRegistry::tables()` order = FK-safe insert order for import; **reversed** = FK-safe
  delete order for purge. A table added to the registry is automatically exported, imported and purged.
- **Erasure has a documented horizon.** After a purge, data still exists in GCS soft delete (30 days) and in Layer A
  DR dumps (Bucket Lock 35 days). The privacy policy says "removed from backups within 35 days of deletion".

### 9.2 Families (config `config/retention.php`)

| Family | PryTax data | Subject | Action | When |
|---|---|---|---|---|
| `credentials` | user password hash, `personal_access_tokens`, sessions, password reset tokens | user | hard delete | immediately on user removal; expired tokens/resets — rolling daily |
| `access` | role assignments, pending invitations | user | hard delete | immediately on user removal |
| `user_pii` | `users.name`, `users.email` | user | **anonymize** (`name` → `Видалений користувач`, `email` → `deleted+{id}@invalid`, frees the unique index) | immediately on user removal; row kept so `created_by_user_id` and `audit_logs` causers still resolve |
| `accounting` | every `[P]` / `[child]` table of the registry (profile, settings, accounts, clients, contracts, invoices, payments, refunds, transfers, tax_* …) | profile | hard delete (registry reversed) | profile or organization deletion + grace 30 days |
| `documents` | objects in `prytax-files` referenced by `files` rows of the profile's records (invoice PDFs, uploads, the profile's signature) | profile | hard delete | same run as `accounting`, after its DB delete commits |
| `backups` | the profile's archives in `prytax-backups`, `backups` rows, its `backup_downloads` | profile | hard delete | same run, last job (so the final export stays downloadable for the whole grace period) |
| `audit` | api-core `audit_logs` rows tagged `metadata.profile_id` (incl. entries of hard-deleted records); untagged entries of the organization (`organization_id`) | profile / organization | hard delete | with `accounting`, or at organization purge for untagged ones; while they exist — kept (audit trail of financial edits). api-core's `audit.retention_days` stays off |
| `communication` | `alerts` (+ `alert_reads` CASCADE); api-core's `notifications` (per user) | profile / user / global | hard delete | alerts with the profile, resolved ones older than 180 days rolling daily (`Prunable` on `Alert`); a user's notifications with the user's hard delete at organization purge |
| `technical` | `idempotency_keys` (24 h; already pruned by `model:prune` in `routes/console.php`), `failed_jobs` (30 d, `queue:prune-failed`), job batches (7 d), `backup_runs` without remaining backups (90 d), `tmp/` objects (bucket lifecycle 1 d), orphaned uploads never attached to an invoice (7 d), `files` / `settings` / `sequences` rows whose morph owner no longer exists (7 d; the file objects too) | global | hard delete | rolling, daily |
| `exchange_rates`, `tax_rules`, `holidays` | global reference data | — | **never** | referenced by snapshots |

Every table belongs to exactly one family; a completeness test enforces it (same approach as the registry). In a
profile purge, `audit` and `communication` rows are removed by the `accounting` job (they are registry tables); the
separate families exist for their rolling rules while the profile lives.

### 9.3 Triggers and flows

| Trigger | Immediate (lock) | Grace | Purge jobs |
|---|---|---|---|
| **User removed** from an organization (owner action) | api-core's own `DELETE /api/users/{id}`: soft delete, the account can't sign in, its tokens stop working; roles removed | none | `credentials`, `access`, `user_pii` (after api-core's delete, never instead of it) |
| **Profile deleted** (soft delete exists) | profile hidden, excluded from schedules and notifications; automatic final backup | 30 days; owner can restore during grace | `accounting` (all registry tables except the `profiles` row, so `alerts`, `settings`, `sequences`, `files` rows and `audit_logs` go with it) → `documents` → `backups` (archives, `backups` / `backup_downloads` rows, **then** the `profiles` row, since `backups.profile_id` is RESTRICT) |
| **Organization deleted** (owner action, re-auth required) | api-core's soft delete of the organization, which soft-deletes its users with it (restoring it restores them); tokens revoked, backup policy disabled; final organization bundle built and e-mailed as "ready" (no link in the e-mail) | 30 days; cancel restores access | per profile as above, then the remaining `audit_logs WHERE organization_id = ?` (user-, organization- and auth-level entries), then the organization's users (`credentials`, `access`, `user_pii` → hard delete of user rows), then api-core organization row |
| **Profile moved to another organization** (e.g. a ФОП leaves the accountant) | — | — | export → `prytax:backup:import` into the target organization (§5) → then "profile deleted" in the source |

There is no "delete my data now" path without grace in phase 1. If one is needed (a legal erasure request), ops runs the
same run with `immediate: true` (all `due_at = now`) after the lock step, as the reference design does.

### 9.4 Tables

```
data_retention_runs   id ULID, subject_type(enum user|profile|organization|global), subject_id NULL,
                      trigger(enum user_removed|profile_deleted|organization_deleted|rolling|manual),
                      immediate BOOL, requested_by_user_id NULL, status(enum pending|running|completed|failed|cancelled),
                      created_at, cancelled_at NULL, completed_at NULL
                      INDEX(subject_type, subject_id, status)
data_retention_jobs   id ULID, run_id → data_retention_runs (CASCADE), family VARCHAR(32), action(enum hard_delete|anonymize),
                      sequence TINYINT,                       -- order inside the run (accounting before documents before backups)
                      due_at TIMESTAMP, status(enum pending|dispatched|completed|failed|cancelled),
                      attempts TINYINT, records_processed INT, records_deleted INT, records_anonymized INT,
                      objects_deleted INT, error TEXT NULL, started_at NULL, finished_at NULL
                      INDEX(status, due_at)
```

Run status is derived from its jobs (completed when all non-cancelled jobs are completed; failed if any failed). One
active run per subject: a new deletion request for a subject with a pending run returns 409. Cancelling during grace
sets all `pending` jobs to `cancelled` and reverses the lock.

### 9.5 Execution

```
Scheduler (every 5 min, onOneServer) → ExecuteDueRetentionJobs
  SELECT … FROM data_retention_jobs WHERE status = 'pending' AND due_at <= now()
    AND NOT EXISTS (earlier-sequence job of the same run not completed)
  per job: claim atomically
    UPDATE data_retention_jobs SET status = 'dispatched', started_at = now(), attempts = attempts + 1
    WHERE id = ? AND status = 'pending'          -- affected rows = 1 → we own it
  → RunRetentionJob::dispatch($jobId)->onQueue('retention')

Daily (onOneServer) → CreateGlobalRetentionRun: one run with the technical / communication rolling jobs, due now.
  Rolling cutoffs that have a model use Laravel's own pruning instead (Prunable / MassPrunable + `model:prune`, as
  `routes/console.php` already does for AccessToken, AuditLog and IdempotencyKey; `queue:prune-failed`,
  `queue:prune-batches`); the global run keeps only what has no model (orphaned morph rows, `tmp/` objects).

RunRetentionJob → FamilyHandler::handle(job): RetentionResult
  - handlers never swallow exceptions; the job wrapper records failed + error and leaves the run failed
    (ops re-queues: status back to pending; handlers are idempotent)
```

**`AccountingFamilyHandler` (profile)** — the purge:

```php
DB::transaction(function () use ($profileId, $result) {
    DB::statement('SET SESSION innodb_lock_wait_timeout = 30');
    foreach ($this->registry->tables() as $table) {               // 1. break cycles: null deferred columns
        $table->nullDeferredQuery(DB::connection(), $profileId)?->update();   // invoices.primary_file_id = NULL
    }
    foreach (array_reverse($this->registry->tables()) as $table) { // 2. children before parents, morph before owners
        if ($table->isReference() || $table->isRoot()) {
            continue;                                   // global data is never purged per profile; the profiles row
        }                                               // is deleted by the last (backups) job
        $result->deleted += $table->deleteQuery(DB::connection(), $profileId)   // query builder, withTrashed,
            ->delete();                                                      // chunked by id for big tables
    }
});
```

- Query builder, not Eloquent: `InvoiceObserver` would throw on locked invoices and `Audited` would write
  thousands of deletion entries. Purge is the one place where observers are bypassed on purpose.
- Includes soft-deleted rows (`invoices.deleted_at`, `profiles.deleted_at`): they still hold RESTRICT FKs.
- Reversed order: `transfer_allocations` and `refunds` (RESTRICT on payments) go before `payments`; `payments`
  (`source_transfer_id`) before `account_transfers`; `contract_rates` before `charge_types`; the morph tables before all
  their owners. The insert-order test (§4.2) guarantees it, and the fixture purge proves it.
- Before the transaction: collect `disk` + `path` of every `files` row selected by the morph strategy (invoice
  documents, the profile's signature, any other owner's files) into the job's payload,
  so `DocumentsFamilyHandler` can delete the objects after commit. It deletes by stored path list (idempotent:
  missing objects count as done), never by prefix listing across tenants.

**`UserPiiHandler`** — runs **after** api-core's user delete, never instead of it. api-core's `DELETE /api/users/{id}`
soft-deletes the user and stops its tokens; its permanent delete (`?force=1`) also hands the accounts linked to the user
over to the oldest of them (`main_account_id`), which a raw `DELETE` or `UPDATE` would skip. The handler then
anonymizes: `UPDATE users SET name = 'Видалений користувач', email = CONCAT('deleted+', id, '@invalid'),
password = <Hash::make(random 64 bytes)>, remember_token = NULL WHERE id = ? AND deleted_at IS NOT NULL` —
`users.password` is NOT NULL in api-core, so it gets an unusable hash, not NULL. FKs `created_by_user_id` stay valid;
the UI shows the anonymized name. An anonymized e-mail can no longer be re-invited into the old row (api-core restores
a deleted user when its e-mail is invited again); inviting the person creates a new user, which is intended.

### 9.6 Ops commands

```
php artisan prytax:retention:execute            # what the scheduler runs; safe to run by hand
php artisan prytax:retention:status {run?}      # runs, jobs, counts, errors
php artisan prytax:retention:retry {job}        # failed → pending
php artisan prytax:retention:cancel {run}       # during grace; reverses the lock
```

No admin UI in phase 1. The owner sees deletion state in the app ("ФОП буде видалено 26.11.2026 · Скасувати
видалення · Завантажити архів").

---

## 10. Module structure & boundaries

### 10.1 Why a module

Backups and retention are a cross-cutting subsystem with their own tables, queue, scheduler entries, GCS
dependency, zip/CSV writers, routes and settings screen. The accounting code never needs to know they exist. As one
module they have one boundary, one test suite and one place for the completeness tests that guard the whole app
(every `profile_id` table registered, every table in a retention family, every column reviewed).

**Not a composer package yet** — one consumer (PryTax), and the right abstractions become clear only with a second
one. A namespace folder inside the app gives the same separation without path-repository setup; with the boundary
enforced by tests, moving it into a package later is a mechanical move (§11.2).

### 10.2 Layout

```
app/
├── DataLifecycle/                       PryTax\DataLifecycle\        ← generic engine (extractable as-is)
│   ├── Contracts/
│   │   ├── TableRegistry.php            tables(): list<TableSpec>
│   │   ├── TenantRoot.php               table/key of the tenant root, archive folder name, label
│   │   ├── ArchiveChecks.php            compute(tenantId, ConnectionInterface, RunSpec): array
│   │   └── RetentionFamilyHandler.php   family(): string; handle(RetentionJob): RetentionResult
│   ├── Registry/                        TableSpec, ColumnPolicy, RegistryHasher, ColumnSnapshot
│   ├── Backup/
│   │   ├── Internal/                    ArchiveWriter, CsvSerializer, ManifestBuilder, ArchiveStore (any disk),
│   │   │                                BundleBuilder, Pruner, ArchiveImporter, upcasters
│   │   ├── Jobs/                        RunOrganizationBackup, BuildOrganizationBundle, PruneBackups, VerifyRandomBackup
│   │   ├── Models/                      BackupPolicy, BackupRun, Backup, BackupDownload
│   │   ├── Http/                        controllers, requests, resources, routes.php
│   │   └── Events/                      BackupCompleted, BackupFailed, BundleReady
│   ├── Retention/
│   │   ├── Internal/                    RunFactory, JobClaimer, ExecuteDueJobs, generic rolling handlers
│   │   ├── Jobs/                        RunRetentionJob, CreateGlobalRetentionRun
│   │   ├── Models/                      RetentionRun, RetentionJob
│   │   └── Events/                      RetentionRunCompleted, RetentionJobFailed
│   ├── Console/                         prytax:backup:*, prytax:retention:*
│   ├── database/migrations/
│   ├── config/data-lifecycle.php        buckets, queues, caps, retention families and periods
│   └── DataLifecycleServiceProvider.php routes, migrations, schedule, config, commands
├── Backups/                             PryTax\Backups\              ← PryTax definitions
│   ├── PryTaxTableRegistry.php          implements TableRegistry (§4.2)
│   ├── ApiCoreTables.php                Core\ tables PryTax backs up (§11.1)
│   ├── ProfileTenantRoot.php            implements TenantRoot (profiles, slug_p{id})
│   └── AccountingArchiveChecks.php      implements ArchiveChecks (income by quarter, invoice totals, tax paid)
├── Retention/                           PryTax\Retention\            ← PryTax handlers
│   ├── AccountingFamilyHandler.php      reversed-registry purge (§9.5)
│   ├── DocumentsFamilyHandler.php
│   ├── ProfileFinalizeHandler.php       backups of the profile, then the profiles row
│   └── UserRemovalHandler.php           credentials + access + user_pii on Core\ tables (§11.1)
└── Providers/DataLifecycleBindings.php  binds the contracts to PryTax\Backups / PryTax\Retention classes,
                                         tags the family handlers, listens to module events (notifications, mail)
```

The `Internal/` folders are the module's private implementation; everything outside the module talks to it only
through `Contracts/`, `Events/`, its HTTP routes and its console commands.

### 10.3 Dependency rules (a PHPUnit test, in the normal suite)

The project uses PHPUnit 12, without Pest or PHPStan, so the rules are one feature test,
`tests/Feature/DataLifecycle/ModuleBoundariesTest.php`. It reads every PHP file of a namespace with
`token_get_all()`, collects the classes it uses (`use` imports and fully qualified names), and asserts:

| Rule | Classes of | Must not use |
|---|---|---|
| The engine doesn't depend on the app | `PryTax\DataLifecycle\*` | `PryTax\*` outside `PryTax\DataLifecycle\` |
| The app talks to the module only through contracts, events, models, jobs and routes | `PryTax\*` outside the module | `PryTax\DataLifecycle\Backup\Internal\*`, `PryTax\DataLifecycle\Retention\Internal\*` |
| Retention may use backup, never the reverse | `PryTax\DataLifecycle\Backup\*` | `PryTax\DataLifecycle\Retention\*` |

A failure names the file and the class it uses. No package is needed; Pest's `arch()` (approval needed) would only be
a nicer syntax for the same check.

**The engine may use api-core.** CLAUDE.md requires endpoints to be built from api-core's base classes (`BaseModel`,
`BaseQuery`, `CrudRepository`, `CrudPolicy`, the CRUD requests, `BaseResource`, `CrudController`), `backups` and
`backup_runs` are organization-scoped (`BelongsToOrganization`, §4.7), and scheduled jobs need api-core's
`AsyncJob::dispatchFor()` to run in an organization. The extracted package requires api-core anyway (§11.2), so a
rule against it would only force the engine to rebuild what api-core gives.

Direction: the app depends on the module (it implements `TableRegistry`, `ArchiveChecks`, the family handlers); the
module never depends on the app. Both depend on api-core. Retention uses Backup (final export before purge); Backup
never knows about Retention. Deptrac is not used: with one module and three rules, a test is enough and runs without an
extra CI step. Revisit when there are several modules with a layering diagram to protect.

### 10.4 What the engine needs from the app (and nothing more)

| Contract | PryTax implementation | Used for |
|---|---|---|
| `TableRegistry` | `PryTax\Backups\PryTaxTableRegistry` | export order, import order, purge order (reversed), registry hash, column snapshot |
| `TenantRoot` | `PryTax\Backups\ProfileTenantRoot` (`profiles.id`, folder `slug(full_name_en)_p{id}`) | scoping, archive and folder names; another app would point it at `organizations` |
| `ArchiveChecks` | `PryTax\Backups\AccountingArchiveChecks` | manifest `checks`, import verification |
| `RetentionFamilyHandler` (tagged, many) | `PryTax\Retention\*` | the `accounting`, `documents`, `profile_finalize`, `user_removal` families; the engine ships the generic `technical` and `communication` rolling handlers itself |

Anything beyond these four (pluggable storage drivers, formats other than CSV, per-profile policy overrides) waits
for a real need.

---

## 11. api-core (`Core\`)

### 11.1 Phase 1: api-core is not changed

api-core provides lightweight, universal scaffolding (organizations, users, Sanctum, roles, HTTP filtering) to every
app. Backup code there would force GCS, zip and queue-heavy dependencies and four extra migrations on apps that
don't need them. So **api-core has no backup or retention code**; PryTax handles the few api-core tables itself:

```php
namespace PryTax\Backups;

use PryTax\DataLifecycle\Registry\TableSpec;

/** api-core (Core\) tables PryTax backs up. Covered by the column snapshot like any PryTax table. */
final class ApiCoreTables
{
    /**
     * Tables that reach their owner through a type/id pair. No profile_id; selected through the exported owners.
     * Placed after every owner in the registry.
     *
     * @return list<TableSpec>
     */
    public static function owned(): array
    {
        return [
            TableSpec::morph('settings', morph: 'model')->file('core/settings')
                ->rewrite('value', json: '$', to: 'accounts',
                    when: ['key' => ['taxes.payment_account_ids', 'taxes.default_account_id']]),   // §4.2
            TableSpec::morph('sequences', morph: 'model')->file('core/sequences')
                ->rewrite('key', pattern: '/client:(\d+)/', to: 'clients'),                         // invoices:client:{id}[:year]
            TableSpec::morph('files', morph: 'fileable')->file('core/files')
                ->documents(disk: 'disk', path: 'path'),   // adds archive_path; reads each object through its row's disk
            TableSpec::profileJson('audit_logs', column: 'metadata', path: '$.profile_id')->file('core/audit_logs')
                ->optional('include_audit_log')
                ->exportOnly(),                            // history of the source profile, never imported
        ];
    }

    /** @return list<TableSpec> — last in the registry */
    public static function references(): array
    {
        return [
            // reference/users.csv — users referenced by created_by_user_id and by audit_logs.causer_* (morph)
            TableSpec::reference('users', via: ['*.created_by_user_id'])
                ->viaMorph('audit_logs', morph: 'causer', alias: 'models.users')
                ->only('id', 'name', 'email')
                ->matchOn('email', within: 'organization'),   // import: the target organization's user, else NULL
        ];
    }

    public const EXCLUDED = [
        'notifications'          => 'per-user notifications (notifiable_*), not profile data',
        'personal_access_tokens' => 'credentials — never exported',
        'password_reset_tokens'  => 'credentials — never exported',
        'sessions'               => 'credentials — never exported',
        'roles'                  => 'global configuration',
        'permissions'            => 'global configuration',
        'role_has_permissions'   => 'global configuration',
        'model_has_roles'        => 'organization-level access, not profile data',
        'model_has_permissions'  => 'organization-level access, not profile data',
    ];
}
```

- Confirmed against api-core's code and migrations:
  - Morph columns: `settings.model_*`, `sequences.model_*`, `files.fileable_*`, `audit_logs.subject_*` and
    `audit_logs.causer_*` (the actor, taken from `Auth::user()` unless a causer is passed; for a user it is
    `models.users` + the user's id). There is no impersonator column on `audit_logs`; only `personal_access_tokens`
    has `impersonator_id`.
  - `files` columns: `fileable_type`, `fileable_id`, `collection`, `name`, `mime_type`, `size`, `sha256`, `metadata`,
    `disk` (string 64) and `path` (string 1024). `disk` and `path` are hidden from JSON, which doesn't affect the
    exporter (query builder). Objects are read and deleted through **each row's own disk** (`File::storage()` /
    `contents()`), never a fixed bucket.
  - `audit_logs` columns and the `metadata.profile_id` decision: §4.2 "Audit entries".
- `organizations`: the organization bundle's `manifest.json` carries `organization.id` and `name`; no CSV.
- Retention families for `Core\` tables (§9.2): `users` → `user_pii` (anonymize) and, at organization purge, hard
  delete; `personal_access_tokens`, `password_reset_tokens`, `sessions` → `credentials`; `model_has_roles`,
  invitations (if api-core has them) → `access`; `organizations` → deleted last in an organization purge;
  `roles`, `permissions`, `role_has_permissions` → never.
- `PryTax\Retention\UserRemovalHandler` builds on api-core's own deletes instead of replacing them: a user is removed
  through api-core's user delete (soft delete, tokens stopped; a permanent delete also hands linked accounts over), an
  organization through api-core's organization delete (which soft-deletes its users with it). The handler adds only
  what api-core doesn't do: the anonymization of `user_pii` (§9.5) and, at organization purge, the hard delete of the
  remaining rows through the query builder. Names as in api-core: `Core\Models\User`, `Core\Models\Organization`,
  spatie's tables.
- **Safety net:** the column snapshot (§4.2) lists every column of every registered or excluded `Core\` table with
  its treatment, and the completeness tests classify every `Core\` table. Upgrading api-core with a migration that
  adds a column or a table fails PryTax's CI until someone decides how it is exported and retained. This replaces
  api-core registering its own definitions.

### 11.2 Later: extraction to a sibling package

When a second app (e.g. the restaurant menu app) needs backups:

1. Move `app/DataLifecycle/` into its own repository as **`api-data-lifecycle`**, namespace `Core\DataLifecycle\`
   or a neutral vendor namespace, requiring api-core. It never goes **into** api-core (same reasons as §11.1).
2. Move `ApiCoreTables` and `UserRemovalHandler` **into the lifecycle package** — it depends on api-core and
   knows its schema, so it ships and auto-registers the api-core definitions for every consumer. (Since the engine may
   use api-core, §10.3, they may move into `PryTax\DataLifecycle\` earlier, whenever that is convenient.)
   Alternatives considered and rejected: api-core registering them itself (needs a dependency back on the lifecycle
   package → cycle, or `interface_exists()` checks for a package it doesn't require); a separate zero-dependency
   contracts package (correct, but three packages for one feature).
3. Each app keeps only its own registry, tenant root, checks and domain handlers. PryTax's `TenantRoot` is the
   profile; an app without profiles points it at `organizations`.
4. The second consumer is the moment to re-check the four contracts (§10.4). Adjust them then, not before.

---

## 12. Local development with api-core

- **Committed `composer.json`:** api-core as a **git (VCS) repository with tagged versions**
  (`"andriichello/api-core": "^1.4"`), so CI and production builds are reproducible.
- **Never commit a path repository** pointing at `../api-core`: it breaks every build where that sibling folder
  doesn't exist. **Today's `composer.json` does exactly that** (a `path` repository `../api-core`, symlinked, with
  `"andriichello/api-core": "dev-dev"`): fix it before the first CI or production build, independently of backups.
- **Local linking:** a git-ignored override, either `COMPOSER=composer.local.json composer update` with a local file
  that adds `{"type": "path", "url": "../api-core", "options": {"symlink": true}}`, or `wikimedia/composer-merge-plugin`
  merging a git-ignored `composer.local.json`.
- **Branch alias in api-core** (`"extra": {"branch-alias": {"dev-main": "1.x-dev"}}`) so `^1.4` constraints still
  resolve against the local checkout.
- **Tag api-core whenever PryTax starts depending on something new in it**, even 1.4.1 → 1.4.2. Otherwise
  production ends up built against `dev-main` and silently picks up whatever is on main that day.
- The DataLifecycle module needs none of this while it is a folder in the app; after extraction (§11.2), the lifecycle
  package gets the same treatment as api-core.

Sources: [Cloud SQL backups overview](https://docs.cloud.google.com/sql/docs/mysql/backup-recovery/backups) ·
[Cloud Storage soft delete](https://docs.cloud.google.com/storage/docs/soft-delete)
