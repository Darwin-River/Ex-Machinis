#!/usr/bin/env bash
#
# End-to-end check of the Docker deployment (used by CI). Starts the stack
# for the fake domain example.test over plain HTTP, then checks that:
#   - the website answers, and Getting Started shows the players' manual,
#   - the scheduler's mail checks succeed,
#   - emailing registrar@ with subject "register" registers a player,
#   - a drone runs an emailed FORTH command and the engine emails the reply,
#   - it unpacks its starter kit, flies to the Moon, mines and refines kunspar,
#   - a sale between two companies of the same player is refused,
#   - with email off, a script released on the website runs (no email),
#   - actions and computing burn fuel; a drone that runs dry becomes a wreck,
#   - money past 32,767 comes back as a double, and the double words work,
#   - House drop stations buy goods and sell fuel when their batches clear,
#   - the Test button runs a script in the test world, not the live one,
#   - it launches a new drone from a ship kit with a copy of its program.
# Needs ports 80, 443 and 25 free. Leaves the stack running; remove it with
#   docker compose down -v && rm -rf .env mail/config
set -euo pipefail
cd "$(dirname "$0")"

DOMAIN=example.test
PLAYER=player@outside.test

[ -f .env ] && { echo "deploy/.env already exists; run this on a clean checkout." >&2; exit 1; }
# A fast game clock (1 real second = 1 game hour) so the trip to the Moon
# and the light-speed delays take seconds
SITE_ADDRESS=http://localhost GAME_TIME_SCALE=3600 ./setup.sh "$DOMAIN" "admin@$DOMAIN"

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
sql() {
    docker compose exec -T db sh -c 'mysql -N -uexmachinis -p"$MYSQL_PASSWORD" exmachinis 2>/dev/null' <<<"$1"
}
send_mail() { # to subject body
    python3 - "$PLAYER" "$@" <<'PY'
import smtplib, sys
from email.message import EmailMessage
m = EmailMessage()
m['From'], m['To'], m['Subject'] = sys.argv[1], sys.argv[2], sys.argv[3]
m.set_content(sys.argv[4])
with smtplib.SMTP('127.0.0.1', 25, timeout=30) as s:
    s.send_message(m)
PY
}

wait_for 120 "website answers" curl -fsS -o /dev/null http://localhost/
# The image must carry the players' manual (Getting Started renders it)
wait_for 60 "Getting Started shows the players' manual" sh -c "curl -fsS http://localhost/getting-started | grep -q 'chapter-4-issuing-commands'"
wait_for 120 "mail check succeeds" sh -c "docker compose logs app | grep -q 'GET /get-mails/[^ ]* HTTP/1.1\" 200'"
wait_for 60 "mail server accepts connections" python3 -c "import smtplib; smtplib.SMTP('127.0.0.1', 25, timeout=5).quit()"

send_mail "registrar@$DOMAIN" register "sign me up"
registered() { [ -n "$(sql "select user_id from users where email='$PLAYER'")" ]; }
wait_for 60 "player registered by email" registered

DRONE=$(sql "select a.name from agents a join users u on u.user_id = a.user_id where u.email='$PLAYER' order by a.agent_id limit 1")
[ -n "$DRONE" ] || fail "no drone created for the player"
send_mail "$DRONE@$DOMAIN" "smoke test" "<run>2 3 + . cr</run>"
wait_for 90 "drone $DRONE ran the command" sh -c "docker compose logs game-engine | grep -q 'Command succesfully evaluated'"
wait_for 30 "engine emailed the reply" sh -c "docker compose logs mail | grep -q 'from=<$DRONE@$DOMAIN>'"

