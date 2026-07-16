#!/usr/bin/env bash
#
# PioDeploy — production deploy.
#
#   cd /var/www/piodeploy && sudo bash deploy/deploy.sh
#
# Pulls the branch, installs what actually changed, migrates, rebuilds every
# cache, restarts the worker and fixes ownership. Safe to re-run: with nothing
# to pull it just rebuilds caches.
#
# Override with env vars if the box is laid out differently, e.g.
#   APP_DIR=/srv/piodeploy BRANCH=main sudo -E bash deploy/deploy.sh

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/piodeploy}"
BRANCH="${BRANCH:-master}"
PHP="${PHP:-php}"
WEB_USER="${WEB_USER:-www-data}"

log()  { printf '\n\033[1;36m==>\033[0m %s\n' "$1"; }
warn() { printf '\033[1;33m  ! %s\033[0m\n' "$1"; }
fail() { printf '\n\033[1;31mFAILED:\033[0m %s\n' "$1" >&2; exit 1; }

[[ $EUID -eq 0 ]] || fail "Run as root: sudo bash deploy/deploy.sh"
cd "$APP_DIR" || fail "No app at $APP_DIR (set APP_DIR=...)"
[[ -f artisan ]] || fail "$APP_DIR is not a Laravel app (no artisan)."

# A deploy that dies before touching the database can safely come back up on
# the old code. One that dies mid-migration cannot: MySQL DDL is not
# transactional, so the schema is half-changed and serving it would be worse
# than the maintenance page. MIGRATED tracks which side of that line we are on.
MIGRATED=0

restore() {
    [[ -f storage/framework/down ]] || return 0

    if [[ $MIGRATED -eq 1 ]]; then
        fail "Migration did not complete — leaving the site DOWN on purpose.
  The schema may be half-applied. Inspect it, fix forward, then: $PHP artisan up"
    fi

    warn "Deploy did not finish — bringing the site back up on the old code"
    if ! $PHP artisan up; then
        fail "Could not bring the site back up. It is STILL DOWN. Run: $PHP artisan up"
    fi
}
trap restore EXIT INT TERM

# A hand-edit on the server survives every future deploy and silently drifts
# from the repo. --ff-only only catches it when the same file is incoming.
if ! git diff --quiet || ! git diff --cached --quiet; then
    fail "The working tree has local changes. Someone edited this server directly.
  Review with: git status && git diff
  Then stash or commit them before deploying."
fi

log "At $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"

BEFORE=$(git rev-parse HEAD)

# Down BEFORE the pull: from here until 'up', the code, the vendor tree and
# the schema are all inconsistent with each other. Serving that is exactly the
# 500 the maintenance page exists to prevent.
log "Maintenance mode on"
$PHP artisan down --retry=15

log "Pulling $BRANCH"
git pull --ff-only origin "$BRANCH"
AFTER=$(git rev-parse HEAD)

if [[ "$BEFORE" == "$AFTER" ]]; then
    warn "Already up to date — rebuilding caches anyway"
else
    log "Now at $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"
fi

# Only pay for a step when the commits actually touched it.
# The file list is materialised first: piping straight into `grep -q` lets
# grep exit on the first match, SIGPIPE kills git, and pipefail then reports
# 141 — indistinguishable from "no match", so the step would be skipped.
changed() {
    [[ "$BEFORE" != "$AFTER" ]] || return 1

    local files
    files=$(git diff --name-only "$BEFORE" "$AFTER")

    grep -qE "$1" <<<"$files"
}

# Composer plugins and npm lifecycle scripts execute arbitrary code from the
# dependency tree. This script runs as root; they do not need to.
as_app_user() {
    if [[ -n "${SUDO_USER:-}" && "${SUDO_USER}" != "root" ]]; then
        sudo -u "$SUDO_USER" -H "$@"
    else
        warn "No unprivileged user to drop to — running '$1' as root"
        "$@"
    fi
}

if changed '^composer\.(json|lock)$'; then
    log "Dependencies changed — composer install"
    as_app_user composer install --no-dev --optimize-autoloader --no-interaction
fi

if changed '^(package(-lock)?\.json|vite\.config\.js|tailwind\.config\.js|resources/(js|css)/)'; then
    log "Front-end changed — rebuilding assets"
    as_app_user npm ci --silent
    as_app_user npm run build
fi

log "Migrating"
MIGRATED=1
$PHP artisan migrate --force
MIGRATED=0

# route:cache is the one that gets forgotten: config: and view: caches do not
# touch it, so a newly added route keeps 404ing until this runs. Clear before
# cache, or a stale file can survive the rebuild.
log "Rebuilding caches (config, route, view)"
$PHP artisan config:clear && $PHP artisan config:cache
$PHP artisan route:clear  && $PHP artisan route:cache
$PHP artisan view:clear   && $PHP artisan view:cache

# Workers hold the old code in memory until told otherwise.
log "Restarting the queue worker"
$PHP artisan queue:restart

# artisan ran as root, so everything it just wrote is root-owned — php-fpm
# runs as $WEB_USER and must be able to write these back.
log "Fixing ownership"
chown -R "$WEB_USER:$WEB_USER" storage bootstrap/cache
if [[ -f storage/app/private/agent/PioDeployAgent.zip ]]; then
    chown "$WEB_USER:$WEB_USER" storage/app/private/agent/PioDeployAgent.zip
fi

log "Maintenance mode off"
$PHP artisan up
trap - EXIT

log "Deployed $(git rev-parse --short HEAD)"
$PHP artisan security:check || warn "security:check reported findings (above)"
