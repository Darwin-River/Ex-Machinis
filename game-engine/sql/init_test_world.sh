#!/bin/sh
# Prepares the test world's database (#324 step 7): a second database on the
# same server, with the same schema, migrations and game data as the live
# one, where the Release page's Test button runs scripts. Run as root (it
# creates the database and grants the game's user access), after the live
# database's migrations.
#
# Environment: DB_HOST, DB_PORT, MYSQL_PWD (root's), TEST_DB_NAME (the test
# database, e.g. exmachinis_test) and, optionally, DB_APP_USER (the game's
# MySQL user, which gets access to it).
set -eu

SQL="$(cd "$(dirname "$0")" && pwd)"
: "${TEST_DB_NAME:?set TEST_DB_NAME}"

root() {
    mysql -h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}" -uroot "$@"
}

# A new test database starts from the same base schema as the live one
# (create_db.sql names the live database, so point it at the test one)
if [ -z "$(root -N -e "SHOW DATABASES LIKE '$TEST_DB_NAME'")" ]; then
    echo "test world: creating $TEST_DB_NAME"
    sed -e "s/\`exmachinis\`/\`$TEST_DB_NAME\`/g" "$SQL/create_db.sql" | root
    root "$TEST_DB_NAME" < "$SQL/../db-backup/exmachinis_tables.sql"
fi

DB_USER=root DB_NAME="$TEST_DB_NAME" "$SQL/migrate.sh" | sed 's/^migrate:/test world:/'
root "$TEST_DB_NAME" < "$SQL/game_data.sql"
if [ -n "${DB_APP_USER:-}" ]; then
    root -e "GRANT ALL PRIVILEGES ON \`$TEST_DB_NAME\`.* TO '$DB_APP_USER'@'%'"
fi
echo "test world: $TEST_DB_NAME ready"
