#!/bin/sh
# Replaces the old cron jobs: calls the website's tick every few seconds
# (leaderboard snapshots and the launch queue) and clears old engine logs
# daily (housekeeping.sh). There is no email to check (#367 step 5).
# Once a real minute, apart from the tick, it calls the keeper, which
# rebuilds the dashboard and the stock exchange page (#738): a rebuild can
# take a minute or more, and the tick mustn't wait for it.
INTERVAL="${TICK_SECONDS:-5}"
URL="http://app/tick/${CRON_KEY}"
last_cleanup=""
KEEP_URL="http://app/keep/${CRON_KEY}"
KEEP_SECONDS="${KEEP_SECONDS:-60}"

keeper() {
    while :; do
        if ! out=$(curl -fsS -m 600 "$KEEP_URL" 2>&1); then
            echo "$(date '+%F %T') keep failed: $out"
        fi
        sleep "$KEEP_SECONDS"
    done
}
keeper &

# The House's orders, every HOUSE_SECONDS (#764): on the tick they lagged the
# market's half-day price steps by game hours
HOUSE_URL="http://app/house/${CRON_KEY}"
HOUSE_SECONDS="${HOUSE_SECONDS:-1}"
house() {
    while :; do
        if ! out=$(curl -fsS -m 60 "$HOUSE_URL" 2>&1); then
            echo "$(date '+%F %T') house failed: $out"
        fi
        sleep "$HOUSE_SECONDS"
    done
}
house &

echo "scheduler: ticking every ${INTERVAL}s"
while :; do
    if ! out=$(curl -fsS -m 120 "$URL" 2>&1); then
        echo "$(date '+%F %T') tick failed: $out"
    fi

    today=$(date +%F)
    if [ "$today" != "$last_cleanup" ]; then
        find "$PLAT_HOME/log" -type f -mtime +7 -delete
        last_cleanup="$today"
    fi

    sleep "$INTERVAL"
done
