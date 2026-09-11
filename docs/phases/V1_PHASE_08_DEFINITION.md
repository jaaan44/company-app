# Phase 8 — Clients & Contacts — Specification

**Status:** COMPLETE
**Depends on:** Phase 6 (Organization Structure), Phase 5 (Roles & Permissions)

## Objective

Build the foundational Clients & Contacts module for Company App: `clients` (external companies/organizations Company App's own company does business with) and `contacts` (people associated with a client). This is the reusable customer-data foundation later modules — Projects, Tasks, Work Logs, Messaging, CRM-style workflows, reporting — reference. Not those later modules themselves.

## In Scope

- `clients` table/model — `id`, `public_id` (ULID, DEC-017), `client_code` (nullable, unique when present, admin-supplied — not system-generated), `name` (required — the client's legal/business name), `status` (`App\Enums\ClientStatus`: `active`/`inactive`), `email`/`phone`/`website` (nullable), a small structured set of inline address fields (`address_line1`, `address_line2`, `city`, `state_province`, `postal_code`, `country`, all nullable), `notes` (nullable text), timestamps.
- `contacts` table/model — `id`, `public_id` (ULID, DEC-017), `client_id` (**required**, `restrictOnDelete()`), `first_name`, `last_name`, `job_title` (nullable), `email`/`phone` (nullable), `is_primary` (boolean, default `false`), `status` (`App\Enums\ContactStatus`: `active`/`inactive`), `notes` (nullable text), timestamps.
- **Client ↔ Contact relationship:** one Client has many Contacts; a Contact belongs to exactly one Client (`client_id` is required, never nullable) — no many-to-many, no client-less contacts. This matches the governing instructions' expected default and `03_DATABASE_MODEL.md`'s existing description ("`contacts` belong to a `client` (many contacts per client)").
- **Primary contact designation:** at most one `is_primary = true` Contact per Client, enforced at the application layer inside a DB transaction (`ContactController`) — setting a contact as primary atomically clears any other primary contact for the same client first. Not a DB partial-unique-index (MySQL/SQLite portability), and not left as fragile "last write wins" — always explicitly cleared-then-set in one transaction.
- Two new permissions added to the existing `RolePermissionSeeder`: `clients.view` (attached to Manager and Staff — the Client/Contact directory is company-wide, matching the existing `organization.view`/`staff.view` precedent) and `clients.manage` (Administrator-only, via the existing centralized `Gate::before` override). Contacts share Client's permissions — no separate `contacts.*` permission pair, since a Contact has no independent meaning apart from its Client (smallest sensible permission model).
- Versioned REST API: `/api/v1/clients` and `/api/v1/contacts`, both full CRUD, route-model-bound by `public_id`, permission-gated (`clients.view` reads / `clients.manage` writes). Flat top-level resources (not nested `/clients/{client}/contacts`) — matches the established Phase 6/7 precedent ("prefer a top-level filterable resource... when a resource is more independent than owned" combined with Staff's own precedent of filtering by another resource's `public_id` rather than nesting), even though `04_API_CONVENTIONS.md`'s original illustrative principle mentioned `/clients/{client}/contacts` as a hypothetical example before any real endpoint existed. Contact is filterable by `?client=<public_id>`.
- Status changes (including deactivation) go through the same `update` endpoint as every other field — no separate action route, matching the Phase 6/7 precedent.
- Filtering: Clients — `?status=`, `?q=` (search `name`/`client_code`). Contacts — `?client=<public_id>`, `?status=`, `?is_primary=`, `?q=` (search `first_name`/`last_name`/`email`).
- `ClientResource` — identity/code/name/status/business contact details/address/notes, plus a `contacts_count` (via `withCount`, not a nested array) — avoids a large nested graph on every list response, per the governing instructions. Full contact listings are fetched via `/api/v1/contacts?client=<public_id>`.
- `ContactResource` — person name/title/email/phone/`is_primary`/status/notes, plus a minimal nested `client` object (`public_id` + `name`) — never nests the full Client resource.
- Form Request validation: unique `client_code` when present; required `client_id` (submitted and resolved by `public_id`, never an internal numeric id) on Contact; valid email/website formats; reasonable field lengths; primary-contact handling (§ above).
- `ClientFactory`/`ContactFactory` for tests. No production/demo seeder — Client/Contact are business-owned data, not fixed system catalog data (same reasoning as Phase 6/7's Department/Team/Position/Staff).
- Automated tests: CRUD, validation, uniqueness, filtering/search, relationship integrity (multiple Contacts per Client; Client delete blocked while Contacts exist; primary-contact enforcement), and authorization (Administrator/Manager/Staff/no-role/unauthenticated/suspended).

## Explicitly Out of Scope

Per the governing instructions: projects, project assignments, opportunities, sales pipeline, leads, quotations, contracts, invoices, billing, payments, tasks, work logs, client portals, support tickets, service desk, email campaigns, marketing automation, messaging, notifications, file/document management, account-manager ownership rules, complex tagging, custom fields framework, activity timeline, contact interaction history, multiple addresses, branch/location hierarchy, advanced CRM segmentation. No Admin Backoffice (Blade/Livewire) CRUD UI — no such UI pattern exists yet for any module (Phase 6/7's precedent); this phase is API/backend only. No polymorphic/multi-address infrastructure. No auto-generated client codes.

## Client Lifecycle

`App\Enums\ClientStatus` (`active`/`inactive`) — a two-state lifecycle mirroring `OrganizationStatus` (Department/Team/Position), not `StaffStatus`'s three states: a Client is master/business-relationship data, not a person's employment record, so "temporarily inactive" vs. "no longer employed" has no analogue here — a client relationship is simply current or not. No `SoftDeletes` layered on top (would be a second, overlapping "is this still around" mechanism for the same row, same reasoning as DEC-029).

## Contact Lifecycle

`App\Enums\ContactStatus` (`active`/`inactive`) — a lightweight two-state lifecycle so a contact who has left the client organization can be preserved (not deleted) while being excluded from "who do I currently contact here" views. Deliberately not a richer multi-state lifecycle like `StaffStatus` — a Contact is a simpler record with no separation-date/employment-history concept to track.

## Client Codes / Identifiers

`client_code` is **nullable**, unique when present, admin-supplied (no auto-numbering) — deliberately different from Staff's `employee_number` (required), because a client record is often created (e.g. from a business card or an early sales conversation) before any internal reference code is assigned, unlike an employee who is always assigned an HR number at hire. `public_id` (ULID) remains the sole externally-addressable identifier (DEC-017); `client_code` is a convenience business reference only.

## Address Modeling

A small structured set of inline fields directly on `clients` (`address_line1`, `address_line2`, `city`, `state_province`, `postal_code`, `country`) — not a single formatted-text field (loses structure for filtering/reporting later) and not a polymorphic/multi-address subsystem (no demonstrated need; explicitly excluded by the governing instructions).

## Relational Integrity / Delete Behavior

- A Client cannot be deleted while any Contact still references it (`409`, application-enforced, backed by a DB-level `restrictOnDelete()` foreign key) — same philosophy and mechanism as `DepartmentController::destroy` (Phase 6) and `StaffController::destroy` (Phase 7): favor preserving business history over cascade-delete.
- A Contact may be freely deleted — nothing yet depends on Contacts (no later module exists yet), matching the Phase 6 precedent for Team/Position ("may be freely deleted in this phase").

## Relevant Documentation

- `docs/02_ARCHITECTURE.md` §4/§15 (Authorization), §16/§17 (Organization Structure / Staff precedent)
- `docs/03_DATABASE_MODEL.md` §1 (Clients group)
- `docs/04_API_CONVENTIONS.md` (Resource Naming, Filtering)
- `docs/05_SECURITY_MODEL.md` (Authorization, Least Privilege, Sensitive Information)
- `docs/DECISIONS.md` DEC-017 (identifiers), DEC-028 (authorization pattern), DEC-029 (org structure lifecycle precedent), DEC-030 (Staff precedent)

## Acceptance Criteria

- Clients and Contacts can be created, listed, viewed, updated, and deleted through versioned API endpoints, independently authorized server-side.
- A Contact always has a valid Client reference; a Client with Contacts still referencing it cannot be deleted.
- At most one primary Contact exists per Client at any time.
- Manager/Staff can view the Client/Contact directory but not manage it; a user with no role can do neither; unauthenticated/suspended access is rejected.
- No internal numeric ID is ever exposed by an API response or accepted as client input — only `public_id`.
- All CLAUDE.md §5 quality gates pass, including the full Phase 1–7 regression suite.

## Testing Expectations

- Feature tests for Client CRUD (list/create/view-by-public-id/internal-id-404/update/status-transition/delete/uniqueness/search-filtering).
- Feature tests for Contact CRUD (list/create/view/update/delete, required Client relationship, invalid Client public ID, search/filter by Client, primary-contact enforcement).
- Relationship integrity: multiple Contacts per Client; Client delete protection when Contacts exist; primary-contact behavior.
- A cross-cutting authorization test file mirroring `StaffAuthorizationTest`/`OrganizationAuthorizationTest`.
- Full Phase 1–7 regression suite continues passing unmodified in behavior.

## Notes

- Contacts share Client's permissions (`clients.view`/`clients.manage`) rather than a separate `contacts.*` pair — a Contact has no independent meaning apart from its Client, and this keeps the permission catalog as small as the governing instructions ask.
- No row-level ownership/account-manager ACLs — out of scope per the governing instructions; every `clients.view` holder sees every Client/Contact.
