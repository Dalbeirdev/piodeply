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

# A deploy that dies half-way must never leave customers on the maintenance
# page. This runs on any exit, including a failed migration.
restore() {
    if [[ -f storage/framework/down ]]; then
        warn "Deploy did not finish — bringing the site back up"
        $PHP artisan up || true
    fi
}
trap restore EXIT

log "At $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"

BEFORE=$(git rev-parse HEAD)
log "Pulling $BRANCH"
git pull --ff-only origin "$BRANCH"
AFTER=$(git rev-parse HEAD)

if [[ "$BEFORE" == "$AFTER" ]]; then
    warn "Already up to date — rebuilding caches anyway"
else
    log "Now at $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"
fi

# Only pay for a step when the commits actually touched it.
changed() {
    [[ "$BEFORE" != "$AFTER" ]] || return 1
    git diff --name-only "$BEFORE" "$AFTER" | grep -qE "$1"
}

if changed '^composer\.(json|lock)$'; then
    log "Dependencies changed — composer install"
    composer install --no-dev --optimize-autoloader --no-interaction
fi

if changed '^(package(-lock)?\.json|vite\.config\.js|tailwind\.config\.js|resources/(js|css)/)'; then
    log "Front-end changed — rebuilding assets"
    npm ci --silent
    npm run build
fi

log "Maintenance mode on"
$PHP artisan down --retry=15

log "Migrating"
$PHP artisan migrate --force

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
