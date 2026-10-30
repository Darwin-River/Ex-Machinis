#!/usr/bin/env bash
#
# Backs up the game: the live database, the test world's database and
# deploy/.env (the app key and passwords), into one dated folder. Run it from
# anywhere, as a user who can run docker:
#
#   deploy/backup.sh                     # into /var/backups/exmachinis
#   BACKUP_DIR=/srv/backups deploy/backup.sh
#
# Optional settings (environment variables):
#   BACKUP_DIR      where backups go (default /var/backups/exmachinis)
#   KEEP_DAYS       backups older than this are deleted (default 14)
#   RCLONE_REMOTE   also copy each backup off the server with rclone, e.g.
#                   "storagebox:exmachinis" (see documents/hosting.md)
#
# The dump is consistent while the game runs (--single-transaction): drones
# keep working during a backup. See documents/hosting.md for restoring.
set -euo pipefail

cd "$(dirname "$0")"

BACKUP_DIR="${BACKUP_DIR:-/var/backups/exmachinis}"
KEEP_DAYS="${KEEP_DAYS:-14}"
STAMP="$(date -u +%Y-%m-%d_%H%M)"
DEST="$BACKUP_DIR/$STAMP"

die() { echo "backup: $*" >&2; exit 1; }
[ -f .env ] || die "deploy/.env is missing: run setup.sh first"
docker compose ps --status running db | grep -q db || die "the database isn't running (docker compose up -d)"

umask 077
mkdir -p "$DEST"

dump() {   # dump <database> <file>
    docker compose exec -T db sh -c \
        'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --quick --routines --triggers --no-tablespaces "$1"' \
        sh "$1" | gzip > "$2"
    # A dump that ends without its last line was cut short
    gzip -dc "$2" | tail -n 1 | grep -q "Dump completed" || die "the dump of $1 is incomplete"
}

dump exmachinis "$DEST/exmachinis.sql.gz"
# The test world is optional: skip it if it doesn't exist
if docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "USE exmachinis_test"' >/dev/null 2>&1; then
    dump exmachinis_test "$DEST/exmachinis_test.sql.gz"
fi
cp .env "$DEST/env"
git -C .. rev-parse HEAD > "$DEST/commit"   # the code the data goes with

echo "backup: $(du -sh "$DEST" | cut -f1) in $DEST"

if [ -n "${RCLONE_REMOTE:-}" ]; then
    rclone copy "$DEST" "$RCLONE_REMOTE/$STAMP" || die "copying off the server failed (the local backup is fine)"
    rclone delete --min-age "${KEEP_DAYS}d" "$RCLONE_REMOTE" && rclone rmdirs --leave-root "$RCLONE_REMOTE" || true
    echo "backup: copied to $RCLONE_REMOTE/$STAMP"
fi

# Old backups out
find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -mtime +"$KEEP_DAYS" -exec rm -rf {} +
