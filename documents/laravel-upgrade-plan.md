# Laravel upgrade plan

Plan for moving the website (`app/`, `routes/`, `resources/`, `config/`) off its
end-of-life stack. The C game engine is not affected.

## Where we are

| Piece | Current | Status |
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
- Stay on PHP 8.3 until the IMAP question below is settled, because 8.4 drops the
  `imap` extension.

## Steps

Do each hop on its own branch and deploy it (or at least smoke-test it)
before starting the next. Laravel only supports upgrading one major at a time.

### 0. Safety net (before touching versions)
1. Write feature tests for the pages people actually use: home, login/register,
   spacecraft list and detail, resources with buy and sell orders, space
   objects, and `/get-mails/{key}`. HTTP status plus key text is enough.
2. Add a GitHub Actions workflow that runs `composer install` and `phpunit`.
3. Take a DB backup (see `admin/db_backups.md`).

### 1. Laravel 6 → 7
- `app/Exceptions/Handler.php`: change `Exception $exception` to `Throwable $exception`
  in `report()` and `render()`.
- Bump `phpunit` to ^8.5, `nunomaduro/collision` if added, and `laravel/tinker` to ^2.
- `config/session.php` uses `str_slug()`; the string and array helpers were removed
  in 6. Replace it with `Str::slug()` (or add `laravel/helpers` for a while).

### 2. Laravel 7 → 8
- Models move to `app/Models` by default. This is optional; keeping them in `app/` still works.
- `database/seeds` → `database/seeders` with namespaces. Update composer autoload.
- Swap `fzaninotto/faker` for `fakerphp/faker`.
- Route strings like `'HomeController@index'` still work as long as the
  `$namespace` property stays in `RouteServiceProvider`. Migrating to
  `[HomeController::class, 'index']` is cleaner and needed by step 4.
- Pagination now defaults to Tailwind. Call `Paginator::useBootstrap()` because the site uses Bootstrap.

### 3. Laravel 8 → 9 (needs PHP 8.0+)
- Remove `fideloper/proxy`. Make `TrustProxies` extend
  `Illuminate\Http\Middleware\TrustProxies`.
- Flysystem 3 and Symfony Mailer replace SwiftMailer. Check the mail config
  (`MAIL_DRIVER` → `MAIL_MAILER`) and the welcome and report emails.
- `graham-campbell/markdown` goes to ^14. The config format changed.

### 4. Laravel 9 → 10 → 11 → current
- Laravel 10: PHP 8.1+, phpunit 10. The upgrade is mostly mechanical.
- Laravel 11: the new slim app skeleton is optional. Existing `Kernel.php` files
  keep working, so don't restructure.
- After that: follow each release's upgrade guide. Consider running
  [Laravel Shift](https://laravelshift.com) for the mechanical parts.

### 5. Frontend
- Fix the `package.json` scripts for Mix 6 first (`mix`, `mix watch`, `mix --production`).
- Then move to **Vite** (`laravel-vite-plugin`) and **Vue 3**. The 9 components are
  simple tables. The main changes are `Vue.component` → `app.component` and
  removing filters if they're used.
- Bootstrap `4.0.0-beta` should go to 4.6 or 5.

### 6. IMAP mail intake
`/get-mails/{key}` reads the registrar inbox with `php-imap`, which depends on the
`imap` extension. Before moving to PHP 8.4, either install the PECL `imap`
extension on the server or switch to a pure-PHP client such as
`webklex/php-imap`.

## Clean-up worth doing along the way
- Delete or move the stray root-level `HomeController.php` (it differs from
  `app/Http/Controllers/HomeController.php`) and `info.php` (it exposes `phpinfo()`).
- Rename `model of .env` to `.env.example`, which is the Laravel convention.
- Move secrets out of `game-engine/conf/*.conf` into environment variables.

## Rough effort
About 3–5 focused days for the backend hops, including tests, plus 2–3 days for the
Vite/Vue 3 frontend move. The biggest risks are the mail stack (steps 3 and 6)
and having no existing tests.
