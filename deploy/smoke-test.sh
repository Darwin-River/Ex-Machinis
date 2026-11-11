#!/usr/bin/env bash
#
# End-to-end check of the Docker deployment (used by CI). Starts the stack
# for the fake domain example.test over plain HTTP, then checks that:
#   - the website answers, and Getting Started shows the players' manual,
#   - the scheduler's ticks succeed, and there are no accounts or email,
#   - FORTH is retired: the website and the API refuse a FORTH script, and
#     take a Lua one,
#   - a Lua script released on the website runs, and what it prints and
#     reports is kept for the website (#367 step 5),
#   - it asks about its world by name (quotes, cost_to_buy) and sets its
#     status line (set_status),
#   - it buys a Mining Kit at Earth and unpacks it, flies to the Moon, mines
#     and refines kunspar,
#   - companies of one player may trade with each other,
#   - a released script in several pieces runs, the Release page offers
#     starter scripts, and drones know the game names,
#   - actions burn fuel, and thinking costs the company processor tax in
#     credits, not fuel (#544): a drone that thinks for ever on 1 fuel keeps
#     its fuel and works on,
#   - money past 32,767 comes back right (a plain number in Lua),
#   - House station drones buy goods and sell fuel at once (31 and 32), and a
#     pull for more than a seller offers fails (-5) and costs no fuel,
#   - a mined-out deposit yields nothing until it refills over game time,
#   - another player's wreck can be plundered (not one's own), and a seller
#     pays the sales tax out of a sale,
#   - a waiting drone runs no instructions but still sells, and a drone sees
#     live orders only where it is,
#   - a drone broadcasts a message: a drone of its company hears it, another
#     company's doesn't,
#   - a drone's page shows its code, and a wreck's page has a post-mortem,
#   - the home feed, the market, the map and the leaderboard show the world,
#   - there is no test world: no Test button, no test runs in the API,
#   - it launches a new drone (a clone) from a ship kit with a copy of its
#     program and variables, some of its fuel and a record of its parent,
#   - a launched function that crashes restarts afresh, and crashes in a row
#     make a wreck.
# A Lua drone runs its launched() once (game-engine/src/lua_drone.c): it
# takes no commands after it is released. So each drone here is a script
# that goes through its steps in order, printing what each gave and
# reporting it (report()), and the checks wait for those replies. Where the
# test changes the world between steps (with SQL), the script waits for the
# change in a loop of wait()s, which cost no fuel.
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
# replied <drone id> <text>: a reply of the drone's, kept for the website,
# holds the text (a LIKE pattern: % is any text). The drones below print one
# line per step and report() it
replied() { [ -n "$(sql "select id from drone_messages where agent_id=$1 and body like '%$2%' limit 1")" ]; }
# replies <drone id> <text>: how many of its replies hold the text
replies() { sql "select count(*) from drone_messages where agent_id=$1 and body like '%$2%'"; }
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
# (fetch a page, then search it: with pipefail, "curl | grep -q" can fail
# when grep stops reading before curl has written the whole page)
fetch() { curl -fsS "$@" || echo "fetch failed: $*"; }
# finished <drone id>: its launched function returned normally
# (grep -c reads the whole log: grep -q stops early, and under pipefail the
# cut-off docker compose logs fails the check)
finished() { [ "$(docker compose logs game-engine 2>/dev/null | grep -c "Drone \[$1\] finished its launched word")" -gt 0 ]; }
# give <drone id> <resource> <units> <new cargo>: puts units of a resource
# in a drone's hold, stamped after its latest event (the engines read a
# drone's stock from its latest event in game time, so an event without one
# is ignored)
give() {
    sqlw "insert into events (event_type, action, logged, outcome, drone, resource, installed, locked, new_quantity, new_cargo, game_t)
         select 1, 0, 1, 1, $1, $2, 0, 0, $3, $4, IFNULL(MAX(game_t), 0) + 1 from events where drone = $1"
}

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

