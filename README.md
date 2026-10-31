## PryTax

PryTax is an accounting and invoicing application for Ukrainian sole proprietors (ФОП 3 група на спрощеній системі,
without employees), and for accountants, who keep the books of several of them under one login. It's a JSON API on
Laravel 13 and [api-core](https://github.com/Andriichello/api-core), which brings organizations, users, authentication,
roles and the CRUD layer, for a Vue 3 SPA.

### Documentation

- [docs/specs/accounting.md](docs/specs/accounting.md): the functional rules, i.e. what the application must do.
- [docs/specs/design.md](docs/specs/design.md): the implementation, i.e. the schema, the services and the build order.
- [docs/specs/invoice.md](docs/specs/invoice.md): the invoice PDF, its template and its styles.
- [docs/specs/backups.md](docs/specs/backups.md): backups, data safety and retention.
- [docs/specs/builds.md](docs/specs/builds.md): Docker, SQLite and the desktop app.
- [docs/specs/suggestions.md](docs/specs/suggestions.md): the open decisions and review items.
- api-core's documentation is installed in `vendor/andriichello/api-core/docs/`, listed in
  `vendor/andriichello/api-core/docs/00-index.md`.

An organization of api-core is whoever runs the application: a sole proprietor, or an accountant's practice. Inside it,
each profile is one ФОП, with its tax identity, settings and records, so `profile_id` is the tenant key of the domain
tables.

### Requirements

- PHP 8.5 with the `pdo_mysql` and `fileinfo` extensions, and Composer.
- MySQL 8.0.13 or newer, for both the application and its tests (the schema uses generated columns, `CHECK`s and
  indexes over expressions). MariaDB isn't supported. SQLite 3.38 or newer works too, with `pdo_sqlite` (see
  "Running on SQLite").
- api-core checked out next to this repository, in `../api-core`: Composer symlinks it from there (see below).
- Node 20.19 or newer, for the frontend's Vite 8.

### Setting up

1. Clone api-core next to PryTax, so the path `../api-core` exists:

   ```bash
   git clone https://github.com/Andriichello/api-core.git ../api-core
   ```

2. Install the dependencies, and create the `.env` file with an application key:

   ```bash
   composer install
   cp .env.example .env
   php artisan key:generate
   ```

3. Create the databases of the application and of the tests, and set `DB_USERNAME` and `DB_PASSWORD` in `.env`:

   ```sql
   CREATE DATABASE prytax CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE DATABASE prytax_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

4. Migrate the database and seed the roles:

   ```bash
   php artisan migrate --seed
   ```

5. Create the first super admin in `php artisan tinker`, as in api-core's
   `vendor/andriichello/api-core/docs/getting-started/02-installation.md` (step 7):

   ```php
   $admin = new Core\Models\User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'a-long-secret']);
   $admin->forceFill(['email_verified_at' => now(), 'password_updated_at' => now()])->save();
   $admin->assignRole('super-admin');
   ```

6. Build the SPA, serve the app on `http://localhost:8000`, and run the queue worker, which runs backups, bundles and
   period exports, and the scheduler, which fetches the day's rates, schedules the tax periods and notifications,
   takes backups and prunes old records:

   ```bash
   npm install && npm run build
   php artisan serve
   php artisan queue:work --queue=backups,retention,default
   php artisan schedule:work
   ```

### The starter: `./prytax`

`./prytax` opens a menu that starts PryTax and does the chores around it; `./prytax <action>` does one thing
(`./prytax --help` lists the options). It runs with PHP 8.5 (`PRYTAX_PHP`, `php8.5` or `php`), puts it first on its
processes' PATH as `php`, and takes a recent Node from nvm when the PATH's is older.

The menu first tells what of PryTax runs already, then asks for the platform: **Web** (here, or in Docker) or
**Desktop** (the development window, with DevTools or without). Below the box, **Tools** leads to the status, the
doctor, the tests, the builds and the databases, and **Quit** ends it; the menus after the first offer **Back**
there too. Esc quits from any question. While the web app or the desktop window runs, **n** opens an ngrok tunnel for
a phone (and closes it), and Ctrl+C stops everything and comes back to the menu. Docker runs in the background, so
once it's up a menu offers the tunnel, the first owner, the logs or stopping it. An app that runs already is offered
to restart or stop (the web app), to close or to tunnel to (the desktop app), or the Docker menu.

| Action | What it does |
|---|---|
| `web` | The web app here: the server on APP_URL's port, the queue worker and the scheduler, with Vite's hot reload (`--vite`) or the built SPA, on `.env`'s MySQL or a SQLite file (`--db=sqlite`, `--sqlite=<file>`), until Ctrl+C; **n** tunnels to it (not with Vite) |
| `docker` | The web app in Docker: `docker compose up` with `.env.docker` (made from the example when missing), MySQL in a container with `--mysql`, then its menu |
| `desktop` | The desktop app's development window (`native:run`), without DevTools unless `--devtools`; **n** serves its data to a phone over a tunnel |
| `phone` | The app on a phone without a window: PHP's server on 8012 behind an ngrok tunnel, on the desktop app's data or the web app's (`--source`) |
| `status`, `stop` | What runs on PryTax's ports, and stopping it: `./prytax stop web vite`, or `--all` |
| `doctor` | What PryTax needs here, checked, with what fixes each |
| `test` | The PHP tests on MySQL or SQLite, the SPA's tests and types (`--filter`) |
| `build` | The SPA, the desktop app's Linux installers, the invoice styles' thumbnails |
| `db` | The web app's, the desktop app's or a SQLite file's migrations, starting over with the demo data, the first owner |

