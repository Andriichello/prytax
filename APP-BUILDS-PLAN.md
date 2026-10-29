# PryTax: Docker for the web, SQLite, and a desktop app (NativePHP)

## Context
You want PryTax to run in two ways:
- **Web:** in containers, with MySQL either in a container or reached by host and credentials.
- **Desktop:** cross-platform (Linux, Windows, macOS), with the backend on SQLite.

**How it fits together:** PryTax is already one process. Vite builds the Vue SPA into `public/build`. Laravel's catch-all
`Route::view('/{path?}', 'app')` (`routes/web.php`) serves it, and the API runs on the same origin under `/api`.
The SPA uses relative URLs only (`resources/js/api/client.ts:42`) and logs in with a Sanctum session cookie. That gives:
- **Web:** one image runs as three processes: the app, the queue worker and the scheduler. The database is a compose
  profile or any external host.
- **Desktop:** a bundled PHP 8.5 runtime serves the same app on `127.0.0.1:<port>` against a SQLite file in the user's
  app-data folder, and an Electron window loads that address. NativePHP provides this.

**Decisions taken:**
- NativePHP for the desktop shell.
- A first-run setup screen creates the owner on desktop.
- Order: Docker first, then the SQLite port, then the desktop app.
- Each phase gets its own branch (`feat/docker`, `feat/sqlite`, `feat/desktop`) and small commits per layer, with no
  Co-Authored-By trailer.
- `MEMORY.md` is updated at the end of each phase.

**What the audits found that this plan has to handle:**
- **MySQL-only SQL** in 7 PryTax migrations, 4 runtime queries and the backup engine. api-core needs nothing; its own
  tests already run on SQLite.
- **The api-core path repository** (`composer.json:8-19`, `symlink: true`), whose symlink breaks any build made
  outside this machine.
- **A required queue worker and scheduler.** Backups, bundles and period exports only run on a worker. Rates, tax
  periods, alerts, backups and retention are scheduled.
- **No sign-up endpoint.** A fresh install has no way to create its first user.
- **Sanctum's stateful list must match the window's origin, port included.** Otherwise login silently falls back to
  tokens and the SPA gets a 401 on every call.
- **`APP_KEY` encrypts tax IDs, IBANs and invoice snapshots.** It must be unique per install and must never be lost.

## Phase 0: api-core in builds (no `composer.json` change)
- Keep the path repository for local development.
- Builds need api-core next to prytax at `../api-core`:
  - Docker: `build.additional_contexts: { api-core: ../api-core }`.
  - CI: a second checkout of `Andriichello/api-core` into `../api-core`.
- **As built:** `COMPOSER_MIRROR_PATH_REPOS=1` doesn't help. `composer.lock` pins the path repository's
  `transport-options` (`"symlink": true`), which beats the environment variable. So after `composer install` a build
  replaces the link `vendor/andriichello/api-core` with a copy of api-core (`rm` the link, then `cp -R`). The
  Dockerfile does this, and the desktop builds (Phase 3) must do the same.
- The package's `config/` must ship with it (`CoreServiceProvider.php:189`). Its `vendor/`, `.git` and `tests` must
  not.

## Phase 1: Docker (web), branch `feat/docker`
**Status: built and checked** (2026-10-29). The departures from the plan below:
- **bcmath** joined the extensions. The app doesn't need it, but brick/math uses it for speed, and `DemoSeeder` calls
  `bcdiv()`.
- **`TRUSTED_PROXIES`** (opt-in, read with `env()` in `bootstrap/app.php`, because the middleware is configured
  before the config loads). Behind a TLS proxy it makes the links `https`, so downloads from an `https` page aren't
  blocked.
- **`PRYTAX_ENV_FILE`** picks the compose file's `env_file`. A server uses `.env`; a development checkout uses a
  git-ignored `.env.docker` and adds `--env-file .env.docker` to every command.
- **The entrypoint stops when `APP_KEY` is empty**, except when it is running `key:generate`.
- **`prytax:create-owner` prompts for the password**, so it needs a terminal: `docker compose exec app php artisan
  prytax:create-owner`.
- **The image is about 710 MB**, most of it the FrankenPHP base image.

