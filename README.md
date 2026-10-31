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
- Access to api-core's private repository (`Andriichello/api-core`): Composer takes it from GitHub (see below).
- Node 20.19 or newer, for the frontend's Vite 8.

### Setting up

1. Give Composer a GitHub token, which may read api-core's private repository (once per machine; `gh auth token`
   gives the GitHub CLI's):

   ```bash
   composer config -g github-oauth.github.com "$(gh auth token)"
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

### The quickest start: `./start`

With nothing but Docker (and its compose plugin) on the machine, `./start` runs your local PryTax in Docker on SQLite,
at http://localhost:8090: the web app, its queue worker and its scheduler, as one person's installation, whose first
visit chooses the password. It's apart from what `./prytax` runs for development: its own settings
(`.env.docker.local`, made when missing with a key of its own: keep the file), its own compose project
(`prytax-local`, named in them: its containers and storage volume), its own SQLite file (`prytax.local.sqlite`) and
port, so `./prytax docker`, a new `.env.docker` or a reset never touch your data. Building takes api-core from its
private repository: `./start` uses `gh auth token` when `COMPOSER_AUTH` isn't set (see "Running with Docker"). Once
it runs, it shows what the app holds (its organizations, their ФОПs, their documents, backup archives and latest
backup), then offers an ngrok tunnel for a phone, the logs, or stopping it; quitting leaves it running. It tells the
last run of either starter, too. Commands of its own: `docker compose --env-file .env.docker.local …`.

### The starter: `./prytax`

`./prytax` opens a menu that starts PryTax and does the chores around it; `./prytax <action>` does one thing
(`./prytax --help` lists the options). It runs with PHP 8.5 (`PRYTAX_PHP`, `php8.5` or `php`), puts it first on its
processes' PATH as `php`, and takes a recent Node from nvm when the PATH's is older. Whatever it starts is one
person's installation (`PRYTAX_SINGLE_ORGANIZATION=true`, whatever the settings say): one organization, signed in with
a password alone.

Without PHP 8.5 (or with `PRYTAX_PHP=docker`), it runs in a container with PHP 8.5 and Node 24, built once from the
Dockerfile's `starter` stage, with the checkout mounted, on this machine's network and processes; a fresh checkout gets
its packages (`composer install`, with `gh auth token`), `.env` and key and `node_modules` there first. The menu then
offers what runs in it: the web app here and the tools (no desktop window, Docker or ngrok: `./start` runs Docker).
On macOS and Windows, Docker Desktop needs its host networking turned on.

The menu first tells what of PryTax runs already, and the last app started, by either starter, with its overview
(organizations, ФОПs, storage, latest backups), which it offers first as **Again** (its note: when it was set up here
and when its database was created). In the container, a SQLite file must be inside the checkout. Then it asks for the
platform: **Web** (in Docker, here, or your local app, `./start`'s) or
**Desktop** (the development window, with DevTools or without). Below the box, **Tools** leads to the status, the
doctor, the tests, the builds and the databases, and **Quit** ends it. Every question after the first menu offers
**Back**, which goes to the question before it (past those an option answered) and from an action's first question to
the menu it was chosen in; yes-or-no questions are two options, and in a typed answer (a file's path) Esc goes back.
Esc quits from a menu. While the web app or the desktop window runs, **n** opens an ngrok tunnel for
a phone (and closes it), and Ctrl+C stops everything and comes back to the menu. Docker runs in the background, so
once it's up a menu offers the tunnel, the first owner, the logs or stopping it. An app that runs already is offered
to restart or stop (the web app), to close or to tunnel to (the desktop app), or the Docker menu.

| Action | What it does |
|---|---|
| `web` | The web app here: the server on APP_URL's port, the queue worker and the scheduler, with Vite's hot reload (`--vite`) or the built SPA, on `.env`'s MySQL or a SQLite file (`--db=sqlite`, `--sqlite=<file>`), until Ctrl+C; **n** tunnels to it (not with Vite) |
| `local` | Your local app: `./start` in the foreground (its own settings, project, SQLite file and port 8090) |
| `docker` | The web app in Docker: `docker compose up` with `.env.docker` (made from the example when missing, on SQLite unless another database is chosen, `--db=mysql` or `--mysql`), then its menu |
| `desktop` | The desktop app's development window (`native:run`), without DevTools unless `--devtools`; **n** serves its data to a phone over a tunnel |
| `phone` | The app on a phone without a window: PHP's server on 8012 behind an ngrok tunnel, on the desktop app's data or the web app's (`--source`) |
| `status`, `stop` | What runs on PryTax's ports, and stopping it: `./prytax stop web vite`, or `--all` |
| `doctor` | What PryTax needs here, checked, with what fixes each |
| `test` | The PHP tests on MySQL or SQLite, the SPA's tests and types (`--filter`) |
| `build` | The SPA, the desktop app's Linux installers, the invoice styles' thumbnails |
| `db` | The web app's, the desktop app's or a SQLite file's migrations, starting over with the demo data, the first owner |
| `mcp` | Claude: the connection of each Claude integration that is on, for the data of the app started last (first), the desktop app's, `.env`'s, Docker's while it runs or another SQLite file, and whether the server answers (see "Claude and PryTax (MCP)") |

`php artisan prytax:summary` prints the same overview of whichever database it runs on (`--json` for scripts). The
starters remember their last run in `storage/framework/prytax-starter.env` (git-ignored); a database knows when it was
created (its `installations` row).

Before it starts something, it offers what it needs: a fresh build of the SPA, a database's pending migrations (after a
copy of a SQLite file), a new SQLite file with the demo data. Starting a database over asks for its name to be typed.
`docs/specs/builds.md` §6 has the details.

### Running with Docker

`compose.yaml` runs one image (`Dockerfile`) as three services: `app` (FrankenPHP serving the SPA and the API on port
8080, in classic mode), `worker` (the queue: backups, bundles and period exports) and `scheduler`. The image's
Composer takes api-core from its private GitHub repository, so building needs `COMPOSER_AUTH` in the shell, with a
token that may read it; it's passed as a build secret, which stays out of the image. The SPA is built inside the
image, so neither PHP nor Node is needed on the host.

```bash
export COMPOSER_AUTH="{\"github-oauth\":{\"github.com\":\"$(gh auth token)\"}}"
```

1. Create `.env` from `.env.docker.example` and pick the database: SQLite (the default, a file in the storage volume,
   for one host), MySQL in a container (its lines uncommented, `DB_HOST=mysql`, the `mysql` profile), or an external
   MySQL 8.0.13+ (its host and credentials; the host machine's is `host.docker.internal`, if it listens on Docker's
   bridge rather than only on 127.0.0.1). Generate the application key and put it in `APP_KEY`; keep it safe, as it
   encrypts the tax IDs and IBANs:

   ```bash
   cp .env.docker.example .env
   docker compose run --rm --no-deps app php artisan key:generate --show
   ```

2. Start it; the `app` service migrates the database and seeds the roles on every start:

   ```bash
   docker compose up -d --build                   # SQLite, or an external MySQL
   docker compose --profile mysql up -d --build   # MySQL in a container
   ```

3. Open `APP_URL`. It's one person's installation by default (`PRYTAX_SINGLE_ORGANIZATION=true`): the empty app asks
   to choose a password, which registers the organization and its owner, and then to keep the recovery kit. Whoever
   opens it first chooses it, so do it before others can reach the address. With `false` (a server shared by several
   organizations), or from the terminal instead, create the organization and its owner, then sign in (one person's
   installation asks only the password and prints the e-mail and the recovery code):

   ```bash
   docker compose exec app php artisan prytax:create-owner
   ```

   To start it with the ФОПs of backup archives (from the desktop app, say: a ФОП's archive or a backup's archive of
   every ФОП), copy them into the container and name each with `--from`; all of them are restored, or nothing is
   created:

   ```bash
   docker compose cp prytax_org-praktyka.zip app:/tmp/
   docker compose exec app php artisan prytax:create-owner --from=/tmp/prytax_org-praktyka.zip
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

