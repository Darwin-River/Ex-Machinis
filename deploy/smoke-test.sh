#!/usr/bin/env bash
#
# End-to-end check of the Docker deployment (used by CI). Starts the stack
# for the fake domain example.test over plain HTTP, then checks that:
#   - the website answers, and Getting Started shows the players' manual,
#   - the scheduler's ticks succeed, and there are no accounts or email,
#   - a script released on the website runs, and so do commands queued for
#     its drone; their replies are kept for the website (#367 step 5),
#   - it unpacks its starter kit, flies to the Moon, mines and refines kunspar,
#   - companies of one player may trade with each other,
#   - a released script in several pieces runs, the Release page offers
#     starter scripts, and drones know the game words,
#   - actions and computing burn fuel; a drone that runs dry becomes a wreck,
#   - money past 32,767 comes back as a double, and the double words work,
#   - House station drones buy goods and sell fuel at once (31 and 32),
#   - a mined-out deposit yields nothing until it refills over game time,
#   - another player's wreck can be plundered (not one's own), and a seller
#     pays the sales tax out of a sale,
#   - a waiting drone runs no instructions but still sells, and a drone sees
#     live orders only where it is,
#   - a drone broadcasts a message and another drone hears it,
#   - a drone's page shows its code, and a wreck's page has a post-mortem,
#   - the home feed, the market, the map and the leaderboard show the world,
#   - the Test button runs a script in the test world, not the live one,
#   - it launches a new drone (a clone) from a ship kit with a copy of its
#     program, some of its fuel and a record of its parent,
# Needs ports 80 and 443 free. Leaves the stack running; remove it with
#   docker compose down -v && rm -rf .env
set -euo pipefail
cd "$(dirname "$0")"

DOMAIN=example.test

[ -f .env ] && { echo "deploy/.env already exists; run this on a clean checkout." >&2; exit 1; }
# A fast game clock (1 real second = 1 game hour) so the trip to the Moon
# and the light-speed delays take seconds
# Full engine logs (ENGINE_TRACE_LEVEL=2): the checks below read them
SITE_ADDRESS=http://localhost GAME_TIME_SCALE=3600 ENGINE_TRACE_LEVEL=2 ./setup.sh "$DOMAIN" "admin@$DOMAIN"

