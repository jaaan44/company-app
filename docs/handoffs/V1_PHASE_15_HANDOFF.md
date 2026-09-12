# V1 Phase 15 — Notifications — Handoff

## 1. Phase Identification

- **Phase:** 15 — Notifications
- **Date:** 2026-09-12
- **Branch:** `claude/compassionate-hypatia-92za04` (branched from `main`, not merged)

## 2. Objective

Build the foundational in-app Notifications module: a lean, unified place for employees to see application-generated alerts about events elsewhere in the application — infrastructure plus exactly one wired producer (Announcement publish), per the roadmap's own "Phase 14 (first real notification producer)" dependency line. Not a messaging system, email platform, push infrastructure, or workflow engine.

## 3. Scope Implemented

- `notifications` table / `App\Models\Notification` — recipient identity is the `User` account (DEC-038), not `Staff`.
- `App\Enums\NotificationType` (`announcement_published`) and `App\Enums\NotificationSourceType` (`announcement`) — both closed, one-case enums, extended only alongside a real future producer.
- Read/unread state (`read_at`, nullable), server-controlled, idempotent mark-read (preserves the original first-read timestamp), and bulk mark-all-read.
- Self-service API, entirely under `/api/v1/me/notifications`: list (paginated, `?unread=1`, `?type=`), show, unread-count, mark-one-read, mark-all-read. No permission, and — uniquely among every `/me/...` surface in this API — no linked-Staff requirement either.
- Exactly one event integration: `AnnouncementController::publish()` fans out one Notification per resolved recipient, as a publish-time snapshot, inside the same DB transaction as the publish itself.
- Discovered-and-fixed: removed the unused, incompatible default-scaffold `Illuminate\Notifications\Notifiable` trait from `App\Models\User`.

## 4. Implementation Summary

**Recipient identity (DEC-038):** `notifications.recipient_user_id` → `users`, `cascadeOnDelete()`. Chosen over `staff_id` (and over duplicating both) because a Notification can only ever be consumed through an authenticated login, and DEC-030 already established Staff may exist without one — every producer already resolves to a `User` before creating a row, so no second identity column was warranted.

**Schema:** small and typed, not polymorphic. `source_type`/`source_public_id` is Option A among the phase's governing candidates (a typed pair) rather than a generic polymorphic Eloquent relation — only one concrete source type (`announcement`) exists in scope, matching this codebase's repeated preference (DEC-017, DEC-037) for a dedicated shape over generic polymorphism until a second/third type is actually in view. No separate `action_type`/`action_public_id` — `source` already tells a client what to navigate to.

**Creation architecture:** synchronous, direct Eloquent `create()` calls inside the triggering request's own transaction — no queue, no Laravel notification-channel/broadcasting package, no generic event-bus. `App\Http\Controllers\Api\V1\Announcements\Concerns\NotifiesAnnouncementAudience` (a plain trait, mirroring the codebase's existing `Concerns` pattern rather than introducing a new `Services`/`Actions` layer) resolves recipients with the same company-wide/scoped-Department-Team-union logic `ScopesAnnouncementVisibility` already uses for `/me/announcements`, evaluated once at publish time as a snapshot. A Staff member with no linked User is silently skipped. No idempotency key was needed: `AnnouncementController::publish()` can only ever succeed once per Announcement (its pre-existing `status !== draft` guard), so the fan-out structurally cannot double-fire, and `archive()` never re-notifies.

**Content:** deliberately generic. The one wired producer sets `title` to the Announcement's own (already-broadcast, non-sensitive) title and a fixed `message` — never the Announcement body.

**Authorization:** no new permission. `NotificationController::authorizeOwnership()` denies (`404`) any request for a Notification whose `recipient_user_id` doesn't match the authenticated User — not even Administrator can read another User's notification through this API.

**Discovered/fixed:** `App\Models\User` declared Laravel's default `Illuminate\Notifications\Notifiable` trait — confirmed via a full-codebase search to be used nowhere. Its own polymorphic `notifications`/`notify()` shape is fundamentally incompatible with this phase's dedicated `notifications` table (a future accidental `$user->notify(...)` call would have failed against the real schema). Removed; replaced with an explicit `User::notifications(): HasMany`.

## 5. Files Changed

