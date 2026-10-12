# Phase 33 — Field Reporting: Service Reports, Incident Reports & Attachments (Mobile) — Specification

**Status:** Revision 1 (2026-10-12). **Decisions R-1…R-13 approved under the product owner's blanket authorization (DEC-059).** Implementation is authorized through Gate 4; merged on green CI; UAT in the combined round. **Flagged as sensitive:** a new runtime dependency (`image_picker`), the app's first file upload, and its first binary download (R-8, R-9). The shipped permission list is a hard gate in the UAT APK step.

**Depends on:**
- Phase 18 (Service Reports, DEC-041) and Phase 19 (Incident Reports, DEC-042), sharing the attachment infrastructure (private disk, JPEG/PNG/PDF, 10 MB, authorized download only).
- Phases 27–32 (the `ApiClient` rules, paged lists, confirmed writes, the no-profile `403`, and the company day DEC-052).

## 1. Objective

Let field staff write service reports and report incidents from the phone, with photos, and follow their status.

## 2. Current-State Findings (read-only discovery on `main` with Phase 32)

| Area | Behaviour today |
|---|---|
| `GET /service-reports`, `/incident-reports` | Visibility-scoped. Service reports: creator, participant, the creator's direct Manager, the project's Lead, Administrator. Incidents: similar, plus the assigned investigator. Filters include `staff` and `reporter`. Ordered by `service_date` or `occurred_at`, then `created_at` (**no unique tie-breaker**). No company day. |
| `POST /service-reports` | `client_id` (required), optional `project_id`/`task_id` (must be a member or the assignee), `service_date` **`before_or_equal:today` — the UTC today**, so between 00:00 and 07:59 Manila today's date is refused (**defect**, as Phase 29B fixed for work logs). `work_performed` (required, ≤10000); findings, recommendations and follow-up (≤5000); `site_representative_name` (≤255); participants. Created as `draft`. Linked active Staff only. |
| Service report lifecycle | Draft → (creator) `submit` → submitted → (Manager or Lead) `review` or `reject` (reason) → reviewed / rejected → (creator) `return-to-draft`. Edit, delete and attachments only while draft, and only by the creator (or Administrator). |
| `POST /incident-reports` | `incident_type` (6 types), `severity` (default `medium`), `occurred_at` (≤ now), location, `description` (required) and seven optional narrative fields; optional client, project and task. Created as `reported`. |
| Incident lifecycle | Reported → (Manager) assign → start investigation → resolve → close, and reopen. The **reporter** may edit and attach only while `reported`; afterwards the investigator, the Manager or an Administrator may. |
| Attachments | `POST …/attachments` (`multipart/form-data`, field `file`, JPEG/PNG/PDF, 10 MB, content-sniffed); `GET …/attachments/{id}/download` (streamed, authorized); `DELETE`. Service reports: draft only. Incidents: reported or under investigation. |

**Mobile:** no reports; `ApiClient` sends JSON only.

## 3. Product Decisions (R-1…R-13, approved under DEC-059)