Before it starts something, it offers what it needs: a fresh build of the SPA, a database's pending migrations (after a
copy of a SQLite file), a new SQLite file with the demo data. Starting a database over asks for its name to be typed.
`docs/specs/builds.md` §6 has the details.

### Running with Docker

`compose.yaml` runs one image (`Dockerfile`) as three services: `app` (FrankenPHP serving the SPA and the API on port
8080, in classic mode), `worker` (the queue: backups, bundles and period exports) and `scheduler`. The image builds
api-core from `../api-core`, which has to be checked out next to PryTax, as above. The SPA is built inside the image,
so neither PHP nor Node is needed on the host.

1. Create `.env` from `.env.docker.example` and pick the database: MySQL in a container (`DB_HOST=mysql`, the
   `mysql` profile), or an external MySQL 8.0.13+ (its host and credentials; the host machine's is
   `host.docker.internal`, if it listens on Docker's bridge rather than only on 127.0.0.1). Generate the application
   key and put it in `APP_KEY`; keep it safe, as it encrypts the tax IDs and IBANs:

   ```bash
   cp .env.docker.example .env
   docker compose run --rm --no-deps app php artisan key:generate --show
   ```

2. Start it; the `app` service migrates the database and seeds the roles on every start:

   ```bash
   docker compose --profile mysql up -d --build   # MySQL in a container
   docker compose up -d --build                   # an external MySQL
   ```

3. Create the organization and its owner, then sign in at `APP_URL`:

   ```bash
   docker compose exec app php artisan prytax:create-owner
   ```

In a development checkout, whose `.env` is the local one, save the settings as `.env.docker` with
`PRYTAX_ENV_FILE=.env.docker` in it, and add `--env-file .env.docker` to every `docker compose` command. Behind a TLS
proxy, set `APP_URL` to the `https://` address, `SANCTUM_STATEFUL_DOMAINS` to its host, `SESSION_SECURE_COOKIE` to
`true` and `TRUSTED_PROXIES` to the proxy's address (or `*`). The files, backups and logs live in the `storage`
volume.

### Running on SQLite

PryTax runs on SQLite 3.38 or newer as it does on MySQL, with PHP's `pdo_sqlite` (`docs/specs/builds.md` §3). Point
`.env` at a file, create it and migrate:

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/prytax.sqlite
```

```bash
touch /absolute/path/to/prytax.sqlite
php artisan migrate --seed
```

The app, the queue worker and the scheduler share the file: WAL and immediate transactions make a writer wait its turn
rather than fail (`DB_JOURNAL_MODE`, `DB_BUSY_TIMEOUT`, `DB_TRANSACTION_MODE`). In Docker, set `DB_CONNECTION=sqlite`
and `DB_DATABASE=/app/storage/database/prytax.sqlite`, and leave out the `mysql` profile.

### The desktop app

PryTax runs as a desktop app for Linux, Windows and macOS, made with [NativePHP](https://nativephp.com): its bundled PHP
8.5 serves the app on `127.0.0.1` against a SQLite database in the user's application data, and an Electron window
shows it (`docs/specs/builds.md` §4). Each installation makes its own `APP_KEY` on the first launch. The first launch
opens Реєстрація; after it the owner keeps the recovery kit, a text file with the key, without which a copy of the
database can't be read. On a new computer, Відновлення on the first screen takes the kit and the old computer's
database.

#### Running it in development

It needs Node 22 or newer, and PHP 8.5 as `php` on the `PATH`, which NativePHP's commands call.

1. Install NativePHP's Electron. Composer's `post-update-cmd` runs it too; it keeps `config/nativephp.php` and the
   provider as they are:

   ```bash
   php artisan native:install --force --no-interaction
   ```

2. On Linux, Electron's sandbox helper must belong to root and be setuid, again after every `native:install`:

   ```bash
   sudo chown root:root vendor/nativephp/desktop/resources/electron/node_modules/electron/dist/chrome-sandbox
   sudo chmod 4755 vendor/nativephp/desktop/resources/electron/node_modules/electron/dist/chrome-sandbox
   ```

3. Build the SPA and open the window (or run `composer native:dev`, which runs Vite's dev server beside it):

   ```bash
   npm run build
   php artisan native:run
   ```

4. After the first launch, and after new migrations, migrate the window's database. NativePHP's own migration runs
   before the backups module registers its migrations, and these commands run outside NativePHP, so they need the
   file cache:

   ```bash
   CACHE_STORE=file php artisan native:migrate
   ```

In development the window uses `database/nativephp.sqlite`, the storage in `~/.config/prytax-dev/storage` (the PDFs
and backups) and the `.env`'s `APP_KEY`. After SPA changes, run `npm run build` and press Ctrl+R in the window; restart
it after changing providers or jobs, as its queue worker keeps the old code.

#### Building the installers

```bash
php artisan native:build linux x64
```

The build runs `npm run build`, bundles the app with api-core copied in and a `.env` without the key, database, mail
or Sanctum settings, and writes an AppImage and a `.deb` to `nativephp/electron/dist/`. On Ubuntu 24.04 and newer the
AppImage needs `--no-sandbox`; the `.deb` doesn't.

`.github/workflows/desktop.yml` builds Linux (AppImage, deb), Windows (NSIS) and macOS (dmg) installers, by hand
(Actions → Desktop builds → Run workflow, once the workflow is on `main`) or on a pushed `desktop-v*` tag. It needs
the `API_CORE_TOKEN` secret, a token that may read api-core. The installers aren't signed, so macOS and Windows warn on
their first launch; `docs/specs/builds.md` §4.9 lists NativePHP's signing variables.

### api-core

`composer.json` requires `andriichello/api-core:dev-dev` from a path repository, which symlinks
`vendor/andriichello/api-core` to `../api-core`, so changes to api-core take effect here at once. Switch to its VCS
repository (`https://github.com/Andriichello/api-core`) before deploying, as a server has no `../api-core`.

After updating api-core:

- read `vendor/andriichello/api-core/docs/getting-started/08-upgrading.md`;
- publish its new migrations with `php artisan vendor:publish --tag=api-core-migrations`, which keeps the published
  ones as they are;
- run `php artisan boost:update`, which refreshes its guideline and skill (see "AI agents").

The application's code lives in the `PryTax\` namespace (`app/`), while api-core's documents show `App\` in their
examples.

### Configuration

| Env                               | Value in `.env.example`                        | Description                                                         |
|-----------------------------------|------------------------------------------------|---------------------------------------------------------------------|
| `AUTH_MODEL`                      | `Core\Models\User`                             | api-core's user model.                                              |
| `SANCTUM_STATEFUL_DOMAINS`        | `localhost:5173,localhost:8000`                | Hosts of the SPA, whose requests sign in with a session cookie.     |
| `CORS_ALLOWED_ORIGINS`            | `http://localhost:5173`                        | Origins, which may call the API with credentials (comma-separated). |
| `SESSION_DOMAIN`                  | `null`                                         | The parent domain, when the SPA and the API are on subdomains.      |
| `API_CORE_PASSWORD_RESET_URL`     | `http://localhost:5173/reset-password`         | SPA page of the password reset link.                                |
| `API_CORE_EMAIL_VERIFICATION_URL` | `http://localhost:5173/verify-email`           | SPA page of the email verification link.                            |
| `API_CORE_INVITATION_URL`         | `http://localhost:5173/accept-invitation`      | SPA page of the invitation link.                                    |
| `PRYTAX_REGISTRATION`             | `true` (`false` in `.env.docker.example`)      | Whether the sign-in page offers registering a new organization.     |

With `PRYTAX_REGISTRATION` on, the sign-in page links to a form that registers a new organization with its owner, who
is signed in at once (`GET`/`POST /api/registration`); an installation without any user opens it at once. It's off by
default, as anyone reaching a server could create organizations; then owners come from `prytax:create-owner` and
invitations.

Requests from other origins, such as integrations, sign in with tokens. api-core's other options are listed in
`vendor/andriichello/api-core/docs/getting-started/03-configuration.md`.

The roles are `owner`, `accountant` and `viewer`, below api-core's built-in `super-admin`, and their permissions are in
`config/api-core.php`. Seed them again after changing the list:

```bash
php artisan db:seed --class="Core\Database\Seeders\RolesAndPermissionsSeeder"
```

### API

`routes/api.php` registers api-core's routes under `/api`:

- `auth/…`: login, refresh, logout, `me`, switching accounts, password reset and change, email verification and
  accepting invitations;
- `users`: the users of the organization, who are invited, updated and deleted by owners;
- `notifications`: the signed-in user's notifications.

PryTax's own routes go into the group with the `organization` middleware, nested under `profiles/{profile}/…`.

### Testing

```bash
php artisan test
```

The tests run on the MySQL database `prytax_test` (set in `phpunit.xml`), with the credentials from `.env`, and
refresh it on every run. `composer test:sqlite` runs them on SQLite, in memory; both must pass. Format the code with `vendor/bin/pint`, whose `pint.json` is api-core's house style.

### AI agents

[Laravel Boost](https://laravel.com/docs/boost) is installed for Claude Code, with api-core's guideline and its
`api-core-development` skill (selected in `boost.json`). Boost writes the guidelines between the
`<laravel-boost-guidelines>` tags of `CLAUDE.md`, the skills in `.claude/skills/` and the MCP server in `.mcp.json`.
Put PryTax's own guidelines in `.ai/guidelines/` and its skills in `.ai/skills/`, and run `php artisan boost:update`
after changing them, or after updating api-core or Boost.
