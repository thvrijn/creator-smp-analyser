#!/usr/bin/env bash
# Deploys the app on the Raspberry Pi. Run it in the checkout on the Pi (/opt/docker/apps/creator-smp-analyser):
#
#   ./scripts/deploy.sh [commit]      default: the latest master
#
# The self-hosted runner on the Pi runs it after every push to master (.github/workflows/ci.yml), with that commit.
#   1. Fetches master (from DEPLOY_GIT_URL, default origin), checks out the commit and continues with that
#      version of this script.
#   2. Builds the image on the Pi (creatorsmp4-app:<commit>; Docker's layer cache keeps it quick), while the
#      old version keeps running.
#   3. Waits until no job runs, because a restart kills a running transcription, analysis or download
#      (at most DEPLOY_WAIT_MINUTES, default 60; FORCE=1 does not wait).
#   4. Backs up the database to backups/ (keeps DEPLOY_KEEP_BACKUPS, default 14) and runs the migrations.
#   5. Starts the new containers and waits until the app answers on /up.
# It never removes volumes: the database, uploads and audio are kept. Roll back with ./scripts/deploy.sh <older commit>
# (the migrations stay: they only add).
set -euo pipefail
cd "$(dirname "$0")/.."

if [ -z "${DEPLOY_CHECKED_OUT:-}" ]; then
    git fetch --quiet "${DEPLOY_GIT_URL:-origin}" master
    commit=$(git rev-parse --verify "${1:-FETCH_HEAD}^{commit}")
    git checkout --quiet --force --detach "$commit"
    DEPLOY_CHECKED_OUT=1 exec "$0" "$commit"
fi

commit="$1"
compose=(docker compose -f docker-compose.prod.yml)
image_repo="${DEPLOY_IMAGE_REPO:-creatorsmp4-app}"
export APP_IMAGE="$image_repo:$commit"
step() { printf '\n\033[1;33m==> %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mdeploy failed: %s\033[0m\n' "$*" >&2; exit 1; }

[ -f .env ] || fail ".env is missing: copy .env.production.example to .env and fill it in (see DEPLOY.md)"
env_value() { sed -n "s/^$1=//p" .env | tail -1; }
db_user=$(env_value DB_USERNAME); db_user=${db_user:-creatorsmp4}
db_name=$(env_value DB_DATABASE); db_name=${db_name:-creatorsmp4}
[ -n "$(env_value APP_KEY)" ] || fail "APP_KEY is empty in .env"
[ -n "$(env_value WORKER_TOKEN)" ] || fail "WORKER_TOKEN is empty in .env"

step "Deploying ${commit:0:7}: $(git log -1 --format=%s "$commit")"

docker network inspect proxy >/dev/null 2>&1 || fail "the Docker network 'proxy' (the reverse proxy's) does not exist"

# Built first: the old version keeps running (and its jobs keep going) while the Pi builds.
step "Building $APP_IMAGE"
"${compose[@]}" build app

postgres_running() { "${compose[@]}" ps --status running --services 2>/dev/null | grep -qx postgres; }
running_jobs() {
    "${compose[@]}" exec -T postgres psql -U "$db_user" -d "$db_name" -tAc \
        "SELECT string_agg(id::text, ', ') FROM streams WHERE 'processing' IN (transcription_status, event_extraction_status, video_download_status)" 2>/dev/null || true
}

if postgres_running && [ -z "${FORCE:-}" ]; then
    deadline=$(( $(date +%s) + ${DEPLOY_WAIT_MINUTES:-60} * 60 ))
    while running=$(running_jobs) && [ -n "$running" ]; do
        [ "$(date +%s)" -lt "$deadline" ] || fail "a job is still running for stream(s) $running; deploy again later, or with FORCE=1"
        echo "A job is running for stream(s) $running; waiting so the restart does not kill it..."
        sleep 30
    done
fi

if postgres_running; then
    step "Backing up the database"
    mkdir -p backups
    backup="backups/db-$(date +%Y%m%d-%H%M%S)-${commit:0:7}.sql.gz"
    "${compose[@]}" exec -T postgres pg_dump -U "$db_user" -d "$db_name" --no-owner | gzip > "$backup.tmp"
    mv "$backup.tmp" "$backup"
    echo "$backup ($(du -h "$backup" | cut -f1))"
    # shellcheck disable=SC2012 # our own file names, sorted by age
    ls -1t backups/db-*.sql.gz | tail -n +$(( ${DEPLOY_KEEP_BACKUPS:-14} + 1 )) | xargs -r rm --
fi

step "Running the migrations"
"${compose[@]}" up -d --wait postgres redis
"${compose[@]}" run --rm --no-deps app php artisan migrate --force
# Only the admin account (never DatabaseSeeder: that adds development players and streams).
# It does nothing when the account exists, so a changed password is kept.
"${compose[@]}" run --rm --no-deps app php artisan db:seed --class=AdminUserSeeder --force

step "Starting the new version"
if ! "${compose[@]}" up -d --remove-orphans --wait --wait-timeout 180; then
    "${compose[@]}" logs --tail=50 app queue >&2 || true
    fail "the new version did not start (logs above); roll back with ./scripts/deploy.sh <previous commit>"
fi
"${compose[@]}" ps

# Keep the current and the previous image for a quick rollback; older ones only take space.
docker images "$image_repo" --format '{{.Repository}}:{{.Tag}}' | grep -vx "$APP_IMAGE" | grep -v ':latest$' | tail -n +2 | xargs -r docker rmi >/dev/null 2>&1 || true
docker image prune -f >/dev/null

step "Deployed ${commit:0:7}"