| # | Decision | Why |
|---|---|---|
| **R-1** | **More → Reports**: one screen with two segments, **Service reports** and **Incidents**. Each lists the reports I can see, newest first, 20 a page, with status chips; a **New** button. | One home for field reporting. |
| **R-2** | **Backend fixes:** (a) a service report's `service_date` may be no later than the **company** today (create and update), else `422` "The service date cannot be later than today."; (b) `id` tie-breakers on both lists; (c) `meta.company_day` on both lists. | DEC-052; stable paging; company-time display. |
| **R-3** | **New service report:** a client (search the active clients), an optional project (one of my projects), a service date (default and maximum the company today), work performed (required), findings, recommendations, follow-up actions, and the site representative. Saved as a **draft**, which opens its detail. No participants or task on mobile. | The field record; the rest stays on the web. |
| **R-4** | **Service report detail:** the client, project, date, status chip, every text field, the history (company time, notes such as a rejection reason) and the attachments. While it is **my draft**: **Edit**, **Add photo**, remove a photo, **Submit** (confirmed: "Your manager will review it. You can't edit it after submitting.") and **Delete draft** (confirmed). While **my report is rejected**: **Return to draft**. All confirmed by the server, never optimistic. | The creator's lifecycle exactly as the API allows. |
| **R-5** | **Report an incident:** type, severity (default Medium), when it happened (date and time; default now; not in the future), location, description (required), immediate action taken, people involved, and witness notes. Created as **reported**. No client, project, task or participants on mobile. | Fast reporting from the scene. |
| **R-6** | **Incident detail:** type, severity and status chips, when (company time), location, reporter, assigned to, the narrative fields, history and attachments. While it is **mine and still reported**: **Edit** and **Add photo**, and remove a photo. Investigation actions (assign, investigate, resolve, close, reopen) stay on the web. | The reporter's part; investigation is a desk task. |
| **R-7** | **Photos as attachments:** **Take photo** (camera) or **Choose photo** (gallery), scaled to at most 2560 px at JPEG quality 85, checked against 10 MB before sending. Uploaded once, with progress shown; a failure keeps nothing and says why. Photos open full screen, read through the authorized download into memory, never saved to the device. PDFs show their name and size with "Open it on the web" (no PDF upload or viewer on mobile). | Evidence from the field without a document system on the phone. |
| **R-8** | **Package:** `image_picker` **1.2.3** with `image_picker_android` **0.8.13+23**, pinned exactly. Only `pickImage` is used, through one `PhotoSource` adapter (the only importer). **No new Android permission:** the camera opens through the system camera app, and the gallery uses Android's photo picker. The UAT APK gate requires the permission list to be **unchanged** from 30B. | Camera and gallery with the least privilege. |
| **R-9** | **`ApiClient` gains `postMultipart` (one file plus fields) and `getBytes` (authorized download)**, with the same 401/403 rules and no retry. | One place for the session rules. |
| **R-10** | **No profile:** creating and listing need a linked Staff record. A `403` shows "No staff profile is linked to this account." | The established pattern. |
| **R-11** | **A `ReportChanges` notifier** reloads both lists after a confirmed save, submit, delete, return or attachment change. | The `*Changes` pattern. |
| **R-12** | **Not in Phase 33:** review and reject, the incident investigation workflow, participants, linking a task, PDF upload, offline drafts, signatures, editing after submission, and report exports. | Scope; desk work stays on the web. |
| **R-13** | **No new permission or migration.** One new dependency (R-8). | — |

## 4. Gates

1. **Backend:** R-2, with tests for 00:30 and 23:30 Manila, the SQL-pinned tie-breakers on both lists, and `company_day`.
2. **Mobile data and state:**
   - `ApiClient.postMultipart` and `getBytes`, tested with the same 401/403/422 rules;
   - `lib/features/reports/`: strict models, the API client, controllers (both lists, both forms, both details with attachments), `ReportChanges`, and the `PhotoSource` adapter with a fake in tests;
   - the dependency pinned, and a test proving one importer.
3. **Mobile UI:** More → Reports, the lists, the forms, the details, the photo flow and the full-screen viewer.
4. **Integration review and PR:** full gates, contract parity (including a real multipart upload and a real download), docs, DEC entry, UAT rows, handoff, PR, and merge on green CI.

## 5. UAT scenarios (all `NOT RUN`; run in the combined round)

| ID | Scenario |
|---|---|
| UAT-33-01 | More → Reports shows my service reports and incidents newest first with status chips; no profile shows the no-profile message. |
| UAT-33-02 | New service report for a client (and one of my projects) dated today — including between 00:00 and 07:59 Manila — saves as a draft; a future date is refused. |
| UAT-33-03 | On the draft: take a photo and choose one from the gallery; both upload and open full screen; remove one; edit a field; submit; the draft can no longer be edited or attached to. |
| UAT-33-04 | Rejected by the manager (on the web): the reason shows in the history; Return to draft, edit and submit again. Delete a different draft. |
| UAT-33-05 | Report an incident with type, severity, time and description; add a photo; edit it while reported; once the manager starts an investigation (web) the app offers no Edit or Add photo. |
| UAT-33-06 | Offline: saving, submitting and uploading fail clearly and nothing is created twice; an oversized image is refused before sending. |
| UAT-33-07 | Android: the first Take photo opens the camera without any app permission prompt; the app's permission screen lists only location (from 30B); a PDF attached on the web shows as "Open it on the web". |
| UAT-33-08 | Dark mode and large text on the lists, both forms and both details. |

## 6. Out of Scope

Everything in R-12, plus a Home tile for reports, notifications about report decisions (the API has none), and map locations.

## Notes

- **Revision 1 (2026-10-12):** discovery on `main` after Phase 32; R-1…R-13 recorded as approved under DEC-059.
