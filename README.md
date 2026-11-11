# Ex Machinis

**A free multiplayer space game you play by writing code.** You program drones in [Lua](https://www.lua.org/manual/5.4/); they mine, refine, build, trade and replicate across our real solar system, on their own, while you watch.

**Break, learn, repeat.** Every release is live and free: release a drone, watch what goes wrong, and release a better one.

## The game

Ex Machinis is set in a parallel history where the nations of the world turned to space after the Second World War. By now, fleets of unmanned spacecraft mine the planets, moons, asteroids and comets, and every citizen has the right to a spacecraft of their own. Yours does exactly what your program tells it to, and nothing else.

- **Release a script, start a company.** Write a Lua program with a `launched()` function and release it on the website or through the [API](documents/api.md). No account, no download, no cost. Each release founds a new company: one drone in orbit around Earth with 100 fuel and 3,000 credits.
- **Your drone runs on its own.** It buys its equipment, flies, mines, refines and manufactures, posts buy and sell orders at the exchanges, talks to other drones, and builds and launches new drones. You can't steer it once it's released; its public page shows where it is, what it carries, what it did and what it says.
- **The world is shared and fast.** Every player's drones work the same markets, deposits and routes, and prices move with supply and demand. A game day passes in under a minute, so you see how a design does in minutes.
- **Failure is part of the game.** Drones run out of fuel, overspend, crash and become wrecks. Each failure shows you what your program didn't foresee; fix it and release the next version.

### Objectives

- **Build the most valuable company.** The website's **Leaderboard** ranks companies by **net wealth** (credits, plus goods and shares held, minus what every company starts with), all time and over the last week.
- **Find a niche.** Mine what others don't, supply fuel where it's short, manufacture components, or run a trading company; companies can also raise credits by issuing shares on the stock exchange.
- **Grow a fleet.** A successful drone pays for copies of itself, each running its own code.
- **Learn to program.** The real objective: every lineage of companies teaches loops, state machines, functions, data, and how to handle what goes wrong. The game is built to be an approachable first programming project, and a hard optimisation problem for experienced coders and AI agents alike.

## Play

- **On the website:** open the **Release** page, start from a starter script, and release it. Then follow your drone's page.
- **From code or an AI agent:** the [API](documents/api.md) releases and follows companies as JSON, under the same rules.

## Documentation

- [Players' manual](documents/players-manual.md): learn the game and Lua, step by step.
- [Lua functions](documents/lua-functions.md): every function a drone knows.
- [Strategy blog](documents/blog/): how the best companies in our playtests were written.
- [API](documents/api.md): play from a program.
- [Design manual](documents/design-manual.md) and [economy playbook](documents/economy-playbook.md): how the game works inside, and how its economy is tuned.
- [Hosting](documents/hosting.md): run your own world.
- [Sustainability playbook](documents/sustainability-playbook.md) and [open-source pathway](documents/open-source-pathway.md): how the project is funded, promoted and handed on.

## Licence

Ex Machinis is free and open source under the [MIT licence](LICENSE): you may use, copy, change and share it, including hosting your own world, as long as the licence notice stays with it. Bundled third-party code keeps its own MIT licences: [Lua 5.4](game-engine/lua54/) and the [embed eForth VM](game-engine/src/embed/LICENSE).

## Contributing and support

- **Contribute:** bug reports, ideas, docs, strategy posts and code are all welcome. Start with [CONTRIBUTING.md](CONTRIBUTING.md); every change starts with an issue, and pull requests go into `main`. Please follow the [code of conduct](CODE_OF_CONDUCT.md), and report security problems privately ([SECURITY.md](SECURITY.md)).
- **Keep the lights on:** the game is free, with no ads and nothing to buy. Supporters cover its running costs (the server, the domain and the AI subscription that does most of the building); the website's **Keep the Lights On** page shows the costs, how much is covered, and how to chip in.

## Programming drones

Drones are programmed in Lua: the [players' manual](documents/players-manual.md) teaches the game, [lua-functions.md](documents/lua-functions.md) lists the functions every drone knows, and `examples/` has starter scripts and company designs. Drones used to be programmed in FORTH; it was retired, but drones already running in FORTH carry on, on the engine's FORTH VM (built from [embed.fth](game-engine/src/embed/embed.fth)).

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
| `documents/` | The docs: see [Documentation](#documentation) above |
| `examples/` | Starter scripts, example company designs and queries |
| `admin/` | Maintainer tools: blog announcements, analytics notes, the history clean-up |

### Run the website locally

You need **PHP 8.3** (with `pdo_mysql`), **Composer**, **MySQL 8** and **Node 20+**. Node is only needed to rebuild the frontend.

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
- **phpunit:** PHP 8.3 with MySQL 8.4
- **frontend:** builds with Vite and checks `html/build/` is up to date
- **engine:** checks the generated game data is up to date, then builds both C engines
- **docker:** builds the Docker images and runs `deploy/smoke-test.sh`, which releases a drone on the website and plays the starting sequence: unpack the starter kit, fly to the Moon, mine

### Deploying

The easiest way to set up a server is Docker: [deploy/README.md](deploy/README.md), and [documents/hosting.md](documents/hosting.md) for a step-by-step guide to hosting, updating and backing up the public game. One script starts the website, database and both engines, then prints the DNS record to add.

For a manual install (Apache with `html/` as the web root, and cron), see [documents/install.md](documents/install.md). Its mail server sections are only for the old email game.

