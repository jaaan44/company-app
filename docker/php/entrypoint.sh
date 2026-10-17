#!/bin/sh
set -e

# Local development only (DEC-070, after the staging incident of
# 2026-10-17): refuse to start on a staging or production host. A staging
# checkout has .env.staging at its root and APP_ENV=staging in
# apps/api/.env; this image must never run against either.
refuse() {
    echo "REFUSING TO START: $1" >&2
    echo "This is the LOCAL DEVELOPMENT container (docker-compose.yml)." >&2
    echo "On the staging host use docker-compose.staging.yml - see docs/DEPLOYMENT_STAGING.md." >&2
    exit 1
}
if [ -f /repo-root/.env.staging ]; then
    refuse "this checkout has .env.staging, so it is the staging host."
fi
if [ -f .env ] && grep -Eq '^APP_ENV=("?)(staging|production)' .env; then
    refuse "apps/api/.env says APP_ENV is staging or production."
fi

# The application directory is bind-mounted from the host, whose
# ownership/permissions vary across Windows/macOS/Linux and essentially
# never match the container's php-fpm user (www-data). Rather than try to
# align UIDs across three host platforms, grant write access to
# "other" for these two directories specifically — not a blanket chmod
# of the whole application, and not 777 (no execute bit for plain files,
# via the capital X) — which is sufficient regardless of which UID owns
# the bind-mounted files (see docs/handoffs/V1_PHASE_04A_HANDOFF.md
# §Permissions).
if [ -d storage ] && [ -d bootstrap/cache ]; then
    chmod -R a+rwX storage bootstrap/cache 2>/dev/null || true
fi

exec "$@"
