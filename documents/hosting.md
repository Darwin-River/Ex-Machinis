# Hosting Ex Machinis

This guide runs the public game on one rented Linux server with Docker. It covers:

- choosing and preparing the server;
- installing the game;
- updating it;
- backing it up;
- restoring it, on the same server or a new one.

[deploy/README.md](../deploy/README.md) is the short reference for the Docker setup, and this guide builds on it. Players' tools and AI agents play through the [API](api.md) at `https://<domain>/api/v1`, which needs no setup.

## Contents

1. [Why one server](#why-one-server)
2. [Choose a server](#choose-a-server)
3. [Prepare the server](#prepare-the-server)
4. [Install the game](#install-the-game)
5. [Open the game to players](#open-the-game-to-players)
6. [Update the game](#update-the-game)
7. [Back up the game](#back-up-the-game)
8. [Restore from a backup](#restore-from-a-backup)
9. [Move to a new server](#move-to-a-new-server)
10. [Monitoring and upkeep](#monitoring-and-upkeep)
11. [Checklist](#checklist)

## Why one server

The game is the following pieces, which `deploy/docker-compose.yml` runs together:

- a website;
- a MySQL database;
- two engines that run constantly (the game engine runs the drones' programs, the events engine settles what they do);
- a scheduler;
- Caddy, which provides HTTPS.

The engines need a database that answers in well under a millisecond, and they write to it all the time. So the simplest, fastest and cheapest setup is everything on one virtual private server (VPS).

Platforms such as Railway or Render bill each service and the database separately and add network delay between them. They are a poor fit for always-on engines.

**Recommended host:** **Hetzner Cloud** (best price for the power).

**Alternative:** **DigitalOcean** (more data-centre regions, a friendlier dashboard). The steps below work on either; only the dashboard steps differ.

## Choose a server

| Use | vCPUs | RAM | Disk | Hetzner Cloud | DigitalOcean |
|---|---|---|---|---|---|
| Trying it out, a few dozen drones | 2 | 4 GB | 40 GB | CPX21 or CX32 | Basic 4 GB |
| **Public launch** (hundreds of drones, test world on) | 4 | 8 GB | 80 GB | **CPX31** | Basic or Premium 8 GB |
| A busy world (a thousand or more drones) | 8 | 16 GB | 160 GB | CPX41 or CCX23 | CPU-Optimized 16 GB |

Notes on choosing:

- **CPU is what limits the game.** Every drone's FORTH program runs on the game engine, and each drone action is a database write.
- **If the engines fall behind, the game slows down rather than breaking.** The game clock slows, and new releases queue (see `GAME_TIME_FLOOR` in [deploy/README.md](../deploy/README.md#settings)). If that happens, resize the server to a bigger plan: both hosts do it in a few minutes, keeping the disk.
- **Pick a region near most of your players.** For Hetzner that is Ashburn or Hillsboro for the Americas, and Falkenstein, Nuremberg or Helsinki for Europe.
- **Pick Ubuntu 24.04 LTS** as the image.

Before you start, you also need:

- **A domain name**, from any registrar. You only need to add one DNS record.
- **An SSH key** on your computer. Check for one with `ls ~/.ssh/id_ed25519.pub`. If there is none, make one with `ssh-keygen -t ed25519`.

## Prepare the server

### 1. Create the server

**Hetzner Cloud** ([console.hetzner.cloud](https://console.hetzner.cloud)):
1. Create a project, then click **Add Server**.
2. Choose these options:
   - **Location**: one near your players.
   - **Image**: Ubuntu 24.04.
   - **Type**: from the table above.
   - **Networking**: public IPv4 and IPv6.
   - **SSH key**: paste your `~/.ssh/id_ed25519.pub`.
   - **Backups**: on. This takes a daily whole-disk snapshot for 20% of the server price; it is a second safety net, not a replacement for the database backups below.
   - **Name**: for example `exmachinis`.
3. Under **Firewalls**, create a firewall with three inbound rules, and attach it to the server:
   - TCP 22 (SSH);
   - TCP 80 (HTTP);
   - TCP 443 (HTTPS).

**DigitalOcean** ([cloud.digitalocean.com](https://cloud.digitalocean.com)):
1. Click **Create**, then **Droplets**.
2. Choose these options:
   - a region near your players;
   - Ubuntu 24.04;
   - a size from the table above;
   - **SSH Key** authentication, with your key added;
   - **Enable automated backups** on;
   - a hostname such as `exmachinis`.
3. Under **Networking → Firewalls**, create a firewall with inbound TCP 22, 80 and 443, and apply it to the droplet.

Write down the server's public IPv4 address. Below it is called `SERVER_IP`.

### 2. Log in and update

```sh
ssh root@SERVER_IP
apt update && apt -y full-upgrade
reboot
```

Wait a minute, then log back in with `ssh root@SERVER_IP`.

### 3. Create a user for running the game

Running day to day as root is risky, so create a normal user (here `forge`) that can use `sudo`. It gets your SSH key.

```sh
adduser --disabled-password --gecos "" forge
usermod -aG sudo forge
mkdir -p /home/forge/.ssh
cp /root/.ssh/authorized_keys /home/forge/.ssh/
chown -R forge:forge /home/forge/.ssh && chmod 700 /home/forge/.ssh && chmod 600 /home/forge/.ssh/authorized_keys
passwd forge          # a password for sudo; you never log in with it
```

From your computer, check that `ssh forge@SERVER_IP` works. Then lock SSH down to keys only, with no root login:

```sh
sudo tee /etc/ssh/sshd_config.d/90-hardening.conf <<'EOF'
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
EOF
sudo systemctl restart ssh
```

Keep your current session open until you've confirmed that a new `ssh forge@SERVER_IP` still works.

### 4. Automatic security updates and swap

```sh
sudo apt -y install unattended-upgrades fail2ban
sudo dpkg-reconfigure -plow unattended-upgrades     # answer Yes
```

- **unattended-upgrades** installs security fixes every night.
- **fail2ban** blocks addresses that keep guessing at SSH.

A swap file stops a short memory spike (a big build, say) from killing MySQL:

```sh
sudo fallocate -l 4G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

### 5. Install Docker

```sh
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker forge
```

Then cap the size of the containers' own logs, so they can't fill the disk:

```sh
sudo tee /etc/docker/daemon.json <<'EOF'
{ "log-driver": "json-file", "log-opts": { "max-size": "50m", "max-file": "5" } }
EOF
sudo systemctl restart docker
```

Log out and back in, so `forge` can run `docker` without `sudo`. Check with `docker run --rm hello-world`.

Docker publishes ports 80 and 443 itself and bypasses `ufw`, so the host's firewall (from step 1) is the one that counts. The game publishes no other ports: the database is not reachable from outside.

## Install the game

### 1. Get the code

The public game runs from the **`public-forge`** branch.

```sh
sudo mkdir -p /opt/exmachinis && sudo chown forge:forge /opt/exmachinis
git clone -b public-forge https://github.com/Darwin-River/Ex-Machinis.git /opt/exmachinis
cd /opt/exmachinis
```

### 2. Run the setup script

```sh
deploy/setup.sh
```

It asks for two things:
- **the game's domain** (for example `play.example.com`);
- **your email address**, which Let's Encrypt uses for certificate notices.

Then it:
- writes `deploy/.env` with random passwords and keys;
- builds the images, which takes 5 to 10 minutes the first time;
- starts everything;
- prints the DNS record to add.

**Copy `deploy/.env` somewhere safe now**, such as a password manager. It holds the app key and the database passwords, which can't be recovered from anywhere else. The backups below also include it.

### 3. Point the domain at the server

At your DNS provider, add an **A record** for the domain with the value `SERVER_IP`. If the server has IPv6, also add an AAAA record with its IPv6 address.

DNS usually takes a few minutes to update, sometimes up to an hour. Once the domain points at the server, Caddy gets the HTTPS certificate by itself.

### 4. Check that it works

```sh
cd /opt/exmachinis/deploy
docker compose ps                  # everything "running"; migrate "exited (0)"
docker compose logs migrate        # ends with "migrate: game data loaded"
docker compose logs caddy | tail   # "certificate obtained successfully"
```

Then, in a browser:
1. Open `https://<domain>`.
2. On the **Release** page, pick the **Miner** starter and release it under any company name.
3. Open the drone's page. Within a minute or two it should show the drone loading its script and starting work.

## Open the game to players

Edit `deploy/.env` (`nano /opt/exmachinis/deploy/.env`) and add:

```
GAME_TIME_SCALE=24      # aims for one game day per real hour
GAME_TIME_FLOOR=8       # never slower than 8 game hours per real hour
TEST_WORLD=1            # the Release page's Test button
```

Apply the changes:

```sh
cd /opt/exmachinis/deploy
docker compose up -d
docker compose --profile testworld up -d      # the test world's engines
```

What these settings do:
- **`GAME_TIME_SCALE`** sets how fast game time runs.
- **`GAME_TIME_FLOOR`** is how far the clock may slow down when the server is busy.
- **`TEST_WORLD`** turns on the Test button, which lets players try a script in a separate world before they release it.

[deploy/README.md](../deploy/README.md#opening-public-forge) explains them in full, including how to load-test the server before you announce the game.

**Starting the world.** A fresh install has only the exchanges' station drones. The website creates them each time it starts (`forge:stations`).

For a livelier market on day one, release a few starters yourself from the Release page, for example two Miners, a Refiner and a Maker, so the first players find sellers and buyers.

`forge:load-test` refuses to run on the live database, so use it only on the test world, as described above.

## Update the game

New code reaches the game through the `public-forge` branch.

**What happens during an update:**
- The containers restart. Drones pause for a minute or two and carry on; nothing is lost.
- The `migrate` step updates the database schema and the game data (resources, recipes, queries) automatically.
- Players and their drones are kept.

### Every update

```sh
cd /opt/exmachinis
deploy/backup.sh                                  # 1. a backup first (see below)
git fetch origin public-forge
git log --oneline HEAD..origin/public-forge      # 2. what's new
git pull --ff-only origin public-forge            # 3. take it
cd deploy
docker compose up -d --build                      # 4. rebuild and restart
docker compose --profile testworld up -d          #    (if the test world is on)
docker compose logs migrate | tail -5             # 5. "migrate: game data loaded"
docker compose ps                                 # 6. all running
```

Then open the site and check a drone page, as in step 4 of the install.

**Pick a quiet time.** The rebuild takes a few minutes, but the site stays up until the new containers replace the old ones.

**Clean up old images** every few updates. Unused images take several gigabytes:

```sh
docker image prune -f
```

### If an update goes wrong

1. **Look at the logs first:** `docker compose logs --tail 100 app game-engine events-engine migrate`.

2. **Go back to the previous code.** Every backup records the code version it was taken with, in its `commit` file. Run:

   ```sh
   cd /opt/exmachinis
   git checkout <commit from the backup>          # e.g. git checkout $(cat /var/backups/exmachinis/<backup>/commit)
   cd deploy && docker compose up -d --build
   ```

3. **If the update changed the database schema** (its `migrate` log listed a new migration), going back to old code is not enough. Database migrations only go forward, so also restore the backup you took before updating (see [Restore from a backup](#restore-from-a-backup)).

4. **Once the fix is out, return to the branch:**

   ```sh
   git checkout public-forge && git pull --ff-only
   ```

## Back up the game

### What to back up

| What | Where it lives | How |
|---|---|---|
| **The game's database** (companies, drones, the market, everything players did) | Docker volume `db-data` | `deploy/backup.sh` (a `mysqldump`) |
| The test world's database | the same volume | `deploy/backup.sh` |
| **`deploy/.env`** (app key and passwords) | `/opt/exmachinis/deploy/.env` | `deploy/backup.sh` copies it into every backup |
| The code | GitHub | the backup records its version (`commit`) |
| HTTPS certificates | volume `caddy-data` | not needed: Caddy gets new ones |
| Engine logs and website cache | volumes `engine-logs` and `app-storage` | not needed |

### The backup script

`deploy/backup.sh` writes a dated folder, such as `/var/backups/exmachinis/2026-11-02_0317/`, containing:

| File | What it is |
|---|---|
| `exmachinis.sql.gz` | the live world |
| `exmachinis_test.sql.gz` | the test world, if it exists |
| `env` | a copy of `deploy/.env` |
| `commit` | the version of the code the backup was taken with |

How it behaves:
- **The dump runs while the game runs.** It reads a consistent snapshot of the database, so drones keep working and nobody notices.
- **It checks each dump** is complete, and fails loudly if not.
- **It deletes backups older than 14 days.**

Run it once by hand to check it:

```sh
sudo mkdir -p /var/backups/exmachinis && sudo chown forge:forge /var/backups/exmachinis
/opt/exmachinis/deploy/backup.sh
ls -lh /var/backups/exmachinis/*/
```

Settings, as environment variables:

| Setting | Default | What it does |
|---|---|---|
| `BACKUP_DIR` | `/var/backups/exmachinis` | where backups go |
| `KEEP_DAYS` | 14 | how many days of backups to keep |
| `RCLONE_REMOTE` | (none) | also copy each backup off the server (below) |

### Back up every night

Edit the cron table with `crontab -e` as `forge`, and add:

```
# Ex Machinis: back up at 03:17 every night, and keep a log
17 3 * * * /opt/exmachinis/deploy/backup.sh >> /var/backups/exmachinis/backup.log 2>&1
```

Check the log after the first night: `tail /var/backups/exmachinis/backup.log`.

### Keep a copy off the server

A backup on the same disk doesn't survive losing the server. Copy each one off it with [rclone](https://rclone.org), which works with almost any storage.

Good, cheap options:
- **Hetzner Storage Box**, about €4 a month for 1 TB, reached over SFTP.
- **Backblaze B2**, or **DigitalOcean Spaces**, both S3-compatible.

1. Install rclone and set up a remote. Run `rclone config`, choose **n** (new remote), and answer its questions. For a Storage Box:
   - choose **sftp**;
   - the host is `uXXXXX.your-storagebox.de`, port 23;
   - the user is your Storage Box user;
   - use the password, or better, an SSH key.

   Call the remote `offsite`, then check it:

   ```sh
   sudo apt -y install rclone
   rclone config
   rclone mkdir offsite:exmachinis && rclone lsd offsite:
   ```

2. Add the remote to the cron line:

   ```
   17 3 * * * RCLONE_REMOTE=offsite:exmachinis /opt/exmachinis/deploy/backup.sh >> /var/backups/exmachinis/backup.log 2>&1
   ```

Each backup is then copied to `offsite:exmachinis/<date>`, and copies older than `KEEP_DAYS` are removed there too. To keep more history off the server, give it a larger value, for example `KEEP_DAYS=60`.

The backup files contain `.env`'s secrets. Keep the remote private, and turn on encryption at rest where the provider offers it, or use an rclone `crypt` remote on top.

### Test your backups

A backup you've never restored is a hope, not a backup. Once a month, restore the latest one into a scratch database and look at it:

```sh
cd /opt/exmachinis/deploy
B=$(ls -d /var/backups/exmachinis/20* | tail -1)
docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "DROP DATABASE IF EXISTS restore_check; CREATE DATABASE restore_check"'
gunzip -c $B/exmachinis.sql.gz | docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot restore_check'
docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot restore_check -e "SELECT COUNT(*) AS companies FROM companies; SELECT COUNT(*) AS drones FROM agents"'
docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "DROP DATABASE restore_check"'
```

The counts should match the leaderboard's.

## Restore from a backup

Restoring replaces the whole world with the backup's: everything since the backup is lost. Stop everything that writes to the database first:

```sh
cd /opt/exmachinis/deploy
B=/var/backups/exmachinis/<the backup to restore>
docker compose stop app scheduler game-engine events-engine
docker compose --profile testworld stop                     # if the test world is on

# The live world
gunzip -c $B/exmachinis.sql.gz | docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot exmachinis'
# The test world (optional)
gunzip -c $B/exmachinis_test.sql.gz | docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot exmachinis_test'

docker compose up -d                                         # migrate runs, then everything starts
docker compose --profile testworld up -d
```

- **If the backup is older than the code**, the `migrate` step brings its schema up to date as everything starts.
- **If the code is older than the backup** (you rolled back), first check out the code the backup was taken with: `git checkout $(cat $B/commit)`.
- **To restore from the off-site copy**, fetch it first:

  ```sh
  rclone copy offsite:exmachinis/<date> /var/backups/exmachinis/<date>
  ```

## Move to a new server

Use this when changing hosts or server size the long way, or recovering from a lost server.

1. **Prepare the new server**: follow [Prepare the server](#prepare-the-server).

2. **Fetch the backup and get the code at the version the backup was taken with:**

   ```sh
   sudo mkdir -p /opt/exmachinis /var/backups/exmachinis && sudo chown forge:forge /opt/exmachinis /var/backups/exmachinis
   rclone copy offsite:exmachinis/<date> /var/backups/exmachinis/<date>       # or scp it from the old server
   B=/var/backups/exmachinis/<date>
   git clone -b public-forge https://github.com/Darwin-River/Ex-Machinis.git /opt/exmachinis
   cd /opt/exmachinis && git checkout $(cat $B/commit)
   ```

3. **Put the old `.env` back.** This brings the same app key and passwords:

   ```sh
   cp $B/env deploy/.env && chmod 600 deploy/.env
   ```

4. **Start it, then restore the backup:**

   ```sh
   cd deploy
   docker compose up -d --build
   ```

   Wait until `docker compose logs migrate` shows "game data loaded". Then follow [Restore from a backup](#restore-from-a-backup).

5. **Move the DNS** A record to the new server's IP. Caddy gets a certificate once the new IP is live.

6. **Get back onto the branch:**

   ```sh
   git checkout public-forge && git pull --ff-only && docker compose up -d --build
   ```

7. **Set up the nightly backup cron line** on the new server.

8. **Delete the old server** after a few days, once you're sure.

To keep the downtime short when moving on purpose:
- Lower the DNS record's TTL to 300 seconds a day before.
- On the old server, stop the engines (`docker compose stop app scheduler game-engine events-engine`) and run `deploy/backup.sh`.
- Restore that backup on the new server.

## Monitoring and upkeep

| How often | What | How |
|---|---|---|
| Daily, at a glance | The site is up, and the game clock is moving | The Home page's clock line. Free uptime monitors such as UptimeRobot or Better Stack can check `https://<domain>` every few minutes and email you |
| Weekly | Disk space | `df -h /` (keep it under 80%) and `docker system df` |
| Weekly | Backups ran | `tail /var/backups/exmachinis/backup.log` and `rclone lsd offsite:exmachinis` |
| Weekly | The engines are keeping up | the Home page's clock: at the floor, with releases queueing, means the server is too small |
| Monthly | Test a restore | [Test your backups](#test-your-backups) |
| Monthly | Reboot for kernel updates, if one is waiting | `[ -f /var/run/reboot-required ] && sudo reboot` (everything restarts on its own) |
| Every few updates | Remove old images | `docker image prune -f` |

**If the disk fills up**, check these in order:
- old images: `docker image prune -a -f`;
- old backups: lower `KEEP_DAYS`;
- the engines' logs, which are cleared after 7 days on their own: `docker compose exec game-engine du -sh /opt/exmachinis/log`.

**If the server is too slow**, resize it from the host's dashboard:
- **Hetzner:** Rescale, keeping the disk.
- **DigitalOcean:** Resize, choosing CPU and RAM only.

Both power the server off for a few minutes, and everything starts again on its own.

## Checklist

- [ ] The server is created, with the host's firewall allowing only 22, 80 and 443, and its backups on.
- [ ] Logging in as `forge` works with a key only; root login and passwords are off.
- [ ] Unattended upgrades, fail2ban, swap and Docker's log limit are set up.
- [ ] The game was installed from `public-forge` with `deploy/setup.sh`.
- [ ] `deploy/.env` is saved in a password manager.
- [ ] The DNS A record is added, HTTPS works, and a released Miner runs.
- [ ] Public Forge settings and the test world are on.
- [ ] `deploy/backup.sh` runs nightly from cron and copies off the server.
- [ ] A restore was tested.
- [ ] An uptime monitor watches the site.
