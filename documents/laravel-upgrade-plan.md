# Laravel upgrade plan

Plan for moving the website (`app/`, `routes/`, `resources/`, `config/`) off its
end-of-life stack. The C game engine is not affected.

## Status (Oct 2026)

**Backend upgrade complete:** Laravel 13.38 on PHP 8.3, phpunit 10.5, with 62
tests running in CI on every PR. **Frontend complete:** Vite + Vue 3.5. **Mail
intake** no longer needs the PHP imap extension. All planned steps are done.

## Where we started

| Piece | Was | Status then |
|---|---|---|
| Laravel | 6.20.42 | End of life since Sept 2022 |
| PHP | `>=7.0` in composer.json | PHP 7.x is end of life |
| phpunit | ~6.0 | Doesn't run on PHP 8 |
| fideloper/proxy | 4.3 | Merged into Laravel 9 core |
| fzaninotto/faker | ~1.4 | Abandoned (now fakerphp/faker) |
| php-imap/php-imap | 3.x | Needs the `imap` extension, which was removed from PHP core in 8.4 |
| Vue | 2.x | End of life since Dec 2023 |
| laravel-mix | 6.x | Maintenance only. The `package.json` scripts still use Mix 5 flags (`--hide-modules`, `setup/webpack.config.js`), so `npm run dev` probably fails today |

The app itself is small: 6 controllers (~750 lines), 68 lines of routes,
13 models, 8 migrations, 9 Vue components and the stock auth controllers.
That keeps the upgrade cheap.

## Target

- **PHP 8.3** (supported to end of 2027) and **the current Laravel major**.
- Moving to PHP 8.4+ is no longer blocked by the `imap` extension (see step 6).

## Steps

Do each hop on its own branch and deploy it (or at least smoke-test it)
before starting the next. Laravel only supports upgrading one major at a time.

### 0. Safety net ✅ done
- `tests/Feature/PagesTest.php` has smoke tests for every public page and JSON
  search endpoint. They use a MySQL database built from
  `game-engine/sql/create_db.sql`, `game-engine/db-backup/exmachinis_tables.sql`
  and `tests/database/fixtures.sql`.
- `.github/workflows/tests.yml` runs them on PHP 7.4 and MySQL 5.7 for every PR
  and every push to `main`.
- Fixes needed to get the suite running:
  - `config/session.php` called the removed `str_slug()` helper, so the app crashed on boot.
    It now uses `Str::slug()`.
  - phpunit went from ~6.0 to ^8.5 and mockery from 0.9 to ^1.3, which Laravel 6 requires.
  - `create_db.sql` was missing a comma, so `abundancies` was never created.
- Composer's platform is pinned to PHP 7.4 so the lock file stays installable there.
  Raise it at each PHP bump.

### 1. Laravel 6 → 7 ✅ done
- `laravel/framework` ^7.0 (7.30.7), `laravel/tinker` ^2.0.
- Added `laravel/ui` ^2.0: Laravel 7 moved `Auth::routes()` and the login,
  register and password-reset controller traits into that package.
- `app/Exceptions/Handler.php` now takes `Throwable` instead of `Exception`.
- Laravel 7's new date format for model JSON doesn't affect this app. Every JSON
  endpoint uses the query builder, and the models passed to `json_encode` in
  views have no date columns.
- Added tests for player sign-up (`POST /web-register`).

### 2. Laravel 7 → 8 ✅ done
- `laravel/framework` ^8.0 (8.83.29), `laravel/ui` ^3.0.
- Swapped `fzaninotto/faker` for `fakerphp/faker`.
- `database/seeds` → `database/seeders` (namespace `Database\Seeders`). The old
  `UserFactory` closure is now a class-based factory in `Database\Factories`,
  and composer autoload uses PSR-4 for both.
- Removed `graham-campbell/markdown`. Nothing used it, and it had no Laravel 8
  release in the 11.x line.
- Kept: models stay in `app/`, route strings keep working through
  `RouteServiceProvider::$namespace`, and the legacy `MAIL_DRIVER` setting is
  still honoured. `Paginator::useBootstrap()` isn't needed because no Blade
  view renders `->links()`; pagination is JSON for the Vue tables.

### 3. Laravel 8 → 9 ✅ done
- Part 1, still on Laravel 8: CI and composer's platform moved to **PHP 8.1**,
  and composer.json now requires `php ^8.1`. Dependencies were refreshed within
  their existing constraints, and every PHP file lints cleanly on 8.1.
- Part 2: `laravel/framework` ^9.0 (9.52.22), `laravel/ui` ^3.4,
  `laravel/tinker` ^2.7, phpunit ^9.5 (`phpunit.xml` migrated to the 9.x
  `<coverage>` format).
