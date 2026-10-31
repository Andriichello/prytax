# PryTax — Builds: Docker, SQLite and the desktop app (v1)

Companion to `docs/specs/design.md` (v5.2). How PryTax runs besides a PHP server on MySQL: as a container image for
the web (§2), on SQLite (§3), and as a desktop app for Linux, Windows and macOS, made with NativePHP (§4). The
commands that run each are in `README.md`, and the starter, `./prytax`, runs them (§6).

**State:** as built on 2026-10-29/30, on the branches `feat/docker`, `feat/sqlite` and `feat/desktop`, merged into
`dev`. **History:** this document was the plan `APP-BUILDS-PLAN.md`, kept in the repository's root while it was built
(the owner chose NativePHP, a first-run setup and the order Docker → SQLite → desktop, and asked for a required
recovery kit, rates retried when offline, FrankenPHP in classic mode and native save dialogs). Its history is in git;
what was built differently from it is said where it applies.

---

## 1. The shape

### 1.1 One process

PryTax is one process. Vite builds the Vue SPA into `public/build`, Laravel's catch-all `Route::view('/{path?}',
'app')` (`routes/web.php`) serves it, and the API answers on the same origin under `/api`. The SPA calls relative URLs
only (`resources/js/api/client.ts`) and signs in with Sanctum's session cookie. So:
- **Web:** one image runs as three processes (the app, the queue worker and the scheduler), against MySQL in a
  container, any external MySQL, or a SQLite file (§2).
- **Desktop:** NativePHP's bundled PHP 8.5 serves the same app on `127.0.0.1:<port>` against a SQLite file in the
  user's application data, and an Electron window loads that address (§4).

What the audits before the build found, and where each is handled:
- **MySQL-only SQL** in 7 migrations, 4 runtime queries and the backup engine (§3). api-core needed nothing: its own
  tests already ran on SQLite.
- **api-core's path repository**, whose symlink breaks any build made outside the development machine (§1.2).
- **A queue worker and a scheduler are required.** Backups, bundles and period exports run only on a worker; rates,
  tax periods, alerts, backups and retention are scheduled (§2.2, §4.1).
- **No sign-up.** A fresh installation had no way to create its first user (§1.3).
- **Sanctum's stateful list must match the window's origin, port included.** Otherwise signing in falls back to
  tokens, and the SPA gets a 401 on every call (§4.2).
- **`APP_KEY` encrypts the tax IDs, IBANs and invoice snapshots.** It must be unique per installation and never lost
  (§4.4).

### 1.2 api-core in builds

`composer.json` keeps the path repository `../api-core` (`symlink: true`) for development, and every build takes
api-core from a checkout beside PryTax: Docker from a second build context (`additional_contexts: { api-core:
../api-core }`), the CI from a second checkout (§4.9).

`COMPOSER_MIRROR_PATH_REPOS=1` doesn't help: `composer.lock` pins the repository's `transport-options` (`"symlink":
true`), which beats the variable. So after `composer install` the Dockerfile replaces the link
`vendor/andriichello/api-core` with a copy (`rm` the link, then `cp -R`), and NativePHP's build copies the app with
its links followed (§4.9). The package's `config/` ships with it (`CoreServiceProvider` loads it); its `vendor/`,
`.git`, `node_modules`, `tests` and `storage` don't.

### 1.3 The first owner and registration

`PryTax\Services\Setup\OrganizationRegistrar` creates an organization (active) and its owner (verified, with
`password_updated_at`, role `owner`), who signs in right away and invites the others. Two ways lead to it:
- **`php artisan prytax:create-owner`**, through `InstallationSetup`, which refuses once any user exists (deleted ones
  included). The organization, name and email come from options or prompts; the password is always prompted, so it
  stays out of the shell's history, and the command needs a terminal. That's how a server gets its first owner.
  `--from=<zip>` (repeatable, 2026-10-31) starts the organization with the ФОПs of backup archives, a ФОП's or a
  run's bundle (an AES archive's password is prompted), all of them or nothing created (`backups.md` v1.9): moving
  from the desktop app to a server goes this way.
- **Registration from the sign-in page** (`RegistrationController`): `GET /api/registration` answers `{ enabled,
  first_owner }`, and `POST /api/registration` creates the organization and its owner and signs the SPA in, as
  api-core's login does. Both are under api-core's auth rate limit. `PRYTAX_REGISTRATION` (`prytax.registration`)
  turns it on: off by default and in `.env.docker.example` (anyone reaching a server could create organizations), on
  in `.env.example` for development, and always under NativePHP (§4.2).

On an installation without any user, Вхід opens Реєстрація at once, with a first-launch hint: that's the desktop app's
first launch. Otherwise Вхід links to it («Ще не працюєте в PryTax? Зареєструвати організацію»).

---

## 2. Docker (web)

### 2.1 The image

`Dockerfile`, in three stages:
1. **Assets:** `node:24-alpine` runs `npm ci` and `npm run build`.
2. **Vendor:** `composer install --no-dev` (Composer copied from `composer:2` into the PHP 8.5 stage, so the platform
   check passes), then api-core copied over its link (§1.2), then `composer dump-autoload --optimize`.
3. **App:** `dunglas/frankenphp` on PHP 8.5, with `pdo_mysql pdo_sqlite zip gd pcntl bcmath opcache`
   (`install-php-extensions`) and `docker/php.ini` over `php.ini-production`. zip is for backups and exports, gd for
   dompdf's images, pcntl for the worker's job timeouts; bcmath isn't needed by the app, but brick/math uses it for
   speed and `DemoSeeder` calls `bcdiv()`. It runs as `www-data` on port 8080, with a healthcheck on `/up`.

**FrankenPHP runs in classic mode** (`docker/Caddyfile`: `php_server` without a `worker` directive, as the Dockerfile
says). It boots Laravel on every request, as `php -S` and PHP-FPM do. Worker mode and Octane are left out: Octane
isn't installed, and the code (api-core's current organization, request-scoped singletons) was never checked for state
that leaks between requests. Classic mode is fast enough at PryTax's scale; worker mode would need its own audit.

`.dockerignore` keeps out `vendor`, `node_modules`, `storage/*`, `public/hot`, `.env`, `.idea` and `storage/handoff`.
The image is about 710 MB, most of it FrankenPHP's base image.

**`docker/entrypoint.sh`**, for the three services alike:
- creates the `storage/` skeleton the volume starts without: `app/private`, `app/backups`, `database`, `fonts`
  (PdfFonts installs fonts at runtime), `logs` and `framework/{cache/data,sessions,views}`;
- runs a `key:generate` at once, before anything needs a key;
- stops when `APP_KEY` is empty, with a message on how to make one;
- with `RUN_MIGRATIONS=true` (the app service only) runs `migrate --force` and `db:seed --force`: `DatabaseSeeder`
  only syncs the roles, so it's safe to repeat;
- runs `config:cache`, `route:cache`, `view:cache` and `event:cache`, then `exec "$@"`.

### 2.2 The services

`compose.yaml` runs the one image (`prytax:latest`) with `env_file: ${PRYTAX_ENV_FILE:-.env}`, the `storage` volume
and `host.docker.internal` mapped to the host:

| Service | Command | Notes |
|---|---|---|
| `app` | FrankenPHP | Builds the image; port `${APP_PORT:-8080}`; `RUN_MIGRATIONS=true`; waits for `mysql` when it runs |
| `worker` | `php artisan queue:work --queue=backups,retention,default --sleep=3 --max-time=3600` | Starts once `app` is healthy |
| `scheduler` | `php artisan schedule:work` | Starts once `app` is healthy |
| `mysql` | — | `profiles: [mysql]`, `mysql:8.4`, the `mysql` volume, a healthcheck; MySQL 8.0.13+ is required, MariaDB isn't supported |

The database is one of three:
- **MySQL in a container:** `DB_HOST=mysql` and `docker compose --profile mysql up`; the user is created on its first
  start (`DB_PASSWORD` is required).
- **An external MySQL** (cloud or local): no profile; `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
  `DB_PASSWORD` and optionally `MYSQL_ATTR_SSL_CA`. The host machine's is `host.docker.internal`, when it listens on
  Docker's bridge rather than only on 127.0.0.1.
- **SQLite** (§3): `DB_CONNECTION=sqlite`, `DB_DATABASE=/app/storage/database/prytax.sqlite`, no profile. The file is
  made on the first start, and the worker's container shares it in WAL mode.

### 2.3 Settings

`.env.docker.example` holds the three database variants, `APP_KEY`, `APP_URL`, `APP_PORT`, `SANCTUM_STATEFUL_DOMAINS`
(the public host and port), `SESSION_SECURE_COOKIE` (behind TLS) and `PRYTAX_REGISTRATION=false`.
- **The key:** `docker compose run --rm --no-deps app php artisan key:generate --show` prints one for `APP_KEY`; it
  must never change or be lost (§1.1).
- **`PRYTAX_ENV_FILE`** picks the compose file's `env_file`. A server uses `.env`; a development checkout, whose
  `.env` is the local one, uses a git-ignored `.env.docker` and adds `--env-file .env.docker` to every command.
- **`TRUSTED_PROXIES`** (opt-in, read with `env()` in `bootstrap/app.php`, as the middleware is configured before the
  config loads). Behind a TLS proxy it makes the links `https`, so downloads from an `https` page aren't blocked.

### 2.4 Checked

`/up` answers 200; an owner made by `prytax:create-owner` signs in at `http://localhost:8080`; an invoice's PDF
downloads; a manual backup finishes on the worker; `schedule:list` runs in the scheduler's container. With MySQL in a
container, with the host's MySQL, and with SQLite.

---

## 3. SQLite

The full suite passes on MySQL and on SQLite, and on MySQL the generated SQL is byte-identical to what it was, so
existing databases are unaffected. SQLite 3.38 or newer: nothing depends on the functions of 3.44 or 3.48.

### 3.1 Helpers

- **`PryTax\Helpers\Sql`** writes what the two say differently: `year()`, `quarter()` and `month()` (`YEAR(x)` and
  `CAST(strftime('%Y', x) AS INTEGER)`), `when()` (`CASE`, never `IF`) and `concat()` (`CONCAT` and `||`). Its error
  checks replace the MySQL-only ones in `InvoiceFreezer`, `InvoiceWriter` and `TenantRepository`:
  `violatesUnique()` (MySQL names the index, SQLite lists its columns) and `restrictsDelete()` (MySQL's 1451,
  SQLite's `FOREIGN KEY constraint failed`).
- **`PryTax\Helpers\CheckConstraint::add()` / `drop()`**: on MySQL `ALTER TABLE … ADD CONSTRAINT … CHECK`, on SQLite
  `BEFORE INSERT` and `BEFORE UPDATE` triggers that `RAISE(ABORT, '<name>')`.

### 3.2 Migrations

Seven: `create_invoices` (`IF` became `CASE`), `create_payments`, `create_refunds`, `create_account_transfers`,
`create_transfer_allocations`, `create_tax_payments`, `add_virtual_invoices` and
`add_original_amount_and_one_payment_per_receipt_tax`. The last uses `virtualAs` on SQLite, which can't add a STORED
column to an existing table.

### 3.3 Queries

- `InvoiceController`'s facets and `AccountingArchiveChecks` use `Sql::year/quarter/concat`.
- The backups' `Selection` reads a JSON path with a switch of its own (`CAST(json_extract(…) AS INTEGER)` on SQLite,
  `JSON_UNQUOTE(JSON_EXTRACT(…))` on MySQL), and limits a quarter's or a month's rows by comparing their year and
  quarter (or month) columns with the period's first and last, instead of `MAKEDATE`/`INTERVAL`.
- The accounting view's invoices are ordered by id: MySQL returned them in that order by chance, SQLite doesn't.
- Dates are stored as `Y-m-d` on SQLite too, so date comparisons behave as on MySQL.

### 3.4 The backup engine

DataLifecycle stays self-contained: its driver switches are its own, not `PryTax\Helpers\Sql` (`backups.md`).
- `SchemaInspector` keeps its single `information_schema` query on MySQL and uses the schema builder
  (`Schema::getTables/getColumns/getForeignKeys`) on SQLite; generated columns come from a column's `generation`.
- `BackupRunner`'s snapshot is a `BEGIN DEFERRED` read transaction on SQLite (consistent under WAL), and
  `UTC_TIMESTAMP()` becomes `datetime('now')`. `TenantPurger`'s `SET SESSION` runs on MySQL only.
- `Column` tells JSON and dates by the declared type the inspector reads. SQLite declares JSON columns as `text`, so
  its archives keep JSON as stored, without compacting it.
- Values are exported as stored (decimals unformatted), but the manifest's checks are sums with two decimals on both
  drivers (`AccountingArchiveChecks`: MySQL sums to `1234.50`, SQLite to `1234.5`). That's meant to let an archive
  move between the drivers (desktop ↔ cloud); no test covers it yet (§5).

### 3.5 The connection

`config/database.php`'s `sqlite`, from the environment: `journal_mode=wal`, `busy_timeout=5000`,
`synchronous=normal` and `transaction_mode=IMMEDIATE`. The app, its worker and its scheduler share the file: WAL lets
them read while one writes, and a writer waits its turn instead of failing with "database is locked" halfway through,
which stands in for the row locks SQLite ignores.

### 3.6 Ukrainian text

A connector bound in `AppServiceProvider` registers `LOWER`, `UPPER` and `LIKE` on every SQLite PDO, reconnections
included (`SqliteFunctions`, on `mb_strtolower`/`mb_strtoupper`, `Pdo\Sqlite::createFunction`). api-core's plain
`like` filters ignore case on MySQL, so they now do on SQLite too, Cyrillic included. Sorting is byte order on SQLite
(`PracticeOverview`'s `orderBy('full_name')`), a known difference left until it shows.

### 3.7 Numbers

SQLite returns decimals and raw `SUM`s as floats.
- Model casts return strings; every raw sum goes through `Decimal::of((string) …)`, and PHP's 14-digit float-to-string
  conversion gives the exact kopeck below 10¹².
- PHP coerces a float passed to an `int|string` parameter into an int (`3892.5` became `3892`), so
  `Invoice::$paid_amount`, client stats and alerts lost their kopecks: `Decimal` and `MoneyFormatter` take floats
  now, read to six places.

### 3.8 Tests and checks

- `composer test:sqlite` runs the suite on `:memory:`; both it and the MySQL run must pass. `VerifyDatabaseTest` uses
  `Schema::disableForeignKeyConstraints()` instead of `SET FOREIGN_KEY_CHECKS`. `SqlTest` covers `Sql`'s date parts
  and strings, the case of Ukrainian letters, and `CheckConstraint` (a negative amount is refused), on whichever
  driver the suite runs.
- The demo practice seeded into both databases gives 76 identical report responses (Облік, Податки, the books, ЄСВ,
  fees, declarations, invoices, payments, accounts, clients; four ФОП).
- On SQLite the screens render in uk and en, an invoice's PDF downloads, a manual backup and a period export complete
  on a queue worker, and Cyrillic `like` filters ignore case.

---

## 4. The desktop app (NativePHP)

### 4.1 NativePHP's runtime

`nativephp/desktop` `^2.4`. `native:install` added the `native:dev` script, a `post-update-cmd` hook,
`config/nativephp.php` and the provider, which is `PryTax\Providers\NativeAppServiceProvider`.

| Question | Answer |
|---|---|
| The bundled PHP | 8.5, static musl, with zip (AES-256), gd, mbstring, dom, iconv, fileinfo, intl, bcmath and pdo_sqlite (`Pdo\Sqlite`, SQLite 3.45). No pcntl, so the worker can't enforce job timeouts; no xmlwriter or xmlreader, so PHPUnit can't run on it and openspout (XLSX) must be checked before it ships. |
| PryTax on it | Migrations, `DemoSeeder`, a PDF, a backup and a period export work; the 76 report responses are identical to MySQL's. |
| Port | The first free one from 8100. localStorage stays between launches. |
| Storage and database | Production: `<userData>/storage` and `<userData>/database/database.sqlite`, where userData is `~/.config/<app>`, `%APPDATA%\<app>` or `~/Library/Application Support/<app>`. Development: `~/.config/prytax-dev/storage` and the project's `database/nativephp.sqlite` (`PryTax\Helpers\Desktop`). |
| `.env` | The build copies it, removes `cleanup_env_keys` and forces the log settings. NativePHP never makes a key per installation (§4.4). |
| Scheduler | Electron runs `artisan schedule:run` every minute, and stops during sleep: no `schedule:work`. |
| Migrations | `artisan migrate --force` on the first launch of each new version (production only), after a full boot. |

What NativePHP overrides when it runs (`NativeServiceProvider`):
- **The database:** `database.default` becomes its own `nativephp` connection, with WAL and `busy_timeout=5000` but
  not our `transaction_mode` (§4.2).
- **Sessions** go to files, the **queue** to the database, and its **workers** come from `queue_workers`.
- **`PreventRegularBrowserAccess`** answers 403 without the `_php_native` cookie or secret header (§4.3).

### 4.2 The window and its settings

- **`config/nativephp.php`:** `app_id` `com.prytax.desktop`, version `0.1.0`; one queue worker on `backups`,
  `retention` and `default` (timeout 1800 s, 512 MB, as a backup may take half an hour); `npm run build` before a
  build; `cleanup_env_keys` drops `APP_KEY`, `DB_*`, `MAIL_*`, `SANCTUM_*`, `CORS_*`, `TRUSTED_PROXIES`, passwords,
  secrets and the cloud and signing keys; `cleanup_exclude_files` drops `docs`, `docker`, `tests`, development data
  and notes, the development key files and api-core's own `vendor/`, `.git`, `node_modules` and `storage`.
- **`NativeAppServiceProvider`:** the window, 1280 × 800, at least 600 × 640 px, so the desktop layout always applies
  (`design.md` §17.1: a rail of icons below 1024 px), with its state remembered and Chromium's PDF viewer
  (`plugins`) for the invoice's preview. Its `phpIni()` gives every PHP process 512 MB and 200 MB uploads (as large
  as a backup's import takes; 32 MB until 2026-10-31), without a time limit. It also starts the launch's catching up (§4.7).
- **Under NativePHP** (`AppServiceProvider`, when `Desktop::isRunning()`): the installation's key (§4.4); registration
  on (§1.3); the file cache, as a `database` store would bind to the configured MySQL connection before NativePHP
  replaces it, and NativePHP's PHP has no driver for it; mail to the log; `sanctum.stateful` with the request's host,
  so whatever port NativePHP picks signs in with the session; `busy_timeout`, `journal_mode`, `synchronous` and
  `transaction_mode` copied onto the `nativephp` connection, which is then purged; the guard of §4.3.
- **Roles:** nobody seeds them by hand, so `SyncRolesAfterMigrations` syncs them after NativePHP's migrations
  (`MigrationsEnded`, `NoPendingMigrations`). Without it the first owner couldn't register, and an update's new
  permissions wouldn't reach the existing roles.
- **`GET /api/environment`** tells the SPA it runs in the window (`useDesktopApp`).

### 4.3 Only the window

`PreventRegularBrowserAccess` keeps other programs and web pages away from `127.0.0.1:<port>`. NativePHP pushes it
onto a kernel it makes itself (`make(Foundation\Http\Kernel::class)`), never the app's, so it let everything through
(200 without the secret). The app's kernel now prepends it under NativePHP: 403 without the secret, 200 with its
header (which Electron adds to the window's every request) or cookie, and a cross-site form POST to registration is a
403. In development the window's own server answers 403 to other browsers too.

### 4.4 The installation's key

The bundle carries no `APP_KEY`, so `InstallationKey` makes one on the first launch, before anything is encrypted with
it, and keeps it beside the database in `database.sqlite.key` (mode 0600). The bundled key is kept only while
developing (`native:run` with the `.env`'s key), or for a database with data but no key file, which the bundled key
encrypted.

**The trap it guards against:** someone moves to a new laptop and copies the database but not the key; every tax ID,
IBAN and invoice snapshot is lost for good. Hence the recovery kit (§4.5) and the restore (§4.6).

### 4.5 The first launch and the recovery kit

The first launch opens Реєстрація (§1.3), with a link to Відновлення (§4.6). After registering, the owner keeps
**Набір відновлення** before working: the router's guard sends them to it until they do.
- **The kit** (`RecoveryKit`): a text file with the key, the date it was made, the database's path, the organizations
  and how to restore on another computer. It's never sent anywhere.
- **`GET`, `POST save` and `POST confirm` on `desktop/recovery-kit`** (`RecoveryKitController`, owners only, 404
  outside the desktop app): the text; saving it through a native save dialog (`Dialog::new()->save()`); the owner's
  word that they copied it somewhere safe. The time it was kept is recorded beside the database
  (`database.sqlite.recovery`) and shown on the page.
- Резервні копії shows it on its Застосунок tab (2026-10-31; `RecoveryKitPanel`, which the setup's page frames too), so it
  can be kept again at any time without leaving Налаштування.

### 4.6 Restore on a new computer

From the first screen, before anyone registered (`DesktopRestoreController`, `POST desktop/restore/kit`,
`desktop/restore/database` and `desktop/restore`; `RestorePage`): the kit and the old computer's database, each chosen
in a native dialog. `DatabaseRestore` reads the kit's key and checks that it decrypts the database's tax IDs; a wrong
key or a file that isn't PryTax's is refused before anything is touched. Then the worker is stopped, NativePHP's
connection closed, the file swapped in (the new installation's WAL removed), the key replaced, and the app relaunched.

The whole installation, documents and in-app backups too, moves with a full backup instead (§4.11), which this restore
predates: it carries no documents. Its ФОПs alone can be restored too (2026-10-31): register, then Новий ФОП →
«Відновити з архіву» takes a ФОП's archive or a bundle of every ФОП (`backups.md` v1.9).

### 4.7 Catching up on launch

The desktop app is rarely open at 01:00, when a server fetches the day's rates and builds tax periods and
notifications. `LaunchCatchUp` (from the window's provider) queues them on every launch, without delaying the window:
- **`FetchRatesJob`** wraps the same service as `prytax:fetch-rates`. A day that can't be fetched (offline, an outage)
  puts the job back after 1, 5 and 15 minutes, then every 30, for up to 12 hours (`retryUntil`), instead of failing
  it; one at a time, so a second launch doesn't queue another. Meanwhile a receipt takes a manual rate, as it always
  may. The boot itself never calls the network.
- **`prytax:schedule-tax-periods`** and **`prytax:sync-alerts`**, queued.
- **Backups** need nothing: a policy's missed window is due at the dispatcher's next run (every five minutes). The
  backups' pruning (05:30), the rolling cleanup (03:40) and the weekly check (Sunday 06:00) aren't caught up: they run
  only when the app is open at that time (§5).

### 4.8 Files and downloads

Electron's own download handling saves every file through its native save dialog, with no code of ours. Checked in the
development window on 2026-10-30 (driven over DevTools, downloads sent to a folder instead of the dialog): Облік's CSV,
Книги's PDF, an invoice's PDF, a backup's zip and Облік's period archive (a queued export, then `window.location`)
arrive whole; the style preview renders, and «Відкрити PDF» opens a window of its own in Chromium's viewer. So the
planned `useDownload()` composable and a desktop-only save endpoint aren't needed and weren't built. pdf.js runs on its
legacy build (Electron 40's Chromium lacks `Map.prototype.getOrInsertComputed`).

### 4.9 Builds

- **Local:** `php artisan native:build linux x64`, with Node 22+ (24 is used) and PHP 8.5 as `php` on the PATH for the
  build's own `composer install --no-dev`. It makes `nativephp/electron/dist/PryTax-0.1.0.AppImage` (161 MB) and
  `prytax_0.1.0_amd64.deb` (123 MB). The build copies the app with links followed, so api-core arrives as a folder
  (§1.2), and runs `npm run build` first.
- **The bundle, checked:** its `.env` has no `APP_KEY`, `DB_*`, `MAIL_*` or `SANCTUM_*`; no database, key, kit record
  or `storage/app` is shipped, only the storage skeleton and the PDF fonts. Its first launch (the bundle's PHP with
  Electron's production variables) ran 61 migrations, synced the roles and made a fresh key (0600).
- **The AppImage on Ubuntu 24.04+** needs `--no-sandbox` (its `chrome-sandbox` can't be setuid inside the image); the
  `.deb` installs it properly.
- **CI:** `.github/workflows/desktop.yml` builds Linux (AppImage, deb), Windows (NSIS) and macOS (dmg, arm64), unsigned,
  by hand (Actions → Desktop builds) or on a pushed `desktop-v*` tag, and uploads them as artifacts. It checks api-core
  out beside PryTax with the `API_CORE_TOKEN` secret (a fine-grained token that may read `Andriichello/api-core`,
  which is private). `workflow_dispatch` only shows once the workflow is on `main`, the default branch; a tag runs it
  from any commit.
- **Signing is left out** (the owner, 2026-10-30: no certificates). Unsigned builds make macOS Gatekeeper and Windows
  SmartScreen warn on the first launch. NativePHP's builder signs when its variables are set: Apple's
  `NATIVEPHP_APPLE_ID`, `NATIVEPHP_APPLE_ID_PASS` and `NATIVEPHP_APPLE_TEAM_ID` with a Developer ID certificate, and
  `NATIVEPHP_AZURE_*` for Azure Trusted Signing on Windows.
- NativePHP migrates on each new version, so the SQLite suite (§3.8) guards upgrades.

### 4.10 Development

- **`chrome-sandbox`** (`vendor/nativephp/desktop/resources/electron/node_modules/electron/dist/`) must be owned by root
  with mode 4755 on Linux, again after every `native:install` or `npm install` of NativePHP's Electron.
- **`php artisan native:run`** opens the window on `database/nativephp.sqlite` (`composer native:dev` adds Vite's
  hot reload). `REMOTE_DEBUGGING_PORT=9333` lets scripts drive it over DevTools. After SPA changes, `npm run build` and
  Ctrl+R; changes to providers or jobs need a restart (the queue worker keeps its code).
- **Migrate once after the first `native:run`, and after new migrations:** NativePHP's automatic migration runs while
  its provider boots, before DataLifecycle registers its five migrations. `native:migrate`, `native:seed` and
  `native:migrate:fresh` run outside NativePHP's runtime, so they need `CACHE_STORE=file`, plus
  `LARAVEL_STORAGE_PATH=~/.config/prytax-dev/storage` for seeders that store files.

---

### 4.11 A full backup of the whole app, and restoring it

Added on 2026-10-31 at the owner's request: move the whole app from one computer to another, and bring an older
computer to another's state, seeing first what changes. Only where `prytax.full_backups` (`PRYTAX_FULL_BACKUPS`)
allows it: always in the desktop app, on in `.env.example` for a web app run on this computer, off in
`.env.docker.example` (a restore replaces every organization). It needs PryTax on a SQLite file (the owner chose
SQLite only); elsewhere Резервні копії says so. Owners only, or anyone before the first user registered (a new
computer's first launch: Реєстрація links to it, `/restore/full`). `InstallationBackupController`, `api/installation`.
- **The backup** (`InstallationBackup`): one zip, every entry AES-256 with a password (required, 8+, as the key opens
  everything): `manifest.json` (format `prytax-installation` v1, `made_at`, the data's `last_change_at`, the latest
  migration, the organizations with their ФОП, the documents' and archives' count and size), `key`, `database.sqlite`
  (`VACUUM INTO`, consistent with its WAL), `documents/` (the files disk's `files/`) and `archives/` (the backups
  disk but `tmp/`: the in-app backups go along, as the owner chose). Saved through a native dialog in the desktop app,
  else through a signed link (30 min), removed after it.
- **Choosing a backup:** the desktop app's native dialog (a path, so no upload limit); a browser uploads it in 1 MB
  pieces (`installation/uploads`, each appended at its offset, a wrong one is a 409), as PHP takes 2 MB uploads by
  default. Work files live in `storage/framework/installation/` (outside the disks a backup carries), removed after a
  day.
- **The comparison** (`InstallationComparison`): every table of both databases, row by row (by `id`, else by the whole
  row), encrypted values decrypted with each side's key, the keyed hash (`tax_id_hash`) skipped, and what signing in
  writes (`last_login_at`, `remember_token`; a row only touched) taken for no change. Rows only in the backup come;
  rows only here, or changed here later than the backup's (by `updated_at`), are lost. No loss: **fast-forward**;
  losses: **rewrite**, with their count; no difference: **identical**. Sessions, caches, the queue and one-time tokens
  aren't compared; history, derived rows, caches and seeded rows (audit log, alerts, notifications, NBU rates, in-app
  backups, retention runs, roles and permissions, tax rules, holidays) are shown apart and don't decide it. The
  schema: the same, older (fewer migrations: migrated after the restore) or newer (refused: update the app first).
  The screen shows when the backup was made, the last change in it and here, both sides' organizations, and each
  table's rows (100 at most, those lost first) with their changed values.
- **The restore** (`InstallationRestore`): only after this computer's state was saved (a full backup for that
  restore's token, saved or downloaded), unless nobody registered here. The backup's database is prepared on a copy:
  its encrypted columns (`InstallationTables::ENCRYPTED`, which a test keeps equal to the models' encrypted casts)
  encrypted again with this installation's key, so its key and recovery kit stay; the tax IDs' hashes computed again;
  another computer's sessions, caches and queued jobs emptied. Then the queue worker is stopped (desktop), everyone
  signed out (and file sessions removed), the file swapped in (its WAL removed), the documents and in-app backups
  replaced (the backups disk's `tmp/` kept), the migrations and the roles' sync run; the desktop app relaunches, a
  browser opens the sign-in with a notice, and a web app's queue worker is told to restart (`queue:restart`; the
  starter's group then stops: start it again).
- **Checked live** (two scratch SQLite installations, `php -S` with PHP's 2 MB uploads, headless Chrome): computer 1's
  backup (5 MB, 87 files) uploaded in pieces on computer 2, compared (rewrite: computer 2's renamed organization, its
  owner, four tax periods), this state saved, restored: computer 2 had computer 1's organization, users and ФОПs, and
  signed in with computer 1's account; restoring the same backup again was identical. The desktop's native dialogs
  and relaunch weren't run (the owner's window was open). Tests: `InstallationComparisonTest`, `InstallationRestoreTest`,
  `InstallationTablesTest`, `InstallationBackupControllerTest`.

## 5. Open

- **Signing and notarization** (§4.9), and **the CI**, untested until it's pushed and `API_CORE_TOKEN` is set; so the
  Windows and macOS builds are unchecked.
- **The desktop's full restore** (§4.11) wasn't run in the window (its native dialogs and relaunch); a browser's was.
  The old restore (§4.6: the recovery kit and the database) still carries no documents.
- **XLSX exports:** openspout isn't installed, and the bundled PHP has no xmlwriter or xmlreader (§4.1).
- **Job timeouts on the desktop:** the bundled PHP has no pcntl, so a stuck job isn't stopped.
- **Mail on the desktop** goes to the log (§4.2), so its invitations, password resets («Забули пароль?») and the
  backups' e-mails reach nobody: a desktop owner who forgets the password can't reset it.
- **The desktop's daily backup jobs:** pruning, the rolling cleanup and the weekly check aren't caught up on launch
  (§4.7), so a desktop that is closed at night never prunes its archives to the owner's policy.
- **Updates:** NativePHP's updater has no provider set up, so a new version is installed by hand.
- **Sorting on SQLite** is byte order (§3.6).
- **An archive moved between MySQL and SQLite** isn't tested (§3.4).
- **FrankenPHP's worker mode** would need an audit of state between requests (§2.1).

---

## 6. The starter (`./prytax`)

Added on 2026-10-30, at the owner's request: one program that starts PryTax either way, with the chores around it.
- **`./prytax`** (a bash script in the root) finds PHP 8.5 (`PRYTAX_PHP`, `php8.5`, or `php` if it's 8.5) and runs
  `php artisan prytax:start` (`PryTax\Console\Commands\Start`), a Laravel Prompts menu, or the action named:
  `./prytax web`.
- **The menu** (reshaped on 2026-10-31 at the owner's request: too many options at once) first lists the apps (web,
  Docker, desktop) as running or not, and Vite, the phone server and ngrok when they run. Then the platform: Web
  (here or in Docker) or Desktop (the development window, with DevTools or without); the labels say what runs.
  `Menu` (a `SelectPrompt` with its own `MenuRenderer`) draws the lines that lead out below the box, aligned with
  its options and reached by the same arrows: Tools (status, doctor, tests, builds, databases) on the first menu,
  Back on the others, Quit last with Esc beside it. Esc chooses Quit in a menu; in any other question it cancels as
  Ctrl+C does (`EscapeTerminal`, the prompts' terminal while the starter runs). After an action the menu comes back
  (a tool's to its tools), until Quit; a Ctrl+C that stops a child (tests, logs) doesn't stop the starter. The actions are classes in `app/Console/Starter/` (`WebAction`, `DockerAction`, `DesktopAction`,
  `PhoneAction`, `StatusAction`, `DoctorAction`, `TestAction`, `BuildAction`, `DatabaseAction`), with what they share:
  - `Toolchain`: this PHP linked as `php` in `<tmp>/prytax-<uid>/bin`, first on every child's PATH (`native:run`
    and `native:build` call `php`), and nvm's newest Node of 22 or later when the PATH's is older.
  - `Listeners`: what listens on PryTax's ports (`ss -ltnpH`, else `lsof`): the web app (APP_URL's port, 8010), Vite
    5173, the desktop app 8100 (DevTools 9333), the phone 8012, ngrok 4040, Docker (`.env.docker`'s APP_PORT, 8080),
    MySQL 3306; and stopping them (SIGTERM; the desktop app by its Electron's main process, as closing its window).
  - `ProcessGroup`: several processes in the foreground with their names before their lines, until Ctrl+C or until
    one of those it started with ends, which stops the rest (SIGTERM, SIGKILL after 5 s). Meanwhile it reads keys
    from the terminal (`-icanon -echo`, so Ctrl+C still interrupts) and starts or stops more processes, a tunnel,
    whose end stops nothing else.
  - `Tunnel`: `ngrok http 127.0.0.1:<port> --log=stdout --log-format=json` as the group's `ngrok`, which prints
    `Open <url> on the phone…` once the log's `started tunnel` has the address, and ngrok's errors.
  - `Database`: the web app's (`.env`), the desktop app's (`database/nativephp.sqlite`, with `CACHE_STORE=file` and
    its storage as `LARAVEL_STORAGE_PATH`, as in §4.10) or a SQLite file, as the variables of a child process; its
    pending migrations; a copy of a SQLite file beside it (`VACUUM INTO`, so with its WAL), before it's changed.
  - `Doctor`: the checks of `./prytax doctor`, which the actions reuse (the desktop's, the SPA's build).
- **Before starting**, an action offers what it needs: a new SQLite file is migrated and seeded (the roles, then the
  demo data if wanted), pending migrations run after a copy (then `db:seed`, which syncs the roles, as the Docker
  entrypoint does), a stale or missing SPA build is built. A port in use is refused, with `./prytax status` to see it.
- **A tunnel's address signs in** because the server behind it runs with the requests' own host stateful (Sanctum's
  current-host placeholder after the configured domains) and ngrok's agent trusted as a proxy (`TRUSTED_PROXIES=
  127.0.0.1`, so the pages and their assets are https); APP_URL and ASSET_URL stay as they are. Checked with
  requests carrying ngrok's headers (Host, X-Forwarded-Proto/Host/For): assets on `https://<host>/build/…`, sign-in
  and `auth/me` answered; the cookies are `secure`, as https wants.
- **web** runs `artisan serve --no-reload` on 127.0.0.1, `queue:work --queue=backups,retention,default` (not
  `queue:listen`, whose worker would outlive it) and `schedule:work`, and Vite with `--vite`, always ready for a
  tunnel, which **n** opens (not with Vite, whose SPA a phone couldn't load). Another port than APP_URL's gets its
  own APP_URL. When its port is in use, it offers to restart or stop what's there (stopped by its port), and refuses
  without interaction.
- **docker** makes `.env.docker` from the example when it's missing (PRYTAX_ENV_FILE set, a new APP_KEY, a random
  database password; mode 0600), runs `docker compose --env-file … [--profile mysql] up -d --build` unless it runs
  already, then a menu: a tunnel, the first owner, the logs, stopping it (`down` with the MySQL profile). The tunnel
  makes the `app` container again (`up -d app` with `storage/framework/prytax-tunnel.compose.yaml`, which adds the
  current-host placeholder to its stateful domains and `TRUSTED_PROXIES=PRIVATE_SUBNETS`, as Docker's network is
  between), opens ngrok until Ctrl+C, then makes it again without the file, which it removes. Not run live (it
  would build the image).
- **desktop** checks Node, Electron (offering `native:install`) and its sandbox (§4.10; the fix needs sudo, so it's
  shown, not run), then runs `native:run --no-dependencies --no-interaction -v` in a group (without interaction its
  dev script doesn't take the terminal; `-v` shows its log) with `PRYTAX_DEVTOOLS` and `REMOTE_DEBUGGING_PORT=9333`.
  **n** starts the phone server on its data and a tunnel to it (the window's own server refuses browsers). When it
  runs already, it offers that tunnel (`phone --source=desktop`) or closing it.
  `prytax.desktop_devtools` (`PRYTAX_DEVTOOLS`) decides the window's DevTools; unset, they follow APP_DEBUG.
- **phone** serves PryTax on 8012 (PHP's own server from `public/`, `variables_order=EGPCS`, so LARAVEL_STORAGE_PATH
  reaches it), with registration off and the tunnel's address signing in, and a tunnel to it; the desktop's **n**
  does the same while its window runs.
- **stop** without names asks, or refuses when it can't ask (`--no-interaction`), so it never stops what wasn't meant;
  the system's MySQL is only shown.
- **db: starting over** (`migrate:fresh --seed` and the demo data) waits for the app on that database to close, asks
  for its name to be typed, and keeps a copy of a SQLite file first.
- **Tests:** `StartTest` (processes faked): the menu from the platforms to the tools and back, then Quit, with what
  runs told first; the web app on a new SQLite file, ready for a tunnel; a port in use refused without interaction,
  or the web app there stopped when chosen; the desktop window's PHP and DevTools; Docker's settings made, and its
  tunnel's container made again and back; the phone's server and its tunnel's address from ngrok's log; starting
  over only once the name is typed and after a copy; the parsing of `ss` and `lsof`. `MenuTest`: Esc chooses Quit,
  the arrows reach the lines below the box, which are drawn under it with Esc at its edge.
- **Laravel PAO** (`laravel/pao`, a dev dependency) strips decoration from artisan's output when an AI agent runs it
  (`CLAUDECODE` and the like): colours, box drawing, some symbols (⚠, →, ●) and runs of spaces. That, not Laravel,
  is why an agent sees the menu without its box; a person's terminal shows it whole. To see it as a person does,
  run without those variables (`env -u CLAUDECODE -u AI_AGENT …`), or set `PAO_DISABLE=1`.
