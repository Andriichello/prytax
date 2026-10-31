# PryTax — Backups, data safety & retention (design v1.9)

Companion to `docs/specs/design.md` (v5.2) and `docs/specs/builds.md` (Docker, SQLite and the desktop app). Covers (1)
protecting the platform's data against loss, (2) per-organization backups / exports that owners configure and download,
and (3) data retention and deletion (§9). Stack: Laravel 13, PHP 8.5, MySQL 8 or SQLite (`builds.md` §3), PHPUnit 12,
queue workers, scheduler with `->onOneServer()`, Google Cloud Storage (private buckets) in production and a local disk
elsewhere (§3).

### Changes in v1.9 (a new organization from archives, bundles restored)

Built on 2026-10-31 (branch `feat/organization-from-backups`), at the owner's request: a new organization lands on
Новий ФОП, which offered no way to start from a backup. What changed since v1.8:
- **Новий ФОП → «Відновити з архіву»** (owners): a card on the new-ФОП form, also while the organization has no ФОП
  yet (the router lets `/backups/import` through then; its back arrow returns to Новий ФОП). §4.8, §5.3.
- **A run's bundle is restored whole:** `POST organization/backups/import` takes a ФОП's archive or a bundle of every
  ФОП of a backup, and restores all of its ФОПs in one transaction, or none (one the organization has refuses the
  bundle; the documents stored for the others are removed again). The answer is `{dry_run, tenants: [{tenant_id,
  tenant, rows, documents, skipped_files}]}`. `prytax:backup:import` does the same. §5.1.
- **Engine:** `ArchiveImporter::import()` reads a tenant from a bundle's folder (`profiles/<folder>/`), `inspect()`
  tells what an archive holds without importing it, and an import's result lists the documents it stored, so
  `discard()` removes them when the caller's transaction rolls back. `ArchiveImports` (public, `Backup\`) is how the
  app uses them (§10.3): `inspect()`, `importAll()`, `discard()`. `ImportException::PASSWORD_REQUIRED` tells an archive,
  which needs its password.