# The starting sequence from the game data: unpack the starter kit, fly to
# the Moon (10), mine kunspar (1100) with the kit's mining module, and check a
# multi-line program at the same time.
DRONE_ID=$(sql "select agent_id from agents where name='$DRONE'")
has() { [ "$(sql "select IFNULL(MAX(units), 0) from sqInventory where drone=$DRONE_ID and resource=$1")" -ge "${2:-1}" ]; }
wait_for 30 "drone got the starter kit" has 5901
# perform holds a script until its whole action is done; until then the
# drone refuses new scripts, so wait for it to be idle before sending one
idle() { [ "$(sql "select count(*) from commands where agent_id=$DRONE_ID") $(sql "select IFNULL(waiting_action, 0) from agents where agent_id=$DRONE_ID")" = "0 0" ]; }
send_mail "$DRONE@$DOMAIN" "unpack" "<run>25901 perform . cr</run>"
wait_for 90 "starter kit unpacked (engine in cargo)" has 5001
wait_for 60 "drone finished the unpack script" idle

send_mail "$DRONE@$DOMAIN" "to the moon" "<run>
  : sq dup * ;
  7 sq . cr
  10 5001 perform . cr
  6 sq . cr
</run>"
wait_for 90 "drone ran a multi-line program" sh -c "docker compose logs game-engine | grep -q 'result \[ 49'"
at_moon() { [ "$(sql "select object_id from agents where agent_id=$DRONE_ID")" = 10 ]; }
wait_for 120 "drone flew to the Moon" at_moon
wait_for 60 "drone finished the trip script" idle
# perform paused the script for the trip: the line after it must still run
wait_for 30 "script carried on after the trip" sh -c "docker compose logs game-engine | grep -qE '[|] +36[[:space:]]*\$'"

send_mail "$DRONE@$DOMAIN" "mine" "<run>11100 perform . cr</run>"
wait_for 120 "drone mined kunspar on the Moon" has 1100
wait_for 60 "drone finished the mining script" idle

# Companies (#324 step 4): the player gets a second company with a drone at
# the Moon and a buy order for kunspar. Selling to it is refused: a trade
# between two companies of the same player leaves -13 and moves nothing.
PLAYER_ID=$(sql "select user_id from agents where agent_id=$DRONE_ID")
sql "insert into companies (user_id, name, credits) values ($PLAYER_ID, 'Sister Company', 1000)"
SISTER_CO=$(sql "select max(company_id) from companies where user_id=$PLAYER_ID")
sql "insert into agents (user_id, company_id, name, object_id, hull_type) values ($PLAYER_ID, $SISTER_CO, 'sister1', 10, 1)"
SISTER=$(sql "select agent_id from agents where name='sister1'")
sql "insert into events (event_type, action, logged, outcome, drone, resource, installed, locked, new_quantity, new_credits)
     values (5, 0, 1, 1, $SISTER, 1100, 0, 0, 50, 1)"
send_mail "$DRONE@$DOMAIN" "sell to my other company" "<run>1 1100 $SISTER 10 perform . cr</run>"
wait_for 90 "a sale to the player's other company is refused (-13)" sh -c "docker compose logs game-engine | grep -q 'perform leaves \[-13\]'"
wait_for 60 "drone finished the refused-sale script" idle
[ "$(sql "select IFNULL(MAX(units), 0) from sqInventory where drone=$SISTER and resource=1100")" = 0 ] || fail "kunspar reached the other company"
# The refused sale is the one action this test expects to be aborted
REFUSED_ACTION=$(sql "select IFNULL(MAX(id), 0) from actions where drone=$DRONE_ID and aborted=1")

# Refining needs 20 kunspar (each mining run gives 5 here) and the starter
# kit's Smelter (A1); each perform waits for its action
send_mail "$DRONE@$DOMAIN" "refine" "<run>
  11100 perform drop 11100 perform drop 11100 perform drop
  21100 perform . cr
</run>"
wait_for 180 "drone refined kunspar into metals" has 2011
wait_for 60 "drone finished the refining script" idle

