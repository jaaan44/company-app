# 00 — Project Charter

## What Company App Is

Company App is an internal company operations and communication platform. It centralizes staff, client, project, task, HR, scheduling, communication, and operational-reporting workflows that would otherwise live in disconnected spreadsheets, chat tools, and paper processes.

## Planned System

- **Laravel backend/API** — canonical business logic and data store
- **Laravel web-based Admin Backoffice** — internal administration and management UI
- **Flutter mobile application** — the primary interface for staff (field and office)
- **Relational database** — system of record
- **Redis / queue infrastructure** — where appropriate (jobs, caching, broadcasting)
- **Real-time capabilities** — where appropriate (messaging, notifications, live status)

## Scope Areas (V1 Direction)

Staff · Clients · Contacts · Current Staff Status · Location Check-in · Projects · Leave Requests/History · Tasks · Work Logs · Simple Messaging/Chat · Scheduler · Service Reports · Announcements · Incident Reports · Notifications · Administrative Reporting · Roles & Permissions · Audit Logging

## Product Principles

1. **Repository is the source of truth.** Conversation history is never required to understand project state. A new developer or new AI session must be able to determine the application's purpose, architecture, rules, completed/current/planned work, key decisions, known issues, and test status entirely from repository documentation.
2. **Controlled incremental development.** Development proceeds through explicitly authorized phases. Future phases are never implemented early.
3. **Scope discipline.** Only functionality explicitly in the active phase gets built. No speculative features, no unnecessary abstractions.
4. **Security by design.** Authorization and data isolation are deliberate design decisions, not afterthoughts. Sensitive administrative operations must eventually be auditable.
5. **Testing matters.** Automated tests where appropriate; manual/UAT testing tracked explicitly and never fabricated.
6. **Maintainability over cleverness.** Prefer clear, conventional architecture (idiomatic Laravel, idiomatic Flutter) over unnecessary complexity.

## Out of Scope (V1)

- Continuous/background GPS tracking (see DEC-005 — explicit check-ins only)
- Full-featured chat platform parity with Slack/Teams (see DEC-007 — messaging stays simple)
- Multi-tenant / multi-company support (not currently planned; single organization)
- Public-facing client portal (this is an *internal* operations tool)

These exclusions are current-direction, not permanent — they may be revisited via a new dated decision if the product direction changes.

## Governance

This project is governed by a phase-based process. See `docs/ROADMAP.md` for the phase sequence, `docs/DECISIONS.md` for the decision ledger, and `CLAUDE.md` for the operating rules that bind every implementation session (human or AI).

## Status

As of Phase 0 (2026-09-09), this is a **documentation and governance foundation only**. No application code, database schema, or infrastructure exists yet. See `docs/CURRENT_STATE.md` for the live status.