- **Missing NBU rates are fetched again:** a new contract, `ReferenceResolver` (§10.4), lets the app supply a reference
  row this database lacks; PryTax's `NbuReferenceResolver` fetches the NBU's rate of an `exchange_rates` row (that
  table is a cache of the NBU), so a new installation (the desktop app's first launch) restores ФОПs with foreign
  income. Offline, the import is refused: «Курс НБУ USD на 05.10.2026, на який посилається архів, не вдалося отримати.
  Підключіться до інтернету й спробуйте ще раз.» Reference rows are still never inserted from an archive. §5.1.
- **`prytax:create-owner --from=<zip>`** (repeatable; `builds.md` §1.3): a fresh server's first organization starts
  with the ФОПs of archives (bundles too; an AES archive's password is prompted), all of them or nothing created;
  the same ФОП in two archives is refused. Moving from the desktop app to a server goes this way.
- **The desktop app takes uploads of 200 MB** (`builds.md` §4.2), as large as the import's request allows; Docker
  still caps them at 32 MB.
- **Checked live** on scratch SQLite files: registering a new organization, then Новий ФОП → «Відновити з архіву»
  with the demo practice's bundle (4 ФОПs, 39 documents): the check, the restore (25 NBU rates fetched), Головна of
  the first. Tests: `BackupImportTest` (a bundle into an organization without a ФОП; a bundle with one the
  organization has restores none), `ArchiveImporterTest` (an NBU rate fetched again, refused while offline),
  `CreateOwnerTest` (`--from`, with a password; the same ФОП twice creates nothing).

### Changes in v1.8 (SQLite, the desktop app and restoring in the app)

As built on 2026-10-29/30 (`feat/sqlite`, `feat/desktop` and the client's address, merged into `dev`). What changed
since v1.7:
- **SQLite** (`builds.md` §3.4): the engine runs on MySQL and on SQLite, with driver switches of its own (PryTax's
  `AccountingArchiveChecks` uses `PryTax\Helpers\Sql`). On SQLite `SchemaInspector` reads the schema through the schema
  builder, the run's snapshot is a `BEGIN DEFERRED` read transaction (one version of the file under WAL, no statement
  time limit) with `snapshot_at` from `datetime('now')` (UTC), the audit log's selection uses `json_extract`, and the
  purge skips MySQL's `innodb_lock_wait_timeout` (§4.2, §4.3, §9.5).
- **Archives move between the drivers:** `Column` goes by the declared type, and SQLite declares JSON columns `text`
  and keeps decimals as numbers, so an archive written there holds JSON as stored and decimals without trailing zeros
  (`1234.5`). The manifest's `checks` are two-decimal strings on both drivers, so an archive made on one should import
  and check out on the other: the desktop app ↔ a server (§2.5, §2.6). No test covers it yet (§8).
- **Restoring in the app** («Відновити з архіву», 2026-10-29): `POST /api/organization/backups/import` and the owners'
  new `import` permission on backups. An uploaded ФОП archive (with its password, when it was downloaded with one: the
  importer opens AES archives) is checked with `dry_run`, then imported as a **new** ФОП of the organization, never in
  place. A ФОП the organization has (deleted ones too), a run's bundle, a period export, and a missing or wrong password
  are refused. SPA `/backups/import`, linked from Резервні копії. That is §5.3's self-service restore, upload only;
  restoring into the same organization stays open (D7, D9, §4.7, §4.8, §5.1, §5.3).
- **The desktop app** (`builds.md` §4): backups run on the app's own queue worker and stay on the computer; a policy's
  missed window is due at the scheduler's next run, while the pruner, the weekly verification and the rolling
  retention run at fixed times, which the launch doesn't catch up (§4.3). Its owner keeps the recovery kit again from
  Резервні копії (§4.8). It has no Layer A (§1), and restoring it from an in-app archive waits for §5.3.
- **The client's address** is JSON `{text}` (2026-10-30) in the same `clients.address` column: no registry change, so
  no `format_version` bump and no upcaster. Older archives import their parts as they are, which the invoice PDF and
  the SPA still read (D11, §4.2).
- **UI:** Резервні копії is in the «Керування» group of Ще on a phone and of Налаштування on desktop; a run card
  narrower than 720 px puts a ФОП's size under its name; the desktop app's side note (§4.8).
- **Corrected where the body still had the design:** the run job's retries and its snapshot's connection (§4.3), the
  weekly verification (§5.2), the build order (§7), the rolling retention's command (§9.5), the module's layout (§10.2,
  §10.4), `composer.json` (§12).
- **Tests not written yet:** besides v1.7's three, an archive made on one driver imported on the other, and the import
  refusing a bundle.
- **Open, found here:** on the desktop, pruning and the rolling retention wait for the app to be open at 05:30 and
  03:40 (§4.3); an import's upload is capped at 32 MB by PHP in Docker and the desktop app (§5.1). The download limit
  shared with the import and the status polls was fixed the same day (§4.7).

### Changes in v1.7 (as built on `feat/backups`, and the UI handoff)

Layer B, retention and `prytax:verify-db` are built (2026-10-28, §7 steps 2–6); Layer A is infrastructure and not
deployed yet. What the code does differently from v1.6:
- **Registry:** `projects` and `charge_types` are `profile` tables (they have `profile_id`); the query builder exports
  soft-deleted rows without `withTrashed()`; `tax_payments.receipt_tax_key` is a generated column too, and only
  `VIRTUAL|STORED GENERATED` columns count as generated (`DEFAULT_GENERATED` is a default); the snapshot's
  `signature_file_id` is rewritten after `files` is imported (a late rewrite); `tax_id` keys aren't row ids; the
  module's own tables are excluded (`PryTaxTableRegistry::EXCLUDED`).
- **Archive:** documents sit at `documents/{owner table}/{owner id}/{file id}-{name}`; timestamps are written in UTC
  (the database keeps Kyiv time); no upcasters yet, so the importer refuses other format versions.
- **Tables:** `backups` also keeps `file_name`, `manifest` and `snapshot_at`; runs keep their options, profile ids and
  bundle columns.
- **Downloads:** the audit entry has no subject (`audit_logs.subject_id` is an integer and backup ids are ULIDs): it is
  named `models.backups.downloaded` with `metadata.backup_id`. A password download is repacked inside the request, not
  by a job, so it answers `{url, expires_at}` at once (§4.6).
- **Runs:** the job has `tries = 0`, `maxExceptions = 3` and `retryUntil` 12 h (`WithoutOverlapping`'s releases would
  use up `tries`); manual runs don't skip unchanged ФОП.
- **Retention:** a soft-deleted organization takes its final backups and is purged after 30 days, but its users are
  soft-deleted with it, so nobody could download a final bundle: the bundle and its e-mail aren't built (§9.3), and
  there is no organization delete endpoint.
- **Tests not written yet:** a concurrent write during the snapshot (it needs a connection outside the test's
  transaction), the engine's tests with a fake registry (DDL breaks `RefreshDatabase`), an upcaster.

The UI follows Claude Design's «Backups» handoff (`storage/handoff-28-10-2026/prytax-backups-handoff/BACKUPS_UI.md`, 13
mobile and 12 desktop boards), now §4.8; for what users see it wins, for the backend this document does. The owner's
answers of 2026-10-28: the password switch of a download is off by default (an AES zip doesn't open in the built-in
Windows and macOS archivers; the warning about decrypted data always shows); the organization bundle has no password;
self-service restore and §5.3 stay open; sizes use the dot («4.2 МБ»); in-app notices go through api-core's per-user
database notifications (`notifications`), shown in Сповіщення beside the ФОП's own.

### Changes in v1.6 (where Layer A's database copies live)

- **Cloud SQL enhanced backups into a Backup and DR backup vault** in the DR project, instead of standard automated
  backups (an instance can't use both): daily, with point-in-time recovery, 30 days of enforced retention that no one
  can shorten once locked; they survive the deletion of the instance and of the app project (§1.1).
- **The nightly dump is a serverless export** (`instances.export` with `offload`) started by Cloud Scheduler and a Cloud
  Run job **in the DR project**, written by the instance's service account with create-only access into the DR bucket,
  which sits in **another region** than the instance; the Laravel scheduler and the app's identity have no access to
  the DR project (§1.1).
- **DR project `prytax-dr-<env>`** holds the vault, the bucket and the export job; its layout, permissions, the lien
  Backup and DR puts on it from 1 November 2026, monitoring and the drill alternating vault and dump are specified (§1.1).
- **All Layer A retention is 30 days** (vault, DR bucket, soft delete; was 35 for the bucket), so the erasure horizon
  "within 35 days" keeps a margin for lifecycle delays; the DR bucket has no soft delete of its own (§1.1, §1.3, §3.1,
  §9.1). The document copy's lifecycle and re-copying are spelled out (§1.2).

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
| D1 | Are per-organization CSV archives our backup? | **No — two layers.** Layer A (platform disaster recovery) = Cloud SQL enhanced backups in a backup vault + point-in-time recovery + an independent nightly dump in another region, both in a separate DR project, owned by us and not configurable. Layer B (tenant backups/exports) = per-profile CSV archives that organizations schedule and download. CSV archives cannot restore a whole platform quickly or consistently; database backups cannot give an owner their own data. Both are needed. |
| D2 | Full vs quarter-by-quarter vs year-by-year vs invoice-by-invoice? | **Scheduled backups are always a full snapshot of the profile.** A ФОП's whole history is a few MB (KB without PDFs), so incremental or partial backups add complexity and restore risk for no saving. Period slicing (year / quarter / date range) exists as an **on-demand export** (for an accountant or a tax audit) and is marked as not restorable. Invoice-by-invoice backups are not offered: thousands of tiny objects, no restore value. Inside every archive, documents are already organised per invoice. |
| D3 | Format | **CSV, one file per table**, RFC 4180, UTF-8 with BOM, plus `manifest.json`, `schema.json`, `README.txt` and a `documents/` folder with the invoice PDFs and uploads. |
| D4 | "Zipped and gzipped"? | **Only `.zip` (deflate).** Gzipping a zip saves ~0 % (the content is already compressed) and turns a double-click archive into a two-step one on Windows/macOS. |
| D5 | Unit of storage | **One archive per profile per run.** The organization archive ("one zip, a folder per profile") is **assembled on demand** from the profile archives of the same run, so nothing is stored twice and all profiles in it share one snapshot instant. |
| D6 | Encrypted columns (`profiles.tax_id`, `accounts.iban`, `accounts.account_number`, `invoices.snapshot` — the snapshot copies the РНОКПП and IBAN) | **Exported decrypted**, the snapshot as JSON (ciphertext is useless outside the app's `APP_KEY`). The list is derived from the models' `encrypted*` casts by a test, never maintained by hand. The archive is therefore sensitive: private bucket, signed short-lived download URLs, downloads audited, an optional password set at download time. |
| D7 | Who configures / downloads | **Owner** configures the policy, downloads, and restores an archive as a new ФОП (`import`, §5.1). **Accountant** can download (they run the books) and trigger a manual backup. **Viewer**: no access (bulk export of personal data). |
| D8 | Retention | **Grandfather-father-son** per organization (keep N daily / N weekly / N monthly + manual), enforced by an app-side pruner; a bucket lifecycle rule is the hard ceiling. **The latest completed backup of a profile is never pruned.** |
| D9 | Restore | Always **into a new profile** (never in place): the ops tool `prytax:backup:import` and, since 2026-10-29, the owners' «Відновити з архіву» in the app (§5.1), plus an automated export→import round-trip test. A ФОП the organization still has can't be restored into it (§5.3, open). No in-place overwrite, ever. |
| D10 | `APP_KEY` | Layer A backups are useless without the key that encrypted the columns. It lives in Secret Manager with every historical version; key rotation uses `APP_PREVIOUS_KEYS`. |
| D11 | How is the backup definition versioned? | **In code, with a hash.** The registry is deployed atomically with the migrations, so a database copy of it plus an hourly sync (the reference design) would only add a window where code and registry disagree. Each backup stores `registry_hash` (sha256 of the normalized registry + column lists). **`format_version` is bumped for breaking changes only** (a dropped or renamed column, a changed treatment, a removed table); CI compares the column snapshot with the committed one and fails on a breaking change without a bump. An added column changes `registry_hash` only: the importer fills columns missing from an older archive with their defaults. A new shape of the JSON inside a column isn't a registry change either, while the code reads both shapes (`clients.address`, §4.2). Backups are never produced with an *old* definition; old *archives* are read through importer upcasters (none yet: format 1 is the only one, and the importer refuses any other). |
| D13 | Module or scattered code? | **A module in the app**, not a composer package: engine `PryTax\DataLifecycle\` (generic, no knowledge of accounting; built on api-core's base classes), definitions `PryTax\Backups\` + `PryTax\Retention\` (PryTax-specific), four contracts between them, a PHPUnit test for the direction of dependencies (§10). |
| D14 | Does api-core take part? | **No.** api-core (`Core\`) stays free of backup code, migrations and GCS/zip dependencies. PryTax registers the api-core tables it needs and owns the user-removal handler (§11). Extraction later goes to a sibling package, never into api-core. |
| D12 | Data retention & deletion | **Families × actions × periods in config**, executed by deletion runs with per-family jobs and a `due_at`. Accounting data is never deleted on a timer while the organization exists; offboarding = lock immediately → final export → grace period → FK-ordered purge (DB rows, documents, backups). Credentials and technical data have short rolling cutoffs (§9). |

---

## 1. Layer A — platform disaster recovery (not tenant-configurable)

Target: **RPO ≤ 5 min, RTO ≤ 1 h** for the database; **no loss** of uploaded documents.

Layer A is the hosted platform's. The other installations (`builds.md`) have none: a self-hosted Docker one has what
its operator sets up, and the desktop app's database is one SQLite file on the computer, which the owner copies with
the recovery kit to restore it elsewhere (`builds.md` §4.4–§4.6); its Layer B archives stay on the same computer.

### 1.1 Database (Cloud SQL for MySQL; equivalents noted for self-managed MySQL)

Nothing of Layer A exists yet: PryTax isn't deployed, and the local MySQL has no backups. In production the database
has two copies outside the app's project, both in the **DR project `prytax-dr-<env>`**, to which nobody in the app
project has any role:

| Copy | Stored in | Retention | Survives |
|---|---|---|---|
| **Enhanced backups** + point-in-time recovery logs | a Backup and DR **backup vault** in the DR project (Google-managed storage, not a bucket), the instance's region | 30 days, enforced: no one can delete a backup earlier | deleting the instance or the whole app project; a compromised app identity; a bad migration (restore to the minute before it) |
| **Nightly logical dump** (`.sql.gz`) | the bucket **`prytax-dr-<env>`** in the DR project, **another region** | 30 days, locked retention policy | all of the above, plus an outage of Cloud SQL, of Backup and DR or of the instance's region; readable by any MySQL |

**Enhanced backups into a backup vault** (instead of Cloud SQL's standard automated backups: an instance uses one
backup option at a time, and attaching a backup plan overwrites the instance's own backup settings):
- The **backup vault and the backup plan live in the DR project**, as Google recommends (a project apart from the
  instances). The vault is in the instance's region, or a multi-region compatible with it; a vault can't be elsewhere.
- **Backup plan:** a daily backup, kept 30 days, and point-in-time recovery from transaction logs, which enhanced
  backups support, also after the instance is deleted. That gives the RPO of ≤ 5 min.
- **Enforced retention:** the vault's minimum enforced retention is 30 days. Until it passes, no one (a project owner
  or Google) can delete a backup. It is set unlocked while testing and **locked after the first restore drill**; a
  locked minimum can never be lowered.
- **Permissions:** the vault's service agent gets the *Backup and DR Cloud SQL Operator* role in the app project
  (needed because the vault and the instance are in different projects). No other cross-project grant.
- **The lien:** from 1 November 2026, Backup and DR puts a project-level lien on any project whose vault holds
  backups protected by enforced retention, so the DR project can't be deleted while they exist. That is wanted;
  decommissioning removes the lien deliberately.
- **Limitation:** an instance with enhanced backups can't have a Cloud SQL *DR replica* (the cross-region failover
  replica). Read replicas are unaffected, and the cross-region copy is the dump below.
- **Deletion protection** stays on the instance. The *final backup* setting of standard backups isn't needed: the vault
  keeps backups and logs after the instance is deleted.
- **Retention stays ≤ 30 days** so the erasure horizon holds (§9.1). A longer one changes the privacy policy and the key
  retention of §1.3 with it.

**Nightly logical dump into the DR bucket:**
- **Who runs it:** Cloud Scheduler in the DR project starts a Cloud Run job, which runs as its own service account of
  the DR project and calls the Cloud SQL Admin API `instances.export` with `offload: true` — a **serverless export**: a
  temporary instance does the work, so the primary isn't loaded (billed extra). Equivalent command:
  `gcloud sql export sql prytax-<env> gs://prytax-dr-<env>/mysql/{yyyy}/{mm}/{dd}/prytax-{yyyymmddThhmmZ}.sql.gz
  --database=prytax --offload` (gzipped because of the `.gz` name). Not the Laravel scheduler: the app's identity has
  no access to the DR project.
- **Permissions, least privilege:**
  - the job's service account: a custom role with `cloudsql.instances.get` and `cloudsql.instances.export` on the
    instance, granted in the app project; it can start exports and nothing else;
  - the **instance's own service account** (its `serviceAccountEmailAddress`) writes the file:
    `roles/storage.objectCreator` on the DR bucket only, which creates objects but can't overwrite or delete them. The
    docs show this grant within one project; for a bucket in another project the same bucket-level grant is expected
    to work, and the first test export confirms it;
  - one or two named people can read the DR project: a dump holds names, e-mails, addresses and amounts in plain text
    (РНОКПП, IBANs and invoice snapshots stay encrypted with `APP_KEY`, §1.3).
- **Bucket `prytax-dr-<env>`:** a region other than the instance's (e.g. the instance in `europe-central2`, Warsaw;
  the bucket in `europe-west3`, Frankfurt); Standard class; uniform access; public access prevention; a **retention
  policy of 30 days**, unlocked while testing, then locked (Bucket Lock: it can't be shortened or removed, and the
  bucket can't be deleted until every object is older than it); a lifecycle rule deleting objects older than 31 days;
  **no soft delete** on this bucket (the retention policy already protects it, and soft delete would keep deleted dumps
  past the erasure horizon); CMEK optional.
- **Monitoring:** an alert when the job fails or no new dump has appeared for 26 h.
- **Why both copies:** the vault restores fastest, to any minute, but only into Cloud SQL and only in the instance's
  region. The dump is portable (any MySQL, a laptop), readable, in another region, and independent of Cloud SQL and of
  Backup and DR.
- Self-managed MySQL: `xtrabackup` nightly with binlog shipping instead of the vault, and `mysqldump
  --single-transaction --routines --triggers --hex-blob` for the dump, into the same bucket.

**Quarterly restore drill**, alternating the source: (a) point-in-time recovery from the vault to a chosen minute
into a scratch instance; (b) the latest dump into a scratch instance (or a local MySQL). Run `php artisan
prytax:verify-db` (row counts, FK orphans, `TaxReconciler` balances for a sample of profiles), and record the duration
(this is the real RTO).

### 1.2 Documents (invoice PDFs, uploaded invoices, signature images)

- Documents bucket (`prytax-files-<env>`): private, uniform bucket-level access, public access prevention enforced,
  **soft delete 30 days** (configurable 7–90; covers deletes and overwrites), and object versioning off (soft delete
  covers it).
- Generated PDFs are regenerable from `invoices.snapshot`; **uploaded** files and signatures are not, which is
  why the bucket needs protection, not just the database.
- A daily Storage Transfer Service job of the DR project copies the documents into `prytax-dr-<env>/files/`
  (incremental: objects missing from the copy or changed). Deletions aren't propagated. The DR bucket's 31-day
  lifecycle removes every copy, and the next run copies again whatever still exists in the source. So the copy keeps
  every live document, and a document deleted in the source leaves the copy within about a month (§9.1). The cost of
  copying again is a few GB a month at most (§3.4).

### 1.3 Keys and secrets

- `APP_KEY` and every previous key in **Secret Manager** (versions never destroyed). Without them, `tax_id`, `iban`
  and `account_number` in any database backup are unreadable, and `tax_id_hash` (HMAC with the app key) cannot be
  recomputed.
- Key rotation: new key in `APP_KEY`, old keys in `APP_PREVIOUS_KEYS`; a re-encrypt job, then old keys stay in
  Secret Manager for as long as any backup encrypted with them exists (30 days for Layer A, §1.1; Layer B archives
  hold decrypted values and need no key).

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
  SQLite keeps decimals as numbers, so an archive written there drops trailing zeros (`1234.5`, `1234`); both drivers
  should import either (not tested yet, §8).
- Dates `YYYY-MM-DD`; timestamps ISO 8601 UTC `2026-10-27T02:37:11Z`.
- `NULL` = empty unquoted field; empty string = `""`. Readers that don't distinguish lose nothing important
  (`schema.json` marks nullability).
- Booleans `1` / `0`; enums as their string value; JSON columns as compact JSON strings. SQLite declares JSON columns
  `text`, so there they are written as stored; decrypted JSON (`invoices.snapshot`) is compacted on both.
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

`checks` are recomputed after an import; any mismatch fails the import (§5). They are amounts with two decimals on
either driver (MySQL sums decimals as strings, SQLite as floats), so an archive made on one should check out on the
other.
`content_hash` drives `skip_unchanged`.

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

The buckets are production's. As built, no GCS adapter is installed (§7), so every installation (development, Docker,
the desktop app) keeps its archives on the local `backups` disk (`storage/app/backups`; the desktop app's storage is
in its data folder, `builds.md` §4.1), and downloads go through the signed route of §3.3.

### 3.1 Buckets

| Bucket | Purpose | Settings |
|---|---|---|
| `prytax-backups-<env>` | Layer B archives | Region `europe-central2` (or the app's region); Standard class; private; uniform access; public access prevention; **soft delete 30 days**; lifecycle: delete `org/*/profile/*` objects older than 1 125 days (36 months + margin — the hard ceiling); delete `tmp/*` after 1 day |
| `prytax-files-<env>` | documents | §1.2 |
| `prytax-dr-<env>` (DR project) | Layer A dumps (`mysql/`) + document copies (`files/`) | Another region than the instance's; retention policy 30 days, locked; lifecycle: delete after 31 days; no soft delete; only the instance's service account may create dumps (`objectCreator`); nobody in the app project has access. The Backup and DR vault with the enhanced backups is in the same project (§1.1) |

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
  by the API after a policy check; every issue is logged in `backup_downloads`. On a local disk (development, Docker,
  the desktop app) the link is the app's signed route `backups.archive` (`/backups-storage/{path}?disk=…&name=…`),
  which saves the archive under the same name.
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
| `TableSpec::profileJson(column, path)` | api-core `audit_logs` (`metadata.profile_id`, written by PryTax — see "Audit entries" below) | `WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.profile_id')) AS UNSIGNED) = ?` (SQLite: `CAST(json_extract(…) AS INTEGER)`) — not indexed; fine for an export or a purge |
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

**The JSON inside a column can change shape without a bump** while the code reads both shapes, since neither the
column snapshot nor `registry_hash` sees it. `clients.address` (2026-10-30) went from parts (`{lines, city, state,
zip}`) to the text the invoices print (`{text}`): a migration converted the stored rows once, and an archive from before
imports its parts as they are, which the invoice PDF prints as it did (`invoice.md` §2) and the SPA's client form turns
into the text. `format_version` stays 1.

**Registry hash** = `sha256(json_encode(normalize($registry->tables()), JSON_UNESCAPED_UNICODE |
JSON_UNESCAPED_SLASHES))` with keys sorted, computed when a run starts (cheap; nothing to cache). Stored on `backups`
and in the manifest; the importer keys its upcaster chain on `format_version` and logs `registry_hash` for diagnosis.

Not adopted from the reference design: a `backup_modules` / `backup_module_versions` table pair, the hourly sync
event, per-domain module listeners, and producing backups with a historical definition. With ~25 tables in one
deployable, one registry class is easier to review, and an old definition can reference columns that no longer exist.

### 4.3 The run

```
Scheduler (every 5 min, onOneServer)
  └─ prytax:backup:dispatch: gives an organization without a policy the default one, then
     policies WHERE enabled AND next_run_at <= now()
       → backup_runs row (queued), then RunOrganizationBackup::dispatchFor($policy->organization_id, $run->id)
         ->onQueue('backups')                       // api-core AsyncJob: the scheduler has no current organization
       → next_run_at = the next slot after now (frequency + per-org offset)
  Manual and automatic runs create their backup_runs row the same way and dispatch the same job.

RunOrganizationBackup($runId)  (AsyncJob; middleware WithoutOverlapping("backups:{org}")->releaseAfter(60)
                                ->expireAfter(1200); tries 0, maxExceptions 3, retryUntil 12 h;
                                backoff 5/15/45 min; timeout 15 min)
  0. Load the run; a run already completed/partial/failed → return. Profiles that already have a backups row
     for this run (completed or skipped) are skipped, so a retry resumes the run instead of duplicating it.
  1. backup_runs row → running
  2. Data, inside one consistent snapshot on the connection `data-lifecycle.connection` (`BACKUP_DB_CONNECTION`:
     one of its own or a replica; the default one when unset), opened with ->unprepared(…) — DB::transaction()
     can't open it:
       MySQL:  SET SESSION MAX_EXECUTION_TIME = 600000;
               SET TRANSACTION ISOLATION LEVEL REPEATABLE READ;
               START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY;
               snapshot_at = SELECT UTC_TIMESTAMP()
       SQLite: BEGIN DEFERRED;                      // reads one version of the file under WAL, blocks no writer
               snapshot_at = SELECT datetime('now') // UTC
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

prytax:backup:prune (daily at 05:30, onOneServer)
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
SQLite has no such limit.

**In the desktop app** (`builds.md` §4.1, §4.2, §4.7) the runs, bundles and exports go to the app's own queue worker,
and Electron runs the scheduler every minute while the app is open. The app is rarely open between 01:00 and 05:00, so
a policy's `next_run_at` passes while it is closed and is due at the scheduler's next run: the backup is taken soon
after the app opens, once, and `next_run_at` moves to the next slot. The pruner (05:30), the verification (Sundays
06:00, §5.2) and the rolling retention (03:40, §9.5) run at fixed times, and the launch doesn't catch them up
(`LaunchCatchUp` queues the rates, the tax periods and the alerts), so on a desktop they run only when the app is open
then. Open: a desktop's archives aren't pruned to its policy while that is so.

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
→ the archive is re-packed into `tmp/` with AES-256 entry encryption (`ZipArchive::setEncryptionName(…,
ZipArchive::EM_AES_256, $password)`) inside the request (v1.7: a profile archive is small enough, and a queued job would
have to carry the password), the password is never stored, and the link is valid 10 min. Stored archives are
not password-protected: scheduled runs would have to keep the password, which defeats the purpose.

### 4.7 API (organization-level; not under `profiles/{profile}`)

As built (ids are ULIDs; v1.7 adds what the UI needs, marked «UI»; v1.8 the restore, §5.1):

```
GET    /api/organization/backup-policy                     owner, accountant (read)
PUT    /api/organization/backup-policy                     owner (one group's fields at a time is fine)
GET    /api/organization/backup-runs?page[number]=         owner, accountant: runs, newest first, ?include=backups
POST   /api/organization/backup-runs                       owner, accountant   {profiles: all|[ids]} → 202 {run_id}
GET    /api/organization/backup-runs/{run}                 status + per-profile results (?include=backups)
POST   /api/organization/backup-runs/{run}/bundle          owner, accountant   → 202
GET    /api/organization/backup-runs/{run}/bundle          → {status, url?, expires_at?}
GET    /api/organization/backups?filter[profile_id]=       owner, accountant
POST   /api/organization/backups/import                    owner   {archive, password?, dry_run?} (multipart)
                                                    → {dry_run, tenant_id, tenant, rows, documents, skipped_files}
GET    /api/organization/backups/{backup}
POST   /api/organization/backups/{backup}/download         owner, accountant   {password?} → {url, expires_at}
DELETE /api/organization/backups/{backup}                  owner; manual runs only
GET    /api/organization/backup-status                     UI: the status card (below)
GET    /api/organization/deleted-profiles                  UI: ФОП in their grace (below)
POST   /api/profiles/{profile}/exports                     owner, accountant   {from, to} → 202 (period export)
GET    /api/profiles/{profile}/exports/{export}            → {status, url?}
```

- Pagination is api-core's page numbers, as everywhere in the app (`design.md` §10), not a cursor.
- **`keep_until`** (UI) on every backup: when the copy will disappear under the current policy, computed on read by a
  dry run of the pruner's selection (§4.3): a scheduled copy kept as a day's, week's or month's keeper gets the date it
  falls out of that window, with `keep_reason` `day|week|month`; the newest completed copy of a profile `null` (never
  pruned); a manual copy `null` (until deleted; beyond 20 the oldest goes); an automatic one `created_at + 30 days`. An
  estimate: changing the policy changes it.
- **The status card** (UI): the latest run (with its profile count, size and failures), the policy's `enabled` and
  `next_run_at`, the per-organization time of day (`crc32(org_id) % 240` minutes after 01:00 Kyiv), the size of each
  included profile's newest completed archive summed, and `manual_runs_left` today (5 − today's manual runs).
- **Deleted ФОП** (UI): the organization's soft-deleted profiles whose purge is planned, with `deleted_at`, the
  purge's `due_at` and the automatic backup taken at deletion; `POST /api/profiles/{profile}/restore` (api-core's,
  owners) cancels the purge.
- Scoping: `backups` / `backup_runs` use api-core's organization scope (an organization bundle has no single profile).
  Rate limits: manual runs 5/day/org, downloads 30/hour/user. Every download → `backup_downloads` + an `audit_logs`
  entry (`models.backups.downloaded`, §v1.7); every import but a dry run → `models.backups.imported` with
  `metadata.tenant_id` and `file_name`.
- **The hourly limit as built** counts the user's download links in `backup_downloads` over the last hour
  (`data-lifecycle.backups.downloads_per_hour`, 30; the `LimitsDownloads` trait): a backup's download, and the status
  of a bundle or of a period export once it gives the link, are refused with a 429 past it, while the status checks
  before that (the SPA polls every 2–2.5 s) don't count. The import has a throttle of its own, 30 an hour
  (`throttle:30,60,backups-import`). Until 2026-10-30 a plain `throttle:30,60` on all four routes was one counter per
  user, which a slow bundle's polls used up.
- **Notices** (UI): a partial or failed run, a ready bundle and a ready period export are api-core database
  notifications (`notifications`, per user: the owners for runs, the requesting user for a bundle or an export), listed
  through api-core's `NotificationController` and shown in Сповіщення. E-mails follow `notify` and carry no links.

### 4.8 UI (owner and accountant; the «Backups» handoff)

The screens follow `BACKUPS_UI.md` of the handoff (`storage/handoff-28-10-2026/prytax-backups-handoff/`; boards
`M-BK-*` at 390 px, `D-BK-*` at 1440 px; exact copy in `screens/html`). They reuse design.md §16.6 and §17's
components: accordion cards, the sheet or dialog, badges, the export block, the sidebar.
- **Entry:** «Резервні копії» in the «Керування» group, after Користувачі й ролі, with the time of the last copy on
  the right: on Ще on a phone, on Налаштування (from the sidebar) on desktop (v1.8; `design.md` §16.5, §17.2). Routes
  `/backups`, `/backups/policy` and `/backups/import` (`/settings/backups` redirects). Organization-level: it doesn't
  change with the current ФОП. Viewers have no entry point and get 403.
- **Status card** in five states: the last run OK (✓ green, «Остання копія · Сьогодні, 02:37 · 4 ФОП · 4.2 МБ · за
  розкладом»), a run in progress (spinner, «Зараз · Створюється копія… · 2 з 4 ФОП», a bar), a partial run (⚠ red, the
  failed ФОП named), no copies yet (clock, «Ще немає копій · Перша — сьогодні вночі ≈ 02:37, або створіть зараз»), the
  schedule off (⚠ amber). «Наступна: Завтра ≈ 02:37» (or «—»). Mobile adds the link row «Розклад і зберігання · Щодня
  · до 12 міс. ›»; desktop shows a policy summary card in the right column.
- **Actions:** «+ Створити зараз» (primary) and «⤓ Усі ФОП · ZIP» (secondary); in the desktop header «+ Створити копію
  зараз» with the bundle button before it.
- **«Копії»:** the runs, newest first (4 on mobile, 5 on desktop, then «Показати старіші · N»). A run card: the date
  and time (the time in mono) and the kind chip (За розкладом, white bordered; Вручну, accent-soft; Автоматично,
  dashed); a line saying how long it is kept («зберігається до 03.11», «зберігається до 22.11 · остання копія тижня»,
  «доки не видалите · Олена Коваленко», «перед видаленням ФОП … · зберігається до 23.11»), with «⤓ Усі ФОП · ZIP»
  when the run has two ФОП or more, or «🗑 Видалити» on a manual run for owners; then a row per ФОП: the avatar (grey
  when inactive), the name, the status chip (Готово green, Без змін grey, Помилка red, Створюється accent-soft, У черзі
  dashed), the meta («1.8 МБ · 41 документ», «0.9 МБ · копія від 25.10», or the error in red) and a 40 px ⤓, or
  «Повторити» on a failure (a manual run of that ФОП). «Без змін» downloads the profile's last completed archive.
  Desktop grid `minmax(0,1fr) 100px 220px 72px`; a card narrower than 720 px drops the 220 px column and puts the meta
  under the name. The footnote (the side note «Як це працює» on desktop, which also tells of Layer A's 30 days and
  points to Облік › Експорт; in the desktop app it says instead that the copies stay on this computer and that the
  recovery kit with the database file restores the whole of PryTax on another one).
- **Links under the status card:** «Відновити з архіву» (owners, below) and, in the desktop app, «Набір відновлення»
  (owners; `builds.md` §4.5), which opens the kit to keep it again and closes back to Резервні копії.
- **«Відновити з архіву»** (`/backups/import`, owners: `import` on backups; v1.8): «Обрати архів (.zip)», then «Пароль
  архіву» («Лише якщо архів завантажено з паролем»); [Перевірити] runs a dry run and shows «В архіві»: the ФОП's name
  with «•••• 4821» and «{rows} записів · документів: {documents}»; then [Відновити як новий ФОП], and «Відновлено:
  {name}» with [Перейти до ФОП], which makes it the current ФОП and opens Головна. A refusal shows under the file. The
  side note: a restore never overwrites a ФОП, one the organization has can't be restored here. **v1.9:** a bundle of
  every ФОП lists them all, [Відновити {count} ФОП як нові], «Відновлено ФОП: {count}» with each name, and [Перейти до
  ФОП] opens the first; the page is also reached from Новий ФОП (a card «Відновити з архіву»), even before the
  organization has a ФОП, and its back arrow goes there then.
- **«Створити копію зараз»** (a sheet; a 480 px dialog): «Усі ФОП організації» (default) or «Лише поточний ФОП»; the
  info «… До 5 ручних запусків на день — сьогодні ще N.», at 0 «Створити» disabled with «Ліміт на сьогодні вичерпано —
  спробуйте завтра.»; [Скасувати] [Створити]. The new run appears on top running; the page polls the run every 3 s
  while visible.
- **Download** (a sheet; a 520 px dialog): who and what; the amber warning that the archive holds decrypted РНОКПП,
  IBAN and account numbers; the switch «Захистити паролем» (off by default) with the password field and its hint;
  [Скасувати] [⤓ Завантажити ZIP]; «Посилання діє 10 хвилин. Кожне завантаження записується в журнал організації.».
  Errors (the hourly limit) inline. Deleting a manual copy asks «Видалити ручну копію від 26.10, 14:12? Цю дію не
  можна скасувати.» and deletes each backup of the run.
- **Розклад і зберігання** (`/backups/policy`): four accordion cards, one open at a time, each saving its own fields
  (`PUT …/backup-policy`): Розклад (on/off, Щодня / Щотижня with a weekday / Щомісяця with a day 1–28; the time is the
  organization's, no input), Зберігання (the preset Стандарт / Мінімум / Для архіву / Власний, derived from the three
  numbers, and the steppers: days 0–31, weeks 0–12, months 0–36), Що входить (all ФОП including new ones, or chosen
  ones, which can't be none; documents; the audit log; skip unchanged), Сповіщення (Лише про помилки / Завжди /
  Ніколи). Accountants see it read-only with «Ви бачите налаштування, але змінює їх власник організації.».
- **The organization bundle:** «Усі ФОП · ZIP» posts the bundle of the newest run (or of a run card's run); a banner
  «Готуємо архів усіх ФОП …» turns into «✓ Архів готовий · 4 ФОП · 4.2 МБ» with «⤓ Завантажити ZIP» (a fresh link on
  each click, for 24 h). No password.
- **«Видалені ФОП»** (after the status card, or in the list column on desktop): per profile in its grace, the grey
  avatar and the name, «Видалено 20.10 · дані й копії зітруться 19.11» in red, the text about the final copy, and
  [Відновити ФОП] (owners, confirmed) [⤓ Фінальна копія] (the download dialog).
- **«Архів за період»** on Облік (per ФОП, not here): Облік's export block gets a third button, «🗃 Архів» (desktop
  «🗃 Архів ZIP»), with the hint «CSV і XLSX — таблиця надходжень. Архів — усі дані й PDF за період, ZIP.». The sheet
  (a dialog on desktop): Рік / Квартал / Період (the range picker), by default Облік's quarter, with «IV квартал ще
  триває — в архіві буде все до сьогодні.» while it runs; «Що буде в архіві · 01.10 – 27.10.2026» and its text; the
  info «Це не резервна копія: з неї не відновити ФОП …»; [Скасувати] [Підготувати ZIP]; «Готуємо у фоні: архів
  з’явиться в сповіщеннях, посилання діє 24 години.».
- **Notices:** «Резервна копія: {N} з {M} ФОП не збережено» → `/backups`; «Архів організації готовий»; «Архів за період
  готовий · {ФОП} · {період}»; «ФОП {name} видалено — фінальну копію можна завантажити до {date}».

**As built (2026-10-28, branch `feat/backups-ui`):** `pages/backups/BackupsPage.vue` and `BackupPolicyPage.vue`,
`components/backups/*` (`BackupStatusCard`, `BackupRunCard`, `BackupRunSheet`, `BackupDownloadSheet`), `utils/backups.ts`,
and `components/accounting/PeriodExportSheet.vue` (Рік / Квартал / Період). The API added `backup-status`,
`deleted-profiles`, `keep_until` / `keep_reason` (`Pruner::keepUntil()`, a request-scoped `RetentionEstimate`) and
`previous` on a backup skipped as unchanged (the archive its download gives); `backup-runs` filters by `kind`. A run's
«Повторити» and the failed ФОП's card start a manual run of that ФОП. Not built yet: the in-app notices (api-core's
`notifications`; the bundle's banner polls on the page and the period archive's sheet waits for its export and
downloads it), XLSX on Облік (the package couldn't be installed here), and the banner tells the signed link's 10 minutes
rather than the bundle's 24 hours.

**As built since v1.7 (2026-10-28/30):** `pages/backups/BackupImportPage.vue` and its link, the recovery
kit's link (`useDesktopApp`), the desktop app's side note, the run card's container query, and the entry in
«Керування». Довідка (`/help`) has a Резервні копії topic for those who see backups. Still not built: the in-app
notices, XLSX and the banner's 24 hours, as above.

---

## 5. Restore

### 5.1 Phase 1 — ops tool

```
php artisan prytax:backup:import {archive} --organization={id} [--dry-run]
```

1. Validate `manifest.json` (`format`, `format_version` ≤ supported, `restorable = true`), file sha256s, `schema.json`.
   Older `format_version`s go through upcasters (`BackupUpcaster_v1_to_v2`, …); none exist yet, so the importer
   refuses any version but its own (1). A run's bundle (`format: prytax-backup-bundle`) and a period export
   (`restorable: false`) are refused with their own messages. An archive downloaded with a password (§4.6) is opened
   with it; without it, or with another, it is refused.
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
   uploaded to the files disk and `files.disk` / `files.path` rewritten. An archive written without its documents
   (`include_documents` off) leaves out its `files` rows (`skipped_files`), and what points at them becomes NULL.
   After the commit, AlertSync runs for the new profile (`ArchiveImported`).
4. Verification inside the transaction: row counts = manifest (export-only tables excluded); recompute `checks`
   (income by quarter, invoice totals, tax paid) = manifest; otherwise roll back.
5. Constraint: `UNIQUE(organization_id, tax_id_hash)` means the same ФОП cannot be imported into an organization
   where it still exists (soft-deleted rows count too). Import into another organization, or purge the original
   first. The tool reports this before writing (`ProfileTenantRoot::importConflict()`, naming the profile and whether
   it is deleted).

**In the app** («Відновити з архіву», 2026-10-29; the screen is in §4.8). `POST /api/organization/backups/import`
(`BackupController::handleImport`, `ImportBackupRequest`, owners: `import` on backups) runs the same importer on an
uploaded archive, in the request (`set_time_limit(300)`), always into the request's organization (`orgId()`) as new
ФОПs (v1.9: every ФОП of a run's bundle, in one transaction, or none):
- `archive`: the `.zip` as downloaded. The request allows 200 MB, and so does the desktop app (v1.9), but PHP takes
  uploads up to 32 MB in Docker (`builds.md` §2.1), which an archive with years of documents can exceed.
- `password`: an AES archive's (§4.6), never stored. `prytax:backup:import` takes none.
- `dry_run`: import, verify and roll back, to tell whose archive it is (the manifest's profile: name, the last four
  digits of the РНОКПП) and what it holds (rows, documents) before restoring it.
- A refusal (the importer's `ImportException`) is a 422 of `archive`, in the user's language for the fixed messages
  (not a zip, the password, a period export, another app's archive, a ФОП the organization has, an NBU rate that
  couldn't be fetched); those with values in them (a format version, a damaged file, a failed verification) stay in
  English.
- An NBU rate, which the archive references and this database lacks, is fetched again (`ReferenceResolver`, v1.9).
- Restoring a ФОП on another installation (the desktop app ↔ a server) is meant to go this way, as archives should move
  between the drivers (§2.5, §2.6; not tested yet, §8).

### 5.2 Continuous verification

- CI: fixture profile → export → import into a fresh organization → assert every `TaxLiabilityCalculator` result and
  `TaxReconciler` balance, every holding, and every invoice PDF hash (a re-render draws the restored profile's own
  signature) is identical; the restored settings name the restored accounts; a new invoice of a restored client
  continues its counter.
- Weekly `prytax:backup:verify` (Sundays 06:00; `BackupVerifier`): a random completed archive of the last month that
  isn't verified yet, its sha256 checked, then imported in a dry run into a scratch organization, both rolled back; a
  pass sets `backups.verified_at`, a failure is reported (`report()` and the log) for ops.

### 5.3 Later — self-service

"Відновити з архіву як новий ФОП" (owner): upload or pick an archive → dry-run report (counts, checks, conflicts) →
confirm → new profile. In-place restore is never offered.

**Built on 2026-10-29** as «Відновити з архіву» (§5.1 "In the app", §4.8): upload → «Перевірити» (whose it is, its
rows and documents; a conflict is a refusal) → «Відновити як новий ФОП». Not built: picking one of the organization's
stored archives instead of uploading it, and the checks in the report.

**Open decision: restoring into the same organization.** `UNIQUE(organization_id, tax_id_hash)` counts soft-deleted
profiles, so while the original exists (even deleted, during its 30-day grace) its archive can't be restored next to
it. That is the usual case ("my data got messed up, give me last week's"), and comparing the two side by side is
impossible as the schema stands. The importer refuses it today, in the app too (§5.1, step 5; for a deleted ФОП its
message says to purge it first, which an owner can't do before the grace ends, §9.3), and `builds.md` §4.6 leaves
restoring the desktop app from an in-app archive to this decision. Options:
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
- Restoring in the app (§5.1) is the owners'; the upload is read in the request and not kept, its password is never
  stored, and every restore is audited (`models.backups.imported`).
- Existing archives are protected by the bucket's 30-day soft delete, which the app's identity can't bypass (§3.3);
  the data they hold is also in Layer A's copies in the DR project (§1.1), from which any archive can be produced
  again. The app itself can delete archives, as owners and retention need it to.
- The database's copies (the vault's enhanced backups and the nightly dumps) live in the DR project, which no identity
  of the app project can reach; the vault's backups can't be deleted early by anyone (§1.1).
- Organization, profile and user deletion follow §9: lock first, final export, grace period, FK-ordered purge
  including documents and backups. Keeping records 1 095 days is the ФОП's obligation; the final export is how they
  meet it.
- No secrets in archives (tokens are excluded; the profile's `settings` rows hold no credentials — if a setting
  ever stores one, it gets an `omit()` or a dedicated exclusion, enforced by review of the column snapshot).
- Logs: run ids and sizes only, never row content.

---

## 7. Build order

1. **Layer A** before the first real user: the DR project; Cloud SQL enhanced backups into its vault with
   point-in-time recovery, and deletion protection; the nightly serverless export into the DR bucket in another region,
   with its monitoring; the documents copy; documents bucket soft delete; `APP_KEY` in Secret Manager; one restore
   drill from each source, then lock the vault's retention and the bucket's retention policy.
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

**As built (2026-10-30):** steps 2–7 and retention: Layer B, retention, `prytax:verify-db` and the weekly verification
on 2026-10-28 (`feat/backups`, the screens on `feat/backups-ui`); the engine on SQLite and the restore in the app
(upload only, §5.3) on 2026-10-29. Not built: step 1 (Layer A is infrastructure, and nothing is deployed), the GCS
adapter (a package to approve), the final organization bundle and its e-mail (§9.3), an organization delete endpoint,
a restore into the same organization (§5.3), and the in-app notices (§4.8).

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

Restore in the app and SQLite (v1.8): an owner restores a ФОП's archive into another organization; only owners may;
a ФОП the organization has is a 422; an archive downloaded with a password opens only with it; a file that isn't a zip
is a 422 · the whole suite also runs on SQLite (`composer test:sqlite`, `builds.md` §3.8). Not written yet: an archive
made on one driver imported on the other, the import refusing a bundle, and v1.7's three (a concurrent write during the
snapshot, the engine's tests with a fake registry, an upcaster).

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
- **Erasure has a documented horizon.** After a purge, data still exists in GCS soft delete (30 days), in the vault's
  enhanced backups (30 days, enforced), and in the DR bucket's dumps and document copies (deleted after 31 days by its
  lifecycle, which may lag a day). The privacy policy says "removed from backups within 35 days of deletion".

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
| **Profile moved to another organization** (e.g. a ФОП leaves the accountant) | — | — | export → import into the target organization (`prytax:backup:import`, or the target's owner in the app, §5.1) → then "profile deleted" in the source |

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

Daily (03:40, onOneServer) → prytax:retention:rolling: one run with the technical rolling job, due now.
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
    DB::statement('SET SESSION innodb_lock_wait_timeout = 30');   // MySQL only; SQLite waits for its busy_timeout
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

As built (v1.8; the design had a few other names, such as `PruneBackups` and `VerifyRandomBackup` jobs, which are
commands now):

```
app/
├── DataLifecycle/                       PryTax\DataLifecycle\        ← generic engine (extractable as-is)
│   ├── Contracts/
│   │   ├── TableRegistry.php            tables(), excluded(), formatVersion()
│   │   ├── TenantRoot.php               table/key of the tenant root, archive folder name, label, import conflict
│   │   ├── ArchiveChecks.php            compute(tenantId, ConnectionInterface, RunSpec): array
│   │   └── RetentionFamilyHandler.php   families(): list<string>; handle(RetentionJob): RetentionResult; TAG
│   ├── Registry/                        TableSpec, Rewrite, Column, SchemaInspector (information_schema on MySQL,
│   │                                    the schema builder on SQLite), MorphAliases, ColumnSnapshot, RegistryHasher
│   ├── Backup/
│   │   ├── Internal/                    Selection, Csv, Values, ArchiveWriter, ArchiveImporter, ArchiveStore (any
│   │   │                                disk), BackupRunner, Pruner, RetentionEstimate, BundleBuilder,
│   │   │                                ArchiveRepacker, BackupVerifier
│   │   ├── Jobs/                        RunOrganizationBackup, BuildOrganizationBundle
│   │   ├── Models/, Enums/              BackupPolicy, BackupRun, Backup, BackupDownload
│   │   ├── Http/                        controllers (BackupPolicy, BackupRun, Backup, Export, Archive), requests,
│   │   │                                resources; Policies/, Repositories/, Queries/ beside it
│   │   ├── Events/                      BackupRunFinished, ArchiveImported
│   │   └── BackupRuns, RunSpec, ImportResult, ImportException
│   ├── Retention/
│   │   ├── Internal/                    DueJobs (the atomic claim), RetentionHandlers, TechnicalHandler
│   │   ├── Jobs/                        RunRetentionJob
│   │   ├── Models/                      RetentionRun, RetentionJob
│   │   ├── Events/                      RetentionJobFailed, RetentionRunCancelled
│   │   └── RetentionRuns, TenantPurger, RetentionResult
│   ├── Console/                         prytax:backup:{dispatch,prune,verify,import,snapshot},
│   │                                    prytax:retention:{execute,rolling,status,retry,cancel}
│   ├── database/migrations/             the five tables of §4.1 and §9.4
│   ├── resources/views/                 the archive's README
│   ├── config/data-lifecycle.php        the snapshot's connection, disk, queues, caps, grace and cleanup days
│   └── DataLifecycleServiceProvider.php routes, migrations, schedule, config, commands
├── Backups/                             PryTax\Backups\              ← PryTax definitions
│   ├── PryTaxTableRegistry.php          implements TableRegistry (§4.2)
│   ├── ApiCoreTables.php                Core\ tables PryTax backs up (§11.1)
│   ├── ProfileTenantRoot.php            implements TenantRoot (profiles, slug_p{id}, the tax id's conflict)
│   ├── AccountingArchiveChecks.php      implements ArchiveChecks (income by quarter, invoice totals, tax paid)
│   ├── ProfileAuditTagger.php           metadata.profile_id on audit entries (§4.2)
│   └── BackupRunNotifier.php, BackupRunNotification.php   the owners' e-mails (`notify`)
├── Retention/                           PryTax\Retention\            ← PryTax handlers
│   ├── AccountingFamilyHandler.php      reversed-registry purge (§9.5)
│   ├── DocumentsFamilyHandler.php
│   ├── ProfileFinalizeHandler.php       backups of the profile, then the profiles row
│   ├── UserRemovalHandler.php           credentials + access + user_pii on Core\ tables (§11.1)
│   ├── OrganizationPurgeHandler.php     a deleted organization's ФОП, users, audit log and row (§9.3)
│   ├── RetentionTriggers.php            model listeners that plan the deletions (§9.3)
│   └── RetentionFamilies.php            the family of every table outside the registry (§9.2)
└── Providers/DataLifecycleBindings.php  binds the contracts to PryTax\Backups / PryTax\Retention classes,
                                         tags the family handlers, the audit tagger, listens to the module's events
                                         (the owners' e-mails, AlertSync after an import)
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
| `RetentionFamilyHandler` (tagged, many) | `PryTax\Retention\*` | the `accounting`, `documents`, `profile_finalize`, `credentials` / `access` / `user_pii` and `organization_*` families; the engine ships the generic `technical` rolling handler itself (`communication`'s cutoff is `Alert`'s `model:prune`) |

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
  Still so as built (v1.8): the builds work around it by taking api-core from a checkout beside PryTax and copying it
  over the link (`builds.md` §1.2); the tagged dependency isn't done.
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
[Cloud Storage soft delete](https://docs.cloud.google.com/storage/docs/soft-delete) ·
[Cloud SQL: choose your backup option](https://docs.cloud.google.com/sql/docs/mysql/backup-recovery/backup-options) ·
[Back up Cloud SQL instances to a backup vault](https://docs.cloud.google.com/backup-disaster-recovery/docs/cloud-console/sql/csql-backup) ·
[Backup vaults](https://docs.cloud.google.com/backup-disaster-recovery/docs/concepts/backup-vault) ·
[Cloud SQL export and import using SQL dump files](https://docs.cloud.google.com/sql/docs/mysql/import-export/import-export-sql) ·
[Cloud SQL serverless exports](https://cloud.google.com/blog/products/databases/introducing-cloud-sql-serverless-exports)
