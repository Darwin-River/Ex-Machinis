#!/usr/bin/env bash
#
# End-to-end check of the Docker deployment (used by CI). Starts the stack
# for the fake domain example.test over plain HTTP, then checks that:
#   - the website answers,
#   - the scheduler's mail checks succeed,
#   - emailing registrar@ with subject "magica" registers a player,
#   - a drone runs an emailed FORTH command and the engine emails the reply.
# Needs ports 80, 443 and 25 free. Leaves the stack running; remove it with
#   docker compose down -v && rm -rf .env mail/config
set -euo pipefail
cd "$(dirname "$0")"

DOMAIN=example.test
PLAYER=player@outside.test

[ -f .env ] && { echo "deploy/.env already exists; run this on a clean checkout." >&2; exit 1; }
SITE_ADDRESS=http://localhost ./setup.sh "$DOMAIN" "admin@$DOMAIN"

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
wait_for 120 "mail check succeeds" sh -c "docker compose logs app | grep -q 'GET /get-mails/[^ ]* HTTP/1.1\" 200'"
wait_for 60 "mail server accepts connections" python3 -c "import smtplib; smtplib.SMTP('127.0.0.1', 25, timeout=5).quit()"

send_mail "registrar@$DOMAIN" magica "sign me up"
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
send_mail "$DRONE@$DOMAIN" "unpack" "<run>25901 perform . cr</run>"
wait_for 90 "starter kit unpacked (engine in cargo)" has 5001

send_mail "$DRONE@$DOMAIN" "to the moon" "<run>
  : sq dup * ;
  7 sq . cr
  10 5001 perform . cr
</run>"
wait_for 90 "drone ran a multi-line program" sh -c "docker compose logs game-engine | grep -q 'result \[ 49'"
at_moon() { [ "$(sql "select object_id from agents where agent_id=$DRONE_ID")" = 10 ]; }
wait_for 120 "drone flew to the Moon" at_moon

send_mail "$DRONE@$DOMAIN" "mine" "<run>11100 perform . cr</run>"
wait_for 120 "drone mined kunspar on the Moon" has 1100
[ -z "$(sql "select id from actions where drone=$DRONE_ID and aborted=1")" ] || fail "an action was aborted"
echo "ok: no action aborted"

for svc in game-engine events-engine app; do
    restarts=$(docker inspect -f '{{.RestartCount}}' "$(docker compose ps -q $svc)")
    [ "$restarts" = 0 ] || fail "$svc restarted $restarts times"
done
echo "ok: no service restarted"

echo "Smoke test passed."