# Launch (#238): give the drone a Mining Cruiser kit (building one takes
# days), then launch a drone with a copy of its own program. The copy is
# taken while this script is paused in perform: the new drone starts at the
# prompt and runs its launched word.
sql "insert into events (event_type, action, logged, outcome, drone, resource, installed, locked, new_quantity, new_cargo)
     values (1, 0, 1, 1, $DRONE_ID, 6001, 0, 0, 1, 224)"
wait_for 10 "drone got a ship kit" has 6001
send_mail "$DRONE@$DOMAIN" "launch" "<run>
  : launched .\" launched-word-ran\" cr ;
  $DRONE_ID 6001 12 perform . cr
</run>"
launched() { [ -n "$(sql "select agent_id from agents where user_id=(select user_id from agents where agent_id=$DRONE_ID) and hull_type=6001 and vm is not null")" ]; }
wait_for 120 "drone launched a Mining Cruiser" launched
NEW=$(sql "select name from agents where hull_type=6001 order by agent_id desc limit 1")
wait_for 60 "launched drone $NEW ran its launched word" sh -c "docker compose logs game-engine | grep -q 'result \[launched-word-ran'"
wait_for 60 "launched drone emailed its owner" sh -c "docker compose logs mail | grep -q 'from=<$NEW@$DOMAIN>'"
wait_for 60 "drone finished the launch script" idle
[ "$(sql "select IFNULL(MAX(units), 0) from sqInventory where drone=$DRONE_ID and resource=6001")" = 0 ] || fail "the kit was not used up"
echo "ok: the kit was used up"

# Entry point and crashes (#324 step 5): the engine runs launched inside
# catch and saved a clean copy of the program at launch. A launched word that
# crashes restarts from the clean copy after a wait; 5 crashes in a row make a
# wreck.
NEW_ID=$(sql "select agent_id from agents where name='$NEW'")
# A launched drone's hold starts empty: give it fuel for the rounds below,
# as its owner would by pushing some over (#324 step 8)
sql "insert into events (event_type, action, logged, outcome, drone, resource, installed, locked, new_quantity, new_cargo)
     values (1, 0, 1, 1, $NEW_ID, 2061, 0, 0, 200, 200)"
wait_for 60 "launched drone's launched word returned normally" sh -c "docker compose logs game-engine | grep -q 'Drone \[$NEW_ID\] finished its launched word'"
[ "$(sql "select release_vm is not null from agents where agent_id=$NEW_ID")" = 1 ] || fail "no clean copy saved at launch"
echo "ok: clean copy saved at launch"
idle_new() { [ "$(sql "select count(*) from commands where agent_id=$NEW_ID")" = 0 ]; }
crash_launched() { # redefine launched to divide by zero, then restart it
    # The restart is queued only once this round's definition has run: the
    # engine has read the round's mail and the drone has nothing left to do
    # (compiling may take more than one instruction slice, so its output can
    # come in pieces: don't match on it)
    send_mail "$NEW@$DOMAIN" "crash $1" "<run>: launched 1 0 / ;</run>"
    wait_for 60 "launched drone $NEW got the crashing word ($1)" sh -c "docker compose logs game-engine | grep -q 'SUBJECT=\[crash $1\]'"
    wait_for 60 "launched drone $NEW took the crashing word ($1)" idle_new
    sql "insert into commands (code, agent_id, created_at, updated_at, subject, email_content) values ('<launched>', $NEW_ID, NOW(), NOW(), 'Restart', '')"
}
crash_launched 1
crashed() { [ "$(sql "select count(*) from drone_crashes where drone=$NEW_ID and code=-10 and restart_at is not null")" = 1 ]; }
wait_for 60 "a crash in launched is caught (-10) and a restart is scheduled" crashed
[ "$(sql "select count(*) from commands where agent_id=$NEW_ID and code='<launched>' and deliver_at > (select min(restart_at) - 1 from drone_crashes where drone=$NEW_ID)")" = 1 ] || fail "no restart queued"
[ "$(sql "select vm = release_vm from agents where agent_id=$NEW_ID")" = 1 ] || fail "the program was not put back to its clean copy"
echo "ok: the program went back to its clean copy and a restart is queued"
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
send_mail "$DRONE@$DOMAIN" "doubles" "<run>create bank 8 allot
  $COMPANY bank 8 101 query drop bank 2@ d. 300 300 m* d.</run>"
