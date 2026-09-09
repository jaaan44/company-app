# DECISIONS — Architectural & Product Decision Ledger

Durable record of accepted decisions. Each entry is permanent once recorded — a superseded decision is marked `SUPERSEDED` and links to its replacement; it is never deleted or silently rewritten. New decisions get new, incrementing IDs.

---

### DEC-001 — Repository-based development memory
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Repository documentation is authoritative for project state. Conversation history is not authoritative.
**Rationale:** Enables reliable recovery of project state across sessions, developers, and AI context resets without dependency on chat history.

### DEC-002 — Phase-based development
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Development proceeds through explicitly authorized phases. A phase must be completed, reviewed, and tested as appropriate, and approved before the next phase begins.
**Rationale:** Prevents scope creep and uncontrolled speculative implementation; keeps each unit of work reviewable.

### DEC-003 — Laravel + Flutter architecture
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Laravel provides the backend/API and the Admin Backoffice. Flutter provides the staff mobile application.
**Rationale:** Given technology direction for the project; Laravel's ecosystem covers API + admin web needs, Flutter covers cross-platform mobile with a single codebase.

### DEC-004 — Granular authorization
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Authorization is permission-oriented rather than relying solely on hard-coded role checks.
**Rationale:** Roles alone don't scale to the operational nuance the product needs (e.g. a Supervisor's data visibility vs. an Administrator's). Permissions as the primitive, roles as bundles, keeps this flexible.

### DEC-005 — Location model
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** V1 favors explicit employee location/check-ins instead of continuous GPS tracking. Continuous tracking is outside the initial product direction.
**Rationale:** Simpler, more private, and sufficient for the intended use cases (client visit confirmation, field presence) without the privacy/battery/infrastructure burden of continuous tracking.

### DEC-006 — Tasks may exist independently
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Tasks may optionally belong to a project. Internal company tasks must be possible without creating artificial projects.
**Rationale:** Forcing every task into a project would create noise (fake "Internal" projects) and misrepresent the data model.

### DEC-007 — Simple messaging first
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** The initial messaging system remains lightweight. Do not attempt to reproduce Slack/Teams functionality in V1.
**Rationale:** Messaging is a supporting feature of an operations platform, not the product's core value; scope discipline avoids an open-ended chat-platform build.

### DEC-008 — Connected domain model
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Modules should not become isolated mini-applications. Staff, clients, projects, tasks, work logs, schedules, service reports, and incidents should have meaningful relationships where appropriate.
**Rationale:** Preserves the value of a unified operations platform over a collection of disconnected tools; supports reporting and cross-module workflows.

### DEC-009 — Auditability
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Important administrative and workflow actions should eventually have auditable histories.
**Rationale:** Required for accountability in an internal operations tool handling HR, incident, and administrative data.

### DEC-010 — Historical operational records
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Important state changes such as staff status, leave approvals, and incident progress should preserve history where appropriate rather than simply overwriting previous state.
**Rationale:** Operational and compliance value in knowing not just current state but how it got there; supports reporting and dispute resolution.

### DEC-011 — Monorepo structure: apps/api and apps/mobile
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Company App is developed as a single monorepo. The Laravel backend/API (and, eventually, the Admin Backoffice) lives under `apps/api`; the Flutter staff mobile app lives under `apps/mobile`. No monorepo orchestration tooling (Nx/Turborepo/Melos) is introduced — the two applications operate independently within the shared repository.
**Rationale:** Resolves the "monorepo vs polyrepo" open question from `02_ARCHITECTURE.md` §9 in favor of the suggested default, appropriate for a single team with tightly coupled release cadence at ~100-user scale. This decision fixes *structure and location* only — it does not decide the Admin Backoffice's rendering approach (Blade/Livewire/Inertia/SPA), which remains open.

### DEC-012 — Local bootstrap database: SQLite, production engine still open
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** The Laravel application's local development bootstrap uses SQLite (Laravel's own default for a fresh install). This is a local/dev convenience only. It does **not** resolve the PostgreSQL vs. MySQL open question recorded in `02_ARCHITECTURE.md` §9 for production — that choice remains deliberately open until a phase that genuinely requires it.
**Rationale:** Avoids silently converting an intentionally deferred architecture decision into a permanent one merely because a bootstrap step needed *some* working database driver. SQLite requires no separate service, matching the resource-efficiency direction for Phase 1.

### DEC-013 — No Docker by default for local development
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Company App's default local development workflow does not use Docker. PHP, Composer, Node.js, and the Flutter SDK installed directly on the developer's machine are sufficient and are what both apps have actually been built and validated against (Phases 1–2).
**Rationale:** At ~100 users, an always-running container layer adds idle resource overhead and setup complexity with no demonstrated need — both apps run cleanly without it. This does not forbid Docker later for a specific, demonstrated need (e.g. standardizing a chosen non-SQLite database across contributors); it only sets the default.

### DEC-014 — Static analysis: Larastan v3, level 5
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Backend static analysis uses Larastan (PHPStan for Laravel) v3, configured at `apps/api/phpstan.neon` scanning `app/` at level 5, with no ignored-error baseline.
**Rationale:** Level 5 is a realistic, moderate starting point for a currently near-empty codebase — strict enough to catch real classes of bugs, not so strict (e.g. max/9) that it would demand suppression rules or fight Eloquent's dynamic behavior before any real business logic exists. The level should be raised deliberately as the codebase matures, not lowered to make a failing check pass.

### DEC-015 — CI layout: two path-filtered GitHub Actions workflows
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** CI is two separate workflow files — `.github/workflows/backend-ci.yml` and `.github/workflows/mobile-ci.yml` — each triggered on pull requests targeting `main` and pushes to `main`, and each scoped via `paths:` to its own app directory (plus its own workflow file) so an unrelated app's changes don't trigger it. Single PHP version (8.4) and single Flutter version (3.47.2); no build matrix; no Android/iOS artifact builds.
**Rationale:** Matches the resource-efficiency direction (`02_ARCHITECTURE.md` §0) — fast, cheap CI appropriate for a ~100-user internal tool. Two small workflows were chosen over one workflow with two jobs for clearer independent history/status per app, without adding real complexity (no shared logic between them to justify combining).

---

## Template for Future Decisions

```
### DEC-XXX — <short title>
**Date:** YYYY-MM-DD
**Status:** ACCEPTED | SUPERSEDED by DEC-YYY | REJECTED
**Decision:** <what was decided>
**Rationale:** <why>
```