**A copy of the whole app** (Резервні копії → Копія всього застосунку, owners): one password-protected file with every
organization, its documents, its in-app backups and the key. Restoring one on another computer (or on Реєстрація's
first screen, «Відновити з повної копії застосунку») compares it with that computer first — when the copy was made,
the last change on each side, whether it only moves this computer forward or rewrites it, and every row it adds,
changes or removes — and asks to save this computer's state before replacing it (`docs/specs/builds.md` §4.11).

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

`composer.json` requires `andriichello/api-core:dev-dev` from its VCS repository
(`https://github.com/Andriichello/api-core`, private), and `composer.lock` pins the commit. Changes to api-core reach
PryTax once they're pushed to its `dev` branch and taken with `composer update andriichello/api-core`. To try
unpushed api-core changes, point Composer at a local checkout for a while, without committing it:
`composer config repositories.api-core '{"type": "path", "url": "../api-core", "options": {"symlink": true}}'`, then
`composer update andriichello/api-core`; undo both before committing.

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
| `PRYTAX_FULL_BACKUPS`             | `true` (`false` in `.env.docker.example`)      | Whether owners back up and restore the whole app (SQLite only).     |
| `PRYTAX_SINGLE_ORGANIZATION`      | `false` (`true` in `.env.docker.example`)      | Whether the installation is one person's, signed in by a password.  |