**New files** (`docker/` is a new top-level folder; approving this plan approves it):
- `Dockerfile`, multi-stage:
  1. `node:24` runs `npm ci && npm run build`.
  2. `composer install --no-dev -o` (Composer copied from `composer:2` into the PHP 8.5 stage, so the platform
     check passes), then api-core copied over its link.
  3. The runtime is `dunglas/frankenphp` on PHP 8.5, with `install-php-extensions pdo_mysql pdo_sqlite zip gd pcntl
     opcache`. FrankenPHP serves `public/` and PHP in one process.
- **FrankenPHP runs in classic mode.** It boots Laravel on every request, exactly as `php -S` and PHP-FPM do today.
  - `docker/Caddyfile` uses `php_server` with no `worker` directive, and a comment in the Dockerfile says so.
  - Octane and worker mode are left out: Octane isn't installed, and the code (api-core's current organization,
    request-scoped singletons) was never checked for state leaking between requests.
  - Classic mode is fast enough at PryTax's scale. Switching to worker mode later would need its own audit.
- `.dockerignore`: excludes `vendor`, `node_modules`, `storage/*`, `public/hot`, `.env`, `.idea` and `storage/handoff`.
- `docker/entrypoint.sh`:
  - Creates the `storage/` skeleton: `framework/{cache,sessions,views}`, `app/private`, `app/backups`, `logs` and
    `fonts`, because PdfFonts installs fonts at runtime.
  - When `RUN_MIGRATIONS=true` (the app service only), runs `migrate --force` and `db:seed --force`.
    `DatabaseSeeder` only syncs roles, so it's safe to repeat.
  - Runs `config:cache`, `route:cache`, `view:cache` and `event:cache`, then `exec "$@"`.
- `compose.yaml`, all services on one image with `env_file: .env` and a `storage` volume:

  | Service | Command | Notes |
  |---|---|---|
  | `app` | FrankenPHP | port `${APP_PORT:-8080}`, healthcheck on `/up` |
  | `worker` | `php artisan queue:work --queue=backups,retention,default` | |
  | `scheduler` | `php artisan schedule:work` | |
  | `mysql` | — | `profiles: [mysql]`, `mysql:8.4`, named volume, healthcheck. MySQL 8.0.13+ is required; MariaDB isn't supported (README). |

  - With MySQL in a container: `docker compose --profile mysql up` and `DB_HOST=mysql`.
  - With an external database (cloud or local): don't enable the profile; set `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
    `DB_USERNAME`, `DB_PASSWORD` and optionally `MYSQL_ATTR_SSL_CA`.
  - With SQLite (after Phase 2): `DB_CONNECTION=sqlite`, `DB_DATABASE=/app/storage/database/prytax.sqlite`.
- `.env.docker.example` shows the three database variants plus `APP_KEY`, `APP_URL`, `SANCTUM_STATEFUL_DOMAINS`
  (the public host and port) and `SESSION_SECURE_COOKIE` (for use behind TLS).
- **First owner:** a new command, `php artisan prytax:create-owner` (organization, name, email; prompts for the
  password).
  - It is built on a shared service, `PryTax\Services\Setup\InstallationSetup`, which Phase 3's setup endpoint reuses.
  - Like `DemoSeeder.php:95-133`, the service creates the Organization (`activated_at`) and the User
    (`organization_id`, `email_verified_at`, `password_updated_at`), then calls `assignRole('owner')`.
  - It refuses to run if any user already exists.
- **Generating the key:** `docker compose run --rm --no-deps app php artisan key:generate --show` prints the `APP_KEY` to put in
  `.env`.
- README: a short "Running with Docker" section.

## Phase 2: SQLite-compatible app, branch `feat/sqlite`
Goal: the full suite passes on both MySQL and SQLite. On MySQL the generated SQL must stay byte-identical, so
databases that already exist are unaffected.

**Status: built and checked** (2026-10-29). The departures from the plan below:
- **Floats in `Decimal`.** PHP coerces a float passed to an `int|string` parameter into an int, so `3892.5` becomes
  `3892`.
  - SQLite returns decimals as floats, so `Invoice::$paid_amount`, client stats and alerts lost their kopecks.
  - `Decimal` and `MoneyFormatter` now take floats and read them to six places.
- **Error checks.** `Sql::violatesUnique()` (MySQL names the index, SQLite lists its columns) and
  `Sql::restrictsDelete()` (MySQL's 1451, SQLite's `FOREIGN KEY constraint failed`) replace the MySQL-only checks in
  `InvoiceFreezer`, `InvoiceWriter` and `TenantRepository`.
