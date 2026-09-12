# Phase 17 — Scheduler — Specification

**Status:** COMPLETE (pending product-owner review)
**Depends on:** Phase 13 (Leave), Phase 11 (Task deadlines), Phase 10 (Projects — Milestones deferred there, implemented here)

## Objective

Build a unified schedule/calendar surface for Company App, per a product-owner-reviewed planning audit (see `docs/DECISIONS.md` DEC-040) that resolved the roadmap's terse one-line description into concrete architecture: a hybrid Scheduler combining (1) a read-time aggregation API over existing modules and (2) a lightweight, Scheduler-owned entity for activities with no other system-of-record module. This phase also builds the minimal Project Milestone concept the Phase 17 roadmap dependency incorrectly assumed Phase 10 had already built — Phase 10 explicitly deferred it (see `docs/phases/V1_PHASE_10_DEFINITION.md`'s own exclusions and `03_DATABASE_MODEL.md` §1).

## In Scope

- `schedule_entries` / `schedule_entry_participants` — a Scheduler-owned entity for manually created activities (meetings, client visits, service appointments, company events, training, other internal activities — `App\Enums\ScheduleEntryActivityType`), with a simple Staff participant list (no RSVP state) and an optional Project link.
- `project_milestones` — a minimal target-date marker per Project (`App\Enums\ProjectMilestoneStatus`: `pending`/`completed`/`cancelled`), nested under its Project.
- `GET /api/v1/schedule` — a unified, read-time aggregation over exactly four sources (manually created Schedule Entries, Task due dates, approved Leave Requests, Project Milestones), each tagged with a closed `App\Enums\ScheduleSourceType` discriminator, each governed by that source module's own existing visibility rule. No calendar rows are copied into a generic table for any of the three aggregated sources — each remains its own system of record.
- Full CRUD for Schedule Entries (`/api/v1/schedule-entries`) and Project Milestones (`/api/v1/projects/{project}/milestones`), authorized entirely via established row-level, in-controller patterns (mirroring Tasks' Project Lead carve-out, DEC-034) — no new permission was introduced.
- Real time-of-day scheduling for Schedule Entries: UTC-stored `starts_at`/`ends_at`, a single configurable company timezone (`config('scheduling.company_timezone')`, `App\Support\CompanyTimezone`), and all-day semantics (a single pair of datetime columns used for both timed and all-day entries — see the Date/Time Design section below).
- Relational-integrity protections extending `StaffController::destroy`/`ProjectController::destroy`, consistent with every prior module.
- Correcting `docs/ROADMAP.md`'s Phase 17 entry, which listed "Phase 10 (Milestones)" as a dependency despite Phase 10 never having built it.

## Explicitly Out of Scope

- Recurring events, recurrence rules, generated occurrences, exception dates, or "edit this occurrence vs. the series" behavior.
- RSVP/invited/accepted/declined/tentative attendee state — participation means only "appears on this Staff member's schedule."
- Reminders of any kind (at-creation or time-based) and Notification integration for Schedule Entries — no Scheduler Notification producer was wired.
- Cron, a Laravel Scheduler worker, queues, Redis, or any background/scheduled-job infrastructure.
- Conflict/overlap detection — overlapping Schedule Entries are valid data.
- Department- or Team-linked Schedule Entries (Project-linked only).
- Attachments/files.
- Google Calendar, Outlook, or `.ics` import/export/feed integration.
- Flutter mobile Scheduler UI and Admin Backoffice Scheduler UI.
- Scheduler-specific audit logging (Audit Logging, DEC-009, remains an unbuilt, pre-existing, project-wide gap).
- WebSockets/Reverb, Redis, queues/broadcasting, rich text.
- Dedicated Meeting/Visit/Appointment/Training/Company Event tables or modules — these are all `ScheduleEntryActivityType` values on the one generic Schedule Entry.
- Per-user/per-Staff timezone fields — a single company-wide timezone only.
- A new `schedule.view`/`schedule.manage` permission — visibility/authority is resolved entirely via established row-level patterns.
- Percent-complete, dependency graphs, nested milestones, milestone recurrence, or any Milestone workflow engine.

## Relevant Documentation

- `docs/DECISIONS.md` DEC-040 (this phase's approved architecture).
- `02_ARCHITECTURE.md` §0 (resource-efficiency direction), §27 (new section this phase adds).
- `03_DATABASE_MODEL.md` §1 Scheduling group (the `calendar_events` open question this phase resolves).
- `04_API_CONVENTIONS.md` (flat filterable top-level resources, standard pagination, ISO-8601 UTC timestamps).
- `05_SECURITY_MODEL.md` (row-level authorization, 404-vs-403 privacy convention, least privilege).

## Acceptance Criteria

- `GET /api/v1/schedule` requires `from`/`to`, aggregates exactly the four approved sources with correct per-source visibility, uses the standard Laravel pagination response shape, and leaks no internal numeric IDs.
- Schedule Entry and Project Milestone CRUD enforce the approved authorization model (creator/Project Lead/Administrator manage; participant/Project-visibility read-only; unrelated Staff 404).
- All-day and timed Schedule Entries validate coherently and store/display UTC ISO-8601 timestamps.
- No calendar data is duplicated from Tasks, Leave Requests, or Project Milestones into a generic table.
- Full Phase 1–16 regression suite plus this phase's new tests pass; `composer validate --strict`, `vendor/bin/pint --test`, and `vendor/bin/phpstan analyse` are clean.
- `docs/ROADMAP.md`'s Phase 17 → Phase 10 Milestones dependency is corrected to reflect that Milestones were actually introduced in this phase.

## Testing Expectations

See `docs/handoffs/V1_PHASE_17_HANDOFF.md` for the full, itemized test list and quality-gate results (Schedule Entry CRUD/authorization/participants/timing validation, Project Milestone CRUD/authorization/status validation, unified aggregation source discriminator/filters/date-range boundaries/pagination/visibility preservation per source, relational integrity, suspended-account behavior, full regression).

## Notes

- The unified aggregation endpoint is fed by an in-memory merge of each source's own small, independently authorized, date-range-scoped query rather than one SQL query — see the handoff's Deviations section for the full reasoning (a genuine multi-source aggregation across four differently-shaped, differently-secured tables cannot be expressed as one portable SQL query without a fragile UNION; at this company's scale the merge-then-paginate approach is simple, correct, and still returns Laravel's standard `LengthAwarePaginator` response shape).
- A Staff member always sees their own approved Leave in the aggregation (mirroring their existing `/me/leave-requests` access) — this is not a new visibility grant, just this endpoint reusing existing self-service access instead of requiring a second round-trip.