With `PRYTAX_REGISTRATION` on, the sign-in page links to a form that registers a new organization with its owner, who
is signed in at once (`GET`/`POST /api/registration`); an installation without any user opens it at once. It's off by
default, as anyone reaching a server could create organizations; then owners come from `prytax:create-owner` and
invitations. One person's installation opens it for its only organization either way.

With `PRYTAX_FULL_BACKUPS` on and PryTax on a SQLite file, an owner saves a copy of the whole app and restores one in
its place, after comparing them (the desktop app always allows it). Keep it off on a shared server: a restore replaces
every organization.

With `PRYTAX_SINGLE_ORGANIZATION` on, the installation has one organization, registered with a password alone (its
name and e-mail are made up), and the sign-in page asks only the password while it has one user. A forgotten password
is set again on «Забули пароль?» with the recovery code, which the recovery kit keeps, or with the `APP_KEY`, as
nothing is mailed. The desktop app always is one, and so is Docker unless its settings say `false` (turn it off for a
server shared by several organizations); turn it on for a web app on your computer (`docs/specs/builds.md` §1.4).

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

### Claude and PryTax (MCP)

Claude (Claude Code or Claude Desktop) can read an organization's books through PryTax's local MCP server. It's
**off until an owner turns it on** in Налаштування → Інтеграції, which records who did it and when. There the owner
chooses the user Claude reads as (their role decides what it sees; a viewer gives the least) and the ФОП it sees, and
copies the connection: `claude mcp add …` for Claude Code, or the JSON for Claude Desktop's
`claude_desktop_config.json`. Both start `php artisan mcp:start prytax` with the integration's key (`PRYTAX_MCP_KEY`)
and, for the desktop app's data, its database, storage and file cache. A new key stops the old configs; turning it
off stops every call. The page shows what Claude last did (its client and version when it connected, its last call)
and, where PryTax runs on your own computer, checks Claude Code's and Claude Desktop's configs for the server.

The thirteen tools only read, within the allowed ФОП: the ФОП and their overview, invoices and clients, receipts,
Облік and the income book, the tax ledger and the declaration's figures, alerts, and the backups' state. Each call runs
in a database transaction, which is always rolled back, so nothing it touches is kept. The desktop app's database is
read with its own key (`database/nativephp.sqlite.key`), which never goes into Claude's config. `./prytax` → Tools →
Claude prints the same connection for the desktop app's data or the web app's, and asks the server as Claude would.
An AppImage runs from another folder each launch, so copy its connection again, or install the .deb.

### AI agents

[Laravel Boost](https://laravel.com/docs/boost) is installed for Claude Code, with api-core's guideline and its
`api-core-development` skill (selected in `boost.json`). Boost writes the guidelines between the
`<laravel-boost-guidelines>` tags of `CLAUDE.md`, the skills in `.claude/skills/` and the MCP server in `.mcp.json`.
Put PryTax's own guidelines in `.ai/guidelines/` and its skills in `.ai/skills/`, and run `php artisan boost:update`
after changing them, or after updating api-core or Boost.