# (match the whole reply, ignoring all whitespace, CR included: if the script
# pauses, its output comes in pieces)
wait_for 60 "a balance of 70,000 and 300 x 300 come out right as doubles" sh -c "docker compose logs --no-log-prefix game-engine | tr -d '[:space:]' | grep -q 'msg\[7000090000'"
wait_for 60 "drone finished the doubles script" idle

# House drop stations (#324 step 10): the drone, on the Moon, offers a unit of
# the metal it refined to the Moon Depot; when the game hour's batch clears,
# the station pays for it
send_mail "$DRONE@$DOMAIN" "station" "<run>1 2011 31 perform . cr</run>"
sold() { [ "$(sql "select count(*) from station_offers where drone=$DRONE_ID and side=1 and filled=1 and paid>0")" = 1 ]; }
wait_for 180 "the Moon Depot bought the drone's metal when its batch cleared" sold
[ "$(sql "select count(*) from station_batches where side=1")" -ge 1 ] || fail "no cleared batch recorded"
echo "ok: the batch was recorded once"
wait_for 60 "drone finished the station script" idle

[ -z "$(sql "select id from actions where drone=$DRONE_ID and aborted=1 and id <> $REFUSED_ACTION")" ] || fail "an action was aborted"
echo "ok: no action aborted"

for svc in game-engine events-engine app; do
    restarts=$(docker inspect -f '{{.RestartCount}}' "$(docker compose ps -q $svc)")
    [ "$restarts" = 0 ] || fail "$svc restarted $restarts times"
done
echo "ok: no service restarted"