- **The DataLifecycle engine stays self-contained.** Its driver switches are its own, not `PryTax\Helpers\Sql`.
  - `SchemaInspector` keeps its single `information_schema` query on MySQL and uses the schema builder on SQLite.
  - SQLite declares JSON columns as `text`, so its archives keep JSON as stored, without compacting it.
  - `Values::export` is unchanged. A column's scale isn't known on SQLite, and the manifest's checks are now
    fixed-scale strings, which is what lets archives move between the drivers.
- **`LIKE` is replaced too, not just `LOWER`/`UPPER`.** api-core's plain `like` filters ignore case on MySQL, so they
  do on SQLite now as well. A connector bound in `AppServiceProvider` registers the functions on every SQLite PDO,
  reconnections included.
- **The accounting view's invoices** are ordered by id. MySQL returned them in that order by chance; SQLite doesn't.
- **Dates** are stored as `Y-m-d` on SQLite as well (checked), so date comparisons behave as on MySQL.
- **Checked:**
  - The demo practice was seeded into both databases, and 76 report responses (Облік, Податки, the books, ЄСВ, fees,
    declarations, invoices, payments, accounts, clients; four ФОП) are identical.
  - Docker on SQLite (`DB_CONNECTION=sqlite`, no profile): the file is created on the first start, the demo seeds,
    and the worker's container completes a backup while sharing the file in WAL mode.
  - On SQLite:
    - The screens render in uk and en.
    - An invoice PDF downloads.
    - A manual backup and a period export complete through a queue worker.
    - Cyrillic `like` filters ignore case.

1. **New `PryTax\Helpers\Sql`** (next to `Decimal`, `DocumentDate`). It returns driver-specific expressions: `year()`,
   `quarter()` and `month()` (`YEAR(x)` vs `CAST(strftime('%Y', x) AS INTEGER)`) and `concat()` (`CONCAT` vs `||`).
   It uses `CASE` instead of `IF`, so the app doesn't depend on SQLite 3.44 or 3.48 functions.
2. **New `PryTax\Helpers\CheckConstraint::add()` / `drop()`.** On MySQL it runs the current `ALTER TABLE … ADD
   CONSTRAINT … CHECK`. On SQLite it creates `BEFORE INSERT` and `BEFORE UPDATE` triggers that call
   `RAISE(ABORT, '<name>')`.
3. **Migrations** (7 files): `create_invoices` (`IF` becomes `CASE`), `create_payments`, `create_refunds`,
   `create_account_transfers`, `create_transfer_allocations`, `create_tax_payments`, `add_virtual_invoices` and
   `add_original_amount_and_one_payment_per_receipt_tax`. The last one uses `virtualAs` on SQLite, because SQLite can't
   add a STORED column to an existing table.
4. **Runtime queries:**
   - `InvoiceController.php:423-442` (facets) and `AccountingArchiveChecks.php:35` use `Sql::year/quarter/concat`.
   - `Selection.php:98` uses Laravel's JSON path `where('col->key', $id)` instead of `JSON_UNQUOTE`.
   - `Selection.php:170` computes the quarter's date bounds in PHP (Carbon) with `whereBetween` instead of
     `MAKEDATE`/`INTERVAL`.
5. **DataLifecycle:**
   - `SchemaInspector` moves to `Schema::getTables/getColumns/getForeignKeys`. Generated columns come from a column's
     `generation` key.
   - `BackupRunner`'s snapshot depends on the driver. On SQLite it's a plain `BEGIN` read transaction (consistent
     under WAL), and `UTC_TIMESTAMP()` becomes `now('UTC')`.
   - `TenantPurger`'s `SET SESSION` runs on MySQL only.
   - `Column` gets its JSON and datetime detection from the registry or casts, not MySQL type names.
   - `Values::export` formats decimals to the column's scale, and the manifest checks become fixed-scale strings, so
     archives move between MySQL and SQLite (desktop ↔ cloud).
6. **SQLite connection** (`config/database.php`): env-driven `journal_mode=WAL`, `busy_timeout=5000` and
   `transaction_mode=IMMEDIATE`. Writers then queue up instead of failing with "database is locked", which replaces
   the row locks SQLite ignores.
