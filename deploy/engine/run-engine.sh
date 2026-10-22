#!/bin/sh
# Run one engine in the foreground. Its log file is also copied to stdout so
# it shows in `docker compose logs`.
set -e
ENGINE="$1"
LOG="$PLAT_HOME/log/$ENGINE.log"
touch "$LOG"
tail -n 0 -F "$LOG" 2>/dev/null &
exec "$PLAT_HOME/bin/$ENGINE"
