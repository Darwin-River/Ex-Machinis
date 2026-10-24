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

db() {
    mysql -h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}" -u"$DB_USER" "$DB_NAME" "$@"
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
    echo "migrate: applying $name"
    db < "$DIR/$file"
    db -e "INSERT INTO schema_version (version, name) VALUES ($version, '$name')"
    count=$((count + 1))
done

echo "migrate: $count migration(s) applied, schema at version $(db -N -e 'SELECT IFNULL(MAX(version), 0) FROM schema_version')"
