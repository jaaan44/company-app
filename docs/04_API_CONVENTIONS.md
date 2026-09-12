# 04 — API Conventions (Initial Principles)

Status: **Principles, implemented for a growing set of endpoints as of Phase 4/5/6/7/8/9/10/11/12/13/14** (`GET /api/v1/health` since Phase 3; `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me` since Phase 4; full CRUD for `departments`/`teams`/`positions` since Phase 6; full CRUD for `staff` since Phase 7; full CRUD for `clients`/`contacts` since Phase 8; `/me/status`, `/me/check-ins`, and scoped staff-specific status/check-in endpoints since Phase 9; full CRUD for `projects` plus nested Project Membership endpoints since Phase 10; full CRUD for `tasks` since Phase 11; self-service `/me/work-logs` plus a scoped `work-logs` supervisory surface since Phase 12; `leave-types` CRUD, self-service `/me/leave-requests`/`/me/leave-balances`, a scoped `leave-requests` supervisory surface, and explicit `/approve`/`/reject`/`/cancel` action endpoints since Phase 13; self-service `/me/announcements` plus a fully Administrator-only `announcements` management surface with explicit `/publish`/`/archive` action endpoints since Phase 14) to establish the conventions with real, tested code. These conventions guide every future endpoint so the API stays consistent without needing a per-endpoint style debate. Prefer standard Laravel/REST practice over inventing custom conventions.

**Phase 14:** `GET /api/v1/me/announcements` plus `GET .../{public_id}` and `POST .../{public_id}/acknowledge` — self-service, needing only a linked Staff record (Phase 9/12/13's `/me/...` precedent), never a permission; always returns only `published` (non-archived) announcements within the requester's own audience, and reports a `public_id` outside that audience as `404` (existence is sensitive), same as `/me/work-logs`/`/me/leave-requests`. Separately, `GET/POST /api/v1/announcements` plus `GET/PUT/PATCH/DELETE .../{public_id}` and `POST .../{public_id}/publish`/`archive` — the management surface, gated end-to-end by a single `can:announcements.manage` route middleware (Administrator-only). Unlike every prior module, there is no scoped/mixed-visibility read endpoint here (contrast Phase 10–13's `GET` routes with no bare `can:` middleware) — Announcement management has exactly one authorized actor type, so a conventional permission-gated route suffices for reads and writes alike. `publish`/`archive` are this document's own "genuine non-CRUD operation" example (see Resource Naming) made real for a third module, alongside Phase 13's `approve`/`reject`/`cancel`.

**Phase 13:** `GET/POST /api/v1/leave-types` plus `GET/PUT/PATCH/DELETE .../{public_id}` — `leave-types.view` (Administrator/Manager/Staff, company-wide)/`leave-types.manage` (Administrator-only), the same pattern as Departments/Staff/Clients. Self-service `GET/POST /api/v1/me/leave-requests`, `GET .../{public_id}`, `POST .../{public_id}/cancel`, and `GET /api/v1/me/leave-balances` need no permission beyond a linked Staff record (Phase 9/12's `/me/...` precedent). The supervisory `GET /api/v1/leave-requests`/`{public_id}` surface is scoped in-controller with no `can:` middleware (Administrator sees all, Manager sees direct reports — mirroring Phase 12's `work-logs.view` shape); `POST /leave-requests` and `POST .../{public_id}/cancel` require `leave-requests.manage`. `POST .../{public_id}/approve`/`reject` are this document's own `POST /leave-requests/{id}/approve` illustrative example, now real — genuine non-CRUD action endpoints rather than a generic `PATCH status`, carrying **no** permission route middleware at all: authority (Administrator, or the requester's current direct Manager) is resolved entirely in `LeaveRequestController`, the same row-level write-authority shape Phase 11/12 established for Project Lead/Work Log authority. There is deliberately **no** `PUT`/`PATCH` for `leave_requests` anywhere (no edit — cancel and resubmit instead, see `05_SECURITY_MODEL.md` and DEC-036).

**Phase 12:** `GET`/`POST /api/v1/me/work-logs` plus `PUT`/`PATCH`/`DELETE .../{public_id}` — self-service, performer identity always server-derived from the authenticated User's linked Staff record (Phase 9's `/me/...` precedent, extended here to include self-edit/self-delete for the first time, not just list/append). Separately, `GET/POST /api/v1/work-logs` plus `GET/PUT/PATCH/DELETE .../{public_id}` — the supervisory/administrative surface: reads are scoped in-controller with no bare `can:` middleware (Administrator sees all, Manager sees only direct reports, Project Lead sees led-Project logs read-only — a three-tier scoping shape not seen before), writes require `can:work-logs.manage` (Administrator-only) route middleware. Two endpoint families exist because each serves a genuinely distinct purpose (self-service can never receive a client-supplied Staff identity; the supervisory surface is explicitly scoped and separately authorized) — not a duplicate pair. See `05_SECURITY_MODEL.md` and DEC-035.

