# Phase 31 — HR: Leave Management (Mobile) — Specification

**Status:** Revision 1 (2026-10-12). **Decisions R-1…R-12 approved under the product owner's blanket authorization of 2026-10-12** (DEC-059): "proceed and finish this whole Employee mobile application (meaning until phase 33)", with the AI's recommended decisions, merges on green CI, and one combined staging and UAT round at the end. Implementation is authorized through Gate 4.

**Depends on:**
- Phase 13 (Leave Management API, DEC-036): leave types, the request lifecycle, derived balances.
- Phase 27–30: the `ApiClient` and its 401/403 rule, the More menu, paged lists, confirmed (non-optimistic) writes (29A), the no-profile `403` pattern (29B R-13, 30B R-15) and the company day (DEC-052).

## 1. Objective

Let staff see their leave balances and requests, request leave and cancel it from the phone, and let Managers approve or reject their direct reports' requests.

## 2. Current-State Findings (read-only discovery on `main` at `fa52d42`)

**Endpoints (Phase 13), unchanged since:**

| Endpoint | Behaviour today |
|---|---|
| `GET /me/leave-requests` | My requests; filters `status`, `type`, `from`, `to`; ordered by `start_date DESC` only (**no tie-breaker**); `per_page` default 50. |
| `POST /me/leave-requests` | `leave_type_id` (active type's `public_id`), `start_date`, `end_date` (≥ start, **same calendar year**), `reason` (3–1000). Days are **inclusive calendar days** (weekends included). Paid types check the balance (`409` "Insufficient leave balance: N day(s) remaining…"). An overlapping pending or approved request is refused (`422`, or `409` in the race check). |
| `GET /me/leave-requests/{id}` | One of mine, with `history` (submitted/approved/rejected/cancelled, actor, note, time). Someone else's: `404`. |
| `POST /me/leave-requests/{id}/cancel` | Optional `reason`. Pending: always. Approved: only before it starts — judged by `start_date->isPast()`, i.e. **UTC midnight**. With Asia/Manila that is 08:00 on the start day, so from 00:00 to 07:59 Manila an already-started leave can still be cancelled. **Defect.** |
| `GET /me/leave-balances?year=` | One row per **active** type: `allocated_days` (null for unpaid), `used_days`, `pending_days`, `remaining_days`, `notes`. The default year is `date('Y')`, the **server (UTC) year**: on 1 January between 00:00 and 07:59 Manila it shows the old year. **Defect** (DEC-052). |
| `GET /leave-types?status=active` | All roles (`leave-types.view`). |
| `GET /leave-requests` | Supervisory: Administrator all; a `leave-requests.view` holder (Manager) **only direct reports**; Staff `403`. Same order without tie-breaker. |
| `POST /leave-requests/{id}/approve` / `reject` | Administrator, or the requester's **direct Manager** (who must hold `leave-requests.view`); never one's own. Approve: optional `note`; reject: `reason` 3–1000. Not pending: `409`. |

- **No linked Staff record:** every `/me/leave-*` call answers `403` "No staff record is linked to this account."
- **No company day** in any leave response.
- No leave notifications exist (a Manager learns of a new request only by looking).
- Approved leave already appears in the Schedule tab (Phase 30A) and its type name is the title.

**Mobile:** no leave code. More has six rows.

## 3. Product Decisions (R-1…R-12, approved under DEC-059)

| # | Decision | Why |
|---|---|---|
| **R-1** | **More → Leave** (a seventh row, "Leave", subtitle "Balances and requests"). One screen: my balances for the **company** year, then my requests newest first with status chips, **Request leave**, and — for Managers with direct reports — **Team requests (N pending)**. | One place for leave; balances in context when requesting. |
| **R-2** | **Backend fixes, small:** (a) balances default to the **company** year; (b) self-cancel of approved leave is refused once `start_date ≤ company today`; (c) `id` tie-breakers on `/me/leave-requests` and `/leave-requests`; (d) `meta.company_day {date, timezone, utc_offset}` on `GET /me/leave-requests`. | DEC-052; stable paging; the app judges "can cancel" and defaults dates from the company day. |
| **R-3** | **Team requests for Managers:** a new `GET /me/team-leave-requests?status=` — requests of **my direct reports only** (`staff.manager_id = me`), for a `leave-requests.view` holder; anyone else gets an empty list with `meta.can_review: false` (not a `403`: the app asks on every Leave load, and a `403` costs an `/auth/me` re-check each time); `meta.can_review`, `meta.direct_reports_count`, `meta.pending_count` and `meta.company_day`; default `status=pending`; ordered `start_date ASC, id ASC` for pending (soonest first), `start_date DESC, id DESC` otherwise. Decisions use the existing approve/reject endpoints. | The supervisory list is company-wide for Administrators; on mobile everyone — Administrators included — sees only their own reports. |
| **R-4** | **No profile:** keep the `403`; the app shows "No staff profile is linked to this account." (the 29B/30B pattern). | No contract change. |
| **R-5** | **Request leave form:** type (active types; paid ones show "N days left"), start and end date (pickers; default the company today; past dates allowed, as the API allows — e.g. sick leave), the computed **"N calendar days (weekends included)"**, reason (3–1000). Same-year and end ≥ start checked before sending. Sent once; the server's `422`/`409` message is shown as is (overlap, balance, inactive type). | The API's rules, explained honestly. |
| **R-6** | **Request detail:** type (paid/unpaid), dates, days, status chip, reason, the **history** (who, what, when in company time, note). **Cancel request** for pending, and for approved not yet started (company day); confirmation with an optional reason. Confirmed, never optimistic. | The lifecycle as the API keeps it. |
| **R-7** | **Team request detail:** the requester's name, type, dates, days, reason, history, and — while pending — **Approve** (optional note) and **Reject** (reason required, 3–1000), each confirmed. After a decision the list refreshes; a `409` ("no longer pending") is shown and the list refreshes. | Managers decide from the phone; the API stays authoritative. |
| **R-8** | **Status chips:** Pending, Approved, Rejected, Cancelled — label always written; Rejected in the error role with text, Cancelled muted. | The Phase 29 chip conventions. |
| **R-9** | **Balances:** per active type: "N days left of M" for paid (with used and pending), "Unpaid — no balance" for unpaid; the year shown. No year switcher. | The common question is "how much do I have left this year". |
| **R-10** | **No leave notifications, attachments or half days** in Phase 31. | Notifications are Phase 32's inbox (reading existing ones); attachments and half days don't exist in the API. |
| **R-11** | **After any change** (submit, cancel, decide), the Leave screen and Schedule reload when next shown (a `LeaveChanges` notifier, as `TaskChanges`). | Approved leave appears in Schedule without a manual refresh. |
| **R-12** | **No new permission, migration or dependency.** | — |

## 4. Gates

1. **Backend:** R-2 (a–d) and R-3, with tests (company year at 07:00 Manila on 1 January; self-cancel at 00:30 and 23:30 Manila on the start day; tie-breakers pinned in SQL; team list scoping — a Staff member gets nothing with `can_review: false`, a Manager's direct reports only, an Administrator's direct reports only, counts, order, filters).
2. **Mobile data and state:** `lib/features/leave/` — strict models, `LeaveApiClient`, controllers (my leave, request form, request detail, team list, team decision), `LeaveChanges`.
3. **Mobile UI:** the Leave row and screens; routes in the More branch; Schedule refresh on leave changes.
4. **Integration review and PR:** full gates, contract parity against real Laravel output, docs, DEC entry, UAT rows, handoff, PR; merge on green CI (DEC-059).

## 5. UAT scenarios (all `NOT RUN`; run in the combined round)

| ID | Scenario |
|---|---|
| UAT-31-01 | More → Leave shows this year's balances (paid: days left of allocated; unpaid labelled) and my requests newest first with status chips. |
| UAT-31-02 | Request leave: choose a paid type (its days left shown), dates within this year, see "N calendar days (weekends included)", give a reason, submit; the request appears as Pending and the balance's pending days rise. |
| UAT-31-03 | Refusals: dates across two years, end before start, a too-short reason, overlapping dates, more days than remain — each refused with a clear message and nothing created. |
| UAT-31-04 | Cancel a pending request (with a reason); cancel an approved future request; an approved request that has started offers no Cancel. The history shows each step. |
| UAT-31-05 | As a Manager: Team requests shows only my direct reports' pending requests, soonest first; approve one with a note and reject one with a reason; the requester then sees the decision and history; approved leave appears in the requester's Schedule. |
| UAT-31-06 | As a Staff member without reports, no Team requests; as an Administrator who manages nobody, no Team requests; a user without a profile sees the no-profile message. |
| UAT-31-07 | Offline: loading and submitting fail clearly and nothing is created; after token revocation the app returns to Login. |
| UAT-31-08 | Dark mode and large text on the Leave screen, the form, a request and a team request. |

## 6. Out of Scope

Leave notifications or reminders; attachments (medical certificates); half days or hours; a holiday calendar or working-day counts; editing a request (cancel and resubmit); Administrator company-wide approvals on mobile; balance administration; reports.

## Notes

- **Revision 1 (2026-10-12):** discovery on `main` at `fa52d42`; decisions R-1…R-12 recorded as approved under DEC-059's blanket authorization.