7. **Cyrillic:** when the driver is SQLite, a listener on `ConnectionEstablished` registers `LOWER` and `UPPER` on top
   of `mb_strtolower` and `mb_strtoupper` (`Pdo\Sqlite::createFunction`). This fixes api-core's case-insensitive
   filters for Ukrainian text. Byte-order sorting (`PracticeOverview` `orderBy('full_name')`) is a known difference;
   I'll fix it only if QA shows it.
8. **Decimals:**
   - Model casts already return strings.
   - Raw `SUM`s come back as floats, but every call site passes them through `Decimal::of((string) …)`. PHP's
     14-digit float-to-string conversion gives the exact cent for any amount below 10¹².
   - So no code change is needed; the SQLite test run confirms it.
9. **Tests:**
   - New composer script `test:sqlite` (`DB_CONNECTION=sqlite DB_DATABASE=:memory:`).
   - `VerifyDatabaseTest`'s `SET FOREIGN_KEY_CHECKS` becomes `Schema::disableForeignKeyConstraints()`.
   - New tests cover the `Sql` helper, `CheckConstraint` on both drivers (a negative amount is rejected) and a backup
     archive made on one driver that imports on the other.

## Phase 3: Desktop with NativePHP, branch `feat/desktop`
**Spike results** (2026-10-29). `nativephp/desktop` 2.4.0 is installed: the user ran the require, then I pinned it to
`^2.4`. `native:install` added `native:dev` and a `post-update-cmd` hook to `composer.json`, `config/nativephp.php` and
the provider, which moved to `PryTax\Providers`.

| Question | Answer |
|---|---|
| The bundled PHP | 8.5.12, static musl. Has zip with AES-256, gd, mbstring, dom, iconv, fileinfo, intl, bcmath, pdo_sqlite (`Pdo\Sqlite`, SQLite 3.45.2). No pcntl, so the queue worker can't enforce job timeouts. No xmlwriter or xmlreader: PHPUnit can't run on it, and openspout (XLSX) must be checked before it ships. Composer's platform check passes. |
| PryTax on it | Migrations, `DemoSeeder`, a PDF, a backup and a period export all work. The 76 report responses are identical to the MySQL demo's. |
| Port | The first free port from 8100 (`get-port`), so 8100 unless another app has it. localStorage stays put between launches. |
| Storage and database | Production: `<userData>/storage` and `<userData>/database/database.sqlite`. userData is `~/.config/<app>`, `%APPDATA%\<app>` or `~/Library/Application Support/<app>`. In debug: the project's `storage/` and `database/nativephp.sqlite`. |
| `.env` and `APP_KEY` | The build copies `.env`, drops `cleanup_env_keys` and forces the log settings. The key is never generated per install, so step 5 stands. |
| Scheduler | Electron runs `artisan schedule:run` every minute and stops during sleep, so no `schedule:work` process is needed (step 3). |
| Migrations | `artisan migrate --force` on the first launch of each new app version (production only). |

**What NativePHP overrides when it runs** (`NativeServiceProvider`):
- **The database:** `database.default` becomes its own `nativephp` connection, with `PRAGMA journal_mode=WAL` and
  `busy_timeout=5000` but none of our `transaction_mode`.
  - Step 4 sets `IMMEDIATE` on that connection, then purges it.
  - Our connector still registers `LOWER`/`UPPER`/`LIKE`, because the driver is SQLite.
- **Sessions** go to files; the **queue** is `database`.
- **Queue workers** come from `queue_workers`. The default listens only on `default`, so step 2 adds `backups` and
  `retention`.
- **`PreventRegularBrowserAccess`** answers 403 without the `_php_native` cookie or secret header. Only the Electron
  window gets in.

**Booting the window** (`native:run`, after the user made `chrome-sandbox` root-owned with mode 4755):
- Electron opens the SPA on `127.0.0.1:8100` from `~/.config/prytax-dev/storage`.
- From the window's origin:
  - Signing in creates a session, not a token, so `Sanctum::$currentRequestHostPlaceholder` works.
  - Облік and an invoice PDF answer.
  - A backup completes in 3 s through the app's own queue worker.
  - Electron runs `schedule:run` every minute.
- The sandbox fix resets with every `npm install` of NativePHP's Electron. Linux builds face it too: a `.deb`
  installs the helper as setuid, while an AppImage usually needs `--no-sandbox` on Ubuntu 24.04+.