**Phase 11:** `GET/POST /api/v1/tasks` plus `GET/PUT/PATCH/DELETE .../{public_id}` — a **flat, top-level resource** (filterable by `?project=<public_id>`), not nested under `/projects/{project}/tasks`, even though a Task can belong to a Project — unlike Project Membership (Phase 10), a Task is not inherently owned by a Project (DEC-006 supports independent Tasks), so the "prefer a top-level filterable resource... when a resource is more independent than owned" guidance (established by Contact→Client, Phase 8) applies here, and this also avoids re-introducing Phase 10's two-consecutive-Eloquent-parameter nested route-model-binding issue. Extends Phase 10's "reads scoped in-controller, not by a bare `can:` middleware" pattern to **writes** for the first time: `POST`/`PUT`/`PATCH /api/v1/tasks` carry no permission route middleware at all — a Project Lead's Task-management authority and a Task assignee's status-only self-service are both authorized entirely inside `TaskController`/`AuthorizesTaskAccess`. `DELETE` remains a plain `can:tasks.manage` route, further narrowed in-controller to Tasks still in their initial `todo` status (see `05_SECURITY_MODEL.md` and DEC-034).

**Phase 10:** `GET/POST /api/v1/projects` plus `GET/PUT/PATCH/DELETE .../{public_id}`, and — the first genuinely **nested** resource with real endpoints in this API — `GET/POST /api/v1/projects/{public_id}/members` plus `PUT/PATCH/DELETE .../members/{staff_public_id}`. Unlike Contact→Client (Phase 8, deliberately kept flat), Project Membership is inherently contextual to a Project, so nesting is the better fit here; it is addressed by its member's Staff `public_id` within the nested collection rather than an independent membership `public_id`. Also the first case where a `GET` list/show pair is **not** gated by a single `can:<permission>` route middleware: visibility is scoped in-controller (an Administrator/Manager sees every Project; an ordinary Staff member sees only Projects they are a member of) — see `05_SECURITY_MODEL.md` and DEC-033.

**Phase 9:** introduces the first `/me/...` self-scoped resource beyond `/auth/me` — `GET`/`POST /api/v1/me/status` and `GET`/`POST /api/v1/me/check-ins`, always acting on the authenticated user's own linked Staff record, never a client-supplied identifier. "Updating" the current value is modeled as **appending** to the same paginated, latest-first collection the `GET` returns (`POST`), rather than a mutable `PUT`/`PATCH` — DEC-032's history-over-mutation approach applied to the API layer itself, and a second demonstration (after Contact→Client) of preferring a flat, well-scoped endpoint over deeper nesting: `GET /api/v1/staff/{public_id}/status`/`check-ins` reuse the existing "top-level resource, scoped by another resource's `public_id`" shape rather than inventing a third pattern. See `05_SECURITY_MODEL.md` and DEC-032.

**Phase 5:** no new endpoints were added; `UserResource` (used by `/auth/login` and `/auth/me`) gained a `role` field — see `05_SECURITY_MODEL.md` API Access.

**Phase 6:** first real demonstration of the "top-level filterable resource" and "route-model-bound by `public_id`" conventions below with genuine CRUD endpoints — `GET/POST /api/v1/departments`, `GET/POST /api/v1/teams`, `GET/POST /api/v1/positions`, plus `GET/PUT/PATCH/DELETE .../{public_id}` for each. See `05_SECURITY_MODEL.md` and `docs/handoffs/V1_PHASE_06_HANDOFF.md`.

