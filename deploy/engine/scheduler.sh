#!/bin/sh
# Replaces the old cron jobs: calls the website's tick every few seconds
# (leaderboard snapshots and the launch queue) and clears old engine logs
# daily (housekeeping.sh). There is no email to check (#367 step 5).
INTERVAL="${TICK_SECONDS:-5}"
URL="http://app/tick/${CRON_KEY}"
last_cleanup=""

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
