# PryTax — Builds: Docker, SQLite and the desktop app (v1)

Companion to `docs/specs/design.md` (v5.2). How PryTax runs besides a PHP server on MySQL: as a container image for
the web (§2), on SQLite (§3), and as a desktop app for Linux, Windows and macOS, made with NativePHP (§4). The
commands that run each are in `README.md`.

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
  (`plugins`) for the invoice's preview. Its `phpIni()` gives every PHP process 512 MB and 32 MB uploads, without a
  time limit. It also starts the launch's catching up (§4.7).
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
- Резервні копії links back to it, so it can be kept again at any time.

### 4.6 Restore on a new computer

From the first screen, before anyone registered (`DesktopRestoreController`, `POST desktop/restore/kit`,
`desktop/restore/database` and `desktop/restore`; `RestorePage`): the kit and the old computer's database, each chosen
in a native dialog. `DatabaseRestore` reads the kit's key and checks that it decrypts the database's tax IDs; a wrong
key or a file that isn't PryTax's is refused before anything is touched. Then the worker is stopped, NativePHP's
connection closed, the file swapped in (the new installation's WAL removed), the key replaced, and the app relaunched.

Restoring from an in-app backup archive waits for `backups.md` §5.3. A ФОП's archive can already be imported into an
organization as a new ФОП (Відновити з архіву, `backups.md`).

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

## 5. Open

- **Signing and notarization** (§4.9), and **the CI**, untested until it's pushed and `API_CORE_TOKEN` is set; so the
  Windows and macOS builds are unchecked.
- **Restoring the desktop from an in-app backup archive** (§4.6), after `backups.md` §5.3.
- **XLSX exports:** openspout isn't installed, and the bundled PHP has no xmlwriter or xmlreader (§4.1).
- **Job timeouts on the desktop:** the bundled PHP has no pcntl, so a stuck job isn't stopped.
- **The desktop's daily backup jobs:** pruning, the rolling cleanup and the weekly check aren't caught up on launch
  (§4.7), so a desktop that is closed at night never prunes its archives to the owner's policy.
- **Updates:** NativePHP's updater has no provider set up, so a new version is installed by hand.
- **Sorting on SQLite** is byte order (§3.6).
- **An archive moved between MySQL and SQLite** isn't tested (§3.4).
- **FrankenPHP's worker mode** would need an audit of state between requests (§2.1).
