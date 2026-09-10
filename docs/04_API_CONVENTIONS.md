# 04 — API Conventions (Initial Principles)

Status: **Principles, now implemented for one endpoint as of Phase 3** (`GET /api/v1/health`) to establish the conventions with real, tested code. These conventions guide every future endpoint so the API stays consistent without needing a per-endpoint style debate. Prefer standard Laravel/REST practice over inventing custom conventions.

## Versioning

- URI-based versioning: `/api/v1/...` — **implemented** (DEC-020): `routes/api.php` groups into `routes/api/v1.php`. A future `/api/v2` adds a parallel file/group; v1 controllers are never duplicated or reused across versions.
- The Flutter mobile app consumes this API. The Admin Backoffice (Blade + Livewire, DEC-019) does not — it reads models/business logic directly within the same Laravel app (see `02_ARCHITECTURE.md` §1) rather than calling its own API.

## Resource Naming

- Plural, lowercase, kebab/snake-free where possible: `/staff`, `/clients`, `/projects`, `/tasks`, `/leave-requests`.
- Nested resources only where genuinely owned/scoped: `/projects/{project}/tasks`, `/clients/{client}/contacts`. Avoid deep nesting beyond two levels — prefer a top-level filterable resource instead (e.g. `/tasks?project_id=`) when a resource is more independent than owned.
- Standard REST verbs/methods: `GET`, `POST`, `PUT/PATCH`, `DELETE`. Avoid verb-in-URL actions except for genuine non-CRUD operations (e.g. `POST /leave-requests/{id}/approve`, `POST /tasks/{id}/complete`), which are acceptable and preferred over overloading `PATCH` with implicit state-machine semantics.

## Authentication

- Token-based (exact mechanism — e.g. Laravel Sanctum — confirmed at the Authentication phase), suited to both the mobile app and the Admin Backoffice/SPA.
- All endpoints require authentication by default; explicitly document any exception (e.g. login itself).

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

- Filtering via query params scoped to the resource, e.g. `GET /tasks?status=open&assignee_id=5`.
- Sorting via `?sort=due_date` / `?sort=-due_date` (leading `-` = descending) — a common, unsurprising convention; avoid bespoke sort syntax.
- Document supported filter/sort fields per endpoint as it's built; don't expose arbitrary column filtering.

## Timestamps & Identifiers

- All timestamps in ISO 8601 UTC (`created_at`, `updated_at`, plus domain-specific ones like `completed_at`, `approved_at`). The health endpoint's `data.timestamp` follows this.
- Primary key strategy — **decided (DEC-017):** numeric `BIGINT` internally; externally addressable entities expose a `ULID public_id` instead of the internal numeric ID once such an entity exists. See `03_DATABASE_MODEL.md` §3.

## Consistency Rules

- Don't invent a new envelope, error shape, or pagination style per module — reuse what's established here and in the Core Architecture phase.
- Any deviation from these conventions in a real endpoint must be justified in that phase's handoff, not silently introduced.

## Explicitly Not Decided Here

- Rate limiting thresholds (see `05_SECURITY_MODEL.md`) — the health endpoint intentionally has none yet; real thresholds are tuned when Authentication (Phase 4) and later write-heavy endpoints are built.