# FORTH is retired (#380, after round 38): drones already running in FORTH
# carry on, but the API's check and the Release page refuse a FORTH script,
# and take a Lua one
check_script() { fetch -X POST http://localhost/api/v1/check --data-urlencode "script=$1"; }
FORTH_CHECK=$(check_script ': launched ;')
grep -qF '"ok":false' <<<"$FORTH_CHECK" && grep -qF 'FORTH has been retired' <<<"$FORTH_CHECK" ||
    fail "the API's check took a FORTH script ($FORTH_CHECK)"
LUA_CHECK=$(check_script $'function launched()\n  print("hello")\nend')
grep -qF '"ok":true' <<<"$LUA_CHECK" && grep -qF '"language":"lua"' <<<"$LUA_CHECK" ||
    fail "the API's check didn't take a Lua script ($LUA_CHECK)"
release "Smoke Forth" ": launched ;"
[ -z "$(company_drone "Smoke Forth")" ] || fail "the Release page released a FORTH script"
grep -qF "FORTH has been retired" <<<"$(fetch -c "$JAR" -b "$JAR" http://localhost/release)" ||
    fail "the Release page didn't say FORTH is retired"
echo "ok: FORTH is retired: the API and the Release page refuse it, and take Lua"

# The House's station drones (#380): the app makes one at each exchange on
# start-up; the drones below trade with the Moon Depot's and the Earth
# Exchange's
station_at() { sql "select agent_id from agents where station_id is not null and object_id = $1"; }
has_station() { [ -n "$(station_at "$1")" ]; }
wait_for 60 "the Moon has a House station drone" has_station 10
wait_for 60 "Earth has a House station drone" has_station 3
MOON_STATION=$(station_at 10)
EARTH_STATION=$(station_at 3)

# Thinking costs processor tax, not fuel (#544): a drone that only computes,
# left 1 unit of fuel, keeps it and works on, and its company is billed in
# credits once a game hour (a real couple of seconds here)
THINK_SCRIPT=$(cat <<'LUA'
-- Computes for ever and never refuels: its company pays for the thinking
function launched()
  local n = 0
  while true do n = n + 1 end
end
LUA
)
release "Smoke Thinker" "$THINK_SCRIPT"
THINKER=$(company_drone "Smoke Thinker")
[ -n "$THINKER" ] || fail "release did not create the thinking drone"
sqlw "insert into events (event_type, action, logged, outcome, drone, resource, installed, locked, new_quantity, new_cargo, game_t)
     select 2, 0, 1, 1, $THINKER, 2061, 0, 0, 1, $(sql "select mass from resources where id=5901") + 1, IFNULL(MAX(game_t), 0) + 1 from events where drone = $THINKER"
billed() { [ -n "$(sql "select b.id from compute_bills b join agents a on a.company_id = b.company_id where a.agent_id = $THINKER and b.amount > 0 limit 1")" ]; }
wait_for 120 "a thinking drone's company pays processor tax" billed
[ "$(sql "select count(*) from actions where drone=$THINKER and protocol=2")" = 0 ] || fail "thinking burned fuel"
[ "$(sql "select concat(wrecked_at is null, ' ', (select units from holdings where drone=$THINKER and resource=2061 and installed=0 and locked=0)) from agents where agent_id=$THINKER")" = "1 1" ] ||
    fail "the thinking drone lost its fuel or became a wreck"
echo "ok: thinking costs the company credits, not fuel, and the drone keeps going"

# Code pushes (#553): a company released with a password takes new code,
# for 5% of its credits; the drone restarts on it
PUSH_V1=$(printf 'function launched()\n  set_status("smoke-v1")\n  while true do wait(60) end\nend')
PUSH_V2=$(printf 'function launched()\n  set_status("smoke-v2")\n  while true do wait(60) end\nend')
curl -fsS -o /dev/null -H 'Accept: application/json' -X POST http://localhost/api/v1/release \
    --data-urlencode "company_name=Smoke Pusher" --data-urlencode "script=$PUSH_V1" --data-urlencode "password=smoke-pass"
PUSHER=$(company_drone "Smoke Pusher")
[ -n "$PUSHER" ] || fail "release did not create the pushed drone"
PUSH_CO=$(sql "select company_id from agents where agent_id=$PUSHER")
says() { [ "$(sql "select script_status from agents where agent_id=$PUSHER")" = "$1" ]; }
wait_for 120 "the drone to be pushed runs its first code" says smoke-v1
[ "$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' -X POST http://localhost/api/v1/companies/$PUSH_CO/push \
    --data-urlencode "script=$PUSH_V2" --data-urlencode "password=wrong-pass")" = 403 ] || fail "a push with the wrong password was not refused"
curl -fsS -o /dev/null -H 'Accept: application/json' -X POST http://localhost/api/v1/companies/$PUSH_CO/push \
    --data-urlencode "script=$PUSH_V2" --data-urlencode "password=smoke-pass" || fail "the push was refused"
wait_for 120 "the pushed drone runs its new code" says smoke-v2
[ "$(sql "select concat(c.pushes, ' ', p.fee = FLOOR(GREATEST(p.credits_before, 0) * 50 / 1000)) from companies c join code_pushes p on p.company_id = c.company_id where c.company_id=$PUSH_CO")" = "1 1" ] ||
    fail "the push did not cost 5% of the company's credits"
echo "ok: a pushed company's drone restarts on its new code, for 5% of its credits"

# A wreck to plunder later (#324 step 12): a drone whose launched function
# crashes for the 5th time in a row. (Released first: the first drone
# plunders its wreck later on)
DRY_SCRIPT=$(cat <<'LUA'
-- (no action first: a finished action ends a run of crashes)
function launched()
  error("smoke-dry")
end
LUA
)
release "Dry Forge" "$DRY_SCRIPT"
DRY=$(company_drone "Dry Forge")
[ -n "$DRY" ] || fail "release did not create the dry drone"
sqlw "update agents set crashes_in_row = 4 where agent_id=$DRY"
KIT=$(sql "select mass from resources where id=5901")
# (Stamped after the drone's latest event: the plunder below once failed on
# fuel the engine said wasn't there)
sqlw "insert into events (event_type, action, logged, outcome, drone, resource, installed, locked, new_quantity, new_cargo, game_t)
     select 2, 0, 1, 1, $DRY, 2061, 0, 0, 1, $KIT + 1, IFNULL(MAX(game_t), 0) + 1 from events where drone = $DRY"
dry() { [ "$(sql "select wreck_cause from agents where agent_id=$DRY")" = crashes ]; }
wait_for 120 "a drone that crashes 5 times in a row becomes a wreck" dry
# A company ends when its last drone dies (#324 step 13): its credits vanish
[ "$(sql "select concat(end_cause, ' ', credits) from companies where name='Dry Forge'")" = "drones 0" ] || fail "the company whose last drone died did not end"
echo "ok: a company whose last drone died has ended"

# Companies (#324 step 4): the player gets a second company with a drone at
# the Moon and a buy order for kunspar. The first drone sells to it, and it
# goes through: the same player's companies may trade with each other (#367
# step 1). (Made before the first drone is released, so its script can name
# the sister drone; all releases here belong to the website's one owner)
PLAYER_ID=$(sql "select user_id from agents where agent_id=$DRY")
sql "insert into companies (user_id, name, credits) values ($PLAYER_ID, 'Sister Company', 1000)"
SISTER_CO=$(sql "select max(company_id) from companies where user_id=$PLAYER_ID")
sql "insert into agents (user_id, company_id, name, object_id, hull_type) values ($PLAYER_ID, $SISTER_CO, 'sister1', 10, 1)"
SISTER=$(sql "select agent_id from agents where name='sister1'")
sql "insert into events (event_type, action, logged, outcome, drone, resource, installed, locked, new_quantity, new_credits, game_t)
     select 5, 0, 1, 1, $SISTER, 1100, 0, 0, 50, 1, IFNULL(MAX(game_t), 0) + 1 from events where drone = $SISTER"

# The first drone: released on the website, it goes through the game's
# starting sequence and much else, one step after the other. Each step
# prints a line the checks below wait for.
MINER_SCRIPT=$(cat <<LUA
-- The smoke test's first drone (deploy/smoke-test.sh). Each step prints what
-- it gave and reports it, so the test can follow; where the test changes the
-- world between steps, the drone waits for the change.
local function say(text)
  print(text)
  report()
end

-- Waits, a game hour at a time (a real second here), until ready() is true
local function hold_until(ready)
  while not ready() do wait(60) end
end

local function sq(n) return n * n end

-- Whether a drone has a buy order for a resource here
local function bids(drone, resource)
  for _, o in ipairs(ask(600, resource)) do
    if o[4] == drone then return true end
  end
  return false
end

-- The broadcast the released drone sends from Earth, once it is known here
local function heard()
  for _, id in ipairs(events_earth(message_event)) do
    if message(id) == "smoke-hello" then return id end
  end
  return nil
end

function launched()
  -- A clone runs this too, with its parent's variables: it says so, and
  -- its launched function returns
  if parent and parent ~= me() then
    say("launched-word-ran " .. tostring(note))
    return
  end
  say("sum " .. 2 + 3)
  -- Asking by name (#380): records, quotes, the cost of a lot, a status line
  set_status("smoke: looking around")
  local cost, got = cost_to_buy(5, fuel)
  say("asked " .. place_name() .. " " .. type(quotes(fuel)) .. " " .. #hold() .. " " .. got .. " " .. now().day)
  -- The starting sequence: buy and unpack a Mining Kit at Earth (#506: a
  -- company starts with credits and no kit), fly to the Moon (the line
  -- after the trip runs once it is over), mine kunspar
  equip(mining_kit)
  say("kit " .. units(engine_module))
  say("unpack " .. unpack())
  print("sq " .. sq(7))
  print("moon-trip " .. travel(moon))
  say("after-trip " .. sq(6))
  say("mine " .. mine(kunspar))
  -- Computing costs the company processor tax (#544)
  local s = 0
  for i = 1, 20000 do s = s + i end
  say("computed " .. s)
  -- Sell a unit to the player's other company
  say("sister " .. push(1, kunspar, $SISTER))
  -- Refining needs 20 kunspar (each mining run gives 5 here)
  for _ = 1, 4 do mine(kunspar) end
  say("refine " .. refine(kunspar))
  -- Launch a clone once the test gives it a Mining Cruiser kit
  hold_until(function() return units(cruiser_kit) > 0 end)
  parent, note = me(), "from-parent"
  say("launch " .. launch(me(), cruiser_kit))
  -- Money past 32,767, once the test sets it
  hold_until(function() return credits() >= 70000 end)
  say("doubles " .. credits() .. " " .. 300 * 300)
  -- Sell a unit of metal to the Moon Depot's station drone
  hold_until(function() return bids($MOON_STATION, metals_a1) end)
  say("station " .. push(1, metals_a1, $MOON_STATION))
  -- Mine the deposit the test emptied; mine again once it refills
  local k = units(kunspar)
  local r = mine(kunspar)
  say("mined-dry gained " .. units(kunspar) - k .. " result " .. r)
  hold_until(function() return deposit(kunspar) > 0 end)
  say("mined-again " .. mine(kunspar))
  -- Plunder the wreck the test brought here
  k = units(kunspar)
  r = plunder($DRY)
  -- The share comes aboard by its own action, a moment after the plunder
  for _ = 1, 10 do
    if units(kunspar) - k >= 5 then break end
    wait(60)
  end
  local got = units(kunspar) - k
  say("plunder " .. r .. " gained " .. got .. " half " .. tostring(got >= 5))
  -- Hear the released drone's broadcast, from the Earth database
  local id
  hold_until(function() id = heard() return id ~= nil end)
  say("hear " .. tostring(crowd(earth) > 0) .. " " .. event_drone(id) .. " " .. message(id))
  -- Once the test brings it to Earth: buy 2 fuel from the cheapest seller,
  -- the waiting released drone
  hold_until(function() return location() == earth end)
  local _, seller = best_offer(fuel)
  say("bought " .. pull(2, fuel, seller) .. " from " .. seller)
  -- Back on the Moon: Earth's live orders can't be seen from here
  hold_until(function() return location() == moon end)
  local rows = ask(611, fuel)
  say("far:" .. tostring(rows[1] and rows[1][4] or "none") .. ":end")
end
LUA
)
release "Smoke Miner" "$MINER_SCRIPT"
DRONE_ID=$(company_drone "Smoke Miner")
[ -n "$DRONE_ID" ] || fail "release did not create a company and drone"
DRONE=$(sql "select name from agents where agent_id=$DRONE_ID")
# The engine gathers the script's pieces into lua_script as it loads them
lua_loaded() { [ "$(sql "select concat(language, ' ', lua_script is not null) from agents where agent_id=$DRONE_ID")" = "lua 1" ]; }
wait_for 60 "drone $DRONE is a Lua drone with its script" lua_loaded
wait_for 90 "drone $DRONE ran its launched function; its reply is kept for the website" replied $DRONE_ID "sum 5"
wait_for 30 "drone $DRONE asked about its world by name" replied $DRONE_ID "asked Earth table"
[ "$(sql "select script_status from agents where agent_id=$DRONE_ID")" = "smoke: looking around" ] || fail "set_status didn't set the drone's status line"
echo "ok: the drone's status line is set"

# The starting sequence from the game data: buy a Mining Kit at Earth and
# unpack it (#506), fly to the Moon (10), mine kunspar (1100) with the
# kit's mining module
has() { [ "$(sql "select IFNULL(MAX(units), 0) from sqInventory where drone=$DRONE_ID and resource=$1")" -ge "${2:-1}" ]; }
wait_for 120 "drone bought a Mining Kit at Earth and unpacked it" replied $DRONE_ID "kit 1"
wait_for 90 "nothing left to unpack" replied $DRONE_ID "unpack 0"
wait_for 30 "the kit's engine is in its cargo" has 5001
at_moon() { [ "$(sql "select object_id from agents where agent_id=$DRONE_ID")" = 10 ]; }
wait_for 120 "drone flew to the Moon" at_moon
wait_for 60 "drone ran its steps before the trip" replied $DRONE_ID "sq 49%moon-trip 0"
# perform paused the script for the trip: the line after it must still run
wait_for 30 "script carried on after the trip" replied $DRONE_ID "after-trip 36"
wait_for 120 "drone mined kunspar on the Moon" has 1100
wait_for 30 "the mining went through" replied $DRONE_ID "mine 0"

sister_bought() { [ "$(sql "select IFNULL(MAX(units), 0) from sqInventory where drone=$SISTER and resource=1100")" = 1 ]; }
wait_for 90 "a sale to the player's other company goes through" sister_bought
wait_for 30 "the sale to the other company worked" replied $DRONE_ID "sister 0"

# Refining needs 20 kunspar and the Mining Kit's Refinery (A1); each perform
# waits for its action
wait_for 180 "drone refined kunspar into metals" has 2011
wait_for 30 "the refining worked" replied $DRONE_ID "refine 0"

# Launch (#238): give the drone a Mining Cruiser kit (building one takes
# days), then it launches a drone with a copy of its own program and
# variables: the new drone runs launched() too, and finds itself a clone
give $DRONE_ID 6001 1 224
wait_for 10 "drone got a ship kit" has 6001
launched() { [ -n "$(sql "select agent_id from agents where user_id=(select user_id from agents where agent_id=$DRONE_ID) and hull_type=6001 and lua_script is not null")" ]; }
wait_for 120 "drone launched a Mining Cruiser" launched
NEW=$(sql "select name from agents where hull_type=6001 order by agent_id desc limit 1")
NEW_ID=$(sql "select agent_id from agents where name='$NEW'")
# (it says the variable its parent set before launching it: a clone gets
# its parent's variables)
wait_for 60 "launched drone $NEW ran launched() with its parent's variables; its reply is kept for the website" replied $NEW_ID "launched-word-ran from-parent"
wait_for 60 "drone finished the launch" replied $DRONE_ID "launch 0"
[ "$(sql "select IFNULL(MAX(units), 0) from sqInventory where drone=$DRONE_ID and resource=6001")" = 0 ] || fail "the kit was not used up"
echo "ok: the kit was used up"

# Cloning (#324 step 13): the launcher is the clone's parent, one generation
# on, and the clone got its program (the clean copy it restarts from after a
# crash: a Lua drone's script) and 20 of its fuel
[ "$(sql "select concat(parent_id, ' ', generation, ' ', code_from) from agents where agent_id=$NEW_ID")" = "$DRONE_ID 1 $DRONE_ID" ] || fail "the clone's lineage was not recorded"
echo "ok: the clone's parent, generation and program source are recorded"
clone_fuel() { [ "$(sql "select IFNULL(MAX(units), 0) from sqInventory where drone=$NEW_ID and resource=2061")" -ge 1 ]; }
wait_for 30 "the clone got fuel from its launcher" clone_fuel
wait_for 60 "launched drone's launched function returned normally" replied $NEW_ID "has finished"
[ "$(sql "select c.language = 'lua' and c.lua_script = p.lua_script from agents c join agents p on p.agent_id = c.parent_id where c.agent_id=$NEW_ID")" = 1 ] ||
    fail "the clone didn't get a copy of its launcher's script"
echo "ok: the clone has a copy of its launcher's script"

# Entry point and crashes (#324 step 5): an error in launched() is a crash
# (-1 for Lua). The drone restarts from its script, with fresh variables,
# after a wait that doubles with each crash in a row; 5 crashes in a row
# make a wreck. A finished action ends a run of crashes: while this drone is
# at Earth it waits a minute before it crashes, so it crashes once in a row
# and restarts each time
CRASH_SCRIPT=$(cat <<'LUA'
-- Crashes each time it runs. At Earth it waits a minute first (an action
-- that works ends a run of crashes); elsewhere it crashes at once.
function launched()
  print("crasher-run " .. tostring(carried))
  report()
  carried = "kept"
  if location() == earth then wait(1) end
  error("smoke-crash")
end
LUA
)
release "Smoke Crasher" "$CRASH_SCRIPT"
CRASHER=$(company_drone "Smoke Crasher")
[ -n "$CRASHER" ] || fail "release did not create the crashing drone"
crashed() { [ "$(sql "select count(*) from drone_crashes where drone=$CRASHER and code=-1 and restart_at is not null")" -ge 1 ]; }
wait_for 90 "a crash in launched is caught (-1) and a restart is scheduled" crashed
wait_for 30 "the crash is reported" replied $CRASHER "smoke-crash%crashed with error -1"
# The restart comes a real second later on this clock: it runs launched()
# again from the script, with none of the variables the crashed run set
restarted_clean() { [ "$(replies $CRASHER "crasher-run nil")" -ge 2 ]; }
wait_for 60 "the restart ran launched() again" restarted_clean
[ "$(replies $CRASHER "crasher-run kept")" = 0 ] || fail "a restart kept the crashed run's variables"
[ "$(sql "select lua_script is not null from agents where agent_id=$CRASHER")" = 1 ] || fail "the crashed drone lost its script"
echo "ok: a crashed drone restarts from its script, with fresh variables"
# Away from Earth it crashes at once, and the next crash is the 5th in a row
sql "update agents set object_id = 10 where agent_id=$CRASHER"
sql "update agents set crashes_in_row = 4 where agent_id=$CRASHER"
wrecked() { [ "$(sql "select concat(wrecked_at is not null, ' ', IFNULL(wreck_cause, '')) from agents where agent_id=$CRASHER")" = "1 crashes" ]; }
wait_for 60 "the 5th crash in a row makes the drone a wreck" wrecked

# Fuel (#324 step 8): the first drone's actions burned fuel from its starting
# 100, and it keeps going; its thinking burned none (#544)
[ "$(sql "select IFNULL(MAX(units), 0) < 100 and IFNULL(MAX(units), 0) > 0 from sqInventory where drone=$DRONE_ID and resource=2061")" = 1 ] || fail "the drone's fuel was not burned"
[ -z "$(sql "select id from actions where drone=$DRONE_ID and protocol=2 limit 1")" ] || fail "thinking burned fuel"
[ "$(sql "select wrecked_at is null from agents where agent_id=$DRONE_ID")" = 1 ] || fail "the drone with fuel became a wreck"
echo "ok: actions burn fuel, thinking doesn't, and a fuelled drone keeps going"

# The first drone waits for its balance to pass 70,000 (money past 32,767
# came back as a double in FORTH; in Lua it is a plain number), then sells
# to the Moon Depot and mines a deposit: set those up first.
# House stations (#324 step 10; #380): the drone, on the Moon, sells a unit of
# the metal it refined to the Moon Depot's station drone like to any drone: a
# push into its buy order, at once, and the seller pays the sales tax out of
# the sale (#380), here 50%
sql "update house_rules set sales_tax_permille = 500"
# Mining deposits (#324 step 11): mining takes from the place's deposit. Empty
# the Moon's kunspar deposit and stop it refilling: mining there yields
# nothing. Let it refill again, and a few game hours later mining yields again.
kunspar() { sql "select IFNULL(MAX(units), 0) from sqInventory where drone=$DRONE_ID and resource=1100"; }
sql "update deposits set refill_per_day = 0 where object_id = 10 and resource = 1100"
sql "insert into deposit_state (object_id, resource, amount, refilled_t) values (10, 1100, 0, 0) on duplicate key update amount = 0"
KUNSPAR=$(kunspar)
COMPANY=$(sql "select company_id from agents where agent_id=$DRONE_ID")
sql "update companies set credits = 70000 where company_id = $COMPANY"
wait_for 60 "a balance of 70,000 and 300 x 300 come out right" replied $DRONE_ID "doubles 70000 90000"

bid() { [ "$(sql "select count(*) from events where drone=$MOON_STATION and event_type=5 and resource=2011 and outcome=1")" -ge 1 ]; }
wait_for 180 "the Moon Depot's station drone posts its buy order for metals" bid
sold() { [ "$(sql "select count(*) from trades where seller=$DRONE_ID and buyer=$MOON_STATION and resource=2011 and units=1 and credits>0")" = 1 ]; }
wait_for 90 "the Moon Depot's station drone bought the drone's metal" sold
[ "$(sql "select tax = FLOOR((credits * 500 + 500) / 1000) and tax > 0 from trades where seller=$DRONE_ID and buyer=$MOON_STATION")" = 1 ] ||
    fail "the seller didn't pay the sales tax on its station sale"
[ "$(sql "select count(*) from company_taxes t join trades d on d.tax = t.amount join agents a on a.agent_id = d.seller
          where d.seller=$DRONE_ID and d.buyer=$MOON_STATION and t.company_id = a.company_id and t.eliminated = 0")" -ge 1 ] ||
    fail "the sales tax wasn't recorded"
echo "ok: a drone sold to the House station drone at once, and paid the sales tax out of the sale"
no_tax
wait_for 30 "the station sale worked" replied $DRONE_ID "station 0"

wait_for 90 "drone mined the empty deposit" sh -c "docker compose logs game-engine | grep -q 'Deposit of resource \[1100\] at \[10\]: \[0\] of'"
wait_for 60 "drone finished mining the empty deposit" replied $DRONE_ID "mined-dry gained 0 "
[ "$(kunspar)" = "$KUNSPAR" ] || fail "a mined-out deposit still yielded kunspar"
echo "ok: a mined-out deposit yields nothing"
# Plunder (#324 step 12), the drone's next step but one: a drone where the
# wreck is may plunder it, whoever owns it (#367 step 1). Put the wreck on
# the Moon, where the first drone is, with 10 kunspar aboard; the plunderer
# gets half and the wreck is left empty
sql "update agents set object_id = 10 where agent_id = $DRY"
give $DRY 1100 10 "$KIT + 10"
sql "update deposits set refill_per_day = 40 where object_id = 10 and resource = 1100"
more_kunspar() { [ "$(kunspar)" -gt "$KUNSPAR" ]; }
wait_for 90 "the deposit refilled over game time and yields again" more_kunspar
wait_for 60 "drone finished mining again" replied $DRONE_ID "mined-again 0"

[ -z "$(sql "select id from actions where drone=$DRONE_ID and aborted=1")" ] || fail "an action was aborted"
echo "ok: no action aborted"

for svc in game-engine events-engine app; do
    restarts=$(docker inspect -f '{{.RestartCount}}' "$(docker compose ps -q $svc)")
    [ "$restarts" = 0 ] || fail "$svc restarted $restarts times"
done
echo "ok: no service restarted"

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
if ! wait_quietly 90 replied $DRONE_ID "plunder 0 gained % half true"; then
    # Say what the plunderer said and holds (#380: a CI run once failed here)
    sql "select id, body from drone_messages where agent_id=$DRONE_ID order by id desc limit 6" >&2
    sql "select resource, units from sqInventory where drone=$DRONE_ID and units <> 0" >&2
    sql "select id, protocol, outcome, aborted from actions where drone=$DRONE_ID order by id desc limit 6" >&2
    fail "the plunderer got half the wreck's kunspar"
fi
echo "ok: the plunderer got half the wreck's kunspar"

# Public Forge (#324 step 6): release a script on the website. It is
# longer than one 2,048-character command, so it is released in pieces that
# must all load, one after the other, before launched runs (#367)
PADDING=$(for i in $(seq 1 40); do echo "-- padding line $i: a released script may be as long as it likes"; done)
FORGE_SCRIPT=$(cat <<LUA
-- The smoke test's released drone (deploy/smoke-test.sh), at Earth: each
-- step prints what it gave and reports it
local function hello() return "forge-release-" end
$PADDING
local function say(text)
  print(text)
  report()
end

-- Units of a resource the Earth Exchange's station drone offers (0 until
-- it posts its sell order)
local function station_offer(resource)
  for _, o in ipairs(ask(610, resource)) do
    if o[4] == $EARTH_STATION then return math.max(o[3] - o[2], 0) end
  end
  return 0
end

function launched()
  say(hello() .. "ok7")
  -- The game's names
  say("game-words " .. stdlib() .. " " .. moon .. " " .. kunspar .. " "
      .. tostring(units(fuel) > 0) .. " " .. tostring(trip(earth) > 0))
  -- A message for the drones that listen
  say("broadcast " .. broadcast("smoke-hello"))
  -- Buy 10 fuel from the Earth Exchange's station drone
  while station_offer(fuel) < 10 do wait(60) end
  say("fuel " .. pull(10, fuel, $EARTH_STATION))
  -- Ask it for one more unit of a good than it offers: refused (-5), and
  -- the fuel the try burned comes back
  for _, good in ipairs({inorganics_a1, organics_a1, metals_a1, metals_a2, alloys_a1}) do
    local n = station_offer(good)
    if n > 0 then
      local before = units(fuel)
      local r = pull(n + 1, good, $EARTH_STATION)
      say("too-much " .. r .. " fuel " .. before .. " " .. units(fuel) .. " got " .. units(good))
      break
    end
  end
  -- Once the test says (its balance past 90,000): sell fuel, see the order
  -- here, and wait 1,200 game minutes, selling all the while
  while credits() < 90000 do wait(60) end
  local r = sell_order(1, 1, fuel)
  local rows = ask(610, fuel)
  local seen = rows[1] and rows[1][4] or 0
  say("sell-and-wait " .. r .. " " .. seen .. " " .. wait(1200))
end
LUA
)
release "Smoke Forge" "$FORGE_SCRIPT" factory
FORGE_DRONE=$(company_drone "Smoke Forge")
[ -n "$FORGE_DRONE" ] || fail "release did not create a company and drone"
# Credits instead of a kit (#506): this one asked for the Factory Kit, and
# got 3,000 credits and no kit
[ "$(sql "select count(*) from sqInventory where drone=$FORGE_DRONE and resource in (5901, 5902) and units > 0")" = 0 ] ||
    fail "a release got a kit"
# (it may have bought fuel already: what it has and has spent come to 3,000)
[ "$(sql "select c.credits + IFNULL((select sum(t.credits) from trades t where t.buyer=$FORGE_DRONE), 0) from companies c
          where c.company_id=(select company_id from agents where agent_id=$FORGE_DRONE)")" = 3000 ] ||
    fail "a release didn't start with 3,000 credits"
echo "ok: a release starts with 3,000 credits and no kit"
echo "ok: release created a company with a drone"
wait_for 90 "released drone ran launched; its reply is kept for the website" replied $FORGE_DRONE "forge-release-ok7"
FORGE_NAME=$(sql "select name from agents where agent_id=$FORGE_DRONE")
# Public, on the drone's page (#367 step 3)
wait_for 30 "the drone's page shows the reply" sh -c "curl -fsS http://localhost/spacecraft/$FORGE_DRONE | grep -q 'forge-release-ok7'"
# Starter scripts and game names (#324 step 15): the Release page fills in
# a starter, and a drone knows the game's names (the library's version: 19)
grep -qF "richer(kunspar, wolfite)" <<<"$(fetch -b "$JAR" "http://localhost/release?starter=miner")" || fail "the Release page did not offer the miner starter"
echo "ok: the Release page offers the starter scripts"
wait_for 60 "a drone knows the game's names" replied $FORGE_DRONE "game-words 19 10 1100 true true"

# Broadcasts (#380): the released drone, at Earth, broadcasts a message, an
# event. Messages are private to a company (after round 40): the first
# drone, on the Moon, of another company, never hears it; moved into the
# sender's company, it reads it from the Earth database (events_earth) once
# light has carried it there, with its sender and text, and counts the
# drones at Earth. Then it goes back to its own company.
sent() { [ "$(sql "select count(*) from broadcasts b join events e on e.action = b.action_id join observations o on o.event = e.id
                   where b.drone=$FORGE_DRONE and b.body='smoke-hello' and e.event_type=13 and e.outcome=1 and o.drone=0")" = 1 ]; }
wait_for 60 "a drone broadcast a message" sent
wait_for 30 "the broadcast worked" replied $FORGE_DRONE "broadcast 0"
sleep 15
[ "$(replies $DRONE_ID "hear ")" = 0 ] || fail "another company's drone read the message"
echo "ok: another company's drone can't read the message"
SENDER_COMPANY=$(sql "select company_id from agents where agent_id=$FORGE_DRONE")
sql "update agents set company_id = $SENDER_COMPANY where agent_id = $DRONE_ID"
wait_for 60 "a drone of the sender's company read the broadcast from the Earth database" replied $DRONE_ID "hear true $FORGE_DRONE smoke-hello"
sql "update agents set company_id = $COMPANY where agent_id = $DRONE_ID"
echo "ok: drones broadcast messages, events their company's drones read from the Earth database"

# The House fuel seller (#324 step 10; #380): the released drone, at Earth,
# buys 10 fuel from the Earth Exchange's station drone like from any drone: a
# pull from its sell order, at once, at 3 a unit (5 until #380 round 37)
fuel_ask() { [ "$(sql "select count(*) from events where drone=$EARTH_STATION and event_type=4 and resource=2061 and outcome=1")" -ge 1 ]; }
wait_for 180 "the Earth Exchange's station drone posts its fuel for sale" fuel_ask
bought() { [ "$(sql "select count(*) from trades where seller=$EARTH_STATION and buyer=$FORGE_DRONE and resource=2061 and units=10 and credits=30")" = 1 ]; }
wait_for 90 "a drone bought 10 fuel from the House at 3 a unit" bought
# In its hold: the pull's increment event. (Its total can still fall: the
# drone's computing is billed in fuel too)
more_fuel() { [ "$(sql "select count(*) from events e join actions x on x.id = e.action
                       where x.protocol = 11 and e.drone = $FORGE_DRONE and e.resource = 2061
                       and e.event_type = 1 and e.outcome = 1")" -ge 1 ]; }
wait_for 60 "the fuel arrived in its hold" more_fuel
wait_for 30 "the pull worked" replied $FORGE_DRONE "fuel 0"
# A pull for more than the seller offers fails (-5) and costs no fuel (#380
# round 38): the engine gives back the fuel it burned
wait_for 60 "a pull for more than the seller offers fails with -5" replied $FORGE_DRONE "too-much -5 "
wait_for 30 "the failed pull's fuel was given back" sh -c "docker compose logs events-engine game-engine | grep -q 'Drone \[$FORGE_DRONE\]: \[[0-9]*\] fuel given back by failed transfer action'"
TOO_MUCH=$(sql "select body from drone_messages where agent_id=$FORGE_DRONE and body like '%too-much -5 %' limit 1" | grep -o 'too-much -5 fuel [0-9]* [0-9]* got [0-9]*')
read -r _ _ _ FUEL_BEFORE FUEL_AFTER _ GOT <<<"$TOO_MUCH"
# (computing between the two looks may cost a unit; no more, and none of the
# good comes)
[ -n "$GOT" ] && [ "$GOT" = 0 ] && [ "$FUEL_AFTER" -le "$FUEL_BEFORE" ] && [ "$FUEL_AFTER" -ge $((FUEL_BEFORE - 1)) ] ||
    fail "the failed pull changed the drone's hold ($TOO_MUCH)"
echo "ok: a pull for more than the seller offers fails and costs no fuel"

# Waiting and local information (#324 step 14). The released drone, at
# Earth, posts a sell order for fuel, checks it sees it there, and waits
# 1,200 game minutes (20 seconds here). While it waits it runs no
# instructions, yet it still sells: a buyer pulls from it.
# (at 1 a unit its order is the cheapest here, so the first row: the Earth
# Exchange's station drone sells fuel too, at 3)
FORGE_COMPANY=$(sql "select company_id from agents where agent_id=$FORGE_DRONE")
sql "update companies set credits = 90000 where company_id = $FORGE_COMPANY"
# (its sell order first: until the test says, it waits a game hour at a time)
forge_waits() { [ "$(sql "select count(*) from events where drone=$FORGE_DRONE and event_type=4 and resource=2061 and outcome=1")" -ge 1 ] &&
                [ "$(sql "select a.protocol from agents g join actions a on a.id = g.waiting_action where g.agent_id=$FORGE_DRONE")" = 3 ]; }
wait_for 60 "a drone with a sell order is waiting" forge_waits
BEFORE=$(sql "select instructions from agents where agent_id=$FORGE_DRONE")
# The first drone (another player's) comes to Earth and buys 2 fuel from it
sql "update agents set object_id = 3 where agent_id = $DRONE_ID"
sold_waiting() { [ "$(sql "select count(*) from trades where seller=$FORGE_DRONE and buyer=$DRONE_ID and resource=2061 and units=2")" = 1 ]; }
wait_for 30 "a waiting drone still sells (the buyer pulls from it)" sold_waiting
forge_waits || fail "the seller stopped waiting before its wait was up"
[ "$(sql "select instructions from agents where agent_id=$FORGE_DRONE")" = "$BEFORE" ] || fail "a waiting drone ran instructions"
echo "ok: a waiting drone runs no instructions"
wait_for 60 "drone finished buying" replied $DRONE_ID "bought 0 from $FORGE_DRONE"
# Back on the Moon, the first drone can't see Earth's live orders (query
# 611 used to list orders anywhere); the seller, at Earth, saw its own
sql "update agents set object_id = 10 where agent_id = $DRONE_ID"
# (the Moon Depot's station drone sells fuel there: the seller's order at 1
# a unit would be the cheapest, so the first row, if the drone saw it)
blind_far() { replied $DRONE_ID "far:%:end" && ! replied $DRONE_ID "far:$FORGE_DRONE:end"; }
wait_for 60 "a drone on the Moon can't see Earth's live orders" blind_far
wait_for 60 "drone finished its launched function" finished $DRONE_ID
wait_for 60 "the seller saw its order at Earth and its wait ended" replied $FORGE_DRONE "sell-and-wait 0 $FORGE_DRONE 0"
wait_for 30 "the seller's launched function has finished" replied $FORGE_DRONE "has finished"

# The Drone page (#324 step 16): a released drone shows its code and a Copy
# button; a wreck has a post-mortem in plain words
FORGE_PAGE=$(fetch "http://localhost/spacecraft/$FORGE_DRONE")
grep -qF "forge-release-" <<<"$FORGE_PAGE" || fail "the drone page doesn't show the released code"
grep -qF "release?copy=$FORGE_DRONE" <<<"$FORGE_PAGE" || fail "the drone page has no Copy this code link"
echo "ok: the drone page shows its code, with a Copy this code link"
grep -qF "Its launched function crashed" <<<"$(fetch "http://localhost/spacecraft/$DRY")" || fail "no post-mortem for the drone that crashed"
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
# The JSON API (#380): a drone and the clock, as programs see them
grep -qF "\"id\":$FORGE_DRONE," <<<"$(fetch "http://localhost/api/v1/drones/$FORGE_DRONE")" || fail "the API doesn't show the drone"
grep -qF '"game_day"' <<<"$(fetch http://localhost/api/v1/clock)" || fail "the API doesn't give the clock"
echo "ok: the API shows the drone and the clock"

# No test world (#380, after round 40): every release is live, so the
# Release page has no Test button and the API no test runs
! curl -fsS http://localhost/release | grep -q 'release/test' || fail "the Release page still has a Test button"
[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST http://localhost/api/v1/test -H 'Content-Type: application/json' -d '{"script":"function launched() end"}')" = 404 ] ||
    fail "the API still takes test runs"
echo "ok: there is no test world: every release is live"

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

# Markets that move (#380 round 36): today's House prices (house_market),
# the news of booms and slumps, and recipes
[ "$(sql "select count(*) from house_market where price > 0 and daily_units > 0")" -ge 100 ] ||
    fail "the House market view has no prices"
[ "$(sql "select count(*) from house_market b where side = 1 and price > (select min(a.price) from house_market a where a.resource = b.resource and a.side = 2)")" = 0 ] ||
    fail "an exchange bids more than another asks"
curl -fsS http://localhost/api/v1/news | grep -q '^\[' || fail "/news didn't answer"
curl -fsS http://localhost/api/v1/recipes/4311/tree | grep -q '"resource":2011' || fail "the pump's recipe tree has no metals"

echo "Smoke test passed."