**Phase 7:** `GET/POST /api/v1/staff` plus `GET/PUT/PATCH/DELETE .../{public_id}` — this document's own `/staff` example (Resource Naming, below) is now a real endpoint. Confirms the "status changes go through the normal update endpoint, not a separate action route" convention for a second resource (first shown by Departments/Teams/Positions in Phase 6). See `05_SECURITY_MODEL.md` and `docs/handoffs/V1_PHASE_07_HANDOFF.md`.

**Phase 8:** `GET/POST /api/v1/clients` and `GET/POST /api/v1/contacts`, plus `GET/PUT/PATCH/DELETE .../{public_id}` for each — this document's own `/clients` example (Resource Naming, below) is now a real endpoint. A deliberate deviation from this document's original nested-resource illustration: Contact is genuinely owned by Client (a required, never-nullable `client_id`), yet is still implemented as a **flat, top-level, filterable resource** (`/api/v1/contacts?client=<public_id>`) rather than `/clients/{client}/contacts` — this document's original "nested resources only where genuinely owned/scoped" example predates any real endpoint; the "prefer a top-level filterable resource" guidance, now demonstrated three times over (Staff→Department/Team/Position/Manager, Staff→User, Contact→Client), is the pattern this API actually follows. See `05_SECURITY_MODEL.md`, DEC-031, and `docs/handoffs/V1_PHASE_08_HANDOFF.md`.

## Versioning

- URI-based versioning: `/api/v1/...` — **implemented** (DEC-020): `routes/api.php` groups into `routes/api/v1.php`. A future `/api/v2` adds a parallel file/group; v1 controllers are never duplicated or reused across versions.
- The Flutter mobile app consumes this API. The Admin Backoffice (Blade + Livewire, DEC-019) does not — it reads models/business logic directly within the same Laravel app (see `02_ARCHITECTURE.md` §1) rather than calling its own API.

## Resource Naming

- Plural, lowercase, kebab/snake-free where possible: `/staff`, `/clients`, `/contacts`, `/projects`, `/tasks`, `/leave-requests`.
- Nested resources only where genuinely owned/scoped: `/projects/{project}/tasks`. Avoid deep nesting beyond two levels — prefer a top-level filterable resource instead (e.g. `/tasks?project_id=`) when a resource is more independent than owned, or (as Phase 8 confirmed for `/contacts?client=`) even when it's genuinely owned but a flat, filterable resource still serves it well — see the Phase 8 note above.
- Standard REST verbs/methods: `GET`, `POST`, `PUT/PATCH`, `DELETE`. Avoid verb-in-URL actions except for genuine non-CRUD operations (e.g. `POST /leave-requests/{id}/approve`, `POST /tasks/{id}/complete`), which are acceptable and preferred over overloading `PATCH` with implicit state-machine semantics.

## Authentication

- **Implemented as of Phase 4** (DEC-022): Laravel Sanctum personal access tokens, `Authorization: Bearer <token>`. The Admin Backoffice does not call this API (see `02_ARCHITECTURE.md` §1) and is therefore not part of this token scheme.
- All endpoints require authentication by default; the only current exception is `POST /api/v1/auth/login` itself (and the pre-existing, deliberately public `GET /api/v1/health`).
- Endpoints implemented so far: `POST /api/v1/auth/login` (public, rate-limited), `POST /api/v1/auth/logout` (authenticated — revokes only the current token), `GET /api/v1/auth/me` (authenticated — also enforces account status via `account.active` middleware).

## Authorization

- Every endpoint enforces permission checks server-side (see `05_SECURITY_MODEL.md`) — the API is the enforcement boundary, not the client UI.
- Authorization failures return `403 Forbidden` with a consistent error body (see below); they must not leak whether a resource exists if the user isn't allowed to know that (return `404` instead of `403` where existence itself is sensitive — decide per-resource).

## Validation & Errors

- Use Laravel Form Requests for validation; return `422 Unprocessable Entity` with a consistent shape, e.g.:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

- General error envelope for non-validation errors:

```json
{
  "message": "Human-readable summary",
  "error_code": "optional_machine_code"
}
```

- Standard status codes: `200` OK, `201` Created, `204` No Content, `400` Bad Request, `401` Unauthenticated, `403` Forbidden, `404` Not Found, `409` Conflict, `422` Validation, `429` Too Many Requests, `500` Server Error.