**Added:**
- `apps/api/database/migrations/2026_09_12_150000_create_notifications_table.php`
- `apps/api/app/Models/Notification.php`
- `apps/api/app/Enums/NotificationType.php`
- `apps/api/app/Enums/NotificationSourceType.php`
- `apps/api/database/factories/NotificationFactory.php`
- `apps/api/app/Http/Resources/NotificationResource.php`
- `apps/api/app/Http/Controllers/Api/V1/Notifications/NotificationController.php`
- `apps/api/app/Http/Controllers/Api/V1/Announcements/Concerns/NotifiesAnnouncementAudience.php`
- `apps/api/tests/Feature/Api/V1/Notifications/NotificationTest.php`
- `apps/api/tests/Feature/Authorization/NotificationsAuthorizationTest.php`
- `apps/api/tests/Feature/Api/V1/Announcements/AnnouncementNotificationFanoutTest.php`
- `docs/phases/V1_PHASE_15_DEFINITION.md`
- `docs/handoffs/V1_PHASE_15_HANDOFF.md` (this file)

**Modified:**
- `apps/api/app/Models/User.php` — removed `Notifiable`, added `notifications(): HasMany`.
- `apps/api/app/Http/Controllers/Api/V1/Announcements/AnnouncementController.php` — `publish()` now runs inside `DB::transaction()` and calls `notifyAudience()`.
- `apps/api/database/seeders/RolePermissionSeeder.php` — docblock note only; no new permission rows.
- `apps/api/routes/api/v1.php` — new Notifications route group.
- `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/CHANGELOG.md`, `docs/CURRENT_STATE.md`, `docs/DECISIONS.md`, `docs/ROADMAP.md`.

## 6. Database/Schema Changes

One new table, `notifications`:
- `id` (bigint), `public_id` (ULID, unique)
- `recipient_user_id` (FK `users`, `cascadeOnDelete()`)
- `type` (string — `NotificationType`)
- `title` (string ≤200), `message` (string ≤500)
- `source_type` (string, nullable — `NotificationSourceType`), `source_public_id` (string, nullable)
- `read_at` (timestamp, nullable)
- `timestamps()`
- Indexes: `(recipient_user_id, read_at)`, `(recipient_user_id, created_at)`

No other table's schema changed.

## 7. API Changes

All new, all under `/api/v1/me/notifications`:

| Method | Path | Auth |
|---|---|---|
| GET | `/me/notifications` (`?unread=1`, `?type=`) | `auth:sanctum`, `account.active` |
| GET | `/me/notifications/unread-count` | `auth:sanctum`, `account.active` |
| POST | `/me/notifications/read-all` | `auth:sanctum`, `account.active` |
| GET | `/me/notifications/{public_id}` | `auth:sanctum`, `account.active` |
| POST | `/me/notifications/{public_id}/read` | `auth:sanctum`, `account.active` |

No permission required on any route. No management/supervisory surface, and no create/update/delete endpoint, exists at all.

## 8. Authorization/Security Changes

- No new permission defined or attached (`notifications.view`/`.manage` were considered and rejected).
- Ownership-only authorization: `recipient_user_id` must equal the authenticated User's id; otherwise `404` (not `403`).
- Content is deliberately non-sensitive — see §4.
- Removed the unused `Notifiable` trait (a latent schema-conflict landmine, not a security vulnerability that was ever exploitable, since nothing called it).

## 9. Tests Added or Changed

