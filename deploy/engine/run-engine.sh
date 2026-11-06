#!/bin/sh
# Run one engine in the foreground. Its log file is also copied to stdout so
# it shows in `docker compose logs`.
#
# The game engine can run as ENGINE_SHARDS processes (#433), each taking its
# share of the drones (ENGINE_SHARD 0 to ENGINE_SHARDS - 1). If any of them
# stops, the others are stopped too and the container exits, so Docker
# restarts the whole set.
set -e
ENGINE="$1"
LOG="$PLAT_HOME/log/$ENGINE.log"
touch "$LOG"
tail -n 0 -F "$LOG" 2>/dev/null &
SHARDS=${ENGINE_SHARDS:-1}
if [ "$ENGINE" != game-engine ] || [ "$SHARDS" -le 1 ]; then
    exec "$PLAT_HOME/bin/$ENGINE"
fi
pids=""
k=0
while [ "$k" -lt "$SHARDS" ]; do
    ENGINE_SHARD=$k "$PLAT_HOME/bin/$ENGINE" &
    pids="$pids $!"
    k=$((k + 1))
done
trap 'kill $pids 2>/dev/null' TERM INT
# Wait until one of them stops, then stop the rest
while :; do
    for p in $pids; do
        if ! kill -0 "$p" 2>/dev/null; then
            echo "run-engine: game engine process $p stopped: stopping the others"
            kill $pids 2>/dev/null || true
            exit 1
        fi
    done
    sleep 5
done