## Success Response Shape

- Single resource:
```json
{ "data": { "id": 1, "type": "task", "...": "..." } }
```
- Collection:
```json
{ "data": [ { "...": "..." } ], "meta": { "...pagination..." }, "links": { "...": "..." } }
```
- Prefer Laravel API Resources for consistent shaping; avoid ad hoc response formats per controller.

## Pagination

- Standard Laravel cursor or length-aware paginator. Default page size documented per resource; expose `page`, `per_page` query params, and standard `meta`/`links` blocks from Laravel's paginator.

## Filtering & Sorting

- Filtering via query params scoped to the resource, e.g. `GET /tasks?status=open&assignee_id=5`. **Implemented (Phase 14):** `GET /api/v1/announcements?status=&audience_type=` (management only — no filters on `/api/v1/me/announcements` beyond pagination, per CLAUDE.md's "do not turn the API into a generic search engine"). **Implemented (Phase 13):** `GET /api/v1/leave-requests?staff=<public_id>&status=&type=<public_id>&from=&to=` (scoped: Administrator/Manager); `GET /api/v1/me/leave-requests?status=&type=&from=&to=` (self, never `?staff=`); `GET /api/v1/leave-types?status=active`; `GET /api/v1/me/leave-balances?year=`/`GET /api/v1/staff/{public_id}/leave-balances?year=` (defaults to the current calendar year). **Implemented (Phase 12):** `GET /api/v1/work-logs?staff=<public_id>&project=<public_id>&task=<public_id>&from=&to=` (privileged, visibility-scoped); `GET /api/v1/me/work-logs?project=&task=&from=&to=` (self, always own — no `?staff=`). **Implemented (Phase 6):** `GET /api/v1/departments?status=active`, `GET /api/v1/teams?department=<public_id>&status=active` — filter values that reference another resource use its `public_id`, never an internal numeric id. **Implemented (Phase 7):** `GET /api/v1/staff?department=<public_id>&team=<public_id>&position=<public_id>&manager=<public_id>&status=active`, plus a simple `?q=` directory search (matched against name fields and employee number) — a genuinely useful lookup for a personnel directory, not a filter added for completeness. **Implemented (Phase 8):** `GET /api/v1/clients?status=active&q=`, `GET /api/v1/contacts?client=<public_id>&status=active&is_primary=1&q=`. **Implemented (Phase 10):** `GET /api/v1/projects?status=active&client=<public_id>&member=<staff public_id>&q=`, `GET /api/v1/projects/{public_id}/members?role=project_lead` — all applied within the requester's visibility-scoped base query (see `05_SECURITY_MODEL.md`), not a raw table scan. **Implemented (Phase 11):** `GET /api/v1/tasks?project=<public_id>&status=&assignee=<staff public_id>&priority=&q=` (title search) — same visibility-scoped pattern; no due-date/overdue filter was added (no demonstrated need at V1 scale).
- Sorting via `?sort=due_date` / `?sort=-due_date` (leading `-` = descending) — a common, unsurprising convention; avoid bespoke sort syntax. Not yet implemented for any endpoint (Phase 6's organization-structure lists are small enough that a fixed `sort_order`-then-name ordering was sufficient; a genuine `?sort=` param is introduced when a future endpoint's data actually needs it).
- Document supported filter/sort fields per endpoint as it's built; don't expose arbitrary column filtering.

## Timestamps & Identifiers

- All timestamps in ISO 8601 UTC (`created_at`, `updated_at`, plus domain-specific ones like `completed_at`, `approved_at`). The health endpoint's `data.timestamp` follows this.
- Primary key strategy — **decided (DEC-017):** numeric `BIGINT` internally; externally addressable entities expose a `ULID public_id` instead of the internal numeric ID once such an entity exists. See `03_DATABASE_MODEL.md` §3.

## Consistency Rules

- Don't invent a new envelope, error shape, or pagination style per module — reuse what's established here and in the Core Architecture phase.
- Any deviation from these conventions in a real endpoint must be justified in that phase's handoff, not silently introduced.

## Explicitly Not Decided Here

- Rate limiting thresholds for future write-heavy endpoints beyond authentication (see `05_SECURITY_MODEL.md` for the Phase 4 authentication thresholds now in place).
