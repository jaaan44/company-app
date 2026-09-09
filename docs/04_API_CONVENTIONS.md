# 04 — API Conventions (Initial Principles)

Status: **Principles only. No endpoints exist.** These conventions guide every future endpoint so the API stays consistent without needing a per-endpoint style debate. Prefer standard Laravel/REST practice over inventing custom conventions.

## Versioning

- URI-based versioning: `/api/v1/...`. Bump only on breaking changes.
- Mobile app and Admin Backoffice (if API-driven) share the same versioned API — no per-client API forks.

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

- All timestamps in ISO 8601 UTC (`created_at`, `updated_at`, plus domain-specific ones like `completed_at`, `approved_at`).
- Primary key strategy (auto-increment vs UUID) decided once, project-wide, at the Core Architecture phase — see `03_DATABASE_MODEL.md` open questions — and applied consistently across the API, not mixed per resource.

## Consistency Rules

- Don't invent a new envelope, error shape, or pagination style per module — reuse what's established here and in the Core Architecture phase.
- Any deviation from these conventions in a real endpoint must be justified in that phase's handoff, not silently introduced.

## Explicitly Not Decided Here

- Exact primary key type (see `03_DATABASE_MODEL.md`)
- Rate limiting thresholds (see `05_SECURITY_MODEL.md`)
- Whether the Admin Backoffice consumes this API or reads models directly (see `02_ARCHITECTURE.md`)
