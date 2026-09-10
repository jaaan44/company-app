# Company App

Internal company operations and communication platform: staff, clients, projects, tasks, work logs, leave management, scheduling, messaging, service reports, incident reports, announcements, and administrative reporting — unified under permission-based access control.

**Stack:** Laravel (API + Admin Backoffice) · Flutter (staff mobile app) · relational database · queues (framework-default for now; Redis is an upgrade path, not a default — see `docs/02_ARCHITECTURE.md` §0/§6).

## Project Status

Phase 3 (Core Architecture) is complete: `/api/v1` routing with a health endpoint, MySQL recorded as the production database direction, a numeric-ID + ULID public-ID identifier convention, Blade + Livewire as the Admin Backoffice direction, and a maintainable Flutter foundation (`app/`, `core/`, `features/`). No Company App business functionality implemented yet.

For current status, always check `docs/CURRENT_STATE.md` — it is kept accurate and up to date; this README is not.

## Repository Structure

```
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

- PHP 8.3+ and Composer (backend requires `apps/api/composer.json`'s `^8.3`; developed against PHP 8.4.19)
- Node.js + npm (for the backend's default Vite/Tailwind frontend tooling)
- Flutter stable SDK (developed against 3.47.2 / Dart 3.13.2)

### Backend (`apps/api`)

```sh
cd apps/api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Checks: `composer validate --strict` · `vendor/bin/pint --test` · `vendor/bin/phpstan analyse` · `php artisan test`

Once running, `GET /api/v1/health` returns `{"data": {"status": "ok", "timestamp": "..."}}` — the versioned API foundation, not a business endpoint.

### Mobile (`apps/mobile`)

```sh
cd apps/mobile
flutter pub get
flutter run
```

Checks: `dart format --output=none --set-exit-if-changed .` · `flutter analyze` · `flutter test`

## Quality Gates / CI

GitHub Actions runs the exact checks above on every pull request targeting `main` and every push to `main` — `.github/workflows/backend-ci.yml` and `.github/workflows/mobile-ci.yml`, each scoped (via `paths:`) to run only when its own app changes. No Docker, no build matrix, no Android/iOS artifact builds — a single PHP version and a single Flutter version, matching the resource-efficiency direction in `docs/02_ARCHITECTURE.md` §0. `CLAUDE.md` §5 is the authoritative list of commands; this section and CI both mirror it.

## Local Development

No Docker is used by default — PHP, Composer, Node.js, and the Flutter SDK installed locally are sufficient for this project's size (~100 users). See DEC-013 in `docs/DECISIONS.md` for rationale; this can be revisited if a real need emerges.

No business features exist in either app yet — see `docs/ROADMAP.md` for what's planned and `docs/CURRENT_STATE.md` for what's authorized next.
