# Company App

Internal company operations and communication platform: staff, clients, projects, tasks, work logs, leave management, scheduling, messaging, service reports, incident reports, announcements, and administrative reporting — unified under permission-based access control.

**Planned stack:** Laravel (API + Admin Backoffice) · Flutter (staff mobile app) · relational database · Redis (queue/cache/broadcast).

## Project Status

This project is in **Phase 0 (Project Definition & Development Governance)**. No application code exists yet — this repository currently contains only the governance and planning documentation required before implementation begins.

For current status, always check `docs/CURRENT_STATE.md` — it is kept accurate and up to date; this README is not.

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

There is no application to run yet. Once Phase 1 (Project Bootstrap) is authorized and complete, this section will document how to set up the Laravel and Flutter projects locally.
