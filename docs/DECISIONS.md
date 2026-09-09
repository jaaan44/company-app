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

---

## Template for Future Decisions

```
### DEC-XXX — <short title>
**Date:** YYYY-MM-DD
**Status:** ACCEPTED | SUPERSEDED by DEC-YYY | REJECTED
**Decision:** <what was decided>
**Rationale:** <why>
```
