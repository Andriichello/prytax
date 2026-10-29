# PryTax: progress and handoff

Status as of 2026-10-29: **Docker and SQLite (phases 1 and 2 of `APP-BUILDS-PLAN.md`) are merged into `dev`** (fast-forward to
`feat/sqlite`, the user asked; not `main`, not pushed; see "App builds"); the NativePHP desktop app comes next. As of 2026-10-28 (evening): **the three handoffs of 2026-10-28 are built and merged**: `dev` and `main` were
fast-forwarded to `feat/backups-ui` (9daa066) when the user asked, and **pushed by the user** (`origin` = 335dcda; see
"Handoffs of 2026-10-28").
They were built on stacked branches, delta 3 (`feat/minimal-delta-3`, from `dev` after `feat/backups` was merged) →
desktop (`feat/desktop`) → backups UI (`feat/backups-ui`), all merged now; the full suite had 493 tests. Read this file first when resuming, then `CLAUDE.md`,
`docs/specs/design.md` (v5: §14 is the build order, §16 the mobile screens, §17 the desktop layout; `docs/specs/invoice.md` is the invoice PDF as
built; `docs/specs/suggestions.md` what is deferred or hidden). The current design handoff is «Minimal»:
`storage/handoff/MINIMAL_APP.md`, `storage/handoff/CHAT_LOG.md`, and the boards extracted in
`storage/handoff/prytax-minimal-handoff/` (`screens/png/NNN-M-*.png` by board number, `screens/html/M-*.html`,
`source/`). Its second handoff (2026-10-25, afternoon: Податки as one tax ledger) is `storage/handoff/latest/`
(`MINIMAL_DELTA_2026-10-25.md`, `CHAT_LOG_DELTA.md`), extracted in `storage/handoff/prytax-minimal-handoff-2/`; the
specs took it in on 2026-10-25 (design.md §8.5, §16.2, §16.5), and §14 steps 8–9 were built on branch
`feat/tax-ledger` (see "Tax ledger"), merged into `dev` and `main` on 2026-10-27. The earlier «Simplified» handoff is `storage/handoff/prytax-simplified-design-handoff.md` with
`storage/handoff/prytax-design-handoff/`. All of `storage/handoff/` is kept out of git, on disk only.

## App builds (2026-10-29, `APP-BUILDS-PLAN.md`; phases 1–2 merged into `dev`, not `main`, not pushed)

The user asked how PryTax could run as a cross-platform desktop app, and for Docker for the web. The plan, written
down at their request in `APP-BUILDS-PLAN.md` (repo root), has three phases: (1) Docker, (2) SQLite support, (3) a
desktop app with NativePHP. The user chose NativePHP, a first-run setup screen and that order, and reviewed the plan:
a required recovery kit for the desktop `APP_KEY`, rates retried when offline, FrankenPHP in classic mode, and native
save dialogs. I added a "Restore on a new machine" flow. The audits behind it are summarised in the plan's "What the
audits found".

**Phase 1 (Docker) is built and checked** on `feat/docker`. Its departures are under "Status" in the plan.
- **Files:**
  - `Dockerfile`: node:24 assets, then Composer in the PHP 8.5 stage (`dunglas/frankenphp:1-php8.5` plus pdo_mysql,
    pdo_sqlite, zip, gd, pcntl, bcmath, opcache), then the app.
  - `docker/Caddyfile`: classic mode.
  - `docker/php.ini`, `docker/entrypoint.sh`.
  - `compose.yaml`: app, worker and scheduler, plus a `mysql` profile.
  - `.env.docker.example`, `.dockerignore`.
  - README's "Running with Docker".
  - Opt-in `TRUSTED_PROXIES` in `bootstrap/app.php`.
- **First owner:** `prytax:create-owner` on `PryTax\Services\Setup\InstallationSetup`, which Phase 3's `/api/setup`
  will reuse.
- **api-core in builds:** `composer.lock` pins the path repository's symlink, so `COMPOSER_MIRROR_PATH_REPOS` doesn't
  work. The build replaces the link in `vendor/` with a copy, and the desktop builds must do the same.
- **Checked:**
  - **With the `mysql` profile:** migrations and seeding, `create-owner` (through `script` for a terminal), a session
    login as the SPA does it, `DemoSeeder`, an invoice PDF, a manual backup finished by the worker, the scheduler's
    11 tasks, and the login page in headless Chrome.
  - **Without the profile,** against a MySQL on the host through `host.docker.internal`.
  - **`TRUSTED_PROXIES`:** `https` asset links only when it is set.
