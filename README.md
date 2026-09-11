# Company App

Internal company operations and communication platform: staff, clients, projects, tasks, work logs, leave management, scheduling, messaging, service reports, incident reports, announcements, and administrative reporting — unified under permission-based access control.

**Stack:** Laravel (API + Admin Backoffice) · Flutter (staff mobile app) · relational database · queues (framework-default for now; Redis is an upgrade path, not a default — see `docs/02_ARCHITECTURE.md` §0/§6).

## Project Status

Phase 4A (Docker Development Environment) is complete: Docker Compose (`nginx` + `app`/PHP-FPM + `mysql`) is now the standard way to run the backend locally, on top of Phase 4's Authentication (session/cookie login for the Admin Backoffice, Sanctum bearer tokens for the mobile API, account states enforced centrally). No RBAC, Staff Management, or other business functionality implemented yet — see `docs/DECISIONS.md` DEC-022 through DEC-027 and `docs/handoffs/V1_PHASE_04A_HANDOFF.md`.

For current status, always check `docs/CURRENT_STATE.md` — it is kept accurate and up to date; this README is not.

## Repository Structure

```
docker-compose.yml — standard local backend dev environment (nginx + app + mysql)
docker/     — Dockerfile, entrypoint, nginx vhost config
apps/
  api/      — Laravel backend/API (and, eventually, the Admin Backoffice)
  mobile/   — Flutter staff mobile app
docs/       — governance, architecture, and process documentation
CLAUDE.md   — operating rules for AI-assisted development
```

## Development Process

Company App is built through explicitly authorized, numbered phases. The repository — not conversation history — is the source of truth for what has been built, what's in progress, and what's next. See `docs/ROADMAP.md` for the full phase plan.

**If you are an AI assistant (or a new developer) picking this up:** read `CLAUDE.md` first, then `docs/CURRENT_STATE.md`, then `docs/ROADMAP.md`.

## Documentation Map

| Document | Purpose |
|---|---|
| `CLAUDE.md` | Operating rules for AI sessions working on this repo |
| `docs/00_PROJECT_CHARTER.md` | What this project is and its guiding principles |
| `docs/01_PRODUCT_REQUIREMENTS.md` | Product scope, modules, roles, permissions (provisional) |
| `docs/02_ARCHITECTURE.md` | System architecture direction |
| `docs/03_DATABASE_MODEL.md` | Conceptual data model (no migrations yet) |
| `docs/04_API_CONVENTIONS.md` | Future API design principles |
| `docs/05_SECURITY_MODEL.md` | Security strategy |
| `docs/06_UI_UX_GUIDELINES.md` | UI/UX principles for mobile and admin |
| `docs/ROADMAP.md` | Full phase sequence |
| `docs/CURRENT_STATE.md` | **Live** project status — read this first |
| `docs/DECISIONS.md` | Decision ledger (DEC-XXX) |
| `docs/CHANGELOG.md` | Repository change history |
| `docs/phases/` | Per-phase specifications |
| `docs/handoffs/` | Per-phase completion reports |
| `docs/testing/` | Test plan, test status, UAT log |

## Getting Started

### Prerequisites

