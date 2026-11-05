#!/bin/sh
# load-world.sh: a throwaway world for load tests (#429), built from this
# checkout: a fresh database with the game data and House stations, the two
# engines and the House market runner. Never point it at the live database.
#
#   game-engine/tools/load-world.sh start <database> <dir>   build the world and start it
#   game-engine/tools/load-world.sh stop <dir>               stop its engines and market
#
# Then release a load and measure it, e.g.
#   php artisan forge:load-test 60 --starters=miner,miner,refiner,trader,maker \
#       --designs=chemist:12,smith:9,machinist:6,adaptive:6,systems:6,electronics:3,foundry:3 --database=<database>
#   php artisan forge:load-test --measure=300 --min-turns=1000 --database=<database>
#
# Settings from the environment: DB_HOST (127.0.0.1), DB_PORT (3306),
# DB_USER (root), DB_PASSWORD; the clock as live (GAME_TIME_SCALE 1800,
# GAME_TIME_FLOOR 60, CLOCK_LAG_HIGH 10, CLOCK_LAG_LOW 3); ENGINE_SHARDS game
# engines (1). Run from the
# repository root, with the engines built (make -C game-engine/src all).
set -e
REPO=$(cd "$(dirname "$0")/../.." && pwd)
: "${DB_HOST:=127.0.0.1}" "${DB_PORT:=3306}" "${DB_USER:=root}" "${DB_PASSWORD:=}"
export DB_HOST DB_PORT DB_USER DB_PASSWORD MYSQL_PWD="$DB_PASSWORD"
sql() { mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$@"; }

stop() {
    for f in "$1"/ge*.pid "$1/ee.pid" "$1/market.pid"; do
        [ -f "$f" ] && kill "$(cat "$f")" 2>/dev/null || true
        rm -f "$f"
    done
}

case "$1" in
start)
    DB=$2; DIR=$3
    [ -n "$DB" ] && [ -n "$DIR" ] || { echo "usage: $0 start <database> <dir>"; exit 1; }
    case "$DB" in exmachinis) echo "Not the live database"; exit 1;; esac
    mkdir -p "$DIR/bin" "$DIR/conf" "$DIR/log" "$DIR/tmp"; DIR=$(cd "$DIR" && pwd)
    stop "$DIR"
    # The database: schema, migrations, game data
    sql -e "DROP DATABASE IF EXISTS \`$DB\`"
    sed "s/exmachinis/$DB/g" "$REPO/game-engine/sql/create_db.sql" | sql
    sql "$DB" < "$REPO/game-engine/db-backup/exmachinis_tables.sql"
    DB_NAME=$DB "$REPO/game-engine/sql/migrate.sh" >/dev/null
    sql "$DB" < "$REPO/game-engine/sql/game_data.sql"
    # The engines, as the deployment runs them (conf/*.conf, overridden by
    # the environment)
    cp "$REPO"/game-engine/src/bin/game-engine "$REPO"/game-engine/src/bin/events-engine \
       "$REPO"/game-engine/src/bin/eforth.blk "$DIR/bin/"
    cp "$REPO"/game-engine/conf/*.conf "$DIR/conf/"
    rm -f "$DIR"/log/*
    cat > "$DIR/env.sh" <<ENV
export PLAT_HOME=$DIR DB_HOST=$DB_HOST DB_PORT=$DB_PORT DB_USER=$DB_USER DB_PASSWORD=$DB_PASSWORD DB_NAME=$DB
export FORTH_IMAGE_PATH=$DIR/bin/eforth.blk FORTH_VM_OUT_PATH=$DIR/tmp SEND_EMAIL_SCRIPT=/bin/true EMAIL_ENABLED=0
export GAME_TIME_SCALE=${GAME_TIME_SCALE:-1800} GAME_TIME_FLOOR=${GAME_TIME_FLOOR:-60}
export CLOCK_LAG_HIGH=${CLOCK_LAG_HIGH:-10} CLOCK_LAG_LOW=${CLOCK_LAG_LOW:-3} APP_TRACE_LEVEL=-1
ENV
    ( . "$DIR/env.sh"; cd "$DIR"
      # ENGINE_SHARDS game engines (#433), each with its share of the drones
      N=${ENGINE_SHARDS:-1}; k=0
      while [ $k -lt $N ]; do
          ENGINE_SHARDS=$N ENGINE_SHARD=$k nohup bin/game-engine > log/ge$k.out 2>&1 & echo $! > ge$k.pid
          k=$((k + 1))
      done
      nohup bin/events-engine > log/ee.out 2>&1 & echo $! > ee.pid )
    sleep 2
    # The House: its stations and the market runner (what the website's tick does)
    cd "$REPO"
    DB_HOST=$DB_HOST DB_PORT=$DB_PORT DB_USERNAME=$DB_USER php artisan forge:stations --database="$DB" | tail -1
    DB_HOST=$DB_HOST DB_PORT=$DB_PORT DB_USERNAME=$DB_USER nohup php artisan forge:market --database="$DB" --watch=5 \
        > "$DIR/log/market.out" 2>&1 & echo $! > "$DIR/market.pid"
    echo "World $DB running from $DIR"
    ;;
stop)
    [ -n "$2" ] || { echo "usage: $0 stop <dir>"; exit 1; }
    stop "$2"; echo "Stopped"
    ;;
*)
    sed -n '2,20p' "$0"; exit 1
    ;;
esac
