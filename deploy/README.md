# Running Ex-Machinis with Docker

This folder runs the whole game on one server with Docker Compose. One script sets it up. You only add DNS records.

| Service | What it does |
|---|---|
| `caddy` | HTTPS for the website, with automatic Let's Encrypt certificates (ports 80 and 443) |
| `app` | The Laravel website (PHP 8.3 and Apache) |
| `db` | MySQL 8.4, loaded with the game's base schema when its volume is first created |
| `migrate` | Runs on every start, before the website and engines: applies new schema migrations (`game-engine/sql/migrations/`), then loads the game data (`game-engine/sql/game_data.sql`: resources, protocols, queries, where minerals are found), then exits |
| `game-engine` | Runs the drones' FORTH programs and emails their output to players |
| `events-engine` | Processes game events |
| `scheduler` | Checks the game's mailboxes every 5 seconds, which replaces the old cron jobs, and clears engine logs older than 7 days |
| `mail` | [docker-mailserver](https://docker-mailserver.github.io/docker-mailserver/latest/) (Postfix and Dovecot) receives players' mail on port 25 and sends the game's mail |

## How mail flows

- **Joining:** a player emails `registrar@<domain>` with the subject `magica`, or signs up on the website.
- **Commands:** a player emails `<drone name>@<domain>`. Every address other than `registrar@` lands in a catch-all mailbox.
- **Processing:** the website reads both mailboxes (it's called by `scheduler`), registers players and queues the commands.
- **Replies:** `game-engine` runs the commands and emails the output back through `mail`.

## Requirements

- A Linux server, such as Ubuntu 24.04 or Debian 12, with at least 2 GB of RAM and 20 GB of disk.
- A domain name you can add DNS records to.
- Inbound ports **25**, **80** and **443** open.
- **Outbound port 25 allowed.** Many cloud providers block it by default (AWS, Google Cloud, Azure, and DigitalOcean for new accounts). Ask them to unblock it, or pick a provider that allows it.
- Reverse DNS (PTR) for the server's IP, set to `mail.<domain>`. Your hosting provider sets this.

## Install

1. Install Docker, which includes the Compose plugin:
   ```sh
   curl -fsSL https://get.docker.com | sh
   ```
2. Get the code:
   ```sh
   git clone https://github.com/Darwin-River/Ex-Machinis.git
   cd Ex-Machinis
   ```
3. Run the setup script. It asks for the game's domain and your email address:
   ```sh
   sudo deploy/setup.sh
   ```
   The script does the following:
   - Writes `deploy/.env` with random passwords and keys.
   - Creates the `registrar@` and catch-all mailboxes.
   - Builds and starts everything.
   - Creates a DKIM key.
   - Prints the DNS records to add.

   The first build takes a few minutes.
4. Add the printed DNS records at your DNS provider. They are:
   - A records for `<domain>` and `mail.<domain>`
   - MX
   - SPF
   - DKIM
   - DMARC

   Once DNS points at the server, Caddy gets the HTTPS certificate on its own. Until then it retries, and its logs show certificate errors.
5. Check that it works:
   - Open `https://<domain>`.
   - Email `registrar@<domain>` with the subject `magica`. A welcome email with your drones' addresses should arrive within a minute.

**Back up `deploy/.env`.** It holds the app key and every password.

## Everyday tasks

Run these from the `deploy/` folder.

| Task | Command |
|---|---|
| Status | `docker compose ps` |
| Follow all logs | `docker compose logs -f` |
| Logs for one service | `docker compose logs -f game-engine` |
| Detailed engine logs (every orbit, distance and date calculation) | add `APP_TRACE_LEVEL: "3"` to the engine's `environment` in `docker-compose.yml`, then `docker compose up -d` |
| Update to the latest code | `git pull && docker compose up -d --build` |
| Apply new migrations and game data without restarting the engines | `docker compose run --rm migrate` (`docker compose up -d` also does it) |
| Schema version | `docker compose exec db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" exmachinis -e "SELECT * FROM schema_version"'` |
| Restart everything | `docker compose restart` |
| Stop / start | `docker compose stop` / `docker compose up -d` |
| MySQL shell | `docker compose exec db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" exmachinis'` |
| Laravel commands | `docker compose exec app php artisan <command>` |

### Backups

The data lives in Docker volumes: the database, the mailboxes, and Caddy's certificates. To back up the database:

```sh
docker compose exec -T db sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" exmachinis' > exmachinis-$(date +%F).sql
```

Also keep a copy of `deploy/.env` and `deploy/mail/config/`, which holds the mail accounts and the DKIM key.

To restore a dump:

```sh
docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" exmachinis' < exmachinis-YYYY-MM-DD.sql
```

### Starting over

This **deletes all game data, mail and certificates**:

```sh
docker compose down -v
rm -rf .env mail/config
```

## Settings

`deploy/.env` holds the domain, your email address and the secrets. Optional extras:

- `SITE_ADDRESS`: the names Caddy serves. For example, `SITE_ADDRESS=example.com, www.example.com` also serves `www`, which needs its own DNS record.
- `APP_URL`: the site's public URL, if it isn't `https://<domain>`.
- `GAME_TIME_SCALE`: how fast game time passes, in game seconds per real second (default 60: one real minute is one game hour). It governs orbits, action and travel durations, and the light-speed delay of emails. Changing it re-anchors the game clock, so nothing jumps.
- `GAME_TIME_FLOOR`: the clock floor (default 0: the clock never floats). The clock aims for `GAME_TIME_SCALE`, slows by a quarter each minute while the engines run more than `CLOCK_LAG_HIGH` (60) real seconds behind, never below this, and speeds back up once they are less than `CLOCK_LAG_LOW` (15) behind. Behind at the floor, the world is full: new releases wait in a launch queue. The Home page shows the clock.
- `ENGINE_TRACE_LEVEL`: the engines' log detail (default -1: errors, warnings and clock changes only). 0 to 3 log every step, which at full load is gigabytes a day; rotated logs are kept 7 days.
- `TRAVEL_ACCELERATION`: drones' acceleration in m/s² (default 1). Trips take 2·√(distance ÷ acceleration) in game time.
- `GAME_EMAIL=0`: the Public Forge world. Email is off; players open an account and release scripts on the website's Release page.
- `TEST_WORLD=1`: shows the Release page's Test button, which runs a script in the test world (database `exmachinis_test`, which `migrate` keeps up to date). Its engines run in the `testworld` profile: start them with `docker compose --profile testworld up -d`. `TEST_GAME_TIME_SCALE` sets its clock (default 600: ten times the live world's default).

Apply changes with `docker compose up -d`.

All other settings are in `docker-compose.yml`. The engines' settings come from `game-engine/conf/*.conf`. You can override any of them by adding an environment variable of the same name to the engine services.

The mail accounts depend on the domain. To move to another domain, start over with the new one, or edit `mail/config/postfix-accounts.cf` and `postfix-virtual.cf` by hand.

## Opening Public Forge

The first release of Public Forge has email off and no accounts: anyone can release a script on the website. In `deploy/.env`:

```
GAME_EMAIL=0
GAME_TIME_SCALE=24      # aims for one game day per real hour
GAME_TIME_FLOOR=8       # never slower than 8 game hours per real hour
TEST_WORLD=1            # optional: the Release page's Test button
```

Then `docker compose up -d` (and `docker compose --profile testworld up -d` for the test world).

1. **Load test first**, on a copy, never the live database: `docker compose exec app php artisan forge:load-test 1000 --database=exmachinis_test` releases a thousand starter drones into the test world; `... forge:load-test --report --database=exmachinis_test` shows how the engines keep up, and the test engines' clock floats the same way if you give them a floor. See the design manual's [Throughput](../documents/design-manual.md#Engine-Throughput) for what to look for.
2. **Tell players** the site's address: the Release page needs no account.
3. **Watch** the Home page's clock line: if the clock sits at the floor and the world is full, releases queue until there is room. A faster server, or a lower floor, makes room.

## Troubleshooting

- **The site shows a certificate error.** DNS doesn't point at the server yet, or port 80 or 443 is closed. Run `docker compose logs caddy`.
- **Mail from players never arrives.** Check:
  - the MX record
  - that inbound port 25 is open: `nc -vz <server-ip> 25` from another machine
  - `docker compose logs mail`
- **Players don't get replies, or replies land in spam.** Check:
  - that outbound port 25 is allowed
  - the SPF, DKIM and PTR records
  - `docker compose logs mail`, which shows each delivery attempt
- **The scheduler logs "mail check failed".** Run `docker compose logs app` to see the website's error.

## Testing

`deploy/smoke-test.sh` starts the stack for the fake domain `example.test` and checks the whole loop: website, mail check, registration by email, and a drone running an emailed command. CI runs it on every pull request. To run it yourself, use a machine where ports 25, 80 and 443 are free.
