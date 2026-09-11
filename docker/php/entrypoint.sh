#!/bin/sh
set -e

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
