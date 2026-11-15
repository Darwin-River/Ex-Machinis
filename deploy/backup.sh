#!/usr/bin/env bash
#
# Backs up the game: the live database and
# deploy/.env (the app key and passwords), into one dated folder. Run it from
# anywhere, as a user who can run docker:
#
#   deploy/backup.sh                     # into /var/backups/exmachinis
#   BACKUP_DIR=/srv/backups deploy/backup.sh
#
# Optional settings (environment variables):
#   BACKUP_DIR      where backups go (default /var/backups/exmachinis)
#   KEEP_DAYS       backups older than this are deleted (default 14)
#   KEEP_COUNT      at most this many backups are kept, the newest (default 5)
#   RCLONE_REMOTE   also copy each backup off the server with rclone, e.g.
#                   "storagebox:exmachinis" (see documents/hosting.md)
#
# The dump is consistent while the game runs (--single-transaction): drones
# keep working during a backup. See documents/hosting.md for restoring.
set -euo pipefail

cd "$(dirname "$0")"

BACKUP_DIR="${BACKUP_DIR:-/var/backups/exmachinis}"
KEEP_DAYS="${KEEP_DAYS:-14}"
KEEP_COUNT="${KEEP_COUNT:-5}"
STAMP="$(date -u +%Y-%m-%d_%H%M)"
DEST="$BACKUP_DIR/$STAMP"

die() { echo "backup: $*" >&2; exit 1; }
[ -f .env ] || die "deploy/.env is missing: run setup.sh first"
docker compose ps --status running db | grep -q db || die "the database isn't running (docker compose up -d)"

# The backups here, oldest first (their names are UTC timestamps)
backups() { find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name '20*' 2>/dev/null | sort; }
free_kb() { df -Pk "$BACKUP_DIR" | awk 'NR == 2 { print $4 }'; }

# Room for the new backup (#701: a full disk stopped a deploy): twice the
# newest backup's size, or 1 GB if there is none. The oldest backups go
# until there is room, always keeping one; if that isn't enough, stop
mkdir -p "$BACKUP_DIR" 2>/dev/null || true
if [ -d "$BACKUP_DIR" ]; then
    newest=$(backups | tail -n 1)
    need_kb=1048576
    [ -n "$newest" ] && need_kb=$(( $(du -sk "$newest" | cut -f1) * 2 ))
    while [ "$(free_kb)" -lt "$need_kb" ] && [ "$(backups | wc -l)" -gt 1 ]; do
        oldest=$(backups | head -n 1)
        echo "backup: making room: removing $oldest"
        rm -rf "$oldest"
    done
    [ "$(free_kb)" -ge "$need_kb" ] ||
        die "not enough disk for a backup: $(( $(free_kb) / 1024 )) MB free, about $(( need_kb / 1024 )) MB needed. Free some space (documents/hosting.md, \"If the disk fills up\")"
fi

# A backup that fails part-way is removed, so it doesn't fill the disk
trap 'rm -rf "$DEST"' ERR
fail() { rm -rf "$DEST"; die "$@"; }

umask 077
mkdir -p "$DEST" 2>/dev/null ||
    die "can't write to $BACKUP_DIR. Make it once: sudo mkdir -p $BACKUP_DIR && sudo chown $(id -un):$(id -un) $BACKUP_DIR"

dump() {   # dump <database> <file>
    docker compose exec -T db sh -c \
        'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --quick --routines --triggers --no-tablespaces "$1"' \
        sh "$1" | gzip > "$2"
    # A dump that ends without its last line was cut short
    gzip -dc "$2" | tail -n 1 | grep -q "Dump completed" || fail "the dump of $1 is incomplete"
}

dump exmachinis "$DEST/exmachinis.sql.gz"
cp .env "$DEST/env"
git -C .. rev-parse HEAD > "$DEST/commit"   # the code the data goes with

echo "backup: $(du -sh "$DEST" | cut -f1) in $DEST"

if [ -n "${RCLONE_REMOTE:-}" ]; then
    rclone copy "$DEST" "$RCLONE_REMOTE/$STAMP" || die "copying off the server failed (the local backup is fine)"
    rclone delete --min-age "${KEEP_DAYS}d" "$RCLONE_REMOTE" && rclone rmdirs --leave-root "$RCLONE_REMOTE" || true
    echo "backup: copied to $RCLONE_REMOTE/$STAMP"
fi

trap - ERR

# Old backups out: older than KEEP_DAYS, and all but the newest KEEP_COUNT
find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -mtime +"$KEEP_DAYS" -exec rm -rf {} +
backups | head -n -"$KEEP_COUNT" | while read -r old; do rm -rf "$old"; done