- **Local setup for these checks:**
  - The local `.env.docker` (git-ignored) holds a generated key and DB password. The stack's volumes `prytax_mysql`
    and `prytax_storage` keep the demo data: start it with `docker compose --env-file .env.docker --profile mysql up
    -d`, sign in as olena@example.com / password at `http://localhost:8080`.
  - The system MySQL wasn't running, so the tests ran against a throwaway container: `docker run -d --name
    prytax-test-mysql -p 127.0.0.1:3307:3306 --tmpfs /var/lib/mysql -e MYSQL_ROOT_PASSWORD=secret -e
    MYSQL_DATABASE=prytax_test mysql:8.4`, then `DB_PORT=3307 DB_USERNAME=root DB_PASSWORD=secret php8.5 artisan
    test`.
**Phase 2 (SQLite) is built and checked** on `feat/sqlite`, stacked on `feat/docker`. The user asked, so `dev` was
fast-forwarded to `feat/sqlite` (593be4a) on 2026-10-29. `main` and `origin` weren't touched, and the two branches
are kept.
Its departures are under "Status" in the plan.
- **New helpers:**
  - `PryTax\Helpers\Sql`: year, quarter, month, when, concat, `violatesUnique()`, `restrictsDelete()`.
  - `CheckConstraint`: a CHECK on MySQL, two triggers on SQLite.
  - `SqliteFunctions`: Unicode-aware `LOWER`, `UPPER` and `LIKE`, registered by a connector bound in
    `AppServiceProvider`.
- **Migrations:** the seven that used MySQL-only SQL now go through the helpers. On MySQL they produce the same SQL as
  before.
- **SQLite connection:** WAL, `busy_timeout` 5000, `IMMEDIATE` transactions.
- **Backup engine:** the snapshot is `BEGIN DEFERRED` on SQLite, `SchemaInspector` uses the schema builder there, and
  the archive checks are fixed-scale strings.
- **Floats:** `Decimal` and `MoneyFormatter` take floats, which PHP otherwise truncates to an int in `int|string`
  unions. That was a real bug on SQLite.
- **Tests:** `composer test:sqlite` runs the suite in memory. MySQL is still the default (`composer test`), and both
  must pass.
- **Don't run two suites at once,** even on different databases: they share `storage/framework/testing/disks`, which
  `Storage::fake()` wipes.
- **Checked:** 76 report responses are identical between the Docker MySQL demo and a SQLite demo. Screens, a PDF, a
  backup and a period export work on SQLite.
- **Next:**
  - Then Phase 3, the NativePHP desktop app, starting with its spike (`composer require nativephp/desktop`, approved
    with the plan).
  - **The spike** (2026-10-29, branch `feat/desktop` from `dev`, not merged): the user ran `composer require
    nativephp/desktop` themselves (Composer's GitHub token is rejected for me), and I pinned it to `^2.4`.
    - `native:install` added the `native:dev` script, a `post-update-cmd` hook, `config/nativephp.php`, and the
      provider, which is now `PryTax\Providers\NativeAppServiceProvider`.
    - The answers are under "Spike results" in the plan. In short: the bundled PHP 8.5 runs PryTax (76 responses
      identical to MySQL; PDF, backup and export work); it has no pcntl or xmlwriter, so PHPUnit can't run on it.
      Port 8100. Storage and the database live in Electron's userData. `.env` is bundled with its `APP_KEY`.
      Electron runs `schedule:run` every minute. NativePHP swaps in its own `nativephp` connection without
      `IMMEDIATE`.
    - **The window boots.** The user made `chrome-sandbox` root-owned with mode 4755; it resets with every
      `npm install` of NativePHP's Electron.
    - Sign-in (session), PDFs, backups through the app's own worker, and the scheduler all work.
    - Step 4's settings are built in `AppServiceProvider`: file cache, log mail, the request's host stateful, and
      `IMMEDIATE` on NativePHP's connection. The worker takes backups and retention.
    - Development quirks are in the plan: run `native:migrate` once after the first boot; the `native:*` database
      commands need `CACHE_STORE=file`.
    - Verify the `PreventRegularBrowserAccess` 403 in a production build.
    - Later on 2026-10-29 (`feat/desktop`, `dev` merged in twice: registration, then the editor fixes), the rest of
      Phase 3 was built. The plan's "Built so far" and step 9 list it:
      - the window and the bundle's settings;
      - the per-install key and the required recovery kit (Набір відновлення);
      - restore from the kit and an old database;
      - catching up on launch (`FetchRatesJob`);
      - the roles synced after NativePHP's migrations (registration failed without roles);
      - the guard against other clients, which NativePHP broke;
      - a Linux AppImage and deb;
      - the CI workflow (needs the `API_CORE_TOKEN` secret, untested).
    - The user's first desktop session registered, saved the recovery kit through the native dialog, created a ФОП and
      downloaded a backup zip through Electron's own handling. The other downloads (invoice PDF, Облік CSV, Книги PDF,
      the style preview) are still to check, and the `useDownload` layer is built only if one fails.
    - **Відновити з архіву** (the user asked, 2026-10-29, on `feat/desktop`):
      - `POST organization/backups/import` (`BackupController::handleImport`, `ImportBackupRequest`, the owners' new
        `import` permission on backups) uploads a ФОП's backup archive. It takes an optional password (the importer
        now opens AES archives), and `dry_run` tells whose it is and what it holds.
      - The ФОП is imported as a NEW ФОП of the organization, never in place. One the organization has (by tax id) is
        refused with a translated message; so are a run's bundle and a period's export.
      - SPA: `BackupImportPage` (`/backups/import`), linked from Резервні копії.
      - Rebuild the SPA, and sync the roles (the permission is new), before trying it in the window.
    - **Layout and numbering, 2026-10-29/30** (the user's feedback from the window, all on `feat/desktop`):
      - Breakpoints (`useBreakpoint`): the desktop design from 600 px; below 1024 px the sidebar is a 64 px rail
        (`isCompact`) whose ☰ opens the whole sidebar over the page; mobile below 600 px. The window's `minWidth` is
        600. `AuthLayout` splits only from 1024 px, and `meta.bare` routes (recovery kit, change password, no
        profile) skip the frame. Pages have 24 px sides in the compact range. Narrow-width fixes: Облік's switches
        wrap, `ReceiptTable` and `InvoiceTable` (status under the amount) and `TaxSummaryCard` (no bar) give way.
      - Sidebar: no logo; Users and Backups in their own «Керування» group. `SubScreen`: the header row spans the
        page, with the record's kind beside ← («← Рахунок»); the column (+ the side note beside it from 1280 px)
        is centred under it; the note spans the column when it wraps under it.
      - Numbering: «Поточні лічильники» lists every active client's counter (or the ФОП's) from 0, settable before
        the first invoice. The editor's number card: Автоматичний | Лічильник | Власний (dark inputs). A chosen
        counter is `sequence_number` without `number` on the draft; no other invoice may hold it; the freeze moves
        the counter past it; automatic numbers and previews skip counters drafts hold.
      - Fixes: `FetchRatesJob` failed when no receipt had a manual rate (Eloquent `merge` of arrays); the style
        preview uses pdf.js's legacy build (Electron 40's Chromium lacks `Map.prototype.getOrInsertComputed`);
        Книги's income PDF stands top right in an empty year too; the kit shows the time it was kept.
      - To drive the dev window from scripts: run `native:run` with `REMOTE_DEBUGGING_PORT=9333`; `act.mjs` takes
        `CDP`, `BASE`, `NO_EMULATION` and the `clear-emulation`, `type` and `key` steps.
    - Next: the user's check of downloads and of the editor fixes in the window, then signing, the Windows and macOS
      builds (CI), and asking about merging `feat/desktop`.

**Registration** (2026-10-29, branch `feat/registration`; the user asked, so `dev` was fast-forwarded to it, d2b0aed; not `main`, not pushed). The user asked for a way to register
a new organization from the sign-in screen, and chose:
- an env switch (`PRYTAX_REGISTRATION`: off on servers by default, on in local development and the desktop app);
- signing in at once, without e-mail verification;
- the same form as the desktop's first-run setup.

What was built:
- **Backend:**
  - `config/prytax.php` (`registration`).
  - `OrganizationRegistrar`, shared by `InstallationSetup` and `prytax:create-owner`. It gives a second organization
    of the same name its own slug (`praktika-2`).
  - `RegistrationController`: `GET` and `POST /api/registration`, under api-core's auth throttle. It signs in the way
    api-core's login does.
  - `RegisterRequest`: an e-mail any account has is refused with "…Sign in instead."
- **SPA:**
  - `RegisterPage.vue` (`/register`), and `useSession().register()` and `registrationStatus()`.
  - Вхід links to it when registration is on, and opens it at once when no user exists (with a first-launch hint).
  - After registering, the app routes to `/profiles/new`.
  - The local `.env` has `PRYTAX_REGISTRATION=true`.
- **Checked:**
  - Tests on MySQL and SQLite.
  - A browser pass at 390 and 1440 px, in uk and en: first launch → register → Новий ФОП, the link on Вхід, a reused
    e-mail refused.
- **Left for the desktop:** turn registration on under NativePHP, and add the recovery-kit step after registering.
  `feat/desktop` branched before registration, so merge `dev` into it first.

**Fixes from the user's desktop test** (2026-10-29, branch `fix/editor-and-edit-buttons` from `dev`, not merged).

The user's decisions:
- A new invoice is stored only by «Зберегти чернетку». Before, every group's «Зберегти» and every item created or
  updated the draft.
- On desktop, «Змінити» sits at the end of the record's title row, not by 📖/🔔.

The invoice editor (`InvoiceEditPage.vue`):
- A new invoice keeps its groups, items and number in the form (`pending`).
- The footer has [Зберегти чернетку] [Зафіксувати], enabled once the client, the period and the account are set.
- Its status line says what's missing, "not saved yet", or "saved at HH:MM" with "A group's changes are saved at once"
  for a stored draft, which keeps saving group by group.
- A refused save opens the group whose fields were refused (`GROUP_FIELDS`, `revealErrors()`). Before, "Check the
  fields marked in red" showed with nothing red: saving one group of a new invoice was refused for another group's
  `receiving_account_id`.

«Змінити»: `components/ui/EditLink.vue` (the panel header's bordered ✎ button) ends the title row of the client,
account and transfer pages on desktop; phones keep it in the header bar.

Checked in the browser at 1440 and 390 px.

## Handoffs of 2026-10-28 (merged into `dev` and `main`, pushed)

Claude Design's three bundles are in `storage/handoff-28-10-2026/` (git-ignored; the zips are extracted there):
`prytax-minimal-handoff-3/` (`MINIMAL_DELTA_3.md`, the mobile delta), `prytax-desktop-handoff/` (`DESKTOP_APP.md`, 59
boards at 1440), `prytax-backups-handoff/` (`BACKUPS_UI.md`, 25 boards; its `BACKUPS_DESIGN.md` = `docs/specs/backups.md`
v1.6). Build order: delta 3, then desktop, then the backups screens. The owner's answers (the user said "go with your
recommendations", 2026-10-28) are in design.md v5 ("Changes in v5") and backups.md v1.7: keep the client-side current
ФОП and `profiles/{profile}/…` (no `PUT me/current-profile`); «+ Новий ФОП» = the existing owner-only form; English
already ships; the books' PDF mirrors the screen; XLSX via `openspout/openspout` (approved); desktop filter popovers
with Скинути/Застосувати; 1024–1279 px moves the right columns under the main one; notifications are a popover on
desktop and `/alerts` stays a page; backups: password switch off by default, no bundle password, restore deferred,
sizes with a dot, in-app notices through api-core's per-user `notifications`; page-number pagination (not cursor).
The chat logs #39–#86 were appended to `storage/handoff/CHAT_LOG.md`.
- **Specs:** design.md v5 (c409c51: §16.1 ФОП switching, §16.2 capsule and rules, §16.6 shared components, §16.7
  Книги обліку, §17 desktop) and backups.md v1.7 (ee08a60, on `feat/backups`: as built + §4.7/§4.8 the UI).
- **Delta 3, built:** `DateCalendar` + `DatePickerSheet` (single: Сьогодні/Вчора, `pickerNote`; range: presets, band,
  day count, `utils/datePicker.ts` with tests) and `DateRangeField` (the receipt's and the editor's service period, the
  invoice filter range); `DateField` opens the picker on a tap (a touch doesn't focus it, so no keyboard), Alt+↓ from
  the keyboard; rules 1–10 (`SectionTitle` without rule, sentence-case tax badges, «57.3 % сплачено», chips «Усі …»,
  «Застосувати» without the live count, `NewPill`, the ✕ on the right of `FullScreenSheet dismiss="close"`, «Запис»,
  `ExportBlock`/`ExportButton`); the 📖 | 🔔 `BarActions` in `TopHeader`; ФОП switching (`profiles?append=status` →
  `ProfileStatus`: taxes to pay from the ledger's unpaid items, overdue invoices, the earliest unpaid ЄСВ deadline;
  Ще's «ФОП» card; `/profiles` `ProfileSwitchPage` + `ProfileSwitchRow`; App.vue's KeepAlive key and the router's
  `tabPaths`/`keptScroll` follow the ФОП); the language sheet (`LanguageSheet` on Ще's row and the sign-in pill;
  `LanguageSwitch` removed; `LOCALE_OTHER_NAMES`); Книги обліку (`/books?tab=&year=&group=`, `BooksPage`,
  `components/books/*`; API `books/{year}/income|taxes` on `AccountingBooks` (+ `TaxLedger::items()`), PDFs
  `books/{year}/{book}/export?format=pdf`, `resources/views/pdf/books/*`, texts in `lang/uk.json`).
- **Blocked:** `composer require openspout/openspout` fails here ("Could not authenticate against github.com"; the
  composer files were restored). The user is asked to run it; then add the XLSX exports of the books and Облік (rule
  10: Облік's block becomes CSV · XLSX of the quarter, + «🗃 Архів» from the backups handoff).
- **`.env`:** the ngrok `ASSET_URL` and the host in `SANCTUM_STATEFUL_DOMAINS` were removed (the tunnel was gone, and
  the build baked the tunnel's URL into lazy chunks); a copy is in this session's scratchpad. Rebuild after env changes.
- **Desktop, built on branch `feat/desktop`** (from `feat/minimal-delta-3`; merged and pushed): see design.md
  §17.6 (the shell, panels over a background route, dialogs, popovers, the desktop pages, and what is not done).
- **Backups screens, built on branch `feat/backups-ui`** (from `feat/desktop`; merged and pushed): backups.md
  §4.8 "As built" (the pages, the API added: `backup-status`, `deleted-profiles`, `keep_until`, `previous`; what is not
  done: in-app notices, XLSX). Checked in headless Chrome as the accountant (maria, uk), mobile and desktop: no copies
  yet → a manual run through the sheet (the queue worker ran it) → the run card, the download sheet, the bundle ready,
  the read-only policy.
- **Checking:** `act.mjs` takes `WIDTH`/`HEIGHT`/`SCALE` (desktop from 1024) and a `clear-cache` step; maria
  (accountant) has `uk`, olena `en`; the sign-in screen keeps the device's language (click «Українська» in the sheet).
- **Follow-ups of 2026-10-28** (merged into `dev` and `main`, not pushed): backup downloads on a local disk opened the
  SPA's "not found" (its catch-all route took `/backups-storage/…`); local archive links are now the module's signed
  route `backups.archive` (`ArchiveController`), which saves the archive under its name (backups.md §3.3). The record
  panel's header holds the record's title, sub-line, badges and «✎ Змінити» (`SubScreen`'s `record`/`sub`/`badges`/
  `edit`, `usePanel()`). Tables and card rows follow their own width by container queries, and the desktop shell is a
  centred 1440 px box (design.md §17.6); every desktop page was measured at 1024/1280/1440/2560 px with no overlap,
  spill, clipping or truncation. Still open there: ⋯ as a popover, the tax payment's view panel (board 037), «✎ Змінити»
  beside the name on Рахунок/Клієнт, Інвойси's search.

## Where things stand

| Phase | State |
|---|---|
| 1. Backend: migrations, models, services, API, tests | **Done and audited**, fast-forwarded into `main` |
| 2. Frontend: Vue screens of the "Simplified" design | **All 34 screens built**, merged into `dev` |
| 3. QA: specs review + browser flows per role | **Done and merged** (2026-10-21); see "QA pass" |
| 4. Follow-ups: decisions, frontend gaps, Ukrainian API, remember me | **Done and merged into `dev`** (2026-10-24); see "Follow-ups" |
| 5. English, then translation keys for more languages | **Done and merged into `dev`** (2026-10-24); see "English" |
| 6. Invoice settings cascade (design.md v3.3 §4.1) and the PDF's template v2 | **Done, merged and pushed** (2026-10-24); see "Invoice document" |
| 7. Invoice styles (13 PDF styles, template v3), the note in the cascade | **Done, merged and pushed** (2026-10-24); see "Invoice styles" |
| 8. «Minimal» (design.md v4): one ФОП per session, accordion forms, two notes, payments without an invoice, ЄП/ВЗ per receipt | **Merged into `dev`** (2026-10-25), not pushed; see "Minimal" |
| 9. QA of «Minimal»: every flow in the browser as owner, accountant and viewer, uk and en | **Merged into `dev`** (2026-10-25), 10 fixes + the owner's decisions; see "QA of Minimal" |
| 10. The second Minimal handoff: Податки as one tax ledger; ЄП, ВЗ, ЄСВ and ФОП untranslated in English | **Merged into `dev` and `main`** (2026-10-27), not pushed; see "Tax ledger" |
| 11. Backups, data safety and retention: the spec (`docs/specs/backups.md`) | **Spec v1.6 committed, in `dev` and `main`** (2026-10-27), reviewed twice against the code; see "Backups spec" |
| 12. Backups, data safety and retention: the build (Layer B, retention, `verify-db`, the SPA screens) | **Merged into `dev`** (2026-10-28, not `main`, not pushed); see "Backups build" |
| 13. Mobile delta 3: ФОП switching, Книги обліку, the date picker, the language sheet, rules 1–10 | **Merged into `dev` and `main`, pushed** (2026-10-28), but XLSX (blocked on the package); see "Handoffs of 2026-10-28" |
| 14. Desktop layout («Minimal (Desktop)») | **Merged into `dev` and `main`, pushed** (2026-10-28); design.md §17.6 |
| 15. The backups screens of the backups handoff | **Merged into `dev` and `main`, pushed** (2026-10-28); backups.md §4.8 "As built" |

- **Branches:** only `main` and `dev` exist, `main` = `dev` (checked out) = everything through the handoffs of
  2026-10-28, pushed by the user (`origin/main` = `origin/dev`); the merged feature branches were deleted on 2026-10-28
  when the user asked. Work on a new feature branch from `dev`. The remote `origin` is `github.com/Andriichello/prytax`
  (added by the user on 2026-10-24; `main` and `dev` track it); push only when the user asks.
- **api-core's `feat/remember-login`** (`remember` on `auth/login`, the `remember_token` migration, docs, tests; all
  658 api-core tests passed) is fast-forwarded into its `dev` (2026-10-24, the user asked; the branch was deleted),
  which is checked out in `/var/www/api-core`; its `main` = `dev`, both pushed to `origin`. PryTax runs
  api-core's working tree (path repository, symlinked), so keep api-core on `dev`. api-core's commits run GrumPHP
  (phpcs line length 120, phpmd: no boolean flag arguments, phpstan).
- `php8.5 artisan test --compact`: **about 484 tests** on `feat/backups` (2026-10-28): the last full run had 482, one failing
  (the registry's completeness test, on `data_retention_runs`), which was then fixed and its test rerun, but the full
  suite wasn't run again after it (and after `VerifyDatabaseTest`) — run it first. About 185 s, on MySQL `prytax_test`. Run
  it with a generous timeout (or in the background); two runs at once deadlock each other (so parallel agents must
  not run it). The frontend: `npm test` (Vitest, **569 tests** on `feat/backups`, incl. the translation guard
  `resources/js/i18n/i18n.test.ts`), `npm run typecheck` (clean), `npm run build`, all with Node 24 (below).
- **PHP and Node:** the system `php` is 8.4 (set for another project; leave it), but PryTax's vendor needs 8.5: run
  the server, artisan and tests with `php8.5`. The system `node` is 18, which can't run Vite/Vitest: prefix
  `PATH=~/.nvm/versions/node/v24.21.0/bin:$PATH` for `npm test`, `npm run typecheck`, `npm run build` and the browser
  scripts.
- The dev database `prytax` was rebuilt with the Minimal demo data on 2026-10-25 (`migrate:fresh --seed` + the
  `DemoSeeder`), and the roles seeder ran after the new `issue` permission. NBU rates get cached by previews (writes
  on read). `migrate:fresh` leaves old seeds' files in `storage/app/private/files` (orphans; harmless).
- The user asked for commits in logical parts, and (2026-10-24) for no `Co-Authored-By` Claude trailer in them.

### First steps of the next session

1. The three handoffs of 2026-10-28 are built, merged into `dev` and `main` and pushed (2026-10-28). Open: XLSX (the user is to run `php8.5 $(which composer) require openspout/openspout`; then the books' and
   Облік's XLSX exports), the in-app notices of backups (api-core's `notifications` in Сповіщення), the desktop panel
   header anatomy (badges, «✎ Змінити», ⋯ in the header), Інвойси's search, the remaining spec decisions (§5.3, the
   GCS adapter), and the backlog: the open points of "QA of Minimal",
   docs/specs/suggestions.md §1, the yearly PDF summary, `SheetImporter`.
2. On 2026-10-28 MySQL was running (on 2026-10-27 it wasn't: `systemctl is-active mysql`; starting it needs sudo, so
   ask the user), but neither the dev server (8010, `php8.5`) nor the headless Chrome (9223) were; check with
   `ss -ltnp | grep -E ':3306|:8010|:9223'` and start them (commands under "Frontend" and "Checking the UI").
   `npm run build` after SPA changes; sign in as `olena@example.com` / `password` (the UI language is Ukrainian).
   Leave port 8000 (`/var/www/count-api`), the user's own Chrome and the `boost:mcp` servers alone.
3. Helper scripts kept on disk in `storage/handoff/tools/` (git-ignored): `act.mjs` (the browser step runner, see
   "Checking the UI"; it logs every API answer of 400 or more, and has `upload`, `url`, `clear-cookies`, and `tap X
   Y` for a real hit-tested tap), `i18n.py` (edits `i18n/uk` and `i18n/en` together: `rm AREA PATH`, `add AREA PARENT KEY UK EN`,
   `set AREA PATH UK EN`, values as TS literals), `i18n-prune.sh` (removes the keys the guard reports unused, then
   reruns it), `htmltext.py` (the text of a board's HTML: `python3 -I storage/handoff/tools/htmltext.py
   storage/handoff/prytax-minimal-handoff/screens/html/M-Taxes.html`).

## Backups build (2026-10-28, branch `feat/backups`, merged and pushed)

The user asked to implement `docs/specs/backups.md` (v1.6). Built in the spec's §7 order, one commit per part (19,
`git log --oneline dev..feat/backups`); every part has its tests. The spec isn't updated as built yet (v1.7, below).
- **Before real data:** `Relation::requireMorphMap()` and `PryTax\Helpers\MorphMap::register()` (refuses an alias, which
  another class has); `InvoicePdfRenderer::signature()` looks only among the invoice's own profile's signatures;
  `PryTax\Backups\ProfileAuditTagger` (an `AuditLog::creating` listener in `Providers/DataLifecycleBindings`) writes
  `metadata.profile_id`. Not done: the `../api-core` path repository in `composer.json` (§12; a dependency change that
  needs api-core tags, so ask).
- **Engine `app/DataLifecycle/`** (`PryTax\DataLifecycle\`, its own provider, config `data-lifecycle`, migrations and views
  in the module): `Contracts/` (TableRegistry with `excluded()` and `formatVersion()`, TenantRoot, ArchiveChecks,
  RetentionFamilyHandler with `families()` and the container `TAG`); `Registry/` (TableSpec, Rewrite, SchemaInspector,
  Column, MorphAliases, ColumnSnapshot, RegistryHasher); `Backup/` (RunSpec, BackupRuns — starts scheduled, manual,
  automatic runs and exports —, models BackupPolicy/BackupRun/Backup/BackupDownload with enums, the job
  RunOrganizationBackup, BuildOrganizationBundle, Http controllers/requests/resources, policies, repositories, events,
  ImportException/ImportResult; `Internal/`: Selection, Csv, Values, ArchiveWriter, ArchiveImporter, ArchiveStore,
  BackupRunner, Pruner, BundleBuilder, ArchiveRepacker, BackupVerifier); `Retention/` (RetentionRuns, TenantPurger,
  models RetentionRun/RetentionJob, RunRetentionJob; `Internal/`: DueJobs, RetentionHandlers, TechnicalHandler).
  `tests/Feature/DataLifecycle/ModuleBoundariesTest.php` (token_get_all) holds the three dependency rules.
- **PryTax's definitions:** `app/Backups/` (PryTaxTableRegistry, ApiCoreTables, ProfileTenantRoot, AccountingArchiveChecks,
  ProfileAuditTagger, BackupRunNotifier + BackupRunNotification — e-mails to owners by the policy), `app/Retention/`
  (Accounting/Documents/ProfileFinalize/UserRemoval/OrganizationPurge handlers, RetentionTriggers — model listeners —,
  RetentionFamilies — the family of every other table), `Providers/DataLifecycleBindings.php`. The column snapshot is
  `tests/Backup/registry.snapshot.json` (regenerate with `DB_DATABASE=prytax_test php8.5 artisan prytax:backup:snapshot`
  after migrating the test database; it refuses a breaking change without a new `FORMAT_VERSION`).
- **API** (organization-level, ids are ULIDs): `organization/backup-policy` (GET; PUT owners), `organization/backup-runs`
  (GET, POST 202 — 5 manual a day —, GET `{id}?include=backups`, POST/GET `{id}/bundle`), `organization/backups`
  (GET, GET `{id}`, POST `{id}/download {password?}`, DELETE manual ones, owners); `profiles/{profile}/exports` (POST
  `{from, to}` 202, GET `{id}`). Permissions in `config/api-core.php` (`$backups`): owners and accountants all but
  configure and delete, which owners have; viewers none.
- **Commands** (scheduled by the module's provider): `prytax:backup:dispatch` (5 min; gives an organization without a
  policy the default one), `prytax:backup:prune` (05:30), `prytax:backup:verify` (weekly), `prytax:backup:import {zip}
  --organization= [--dry-run]`, `prytax:backup:snapshot`; `prytax:retention:execute` (5 min), `prytax:retention:rolling`
  (daily), `prytax:retention:status|retry|cancel`; app: `prytax:verify-db` (the Layer A drill). `routes/console.php`
  also prunes resolved alerts after 180 days (`Alert` is MassPrunable), failed jobs (30 d) and batches (7 d).
- **Flows:** deleting a ФОП (soft) takes an automatic backup and plans accounting → documents → profile_finalize after
  30 days, restoring it cancels; removing a user through api-core plans credentials → access → user_pii now (name
  `Видалений користувач`, e-mail `deleted+{id}@invalid`, an unusable password); a soft-deleted organization (model
  event; there is no API for it) takes the final backups and is purged after 30 days. Purges never run on a subject,
  which isn't deleted.
- **SPA:** `pages/settings/BackupsPage.vue` (`/settings/backups`, on Ще under Організація), `components/accounting/
  PeriodExportSheet.vue` (a button on Облік), `api.put`, texts in `settings.backups.*` and `accounting.export.*`.
  Typecheck, Vitest and the build pass; **not driven in a browser yet**.
- **Departures from the spec (put them into v1.7):** `projects` and `charge_types` are `profile` tables (they have
  `profile_id`); no `withTrashed()` (the query builder exports soft-deleted rows anyway); `tax_payments.receipt_tax_key`
  is generated too, and only `VIRTUAL|STORED GENERATED` counts (`DEFAULT_GENERATED` is a default); the snapshot's
  `signature_file_id` is rewritten after `files` is imported (a "late" rewrite); `tax_id` keys aren't row ids;
  documents sit at `documents/{owner table}/{owner id}/{file id}-{name}`; timestamps in UTC (the database keeps Kyiv
  time); the module's own tables are excluded in `PryTaxTableRegistry::EXCLUDED`; `backups` keeps `file_name`,
  `manifest`, `snapshot_at`, runs keep their options, profile ids and bundle columns; download audit entries have no
  subject (`audit_logs.subject_id` is an integer, ids are ULIDs: name `models.backups.downloaded`, `metadata.backup_id`);
  a password download is repacked in the request, not by a job; the run job has `tries = 0`, `maxExceptions = 3`,
  `retryUntil` 12 h (WithoutOverlapping's releases would use up `tries`); manual runs don't skip unchanged ФОП; no
  upcasters yet (the importer refuses other format versions).
- **Not done / open (ask):** Layer A itself (GCP: the DR project, the vault, the serverless export — infrastructure, not
  code); the GCS Flysystem adapter (production's `BACKUPS_DISK`, needs approval); §5.3 (same-organization restore) and
  self-service restore; the deletion-state UI («ФОП буде видалено … Скасувати видалення», the restore endpoint exists);
  the final organization bundle and its e-mail on an organization's deletion (its users are soft-deleted with it, so
  nobody could download it: the spec contradicts itself); an organization delete endpoint; tests the spec lists, which
  aren't written: a concurrent write during the snapshot (needs a connection outside the test's transaction), the
  engine's tests with a fake registry (DDL breaks RefreshDatabase), an upcaster.
- **Local setup done on 2026-10-28:** the dev database `prytax` got the five new tables (`migrate`) and the roles' new
  permissions (`RolesAndPermissionsSeeder`, which only syncs roles' permissions); no records were touched. Runs are
  queued (`QUEUE_CONNECTION=database`): run `php8.5 artisan queue:work --queue=backups,retention,default` in the
  background when checking the UI. Archives go to the `backups` disk (`storage/app/backups`, served signed under
  `/backups-storage`); tests fake it in `TestCase`.
- **History caveats:** 8537715's importer test asserts the alert rebuild, which fb9993d adds; 68a3925 adds tables, which
  the registry test excludes only in 956544f.
- **Next:** the full suite; the browser check (Ще → Резервні копії as owner, accountant, viewer; manual backup, download
  with and without a password, the bundle, the policy sheet; Облік → Експорт за період); backups.md v1.7 as built; then
  the questions above.

## Backups spec (2026-10-27, `docs/specs/backups.md` v1.6; built on `feat/backups`, see "Backups build")

The user wrote the design (v1.2): Layer A, the platform's disaster recovery (Cloud SQL backups and point-in-time
recovery, an independent dump into another GCP project, soft delete on the buckets, `APP_KEY` in Secret Manager);
Layer B, per-profile CSV archives in a zip, scheduled per organization with grandfather-father-son retention,
organization bundles on demand, period exports, restore only as a new profile; retention families with deletion runs
and a 30-day grace; a module `PryTax\DataLifecycle\` (engine) with PryTax's definitions in `PryTax\Backups\` and
`PryTax\Retention\`. Two reviews against the code on 2026-10-27 turned it into v1.3–v1.5 (the user's edits, then mine;
"Changes in v1.x" at the top of the spec list them). v1.6 (the user asked, same day) places Layer A's database copies
in a DR project `prytax-dr-<env>`: Cloud SQL **enhanced backups** in a Backup and DR vault (instead of standard
backups; 30 days enforced, PITR) and a nightly **serverless export** (`--offload`) into the DR bucket in another region,
started from the DR project; all Layer A retention is 30 days. Nothing of Layer A exists yet (nothing is deployed).
Facts found, which the spec relies on:
- api-core's `settings` (`model_*`), `sequences` (`model_*`), `files` (`fileable_*`, plus `disk` and `path`) and
  `audit_logs` (`causer_*`, `subject_*`, `metadata` JSON) have no `profile_id`: the registry selects them through their
  owners (`TableSpec::morph`), and audit entries by `metadata.profile_id`, which an `AuditLog::creating` listener of
  PryTax is to write (api-core blocks only updating and deleting entries; `Audited` leaves `metadata` empty).
- Morph columns hold aliases `models.<plural>` (`MorphServiceProvider::slugWithFolderOf`, e.g. `models.invoices`,
  `models.users`), but the map isn't required (`Relation::requiresMorphMap()` is false; only `IdempotencyKey` is
  unmapped), and a PryTax model with the basename of an api-core one would take its alias silently.
- Ids inside values: the snapshot's `profile.signature_file_id` (`InvoicePdfRenderer::signature()` does an unscoped
  `File::query()->find()`), the settings `taxes.payment_account_ids` / `taxes.default_account_id`, and the sequence keys
  `invoices:client:{id}[:year]` (also `invoices.sequence_scope_key`).
- FK order: `contract_rates.charge_type_id` → `charge_types`, `payments.source_transfer_id` → `account_transfers`, and
  the `invoices.primary_file_id` ↔ `files` cycle. Encrypted columns: `profiles.tax_id`, `accounts.iban`,
  `accounts.account_number`, `invoices.snapshot` (`encrypted:array`). `invoices.active_number` is a generated column.
- api-core already soft-deletes a user (tokens stop; a force delete hands linked accounts over) and an organization's
  users with the organization; `users.password` is NOT NULL; `audit.retention_days` applies to the whole table.
- ext-zip (libzip 1.7.3) supports AES-256, so no zipstream package; no GCS adapter, Pest or PHPStan is installed (the
  module's dependency rules are a PHPUnit test).

Open: the decision of §5.3 (restoring into the same organization vs `UNIQUE(organization_id, tax_id_hash)`: purge the
original first, or allow one *active* profile per РНОКПП with a generated `active_tax_id_hash`), and the approval of the
GCS Flysystem adapter. To do before real data exists, whatever else is built: `Relation::requireMorphMap()` and the
alias collision guard, the audit tagger, scoping the signature lookup to the invoice's profile (all three done on
`feat/backups`), and replacing the committed `../api-core` path repository in `composer.json` (§12; not done). Build
order: the spec's §7.

## Tax ledger (2026-10-25, branch `feat/tax-ledger`, merged into `dev` and `main` on 2026-10-27)

The second Minimal handoff (`storage/handoff/latest/`, extracted in `storage/handoff/prytax-minimal-handoff-2/`) and
the owner's note that English keeps ЄП, ВЗ, ЄСВ and ФОП: specs first (design.md §8.5, §16.2, §16.5, §13, §14 steps
8–9), then the build, one commit per part, all on `feat/tax-ledger` from `dev` (not merged, not pushed).
- **Backend:** `Services/Taxes/TaxLedger` (obligations computed: a receipt's ЄП/ВЗ while it has no payment that
  didn't fail; ЄСВ per due month of every started quarter, the months ahead included, minus its non-failed payments;
  merged with the tax payments; the summary by the tax's quarter, the months by placement); `GET
  taxes/{year}/ledger?quarter=&tax_type=&status=` (lists as arrays or comma-separated; `TaxLedgerRequest`).
- **SPA:** `TaxesPage` rewritten (three badges, `TaxFilterSheet`, `TaxSummaryCard` with `TaxBar` / `TaxFigures`,
  `TaxLedgerMonth` / `TaxLedgerRow`); `utils/taxLedger.ts` (quarter-range taps, multi-selects, badges, URL
  `year`/`quarters=2-4|4|all`/`taxes`/`statuses`); new colour tokens (`sent-bar`, `tick`, `tick-on-ink`,
  `positive-on-ink`, `accent-line`, `accent-faint`); `TaxAccrualView`, `TaxHistoryView`, `amountsToPay` and
  `PeriodFilterSheet`'s `yearOnly` removed; old `?quarter=` links still open that quarter.
- **Decisions while building:** a receipt's ЄП/ВЗ is paid only by a payment that didn't fail (`receiptTaxPayment`):
  after a failed attempt the card and the receipt page show it unpaid, and the form records a new payment instead of
  opening the failed one (a fix, its own commit). ЄСВ rows read their quarter's deadline (19.01.2027), not the
  board's monthly ones. A payment without a date sits in the month its tax belongs to. Future quarters stay tappable
  in the drawer (the board has none disabled).
- **Step 9:** the English dictionaries, the API's English source strings (notifications, CSV headers, one validation
  message; `uk.json` re-keyed) and two tests write ЄП, ВЗ, ЄСВ and ФОП; «FOPs» became «ФОП» or «ФОП profiles». The
  guard allows these four words only, and fails on «single tax», «military levy», «SSC» or «FOP» in English. The
  invoice PDF keeps «Individual Entrepreneur».
- **Checks:** PHP suite 413 tests, `npm test` 560, `npm run typecheck`, `npm run build`; Податки and its drawer driven
  in headless Chrome in English (the signed-in user's saved language is `en`, and the dev database holds the user's
  records, so it wasn't switched to check Ukrainian).
- **Back keeps the place (the user's request, 2026-10-25):** going back to Інвойси showed a reload, and Податки lost
  its scroll (it wasn't kept alive, so it remounted empty and loaded again). Now `TaxesPage` is in `App.vue`'s
  `KEPT_ALIVE` with its filter frozen while hidden (as `useInvoiceFilters`), and `useLoader` / `usePagedList` load
  again on return only when `dataVersion` (`api/client.ts`: accepted writes, but `…/preview` and `…/propose`) moved.
  Checked over ngrok with 600 ms latency: no API calls on return, scroll kept; `composables/useLoader.test.ts` mounts
  a KeepAlive with an in-memory renderer (no DOM package). Інвойси's lost scroll didn't reproduce in desktop Chrome.
- **Switching tabs (the user's report, 2026-10-25):** it lagged and showed the wrong page. Cause: `App.vue` had the
  `v-if` on the page inside `KeepAlive`; a `v-if` gives the key 0, KeepAlive caches by key, so every kept page shared
  one cache entry (`parentComponent.ctx.deactivate is not a function`). The `v-if` now sits on the `KeepAlive`. Tabs
  also return to their screen as left: `tabPaths` (router `afterEach`, the route named after the tab) feeds `TabBar`,
  and `scrollBehavior` restores a kept page's scroll (`meta.keepAlive`, saved in a `beforeEach`) when the address is
  the same. Checked over ngrok: no errors, no API calls, filters and scroll kept.
- **No zoom on phones and tablets (the user's request, 2026-10-25):** the viewport's `maximum-scale=1,
  user-scalable=no`, `touch-action: manipulation` on `html`, and `app.ts` cancels iOS Safari's `gesture*` events.
  Headless Chrome can't pinch, so only the settings were checked there; the style preview can't be pinched either.
- **ngrok (2026-10-25, the user asked, to test from a phone):** `ngrok http 8010` runs in the background
  (`https://23dd-91-247-39-213.ngrok-free.app`); `.env` got `ASSET_URL` = that URL and the host appended to
  `SANCTUM_STATEFUL_DOMAINS` (the backup is in that session's scratchpad). While `ASSET_URL` is set, localhost loads
  its assets through the tunnel too: remove both when the tunnel stops.

## Minimal (2026-10-25, branch `feat/minimal`)

Claude Design's «Minimal» handoff (`storage/handoff/prytax-minimal-handoff.zip`, `MINIMAL_APP.md`, `CHAT_LOG.md`, out
of git) became design.md v4 (§14 lists the phase, §16 the screens) with the owner's answers of 2026-10-25 (no real
data, so options were dropped and the demo reseeded; ЄСВ due with its quarter; no ЄСВ exempt marking; tax payments
from a USD account carry a hand-typed original amount; one ФОП at a time, accountants switch by links; kopeck
remainders of per-receipt ЄП/ВЗ skipped for now). docs/specs/suggestions.md was rewritten (§1 deferred, §2 hidden).

- **Backend:** options removed (`accounts.limit_window/limit_counts`, `income.taxable_base`, `taxes.default_grouping`),
  `taxes.payment_account_ids`; two notes (payment and other) instead of one; virtual invoices
  (`invoices.source = virtual`, `VirtualInvoices`, `POST payments` with `virtual_invoice`, `POST invoices/{id}/issue`,
  the status filter `virtual`); tax payments: ЄП/ВЗ only per receipt at its amounts (one per tax), ЄСВ by month,
  `original_amount/original_currency` from a foreign-currency account; ЄСВ exempt marking removed.
- **SPA:** the static top bar and sticky root headers (filter badges, `PeriodFilterSheet`); removed (their routes
  redirect, names kept): the ФОП switcher sheet, Огляд практики, ЄСВ, Книга доходів and the declaration page;
  accordion forms (`AccordionGroup`, `ReadRow`, `Dimmable`, `SwitchCard`; one group open, each saving its own fields)
  for Документ інвойсу, Рахунок, Клієнт, the invoice editor (the draft saves per group), Профіль, Нумерація,
  Податки й сповіщення (payment accounts with routes) and Договір; Інвойс для надходження, the receipt form without
  an invoice, the «Без інвойсу» record (issue or attach); receipt cards (`ReceiptTaxCard`, `MonthCard`); Облік (the
  quarter's summary, receipts by month / invoice / none, fees on demand); Податки (Нарахування | Історія сплат);
  Сплата ЄП / ВЗ (`tax-payment(-new)`) and Сплата ЄСВ (`esv-payment(-new)`); Головна (two quarters, every status);
  the transfer form's tappable balance. The refund action is gone from the receipt page.
- **Decisions made while building** (beyond the specs): the receipt form without an invoice hides the client's fee;
  «Прив’язати» offers the record's client's issued invoices only (noted in design.md §4.2); a tax payment's form is
  `tax-payment-new?payment=&tax=` / `tax-payment/{id}` for ЄП/ВЗ and `esv-payment-new?year=&month=|quarter=` /
  `esv-payment/{id}` for ЄСВ; Податки in «Історія сплат» lists the payments by `paid_at` (`?mode=history`); Облік
  keeps its CSV exports (no XLSX: a package needs approval); the opening balances save as a group (unreadable amounts
  stop it before any request); Головна also lists drafts without an issue date by their period's end.
- **Checks:** PHP suite (404 tests), `npm test` (529), `npm run typecheck`, `npm run build`, and every screen driven in
  the browser against its board. The translation guard (`resources/js/i18n/i18n.test.ts`) fails on unused keys and on
  Cyrillic in English texts, but for ЄП, ВЗ, ЄСВ and ФОП, which English keeps as they are (since step 9): run
  `storage/handoff/tools/i18n-prune.sh` after removing UI.

## QA of Minimal (2026-10-25, branch `feat/minimal`)

Every screen of §16.5 swept as owner, accountant and viewer (no errors; forms send the viewer to the record), then the
flows driven in headless Chrome with the network watched: invoice by accordion groups → freeze → PDF → sent → receipt
with a platform fee → ЄП paid from UAH, ВЗ sent from the USD account (original amount, bank rate) → paid; receipt
without an invoice → record → issue as invoice; attach Kestrel's advance to KS-2026-10-15-7; ЄСВ for a month; transfer
with the tapped balance and FIFO allocations; every settings accordion (save, cancel, 422 inline); style picker and
pdf.js preview; client cascade (language, note) into a new invoice; filters of Інвойси, Облік, Податки; notifications;
external PDF with the duplicate check, return to draft, delete; cancel refused with receipts; client create with a
duplicate abbreviation; sign-in errors; English. The demo was reseeded afterwards. Fixed (one commit each):

- `payments/preview` of an edited payment without an invoice was a **500** (`PaymentRecorder::reopens()` read a null
  coverage; a virtual invoice never reopens). Test `testPreviewsAnEditOfAPaymentWithoutAnInvoice`.
- Deleting such a receipt warned «Інвойс повернеться до статусу «Готовий»»; it now says the record goes with it.
- Viewers saw «Сплатити ЄП і ВЗ…» and unpaid tax rows linking to forms they can't open; English tax labels overflowed
  the 44 px column («Military1 643.79») and the pay row read «Pay the Military levy» (now a sentence per tax).
- The language select of a client/invoice offered «Власне» as its inherit option (now «Як у ФОП: English»).
- Accountants couldn't upload the PDF signature (it sat in the owner-only «Інвойси» group of Профіль ФОП).
- Нумерація: a counter moved back got the API's «уже на 9» while the screen shows 8 (last issued); told locally now.
- The invoice editor asked `invoices/defaults` with the previous client's contract (a swallowed 422); item cards
  showed typed `12,5 × 50` until reloaded.
- Податки showed 0.00 everywhere for an untracked quarter (Коваленко's 2025: the opening balances of 01.01.2026 start
  tracking); it says «не відстежуються» now.
- Signing out re-mounted the screen without a user (`App.vue`'s `KeepAlive` key) and fired 401/404 requests; a
  screen for signed-in users now renders only with a user.

The user's decisions on the open points (2026-10-25, done, specs updated: design.md decision 14, §4, §8.4, §16.4,
§16.5; suggestions.md §2):
- **No pay row:** «Сплатити ЄП і ВЗ за цим надходженням» is removed for now; ЄП and ВЗ are paid one by one from their
  rows (`ReceiptTaxCard` lost `payRow`; restore it from git history).
- **No refunds** is fine: the cancel sheet and the API's refusal only ask to move the receipts.
- **An uploaded PDF goes only when replaced or deleted:** `InvoiceFileController` deletes the uploaded primary that a
  new `primary=1` upload replaces; an external draft's form deletes its PDF (✕, confirmed); a return to the draft keeps
  it (it always did; the sheet now says so). Test `testDeletesTheUploadedPdfOfADraftOnlyWhenReplacedOrDeleted`.

Still open (not changed):
- ВЗ of a quarter (1 % of the quarter) and Σ per receipt differ by a kopeck (suggestions.md §1, known).
- «Прив’язати» offers the client's paid invoices with 0.00 left too (attaching overpays them).
- Облік's summary of an untracked quarter shows ЄСВ 0.00 owed with 5 280.00 paid; Податки now says «не відстежуються».
- Minor: a field's API error stays until the next save (app-wide); the ЄСВ month stepper doesn't update the URL;
  «Без інвойсу» appears in the status filter only while such a record exists (facets).

## QA pass (2026-10-21, branch `dev-qa-fixes`)

Five read-only reviews compared the frontend with design.md, accounting.md, the handoff and the mocks (about 100
findings); four agents fixed them in separate worktrees (cherry-picked onto the branch), and the app was then driven
in headless Chrome as owner, accountant and viewer. Fixed, among others: inviting users (api-core rejected
`locale: 'uk'`) and the missing `/accept-invitation` page; a transfer edit wiping its allocations; receipt edits
previewed as new receipts (`payments/preview` takes `payment_id`); deletes in declared quarters (the note goes as the
`correction_note` query); `corrected` declarations shown as done; tax debt counted twice across quarters
(`utils/taxes.ts`); the full РНОКПП in viewers' payment purpose; lists cut at 50 (`api.all()`); stale counts, races
in previews, unsaved settings overwritten; an accountant's profile edits silently dropped. Added: one receipt for
several invoices, moving a receipt / linking an advance, refund delete and manual rate, account opening balances
and movements, the late-money note on Рахунки (`late_holdings`), a ФОП's closing date, ЄСВ months marked exempt,
`/change-password` (api-core's required update), `/no-profile`, a start-up error screen, route permissions
(`meta.can`: forms a role can't save send it to the record or the list), Ukrainian texts for every API and Laravel
validation message (`utils/errors.ts`).

Verified in the browser (all passing): every screen per role without errors or failed requests; invoice create →
freeze → PDF → sent → receipt → paid; refund and its delete; transfer with the exact-match proposal, edited without
losing allocations; tax payment of a quarter's own debt; declaration submitted → receipt in the locked quarter with a
note → "Потрібна уточнююча" + notification; invite → accept → sign in; external invoice with PDF, currency switch,
duplicate check; one wire for two invoices (pro-rata fee); account movement; ЄСВ exemption; reading a notification
lowers the bell; money format switch; accountant's locked identity fields; unfreeze keeps the number.

Decisions taken in the pass (tell the user if they disagree):
- The ISO code stays **after** the amount (`3 892.50 USD`, handoff §5 and the mocks; design §16 says before).
- Головна orders a month's invoices by the period's start, **latest first** (as the mock).
- Податки opens on `taxes.default_grouping` (default `payment`), so the demo ФОП open on "Надходження", not the
  mock's quarters. Δ in the reconciliation stays owed − hints (the two mocks disagree).
- The invoice editor asks with `window.confirm` before leaving unsaved changes.

Everything left open by the pass was settled in "Follow-ups" below.

## Follow-ups (2026-10-21, merged into `dev` on 2026-10-24)

The user decided: (b) a paid invoice returns to unpaid; Податки keeps the specs' default view (`payment`); add a fee
statistics screen; and go on with the rest. Done, each verified in tests and in headless Chrome:

- **A paid invoice, which a receipt change leaves uncovered, returns to `sent`** (or `ready`, when it was paid without
  being sent; `paid_at` cleared): deleting, reducing or moving a receipt, or a refund (`InvoiceLifecycle::
  reopenIfUncovered()`, inside the write's transaction; transitions `paid → sent|ready`). `payments/preview` names it
  in `reopens_invoice`; the receipt form, the delete sheet and the refund sheet warn first.
- **Комісії** (`/fees?year=`, from Ще → "Комісії й чистий дохід"): the year's income, fees, spread and net income, by
  quarter, account (with currency, linked), client (linked) and route, loss % coloured as on Перекази (`FeeRow`).
  `fees/{year}`'s groups now carry `currency`.
- **Back returns to the same place:** `App.vue` keeps `InvoicesPage` and `TransfersPage` alive (`KeepAlive`, keyed by
  the user); on return `usePagedList` re-reads the pages shown and `useLoader` reloads (both on `onActivated`); the
  invoice filters follow the URL only while the list is shown.
- **Links to another ФОП's record:** a bare record link opened from outside the app (first navigation) is looked up
  across the user's profiles (`useCurrentProfile().locate()`, routes' `meta.record`); `?profile=ID` on any path
  switches the profile and is dropped from the URL.
- **Dates are typed `дд.мм.рррр`** whatever the device's language (`DateField` is a text input, `parseDisplayDate()`
  takes `4.1.2026`, `14/10/2026`, `14102026`), with a calendar under a button; a wrong or out-of-range date is told
  after leaving the field. Since 2026-10-25 the calendar is the app's own `components/form/DatePickerSheet.vue` (the
  user found the native one ugly; no package): a bottom sheet with Monday-first weeks (`calendarWeeks()`,
  `weekdayNames()` in `utils/dates.ts`), today ringed, the chosen day filled, `min`/`max` disabled, a months view
  behind the title, «Сьогодні».
- **Vitest** (approved by the user; `vitest@5.0.3`, pinned to a release a few weeks old): `vitest.config.js` (alias
  only, not Vite's config), `npm test`. Installing with Node 22's npm crashed (arborist `edgesOut`); Node 24's npm 11
  worked (it also rewrote the lockfile's flags of optional platform packages, harmless).
- **Monthly taxes:** `taxes/{year}/months` spreads a quarter's own payments over its months, the earliest first (each
  takes what it still owes after its own payments; the last month keeps the rest; paid before sent), `from_quarter`
  per month; the view marks "за кв.".
- **Ukrainian API:** api-core's `locale` middleware on the `api` group (since 2026-10-24 `api-core.locales = ['uk',
  'en']` and the user's own `locale` decides, see "English"); requests without a user or a header stay English, so
  tests assert English; `lang/uk.json` (every `__()` of `app/` — `LocalizationTest` scans them —, the invitation and reset
  e-mails, Laravel's mail layout) and `lang/uk/{validation,auth,passwords}.php` (messages without the field's name,
  as they show under the field). `utils/errors.ts` now shows any Cyrillic message as is and translates only
  api-core's English-only messages (suspended account, required password update, invitation link, several
  organizations, throttling); other English falls back to a text by status. Write new messages through `__()` and add
  them to `lang/uk.json` (the test fails otherwise).
- **E-mail links follow `APP_URL`** (api-core's default): `.env.example` no longer sets the 5173 URLs; the local `.env`
  now has `APP_URL=http://localhost:8010` and `SANCTUM_STATEFUL_DOMAINS=localhost:8010,127.0.0.1:8010` (the old one
  is backed up only in that session's scratchpad). The invitation e-mail was checked: Ukrainian, link on 8010.
- **«Запам’ятати мене»** on Вхід (checked by default, as the mock): sends `remember`, which api-core's
  `feat/remember-login` branch turns into the remember cookie; the published migration
  `2026_10_21_010000_add_remember_token_to_users_table` adds `users.remember_token`. Verified: with it, a dropped
  session signs in again from the cookie; without it, back to Вхід.

## English (2026-10-24, merged into `dev`)

The user asked for English, switchable with Ukrainian. On 2026-10-24 (later, branch `feat/i18n-keys`, the user's
request "so that we can have more languages added later") the SPA moved from Ukrainian texts as keys to translation
keys; the API keeps Laravel's English-keyed `__()` (the user agreed: `lang/xx.json` already adds a language there).

- **SPA:** `resources/js/i18n/index.ts` — `t('invoices.editor.issueDate', { name })` looks the key up in the
  dictionary of the current language: `i18n/uk/*.ts` (the default language and the source of the keys) and
  `i18n/en/*.ts`, one file per area (`core`, `shell`, `invoices`, `money`, `accounting`, `clients`, `settings`), keys
  `area.screen.name` (shared words in `core.common`). Keys are typed (`MessageKey`, so vue-tsc rejects an unknown
  one), and each English file `satisfies Translation<typeof uk>` (vue-tsc rejects a missing or extra key). A key
  missing in a language falls back to Ukrainian. No package (vue-i18n would need approval). `t()` must run at render
  (computed, getters, templates), never in a frozen constant, since Інвойси and Перекази stay alive.
  `components/ui/Translated.vue` fills a translated sentence's `{name}` with slots (markup inside a sentence).
  Plurals are entries by the categories of `Intl.PluralRules` (`{ one, few, many }` in Ukrainian, `{ one, other }` in
  English), picked by `t(key, { count })` (`core.counts.*`). Month names come from `Intl` (`utils/dates.ts`), quarters
  (`core.quarters`, `core.quarterNames`), weekdays and short months from the dictionaries; statuses, kinds of
  accounts, `TAX_TYPES` (Single tax, Military levy, SSC) and country names follow the language; dates stay
  дд.мм.рррр. `utils/errors.ts` shows an API message when it is in the screens' language (`LETTERS`: a language with
  letters of its own, Ukrainian's Cyrillic, proves it), and translates api-core's English-only ones by key. The guard
  `i18n/i18n.test.ts` fails on Ukrainian outside the dictionaries (comments skipped; mark Ukrainian data
  `// i18n-ignore`, or a block with `i18n-ignore-start`/`-end`), on a key the dictionary lacks, a non-literal key, an
  unused key, a language missing a key or having an extra one, a lost placeholder, or a plural without exactly its
  language's forms.
- **Adding a language:** copy `i18n/en/` to `i18n/xx/` and translate it, add `xx` to `LANGUAGES` in `i18n/index.ts`
  (and to `LETTERS` in `utils/errors.ts`, when it has letters of its own), then the API side: `lang/xx.json`,
  `lang/xx/{validation,auth,passwords}.php`, `api-core.locales`, and `AlertCard`'s `OPENING_ACTIONS` (the API's
  "Open" in that language).
- **The language is the user's:** `users.locale` (`PATCH auth/me`, which `useSession().switchLocale()` sends), shown on
  Ще and on the sign-in screens (`LanguageSwitch`); before signing in, the device's last choice (localStorage
  `prytax.locale`), which the client sends as `Accept-Language`; a language chosen on the sign-in screen becomes the
  account's. A migration moved existing users from api-core's default `en` to `uk`; demo users are `uk`; an
  invitation goes in the inviter's language.
- **API:** English is the source of `__()`, Ukrainian is `lang/uk.json` (`LocalizationTest` scans `__()` and
  `trans_choice()`). Notifications: `AlertCollector` gives each a `texts` closure; `AlertSync` renders it in every
  locale into `alerts.texts` (the columns keep the Ukrainian ones); `AlertResource` returns the request's language
  (rows synced before fall back to the columns: run `prytax:sync-alerts`). CSV exports name their columns in the
  request's language.
- **Stays Ukrainian on purpose:** tax payments' purposes (the treasury's), an FX gain's stored description, the
  treasury recipient example and the ІПК consultation number on the tax settings.

## Invoice document (2026-10-24, branch `feat/invoice-v2`, merged)

The user updated `docs/specs` (design.md v3.3 §4.1, accounting.md, new `invoice.md`; committed on 2026-10-24 when
the user asked, with `invoice.md` rewritten to describe template v3 as built) and handed off the PDF layout in `storage/handoff/prytax-invoice-v3-handoff.md` (+ `invoice-v3-handoff/refs/`,
the target renders at 110 dpi). "v3" is the design iteration; in code it is template **v2**.

- **Cascade** (`Services/Invoices/InvoiceSettingsResolver`): language, payment purpose template, act note on/off and
  text, contract section — invoice → client → ФОП → built-in, null = inherit; each resolved setting is `{value,
  source, inherited}` (`effective_settings` appends on invoices and clients, `effective_invoice_settings` and
  `invoice_texts` — the built-in texts per language — on profiles). Departures from the spec: the ФОП's language is the
  existing `profiles.locale` (no `invoice.locale` setting; the owner changes it); `clients.currency` existed (the
  migration doesn't add it); the new-invoice form uses the existing `invoices/defaults` (it returns
  `effective_settings`) instead of `clients/{client}/invoice-defaults`; the footer note keeps its own client → ФОП
  fallback. A new invoice's currency falls back to `profiles.default_currency` (was `USD`).
- **Payment purpose** (`PaymentPurposeRenderer`): whitelisted placeholders (422 on save for others), `{contract.*}`
  without a value → 422 on freeze naming the template's level; `warnings` outside UAH (`payment_purpose_too_long` >
  140, `payment_purpose_charset` outside SWIFT X) plus `currency_differs_from_contract`. Dates: `Helpers/DocumentDate`
  (`M j, Y` / `d.m.Y`), shared with the PDF. The SPA mirrors it in `utils/invoiceDocument.ts` for live previews.
- **Snapshot v2** (`InvoiceFreezer`): `locale`, profile `name`/`address` and client `name` in it, contract `subject`,
  `presentation{payment_purpose, act_note (null when off), show_contract}`. Version-1 snapshots render with
  `pdf/invoice/v1.blade.php` (moved unchanged); `InvoicePdfRenderer::templateFor()` picks the template, files record
  `template_version` (`'v2'`; old ones have `1`).
- **Template v2** (`pdf/invoice/v2.blade.php` + `InvoiceDocument` view model): matches the four refs within a few px.
  dompdf quirks found: it ignores `<col>` widths and drops a cell's pt width in `table-layout: fixed`, so widths sit
  on the first row's cells (auto layout); it multiplies line heights by the font's height × `font_height_ratio`, so v2
  renders with `fontHeightRatio = 1000/1164` (lines as high as their CSS `line-height`, as in browsers; v1 keeps the
  default). Column widths are measured with dompdf's font metrics. The number is IBM Plex Mono SemiBold
  (`resources/fonts/IBMPlexMono-SemiBold.ttf`, OFL licence beside it), registered via `@font-face`; dompdf caches it in
  `storage/fonts/` (create it on deploy). No PDF golden files: tests assert the HTML, the template choice, the widths
  and the embedded font (`IBMPlexMono-SmBld`).
- **Screens:** `components/invoice/InvoiceDocumentFields.vue` on Клієнт ("Інвойси за замовчуванням", with the
  currency), the draft invoice (collapsed "Документ") and Більше → Документ інвойсу; Договір got its subject.
- Verified: 345 PHP tests, 414 Vitest tests, typecheck, build, a 42-screen sweep in uk and en, a client save in the
  browser (restored after).

## Invoice styles (2026-10-24, branch `feat/invoice-v2`, merged)

From Claude Design's `storage/handoff/prytax-invoice-styles-handoff.md` (+ `invoice-styles-handoff/`: screens, the
styles' reference HTML/CSS, decor SVGs, 110 dpi refs, thumbnails). The user decided: **template v2 dropped** (it never
left this branch) — v1 + v3 only; **`pdfjs-dist` 6.4.299 approved** (pinned, lazy chunk, only the style preview);
design.md §4.1 (and §3's columns) edited, committed with the user's spec edits.

- **Cascade:** `style` (`InvoiceStyle`, 13 cases in catalogue order, `label()`/`group()`/`cssClass()`; string columns,
  an unknown stored key renders as `outlined`) and the **note** (`note_enabled`/`note`/`note_kind` `payment|other`):
  the first level whose `note_enabled` is non-null owns the note whole (`InvoiceSettingsResolver::note()`); the
  `NoteTextRequired` rule (data-aware, implicit) wants its text when on. Columns `clients.invoice_style`,
  `invoice_note_enabled`, `invoice_note`, `invoice_note_kind`; `invoices.style`, `note_enabled`, `note`, `note_kind`;
  settings `invoice.style`, `invoice.note*`. The migration moved `clients.invoice_footer_note` and the
  `invoice.footer_note` setting into each level's note (on, `other`) and dropped them; today only one of them printed
  (client's ?? ФОП's), so no merging was needed. `clients.legal_name_uk` existed; the form edits it now.
- **Snapshot v3:** `presentation.style`, `note` (null when off), `note_kind`; no `footer_note`. Ukrainian document dates
  are month words (`31 жовтня 2026`, `DocumentDate` `j F Y`; the SPA's `documentDate()` the same via `Intl`).
- **Preview:** `GET profiles/{p}/invoices/style-preview?style=&locale=&client_id=&invoice_id=` (`InvoiceStylePreview`,
  viewAny of invoices): the given generated invoice, else the client's or the ФОП's latest non-cancelled one, snapshot
  built in memory with the asked style/locale, rendered with v3, nothing saved; no invoice → `PreviewInvoiceFactory`.
  The catalogue is a frontend constant (`utils/invoiceStyles.ts`), kept equal to the enum by `InvoiceStyleCatalogueTest`.
- **Template v3** (`pdf/invoice/v3.blade.php` + `v3/{decor,head,strip,items,card}`; CSS `resources/invoice-styles/`,
  decor SVGs `resources/invoice-decor/`; `InvoiceDocument` view model): matches the 26 refs within 1–3 px. dompdf
  findings: fonts beyond DejaVu (Plex Mono Regular/SemiBold, Unbounded SemiBold/Bold, static TTFs, OFL) are installed
  into dompdf's font dir by `InvoicePdfRenderer::installedMetrics()` (once, again when the TTF is newer), with a
  `FontHeightOffset` so their lines are as high as DejaVu's; weight 600 is its own weight in dompdf 3.1 (registered
  separately); page numbers via `page_script()` (the `.pager` rule gives colour/font/bottom); pages have 30 pt top and
  bottom margins and the root is pulled up so bands reach the edge, with all decoration in a page-sized frame drawn
  first (so it stays on page 1); decor SVGs need unitless sizes (php-svg-lib read pt as px). Thumbnails:
  `php artisan invoice:style-thumbnails` (needs `pdftoppm`, dev only) → `public/images/invoice-styles/` (committed).
  `storage/fonts/` must be writable on deploy.
- **SPA:** `InvoiceDocumentFields` (style row → `InvoiceStylePicker` → `InvoiceStylePreview`, both on
  `FullScreenSheet`; `PurposePlaceholderSheet` replaces the chips; note block), `TextAreaField` `autogrow` (2–8 rows,
  the placeholder counts), `useOverlay` (the page lock and Escape stack, shared by BottomSheet and FullScreenSheet),
  `api.blob()`. Client page "Документ інвойсу" card → form `#invoice-defaults`; Нумерація's note became a link row;
  Ще shows the ФОП's style; Профіль's language hint.
- Verified: 383 PHP tests, 439 Vitest tests, typecheck, build; a 42-screen sweep in uk and en; the picker, the
  pdf.js preview (arrows, language), the placeholder sheet and the note in headless Chrome (nothing saved).

## The audit (2026-10-20)

Six parallel reviews compared the backend with design.md, accounting.md, suggestions.md and the handoff, and every
finding was fixed with tests, except the items under "Not done yet". The user decided four questions:

- **Numbering**: one counter per ФОП by default (`invoice.numbering.scope = global`); per client stays a setting.
  *Superseded on 2026-10-23 (the user's request, design.md v3.2):* every client has an `abbreviation`, unique per
  profile, and numbers are `{abbreviation}-{date}-{n}` with the client's own counter by default (`BL-2026-10-18-13`;
  `invoice.numbering.prefix = client_abbreviation`, `scope = per_client`).
- **Manual exchange rates** live only on the record they were entered for (`rate_is_manual` on payments, refunds and
  transfers); `exchange_rates` caches NBU rates only.
- **ЄСВ paid above a quarter's minimum carries forward as credit** (design.md said "paid above minimum", not
  overpayment; the user chose the carry). The ЄСВ table takes its balances from the reconciler, so both agree.
- **Obligations are tracked from the later of the registration and the profile's first records** (`TaxTracking`).

## Decisions taken (and where the code departs from the spec)

1. **api-core's building blocks replace some tables of the spec** (CLAUDE.md requires api-core's base classes):
   - profile settings are api-core settings (`HasSettings` on `Profile`, `settings` table), not `profile_settings`;
     their changes are written to the audit log by `ProfileSettingController` (api-core's `Setting` isn't audited);
   - invoice files are api-core files (`HasFiles`, collections `generated` / `uploaded`, `signature` on profiles) with
     `invoices.primary_file_id`, not `invoice_files`;
   - invoice counters are api-core `Sequence`s owned by the profile (keys `invoices:global`, `invoices:client:{id}`,
     with `:{year}` for yearly resets), not `invoice_sequences`;
   - the activity log is api-core's `Audited` trait; `created_by_user_id` comes from `TracksCreator`. There is no
     `updated_by_user_id` column: the audit log records who changed what.
2. **Tenancy**: `profile_id` on domain tables, `BelongsToProfile` (global scope, fails closed), `CurrentProfile`
   (Laravel Context, like api-core's `CurrentOrganization`), `ResolveProfile` middleware (alias `profile`, ranked
   after api-core's middleware), and every profile controller extends `TenantController` with
   `$parents = ['profile', …]`. A profile of another organization, or another profile's record, is a plain 404
   (tested for every resource in `ProfileIsolationTest`).
3. **Notifications of a profile are `alerts` / `alert_reads`** (api-core already owns `notifications`). `AlertCollector`
   evaluates design §9's rules plus two more (a manual rate, which differs from the NBU's published since; in
   December, missing tax rules of the next year); `AlertSync` upserts by `dedupe_key`, resolves, never deletes. A
   notification, which returns or grows more severe, is dated anew and unread by everyone. A tax debt is one notice
   per tax, at the latest quarter due (its running balance holds the earlier debts). Unpaid ЄСВ past its deadline is
   a warning, ЄП/ВЗ an error. Limits and the cap are compared exactly (80/90/100 %); a calendar limit window is in
   the dedupe key. Read only via `POST alerts/{id}/read`. Texts are in every language (`alerts.texts`; the columns
   hold the Ukrainian ones); `action_route` holds SPA paths.
   Writes of contracts, accounts, clients, tax opening balances and settings sync them (`TenantRepository::$syncsAlerts`).
4. **Pagination** is api-core's `page[number]` / `page[size]` (20 by default, 50 at most, `config/pagination.php`),
   not a cursor; infinite scroll pages by number.
5. **`paid_amount`** of invoices is derived on read (`InvoiceQuery::withPaidAmount()`), never stored.
6. **Invoice transitions**: draft → ready → sent → paid, ready/sent → cancelled, ready → draft (no receipts), and also
   **ready → paid**. Paying needs the receipts to cover the total; a paid invoice, which a receipt change leaves
   uncovered, returns to sent (or ready, when never sent) by itself (the user's decision of 2026-10-21). **Cancelling needs no money kept by receipts**
   (move them to the replacing invoice, or refund them); refunded receipts stay with their cancelled invoice. Every
   status change locks the invoice row and re-checks the status (a double tap can't number an invoice twice).
7. **A frozen invoice changes its notes only** (409 otherwise). **`PATCH invoices` requires `If-Unmodified-Since`**
   (the `updated_at` the client edited; 428 without it, 409 when stale, to the second, under a row lock).
8. **Invoice documents**: the snapshot is an encrypted column (it copies the РНОКПП and IBAN), hidden from lists
   (`?append=snapshot`), left out of the audit log. A frozen invoice's primary document can't be replaced; only a
   generated PDF is rendered again when missing. External invoices keep their entered total (items describe them)
   and need a typed number to be frozen. A deleted draft frees its number (`invoices.active_number`, generated).
9. **Tax payments are not blocked by the period lock** (paying after declaring must work). Receipts, refunds and FX
   gains are; the lock is checked inside the write's transaction, so a failed write marks nothing corrected.
   Declarations are cumulative, so income written into an open quarter, while a later quarter of its year is declared,
   needs a correction note too.
10. **Declaration statuses** move none → filled → submitted → accepted | rejected; rejected → filled | submitted;
    accepted → corrected only by a correction; corrected → submitted. Submitting needs the quarter to have ended.
11. **Account balances count `sent` and `paid` tax payments** (dated by `paid_at`, which sent and paid ones require);
    the tax reconciliation counts `paid` only, shows `sent` as pending, ignores `failed`. A transfer leaves its
    account on `sent_at` when known.
12. **Money format defaults** follow design.md: app `space_dot` (`1 234.99`), invoices `plain_dot` (`2851.99`). CSV
    exports use the profile's decimal separator, with `;` for a decimal comma.
13. **The practice overview is org-level**: `GET /api/overview/{year}/{quarter}`; it shows every profile active in
    the quarter (closed ones too), every inactive one with `?with_inactive=1`.
14. **Repatriation notice** = money a receipt still holds on a platform or foreign-bank account past the rule
    (`compliance.platform_repatriation`); the account page marks the same money late.
15. **Refunds** leave from an account in the receipt's currency, take the money from what the receipt holds there
    (so it can't be transferred on), never precede the receipt, and never refund an FX gain.
16. **Exchange rates**: the NBU's rate is always preferred; a manual rate is taken only when the NBU is unavailable,
    never cached, and a receipt saved at one takes the NBU's rate on its next update. FX transfers with a UAH leg need
    a rate (NBU or manual). The nightly fetch covers currencies of accounts, contracts and invoices, retries days of
    manual rates, and caches days of foreign transfer fees. Reads never call the NBU (cached rates only).
17. **FX spread excludes the explicit fee** (accounting.md "the rest of the gap is spread"; design.md's formula
    counted the fee twice in losses). FX gains follow; they are taxed under the "received" base too.
18. **Income above the cap** is taxed at the excess rate (15 %) instead of 5 % in ЄП owed and the declaration
    (п. 293.4); ВЗ stays 1 % of all income.
19. **Tracking** (`Services/Taxes/TaxTracking`): tax periods and ЄСВ months exist from the earliest tax opening
    balance, or else from 1 January of the year of the first receipt/refund/tax payment (or of the profile's
    creation), never before `registered_at`, until `closed_at`, up to the next quarter. Other quarters get an unsaved
    period, so reading a year creates nothing. ЄСВ months follow changes of the registration or closure. Tax opening
    balances are dated on a quarter's first day; a later one re-bases the running balance.
20. **Tax rules end with their year** (`effective_to`), so a year without rules owes no ЄСВ and takes no receipts,
    rather than reusing last year's figures. **The 2027 rules must be added by a migration before 1 January 2027**
    (a notice reminds in December). `taxes.esv_due_day` is a profile setting, defaulting to the rule's day (19).
21. **Roles**: owners do everything; accountants do every financial operation and **configure** profiles (settings,
    signatures, see the full РНОКПП) but can't change a profile's identity or create profiles; viewers only read
    (plus marking notifications read) and see the masked РНОКПП only.
22. **Personal accounts** take no receipts and have no tracked limits; rolling limit windows are `[today − period, today]`.
23. **The app runs in Europe/Kyiv**; signed file URLs last 15 minutes; CORS exposes `Content-Disposition` and
    `Idempotent-Replayed`.
24. **API messages are in the user's own language** (`users.locale`, `uk` or `en`; before signing in the request's
    `Accept-Language`; `lang/uk*`), English without either; api-core's own error messages stay English by its
    design, and the SPA translates those few into Ukrainian (`utils/errors.ts`).
25. **Dependencies added (approved by the user)**: `barryvdh/laravel-dompdf` and `brick/math`. CLAUDE.md forbids other
    new dependencies without approval.

## Not done yet in the backend

- `SheetImporter` (import of the 2025 Google sheet, design §14 step 7): no sheet format to build against.
- XLSX exports and the yearly PDF summary (CSV exists; XLSX needs a package and approval).
- "Забули пароль?" works; its e-mail links to `{APP_URL}/reset-password`, a route the SPA has. (Ukrainian messages
  and «Запам’ятати мене» are done, see "Follow-ups".)
- design.md "pending" items: Appendix A (a refund reduces the original period), B2 (fee currency / UAH-canonical net
  income), B5 (cascade `tax_payments.period_*` when a receipt's date changes). B1 is used for receipt groups only.
- A refund saved at a manual rate can't be refreshed (refunds have no update; delete and record it again).
- A re-rendered PDF reads the signature by its live file id (deleting the signature changes a re-render).
- `tax_id_hash` is an HMAC keyed by `APP_KEY`; rotating the key would break the uniqueness of tax ids.
- Enum columns are VARCHARs without CHECK constraints; `TaxRule`'s immutability is guarded by model events only.
- PII may reach `laravel.log` through bindings of a failed query (clients' tax ids, e-mails).
- Performance: `AlertSync` runs a full collection after every write. Fine for one ФОП; debounce or queue later.
- Found by the backups review (2026-10-27; fix before real data, see "Backups spec"): the morph map isn't required;
  `InvoicePdfRenderer::signature()` finds the snapshot's signature file by id without scoping it to the profile;
  `composer.json` commits a path repository (`../api-core`, `dev-dev`), which breaks any build without that folder.

## Map of the code (namespace `PryTax\`, directory `app/`)

| Area | Files |
|---|---|
| Migrations | `database/migrations/2026_10_20_1000xx…1025xx_*` (26 tables; tax rules 2025/2026 are inserted by their migration) |
| Enums | `app/Enums/*` (`InvoiceStatus`, `PaymentKind`, `TaxType`, `MoneyFormat` with separators, …) |
| Helpers | `Decimal` (money math, `split()` by the largest remainder), `MoneyFormatter` (6 formats, parsing either separator), `CurrentProfile` |
| Models | `app/Models/*` + `Traits/BelongsToProfile`; queries in `app/Queries/*` (`TenantQuery` base); every model has a factory |
| Services | `Rates/NbuRateProvider`; `Taxes/*` (`TaxTracking`, calendar, `DeadlineScheduler`, `EsvObligationScheduler`, `PeriodLock`, `TaxLiabilityCalculator`, `TaxReconciler`, `TaxPaymentRecorder`); `Payments/*` (`PaymentSnapshotter`, `PaymentRecorder` incl. `recordGroup`, `RefundRecorder`, `PaymentFlow`, `RepatriationRule`); `Transfers/*` (`HoldingCalculator`, `AllocationProposer`, `TransferRecorder`, `TransferMetrics`); `Accounts/*` (balance, limits, statement); `Invoices/*` (numbers, writer, freezer, PDF, lifecycle); `Reports/*` (Облік, Книга доходів, ЄСВ, tax breakdowns, declaration, client stats, practice overview, `FeeStatistics`, `AccountingExporter`); `Alerts/*` |
| HTTP | `Http/Controllers/*` (`TenantController` base), `Http/Requests/*` (trait `ScopesToProfile`: profile-scoped rules, money normalization in `overrides()`), `Http/Resources/*`, `Http/Filters/*` (`period`, `unread`), `Http/Middleware/*` (`profile`, `idempotent`), `Policies/*`, `Repositories/*` (write through the services; `TenantRepository` maps FK errors to 422 and syncs notifications) |
| Roles | `config/api-core.php`: owner (all + profiles + users), accountant (all financial, `configure` profiles), viewer (read, plus marking notifications read) |
| Console | `prytax:fetch-rates`, `prytax:schedule-tax-periods`, `prytax:sync-alerts`, scheduled in `routes/console.php` |
| PDF | `resources/views/pdf/invoice.blade.php`, rendered from the invoice snapshot only |
| Tests | `tests/Concerns/ActsInProfiles.php` (helpers incl. `declareQuarter()`); base `TestCase` seeds roles and calls `Http::preventStrayRequests()` |

## API (all under `/api`, `auth:sanctum` + `password.updated` + `organization`)

- Auth: api-core's `auth/*` (login with e-mail and password; the SPA uses the session cookie).
- Org level: `profiles` (CRUD + restore; `?append=unread_alerts_count,needs_correction`), `overview/{year}/{quarter}`
  (`?with_inactive=1`), `exchange-rates` (list of cached NBU rates), `exchange-rates/{currency}/{date}` (fetches the
  NBU rate, 422 when unavailable).
- `profiles/{profile}/…` (middleware `profile`):
  - `settings` (GET/PATCH; incl. `alerts.ready_days`, `taxes.esv_due_day`), `signatures` (files);
  - `accounts` (CRUD, `?append=balance,limit_progress`), `accounts/{id}/statement?year=`,
    `accounts/{account}/opening-balances`, `accounts/{account}/movements`;
  - `clients` (CRUD, `?append=outstanding`, counts of contracts and invoices), `clients/{id}/stats`,
    `clients/{client}/contracts` (rates inline), `…/projects`, `…/charge-types`;
  - `invoices` (CRUD; filters `filter[period][in]=2026,2026-Q4,2026-10`, `filter[issued_at][gte|lte]`,
    `filter[client_id][in]`, `filter[currency][in]`, `filter[status][in]`; default sort `-issued_at,-id`;
    `?append=number_preview,can_unfreeze,snapshot`), `invoices/{id}/freeze|unfreeze|send|pay|cancel`,
    `invoices/{id}/pdf`, `invoices/counters` (GET; PATCH `{scope_key, next_number}` moves a counter forward only),
    `invoices/number-preview?client_id=&period_end=`, `invoices/defaults?client_id=&contract_id=&period_end=` (what a
    new invoice's form starts with), `invoices/outstanding` ("X до оплати" per currency), `invoices/facets` (years,
    currencies, statuses, clients of the filters), `invoices/duplicates?sha256=` (before an external PDF's invoice
    exists), `invoices/{invoice}/files` (upload `primary=1` on drafts only, duplicates by SHA-256 reported);
  - `payments` (CRUD; PATCH answers `meta.invoice` coverage), `POST payments/preview` (incl. `locked_quarter`),
    `POST payments/group` (one wire for several invoices), `payments/{id}/flow` (incl. refunds, `receipt_fx_spread`),
    `payments/{id}/acknowledge-repatriation`, `payments/{payment}/refunds`; `transfers` (CRUD with `allocations`),
    `POST transfers/propose`, `transfers/stats?year=`; `tax-payments` (CRUD). These POSTs take `Idempotency-Key`;
  - `tax-periods` (index/show/update: declaration statuses), `tax-opening-balances`, `tax-payment-requisites`
    (+ `tax-payment-requisites/current?tax_type=&period_year=&period_quarter=|period_month=&date=` with `purpose`
    filled in), `esv-obligations` (index/update: due/exempt);
  - `alerts` (index with `read_at`, `filter[category]`, `filter[unread]`), `alerts/counts`, `POST alerts/{id}/read`;
  - reports: `accounting/{year}/{quarter}` (+ `/export` CSV), `accounting/{year}/export` (the year's receipts and
    taxes), `esv/{year}`, `income-book/{year}` (+ `/export`, per document), `fees/{year}` (fees and spread by quarter,
    account, client, route; net income), `taxes/{year}?tax_type=`, `taxes/{year}/months`,
    `taxes/{year}/{quarter}/payments` (statuses paid/covered/pending/unpaid, refund rows), `declarations/{year}/{quarter}`
    (incl. `esv_quarter`, `income_cap`).
- Amounts are strings with 2 decimals, rates with 4, dates `YYYY-MM-DD`. Inputs accept `2 851,99` too.

## Demo data

```bash
php8.5 artisan migrate:fresh --seed && php8.5 artisan db:seed --class=DemoSeeder   # about 30 s; drops the dev data
```

Organization "Практика Коваленко" with `olena@example.com` (owner), `maria@example.com` (accountant) and
`taras@example.com` (viewer), password `password`. Four ФОП: Коваленко О. П. (the mock's, exact; tracked from 2025),
Мельник А. С. (Q4 2 150 of 4 300 USD received), Бондар І. В. (80 % of the cap, II кв. needs an уточнююча
декларація), Савчук Т. О. (inactive, closed 31.03.2026). The demo profiles are tracked from their first seeded year,
and no tax payment is dated after today. Verified against the mock (and by `DemoSeederTest`):

- III кв.: 503 118.73 ₴, YTD 1 487 175.04, ЄП 25 155.93 = 74 358.75 − 49 202.82, checks 25 155.94 (Δ −0.01), ВЗ 5 031.19.
- IV кв. (with Kestrel's advance of 500 USD without an invoice, 19.10): 373 967.51 ₴, YTD 1 861 142.55, ЄП 18 698.38
  (hints 18 698.39), ВЗ 3 739.68, limit 18.4 %.
- Taxes (Minimal): ЄП and ВЗ paid per receipt (Nordwave's external invoice from ПриватБанк USD at the bank's 41.20, so
  19.82 + 3.96 USD left it), ЄСВ by month on the 19th of the next one; no quarter allocations. ЄП balance today
  −18 647.36 (III кв. +51.02, the opening +51.00 and kopeck remainders; IV кв. 8 218.96 pending).
- Balances: Wise USD 8 717.50, Wise EUR 1 540.00, Payoneer 312.40, ПриватБанк UAH 286 410.37, ПриватБанк USD 2 626.22.
- Receipt of 05.10.2026: 164 379.11 ₴, ЄП 8 218.96, ВЗ 1 643.79 (fees and net income are tested in `PaymentFlowTest`).
- Known differences from the mock: Payoneer's limit is at 76 % (mock 84 %), so no limit notice; the overview's IV кв.
  income per currency follows the spec (invoices issued in the quarter), so it differs from the mock's figures.

## Frontend (phase 2, branch `dev`)

- **Stack (approved 2026-10-20):** Vue 3.5, vue-router 5, TypeScript 6.0 (not 7: vue-tsc needs the JS API), vue-tsc,
  `@vitejs/plugin-vue`; Tailwind 4; HTTP through `fetch`, no axios, no Pinia (module-level composables). No other
  packages may be added without asking. `npm run typecheck` (vue-tsc), `npm run build`, `npm run dev`. **Node 22+ is
  required** (Vite 8); the system's `node` is 18: use nvm, now `PATH=~/.nvm/versions/node/v24.21.0/bin:$PATH`.
- **Served by Laravel:** `routes/web.php` answers every path outside `api/`, `sanctum/` and `up` with
  `resources/views/app.blade.php`, which only hosts `#app` (no data passed, so a Capacitor build can reuse the app).
  `SpaRouteTest` covers it; `TestCase` calls `withoutVite()`.
- **Dev server:** port 8000 on this machine is another project (`/var/www/count-api`) — leave it alone. Serve PryTax
  from `public/` on 8010 (run in the background): `cd public && PHP_CLI_SERVER_WORKERS=4 php8.5 -S 127.0.0.1:8010
  ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`. The local `.env` says 8010 now, so no
  env overrides are needed.
- **`resources/js/`:**
  - `api/client.ts` (fetch, CSRF cookie, 419 retry, 401 → sign-in, api-core bracketed queries, `Accept-Language` of the screens,
    `apiUrl()` for downloads, `newIdempotencyKey()` for receipt/refund/transfer/tax POSTs); `api/types.ts` (every
    response, checked against 342 real ones; enums: `ItemUnit` is `hour|fixed|item`, `NumberingScope` is
    `per_client|global`).
  - `composables/`: `useSession` (`can(action, resource)` from `{action} models.{resource}` permissions),
    `useCurrentProfile` (profiles + the current one, remembered per user in localStorage `prytax.profile.{userId}`,
    settings, money format; first visit prefers the profile whose e-mail is the user's), `useLoader` (load per source,
    stale answers dropped), `usePagedList` (infinite lists), `useStoredState` (per-user view preferences, e.g. Облік's
    grouping and switches), `useInvoiceFilters` (filters in the URL).
  - `utils/`: `money` (format per profile setting with U+202F groups and U+2212 minus, exact BigInt sums, half-up
    `multiplyAmounts` / `multiplyDecimals`, `parseAmount`, `percentOf` capped at 99 until paid, `formatCompact` $/€),
    `dates` (strings, never Date parsing; Kyiv today; Ukrainian months incl. genitive), `plural`, `statuses` (badge
    labels/tones, `TAX_TYPES`), `errors` (Ukrainian texts and patterns for the API's English messages),
    `text` (figures in API texts set in mono), `accounts`, `clients`, `contracts` (`rateOn` = `Contract::rateFor`,
    `describeContract` = `Contract::describe` with PHP date letters).
  - `components/layout` (TabScreen with title-action, SubScreen with `heading`/`muted`/`subtitle`/footer, TopHeader,
    SubHeader, TabBar, AuthLayout); `components/ui` (Icon + `icons.ts`, Avatar, BottomSheet with `lead`/`action`/
    `footer` slots, Chip, StatusBadge, ProgressBar, PeriodStepper, Stepper, SegmentedControl, SwitchTile, InfoRow,
    CopyRow, SectionTitle, Primary/SecondaryButton, ConfirmSheet, LoadState, EmptyState, InfiniteSentinel);
    `components/form` (TextField, SelectField, DateField, TextAreaField); domain components under `invoice/`,
    `payment/`, `transfer/`, `accounting/`, `taxes/`, `client/`, `overview/`, `alert/`, `profile/`.
  - `pages/` by section (`auth`, `invoices`, `payments`, `transfers`, `accounts`, `clients`, `accounting`, `taxes`,
    `settings`, plus Home, Alerts, Overview, NotFound); `router/index.ts` (guards: session, then the current profile;
    `taxes-by-payment` redirects to `/taxes?view=receipts`).
- **Theme:** the tokens are Tailwind colours (`bg-paper`, `paper-light`, `text-ink-2`…`ink-4`, `border-line`,
  `bg-accent`, `handle`, `rail`, `overlay`, …; the default palette is removed), sizes `text-11` … `text-32` in px,
  radii `rounded-card` / `-control` / `-button` / `-badge`, and the `num` utility for every number (mono, tabular,
  nowrap; override with `whitespace-normal!`). Fonts are bundled from `resources/fonts`.
- **The design's renders don't show Geologica:** the export's `fonts.css` points at `fonts/fonts/…`, so the PNGs and
  HTML fall back to system fonts. The app uses Geologica and IBM Plex Mono as specified, so it looks a bit wider.
- **Built (all handoff screens of «Simplified»; «Minimal» replaced or removed several, see "Minimal"):** Вхід, "Забули пароль?", `/reset-password`; Головна; the ФОП switcher sheet;
  Сповіщення; Огляд практики; Інвойси (infinite list, filters sheet with live count, URL filters), invoice page
  (actions, receipts, PDF), editor (generated and external PDF with SHA-256 duplicate check, items sheet, adjustment,
  freeze sheet, delete draft); receipts (form with live `payments/preview`, manual rate only when the NBU has none,
  correction note for declared quarters, "mark the invoice paid"), receipt page (money flow, totals, tax payments,
  refund sheet); transfers (list with route losses, form with FIFO / exact allocations, page); accounts (list, page
  with statement and copyable requisites, form with limits); clients (list, page with stats, projects and work types
  sheets, form), contracts (template preview, rates sheet); Облік (quarters grouped none / month / invoice with
  remembered switches, summary with kopeck checks, CSV links), ЄСВ, Книга доходів; Податки (quarter / month /
  receipt views, tax payment form with budget requisites, declaration with status changes); Ще; settings (profile
  with signature and money formats, numbering and counters, taxes and notifications incl. opening balances and
  budget requisites — the latter not in the mock, added because ФОП 2–4 have none), users (invite, roles, resend,
  delete). A new ФОП lands on the taxes settings (opening balances). Forms hide saving from roles that can't save.
  Each screen was compared with its mock in headless Chrome; Облік, Податки and the declaration show the mock's
  figures exactly.
- **Frontend gaps** (as of 2026-10-21, after the follow-ups): (1) XLSX exports and the yearly PDF summary aren't
  built (no backend; a package needs approval); (2) PNG/HTML pixel polish wasn't done screen by screen beyond the
  comparison above. The others (cross-profile links, date format, JS tests, monthly spread, remember me, scroll on
  back) were done in "Follow-ups".
- **Checking the UI in a browser** (the step runner is kept in `storage/handoff/tools/act.mjs`; run it with Node 24,
  e.g. `PATH=~/.nvm/versions/node/v24.21.0/bin:$PATH SHOTS=/some/dir/ node storage/handoff/tools/act.mjs steps.txt`;
  steps: `go /path`, `wait MS`, `click TEXT`, `dclick TEXT`, `fill LABEL => VALUE` (dates as `дд.мм.рррр`), `shot NAME`
  (full page), `view NAME` (viewport), `text`, `expect REGEX`, `eval JS`, `scroll Y`; an accordion's «Змінити» is
  clicked with `eval [...document.querySelectorAll('button')].filter(b => b.innerText.trim() === 'Змінити')[N].click()`): start
  `google-chrome --headless=new --remote-debugging-port=9223 --user-data-dir=<scratch dir> about:blank` in the
  background, and drive it with a small Node 22 script (built-in `WebSocket`, no packages) over the DevTools protocol:
  `Emulation.setDeviceMetricsOverride` (390 × 844, scale 2, mobile), `Page.navigate`, `Runtime.evaluate` (fill inputs
  with the native value setter + an `input` event, click), `DOM.setFileInputFiles` for uploads,
  `Page.captureScreenshot` (with `captureBeyondViewport` and a clip of the scroll height for full pages). Sign in via
  the form at `/login`; switch the ФОП through the header chip or the localStorage key above. Useful: a step runner
  reading lines like `go /path`, `click TEXT`, `dclick TEXT` (inside `[role=dialog]` — `#app` is inert while a sheet
  is open), `fill LABEL => VALUE`, `form` (each field's label and value), `expect REGEX`, `upload SELECTOR => FILE`;
  accept `Page.javascriptDialogOpening`; clear the in-flight request set on each navigation (a `fetch` made through
  `Runtime.evaluate` may never report finishing). The mail driver is `log`: invitation links are in `laravel.log`.
- **API facts found while typing it:** `period.id` is null in `accounting/{y}/{q}` and `declarations/{y}/{q}` for
  untracked quarters (don't link to `tax-periods/null`); a few timestamps carry `+03:00` instead of `Z` (alert
  `read_at`, `declaration_submitted_at`, the snapshot's `frozen_at`); `quarter_balance` means the quarter's own
  balance in `taxes/{year}` but the running one in `taxes/{year}/months`; statement tax entries put the raw tax type
  in `title`; items come only with `?include=items`; `GET exchange-rates/{currency}/{date}` and `payments/preview`
  fetch and cache NBU rates (writes on read); user routes come from api-core (`/api/users`, roles always included).
- Sanctum SPA sessions: GET `/sanctum/csrf-cookie` before login. Send `If-Unmodified-Since` (the invoice's
  `updated_at`) with every invoice PATCH, and an `Idempotency-Key` with receipt/refund/transfer/tax POSTs.
- SPA paths, which the API links to (`alerts.action_route`, the reset e-mail): `/invoices/{id}`, `/accounts/{id}`,
  `/payments/{id}`, `/transfers/{id}`, `/transfers/new?from={accountId}`, `/clients/{clientId}/contracts/{id}`,
  `/taxes?year=`, `/taxes/declaration/{year}/{quarter}`, `/accounting/{year}/{quarter}`, `/accounting/esv?year=`,
  `/reset-password`, `/accept-invitation`. Keep the routes consistent with these, or change both.
- **Mobile later:** the user wants iOS and Android; the plan is Capacitor around this same app (a second Vite config
  with an `index.html` entry, api-core's access/refresh tokens in secure storage instead of the session cookie,
  downloads through the Filesystem/Share plugins). Keep the app free of Blade-injected data and keep sign-in and
  downloads behind `api/client.ts` and one helper.

## Conventions to keep

- Code in `PryTax\`; class docblocks with a description, `@author Andrii Prykhodko <andriichello@gmail.com>` and
  `@package`; every property and method documented; `vendor/bin/pint --dirty --format agent` before committing.
- Tests: PHPUnit, camelCase `testDoesSomething` methods with docblocks, `RefreshDatabase`, factories and the
  `ActsInProfiles` helpers; NBU rates are cached with `cacheRates()` or faked with `Http::fake()` per test.
- An Eloquent collection's `except()` takes model ids, not keys: use `->toBase()` before keying by another column.
- Work on a feature branch and commit in logical parts; don't push or merge without the user asking.
- Lists of records are newest first everywhere (the user, 2026-10-25; design.md §16.5's display rules): sort by the
  record's date descending with `-id` last (`sort: '-received_at,-id'`), and order groups (months) the same way.
- The dev database now holds the user's own test records too (2026-10-25): don't reseed it without asking.
- Frontend: match the screens' HTML exports (`storage/handoff/prytax-design-handoff/screens/html`) for sizes; UI
  texts through `t('area.screen.name')`, with the Ukrainian in `i18n/uk/*.ts` and the English in `i18n/en/*.ts` (see "English"); `<script setup lang="ts">` with a docblock on each component; every number in `num`; keep option arrays of
  selects/segments in the script (inline `list.map(… ({ value, label: MAP[x] }))` in a template trips vue-tsc with
  "Property 'value' does not exist on type Record<…>").
- Tool gotcha: `\u…` escapes typed into tool inputs arrive as the characters themselves, so invisible U+00A0/U+202F
  can land in sources. Write escapes via Python `chr(92)`, and run
  `grep -rnP "[\x{00A0}\x{202F}\x{2009}]" resources/js` before committing (it must find nothing).