# Public Forge (#324 step 6): switch email off and make the world
# invite-only, then open an account with an invitation and release a script
# on the website. The drone runs it, and its replies are kept for the
# website instead of being emailed.
GAME_EMAIL=0 INVITE_ONLY=1 docker compose up -d app game-engine events-engine >/dev/null 2>&1
wait_for 120 "sign-up page asks for an invitation" sh -c "curl -fsS http://localhost/register | grep -q 'Invitation code'"
CODE=$(docker compose exec -T app php artisan forge:invite 1 --note=smoke | tr -d '\r' | tail -1)
[ -n "$CODE" ] || fail "no invitation code"
JAR=$(mktemp)
token() { curl -fsS -c "$JAR" -b "$JAR" "http://localhost$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed -e 's/.*value="//' -e 's/"$//'; }
curl -fsS -c "$JAR" -b "$JAR" -o /dev/null -X POST http://localhost/register --data-urlencode "_token=$(token /register)" \
    --data-urlencode "invite_code=$CODE" -d name=Forger --data-urlencode email=forger@outside.test \
    -d password=forge-password -d password_confirmation=forge-password
[ -n "$(sql "select user_id from users where email='forger@outside.test'")" ] || fail "account not opened with the invitation"
echo "ok: account opened with an invitation"
curl -fsS -c "$JAR" -b "$JAR" -o /dev/null -X POST http://localhost/release --data-urlencode "_token=$(token /release)" \
    --data-urlencode "company_name=Smoke Forge" --data-urlencode $'script=: hello  ." forge-release-" ;\n: launched  hello ." ok7" ;'
FORGE_DRONE=$(sql "select a.agent_id from agents a join companies c on c.company_id = a.company_id where c.name='Smoke Forge'")
[ -n "$FORGE_DRONE" ] || fail "release did not create a company and drone"
echo "ok: release created a company with a drone"
released() { [ -n "$(sql "select id from drone_messages where agent_id=$FORGE_DRONE and body like '%forge-release-ok7%' and body like '%has finished%'")" ]; }
wait_for 90 "released drone ran launched; its reply is kept for the website" released
FORGE_NAME=$(sql "select name from agents where agent_id=$FORGE_DRONE")
wait_for 30 "the Release page shows the reply" sh -c "curl -fsS -b '$JAR' http://localhost/release | grep -q 'forge-release-ok7'"

# The House fuel seller (#324 step 10): the released drone, at Earth, orders
# 10 fuel from the Earth Exchange; at the batch it pays 5 a unit and gets it
FUEL_BEFORE=$(sql "select IFNULL(MAX(units), 0) from sqInventory where drone=$FORGE_DRONE and resource=2061")
sql "insert into commands (code, agent_id, created_at, updated_at, subject, email_content) values ('10 2061 32 perform .', $FORGE_DRONE, NOW(), NOW(), 'fuel', '')"
bought() { [ "$(sql "select count(*) from station_offers where drone=$FORGE_DRONE and side=2 and filled=10 and paid=50")" = 1 ]; }
wait_for 180 "a drone bought 10 fuel from the House at 5 a unit" bought
more_fuel() { [ "$(sql "select IFNULL(MAX(units), 0) from sqInventory where drone=$FORGE_DRONE and resource=2061")" -gt "$FUEL_BEFORE" ]; }
wait_for 60 "the fuel arrived in its hold" more_fuel
! docker compose logs mail | grep -q "from=<$FORGE_NAME@" || fail "the released drone sent email"
echo "ok: no email from the released drone"

# A drone that never refuels dies (#324 step 8): release one that only
# computes, leave it 1 unit of fuel, and it runs dry and becomes a wreck
curl -fsS -c "$JAR" -b "$JAR" -o /dev/null -X POST http://localhost/release --data-urlencode "_token=$(token /release)" \
    --data-urlencode "company_name=Dry Forge" --data-urlencode 'script=: launched  begin 0 until ;'
DRY=$(sql "select a.agent_id from agents a join companies c on c.company_id = a.company_id where c.name='Dry Forge'")
[ -n "$DRY" ] || fail "release did not create the dry drone"
KIT=$(sql "select mass from resources where id=5901")
sql "insert into events (event_type, action, logged, outcome, drone, resource, installed, locked, new_quantity, new_cargo)
     values (2, 0, 1, 1, $DRY, 2061, 0, 0, 1, $KIT + 1)"
dry() { [ "$(sql "select wreck_cause from agents where agent_id=$DRY")" = fuel ]; }
wait_for 120 "a drone that runs out of fuel becomes a wreck" dry

# Test world (#324 step 7): start its engines and turn on the Test button,
# then test a script. It runs in the test world's own database, with a faster
# clock, and the live world gets nothing.
TEST_WORLD=1 GAME_EMAIL=0 INVITE_ONLY=1 docker compose --profile testworld up -d app test-game-engine test-events-engine >/dev/null 2>&1
wait_for 120 "the Release page has a Test button" sh -c "curl -fsS -b '$JAR' http://localhost/release | grep -q 'release/test'"
curl -fsS -c "$JAR" -b "$JAR" -o /dev/null -X POST http://localhost/release/test --data-urlencode "_token=$(token /release)" \
    --data-urlencode $'script=: hello  ." test-world-" ;\n: launched  hello ." ok8" ;'
sqltest() {
    docker compose exec -T db sh -c 'mysql -N -uexmachinis -p"$MYSQL_PASSWORD" exmachinis_test 2>/dev/null' <<<"$1"
}
tested() { [ -n "$(sqltest "select id from drone_messages where body like '%test-world-ok8%' and body like '%has finished%'")" ]; }
wait_for 90 "a test run runs in the test world" tested
[ -z "$(sql "select company_id from companies where name like 'Test %'")" ] || fail "a test run reached the live world"
echo "ok: the live world is untouched"
wait_for 30 "the Release page shows the test run" sh -c "curl -fsS -b '$JAR' http://localhost/release | grep -q 'test-world-ok8'"

echo "Smoke test passed."
