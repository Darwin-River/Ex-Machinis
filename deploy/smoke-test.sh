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

# A multi-line program that starts a move (the docs' "travel to Mars" step)
send_mail "$DRONE@$DOMAIN" "smoke test 2" "<run>
  : sq dup * ;
  7 sq . cr
  4 5 perform . cr
</run>"
wait_for 90 "drone ran a multi-line program" sh -c "docker compose logs game-engine | grep -q 'result \[ 49'"
DRONE_ID=$(sql "select agent_id from agents where name='$DRONE'")
action_queued() { [ -n "$(sql "select id from actions where drone=$DRONE_ID and protocol=5")" ]; }
wait_for 60 "move was queued" action_queued
sleep 15
[ "$(sql "select aborted from actions where drone=$DRONE_ID and protocol=5")" = 0 ] || fail "the move was aborted"

for svc in game-engine events-engine app; do
    restarts=$(docker inspect -f '{{.RestartCount}}' "$(docker compose ps -q $svc)")
    [ "$restarts" = 0 ] || fail "$svc restarted $restarts times"
done
echo "ok: no service restarted"

echo "Smoke test passed."
