# Phase 4A — Docker Development Environment — Specification

**Status:** COMPLETE
**Depends on:** Phase 4 (Authentication)

## Objective

Establish Docker Compose as the standard local backend development environment — `nginx` + `app` (PHP-FPM) + `mysql` — so contributors develop against the same MySQL engine the production direction (DEC-016) actually targets, while keeping the stack lean and appropriate for a ~100-employee internal tool. Flutter stays outside Docker entirely.

## In Scope

- `docker-compose.yml` (repo root) defining exactly three services: `nginx`, `app`, `mysql`.
- `docker/php/Dockerfile` — a minimal PHP-FPM 8.4 image with only the extensions Laravel/MySQL actually need, plus Composer.
- `docker/nginx/default.conf` — a conventional Laravel vhost.
- Named volume for MySQL data persistence; a named volume isolating the container's `vendor/` from the host bind mount.
- `apps/api/.env.docker.example` — Docker-specific environment example (MySQL instead of SQLite).
- Verification that migrations, the Admin seeder, the Admin login route, `/api/v1/health`, and the full existing automated Authentication test suite all work unchanged against this environment.
- Documentation: this file, the handoff, README/CLAUDE.md/CURRENT_STATE.md/CHANGELOG.md/02_ARCHITECTURE.md/03_DATABASE_MODEL.md/DECISIONS.md/testing docs/ROADMAP.md updates, and DEC-027 formally superseding DEC-013.

## Explicitly Out of Scope

- Phase 5 (Roles & Permissions) or any other business functionality.
- Dockerizing Flutter — it continues to run on the host/emulator/device.
- Redis, a queue worker container, a scheduler container, a WebSocket server, Mailpit, phpMyAdmin, Elasticsearch, a Node container, Kubernetes, or any other always-running service not demonstrably required today.
- Replacing or Dockerizing GitHub Actions CI — it remains SQLite-based per DEC-015, unchanged by this phase.
- Any change to Authentication's business behavior (Phase 4) — this phase only verifies it keeps working under Docker/MySQL.
- Production deployment tooling/orchestration — this is a local development environment only.

## Relevant Documentation

- `docs/02_ARCHITECTURE.md` §0 (resource-efficiency), §11 (dev environment/CI), new Docker subsection
- `docs/03_DATABASE_MODEL.md` (MySQL now used for local dev, not just production direction)
- `docs/DECISIONS.md` DEC-013 (superseded), DEC-016 (MySQL direction), DEC-027 (this phase)
- `docs/handoffs/V1_PHASE_04_HANDOFF.md` (what Authentication must keep working)

## Acceptance Criteria

See the governing Phase 4A instructions' full acceptance-criteria list — all satisfied; see `docs/handoffs/V1_PHASE_04A_HANDOFF.md` for the evidence, including genuine Docker runtime validation actually performed in this session (container build, stack startup, MySQL healthcheck, Laravel↔MySQL connectivity, migrations, the full Authentication test suite, and `/api/v1/health`/`/login` through Nginx) and an explicit account of the one sandbox-network limitation encountered (package installation during image build) and how it was worked around for validation purposes without weakening the committed Dockerfile.

## Testing Expectations

- All existing backend quality gates (`composer validate --strict`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test`) must pass both on the host and inside the Docker `app` container.
- `docker compose config` must validate without error.
- Genuine container build/startup, MySQL healthcheck, and end-to-end HTTP verification through Nginx, wherever the environment permits — with any genuinely unrunnable step named honestly rather than assumed to work.

## Notes

- MySQL development credentials (`company_app` / `secret`) are hardcoded in `docker-compose.yml` and mirrored in `.env.docker.example` — clearly local-only, never production secrets, matching the same honesty standard as `AdminUserSeeder`'s credentials (Phase 4).
- This phase surfaced and fixed two real bugs during genuine runtime validation (not left as "should work" assumptions): a `chmod ug+rwX` in the entrypoint that didn't actually grant the container's `www-data` PHP-FPM process write access (fixed to `a+rwX`, scoped to `storage`/`bootstrap/cache` only — not the whole application), and a compose `env_file` directive that silently defeated `phpunit.xml`'s testing-environment overrides by pre-setting OS-level environment variables PHPUnit's `<env>` config won't force-replace (removed — Laravel already reads `apps/api/.env` directly via the bind mount, no `env_file` needed).