fail() {
    echo "FAILED: $*" >&2
    docker compose ps -a >&2
    docker compose logs --tail 60 >&2
    exit 1
}
# wait_for <seconds> <description> <command...>
wait_for() {
    local secs=$1 desc=$2; shift 2
    for _ in $(seq 1 "$secs"); do
        if "$@" >/dev/null 2>&1; then echo "ok: $desc"; return 0; fi
        sleep 1
    done
    fail "$desc"
}
# wait_quietly <seconds> <command...>: like wait_for, but leaves the
# failing to the caller
wait_quietly() {
    local secs=$1; shift
    for _ in $(seq 1 "$secs"); do "$@" >/dev/null 2>&1 && return 0; sleep 1; done
    return 1
}
sql() {
    docker compose exec -T db sh -c 'mysql -N -uexmachinis -p"$MYSQL_PASSWORD" exmachinis 2>/dev/null' <<<"$1"
}
# sqlw <statement>: a write the checks rely on. The engines write the same
# tables, so a write can meet a lock or a deadlock: try it again, then fail
# out loud (a failing sql under set -e ended the test with no message)
sqlw() {
    for _ in 1 2 3; do sql "$1" && return 0; sleep 1; done
    fail "this write failed three times: $1"
}
# run <drone id> <subject> <FORTH code>: queue a command for a drone, as the
# website and the engine do (there is no email, #367 step 5)
run() {
    local code=${3//\'/\'\'}
    sql "insert into commands (code, agent_id, created_at, updated_at, subject, email_content) values ('$code', $1, NOW(), NOW(), '$2', '')"
}
JAR=$(mktemp)
token() { curl -fsS -c "$JAR" -b "$JAR" "http://localhost$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed -e 's/.*value="//' -e 's/"$//'; }
# release <company name> <script> [kit]: release a script on the website,
# with no account (#367 step 4: there are none), with the mining kit unless
# the third argument says factory (#380)
release() {
    curl -fsS -c "$JAR" -b "$JAR" -o /dev/null -X POST http://localhost/release --data-urlencode "_token=$(token /release)" \
        --data-urlencode "company_name=$1" --data-urlencode "script=$2" --data-urlencode "kit=${3:-mining}"
}
company_drone() { sql "select a.agent_id from agents a join companies c on c.company_id = a.company_id where c.name='$1'"; }

wait_for 120 "website answers" curl -fsS -o /dev/null http://localhost/
# No sales tax (#380) but where a check asks for it: the checks below count
# credits exactly
no_tax() { sql "update house_rules set sales_tax_permille = 0"; }
no_tax
# The image must carry the players' manual (Getting Started renders it)
wait_for 60 "Getting Started shows the players' manual" sh -c "curl -fsS http://localhost/getting-started | grep -q 'chapter-4-issuing-commands'"
wait_for 120 "the scheduler's tick succeeds" sh -c "docker compose logs app | grep -q 'GET /tick/[^ ]* HTTP/1.1\" 200'"
wait_for 60 "the Release page answers" sh -c "curl -fsS http://localhost/release | grep -q 'no account needed'"
for page in register login web-register; do
    [ "$(curl -s -o /dev/null -w '%{http_code}' "http://localhost/$page")" = 404 ] || fail "/$page still answers"
done
[ -z "$(docker compose ps -a -q mail 2>/dev/null)" ] || fail "there is still a mail server"
echo "ok: no accounts, no sign-up page, no email"

# The first drone: released on the website with an empty launched word,
# then driven by commands queued for it
release "Smoke Miner" ": launched ;"
DRONE_ID=$(company_drone "Smoke Miner")
[ -n "$DRONE_ID" ] || fail "release did not create a company and drone"
DRONE=$(sql "select name from agents where agent_id=$DRONE_ID")
# perform holds a script until its whole action is done; until then the
# drone refuses new scripts, so wait for it to be idle before sending one
idle() { [ "$(sql "select count(*) from commands where agent_id=$DRONE_ID") $(sql "select IFNULL(waiting_action, 0) from agents where agent_id=$DRONE_ID")" = "0 0" ]; }
wait_for 90 "drone $DRONE loaded its released script" idle
run $DRONE_ID "smoke test" "2 3 + . cr"
wait_for 90 "drone $DRONE ran the command" sh -c "docker compose logs game-engine | grep -q 'Command succesfully evaluated'"
replied() { [ -n "$(sql "select id from drone_messages where agent_id=$DRONE_ID and subject='smoke test' and body like '%5%'")" ]; }
wait_for 30 "the reply is kept for the website" replied

# The starting sequence from the game data: unpack the starter kit, fly to
# the Moon (10), mine kunspar (1100) with the kit's mining module, and check a
# multi-line program at the same time.
has() { [ "$(sql "select IFNULL(MAX(units), 0) from sqInventory where drone=$DRONE_ID and resource=$1")" -ge "${2:-1}" ]; }
wait_for 30 "drone got the starter kit" has 5901
run $DRONE_ID "unpack" "25901 perform . cr"
wait_for 90 "starter kit unpacked (engine in cargo)" has 5001
wait_for 60 "drone finished the unpack script" idle

run $DRONE_ID "to the moon" "  : sq dup * ;
  7 sq . cr
  10 5001 perform . cr
  6 sq . cr"
wait_for 90 "drone ran a multi-line program" sh -c "docker compose logs game-engine | grep -q 'result \[ 49'"
at_moon() { [ "$(sql "select object_id from agents where agent_id=$DRONE_ID")" = 10 ]; }
wait_for 120 "drone flew to the Moon" at_moon
wait_for 60 "drone finished the trip script" idle
# perform paused the script for the trip: the line after it must still run
wait_for 30 "script carried on after the trip" sh -c "docker compose logs game-engine | grep -qE '[|] +36[[:space:]]*\$'"

run $DRONE_ID "mine" "11100 perform . cr"
wait_for 120 "drone mined kunspar on the Moon" has 1100
wait_for 60 "drone finished the mining script" idle

# Companies (#324 step 4): the player gets a second company with a drone at
# the Moon and a buy order for kunspar. Selling to it goes through: the
# same player's companies may trade with each other (#367 step 1).
PLAYER_ID=$(sql "select user_id from agents where agent_id=$DRONE_ID")
sql "insert into companies (user_id, name, credits) values ($PLAYER_ID, 'Sister Company', 1000)"
SISTER_CO=$(sql "select max(company_id) from companies where user_id=$PLAYER_ID")
sql "insert into agents (user_id, company_id, name, object_id, hull_type) values ($PLAYER_ID, $SISTER_CO, 'sister1', 10, 1)"
SISTER=$(sql "select agent_id from agents where name='sister1'")
sql "insert into events (event_type, action, logged, outcome, drone, resource, installed, locked, new_quantity, new_credits, game_t)
     select 5, 0, 1, 1, $SISTER, 1100, 0, 0, 50, 1, IFNULL(MAX(game_t), 0) + 1 from events where drone = $SISTER"
run $DRONE_ID "sell to my other company" "1 1100 $SISTER 10 perform . cr"
sister_bought() { [ "$(sql "select IFNULL(MAX(units), 0) from sqInventory where drone=$SISTER and resource=1100")" = 1 ]; }
wait_for 90 "a sale to the player's other company goes through" sister_bought
wait_for 60 "drone finished the sale script" idle

# Refining needs 20 kunspar (each mining run gives 5 here) and the Mining
# Kit's Refinery (A1); each perform waits for its action
run $DRONE_ID "refine" "  11100 perform drop 11100 perform drop 11100 perform drop 11100 perform drop
  21100 perform . cr"
wait_for 180 "drone refined kunspar into metals" has 2011
wait_for 60 "drone finished the refining script" idle

# Launch (#238): give the drone a Mining Cruiser kit (building one takes
# days), then launch a drone with a copy of its own program. The copy is
# taken while this script is paused in perform: the new drone starts at the
# prompt and runs its launched word.
sqlw "insert into events (event_type, action, logged, outcome, drone, resource, installed, locked, new_quantity, new_cargo, game_t)
     select 1, 0, 1, 1, $DRONE_ID, 6001, 0, 0, 1, 224, IFNULL(MAX(game_t), 0) + 1 from events where drone = $DRONE_ID"
wait_for 10 "drone got a ship kit" has 6001
run $DRONE_ID "launch" "  : launched .\" launched-word-ran\" cr ;
  $DRONE_ID 6001 12 perform . cr"
launched() { [ -n "$(sql "select agent_id from agents where user_id=(select user_id from agents where agent_id=$DRONE_ID) and hull_type=6001 and vm is not null")" ]; }
wait_for 120 "drone launched a Mining Cruiser" launched
NEW=$(sql "select name from agents where hull_type=6001 order by agent_id desc limit 1")
wait_for 60 "launched drone $NEW ran its launched word" sh -c "docker compose logs game-engine | grep -q 'result \[launched-word-ran'"
NEW_ID=$(sql "select agent_id from agents where name='$NEW'")
new_replied() { [ -n "$(sql "select id from drone_messages where agent_id=$NEW_ID and body like '%launched-word-ran%'")" ]; }
wait_for 60 "launched drone's reply is kept for the website" new_replied
wait_for 60 "drone finished the launch script" idle
[ "$(sql "select IFNULL(MAX(units), 0) from sqInventory where drone=$DRONE_ID and resource=6001")" = 0 ] || fail "the kit was not used up"
echo "ok: the kit was used up"

# Entry point and crashes (#324 step 5): the engine runs launched inside
# catch and saved a clean copy of the program at launch. A launched word that
# crashes restarts from the clean copy after a wait; 5 crashes in a row make a
# wreck.
# Cloning (#324 step 13): the launcher is the clone's parent, one generation
# on, and the clone got its program and 20 of its fuel
[ "$(sql "select concat(parent_id, ' ', generation, ' ', code_from) from agents where agent_id=$NEW_ID")" = "$DRONE_ID 1 $DRONE_ID" ] || fail "the clone's lineage was not recorded"
echo "ok: the clone's parent, generation and program source are recorded"
clone_fuel() { [ "$(sql "select IFNULL(MAX(units), 0) from sqInventory where drone=$NEW_ID and resource=2061")" -ge 1 ]; }
wait_for 30 "the clone got fuel from its launcher" clone_fuel
# Give it more fuel for the rounds below,
# as its owner would by pushing some over (#324 step 8)
sqlw "insert into events (event_type, action, logged, outcome, drone, resource, installed, locked, new_quantity, new_cargo, game_t)
     select 1, 0, 1, 1, $NEW_ID, 2061, 0, 0, 200, 200, IFNULL(MAX(game_t), 0) + 1 from events where drone = $NEW_ID"
wait_for 60 "launched drone's launched word returned normally" sh -c "docker compose logs game-engine | grep -q 'Drone \[$NEW_ID\] finished its launched word'"
[ "$(sql "select release_vm is not null from agents where agent_id=$NEW_ID")" = 1 ] || fail "no clean copy saved at launch"
echo "ok: clean copy saved at launch"
idle_new() { [ "$(sql "select count(*) from commands where agent_id=$NEW_ID")" = 0 ]; }
crash_launched() { # redefine launched to divide by zero, then restart it
    # The restart is queued only once this round's definition has run: the
    # engine has taken the round's command and the drone has nothing left to do
    # (compiling may take more than one instruction slice, so its output can
    # come in pieces: don't match on it)
    run $NEW_ID "crash $1" ": launched 1 0 / ;"
    wait_for 60 "launched drone $NEW got the crashing word ($1)" sh -c "docker compose logs game-engine | grep -q 'SUBJECT=\[crash $1\]'"
    wait_for 60 "launched drone $NEW took the crashing word ($1)" idle_new
    run $NEW_ID "Restart" "<launched>"
}
crash_launched 1
crashed() { [ "$(sql "select count(*) from drone_crashes where drone=$NEW_ID and code=-10 and restart_at is not null")" = 1 ]; }
wait_for 60 "a crash in launched is caught (-10) and a restart is scheduled" crashed
# The restart comes a real second later on this clock, and the engine runs
# it as soon as it is due (#324 step 20): either it is still queued, with
# the clean copy in place, or it has already run the clean launched word
# (one query, so the restart can't run between looking at the queue and at
# the program)
RESTART_STATE=$(sql "select concat((select count(*) from commands where agent_id=$NEW_ID and code='<launched>'
                     and deliver_at > (select min(restart_at) - 1 from drone_crashes where drone=$NEW_ID)),
                     ' ', vm = release_vm) from agents where agent_id=$NEW_ID")
restarted_clean() { [ "$(docker compose logs game-engine | grep -c "Drone \[$NEW_ID\] finished its launched word")" -ge 2 ]; }
if [ "${RESTART_STATE% *}" = 1 ]; then
    [ "${RESTART_STATE#* }" = 1 ] || fail "the program was not put back to its clean copy"
    echo "ok: the program went back to its clean copy and a restart is queued"
else
    wait_for 30 "the restart ran the clean copy's launched word" restarted_clean
fi
# The restart queued above isn't needed (this world's clock is fast): drop it,
# then make the next crash the 5th in a row
sql "delete from commands where agent_id=$NEW_ID and code='<launched>'"
sql "update agents set crashes_in_row = 4 where agent_id=$NEW_ID"
crash_launched 2
wrecked() { [ "$(sql "select wrecked_at is not null from agents where agent_id=$NEW_ID")" = 1 ]; }
wait_for 60 "the 5th crash in a row makes the drone a wreck" wrecked

# Fuel (#324 step 8): the first drone's actions and computing burned fuel
# from its starting 100, and it keeps going
[ "$(sql "select IFNULL(MAX(units), 0) < 100 and IFNULL(MAX(units), 0) > 0 from sqInventory where drone=$DRONE_ID and resource=2061")" = 1 ] || fail "the drone's fuel was not burned"
[ -n "$(sql "select id from actions where drone=$DRONE_ID and protocol=2 and aborted=0")" ] || fail "no power was burned for computing"
[ "$(sql "select wrecked_at is null from agents where agent_id=$DRONE_ID")" = 1 ] || fail "the drone with fuel became a wreck"
echo "ok: actions and computing burn fuel, and a fuelled drone keeps going"

# Double cells (#324 step 9): a balance past 32,767 comes back as a double
# (query 101, read with 2@), and m* multiplies without overflowing
COMPANY=$(sql "select company_id from agents where agent_id=$DRONE_ID")
sql "update companies set credits = 70000 where company_id = $COMPANY"
run $DRONE_ID "doubles" "create bank 8 allot
  $COMPANY bank 8 101 query drop bank 2@ d. 300 300 m* d."
# (match the whole reply, ignoring all whitespace, CR included: if the script
# pauses, its output comes in pieces)
wait_for 60 "a balance of 70,000 and 300 x 300 come out right as doubles" sh -c "docker compose logs --no-log-prefix game-engine | tr -d '[:space:]' | grep -q 'msg\[7000090000'"
wait_for 60 "drone finished the doubles script" idle

# House stations (#324 step 10; #380): the drone, on the Moon, sells a unit of
# the metal it refined to the Moon Depot's station drone like to any drone: a
# push into its buy order, at once, and the seller pays the sales tax out of
# the sale (#380), here 50%
sql "update house_rules set sales_tax_permille = 500"
MOON_STATION=$(sql "select agent_id from agents where station_id is not null and object_id = 10")
[ -n "$MOON_STATION" ] || fail "the Moon has no House station drone"
bid() { [ "$(sql "select count(*) from events where drone=$MOON_STATION and event_type=5 and resource=2011 and outcome=1")" -ge 1 ]; }
wait_for 180 "the Moon Depot's station drone posts its buy order for metals" bid
run $DRONE_ID "station" "1 2011 $MOON_STATION 10 perform . cr"
sold() { [ "$(sql "select count(*) from trades where seller=$DRONE_ID and buyer=$MOON_STATION and resource=2011 and units=1 and credits>0")" = 1 ]; }
wait_for 90 "the Moon Depot's station drone bought the drone's metal" sold
[ "$(sql "select tax = FLOOR((credits * 500 + 500) / 1000) and tax > 0 from trades where seller=$DRONE_ID and buyer=$MOON_STATION")" = 1 ] ||
    fail "the seller didn't pay the sales tax on its station sale"
[ "$(sql "select count(*) from company_taxes t join trades d on d.tax = t.amount join agents a on a.agent_id = d.seller
          where d.seller=$DRONE_ID and d.buyer=$MOON_STATION and t.company_id = a.company_id and t.eliminated = 0")" -ge 1 ] ||
    fail "the sales tax wasn't recorded"
echo "ok: a drone sold to the House station drone at once, and paid the sales tax out of the sale"
no_tax
wait_for 60 "drone finished the station script" idle

# Mining deposits (#324 step 11): mining takes from the place's deposit. Empty
# the Moon's kunspar deposit and stop it refilling: mining there yields
# nothing. Let it refill again, and a few game hours later mining yields again.
kunspar() { sql "select IFNULL(MAX(units), 0) from sqInventory where drone=$DRONE_ID and resource=1100"; }
sql "update deposits set refill_per_day = 0 where object_id = 10 and resource = 1100"
sql "insert into deposit_state (object_id, resource, amount, refilled_t) values (10, 1100, 0, 0) on duplicate key update amount = 0"
KUNSPAR=$(kunspar)
run $DRONE_ID "mine dry" "11100 perform . cr"
wait_for 90 "drone mined the empty deposit" sh -c "docker compose logs game-engine | grep -q 'Deposit of resource \[1100\] at \[10\]: \[0\] of'"
wait_for 60 "drone finished mining the empty deposit" idle
[ "$(kunspar)" = "$KUNSPAR" ] || fail "a mined-out deposit still yielded kunspar"
echo "ok: a mined-out deposit yields nothing"
sql "update deposits set refill_per_day = 40 where object_id = 10 and resource = 1100"
sleep 5
run $DRONE_ID "mine again" "11100 perform . cr"
more_kunspar() { [ "$(kunspar)" -gt "$KUNSPAR" ]; }
wait_for 90 "the deposit refilled over game time and yields again" more_kunspar
wait_for 60 "drone finished mining again" idle

[ -z "$(sql "select id from actions where drone=$DRONE_ID and aborted=1")" ] || fail "an action was aborted"
echo "ok: no action aborted"

for svc in game-engine events-engine app; do
    restarts=$(docker inspect -f '{{.RestartCount}}' "$(docker compose ps -q $svc)")
    [ "$restarts" = 0 ] || fail "$svc restarted $restarts times"
done
echo "ok: no service restarted"

# Public Forge (#324 step 6): release a script on the website. It is
# longer than one 2,048-character command, so it is released in pieces that
# must all load, one after the other, before launched runs (#367)
PADDING=$(for i in $(seq 1 40); do echo "\\ padding line $i: a released script may be as long as it likes"; done)
release "Smoke Forge" ": hello  .\" forge-release-\" ;
$PADDING
: launched  hello .\" ok7\" ;" factory
FORGE_DRONE=$(company_drone "Smoke Forge")
[ -n "$FORGE_DRONE" ] || fail "release did not create a company and drone"
# The kit the player chose (#380): this one asked for the Factory Kit
[ "$(sql "select count(*) from sqInventory where drone=$FORGE_DRONE and resource=5902 and units=1")" = 1 ] ||
    fail "the factory release didn't get a Factory Kit"
echo "ok: a release gets the kit its player chose"
echo "ok: release created a company with a drone"
released() { [ -n "$(sql "select id from drone_messages where agent_id=$FORGE_DRONE and body like '%forge-release-ok7%' and body like '%has finished%'")" ]; }
wait_for 90 "released drone ran launched; its reply is kept for the website" released
FORGE_NAME=$(sql "select name from agents where agent_id=$FORGE_DRONE")
# Public, on the drone's page (#367 step 3)
wait_for 30 "the drone's page shows the reply" sh -c "curl -fsS http://localhost/spacecraft/$FORGE_DRONE | grep -q 'forge-release-ok7'"
# Starter scripts and game words (#324 step 15): the Release page fills in
# a starter, and a drone knows the game words
# (fetch a page, then search it: with pipefail, "curl | grep -q" can fail
# when grep stops reading before curl has written the whole page)
fetch() { curl -fsS "$@" || echo "fetch failed: $*"; }
grep -qF "kunspar 20 dig" <<<"$(fetch -b "$JAR" "http://localhost/release?starter=miner")" || fail "the Release page did not offer the miner starter"
echo "ok: the Release page offers the starter scripts"
run $FORGE_DRONE "game words" "stdlib . moon . kunspar . fuel units 0> . 3 trip 0> ."
knows_words() { [ -n "$(sql "select id from drone_messages where agent_id=$FORGE_DRONE and subject='game words' and replace(replace(replace(body, ' ', ''), '\\n', ''), '\\r', '') like '%15101100-1-1'")" ]; }
wait_for 60 "a drone knows the game words" knows_words

# Broadcasts (#380): the released drone, at Earth, broadcasts a message; the
# first drone hears it once light has carried it there (query 690)
run $FORGE_DRONE "broadcast" ": hi \$\" smoke-hello\" ; hi broadcast ."
said() { [ "$(sql "select count(*) from broadcasts where drone=$FORGE_DRONE and body='smoke-hello'")" = 1 ]; }
wait_for 60 "a drone broadcast a message" said
run $DRONE_ID "hear" "1 heard . heard-from . heard-text count type"
heard() { [ -n "$(sql "select id from drone_messages where agent_id=$DRONE_ID and subject='hear' and body like '%$FORGE_DRONE%smoke-hello%'")" ]; }
wait_for 60 "another drone heard the broadcast" heard
echo "ok: drones broadcast messages that others hear"

# The House fuel seller (#324 step 10; #380): the released drone, at Earth,
# buys 10 fuel from the Earth Exchange's station drone like from any drone: a
# pull from its sell order, at once, at 5 a unit
EARTH_STATION=$(sql "select agent_id from agents where station_id is not null and object_id = 3")
[ -n "$EARTH_STATION" ] || fail "Earth has no House station drone"
fuel_ask() { [ "$(sql "select count(*) from events where drone=$EARTH_STATION and event_type=4 and resource=2061 and outcome=1")" -ge 1 ]; }
wait_for 180 "the Earth Exchange's station drone posts its fuel for sale" fuel_ask
run $FORGE_DRONE "fuel" "10 2061 $EARTH_STATION 11 perform ."
bought() { [ "$(sql "select count(*) from trades where seller=$EARTH_STATION and buyer=$FORGE_DRONE and resource=2061 and units=10 and credits=50")" = 1 ]; }
wait_for 90 "a drone bought 10 fuel from the House at 5 a unit" bought
# In its hold: the pull's increment event. (Its total can still fall: the
# drone's computing is billed in fuel too)
more_fuel() { [ "$(sql "select count(*) from events e join actions x on x.id = e.action
                       where x.protocol = 11 and e.drone = $FORGE_DRONE and e.resource = 2061
                       and e.event_type = 1 and e.outcome = 1")" -ge 1 ]; }
wait_for 60 "the fuel arrived in its hold" more_fuel

# A drone that never refuels dies (#324 step 8): release one that only
# computes, leave it 1 unit of fuel, and it runs dry and becomes a wreck
release "Dry Forge" ": launched  begin 0 until ;"
DRY=$(company_drone "Dry Forge")
[ -n "$DRY" ] || fail "release did not create the dry drone"
KIT=$(sql "select mass from resources where id=5901")
# (Stamped after the drone's latest event: the engines read a drone's stock
# from its latest event in game time, so an event without one is ignored,
# and the plunder below once failed on fuel the engine said wasn't there)
sqlw "insert into events (event_type, action, logged, outcome, drone, resource, installed, locked, new_quantity, new_cargo, game_t)
     select 2, 0, 1, 1, $DRY, 2061, 0, 0, 1, $KIT + 1, IFNULL(MAX(game_t), 0) + 1 from events where drone = $DRY"
dry() { [ "$(sql "select wreck_cause from agents where agent_id=$DRY")" = fuel ]; }
wait_for 120 "a drone that runs out of fuel becomes a wreck" dry
# A company ends when its last drone dies (#324 step 13): its credits vanish
[ "$(sql "select concat(end_cause, ' ', credits) from companies where name='Dry Forge'")" = "drones 0" ] || fail "the company whose last drone died did not end"
echo "ok: a company whose last drone died has ended"

# Plunder (#324 step 12): a drone where the wreck is may plunder it, whoever
# owns it (#367 step 1). Put the wreck on the Moon, where the first drone is,
# with 10 kunspar aboard; the plunderer gets half and the wreck is left empty
sql "update agents set object_id = 10 where agent_id = $DRY"
sqlw "insert into events (event_type, action, logged, outcome, drone, resource, installed, locked, new_quantity, new_cargo, game_t)
     select 1, 0, 1, 1, $DRY, 1100, 0, 0, 10, $KIT + 10, IFNULL(MAX(game_t), 0) + 1 from events where drone = $DRY"
KUNSPAR=$(kunspar)
run $DRONE_ID "plunder" "$DRY 34 perform ."
plundered() { [ "$(sql "select IFNULL(plundered_by, 0) from agents where agent_id=$DRY")" = "$DRONE_ID" ] &&
              [ "$(sql "select IFNULL(SUM(units), 0) from sqInventory where drone=$DRY")" = 0 ]; }
if ! wait_quietly 90 plundered; then
    # Say what state it got to (#380: a CI run once timed out here)
    echo "plunderer $DRONE_ID, wreck $DRY:" >&2
    sql "select agent_id, object_id, wrecked_at, plundered_at, plundered_by from agents where agent_id in ($DRONE_ID, $DRY)" >&2
    sql "select drone, resource, units from sqInventory where drone=$DRY and units <> 0" >&2
    sql "select id, action, event_type, resource, outcome, new_quantity, game_t from events where drone=$DRY order by id desc limit 15" >&2
    sql "select id, protocol, outcome, aborted from actions where drone in ($DRONE_ID, $DRY) order by id desc limit 10" >&2
    fail "another player's drone plundered the wreck, leaving it empty"
fi
echo "ok: another player's drone plundered the wreck, leaving it empty"
got_half() { [ "$(kunspar)" -ge "$((KUNSPAR + 5))" ]; }
wait_for 60 "the plunderer got half the wreck's kunspar" got_half
wait_for 60 "drone finished plundering" idle

# Waiting and local information (#324 step 14). The released drone, at
# Earth, posts a sell order for fuel, checks it sees it there, and waits
# 600 game minutes (10 seconds here). While it waits it runs no
# instructions, yet it still sells: a buyer pulls from it.
# (match the replies' end without spaces, newlines or carriage returns)
reply_ends() { [ "$(sql "select count(*) from drone_messages where agent_id=$1 and subject='$2' and replace(replace(replace(body, ' ', ''), '\\n', ''), '\\r', '') like '%$3'")" -ge 1 ]; }
# (at 1 a unit its order is the cheapest here, so the first row: the Earth
# Exchange's station drone sells fuel too, at 5; a row's drone is at +8)
run $FORGE_DRONE "sell and wait" "1 1 2061 7 perform . create o 40 allot 2061 o 40 610 query drop o 8 + @ . 600 3 perform ."
forge_waits() { [ "$(sql "select a.protocol from agents g join actions a on a.id = g.waiting_action where g.agent_id=$FORGE_DRONE")" = 3 ]; }
wait_for 60 "a drone with a sell order is waiting" forge_waits
BEFORE=$(sql "select instructions from agents where agent_id=$FORGE_DRONE")
# The first drone (another player's) comes to Earth and buys 2 fuel from it
sql "update agents set object_id = 3 where agent_id = $DRONE_ID"
run $DRONE_ID "buy from a waiting drone" "2 2061 $FORGE_DRONE 11 perform ."
sold_waiting() { [ "$(sql "select count(*) from trades where seller=$FORGE_DRONE and buyer=$DRONE_ID and resource=2061 and units=2")" = 1 ]; }
wait_for 30 "a waiting drone still sells (the buyer pulls from it)" sold_waiting
forge_waits || fail "the seller stopped waiting before its wait was up"
[ "$(sql "select instructions from agents where agent_id=$FORGE_DRONE")" = "$BEFORE" ] || fail "a waiting drone ran instructions"
echo "ok: a waiting drone runs no instructions"
wait_for 60 "drone finished buying" idle
# Back on the Moon, the first drone can't see Earth's live orders (query
# 611 used to list orders anywhere); the seller, at Earth, saw its own
sql "update agents set object_id = 10 where agent_id = $DRONE_ID"
# (the Moon Depot's station drone sells fuel there: the seller's order at 1
# a unit would be the cheapest, so the first row, if the drone saw it)
run $DRONE_ID "orders far" "create o 40 allot 2061 o 40 611 query 0> if .\" far:\" o 8 + @ . else .\" far:none\" then cr"
blind_far() { [ "$(sql "select count(*) from drone_messages where agent_id=$DRONE_ID and subject='orders far'
                       and replace(replace(replace(body, ' ', ''), '\\n', ''), '\\r', '') like '%far:%'
                       and replace(replace(replace(body, ' ', ''), '\\n', ''), '\\r', '') not like '%far:${FORGE_DRONE}'")" -ge 1 ]; }
wait_for 60 "a drone on the Moon can't see Earth's live orders" blind_far
wait_for 60 "drone finished asking" idle
sees_here() { reply_ends $FORGE_DRONE 'sell and wait' "0${FORGE_DRONE}0"; }
wait_for 60 "the seller saw its order at Earth and its wait ended" sees_here

# The Drone page (#324 step 16): a released drone shows its code and a Copy
# button; a wreck has a post-mortem in plain words
FORGE_PAGE=$(fetch "http://localhost/spacecraft/$FORGE_DRONE")
grep -qF "forge-release-" <<<"$FORGE_PAGE" || fail "the drone page doesn't show the released code"
grep -qF "release?copy=$FORGE_DRONE" <<<"$FORGE_PAGE" || fail "the drone page has no Copy this code link"
echo "ok: the drone page shows its code, with a Copy this code link"
grep -qF "It ran out of fuel" <<<"$(fetch "http://localhost/spacecraft/$DRY")" || fail "no post-mortem for the drone that ran out of fuel"
echo "ok: wrecks have post-mortems"

# Home, Market and Map (#324 step 17): the feed tells what happened, the
# Market shows the order book and trades (no House price list), the Map where the drones are
grep -qF "became a wreck" <<<"$(fetch http://localhost/)" || fail "the home page's feed doesn't report the wrecks"
MARKET_PAGE=$(fetch http://localhost/market)
grep -qF "Order book" <<<"$MARKET_PAGE" || fail "the market page doesn't show the order book"
! grep -qF "House stations" <<<"$MARKET_PAGE" || fail "the market page shows the House's price list (#380: players find prices in the order book)"
grep -qF "spacecraft/$FORGE_DRONE" <<<"$MARKET_PAGE" || fail "the market page doesn't show the trades"
grep -qF ">Moon<" <<<"$(fetch http://localhost/map)" || fail "the map doesn't list the Moon among the places with drones"
echo "ok: the home feed, market and map show the world"
# The leaderboard (#324 step 18, companies since #367 step 2) ranks the
# companies by wealth (ended companies are left off)
grep -qF "$(sql "select name from companies where company_id=$COMPANY")" <<<"$(fetch http://localhost/leaderboard)" || fail "the leaderboard doesn't list the first company"
[ "$(sql "select count(*) from wealth_snapshots where company_id is not null")" -ge 1 ] || fail "no wealth snapshot was taken"
echo "ok: the leaderboard ranks the companies, and wealth snapshots are taken"

# Test world (#324 step 7): start its engines and turn on the Test button,
# then test a script. It runs in the test world's own database, with a faster
# clock, and the live world gets nothing.
TEST_WORLD=1 docker compose --profile testworld up -d app test-game-engine test-events-engine >/dev/null 2>&1
wait_for 120 "the Release page has a Test button" sh -c "curl -fsS -b '$JAR' http://localhost/release | grep -q 'release/test'"
# Each test run has its own page (#367 step 3): the Test button leads there
TEST_PAGE=$(curl -fsS -c "$JAR" -b "$JAR" -o /dev/null -w '%{redirect_url}' -X POST http://localhost/release/test --data-urlencode "_token=$(token /release)" \
    --data-urlencode $'script=: hello  ." test-world-" ;\n: launched  hello ." ok8" ;')
sqltest() {
    docker compose exec -T db sh -c 'mysql -N -uexmachinis -p"$MYSQL_PASSWORD" exmachinis_test 2>/dev/null' <<<"$1"
}
tested() { [ -n "$(sqltest "select id from drone_messages where body like '%test-world-ok8%' and body like '%has finished%'")" ]; }
wait_for 90 "a test run runs in the test world" tested
[ -z "$(sql "select company_id from companies where name like 'Test %'")" ] || fail "a test run reached the live world"
echo "ok: the live world is untouched"
grep -q "/release/test/[0-9a-f]\{16\}$" <<<"$TEST_PAGE" || fail "the Test button didn't lead to the test run's page ($TEST_PAGE)"
wait_for 30 "the test run's page shows it" sh -c "curl -fsS '$TEST_PAGE' | grep -q 'test-world-ok8'"

# House station drones (#380): the app made the House's trading drone at
# each exchange on start-up; forge:stations makes none twice, and each posts
# orders by the players' rules (a sell order is event type 4, a buy order 5)
[ "$(sql "select count(*) from agents a join companies c on c.company_id = a.company_id where c.house = 1 and a.station_id is not null")" = "$(sql "select count(*) from stations")" ] ||
    fail "the House station drones weren't made"
docker compose exec -T app php artisan forge:stations | grep -q "^0 station drone" || fail "forge:stations made a station drone twice"
echo "ok: each exchange has one House station drone"
posted() { [ "$(sql "select count(*) from events e join agents a on a.agent_id = e.drone join companies c on c.company_id = a.company_id
                     where c.house = 1 and e.event_type in (4, 5) and e.outcome = 1")" -ge 1 ]; }
wait_for 180 "a House station drone posts its orders" posted

echo "Smoke test passed."
