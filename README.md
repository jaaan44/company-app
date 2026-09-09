# Company App

Internal company operations and communication platform: staff, clients, projects, tasks, work logs, leave management, scheduling, messaging, service reports, incident reports, announcements, and administrative reporting — unified under permission-based access control.

**Stack:** Laravel (API + Admin Backoffice) · Flutter (staff mobile app) · relational database · queues (framework-default for now; Redis is an upgrade path, not a default — see `docs/02_ARCHITECTURE.md` §0/§6).

## Project Status

Phase 1 (Project Bootstrap) is complete: a clean Laravel application (`apps/api`) and a minimal Flutter application shell (`apps/mobile`) exist, with no Company App business functionality implemented yet.

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

Checks: `composer validate --strict` · `vendor/bin/pint --test` · `php artisan test`

### Mobile (`apps/mobile`)

```sh
cd apps/mobile
flutter pub get
flutter run
```

Checks: `dart format --output=none --set-exit-if-changed .` · `flutter analyze` · `flutter test`

No business features exist in either app yet — see `docs/ROADMAP.md` for what's planned and `docs/CURRENT_STATE.md` for what's authorized next.