29 new tests, 109 assertions, across three files:
- `tests/Feature/Api/V1/Notifications/NotificationTest.php` (15 tests) — real end-to-end smoke test driven by an actual Announcement publish; listing (newest-first, pagination, `?unread=`, `?type=`); read-state semantics (default unread, idempotent mark-read, ownership); mark-all-read scoping and timestamp preservation; API shape (no internal ids, no recipient identity, bounded/null `source`); auth.
- `tests/Feature/Authorization/NotificationsAuthorizationTest.php` (5 tests) — unauthenticated/suspended rejection, every role can use its own inbox, no-role User can too, foreign notification is 404.
- `tests/Feature/Api/V1/Announcements/AnnouncementNotificationFanoutTest.php` (9 tests) — company-wide fan-out, Department-scoped, Team-scoped, union dedupe, Staff-without-account skipped, content privacy (no Announcement body leaked), no re-notify on archive, snapshot semantics (later org change doesn't alter existing rows), unrelated Staff receives nothing.

All 564 tests in the full suite (Phases 1–15) pass — no regressions.

## 10. Commands/Checks Executed

```sh
composer install --no-interaction --prefer-dist --no-progress   # COMPOSER_PROCESS_TIMEOUT=1800
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan migrate:fresh --force
php artisan migrate:fresh --force --seed
php artisan test
vendor/bin/phpunit --filter="NotificationTest|NotificationsAuthorizationTest|AnnouncementNotificationFanoutTest" --testdox
```

## 11. Results

- `composer validate --strict` → `./composer.json is valid`
- `vendor/bin/pint --test` → `{"tool":"pint","result":"passed"}`
- `vendor/bin/phpstan analyse` (Larastan level 5) → `{"tool":"phpstan","result":"passed","errors":0}`
- `php artisan migrate:fresh --force` → all 31 migrations run cleanly, including `2026_09_12_150000_create_notifications_table`.
- `php artisan migrate:fresh --force --seed` → `RolePermissionSeeder` runs cleanly; **23 permissions, 3 roles** (unchanged from Phase 14 — confirms no new permission was introduced).
- `php artisan test` → `{"tool":"phpunit","result":"passed","tests":564,"passed":564,"assertions":1530,"duration_ms":12454}` — full regression, zero failures.
- Phase 15 tests isolated → `{"tool":"phpunit","result":"passed","tests":29,"passed":29,"assertions":109}` — includes the real HTTP smoke test (`test_full_lifecycle_via_a_real_announcement_publish`): authenticate as Administrator → publish a real Announcement via `POST /announcements/{id}/publish` → authenticate as the recipient → `GET /me/notifications` shows it unread with correct type/title/source → `GET /me/notifications/unread-count` = 1 → `POST .../read` sets `read_at` → repeating `POST .../read` is idempotent (same timestamp) → `GET /me/notifications/unread-count` = 0 → a different User gets `404` on both `GET` and `POST .../read` for that notification's `public_id`.

**Environment note (this session):** the container started with no `vendor/`. The first `composer install --no-interaction --prefer-dist --no-progress` attempt (with `COMPOSER_PROCESS_TIMEOUT=1800`) showed the same failure shape Phase 13's session documented — nearly every dependency's dist zipball download failed (`Could not authenticate against github.com` / proxy `CONNECT`/SSL timeouts), falling back to VCS source cloning for each package in turn. Partway through, this was mistaken for a stalled `phpstan/phpstan` mirror clone (matching the *different* failure shape documented in Phases 9–12) and the process was killed prematurely; re-running the identical command from a clean `vendor/` let it run uninterrupted to completion (all packages resolved via VCS source fallback, no manual `vendor/` file surgery needed) — `composer install` itself reported `EXIT_CODE=0`. This does not affect the correctness of what was committed (`vendor/` is never committed either way).

## 12. Deviations from Specification

None from the governing instructions' actual requirements. Two implementation choices worth flagging as resolved judgment calls, both recorded in DEC-038:
- The instructions listed several source-reference options (typed fields, JSON metadata, polymorphic relation); Option A (typed fields) was chosen, matching this codebase's established anti-polymorphism precedent.
- The instructions asked whether recipients should be User- or Staff-based; User was chosen, per the instructions' own "strong candidate" steer, backed by DEC-030's existing optional Staff↔User linkage.

## 13. Known Issues/Limitations

- Leave Management and Task events are not wired into Notifications — the roadmap names only Announcements as Phase 15's producer; extending to Leave/Tasks is future work (see DEC-038, `docs/ROADMAP.md`).
- No Flutter UI and no Admin Backoffice surface — consistent with every prior module's precedent for a backend-only phase.
- A Notification may remain visible after its source becomes inaccessible (e.g., a future permission change on the source module) — documented as an accepted gap in `05_SECURITY_MODEL.md`, since no sensitive content ever lives in the Notification row itself.

## 14. Manual/UAT Testing Instructions

1. Log in as an Administrator (Sanctum bearer token) and create + publish an Announcement (`POST /api/v1/announcements`, then `POST /api/v1/announcements/{public_id}/publish`).
2. Log in as an employee within that Announcement's audience (linked Staff, matching Department/Team for a scoped Announcement, or any Staff-linked account for company-wide).
3. `GET /api/v1/me/notifications` — confirm one unread notification appears with `type: "announcement_published"`, the Announcement's title, and a `source` pointing back to it.
4. `GET /api/v1/me/notifications/unread-count` — confirm `1`.
5. `POST /api/v1/me/notifications/{public_id}/read` — confirm `read_at` is now set; repeat the call and confirm the timestamp doesn't change.
6. `POST /api/v1/me/notifications/read-all` with another unread notification present — confirm only that account's unread rows update, and the response reports a bounded `updated_count`.
7. Log in as an unrelated User and request the first employee's notification `public_id` — confirm `404`.

This is **Implemented** and **Tested automatically** (see §9/§11). Not yet **Manually verified** by a human, and **Awaiting UAT** — no `UAT_LOG.md` entry has been recorded, and none will be marked `PASS` except by the product owner.

## 15. Documentation Updated

`docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/ROADMAP.md`, `docs/DECISIONS.md` (DEC-038), `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/phases/V1_PHASE_15_DEFINITION.md`, and this handoff.

## 16. Recommended Next Step

Phase 16 — Messaging (per `docs/ROADMAP.md`), which itself depends on Phase 15 for "notification of new messages." **Not authorized to begin** — awaiting explicit product-owner authorization, per CLAUDE.md §8's Stop Discipline.