- Docker + Docker Compose (standard path — see "Backend (Docker)" below), **or** PHP 8.3+ and Composer directly (backend requires `apps/api/composer.json`'s `^8.3`; developed against PHP 8.4.19)
- Node.js + npm (for the backend's default Vite/Tailwind frontend tooling, direct-install path only)
- Flutter stable SDK (developed against 3.47.2 / Dart 3.13.2) — always installed directly; Flutter is never Dockerized

### Backend (Docker — standard, as of Phase 4A)

```sh
cd apps/api
cp .env.docker.example .env
cd ..
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app composer install       # first run only — populates the vendor/ named volume
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed --class="Database\Seeders\AdminUserSeeder"  # local dev only — admin@example.test / password
```

Once up, `http://localhost:8012/api/v1/health` and `http://localhost:8012/login` are reachable through Nginx. Lifecycle: `docker compose up -d` / `down` / `ps` / `logs`. Run any backend command via `docker compose exec app <command>`, e.g. `docker compose exec app php artisan test`, `docker compose exec app vendor/bin/pint --test`. See `docs/handoffs/V1_PHASE_04A_HANDOFF.md` for full detail (MySQL connection info, volume strategy, troubleshooting).

Both host ports are configurable if the defaults are already taken on your machine: `APP_PORT` (application, default `8012`) and `MYSQL_PORT` (MySQL, default `3347` — for host tools like MySQL Workbench only; Laravel's own container-to-container connection always uses `DB_HOST=mysql`/`DB_PORT=3306` regardless). Export them, or set them in a root-level `.env`, before `docker compose up` — e.g. `APP_PORT=8080 MYSQL_PORT=3307 docker compose up -d --build`.

### Backend (direct install — still supported)

```sh
cd apps/api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed --class="Database\Seeders\AdminUserSeeder"  # local dev only — admin@example.test / password
php artisan serve
```

Checks (either path — prefix with `docker compose exec app` for Docker): `composer validate --strict` · `vendor/bin/pint --test` · `vendor/bin/phpstan analyse` · `php artisan test`

Once running, `GET /api/v1/health` returns `{"data": {"status": "ok", "timestamp": "..."}}` — the versioned API foundation, not a business endpoint. Authentication (Phase 4): visit `/login` for the Admin Backoffice, or use `POST /api/v1/auth/login` for the mobile API — see `docs/handoffs/V1_PHASE_04_HANDOFF.md` for full manual-verification steps.

### Mobile (`apps/mobile`)

Flutter always runs directly on the host/emulator/device — never in Docker.

```sh
cd apps/mobile
flutter pub get
flutter run --dart-define=API_BASE_URL=http://localhost:8012/api/v1
```

`API_BASE_URL` examples by target, against the standard Docker backend (port `8012`; see "Backend (Docker)" above — adjust if you overrode `APP_PORT`):
- Desktop/web: `http://localhost:8012/api/v1`
- Android emulator: `http://10.0.2.2:8012/api/v1` (the emulator's alias for the host machine)
- Physical device on the same LAN: `http://<your-machine's-LAN-IP>:8012/api/v1`

Running the backend via direct install instead (`php artisan serve`, port `8000`)? Omit `--dart-define` entirely — `AppConfig.apiBaseUrl`'s built-in default already points at `http://localhost:8000/api/v1` (`lib/core/config/app_config.dart`, unchanged since Phase 3). The `--dart-define` mechanism itself is what makes both cases possible without touching application code — see DEC-021.

Checks: `dart format --output=none --set-exit-if-changed .` · `flutter analyze` · `flutter test`

The app starts on a login screen (Phase 4); sign in with an account seeded via `AdminUserSeeder` above (or any account created via Tinker) to reach the authenticated placeholder shell.

## Quality Gates / CI

GitHub Actions runs the exact checks above on every pull request targeting `main` and every push to `main` — `.github/workflows/backend-ci.yml` and `.github/workflows/mobile-ci.yml`, each scoped (via `paths:`) to run only when its own app changes. CI runs directly on the runner, not via Docker — a single PHP version and a single Flutter version, no build matrix, no Android/iOS artifact builds, matching the resource-efficiency direction in `docs/02_ARCHITECTURE.md` §0. `CLAUDE.md` §5 is the authoritative list of commands; this section and CI both mirror it.

## Local Development

Docker Compose (`nginx` + `app`/PHP-FPM + `mysql`) is the standard local backend environment as of Phase 4A (DEC-027, superseding DEC-013) — see "Backend (Docker)" above and `docs/handoffs/V1_PHASE_04A_HANDOFF.md`. Direct install remains fully supported for a developer who prefers it. Flutter is never Dockerized — the SDK installed directly is the only path.

No business features exist in either app yet — see `docs/ROADMAP.md` for what's planned and `docs/CURRENT_STATE.md` for what's authorized next.