- Removed `fideloper/proxy`. `TrustProxies` now extends
  `Illuminate\Http\Middleware\TrustProxies`. No proxies are trusted, as before.
- Removed the unused dev dependency `composer/composer` 1.x, which pinned
  Symfony 5.
- SwiftMailer → Symfony Mailer: the legacy `MAIL_DRIVER` setting still works.
  New `MailDeliveryTest` sends the welcome, already-registered and users-report
  emails through the real mailer (array transport) and checks recipients,
  subjects and bodies.
- Flysystem 3: the app doesn't use `Storage`, so nothing changed.
- Laravel 9 added an `@required` Blade directive, which broke the form
  validation hints (`data-constraints="@Email @Required"`) in `layouts/app` and
  `auth/register`. They're now escaped as `@@Required`, and a test checks the
  rendered HTML. Watch for the same clash with new directives in later versions.

### 4. Laravel 9 → 10 → 11 → current
- ✅ Laravel 10 done: `laravel/framework` ^10.0 (10.50.3), `laravel/ui` ^4.0,
  `laravel/tinker` ^2.8, `doctrine/dbal` ^3.5.
  - Laravel 10 no longer honours the `path.public` binding, so
    `bootstrap/app.php` now calls `usePublicPath()` to make `html/` the web root,
    for both the site and the tests.
  - phpunit stays on 9.6, which Laravel 10 supports. phpunit 10 (static data
    providers, new XML config) comes with Laravel 11.
  - No raw-SQL expression is cast to a string, so Laravel 10's `DB::raw` change
    doesn't apply. No view text clashes with a Blade directive.
- ✅ PHP 8.3 done (still on Laravel 10): CI and composer's platform moved to 8.3,
  and `composer.json` requires `php ^8.3`.
- ✅ Laravel 11 done: `laravel/framework` ^11.0 (11.57.0), `laravel/ui` ^4.5,
  `laravel/tinker` ^2.9, phpunit ^10.5, mockery ^1.6, Carbon 3.
  - Removed `doctrine/dbal`; Laravel 11 no longer uses it.
  - `phpunit.xml` migrated to the 10.5 format, with `failOnDeprecation` replacing
    `convertDeprecationsToExceptions`. Data providers are now static.
  - Kept the existing `Kernel.php`/provider structure, which Laravel 11 still
    supports. Moving to the slim skeleton is optional.
  - The old migrations in `database/migrations` were later removed (see Clean-up).
- ✅ Laravel 12 done (12.70.1): no code changes needed.
- ✅ Laravel 13 done (13.38.0): `laravel/tinker` ^3.0. No code changes needed.
  The legacy `MAIL_DRIVER` setting still works, no view text clashes with a
  Blade directive, and every route resolves.
- Optional later: adopt the slim Laravel 11+ skeleton (`bootstrap/app.php`
  routing and middleware config), move models to `app/Models`, switch route
  strings to `[Controller::class, 'method']`, and rename `MAIL_DRIVER` to
  `MAIL_MAILER` in `.env`/`config/mail.php`.

### 5. Frontend
**Part 1 ✅ done: working build on Vite.**
- laravel-mix/webpack replaced by **Vite** (`vite.config.js`, `laravel-vite-plugin`,
  `@vitejs/plugin-vue2`). `npm run build` writes to `html/build/`, and the
  layout loads it with `@vite`. The build output is committed, as before, so
  the server doesn't need Node.
- Vue stays on 2, at its final release (2.7.16), because six of the nine tables
  use `vuetable-2`, which has no Vue 3 version.
- Bootstrap's npm package went from 4.0.0-beta to **4.6.2**, which fixes the 4.x
  XSS CVEs in its JS plugins. The theme's vendored copy in
  `resources/assets/sass/bootstrap` is unchanged.
- Dropped unused packages (d3, vue-events, jquery-mousewheel, fibers,
  browser-sync, cross-env, webpack).
- Gotchas fixed so the pages look and behave as before (checked with
  before/after screenshots of 13 pages at desktop and phone widths):
  - `core.min.js` and `script.js` are now `defer`, so they still run after
    Vue mounts `#app`. Otherwise Vue re-renders the navbar the theme just set up.
  - Newer Sass keeps declarations after nested rules in source order; one rule
    in `exmachinis.scss` relied on the old hoisting (the top menu wrapped).
  - SFC templates keep whitespace (`whitespace: 'preserve'`), matching the old
    vue-loader output.
  - Font `url()`s are absolute (`/fonts/...`) so they still resolve from
    `html/build/assets/`.
- CI runs `npm ci && npm run build` and fails if the committed `html/build` is stale.