**What the boot showed** (step 4's settings were built for it, see `AppServiceProvider`):
- **Cache.** A `database` cache store is bound to the configured (MySQL) connection before NativePHP replaces it,
  which NativePHP's PHP has no driver for. Under NativePHP the cache is files (they lock too), mail goes to the log,
  and `sanctum.stateful` takes the request's host.
- **Development-only quirks:**
  - NativePHP's automatic migration (`database/nativephp.sqlite`) runs while its provider boots, before
    DataLifecycle registers its five migrations, so run `php artisan native:migrate` once after the first
    `native:run`. A production launch runs `artisan migrate` after a full boot.
  - `native:migrate`, `native:seed` and `native:migrate:fresh` run outside NativePHP's runtime, so they need
    `CACHE_STORE=file`, plus `LARAVEL_STORAGE_PATH=~/.config/prytax-dev/storage` for seeders that store files.
- **To verify in a production build:** `PreventRegularBrowserAccess` didn't stop a plain request in development
  (200 without the secret). Without it, anything on the machine can reach `127.0.0.1:8100`.

1. **Spike first.** Run `composer require nativephp/desktop` (v2.4+; approving this plan approves the dependency),
   then `php artisan native:install` and `native:run`. Confirm:
   - The bundled PHP 8.5 has zip with AES-256, plus gd, mbstring, dom, iconv, fileinfo and pdo_sqlite.
   - Which port it uses and whether that port is stable between launches.
   - Where storage and the database live.
   - How `.env` and `APP_KEY` are bundled.
   - Whether it runs the scheduler.
   I'll report back before building on any of these.
2. **`config/nativephp.php`:**
   - `app_id` and `version`.
   - `queue_workers`: one worker on `['backups','retention','default']` with timeout 1800 and memory 512.
   - `cleanup_env_keys`: `AWS_*`, `MAIL_*`, `*_SECRET`, `*_PASSWORD`.
   - `cleanup_exclude_files`: `docs`, `storage/handoff`, `storage/app/*`, `tests`, `.idea`.
3. **`NativeAppServiceProvider::boot()`:** `Window::open()` at about 1280×800 with `minWidth(1024)`, so the desktop
   layout (design.md §17.6) always applies. If the spike shows NativePHP doesn't run the scheduler, it also starts
   `schedule:work` as a persistent child process.
4. **Desktop runtime config** (applied only when running under NativePHP):
   - `sanctum.stateful` uses `Sanctum::currentRequestHost()`, so any port logs in.
   - Mail goes to `log`; queue, cache and session use `database`; the backups disk sits in the app-data folder.
5. **Per-install `APP_KEY`:**
   - The bundled `.env` carries no key.
   - A provider's `register()` reads `<appdata>/app.key`, or generates it on first launch with file mode 0600, and
     sets `config('app.key')`.
   - **The trap this guards against:** a user moves to a new laptop and copies the SQLite file but not `app.key`. Every
     tax ID, IBAN and invoice snapshot is then lost for good.
   - **Recovery kit:** a small text file holding the key, the date, the organization's name and restore
     instructions. It is never sent anywhere.
6. **First-run setup** (changed on 2026-10-29, at the user's request: it's the registration form):
   - Registering a new organization from the sign-in page is built (branch `feat/registration`):
     - `GET /api/registration` returns `{ enabled, first_owner }`.
     - `POST /api/registration` creates the organization and its owner through `OrganizationRegistrar`, which
       `InstallationSetup` and `prytax:create-owner` share, and signs the SPA in, as api-core's login does.
     - Both are under api-core's auth rate limit.
     - `PRYTAX_REGISTRATION` (`prytax.registration`) turns it on. It's off on servers by default, on in local
       development; under NativePHP it's turned on, together with step 4's settings.
   - On an installation without any user, Вхід opens Реєстрація at once, with a first-launch hint; that's the
     desktop app's first launch. Otherwise Вхід links to it («Ще не працюєте в PryTax? Зареєструвати організацію»).
   - Left for the desktop: the recovery-kit step right after registering (below), and turning registration on under
     NativePHP.
   - **The recovery kit is required, not optional.** After the account is created, setup can't continue until the
     user does one of these:
     - Saves the kit through a native save dialog (`Dialog::new()->save()`, defaulting to Documents).
     - Copies the kit to the clipboard and ticks "I've stored it somewhere safe".
     - Settings → Data offers the same kit again at any time.
   - **Restore on a new machine:** the first setup screen also offers "Restore". It takes the recovery kit plus either
     the old SQLite file or an in-app backup archive, checks the key by decrypting one stored value, and only then
     installs both.
7. **Nightly jobs a closed app would miss:**
   - **Rates:** boot never calls the network itself. It dispatches a new queued `FetchRatesJob` (an api-core
     `AsyncJob`) that wraps the same service as `prytax:fetch-rates`.
     - Network failures (`ExchangeRateUnavailableException`, connection errors) release the job with backoff (1, 5,
       15 min…) instead of failing it.
     - `retryUntil()` is 12 hours, so the rates arrive once the connection returns.
     - While offline, recording a payment already falls back to "Enter the rate manually" (`NbuRateProvider.php:104`).
   - **Backups:** on boot, check whether today's backup window was missed (`BackupPolicy.php:79-86`). If it was,
     dispatch the backup. It needs no network.
8. **Downloads in Electron:**
   - **All downloads go through one composable, `useDownload()`.** It replaces the 7 call sites: `ExportButton.vue`,
     `InvoiceDetailPage.vue`'s PDF links, the `window.location.href` zips in `BackupDownloadSheet.vue`,
     `BackupsPage.vue` and `PeriodExportSheet.vue`, and `InvoiceStylePreview.vue`'s `window.open(blob)`.
     - On the web it behaves as today.
     - On desktop (`/api/setup` reports `desktop: true`) it calls a desktop-only endpoint. That endpoint opens
       `Dialog::new()->save()` with the suggested file name and writes the file with PHP straight to the chosen path:
       the PDF, CSV or zip from the same services or disks the web routes use.
   - **The invoice preview** opens through a new NativePHP window, or with `Shell::openFile` on a temp file, instead
     of `window.open(blob)`.
   - **Still to check:** the file inputs, pdf.js's module worker and `navigator.clipboard` (fix only what breaks).
9. **Builds:**
   - Local: `php artisan native:build linux`.
   - Then a GitHub Actions matrix (ubuntu, windows, macos) with api-core checked out alongside and
     api-core copied over its link in `vendor/` (Phase 0), producing unsigned builds first.
   - macOS notarization and Windows signing come later and need your certificates.
   - NativePHP runs migrations on version change, so Phase 2's SQLite suite is the guard for upgrades.

## Verification
- **Phase 1:**
  - `docker compose --profile mysql up --build`; `/up` returns 200; run `prytax:create-owner` and log in at
    `http://localhost:8080`.
  - Create an invoice and download its PDF.
  - Start a manual backup and see the worker finish it; `schedule:list` runs in the scheduler service.
  - Repeat without the profile against the local MySQL (`DB_HOST=host.docker.internal`).
- **Phase 2:**
  - `php8.5 artisan test --compact` (MySQL) and `composer test:sqlite`, both green.
  - `migrate:fresh --seed` plus `DemoSeeder` on SQLite, then a browser pass of the main screens in uk and en
    (Облік, Податки, invoices, Книги PDFs, backups).
- **Phase 3:**
  - `native:run`: the setup screen appears on a fresh install and won't continue until the recovery kit is saved or
    copied. Then: login, a profile, an invoice PDF, a backup and a period export.
  - Every download opens a native save dialog and writes the file.
  - Restart the app: the session, language and current profile are kept.
  - **Offline:** start the app with the network down. Boot isn't delayed, the payment form offers manual rates, and
    `FetchRatesJob` succeeds once the network is back.
  - **Restore:** on a second machine (or a fresh app-data folder), choose Restore with the kit and the old SQLite file.
    Encrypted fields read back. A wrong key is refused before anything is installed.
  - Tests:
    - `FetchRatesJob` releases on a network failure and stops after `retryUntil`.
    - The setup and restore endpoints refuse once a user exists, and refuse when `setup_enabled` is off.
    - A Vitest for `useDownload`'s web and desktop branches.
  - `native:build linux`: install the package and repeat the smoke test.
  - Windows and macOS builds are checked from CI artifacts.
- Run `vendor/bin/pint --dirty --format agent` before each phase's last commit. For frontend checks prefix
  `PATH=~/.nvm/versions/node/v24.21.0/bin:$PATH` before `npm test`, `npm run typecheck` and `npm run build`.
