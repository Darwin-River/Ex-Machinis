# Welcome to _Ex Machinis_

[Ex Machinis](http://exmachinis.wiki) is an online multiplayer space simulation designed to expose players to programming and robotics. In the game, which takes place against the backdrop of our real-time solar system, players program remotely piloted spacecraft to mine, manufacture, and trade goods and services.

The game uses augmented reality to project a parallel universe upon our own. In the game's re-imagined post-war history, world leaders embraced space exploration and colonization as a means of protecting mankind from the global threats that emerged in the wake of the second world war.

## About Our Team

Ex Machinis is being developed and managed by Darwin River Software LLC with support from a number of talented developers.  

* **Darwin River Software LLC** is a small software studio that was established to support the creation of engaging educational applications.  The organization is built on an open source model in which contributions from patrons, sponsors, and committed developers, are used to guide and support the creation of free educational software.  Ex Machinis is our effort to create a multiplayer space simulation that encourages and inspires the development of fundamental programming skills as a tool for success. 

## Our Awesome Sponsors

This game relies on our [generous Patreon sponsors](https://patreon.com/exmachinis) for feedback, encouragement, and support. We wouldn't be here without them!

## FORTH Defined

The [embed.fth file](game-engine/src/embed/embed.fth) creates the entire FORTH architecture that is used by Ex Machinis spacecraft.  It is a useful resource for understanding how each of the pre-loaded words function.

## Developer setup

### What's in this repo

| Path | What it is |
|---|---|
| `app/`, `routes/`, `resources/`, `config/` | The website: **Laravel 13** on **PHP 8.3** |
| `resources/assets/` | Frontend sources: **Vue 3** components and Sass, built with **Vite** |
| `html/` | The web root (not Laravel's usual `public/`). `html/build/` holds the built frontend and is committed |
| `game-engine/src/` | The **game engine** and **events engine**, written in C, which run each drone's FORTH VM and process game events |
| `game-engine/sql/`, `game-engine/db-backup/` | The MySQL schema and reference data. The schema comes from these files, not from Laravel migrations |
| `game-engine/scripts/`, `game-engine/conf/` | Cron/start scripts and engine configuration |
| `tests/` | PHPUnit tests and test fixtures |
| `documents/` | Game docs, the [production install guide](documents/install.md) and the [upgrade plan](documents/laravel-upgrade-plan.md) |

### Run the website locally

You need **PHP 8.3** (with `pdo_mysql`), **Composer**, **MySQL 5.7+** and **Node 20+**. Node is only needed to rebuild the frontend.

```bash
composer install
cp .env.example .env           # then set DB_* (and MAIL_* / CRON_KEY if you need them)
php artisan key:generate

# Create the database (drops and recreates the tables, so use a fresh database)
mysql -u root -p < game-engine/sql/create_db.sql
mysql -u root -p exmachinis < game-engine/db-backup/exmachinis_tables.sql

php artisan serve              # http://127.0.0.1:8000, serving html/
```

Frontend changes:

```bash
npm ci
npm run dev                    # live reload while you work
npm run build                  # writes html/build/; commit the result
```

CI fails if the committed `html/build/` doesn't match a fresh `npm run build`.

### Mail settings

- **`/get-mails/{key}`** reads the registrar and catch-all inboxes over IMAP using the `MAIL_*` settings in `.env`. It uses [`webklex/php-imap`](https://github.com/Webklex/php-imap), so PHP's `imap` extension isn't needed.
  - `MAIL_ENCRYPTION=tls` means STARTTLS on `MAIL_INCOMING_PORT`; `ssl` means implicit TLS.
- **`/get-mails/{key}`** and **`/user/send-report/{key}`** only work with the correct `CRON_KEY`, and are refused while it's empty.

### Build and configure the game engines

```bash
sudo apt-get install libconfig-dev libmysqlclient-dev
make -C game-engine/src all    # builds game-engine/src/bin/: game-engine, events-engine and eforth.blk
```

- Each engine reads `$PLAT_HOME/conf/<engine-name>.conf` and logs to `$PLAT_HOME/log/`. The [server setup notes](game-engine/doc/server_setup.txt) describe the `PLAT_HOME` layout.
- `eforth.blk` is the FORTH image each drone's VM starts from (`FORTH_IMAGE_PATH`, normally `$PLAT_HOME/bin/eforth.blk`). It is the engine's built-in image written to a file. Images built in `src/embed/` don't work with the engine.
- **Keep secrets out of the `.conf` files.** Any parameter can be overridden by an environment variable with the same name. The start scripts export `game-engine/scripts/.env` (template: `game-engine/scripts/env.advolition`), so put `DB_PASSWORD` and `CRON_KEY` there.

### Run the tests

The tests use a MySQL database built the same way, plus test fixtures:

```bash
mysql -u root -p < game-engine/sql/create_db.sql
mysql -u root -p exmachinis < game-engine/db-backup/exmachinis_tables.sql
mysql -u root -p < tests/database/fixtures.sql

DB_HOST=127.0.0.1 DB_PASSWORD=... vendor/bin/phpunit
```

`ImapInboxTest` also needs a [GreenMail](https://greenmail-mail-test.github.io/greenmail/) test mail server, and is skipped without one:

```bash
java -Dgreenmail.setup.test.all -Dgreenmail.auth.disabled -jar greenmail-standalone.jar &
IMAP_TEST_HOST=127.0.0.1 vendor/bin/phpunit
```

### Continuous integration

`.github/workflows/tests.yml` runs on every pull request and every push to `main`:
- **phpunit:** PHP 8.3 with MySQL 5.7 and GreenMail
- **frontend:** builds with Vite and checks `html/build/` is up to date
- **engine:** builds both C engines

### Deploying

The easiest way to set up a server is Docker: [deploy/README.md](deploy/README.md). One script starts the website, database, both engines and the mail server, then prints the DNS records to add.

For a manual install (Apache with `html/` as the web root, Postfix/Dovecot, and cron), see [documents/install.md](documents/install.md).

