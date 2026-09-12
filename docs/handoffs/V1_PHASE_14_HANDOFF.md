# Phase 14 Handoff — Announcements

**Phase:** 14 — Announcements
**Date:** 2026-09-12
**Branch:** `claude/company-app-v1-phase-14-dr23o2` (branched from `main`, not merged)
**Depends on:** Phase 5 (Roles & Permissions), Phase 7 (Staff), Phase 6 (Organization Structure)

## 1. Objective

Build the foundational Announcements module for internal company broadcast communication: Administrator-authored announcements with a draft/published/archived lifecycle, company-wide or Department/Team-scoped targeting, and a lightweight employee acknowledgement record. Not direct messaging, chat, comments, notifications infrastructure, or a CMS.

## 2. Scope Implemented

- `announcements` table and `App\Models\Announcement` — `public_id` (ULID), `title` (≤200), `body` (text, ≤10,000, plain text), `status` (`App\Enums\AnnouncementStatus` — `draft`/`published`/`archived`), `audience_type` (`App\Enums\AnnouncementAudienceType` — `company_wide`/`scoped`), `published_at` (nullable, server-controlled), `created_by_user_id`/`published_by_user_id` (both nullable, `nullOnDelete()`).
- `announcement_departments`/`announcement_teams` tables — plain many-to-many pivots (no surrogate id, mirroring `role_permissions`' shape), `announcement_id` `cascadeOnDelete()`, `department_id`/`team_id` `restrictOnDelete()`. Union (not intersection) visibility semantics.
- `announcement_acknowledgements` table and `App\Models\AnnouncementAcknowledgement` — `announcement_id` `cascadeOnDelete()`, `staff_id` `restrictOnDelete()`, unique per pair, `created_at` is the acknowledgement timestamp.
- Lifecycle: `draft` → `published` (`POST .../publish`) → `archived` (`POST .../archive`), both explicit action endpoints, never a generic status `PATCH`. Archived is terminal (no un-archive/republish). A draft may be freely edited (title/body/audience) or hard-deleted; **once published, an Announcement's title/body/audience become immutable content** — `PUT`/`PATCH` returns `409` for both `published` and `archived` (post-review correction, see §11) — so an acknowledgement always permanently corresponds to the exact configuration that existed at publication. Correcting a mistake in already-published information is a three-step workflow: archive the existing Announcement, create a corrected draft, publish it.
- Audience/targeting: `company_wide` (every Staff-linked User) or `scoped` (one or more Departments and/or Teams, union semantics), evaluated against a Staff member's **current** Department/Team membership at read time. A request touching the audience must always specify it atomically (`audience_type` plus, for `scoped`, a non-empty `department_ids`/`team_ids`).
- Acknowledgement: a self-initiated, idempotent `POST /me/announcements/{public_id}/acknowledge` — deliberately not read/unread tracking or a mandatory-acknowledgement workflow. The management resource exposes a plain `acknowledgements_count`.
- `StaffController::destroy`/`DepartmentController::destroy`/`TeamController::destroy` (Phases 7/6) extended to block deletion while any Announcement acknowledgement/audience row references the Staff member/Department/Team.
- A single new permission, `announcements.manage` (Administrator-only), extends `RolePermissionSeeder` — no companion `announcements.view`.
- Two endpoint families: self-service `/api/v1/me/announcements` (list/show/acknowledge) and management `/api/v1/announcements` (full CRUD plus `/publish`/`/archive`), the latter entirely gated by `announcements.manage`.
- `AnnouncementFactory`/`AnnouncementAcknowledgementFactory`, `App\Http\Resources\AnnouncementResource` (single shape shared by both surfaces).
- New tests: `AnnouncementTest`, `MyAnnouncementTest`, `AnnouncementsAuthorizationTest`, plus additions to `RolePermissionSeederTest`.

## 3. Implementation Summary

**Domain model.** `03_DATABASE_MODEL.md` sketched `announcements`/`announcement_recipients`/`announcement_acknowledgements`. Resolved for V1: `announcement_recipients` becomes two dedicated many-to-many pivots (`announcement_departments`/`announcement_teams`) rather than a polymorphic recipients table — only two concrete target types (Department, Team) were ever in view, so a dedicated shape was simpler and matches DEC-017's general preference. No priority field, no scheduled future `publish_at`, and no expiry were built — none were required by the governing roadmap line ("Company-wide/scoped announcements, recipients, acknowledgements") or any technical document, and CLAUDE.md §3 prohibits speculative scope beyond what's authorized.

**Lifecycle is a single status column, no parallel history table.** Unlike Leave Management's `leave_request_actions` (a genuine two-party approval decision with notes to preserve), every Announcement transition has exactly one actor type (Administrator) and no decision content — `status` plus `published_at`/`published_by_user_id`/`updated_at` already capture everything worth knowing. `published_at`/`published_by_user_id` are set only inside `AnnouncementController::publish()`, never client-suppliable and never re-set by a later edit — verified directly in `test_a_published_announcements_publish_timestamp_is_server_controlled`.

**Editing is draft-only — published content is immutable (post-review correction, see §11).** `AnnouncementController::update()` rejects (`409`) any `PUT`/`PATCH` unless the Announcement is still `draft`. This closes a real historical-integrity gap in the phase's original design (which allowed editing a `published` Announcement): an employee's acknowledgement records that they acknowledged a *specific* Announcement, and if its title/body/audience could later be silently rewritten, the system would misrepresent what was actually acknowledged — directly contradicting the phase's own preserve-don't-mutate rationale for published company communications (DEC-009/DEC-010). The fix required no revision history, content-versioning, or acknowledgement migration/invalidation: locking content at publication, with archive + a corrected new draft as the only correction path, is sufficient.

**Audience is atomic, never incremental, and draft-only.** `ValidatesAnnouncementAudience` (shared by Store/UpdateAnnouncementRequest) enforces that a `scoped` announcement always specifies at least one Department/Team, a `company_wide` one specifies neither, and — on update specifically — that touching `department_ids`/`team_ids` without also including `audience_type` is rejected outright, since the audience is always replaced as a whole (mirroring `ContactController`'s clear-then-set primary-contact transaction) rather than patched incrementally. Like title/body, this only applies while the Announcement is `draft` — the same `update()` guard blocks it once published.

**Visibility is a structurally new row-level shape.** `ScopesAnnouncementVisibility` (shared by `MyAnnouncementController`) is neither a permission-holder-scoped-to-a-subset pattern (Phases 9/12/13) nor a no-permission membership check (Phases 10/11) — it's a per-employee audience match against broadcast content every eligible employee is equally entitled to see in full. The management surface, by contrast, needed no row-level scoping at all: with exactly one authorized actor type (Administrator), a conventional `can:announcements.manage` route middleware covers reads and writes alike — the first module where the *entire* surface, including `GET`, sits behind a single permission gate with no in-controller scoping layer.

**Acknowledgement resolves an apparent conflict between the terse roadmap and the detailed scope-exclusion list.** `docs/ROADMAP.md` explicitly names "recipients, acknowledgements" for Phase 14, while the detailed governing instructions list "acknowledgement workflow"/"mandatory-read compliance"/"engagement analytics" among explicit exclusions "unless explicitly required." Both documents are honored by building acknowledgement as a minimal, explicit, separate feature (per the instructions' own "if the roadmap requires acknowledgement... model it explicitly, don't invent it") rather than either skipping it or building it into a full read-tracking/compliance system.

## 4. Files Changed

**Added:**
- `apps/api/database/migrations/2026_09_12_140000_create_announcements_table.php`, `..._140001_create_announcement_departments_table.php`, `..._140002_create_announcement_teams_table.php`, `..._140003_create_announcement_acknowledgements_table.php`
- `apps/api/app/Enums/AnnouncementStatus.php`, `AnnouncementAudienceType.php`
- `apps/api/app/Models/Announcement.php`, `AnnouncementAcknowledgement.php`
- `apps/api/app/Http/Controllers/Api/V1/Announcements/AnnouncementController.php`, `MyAnnouncementController.php`
- `apps/api/app/Http/Controllers/Api/V1/Announcements/Concerns/ScopesAnnouncementVisibility.php`
- `apps/api/app/Http/Requests/Announcements/StoreAnnouncementRequest.php`, `UpdateAnnouncementRequest.php`
- `apps/api/app/Http/Requests/Announcements/Concerns/ValidatesAnnouncementAudience.php`
- `apps/api/app/Http/Resources/AnnouncementResource.php`
- `apps/api/database/factories/AnnouncementFactory.php`, `AnnouncementAcknowledgementFactory.php`
- `apps/api/tests/Feature/Api/V1/Announcements/AnnouncementTest.php`, `MyAnnouncementTest.php`
- `apps/api/tests/Feature/Authorization/AnnouncementsAuthorizationTest.php`
- `docs/phases/V1_PHASE_14_DEFINITION.md`
- `docs/handoffs/V1_PHASE_14_HANDOFF.md` (this file)

**Modified:**
- `apps/api/app/Models/Staff.php` — added `announcementAcknowledgements()` relation.
- `apps/api/app/Models/Department.php`/`Team.php` — added `announcements()` `BelongsToMany` relation.
- `apps/api/app/Http/Controllers/Api/V1/Staff/StaffController.php` — `destroy()` announcement-acknowledgement-reference check.
- `apps/api/app/Http/Controllers/Api/V1/Organization/DepartmentController.php`/`TeamController.php` — `destroy()` announcement-audience-reference check.
- `apps/api/database/seeders/RolePermissionSeeder.php` — `announcements.manage`.
- `apps/api/routes/api/v1.php` — `/me/announcements`, `/announcements` route groups.
- `apps/api/tests/Feature/Authorization/RolePermissionSeederTest.php` — updated permission count (22→23), added Phase 14 permission tests.
- `docs/02_ARCHITECTURE.md` (new §24), `docs/03_DATABASE_MODEL.md`, `04_API_CONVENTIONS.md`, `05_SECURITY_MODEL.md`, `CURRENT_STATE.md`, `CHANGELOG.md`, `DECISIONS.md`, `ROADMAP.md`, `testing/UAT_LOG.md`, `testing/TEST_STATUS.md`.

## 5. Database/Schema Changes

Four new migrations (see §4). Indexes: `announcements` — `(status, published_at)`. `announcement_departments`/`announcement_teams` — composite primary key doubles as the FK index. `announcement_acknowledgements` — unique `(announcement_id, staff_id)`. No DB-level `CHECK` constraints — application-enforced consistency, matching this codebase's existing precedent.

## 6. API Changes

```
GET    /api/v1/me/announcements                         self (linked Staff record required)
GET    /api/v1/me/announcements/{public_id}              self (404 outside audience/draft/archived)
POST   /api/v1/me/announcements/{public_id}/acknowledge  self (idempotent)

GET    /api/v1/announcements                             announcements.manage
GET    /api/v1/announcements/{public_id}                 announcements.manage
POST   /api/v1/announcements                             announcements.manage (always creates 'draft')
PUT/PATCH /api/v1/announcements/{public_id}               announcements.manage (409 unless still 'draft')
DELETE /api/v1/announcements/{public_id}                  announcements.manage (409 unless still 'draft')
POST   /api/v1/announcements/{public_id}/publish          announcements.manage (409 unless 'draft')
POST   /api/v1/announcements/{public_id}/archive          announcements.manage (409 unless 'published')
```

`AnnouncementResource` is a single shape shared by both surfaces (mirrors `StaffResource`'s "one resource, gate sensitive fields" precedent) — `acknowledged_at` appears only on `/me/...` responses, `acknowledgements_count` only for an `announcements.manage` holder. No internal numeric ID anywhere.

## 7. Authorization/Security Changes

- New permission: `announcements.manage` (Administrator-only, via the centralized `Gate::before` override). No companion `announcements.view` — the only module so far with this shape.
- `App\Http\Controllers\Api\V1\Announcements\Concerns\ScopesAnnouncementVisibility` — the self-service query/boolean visibility check (company-wide, or current Department/Team match, union semantics), used by `MyAnnouncementController` only. The management surface has no equivalent scoping — it's a plain `can:announcements.manage` gate covering reads and writes alike.
- A Manager holds no elevated Announcement authority of any kind — they use `/me/announcements` exactly like any Staff member.

## 8. Tests Added or Changed

- `AnnouncementTest` (34 tests, up from 27 — see §11): creation (company-wide/scoped, validation, internal-ID rejection, creator accountability, Staff denial), scoped-audience coherence (at-least-one-target, company-wide-forbids-targets), **draft-only editing** (title, body, audience each independently confirmed editable while draft), **published-content immutability** (title, body, and audience each independently confirmed rejected with `409` while published — replacing the original, now-incorrect "administrator can edit a published announcement" test), archived immutability, an acknowledged published Announcement rejected on edit, atomic-audience-update validation, audience-untouched-when-omitted, lifecycle (publish, duplicate-publish rejection, publish-from-archived rejection, server-controlled `published_at`, archive, duplicate-archive rejection, archive-from-draft rejection, no-unarchive, **acknowledgement survives archive unchanged**, **full draft→publish→acknowledge→archive integration test**), deletion vs. archival (draft deletable, published/archived not), management listing (drafts/archived included, `acknowledgements_count`, internal-ID rejection), relational integrity (Department/Team/Staff delete-protection).
- `MyAnnouncementTest` (22 tests): visibility (company-wide, draft/archived hidden, Department-scoped, Team-scoped, union semantics, no-department/team staff, current-membership move in/out, Manager gets no extra visibility), detail/404 privacy (visible shown, outside-audience 404, draft 404), acknowledgement (idempotent, outside-audience 404, draft 404, coworker privacy), self-service boundaries (no-role-with-staff allowed, no-linked-staff denied).
- `AnnouncementsAuthorizationTest` (7 tests): Administrator full access, Manager/Staff/no-role denied the entire management surface, no-linked-staff self-service denial, unauthenticated, suspended account.
- `RolePermissionSeederTest`: updated permission count (22→23), added `announcements.manage` existence/non-attachment tests.
- Full Phase 1–13 regression suite (467 pre-existing tests) verified passing unmodified in behavior throughout, including after the post-review correction.
- **Test-harness note:** two `MyAnnouncementTest` cases (moving into/out of a targeted Department) initially failed because `Sanctum::actingAs()` reuses the same in-memory `User` object across simulated requests within one test, and Eloquent caches its `staff` relation after first access — a PHPUnit artifact, not a real bug (a genuine HTTP request always resolves the User fresh from the database). Fixed by re-authenticating with `$user->fresh()` between the two requests in each test.

## 9. Commands/Checks Executed

```
composer install --no-interaction --prefer-dist --no-progress   (COMPOSER_PROCESS_TIMEOUT=1800, background — see §11)
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan migrate:fresh --force
php artisan db:seed --class=Database\Seeders\RolePermissionSeeder --force
php artisan test
```

Plus two rounds of real end-to-end HTTP smoke testing via `php artisan serve` + `curl` (Tinker-seeded fixtures each time):

- **Initial round:** Administrator creates a Department-scoped draft Announcement (`POST /api/v1/announcements`) → publishes it (`POST .../publish`, confirmed server-set `published_at`) → an eligible (same-Department) Staff member's `GET /api/v1/me/announcements` contains it → that Staff member acknowledges it (`POST .../acknowledge`, confirmed idempotent-shaped response) → an unrelated-Department Staff member's feed does **not** contain it and its detail endpoint returns `404` → the eligible Staff member is confirmed `403` on the top-level `/api/v1/announcements` → Administrator confirms `acknowledgements_count: 1` on the management view → Administrator archives it (`POST .../archive`) → the archived Announcement disappears from the eligible Staff member's feed.
- **Post-review correction round (§11):** Administrator creates a draft → edits it successfully (confirming draft editing still works) → publishes it → an eligible Staff member acknowledges it → Administrator attempts `PATCH` on the now-published Announcement → confirmed `409` (the corrected behavior) → Administrator archives it → the acknowledgement record is confirmed still present and unchanged → a further edit attempt on the now-archived Announcement is confirmed still `409`. See §10 for the exact request/response sequence.

## 10. Results

- `composer validate --strict`: **valid**.
- `vendor/bin/pint --test`: **passed** (one file needed auto-fixing on first run — `vendor/bin/pint` applied it cleanly; re-verified passing after all test edits, including the post-review correction).
- `vendor/bin/phpstan analyse` (level 5): **0 errors**.
- `php artisan migrate:fresh --force`: all 29 migrations run cleanly, including the four new Announcement tables (no schema change was needed for the correction — it is purely a controller/validation-layer fix).
- `php artisan db:seed --class=RolePermissionSeeder`: runs cleanly, produces the expected 23 permissions including the new `announcements.manage` permission (confirmed attached to neither Manager nor Staff).
- `php artisan test`: **535/535 passing, 1421 assertions** (full suite — Phases 1–14, post-correction; 7 tests / 24 assertions added net versus the pre-correction 528/1397).
- Real HTTP smoke tests (both rounds): every request behaved as designed (see §9).

## 11. Deviations from Specification / Environment Notes

- **Environment:** this container started with no `vendor/` at all. `composer install --no-interaction --prefer-dist --no-progress` under `COMPOSER_PROCESS_TIMEOUT=1800` — the same recovery this repeatedly-documented `phpstan/phpstan` `git clone --mirror`-timeout issue has needed since Phase 9 — completed successfully as a background command in a few minutes, with no manual `vendor/` file surgery needed this time. This does not affect the correctness of what was committed (`vendor/` is never committed either way).
- **Test-harness fix, not an application bug:** see §8's note on `Sanctum::actingAs()`/relation caching — the fix is confined to the two affected test methods; no application code changed as a result.

### Post-Review Correction (2026-09-12)

Product-owner review of the completed phase identified a genuine historical-integrity gap: the original design (`docs/phases/V1_PHASE_14_DEFINITION.md`'s Editing section, and the matching passage in `docs/DECISIONS.md` DEC-037, both since corrected) allowed an already-`published` Announcement's title/body/audience to still be edited. Because an employee's acknowledgement (`announcement_acknowledgements`) records that they acknowledged a *specific* Announcement — not a specific *version* of it, since no version concept existed — an Administrator could rewrite a published Announcement's content after employees had already acknowledged it, silently misrepresenting what those employees actually acknowledged. This directly contradicted the phase's own preserve-don't-mutate rationale for published company communications (DEC-009/DEC-010).

**Correction implemented, exactly as specified by the reviewer:**
- `AnnouncementController::update()` now rejects (`409`) any `PUT`/`PATCH` unless the Announcement is still `draft` — the same restriction `archived` already had, simply extended to cover `published` as well. The error message directs the caller to archive and create a corrected draft instead.
- `publish` → `archive` remains the only transition a `published` Announcement accepts; nothing else changed about the lifecycle, permissions, acknowledgement mechanism, or API surface (no new endpoints were added).
- **No revision/version history, content-version table, edit history, acknowledgement migration, acknowledgement invalidation, or acknowledgement-reset mechanism was introduced** — per the reviewer's explicit instruction, the fix is exactly "lock content at publication," nothing more.
- A misleading statement in the original Deletion vs. Archival reasoning ("no audience rows can exist yet either... impossible before publication") was also corrected — a **scoped draft** genuinely can have `announcement_departments`/`announcement_teams` pivot rows (an Administrator may set the audience before publishing); the actual reasoning for unconditional draft deletion is that those rows are safely deleted via the `announcement_id` cascade, and only *acknowledgement* rows are truly impossible for a draft (because drafts are never employee-visible).

**Tests corrected/added** (see §8): `test_administrator_can_edit_a_published_announcement` (which asserted `200`/success) was removed and replaced with three tests asserting `409` for title, body, and audience changes independently against a published Announcement, plus a fourth for an acknowledged published Announcement. Two further tests were added for archive-time acknowledgement preservation: `test_an_acknowledgement_survives_archive_unchanged` and a full `test_full_lifecycle_draft_publish_acknowledge_archive_retains_the_acknowledgement` integration test. The three draft-editing tests were split out explicitly (title/body/audience each its own test, up from one combined test) to make the draft-vs-published asymmetry unambiguous in the suite. All Phase 1–13 regression remained green throughout — see §10 for final counts.

No other deviation from `docs/phases/V1_PHASE_14_DEFINITION.md`.

## 12. Known Issues/Limitations

- No priority/importance field, no scheduled future publication, no expiry — none were required by the governing roadmap/technical documents (see the Definition doc's Explicitly Out of Scope section).
- No arbitrary individual-Staff targeting — audience is Department/Team-scoped or company-wide only, per the governing instructions' "likely useful minimum" guidance for a ~100-person company.
- No per-staff acknowledgement list/export on the management surface — only an aggregate count, to avoid crossing into an engagement/read-rate dashboard.
- As with every prior module, there is no Admin Backoffice (Blade/Livewire) CRUD UI and no Flutter mobile screens — API/backend only.

## 13. Manual/UAT Testing Instructions

1. Seed the database: `php artisan migrate:fresh --seed` (uses `RolePermissionSeeder`; add `AdminUserSeeder` separately for a local Administrator login).
2. As Administrator, create a Department (if none exists) and a Staff record/User linked to it.
3. `POST /api/v1/announcements` with `audience_type: scoped` and that Department's `public_id` in `department_ids` — confirm `201`, `status: draft`.
4. `POST /api/v1/announcements/{public_id}/publish` — confirm `200`, `status: published`, and a populated `published_at`.
5. Log in as the Department-linked Staff User and `GET /api/v1/me/announcements` — confirm it appears; `POST .../{public_id}/acknowledge` — confirm `200` and a populated `acknowledged_at`; call it again — confirm the same timestamp (idempotent).
6. Create a second Staff/User in a different Department, log in as them, and confirm `GET /api/v1/me/announcements` does **not** include it and its detail endpoint returns `404`.
7. As either Staff member, confirm `GET /api/v1/announcements` (the management endpoint) returns `403`.
8. As Administrator, `GET /api/v1/announcements/{public_id}` — confirm `acknowledgements_count: 1`.
9. **Content-immutability check (post-review correction):** as Administrator, attempt `PATCH /api/v1/announcements/{public_id}` with a changed `title`, `body`, and `audience_type`/`department_ids` (separately or together) against the now-published Announcement from step 4 — confirm `409` in every case, and that the record's title/body/audience are unchanged afterward.
10. `POST /api/v1/announcements/{public_id}/archive` — confirm `200`, `status: archived`; confirm it no longer appears in the Staff member's feed, and that further edit/publish/delete attempts on it all return `409`.
11. Confirm the acknowledgement from step 5 is still present and unchanged (`GET /api/v1/announcements/{public_id}` still shows `acknowledgements_count: 1`).
12. Attempt to delete the targeted Department or the acknowledging Staff member — confirm each is blocked with `409`.

No UAT `PASS` is recorded here — per CLAUDE.md §7, only the product owner may record that in `docs/testing/UAT_LOG.md`.

## 14. Documentation Updated

`docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/ROADMAP.md`, `docs/02_ARCHITECTURE.md` (§24), `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/DECISIONS.md` (DEC-037), `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`, `docs/phases/V1_PHASE_14_DEFINITION.md`, this handoff.

## 15. Recommended Next Step

Per `docs/ROADMAP.md`, the next planned phase is **Phase 15 — Notifications** — not authorized to begin without explicit product-owner direction.
