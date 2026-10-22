#!/bin/sh
# Replaces the old cron jobs: checks the game's mailboxes every few seconds
# (start_email_handler.sh) and clears old engine logs daily (housekeeping.sh).
INTERVAL="${MAIL_CHECK_SECONDS:-5}"
URL="http://app/get-mails/${CRON_KEY}"
last_cleanup=""

echo "scheduler: checking mail every ${INTERVAL}s"
while :; do
    if ! out=$(curl -fsS -m 120 "$URL" 2>&1); then
        echo "$(date '+%F %T') mail check failed: $out"
    fi

    today=$(date +%F)
    if [ "$today" != "$last_cleanup" ]; then
        find "$PLAT_HOME/log" -type f -mtime +7 -delete
        last_cleanup="$today"
    fi

    sleep "$INTERVAL"
done
