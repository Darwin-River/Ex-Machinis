# Welcome to _Ex Machinis_

[Ex Machinis](http://exmachinis.wiki) is an online multiplayer space simulation designed to expose players to programming and robotics. In the game, which takes place against the backdrop of our real-time solar system, players program remotely piloted spacecraft to mine, manufacture, and trade goods and services.

**Break, learn, repeat.** Every release is live and free: release a drone, watch what goes wrong, and release a better one.

The game uses augmented reality to project a parallel universe upon our own. In the game's re-imagined post-war history, world leaders embraced space exploration and colonization as a means of protecting mankind from the global threats that emerged in the wake of the second world war.

## About Our Team

Ex Machinis is being developed and managed by Darwin River Software LLC with support from a number of talented developers.  

* **Darwin River Software LLC** is a small software studio that was established to support the creation of engaging educational applications.  The organization is built on an open source model in which contributions from supporters and committed developers, are used to guide and support the creation of free educational software.  Ex Machinis is our effort to create a multiplayer space simulation that encourages and inspires the development of fundamental programming skills as a tool for success. 

## Keep the lights on

Ex Machinis is free to play. Supporters cover what it costs to run (the server, the domain and the AI subscription that does most of the building); the site's **Keep the Lights On** page (`/support`) shows the costs, how much is covered, and how to chip in. See the [sustainability playbook](documents/sustainability-playbook.md).

## Licence and contributing

Ex Machinis is free and open source under the [MIT licence](LICENSE). See [CONTRIBUTING.md](CONTRIBUTING.md) to get involved, and [SECURITY.md](SECURITY.md) to report a security problem privately.

## Programming drones

Spacecraft are programmed in [Lua](https://www.lua.org/manual/5.4/): the [players' manual](documents/players-manual.md) teaches the game, [lua-functions.md](documents/lua-functions.md) lists the functions every drone knows, and `examples/` has starter scripts and company designs. Drones used to be programmed in FORTH; it was retired, but drones already running in FORTH carry on, on the engine's FORTH VM (built from [embed.fth](game-engine/src/embed/embed.fth)).

## Developer setup

### What's in this repo

| Path | What it is |
|---|---|
| `app/`, `routes/`, `resources/`, `config/` | The website: **Laravel 13** on **PHP 8.3** |
| `resources/assets/` | Frontend sources: **Vue 3** components and Sass, built with **Vite** |
| `html/` | The web root (not Laravel's usual `public/`). `html/build/` holds the built frontend and is committed |
| `game-engine/src/` | The **game engine** and **events engine**, written in C, which run each drone's Lua program (or, for drones released before FORTH was retired, its FORTH VM) and process game events |
| `game-engine/sql/`, `game-engine/db-backup/` | The MySQL schema and reference data. The schema comes from these files, not from Laravel migrations |
| `game-engine/sql/game-data/` | The game's design spreadsheets (resources, recipes, queries) and `build_game_data.py`, which turns them into `game-engine/sql/game_data.sql` and the query list in the design manual |
| `game-engine/scripts/`, `game-engine/conf/` | Cron/start scripts and engine configuration |
| `tests/` | PHPUnit tests and test fixtures |
| `documents/` | Game docs: the [player's manual](documents/players-manual.md) and the [design manual](documents/design-manual.md) (both kept up to date with the code), the [production install guide](documents/install.md), the [upgrade plan](documents/laravel-upgrade-plan.md), the [sustainability playbook](documents/sustainability-playbook.md) (funding, promotion and handing the project on), and the [open-source pathway](documents/open-source-pathway.md) (licence, audit, moving to a project organization) |

### Run the website locally

You need **PHP 8.3** (with `pdo_mysql`), **Composer**, **MySQL 5.7+** and **Node 20+**. Node is only needed to rebuild the frontend.

```bash
composer install
cp .env.example .env           # then set DB_* (and CRON_KEY if you need it)
php artisan key:generate

# Create the database (drops and recreates the tables, so use a fresh database)
mysql -u root -p < game-engine/sql/create_db.sql
mysql -u root -p exmachinis < game-engine/db-backup/exmachinis_tables.sql
DB_USER=root DB_NAME=exmachinis MYSQL_PWD=... game-engine/sql/migrate.sh
mysql -u root -p exmachinis < game-engine/sql/game_data.sql

php artisan serve              # http://127.0.0.1:8000, serving html/
```

Frontend changes:

```bash
npm ci
npm run dev                    # live reload while you work
npm run build                  # writes html/build/; commit the result
```

CI fails if the committed `html/build/` doesn't match a fresh `npm run build`.

### The scheduler's tick

- **`/tick/{key}`** takes the leaderboard's wealth snapshots and lets queued releases in. The Docker `scheduler` calls it every 5 seconds; a manual install calls it from cron. (`/get-mails/{key}`, its old name, still works.)
- It only works with the correct `CRON_KEY`, and is refused while that's empty. There is no email (#367 step 5): no accounts, and drones' replies show on their pages.

### Build and configure the game engines

```bash
sudo apt-get install libconfig-dev libmysqlclient-dev
make -C game-engine/src all    # builds game-engine/src/bin/: game-engine, events-engine and eforth.blk
```

- Each engine reads `$PLAT_HOME/conf/<engine-name>.conf` and logs to `$PLAT_HOME/log/`. The [server setup notes](game-engine/doc/server_setup.txt) describe the `PLAT_HOME` layout.
- `eforth.blk` is the FORTH image each drone's VM starts from (`FORTH_IMAGE_PATH`, normally `$PLAT_HOME/bin/eforth.blk`). It is the engine's built-in image written to a file. Images built in `src/embed/` don't work with the engine.
- **Keep secrets out of the `.conf` files.** Any parameter can be overridden by an environment variable with the same name. The start scripts export `game-engine/scripts/.env` (template: `game-engine/scripts/env.advolition`), so put `DB_PASSWORD` and `CRON_KEY` there.

### Changing game data

Resources, protocols (recipes, mining, travel, market), their effects, queries and where minerals can be mined all come from spreadsheets:

- `game-engine/sql/game-data/resources.xlsx`: resources and production recipes
- `game-engine/sql/game-data/queries.xlsx`: the list of in-game queries
- `documents/economy.xlsx`: mining, travel, market and starter-kit protocols, recipe timings and modules, and query SQL

After changing one, run `python3 game-engine/sql/game-data/build_game_data.py`. It rewrites `game-engine/sql/game_data.sql` and the query appendix of the [design manual](documents/design-manual.md), and lists anything it had to assume. Commit all three; CI fails if they're out of step. To load the new data into an existing database, run `game_data.sql` against it: it replaces those reference tables and leaves players, drones and events alone (Docker does this on every start).

Schema changes (new tables or columns) are numbered migrations in `game-engine/sql/migrations/`, applied by `game-engine/sql/migrate.sh`; see the README there.

### Run the tests

The tests use a MySQL database built the same way, plus test fixtures:

```bash
mysql -u root -p < game-engine/sql/create_db.sql
mysql -u root -p exmachinis < game-engine/db-backup/exmachinis_tables.sql
DB_USER=root DB_NAME=exmachinis MYSQL_PWD=... game-engine/sql/migrate.sh
mysql -u root -p < tests/database/fixtures.sql

DB_HOST=127.0.0.1 DB_PASSWORD=... vendor/bin/phpunit
```

### Continuous integration

`.github/workflows/tests.yml` runs on every pull request and every push to `main`:
- **phpunit:** PHP 8.3 with MySQL 5.7
- **frontend:** builds with Vite and checks `html/build/` is up to date
- **engine:** checks the generated game data is up to date, then builds both C engines
- **docker:** builds the Docker images and runs `deploy/smoke-test.sh`, which releases a drone on the website and plays the starting sequence: unpack the starter kit, fly to the Moon, mine

### Deploying

The easiest way to set up a server is Docker: [deploy/README.md](deploy/README.md), and [documents/hosting.md](documents/hosting.md) for a step-by-step guide to hosting, updating and backing up the public game. One script starts the website, database and both engines, then prints the DNS record to add.

For a manual install (Apache with `html/` as the web root, and cron), see [documents/install.md](documents/install.md). Its mail server sections are only for the old email game.