**Part 2 ✅ done: Vue 3.**
- Vue 3.5 with `createApp()`, built with `@vitejs/plugin-vue`. The page inside
  `#app` is still a Blade-rendered in-DOM template, so the runtime compiler build
  is used and whitespace is preserved, as with Vue 2.
- `vuetable-2` (Vue 2-only) is replaced by `components/DataTable.vue`, a small
  component covering what the six tables used: fields and titles,
  server-side sorting (`sort=field|asc`), paging over Laravel's paginator JSON,
  slot and HTML-formatter cells, and loading events. It renders the same markup
  and classes, so the theme's table styles are unchanged.
- `slot`/`slot-scope` → `#name="props"`; the `moment` filter →
  `formatDateTime()` in `helper.js`; `Vue.nextTick` → `this.$nextTick`.
  Dropped `vue-moment` and `vuetable-2`.
- Checked against the Vue 2 build: identical screenshots (13 pages, desktop and
  phone), and identical requests, rows, pager and sort state for paging,
  sorting and keyword search. The console is also cleaner (vuetable's
  "track-by" warnings are gone).

**Possible later:** Bootstrap 5. The theme's markup and its vendored Bootstrap 4
copy would both need reworking, so it's only worth doing as part of a redesign.

### 6. IMAP mail intake ✅ done
- `/get-mails` now reads mail with **`webklex/php-imap`**, a pure-PHP IMAP client.
  The PHP `imap` extension (removed from PHP core in 8.4) is no longer needed,
  so PHP 8.4+ is unblocked.
- Mail access sits behind `App\Services\Mail\Inbox`/`InboxFactory`
  (`ImapInboxFactory` builds from the existing `MAIL_*` settings). The controller
  logic is unchanged apart from the fixes below, and is now testable with an
  in-memory inbox.
- `MAIL_ENCRYPTION=tls` keeps its old meaning (STARTTLS on the plain port);
  `ssl` is implicit TLS. New optional `MAIL_INCOMING_VALIDATE_CERT` (default true).
- Fixed while porting: a "magica" mail from a sender with no display name failed
  to register (the fallback name was computed but not used), and the
  invalid-command reply was sent "from" the whole recipient list instead of the
  drone's address.
- Tests: `GetMailsTest` (handling logic, fake inbox) and `ImapInboxTest`
  (adapter and full `/get-mails` path against a real GreenMail IMAP server,
  which CI starts).

## Issues found while writing tests
- ✅ Fixed: `/user/send-report/{key}` and `/get-mails/{key}` now return a 403 unless
  `{key}` matches `CRON_KEY` (`config('app.cron_key')`). Both are refused while
  no key is configured.
- ✅ Fixed: the users report sorted by a column `users` doesn't have, and counted
  drones through a `companies` table that isn't in the schema.
- ✅ Fixed: `/companies/{id}` returns a 404 for an unknown id.
- ✅ Fixed: **SQL injection** in `/spacecraft/{id}`. `AgentController::view`
  pasted the URL id into a raw subquery, and MySQL's string-to-int cast let
  the model lookup succeed first. The query now uses the stored `agent_id`,
  and all `{id}` detail routes only accept numbers.
- ✅ Rebuilt: the `sqInventory` view used by the buy and sell order searches is
  now defined in `game-engine/sql/create_db.sql`. It gives each drone's current
  units per resource from its latest successful increment/decrement inventory
  event (the same rule as the cargo manifest). The original definition was
  lost; only fragments survive in the Access file. On an existing database,
  run the `CREATE OR REPLACE VIEW` statement from that file.

## Clean-up ✅ done
- Removed the stray root-level `HomeController.php` (an old copy of the real
  controller) and `info.php` (exposed `phpinfo()`).
- Renamed `model of .env` to `.env.example`.
- Removed the old Laravel migrations in `database/migrations`. They described a
  schema that no longer exists (a `companies` table, an older `agents`) and
  were unsafe to run on Laravel 11+. The schema comes from `game-engine/sql`;
  `documents/install.md` now says so.
- Game engines: any `.conf` parameter can be overridden by an environment
  variable of the same name, and the start scripts export
  `game-engine/scripts/.env`, so `DB_PASSWORD` can live there instead of in
  `conf/*.conf`. The engine log no longer prints passwords (it used to log every
  config value, which is how the DB password ended up in the old log files).
- The engines build again with current GCC (13): two `-Werror` failures were
  fixed without changing behaviour (an always-true null check in `db.c`, and a
  `strncat` that was really a `strcat` in `email.c`). CI now builds both engines.

## Rough effort
About 3–5 focused days for the backend hops, including tests, plus 2–3 days for the
Vite/Vue 3 frontend move. The biggest risks are the mail stack (steps 3 and 6)
and having no existing tests.
