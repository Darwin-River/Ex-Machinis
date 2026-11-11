#!/usr/bin/env bash
#
# Deploys the latest `main` to this server (#575, SOP AD-03): back up, pull,
# rebuild, restart, and check the result.
#
# GitHub Actions runs it after CI passes on `main` (.github/workflows/deploy.yml),
# through an SSH key that can run this script and nothing else; see
# documents/hosting.md, "Automatic updates". You can also run it by hand:
#
#   /opt/exmachinis/deploy/auto-deploy.sh            # deploy if main moved
#   /opt/exmachinis/deploy/auto-deploy.sh --force    # rebuild even if not
#
# It stops at the first problem and says what to do. It never deploys over
# local changes, or while the checkout is off `main` (after a rollback by
# hand): `git checkout main` turns automatic deploys back on.
set -euo pipefail

cd "$(dirname "$0")/.."
REPO="$(pwd)"
LOG_DIR="${DEPLOY_LOG_DIR:-/var/backups/exmachinis}"

# Through the SSH key, the command GitHub sends arrives here; only "force" means anything
FORCE=""
[ "${1:-}" = "--force" ] && FORCE=1
[ "${SSH_ORIGINAL_COMMAND:-}" = "force" ] && FORCE=1

# The backups folder (documents/hosting.md, "Back up the game"), or the home folder before it exists
if ! { mkdir -p "$LOG_DIR" 2>/dev/null && [ -w "$LOG_DIR" ]; }; then
    LOG_DIR="$HOME/exmachinis-deploy"
    mkdir -p "$LOG_DIR"
fi
exec > >(tee -a "$LOG_DIR/deploy.log") 2>&1

say() { echo "deploy: $*"; }
ROLLBACK=""
die() {
    echo "deploy: FAILED: $*"
    [ -z "$ROLLBACK" ] || echo "deploy: to go back to the version that was running: cd $REPO && $ROLLBACK (documents/hosting.md, \"If an update goes wrong\")"
    exit 1
}

# One deploy at a time
exec 9>"$LOG_DIR/deploy.lock"
flock -n 9 || die "another deploy is running; try again when it has finished"

say "$(date -u '+%Y-%m-%d %H:%M UTC') in $REPO"

branch="$(git rev-parse --abbrev-ref HEAD)"
[ "$branch" = "main" ] || die "the checkout is on '$branch', not main (a rollback by hand?). When the fix is on main: cd $REPO && git checkout main"
if ! git diff --quiet || ! git diff --cached --quiet; then
    die "there are local changes in $REPO (git status shows them); commit them through a pull request or undo them"
fi

git fetch --quiet origin main
old="$(git rev-parse HEAD)"
new="$(git rev-parse origin/main)"
if [ "$old" = "$new" ] && [ -z "$FORCE" ]; then
    say "already at ${new:0:7}: nothing to deploy"
    exit 0
fi

trap 'die "a step failed (line $LINENO). Logs: cd $REPO/deploy && docker compose logs --tail 100 app game-engine events-engine migrate"' ERR

say "1/5 backing up"
"$REPO/deploy/backup.sh"

say "2/5 updating ${old:0:7} -> ${new:0:7}:"
git log --oneline "$old..$new" | sed 's/^/    /'
ROLLBACK="git checkout ${old:0:12} && cd deploy && docker compose up -d --build"
git merge --ff-only --quiet origin/main

say "3/5 rebuilding and restarting (containers whose code didn't change keep running)"
cd "$REPO/deploy"
docker compose up -d --build --remove-orphans

say "4/5 waiting for the database update (migrate)"
for _ in $(seq 1 60); do
    state="$(docker compose ps -a --format '{{.State}} {{.ExitCode}}' migrate | head -1)"
    case "$state" in exited*) break ;; esac
    sleep 5
done
case "$state" in
    "exited 0") ;;
    exited*) die "migrate failed ($state): docker compose logs migrate. The schema may be half-updated: restore the backup above before rolling back (hosting.md, \"Restore from a backup\")" ;;
    *) die "migrate didn't finish within 5 minutes ($state)" ;;
esac
docker compose logs migrate | grep -q "migrate: game data loaded" || die "migrate exited without loading the game data: docker compose logs migrate"

say "5/5 checking every service stays up"
sleep "${DEPLOY_SETTLE_SECONDS:-20}"
for service in db app game-engine events-engine scheduler caddy; do
    docker compose ps --status running --services | grep -qx "$service" || die "$service isn't running: docker compose logs --tail 100 $service"
done

docker image prune -f >/dev/null || true
trap - ERR
say "deployed ${new:0:7}"
