# Phase 14 — Announcements — Specification

**Status:** COMPLETE (pending user review) — see `docs/handoffs/V1_PHASE_14_HANDOFF.md`
**Depends on:** Phase 5 (Roles & Permissions), Phase 7 (Staff), Phase 6 (Organization Structure — Departments/Teams)

## Objective

Build the foundational Announcements module for internal company broadcast communication: Administrator-authored announcements with a draft/published/archived lifecycle, company-wide or Department/Team-scoped targeting, and a lightweight employee acknowledgement record. This is explicitly not direct messaging, chat, comments, notifications infrastructure, or a CMS — see Critical Domain Boundary below.

## Critical Domain Boundary

Announcements are internal broadcast content — one-directional, from an authorized author to an audience. They are never: direct messaging, group chat, comments/replies/discussion threads, reactions/likes, polls/surveys, a social feed, user-generated posts, email/SMS/push delivery, a notification queue, file/document management, a knowledge base, calendar/event data, tasks, or project activity. No hidden coupling is introduced to Messaging (Phase 16) or Notifications (Phase 15) — this phase ships no delivery mechanism beyond the API itself; an employee must open the app and query their feed.

## Announcement — Core Model

`announcements`: `public_id` (ULID, DEC-017), `title` (required, string ≤200), `body` (required, text ≤10,000 — plain text; no rich-text editor, no HTML sanitization subsystem, no Markdown rendering pipeline — a client may choose to render line breaks, nothing more), `status` (`App\Enums\AnnouncementStatus` — `draft`/`published`/`archived`), `audience_type` (`App\Enums\AnnouncementAudienceType` — `company_wide`/`scoped`), `published_at` (nullable timestamp, **server-controlled only** — set when the explicit `publish` action runs, never client-supplied, never re-set by a later edit), `created_by_user_id` (nullable, `nullOnDelete()` — accountability, mirrors `tasks.created_by_user_id`), `published_by_user_id` (nullable, `nullOnDelete()` — records which Administrator actually pushed it live, distinct from who drafted it, since the two can differ), timestamps.

**No priority/importance field, no scheduled future `publish_at`, no expiry.** None of these were required by the governing roadmap line ("Company-wide/scoped announcements, recipients, acknowledgements") or any technical document, and CLAUDE.md §3 prohibits speculative scope. Publishing is always immediate (`publish_at <= now()` is simply "now"); an announcement that should stop being current is archived explicitly, not auto-expired. If a future phase demonstrates a real need for priority or scheduled/expiring publication, it can be added without breaking this shape.

## Lifecycle

