#!/bin/sh
# Applies the database migrations in game-engine/sql/migrations/ that the
# database hasn't had yet, in order, and records each in schema_version.
#
# Connection settings come from the environment:
#   DB_HOST (default 127.0.0.1), DB_PORT (3306), DB_USER, DB_NAME,
#   MYSQL_PWD (the password; read by the mysql client itself)
#
# Migration files are named NNNN_what_it_does.sql. Never edit or renumber a
# migration once it is merged: add a new one instead.
set -eu

DIR="$(cd "$(dirname "$0")" && pwd)/migrations"
: "${DB_USER:?set DB_USER}" "${DB_NAME:?set DB_NAME}"

# A migration that can't get its table locks fails after LOCK_WAIT seconds
# with a message, instead of waiting (MySQL's default is a year: #736)
LOCK_WAIT="${LOCK_WAIT:-300}"
db() {
    mysql -h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}" -u"$DB_USER" \
        --init-command="SET SESSION lock_wait_timeout = $LOCK_WAIT" "$DB_NAME" "$@"
}

# Queries left running from before a deploy hold locks a migration needs:
# the app and engines are stopped while migrate runs, so any query in this
# database that has run over a minute is one of those, and is ended (#736:
# a search started before the deploy kept migration 0072 waiting for good)
stale_queries() {
    db -N -e "SELECT id FROM information_schema.processlist
              WHERE db = DATABASE() AND command = 'Query' AND time > 60 AND id <> CONNECTION_ID()"
}

# Two files with the same number would leave the order unclear
dupes=$(ls "$DIR" | grep -E '^[0-9]+_.*\.sql$' | sed 's/_.*//' | sort | uniq -d)
if [ -n "$dupes" ]; then
    echo "migrate: more than one migration numbered $dupes" >&2
    exit 1
fi

db -e "CREATE TABLE IF NOT EXISTS schema_version (
         version INT UNSIGNED NOT NULL PRIMARY KEY,
         name VARCHAR(255) NOT NULL,
         applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)"

applied=$(db -N -e "SELECT version FROM schema_version")
count=0

for file in $(ls "$DIR" | grep -E '^[0-9]+_.*\.sql$' | sort); do
    name=${file%.sql}
    version=$(expr "${name%%_*}" + 0)
    if printf '%s\n' "$applied" | grep -qx "$version"; then
        continue
    fi
    for id in $(stale_queries); do
        echo "migrate: ending query $id, left running from before (it would hold the locks this migration needs)"
        db -e "KILL QUERY $id" || true
    done
    echo "migrate: applying $name"
    db < "$DIR/$file"
    db -e "INSERT INTO schema_version (version, name) VALUES ($version, '$name')"
    count=$((count + 1))
done

echo "migrate: $count migration(s) applied, schema at version $(db -N -e 'SELECT IFNULL(MAX(version), 0) FROM schema_version')"
