#!/bin/bash
# release-order-check.sh: checks that a released drone loads its script
# before it runs launched(), however the release's transaction interleaves
# with the game engine's (the bug of #694 stage 4 step 4 with WAKE_HEAP=1:
# the release's <launched> ran before its script, and launched() never ran).
#
#   game-engine/tools/release-order-check.sh <database> [releases]
#
# Run against a world started by load-world.sh (never the live database).
# It releases a drone that thinks for ever, so the game engine inserts a
# command each turn, then releases (default 40) drones the way the website
# does (Release::release: the script, then <launched>, in one transaction)
# but holding each transaction open for a moment, so the engine's commands
# take ids between the script's and <launched>'s. Each drone's launched()
# sets its status; the check fails if any drone hasn't within 90 seconds.
# Settings from the environment, as load-world.sh: DB_HOST (127.0.0.1),
# DB_PORT (3306), DB_USER (root), DB_PASSWORD.
set -e
DB=$1
N=${2:-40}
[ -n "$DB" ] || { echo "usage: $0 <database> [releases]"; exit 1; }
case "$DB" in exmachinis) echo "Not the live database"; exit 1;; esac
: "${DB_HOST:=127.0.0.1}" "${DB_PORT:=3306}" "${DB_USER:=root}" "${DB_PASSWORD:=}"
export MYSQL_PWD="$DB_PASSWORD"
sql() { mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB" "$@"; }

OWNER=$(sql -e "SELECT IFNULL(MIN(user_id), 0) FROM users")
if [ "$OWNER" = 0 ]; then
    sql -e "INSERT INTO users (name, email, password) VALUES ('order-check', 'order-check@example.test', '-')"
    OWNER=$(sql -e "SELECT MIN(user_id) FROM users")
fi

# release <company> <Lua script> <seconds to hold the transaction open>
# (as Release::release: a company, its drone on Earth, the script, then
# <launched>; the engine stamps them)
release() {
    local script
    script=$(printf '%s' "$2" | sed "s/'/''/g")
    sql <<SQL
START TRANSACTION;
INSERT INTO companies (user_id, name, credits) VALUES ($OWNER, '$1', 100000);
SET @company = LAST_INSERT_ID();
INSERT INTO agents (user_id, company_id, name, object_id, hull_type, language)
    VALUES ($OWNER, @company, '$1', 3, 1, 'lua');
SET @drone = LAST_INSERT_ID();
INSERT INTO commands (code, agent_id, created_at, updated_at, subject, email_content)
    VALUES ('$script', @drone, NOW(), NOW(), 'Release', '');
DO SLEEP($3);
INSERT INTO commands (code, agent_id, created_at, updated_at, subject, email_content)
    VALUES ('<launched>', @drone, NOW(), NOW(), 'Release', '');
COMMIT;
SQL
}

release "Order Thinker" $'function launched()\n  local n = 0\n  while true do n = n + 1 end\nend' 0
sleep 5
for k in $(seq 1 "$N"); do
    release "Order Check $k" $'function launched()\n  set_status("order-ok")\nend' "0.$((k % 4 * 3 + 2))" &
    [ $((k % 4)) = 0 ] && wait
done
wait

missing() {
    sql -e "SELECT IFNULL(GROUP_CONCAT(a.agent_id), '') FROM agents a JOIN companies c ON c.company_id = a.company_id
            WHERE c.name LIKE 'Order Check %' AND IFNULL(a.script_status, '') <> 'order-ok'"
}
for _ in $(seq 1 90); do
    [ -z "$(missing)" ] && break
    sleep 1
done
LEFT=$(missing)
# The thinker stops: its commands go (the engine drops them as stale). The
# engine writes them too, so a deadlock is tried again
for _ in 1 2 3; do
    sql -e "DELETE c FROM commands c JOIN agents a ON a.agent_id = c.agent_id
            JOIN companies o ON o.company_id = a.company_id WHERE o.name = 'Order Thinker'" && break
    sleep 1
done || true
if [ -n "$LEFT" ]; then
    echo "FAILED: these released drones never ran launched(): $LEFT"
    exit 1
fi
echo "ok: all $N released drones loaded their script before launched() ran"