`App\Enums\AnnouncementStatus`: `draft` → `published` → `archived`. Three states, not two-with-a-separate-expiry-flag and not an overlapping `inactive`/`expired` concept — one status column is the single source of truth for "is this currently live." Transitions are explicit action endpoints, never a generic status `PATCH` (per `04_API_CONVENTIONS.md` and Phase 13's precedent):

- `draft` → `published`: `POST /api/v1/announcements/{public_id}/publish`. Only valid from `draft` (`409` otherwise — no re-publishing an already-published or archived announcement). Sets `published_at = now()` and `published_by_user_id` to the acting Administrator, both server-controlled.
- `published` → `archived`: `POST /api/v1/announcements/{public_id}/archive`. Only valid from `published` (`409` from `draft` — nothing to retire yet, delete it instead; `409` from `archived` — already archived).
- `archived` is terminal: no un-archive/republish endpoint. Correcting a mistakenly-archived announcement means creating a new one — the smallest coherent rule, mirroring Leave Management's "no edit; cancel and resubmit" precedent for a similarly terminal state.
- Every `Announcement` is created as `draft` — there is no create-and-publish-in-one-call shortcut. This keeps "publishing" a single, explicit, always-meaningful business action (CLAUDE.md's "explicit business action" instruction) rather than an implicit side effect of `POST /announcements`.

No `announcement_actions`-style history table (contrast Leave Management's `leave_request_actions`): unlike Leave's two-party Manager/requester approval decision, every Announcement transition has exactly one actor type (Administrator) and no decision content (no note/reason to preserve) — `status` plus `published_at`/`published_by_user_id`/`updated_at` already capture everything worth knowing. Adding a parallel history table here would be exactly the "workflow engine" CLAUDE.md's governing instructions warn against building without a demonstrated need.

## Drafts

Drafts exist because "hide future/unpublished content from ordinary employees" is a real, immediate requirement for a broadcast channel — you cannot safely compose a company-wide announcement in the same record employees can already query. A draft is visible only via the management endpoints (`announcements.manage`); it never appears in `/api/v1/me/announcements` or its detail endpoint, which only ever return `published` (non-archived) records. A draft may be freely edited (title/body/audience) and hard-deleted; publishing is the one-way door into employee visibility.

## Editing

**Option A chosen: an already-published Announcement may still be edited** (title/body/audience) — a typo fix or an audience correction after publishing is a normal, low-risk operation, unlike Leave Management's two-party approval record (where "editing a decision already made" is genuinely ambiguous). Editing never touches `status`/`published_at`/`published_by_user_id`/`created_by_user_id` — those are only ever changed by the lifecycle actions or creation itself. **An `archived` Announcement is immutable** — `PUT`/`PATCH` returns `409`; archived is a terminal historical record, matching the read-only treatment `rejected`/`cancelled` Leave Requests already receive in this codebase.

## Deletion vs. Archival

- A `draft` Announcement may be hard-deleted (`DELETE`) — nothing has been broadcast yet, so there is no history to preserve. No audience/acknowledgement rows can exist yet either (both are impossible before publication), so deletion is unconditional.
- A `published` or `archived` Announcement **cannot** be hard-deleted (`409`) — company communications, once actually sent, are preserved as history (DEC-009/DEC-010), the same "preserve, don't destroy" philosophy as every prior module's master data. Archiving is the correct tool for retiring one.

## Author / Accountability

`created_by_user_id` (who drafted it) and `published_by_user_id` (who actually published it, possibly a different Administrator) are both accountability metadata only, never authorization primitives — mirroring `tasks.created_by_user_id`/`work_logs.created_by_user_id`. Both are exposed in the resource as a minimal linked-Staff shape (`{public_id, employee_number, display_name}`, or `null` if the acting User has no linked Staff record, e.g. the seeded local Administrator) — mirroring `LeaveRequestResource.created_by`'s exact pattern, never raw `User` identity.

## Audience / Targeting

`App\Enums\AnnouncementAudienceType`: `company_wide` | `scoped`. This is a single-scope-type-per-announcement model (never both at once) — a `company_wide` announcement has no Department/Team rows at all; a `scoped` announcement targets one or more Departments and/or Teams via **union (OR) semantics**: Department A + Team B means anyone in Department A **or** Team B, never an intersection. This directly matches the governing instructions' explicit warning against accidentally implementing intersection semantics, and is the "likely useful minimum" candidate the instructions named for a ~100-person company.

**Schema:** two plain many-to-many pivots, not a polymorphic `announcement_audiences` table — `announcement_departments` (`announcement_id` `cascadeOnDelete()`, `department_id` `restrictOnDelete()`, composite primary key, no surrogate id/timestamps, mirroring `role_permissions`' "plain pivot" shape) and `announcement_teams` (identical shape against `teams`). Avoiding polymorphism here follows DEC-017/`03_DATABASE_MODEL.md`'s general preference for dedicated pivots when only two concrete target types exist and no third is in view.

**Visibility semantics:**
- `company_wide`: every Staff-linked User sees it, regardless of Department/Team.
- `scoped`: a Staff member sees it only if their **current** `department_id` matches one of the targeted Departments, or their **current** `team_id` matches one of the targeted Teams (union, not intersection).
- A Staff member with no Department and no Team can only ever see `company_wide` announcements — there is nothing for a `scoped` announcement to match.
- Manager status grants no extra Announcement visibility by itself — a Manager sees exactly the same feed as any other Staff member, based on their own organizational placement (see Permissions below). Project membership, similarly, grants no Announcement visibility whatsoever (Announcements are an HR/organization-structure concern, not a Project concern, mirroring Leave Management's identical exclusion).
- **Current membership, not a snapshot:** visibility is evaluated against the Staff member's Department/Team **at read time**. Moving into a targeted Department/Team makes a still-active (`published`, not `archived`) announcement newly visible; moving out removes it from the feed. This is the simplest rule with no demonstrated need for snapshotting (CLAUDE.md §3), and is consistent with how every other org-structure-derived visibility check in this codebase already works (e.g. Staff Directory filters, Project Membership's "current roster" model).

**Multiple audiences:** a `scoped` Announcement may target any number of Departments and Teams together; the update endpoint replaces the full target set atomically (see Editing the Audience below) rather than supporting incremental add/remove calls — there is no dedicated audience-management endpoint, matching the instructions' explicit warning against building an audience-targeting "engine."

**Editing the audience:** a write (`POST`/`PUT`/`PATCH`) that includes any of `audience_type`, `department_ids`, or `team_ids` must include `audience_type` itself and, for `scoped`, a non-empty `department_ids` and/or `team_ids` — the full new audience is always specified atomically in one request (mirroring `ContactController`'s clear-then-set primary-contact transaction), never as an incremental patch to an existing target set. A request that touches neither key leaves the existing audience completely untouched.

## Acknowledgement

The roadmap (`docs/ROADMAP.md`) explicitly names "recipients, acknowledgements" as Phase 14 scope, and `03_DATABASE_MODEL.md`'s Communication group already sketched `announcement_acknowledgements` for exactly this DEC-009-style traceability purpose. This is **acknowledgement**, not **read tracking** — the two are deliberately not conflated (per the governing instructions' explicit warning): there is no separate "read" concept, no unread/read badge, no reminder, and no mandatory-acknowledgement/compliance workflow. Acknowledging is a single, self-initiated, idempotent action a Staff member may take on any Announcement currently visible to them.

`announcement_acknowledgements`: `announcement_id` (`cascadeOnDelete()` — in practice never fires, since only a `draft` can be hard-deleted and a draft can have no acknowledgements yet, mirroring `leave_request_actions.leave_request_id`'s identical reasoning), `staff_id` (`restrictOnDelete()` — a Staff member with any acknowledgement history cannot be hard-deleted, the same relational-integrity philosophy as every other Staff-referencing table), unique on `(announcement_id, staff_id)`, timestamps (`created_at` **is** the acknowledgement timestamp — no separate `acknowledged_at` column, extending DEC-032/DEC-036's "derive/reuse, don't duplicate" precedent to this single-fact case). No `public_id` — never independently addressed by URL, mirroring `project_memberships`/`staff_statuses`.

`POST /api/v1/me/announcements/{public_id}/acknowledge` is idempotent: acknowledging an already-acknowledged announcement returns the same `200` with the original timestamp, never a duplicate row or an error (`firstOrCreate`, backed by the unique constraint). A Staff member cannot acknowledge an announcement outside their own visibility (`404`, existence is sensitive, mirroring every other `/me/...` ownership check in this codebase).

**Management accountability, not an engagement dashboard:** the management resource exposes a plain `acknowledgements_count` (via `withCount`, mirroring `ClientResource.contacts_count`) so an Administrator can see at a glance whether an important announcement has been seen — but there is no per-staff acknowledgement list/export, no percentage-read analytics, and no reminder mechanism. This one integer is judged consistent with an already-established codebase pattern (a simple count on a management resource), not a new "read-rate dashboard" the governing instructions warn against.

## Permissions

A single new permission, `announcements.manage` — Administrator-only, via the existing centralized `Gate::before` override (DEC-028). It gates the **entire** `/api/v1/announcements` management surface (list, show, create, update, delete, publish, archive) — unlike every prior module, there is no companion `announcements.view` granted to Manager/Staff, because ordinary employee visibility is served entirely by `/api/v1/me/announcements` (a domain check — a linked Staff record — exactly like Phase 9/12/13's self-service surfaces, not a permission grant) and the governing instructions explicitly caution against requiring a global view permission for something every Staff member is naturally entitled to see a scoped slice of. A Manager holds no special Announcement authority of any kind in V1 — they read their own targeted feed exactly like any Staff member, and never author, edit, publish, or archive an Announcement. If a future phase demonstrates a genuine need for delegated/departmental publishing authority, that is a new, explicitly authorized decision — not assumed here.

## Employee Visibility & API

```
GET    /api/v1/me/announcements                         self (linked Staff record required, no permission)
GET    /api/v1/me/announcements/{public_id}              self (404 if outside audience/not published/archived)
POST   /api/v1/me/announcements/{public_id}/acknowledge  self (idempotent; 404 if outside audience)

GET    /api/v1/announcements                             announcements.manage (every status, all audiences)
GET    /api/v1/announcements/{public_id}                 announcements.manage
POST   /api/v1/announcements                             announcements.manage (always creates 'draft')
PUT/PATCH /api/v1/announcements/{public_id}               announcements.manage (409 if archived)
DELETE /api/v1/announcements/{public_id}                  announcements.manage (409 unless still 'draft')
POST   /api/v1/announcements/{public_id}/publish          announcements.manage (409 unless 'draft')
POST   /api/v1/announcements/{public_id}/archive          announcements.manage (409 unless 'published')
```

`/me/announcements` never accepts a `?staff=` parameter — it is always the authenticated User's own linked Staff record, mirroring every prior `/me/...` surface.

## Resource Shape

**AnnouncementResource** (single shape, shared by both surfaces, mirroring `StaffResource`'s "one resource, gate sensitive fields" precedent): `public_id`, `title`, `body`, `status`, `audience_type`, `departments` (array of `{public_id, name}`), `teams` (array of `{public_id, name}`), `published_at`, `created_by`/`published_by` (minimal Staff shape or `null`), `acknowledged_at` (only present on `/me/...` responses — whether/when the requesting Staff member acknowledged this specific announcement; absent, not `null`, on the management surface, where "the requesting user" isn't a meaningful acknowledger), `acknowledgements_count` (only present when the requester holds `announcements.manage`), timestamps. No internal numeric ID anywhere.

## Filters & Sorting

Management list: `?status=`, `?audience_type=`. Employee feed: no filters beyond pagination (CLAUDE.md's explicit "do not turn the API into a generic search engine" — no `q` search was demonstrated as required by the roadmap). Both order by `published_at` descending for published records (draft/archived records in the management list order by `updated_at` descending, since they have no meaningful `published_at`) — deterministic, useful "most recent first" ordering; no priority field exists to sort on.

## Validation

- `title`: required, string, ≤200.
- `body`: required, string, ≤10,000.
- `audience_type`: one of `company_wide`/`scoped` (default `company_wide` on create when omitted).
- `department_ids`/`team_ids`: arrays of Department/Team `public_id`s (`Rule::exists(..., 'public_id')`) — never internal numeric IDs.
- Cross-field (in `withValidator()`, mirroring `ResolvesWorkLogReferences`/`ValidatesLeaveDateRangeAndOverlap`'s pattern): `scoped` requires at least one Department or Team; `company_wide` forbids both. On update, supplying `department_ids`/`team_ids` without `audience_type` is rejected — the audience trio is always specified together (see Editing the Audience above).

## Database Indexes

`announcements`: `(status, published_at)` — the primary shape both the management list and the employee feed query against. `announcement_departments`/`announcement_teams`: their FK columns are already indexed by the composite primary key. `announcement_acknowledgements`: unique `(announcement_id, staff_id)`.

## Relational Integrity

- `StaffController::destroy` (Phase 7) is extended: a Staff member with any Announcement acknowledgement still referencing them cannot be deleted (`409`, backed by `restrictOnDelete()`), the same philosophy as every other Staff-referencing history table.
- `DepartmentController::destroy`/`TeamController::destroy` (Phase 6) are extended: a Department/Team still targeted by any Announcement's audience cannot be deleted (`409`, backed by `restrictOnDelete()`) — an Announcement's historical audience is never silently orphaned by an org-structure change.

## Explicitly Out of Scope

Direct messaging, group/project chat, comments/replies/discussion threads, reactions/likes/emojis, polls/surveys, a social feed, user-generated posts/status updates, attachments/file uploads/images, a rich-text editor, arbitrary HTML, push/email/SMS delivery, a notification queue/preference center, events/calendar integration, tasks, project activity feeds, a mandatory-read/acknowledgement-compliance workflow, engagement analytics/read-rate dashboards, revision history/versioning, CMS pages, a knowledge base, categories/tags, AI summarization, Elasticsearch, scheduled/future `publish_at`, expiry, and priority/importance. No Admin Backoffice (Blade/Livewire) CRUD UI and no Flutter mobile screens — API/backend only, consistent with every prior business-module phase's precedent.

## Relevant Documentation

- `docs/03_DATABASE_MODEL.md` §1 (Communication entity group — `announcements`/`announcement_recipients`/`announcement_acknowledgements`, resolved here as `announcements`/`announcement_departments`/`announcement_teams`/`announcement_acknowledgements`)
- `docs/04_API_CONVENTIONS.md` (explicit action endpoints over generic `PATCH status`)
- `docs/05_SECURITY_MODEL.md` (data isolation, least privilege)
- `docs/DECISIONS.md` DEC-009/DEC-010 (auditability, history over mutation), DEC-017 (identifiers), DEC-028 (authorization pattern), DEC-029 (Department/Team restrict-on-delete precedent), DEC-031 (ContactController clear-then-set transaction precedent)

## Acceptance Criteria

- An Administrator can create a draft Announcement, edit it, and publish it; publishing sets `published_at`/`published_by_user_id` server-side.
- A company-wide published Announcement is visible to every Staff-linked employee; a Department/Team-scoped one is visible only to current members of the targeted Department(s)/Team(s) (union semantics), and to nobody else.
- A draft or archived Announcement is never returned by `/me/announcements` or its detail endpoint.
- An employee outside an Announcement's audience receives `404` from its detail/acknowledge endpoints, never a `403` confirming its existence.
- Acknowledging is idempotent, self-scoped, and never visible to a coworker.
- A `published`/`archived` Announcement cannot be hard-deleted; only a `draft` can be. An `archived` Announcement cannot be edited or re-published.
- Staff/Department/Team deletion is blocked while referenced by any Announcement acknowledgement/audience row, respectively.
- No internal numeric ID is ever exposed by an API response or accepted as client input — only `public_id`.
- All `CLAUDE.md` §5 quality gates pass, including the full Phase 1–13 regression suite.

## Testing Expectations

See `docs/handoffs/V1_PHASE_14_HANDOFF.md` for what was actually implemented and tested; broad categories: Announcement CRUD/lifecycle (draft creation, edit, publish, duplicate-publish rejection, archive, duplicate-archive rejection, archived immutability, draft-only deletion); audience validation (company-wide/scoped coherence, at-least-one-target, atomic replacement); employee visibility (company-wide, Department-scoped, Team-scoped, union across both, no-department/no-team staff, draft/archived hidden, unrelated audience hidden, current-membership-at-read-time behavior after a Staff move); acknowledgement (idempotency, privacy, 404 outside audience); authorization (Administrator-only management, Manager has no elevated authority, no-role/no-linked-staff/unauthenticated/suspended boundaries); relational integrity (Staff/Department/Team delete-protection); full Phase 1–13 regression.
