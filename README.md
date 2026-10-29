## PryTax

PryTax is an accounting and invoicing application for Ukrainian sole proprietors (ФОП 3 група на спрощеній системі,
without employees), and for accountants, who keep the books of several of them under one login. It's a JSON API on
Laravel 13 and [api-core](https://github.com/Andriichello/api-core), which brings organizations, users, authentication,
roles and the CRUD layer, for a Vue 3 SPA.

### Documentation

- [docs/specs/accounting.md](docs/specs/accounting.md): the functional rules, i.e. what the application must do.
- [docs/specs/design.md](docs/specs/design.md): the implementation, i.e. the schema, the services and the build order.
- [docs/specs/suggestions.md](docs/specs/suggestions.md): the open decisions and review items.
- api-core's documentation is installed in `vendor/andriichello/api-core/docs/`, listed in
  `vendor/andriichello/api-core/docs/00-index.md`.

An organization of api-core is whoever runs the application: a sole proprietor, or an accountant's practice. Inside it,
each profile is one ФОП, with its tax identity, settings and records, so `profile_id` is the tenant key of the domain
tables.

### Requirements

- PHP 8.5 with the `pdo_mysql` and `fileinfo` extensions, and Composer.
- MySQL 8.0.13 or newer, for both the application and its tests (the schema uses generated columns, `CHECK`s and
  indexes over expressions). MariaDB isn't supported. SQLite 3.38 or newer works too (the desktop app's database, see
  `APP-BUILDS-PLAN.md`): `PryTax\Helpers\Sql` and `CheckConstraint` write what the two say differently.
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

6. Serve the API on `http://localhost:8000`, and run the scheduler, which prunes expired tokens and old audit log
   entries daily:

   ```bash
   php artisan serve
   php artisan schedule:work
   ```

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
