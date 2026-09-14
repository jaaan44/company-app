# Phase 22 — Security Audit — Audit & Planning Report

**Status:** AUDIT COMPLETE, REMEDIATION COMPLETE — see the Post-Implementation Remediation Status addendum at the end of this document for what was actually implemented, dropped, or deferred, and `docs/handoffs/V1_PHASE_22_HANDOFF.md` for the full implementation account. The remainder of this document is preserved exactly as originally written (per CLAUDE.md's "do not rewrite historical decisions unnecessarily") — it reflects the audit's own findings and recommendations at the time it was authored, before the product owner's remediation-scope decisions below.
**This is not a phase specification.** No previous phase produced a file-based "planning audit" (Phases 17–21's planning audits were delivered as chat messages ahead of implementation); this document is the first one committed to the repository, per this session's explicit instructions. It follows the `docs/phases/V1_PHASE_<NN>_*.md` naming and location convention but is deliberately **not** named `V1_PHASE_22_DEFINITION.md`.

**Date:** 2026-09-14 (audit); implementation completed the same day following explicit product-owner authorization.
**Session type (original):** Repository inspection, security audit, scope determination, and planning only. **No application code was changed** in the audit session itself. A separate, later session implemented the authorized remediation scope — see the addendum below and the Phase 22 handoff.
**Repository state audited:** `main` at `ada32a6` (Phase 21 — Integration Audit — merged via PR #23). Working tree confirmed clean before and after the audit; no unrelated files modified.

---

## 1. Executive Summary

Company App's backend (Laravel 13.31/PHP 8.4) and mobile client (Flutter 3.47/Dart 3.13) were audited across authentication, authorization/IDOR, input validation/injection, file uploads, secrets/configuration, logging, CI/CD, dependencies, and mobile-client handling. The audit combined a full re-read of the project's own extensive documentation (`docs/05_SECURITY_MODEL.md`, `docs/DECISIONS.md`, all 21 phase handoffs) with direct, evidence-based code inspection (route-by-route, controller-by-controller, trait-by-trait) rather than relying on the documentation's own self-description.

**Result: no Critical or High severity findings.** The codebase's authorization architecture — eleven distinct, well-documented row-level visibility "shapes" layered under a centralized `Gate::before` permission system — was independently verified to match its documentation almost exactly, including in areas most prone to IDOR (self-service "me" endpoints, `withoutScopedBindings()` nested routes, and file-attachment access). Mass assignment is allowlisted on every model via Laravel 13's `#[Fillable]` attribute. No SQL injection, command injection, or path-traversal vector was found anywhere in `app/`. No committed secrets exist in the working tree or in git history. Dependency scanning (`composer audit --locked`) found no known vulnerabilities in any PHP package; Flutter dependencies are current-generation.

One **Medium** finding was confirmed: the login endpoints (both the mobile API and the Admin Backoffice) return a distinguishable error message for a suspended/inactive account versus a wrong password, which contradicts `docs/05_SECURITY_MODEL.md`'s own stated guarantee that login "never confirms or denies whether a given email is registered." This is a genuine information-disclosure/doc-code mismatch and is the sole item this report recommends as a **required** Phase 22 fix. Everything else found is Low or Informational — legitimate hardening opportunities appropriate to note and cheaply close, but none represents an active, exploitable vulnerability at this application's ~100-employee scale.

**Proposed Phase 22 scope is therefore narrow**: one required fix, a short list of inexpensive hardening items (CI workflow permissions, Sanctum token expiration, a locked-down `config/cors.php`, disabling the dormant `local` disk `serve` route, a tightened `.gitignore` pattern, adding `composer audit` to CI), and everything else explicitly deferred with rationale (most of it already deliberately deferred by prior phases' own decisions, e.g. antivirus scanning, retention/purge jobs, Messaging moderation, and — largest of the deferred items — a User account-management (suspend/reactivate/role-change) mutation surface, which is new product behavior, not a security defect, and was already flagged as a known gap in DEC-044).

---

## 2. Repository / Security Context

- **Branch/commit audited:** `main` @ `ada32a6` (merge commit for PR #23, `claude/phase-21-integration-audit` → `main`). This session's own branch (`claude/eloquent-dijkstra-r1a09v`) is at the same commit — a pure fast-forward, no divergent history, working tree clean throughout.
- **Product:** Company App — an internal operations/communication platform for ~100 employees. Laravel 13 API (Sanctum bearer tokens) + Blade/Livewire Admin Backoffice (session cookies) + Flutter mobile client (currently auth + a minimal home shell only — no business-module UI has been built yet, consistent with every phase since Phase 6 shipping API-only).
- **Phases 1–21 status:** all merged into `main`. Phase 21 (Integration Audit) added a general, cross-module `audit_logs` table and closed the standing DEC-009 gap; it also ran its own bounded cross-module consistency audit (documentation drift + a transaction-boundary correction), described in `docs/handoffs/V1_PHASE_21_HANDOFF.md`.
- **What Phase 22 was intended to accomplish, per the repository:** `docs/ROADMAP.md` names Phase 22 tersely as "review against `05_SECURITY_MODEL.md`." No prior phase pre-scoped it further (unlike Phases 17–21, each preceded by a chat-delivered planning audit) — the repository's own instruction (`docs/CURRENT_STATE.md`'s "For the Next Session" pointer) is explicit that Phase 22 needs product-owner authorization and "must not begin based on the roadmap alone."
- **Security work already completed in earlier phases** (see `docs/05_SECURITY_MODEL.md` for the full ledger): Sanctum/session authentication (Phase 4), centralized `Gate::before` RBAC (Phase 5), eleven distinct row-level authorization shapes across Phases 9–21, rate-limited login (Phase 4), account-state enforcement via `EnsureAccountIsActive` (Phase 4), `flutter_secure_storage` token persistence (Phase 4, DEC-026), file-upload authorization infrastructure (Phase 18/19, DEC-041/042), and the general Audit Log (Phase 21, DEC-009/044).
- **Security concerns explicitly deferred to/through Phase 22 by earlier phases:** password reset/email verification (deferred since Phase 4, still unbuilt), antivirus/malware scanning for attachments (deferred since Phase 18), Sanctum token abilities/scoping ("revisit if a second API-consuming client type is added," `05_SECURITY_MODEL.md` API Access), a User account-management mutation surface (DEC-044's explicit Known Limitation), Messaging moderation/investigation capability, check-in/audit-log retention/purge policies.
- **What Phase 21 surfaced that feeds into Phase 22:** DEC-044's Known Limitation (no User suspend/reactivate/role-change endpoint exists anywhere) and the "future considerations" it recorded (pagination default inconsistency between `CheckInController`/`OperationalStatusController` and every other resource — a consistency nit, not a security issue, and out of this audit's scope to fix).

---

## 3. Audit Methodology

This audit combined:

1. **Documentation-first scoping** — read `CLAUDE.md`, `docs/CURRENT_STATE.md`, `docs/ROADMAP.md`, `docs/05_SECURITY_MODEL.md` (in full — it is the single most detailed document in the repository, itemizing eleven authorization "shapes" and every phase's own security decisions), `docs/04_API_CONVENTIONS.md`, `docs/00_PROJECT_CHARTER.md`, relevant `docs/DECISIONS.md` entries (DEC-009, DEC-022 through DEC-044), and `docs/handoffs/V1_PHASE_21_HANDOFF.md` in full.
2. **Direct route-map review** — the entire `apps/api/routes/api/v1.php` (615 lines) and `routes/web.php` were read line-by-line by the lead session (not delegated), to build an independent map of every endpoint's middleware and to identify every route using `withoutScopedBindings()` (a known IDOR risk pattern when two Eloquent route parameters are chained) for targeted follow-up.
3. **Parallel, evidence-based code audits** — five independent research passes were run concurrently, each instructed to read actual source (not trust documentation), cite file:line evidence, classify severity, and explicitly report "verified working controls" alongside findings:
   - Authentication, session/token security, rate limiting, CORS, password handling.
   - Authorization, IDOR, mass assignment, row-level visibility trait correctness.
   - Input validation, injection (SQL/command/SSRF), file upload security.
   - Secrets/configuration, logging/error handling, CI/CD, Docker.
   - Dependency vulnerability scanning (PHP + Dart) and Flutter mobile-client security.
4. **Independent spot-verification by the lead session** — several claims from the parallel audits and from the documentation itself were re-checked directly (not merely trusted), including: every `auth:sanctum` route group's `account.active` pairing (confirmed complete except the two deliberately-exempt logout routes), the `EnsureAccountIsActive` middleware's full logic, the `User`/`Staff` models' `#[Fillable]` attributes, the attachment storage service's filename-generation logic, and `config/attachments.php`.
5. **Non-destructive verification only** — no exploitation was attempted against a running instance (none was running in this session); findings were verified by reading the actual enforcement code path (controller → trait → query), not by pattern-matching alone. Several suspected issues were explicitly ruled out after reading the full logic (e.g., `whereRaw('1 = 0')` occurrences are all hardcoded deny-all literals, not injectable).
6. **Existing automated test suite reviewed for coverage**, not re-run in this session (no code changed, so no regression risk existed to test against) — see §10 for what already exists.

---

## 4. Existing Security Controls (confirmed, not merely documented)

- **Authentication:** Sanctum bearer tokens (mobile) / session cookies (Admin), `Hash::check()` (never `==`), `password` hidden from serialization at both the model (`#[Hidden(['password','remember_token'])]`) and resource (`UserResource`) layers, login rate-limited 5/min by `email|ip` on both surfaces and genuinely wired (not just documented) to the routes/Livewire component.
- **Account-state enforcement:** `EnsureAccountIsActive` is applied to every `auth:sanctum` route group except the two logout routes (a deliberate, documented, correct exception) — re-checked on every request, not cached, and revokes the current Sanctum token / invalidates the session on the spot when an account is suspended/inactive mid-session.
- **Authorization:** a single `Gate::before` (Administrator bypass, default-deny otherwise) plus eleven independently-evolved row-level visibility patterns, each read in full for this audit and confirmed to be actually invoked from every relevant controller action, not merely present in a trait that's sometimes skipped. Messaging/Notifications correctly never invoke `$user->can()`/`Gate::`, so Administrator has no implicit bypass there, exactly as documented.
- **IDOR defenses:** every self-service (`/me/...`) show/update/delete action explicitly compares the resolved model's ownership field to the authenticated identity (not just an index-query filter); every `withoutScopedBindings()` route (7 confirmed instances: Project Membership, Project Milestones, both attachment-download/delete pairs, Conversation Members) re-verifies the parent-child relationship in the controller before acting.
- **Mass assignment:** every model uses Laravel 13's `#[Fillable([...])]` attribute (an explicit allowlist); no model uses `$guarded = []`; no controller passes `$request->all()` into `create()`/`update()`.
- **Injection:** zero exploitable raw-SQL constructs (every `whereRaw`/`DB::raw` use is a hardcoded literal); no `exec`/`eval`/`unserialize`/dynamic-include/SSRF-prone code anywhere in `app/`.
- **File uploads:** content-sniffed MIME allowlist (`mimes:` rule, not extension/Content-Type trust) + server-enforced size cap, server-generated ULID filenames (original filename never used as a path component), storage outside the webroot (`storage_path('app/private')`), authenticated/authorized streaming downloads only, and explicit cross-report attachment-ownership checks closing the "attachment A via report B's URL" IDOR pattern.
- **Secrets:** no committed `.env` anywhere in the working tree or git history; both `.env.example` files contain only placeholders/self-documented dev-only values; all `config/*.php` credential fields are `env()`-sourced.
- **Dependencies:** `composer audit --locked` (run against `composer.lock` via the Packagist advisory API) returned **no security vulnerability advisories** for any of the 114 locked PHP packages; Flutter's four direct dependencies are all current-generation majors.
- **Mobile client:** Sanctum token persisted exclusively via `flutter_secure_storage` (Keychain/EncryptedSharedPreferences), never logged/printed, cleared on logout alongside a server-side revoke call; no hardcoded secrets; no TLS-bypass code; no cleartext-traffic manifest overrides.
- **CI/CD:** safe `pull_request` (not `pull_request_target`) trigger with standard `actions/checkout`, no secrets referenced in either workflow, `composer.json`'s `minimum-stability: stable`/`prefer-stable: true`.
- **Audit logging (Phase 21):** independently spot-verified at three real call sites (auth, Staff, Client) that the documented redaction discipline is actually followed in code, not just described.

---

## 5. Findings by Severity

No Critical or High findings were identified.

### MEDIUM

**F-01 — Login response distinguishes "wrong credentials" from "account suspended/inactive," contradicting the documented no-enumeration guarantee**
- **Component:** Authentication (API + Admin)
- **Files:** `apps/api/app/Http/Controllers/Api/V1/Auth/AuthController.php:52-64`; `apps/api/app/Livewire/Auth/LoginForm.php:69-77`
- **Evidence:** On a correct-password-but-suspended/inactive account, both surfaces return a distinct message ("This account is not currently active. Contact an administrator.") instead of the generic invalid-credentials message used for a wrong password or unknown email.
- **Trust boundary:** the public, unauthenticated login endpoint (`POST /api/v1/auth/login`) and the Admin login form — both reachable by anyone who can send an HTTP request, rate-limited but not otherwise restricted.
- **What an attacker needs:** the correct password for a specific account (e.g., a reused/breached password, or an offboarded employee re-trying their own former credentials) — they do not need to already know the account is suspended.
- **Impact:** confirms account existence and current employment/access status to anyone holding a correct password for it — a real, if narrow, information disclosure. It also directly contradicts `docs/05_SECURITY_MODEL.md:83`'s explicit claim ("returns a generic failure message that never confirms or denies whether a given email is registered"), which CLAUDE.md §2's Honesty Clause requires be corrected either in code or in documentation.
- **Existing mitigation:** none in the HTTP response; the distinction is otherwise harmless in isolation (rate-limited, no account enumeration via a *wrong* password), but the specific "this account still exists and is now deactivated" signal is not gated by anything.
- **Exploitability:** real but narrow — requires already having valid credentials for the specific targeted account, which is a significant precondition. Rated Medium rather than Low because it is a genuine, unconditional confirmation of a sensitive fact (account status) to anyone who clears that bar, and because it is a documented contract violation, not merely a hardening gap.
- **Recommended remediation:** unify both failure branches into the identical generic message in the HTTP response on both surfaces; preserve the specific reason (bad credentials vs. inactive) only in the `AuditLogger` entry, which is already Administrator-only and exactly what audit logging exists for.
- **Phase 22 scope:** **Yes — required.**

### LOW

**F-02 — Sanctum mobile tokens never expire and carry no ability scoping**
- **Files:** `apps/api/config/sanctum.php:53` (`'expiration' => null`); `AuthController.php:66` (`createToken('mobile')` with no ability list).
- **Attack/precondition:** a lost or stolen device whose token is never revoked server-side (no admin UI/endpoint exists to revoke another user's token; only self-service logout revokes the current token).
- **Impact:** a standing, full-access credential with no automatic expiry — the practical blast radius of device loss/theft is larger than necessary.
- **Existing mitigation:** `EnsureAccountIsActive` means an Administrator can neutralize a compromised account's *all* tokens at once by suspending the account (the very next request re-checks `isActive()` and revokes on the spot) — this is a real, working compensating control, not merely theoretical.
- **Already documented as a deliberate, open deferral** (`docs/05_SECURITY_MODEL.md:82`: "no token abilities are defined yet... revisit if a second API-consuming client type is added"), so this is confirmation of a known gap, not a new discovery.
- **Phase 22 scope:** Hardening (see §8) — a bounded token lifetime is cheap and closes real exposure; ability scoping remains correctly deferred (only one client type exists).

**F-03 — No in-controller defense-in-depth check on `WorkLogController::update()`/`destroy()`**
- **File:** `apps/api/app/Http/Controllers/Api/V1/WorkLogs/WorkLogController.php:113-125`
- **Detail:** relies solely on the `can:work-logs.manage` route middleware (Administrator-only); every sibling module (Tasks, Projects, Service/Incident Reports) additionally re-verifies authority inside the controller. Not currently exploitable — the middleware is correctly scoped and there is no known route-configuration path that bypasses it — but it is the one write path in the entire audited authorization surface with a single line of defense instead of two.
- **Phase 22 scope:** Optional/hardening, low priority — see §8. Not required, since no actual gap exists today and CLAUDE.md §3/§10 caution against speculative code for a scenario that can't currently happen.

**F-04 — CI workflows carry no explicit `permissions:` block**
- **Files:** `.github/workflows/backend-ci.yml`, `.github/workflows/mobile-ci.yml` (both, full files)
- **Detail:** `GITHUB_TOKEN` scope falls back to the repository/organization default rather than being explicitly minimized to `contents: read`. No secrets are referenced by either workflow and the trigger is the safe `pull_request` (not `pull_request_target`), so blast radius is already small — this is standard hardening, not a response to an active hole.
- **Phase 22 scope:** Hardening (see §8) — a one-line addition per workflow.

**F-05 — `.gitignore` enumerates specific `.env.*` filenames rather than a wildcard pattern**
- **File:** `apps/api/.gitignore:1-19` — ignores `.env`, `.env.backup`, `.env.production` by name, but not a general `.env.*` (with negation for the two tracked example files).
- **Impact:** a future `.env.local`/`.env.staging` file would not be auto-ignored and could be accidentally committed.
- **Phase 22 scope:** Hardening (see §8) — trivial, no functional risk today (confirmed no such file exists or was ever committed).

### INFORMATIONAL / HARDENING

**F-06 — No `config/cors.php` published (framework default in effect)**
- No cross-origin browser client currently exists (Flutter is a native HTTP client, unaffected by CORS; the Admin Backoffice is same-origin server-rendered Blade/Livewire) — not an active exposure. Worth a documented note so a future browser-based client doesn't inherit a stale/absent config without a deliberate decision. **Deferred**, not Hardening — see §9.

**F-07 — `local` filesystem disk's Laravel-13-default `'serve' => true` registers a dormant signed-URL route for attachments**
- **File:** `apps/api/config/filesystems.php` (disk config for `local`)
- No code path currently calls `Storage::disk('local')->url()`/`temporaryUrl()` for attachments (confirmed via repo-wide grep) — the route exists but is unused. Explicitly setting `'serve' => false` for this disk removes the dormant surface entirely at zero cost.
- **Phase 22 scope:** Hardening (see §8).

**F-08 — `APP_DEBUG=true` in both `.env.example` files (dev convenience); code default is the safe `false`**
- `config/app.php:42` — `'debug' => (bool) env('APP_DEBUG', false)` is correctly fail-safe. The example files are never used as-is in production (`.env` is git-ignored; CI copies `.env.example` only for its own ephemeral test run). Worth a one-line deployment-documentation reminder, nothing more.
- **Phase 22 scope:** Documentation note only (see §8).

**F-09 — Docker Compose MySQL credentials are a hardcoded, low-entropy dev value (`secret`)**
- Already self-documented in `.env.docker.example`/`docker-compose.yml` as local-development-only; the MySQL host port is bound to `127.0.0.1` only (not network-exposed). No action needed beyond what's already documented.

**F-10 — `composer audit` did not run in this checkout during the secrets/CI-CD pass** (no `vendor/` present) — however, a separate pass in this same audit successfully ran `composer audit --locked` directly against `composer.lock` via the Packagist advisory API and found **no vulnerabilities**. Recommend adding `composer audit` as a standing CI step so this check runs automatically on every PR rather than depending on a manually-installed `vendor/`.
- **Phase 22 scope:** Hardening (see §8).

**F-11 — No `CODEOWNERS`, `dependabot.yml`, or `.github/SECURITY.md`**
- Repository-governance hygiene, not application security. `dependabot.yml` is cheap and gives continuous dependency monitoring (useful given F-10); `CODEOWNERS`/`SECURITY.md` are organizational choices for the product owner.
- **Phase 22 scope:** `dependabot.yml` — Hardening; the other two — Deferred (product-owner/organizational decision, not a security defect).

**F-12 — Privilege-relevant fields (`role_id`, account `status`) are not settable through any API endpoint at all (today, safely) — but no Admin Backoffice user-management surface exists either**
- Confirmed via full-codebase search: no controller/FormRequest references `role_id` or `AccountStatus` as writable. Safe today, but this is the same gap DEC-044 already recorded as a Known Limitation (no `user.suspended`/`.reactivated`/`.role_changed` mutation surface exists to audit, because no mutation surface exists at all). Building this is new product behavior (a User-management feature), not a Phase 22 security fix.
- **Phase 22 scope:** Deferred — see §9.

**F-13 — ULID `public_id`s are confirmed non-enumerable** (verified, not a finding requiring action) — Symfony Uid's ~80 bits of cryptographically random suffix per ULID makes every "404 instead of 403" ownership check in the codebase an effective mitigation, not security theater. Recorded here for completeness since it underpins the confidence behind several "verified working control" claims above.

---

## 6. Verified Non-Issues / Controls Confirmed Working

The following were specifically investigated and found to be correctly implemented — recorded here so this audit does not read as purely a list of gaps:

- Every `auth:sanctum` route group in `routes/api/v1.php` correctly pairs `account.active`, with only the two logout routes deliberately exempt (by design, documented, and independently re-verified by the lead session).
- All eight row-level authorization traits (Tasks, Projects, Scheduling, Service Reports, Incident Reports, Work Logs, Leave Requests, Messaging) are invoked from every relevant controller action, not skipped anywhere.
- All four self-service "me" controllers (Work Logs, Leave Requests, Announcements, Notifications) explicitly check ownership on show/update/delete, not just on index.
- All seven `withoutScopedBindings()` nested routes re-verify the parent-child relationship server-side.
- Both attachment controllers (Service Reports, Incident Reports) check `assertBelongsToReport()` before any download/delete — closing the classic cross-parent attachment IDOR.
- No SQL injection: every raw-SQL construct found is a hardcoded literal (deny-all `whereRaw('1 = 0')` fallbacks, one hardcoded `DB::raw('count(*) as aggregate')`).
- No command/code execution surface anywhere in `app/`.
- No client-controlled sorting parameter reaches `orderBy()` (matches documentation — sorting isn't implemented anywhere yet).
- Status-transition endpoints use dedicated action routes + backed enums end-to-end, never a raw client-supplied status string.
- File-upload pipeline: content-sniffed MIME allowlist, server-enforced size cap, ULID-randomized storage filenames, storage outside webroot, framework-safe `Content-Disposition` encoding (no header-injection surface), authenticated/authorized downloads only.
- No committed secrets anywhere in the working tree or full git history; all config credential fields are `env()`-sourced with safe/no defaults.
- Zero `Log::`/`logger()` calls exist anywhere in `app/` — nothing to leak via logs; `AuditLogger`'s redaction discipline independently confirmed correct at three real call sites (auth, Staff, Client).
- CI workflows use the safe `pull_request` trigger (not `pull_request_target`), reference no secrets, and don't echo anything sensitive.
- `docker/nginx/default.conf`'s document root is `public/` only with an explicit dotfile-deny rule — `.env`/`.git`/`storage`/PHP source are structurally unreachable.
- `composer audit --locked` — zero known vulnerabilities across all 114 locked PHP packages; Flutter's four dependencies are current-generation.
- Flutter mobile: token persisted via `flutter_secure_storage` only, never logged; logout clears local storage **and** calls the backend to revoke server-side; no hardcoded secrets, no TLS-bypass code, no cleartext-traffic manifest overrides.
- Mass assignment is allowlisted (`#[Fillable]`) on every model; no `$request->all()` reaches `create()`/`update()` anywhere.

---

## 7. Phase 22 Required Implementation Scope (Section A)

Only one item meets the bar of "should be resolved before V1 progresses":

1. **F-01 fix:** unify the login failure response on both the mobile API (`AuthController::login`) and the Admin Backoffice (`LoginForm::login`) so a correct-password-but-inactive account returns the identical generic message used for a wrong password/unknown email. The specific reason (bad credentials vs. inactive account) continues to be captured in the `AuditLogger` entry exactly as today — only the HTTP-visible message changes. Update `docs/05_SECURITY_MODEL.md` if any wording needs to move from "intended" to "confirmed," per the Honesty Clause, once implemented.

This is deliberately the *only* required item. Everything else identified is Low/Informational and does not block V1 progression on its own merits.

---

## 8. Hardening Items (Section B — low-risk, inexpensive, appropriate now)

1. **F-02:** set a bounded Sanctum token expiration (`config/sanctum.php`'s `expiration`, e.g. a value on the order of 30–90 days) instead of `null`. **Needs a product-owner decision on the exact duration** (UX trade-off: how often should a legitimate device need to re-authenticate) — implement once that's confirmed; this is not a decision this audit should make unilaterally.
2. **F-04:** add an explicit `permissions: contents: read` block to both `.github/workflows/backend-ci.yml` and `.github/workflows/mobile-ci.yml`.
3. **F-05:** tighten `apps/api/.gitignore` to a wildcard `.env.*` pattern with explicit `!.env.example`/`!.env.docker.example` negations.
4. **F-07:** set `'serve' => false` on the `local` disk in `config/filesystems.php` to remove the dormant signed-URL route entirely (confirmed unused).
5. **F-08:** add a one-line deployment-documentation reminder (README or a future Staging Deployment phase document) that a real production `.env` must explicitly set `APP_DEBUG=false` — the code default is already safe; this is a process safeguard against a copy-paste mistake.
6. **F-10:** add `composer audit --locked` (or plain `composer audit` once `vendor/` is always installed by that point in the workflow) as an additional step in `backend-ci.yml`, after the existing `composer validate --strict` step.
7. **F-11 (partial):** add a `.github/dependabot.yml` for both the `composer` and (if applicable) a Flutter/Dart ecosystem, given `composer audit` currently has no continuous/scheduled counterpart in this repository.
8. **F-03 (optional, lowest priority):** consider adding a defense-in-depth ownership/authority check inside `WorkLogController::update()`/`destroy()` mirroring its sibling modules — genuinely optional since no exploitable gap exists today; do only if it doesn't risk regressing the module's tested behavior, and only with the accompanying regression test in §10.

None of the above requires a schema change, a new dependency, or new infrastructure — consistent with CLAUDE.md §3's scope discipline and the "no enterprise infrastructure for ~100 employees" instruction governing this audit.

---

## 9. Deferred Items (Section C)

1. **User account-management (suspend/reactivate/role-change) mutation surface** (F-12). This is new product behavior — no prior phase authorized building it, and DEC-044 already recorded it as a known, deliberate gap with reserved (unused) audit action names ready for whenever it is built. Building it is a future, separately-scoped phase, not a Phase 22 security fix.
2. **`config/cors.php`** (F-06). No browser-based client exists today to make CORS policy meaningful; publishing a restrictive config now would be speculative infrastructure for a scenario that doesn't yet exist (CLAUDE.md §3). Revisit if/when a browser-based SPA client is ever added.
3. **Sanctum token ability/scoping** beyond the bounded-expiration hardening in §8 — already correctly deferred per `docs/05_SECURITY_MODEL.md` ("revisit if a second API-consuming client type is added"); only one client type exists today.
4. **Antivirus/malware scanning for attachments** — already an explicit, documented V1 non-commitment since Phase 18 (DEC-041); no new evidence from this audit changes that calculus at this scale.
5. **Retention/purge policies** (check-ins, audit logs) — already deferred as a future operational-policy consideration by Phases 9 and 21 respectively; nothing in this audit demonstrates an urgent need to reverse that.
6. **Messaging administrative/investigation/moderation capability** — already an explicit, deliberate future decision per `05_SECURITY_MODEL.md`'s Messaging Privacy section; out of scope here.
7. **`CODEOWNERS` / `.github/SECURITY.md`** — organizational/process decisions for the product owner, not application-security defects; no urgency identified.
8. **General API rate limiting beyond login** — `docs/04_API_CONVENTIONS.md` and `05_SECURITY_MODEL.md` both already explicitly note this as "to be tuned per endpoint when a real need is demonstrated." Nothing in this audit surfaced an abuse pattern or write-heavy endpoint at this ~100-employee scale that demonstrates that need yet; revisit only if real usage shows otherwise.

Avoiding scope creep: no microservices, Kubernetes, external SIEM, Redis/queue infrastructure, or similar enterprise-scale system is proposed anywhere above, consistent with this audit's own governing instructions.

---

## 10. Security Regression Testing Plan

**For the required fix (F-01):**
- New/updated test in `tests/Feature/Auth/` (API) and `tests/Feature/Auth/AdminLoginTest.php` (Admin): assert that a login attempt against a `suspended`/`inactive` account with the *correct* password returns byte-identical response content/shape to a login attempt with a *wrong* password against an *active* account (same message string, same HTTP status). Assert separately (via the existing `AuditLog` model/factory) that the audit trail still distinguishes the two cases internally (`auth.login_failed` with the specific reason preserved in a non-response-visible field or via the existing curated snapshot) — i.e., prove the fix narrows what the *response* reveals without narrowing what the *audit log* records.

**For hardening items, if implemented:**
- **F-02 (token expiration):** a test asserting a newly issued token's `expires_at` is set and in the future; a test using `Carbon::setTestNow()` to fast-forward past expiration and asserting a subsequent authenticated request returns `401`. Confirm existing `tests/Feature/Auth/*` and every module's authorization tests (which authenticate via a helper) still pass unmodified (they should, since none of them time-travel).
- **F-07 (`serve => false`):** a test (or a documented manual check) asserting Laravel's local-disk signed-URL route (`/storage/{path}` or the disk's registered `temporaryUrl` route, if Laravel registers one automatically) is not reachable/returns 404, confirming the dormant surface is actually closed, not just reconfigured.
- **F-04/F-05/F-08/F-10/F-11:** these are YAML/config/documentation changes with no PHPUnit-testable behavior; verify manually that `backend-ci.yml`/`mobile-ci.yml` still pass end-to-end after adding the `permissions:` block and `composer audit` step (a CI run itself is the "test").
- **F-03 (optional WorkLogController hardening, if pursued):** extend `tests/Feature/Api/V1/WorkLogs/*` with a case proving a non-Administrator, non-owning caller still receives `403`/`404` even if the route middleware were hypothetically misconfigured — i.e., test the controller-level check in isolation, not just the route's current correct configuration.

**Existing tests that already provide security coverage** (reviewed, not re-run, since no code changed this session):
- `tests/Feature/Authorization/*` (14 files) — dedicated cross-module authorization tests for Organization, Staff, Clients, Projects, Tasks, Work Logs, Leave, Announcements, Messaging, Notifications, Admin Backoffice access, plus `PermissionMechanismTest`/`RolePermissionSeederTest`/`AdminUserSeederTest`.
- Each business module's own `tests/Feature/Api/V1/<Module>/*` suite includes authorization-adjacent cases (e.g., `IncidentReportLifecycleTest`, `ServiceReportAttachmentTest` — the latter almost certainly already covers the attachment cross-report IDOR this audit independently verified in code).
- `tests/Feature/Api/V1/Audit/*` (4 files, 68 tests) — including `AuditLogTransactionTest`'s forced-failure fail-closed proofs and `AuditLogRedactionTest`.
- `tests/Feature/Auth/AdminLoginTest.php` — existing Admin login coverage, to be extended per F-01 above rather than replaced.
- The full suite was reported passing at 1,068 tests / 2,889 assertions as of the Phase 21 handoff; this audit changed no code, so that baseline is presumed still valid (not re-run in this session, since CLAUDE.md's validation-command discipline applies to implementation phases, and none occurred here).

---

## 11. Proposed Implementation Sequence (if Phase 22 is authorized)

1. Write `docs/phases/V1_PHASE_22_DEFINITION.md` from this document's §7/§8 scope, for product-owner review and explicit authorization (per CLAUDE.md §1/§8) — including a decision request for the Sanctum token expiration duration (F-02).
2. Implement F-01 (required fix) first, with its regression test, since it is the only finding with real user-facing security impact.
3. Implement the Hardening items (§8) in any order — they are independent of each other and of F-01. Suggested grouping: config/CI changes (F-04, F-05, F-07, F-10, F-11) as one batch (no application-logic risk), F-02 (token expiration) separately once its duration is confirmed by the product owner, F-08 as a documentation-only change, F-03 last (optional, lowest priority, only if it doesn't destabilize `WorkLogController`'s existing tested behavior).
4. Run the full CLAUDE.md §5 validation suite (`composer validate --strict`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test`) after every batch.
5. Update `docs/05_SECURITY_MODEL.md` to reflect the F-01 fix (the Honesty Clause requires this), `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, and `docs/DECISIONS.md` (a new DEC- entry if the Sanctum expiration duration or any other choice here rises to the level of an architectural decision worth recording).
6. Write the standard `docs/handoffs/V1_PHASE_22_HANDOFF.md` per CLAUDE.md §6/§8, then **stop** — do not begin Phase 23 without separate authorization.

---

## 12. Risks / Assumptions

- This audit was performed via static code review only; no running instance existed in this session to perform live penetration testing (e.g., actual HTTP requests against a booted server, live rate-limit exhaustion, or a real Docker Compose stack). All findings were verified by reading the actual enforcement code path, which is a strong but not absolute substitute for dynamic testing.
- `composer audit --locked` depends on live connectivity to Packagist's advisory API at the time it was run in this session; it should be re-run (ideally in CI, per F-10) rather than treated as a one-time, permanently-valid result.
- This audit assumes the production deployment (not yet built — Phase 24 is still pending) will follow the same `.env`-based configuration model reviewed here. Production-specific settings (real `APP_DEBUG=false`, `SESSION_SECURE_COOKIE`, a real `SANCTUM_STATEFUL_DOMAINS` if ever needed) could not be verified since no production `.env` exists yet; this audit flags the *code defaults* as safe, which is what's currently verifiable.
- The Flutter mobile app currently has no business-module UI (only auth + a home shell) — this audit's mobile-security scope is correspondingly narrow; a much larger mobile-client review will be warranted once Phase 23 (Mobile UI/UX Audit) or a future phase builds out real screens that render sensitive data client-side.
- No dynamic dependency-confusion, supply-chain-typosquatting, or SBOM-level analysis was performed beyond `composer audit`'s advisory-database check — this is consistent with "no enterprise-scale infrastructure for ~100 employees" but is a real methodological boundary worth naming.

---

## 13. Definition of Done (for this audit/planning session)

- [x] Repository state established (branch, clean working tree, Phase 21 merge confirmed, recent history inspected).
- [x] Phase 22's intended scope recovered from repository documentation (roadmap, security model, decisions, Phase 21 handoff).
- [x] Repository-wide security audit performed across all areas named in the governing instructions (authN, authZ/IDOR, data isolation, input validation/mass assignment, injection, file uploads, API security, secrets/config, logging/error handling, mobile, dependencies, CI/CD).
- [x] Every substantive finding verified against actual code (not documentation alone), with trust boundary, attacker precondition, and impact stated.
- [x] Findings classified by severity (Critical/High/Medium/Low/Informational) — no Critical/High found; one Medium; the rest Low/Informational.
- [x] Verified non-issues / working controls documented alongside findings.
- [x] Phase 22 implementation boundary proposed: 1 required fix, 8 hardening items, 8 deferred items with rationale — no scope creep, no enterprise infrastructure proposed.
- [x] Security regression testing plan specified per proposed fix, plus existing test coverage inventoried.
- [x] This document committed under `docs/phases/` for product-owner review.
- [ ] **Not done, and explicitly out of this session's scope:** implementing any fix, creating `docs/phases/V1_PHASE_22_DEFINITION.md`, opening an implementation branch/PR, or updating `docs/CURRENT_STATE.md`/`docs/CHANGELOG.md`/`docs/ROADMAP.md`/`docs/05_SECURITY_MODEL.md` (those updates belong to the implementation phase itself, once authorized, per CLAUDE.md §6).

---

## 14. Post-Implementation Remediation Status (added after implementation)

The product owner reviewed this audit and explicitly authorized a **narrow** remediation scope — not the full set of hardening items §8 enumerated. See `docs/DECISIONS.md` DEC-045 for the authorization record and `docs/handoffs/V1_PHASE_22_HANDOFF.md` for the complete implementation account (files changed, tests added, quality-gate results). Status per finding:

| ID | Finding | Disposition | Notes |
|---|---|---|---|
| F-01 | Login discloses account existence/status for suspended/inactive accounts | **Fixed** | `AuthController::login`/`LoginForm::login` now return an identical generic message for invalid credentials and for a correct-password-but-inactive account, on both surfaces. Enforcement unchanged; regression tests added. |
| F-02 | Sanctum tokens never expire, no ability scoping | **Implemented (expiration only)** | `config('sanctum.expiration')` set to `env('SANCTUM_EXPIRATION', 43200)` (30 days), using Sanctum's built-in mechanism — no refresh-token architecture. Ability scoping remains correctly deferred (still only one client type). |
| F-03 | No in-controller defense-in-depth check on `WorkLogController` | **Investigated and dropped — confirmed not a gap** | `RolePermissionSeeder` never attaches `work-logs.manage` to Manager/Staff; the existing `can:work-logs.manage` route middleware (Administrator-only) already fully protects `store()`/`update()`/`destroy()`. No code change made, per explicit authorization against duplicating authorization for cosmetic redundancy. |
| F-04 | CI workflows carry no explicit `permissions:` block | **Implemented** | `permissions: contents: read` added to both `.github/workflows/*.yml`. |
| F-05 | `.gitignore` enumerates specific `.env.*` filenames | **Implemented** | `apps/api/.gitignore` now uses a `.env.*` wildcard with explicit negation for the two tracked example files. |
| F-06 | No `config/cors.php` published | **Remains deferred** | No browser-based client exists yet to make CORS policy meaningful — unchanged from the original audit's own recommendation. |
| F-07 | `local` disk's default `'serve' => true` registers a dormant route | **Implemented** | `config/filesystems.php`'s `local` disk now sets `'serve' => false`. |
| F-08 | `APP_DEBUG=true` in example env files (code default is safe) | **Implemented (documentation)** | One-line reminder added to `README.md`; code default (`false`) was already correct and unchanged. |
| F-09 | Docker Compose MySQL dev credentials | **No action needed** | Already self-documented as local-dev-only in the original audit; no change proposed or made. |
| F-10 | `composer audit` did not run in one investigative pass (no `vendor/`) | **Implemented** | `composer audit --locked` added as a standing step in `backend-ci.yml` and as a documented command in `CLAUDE.md` §5. Run twice during remediation (audit pass and implementation pass) — clean both times. |
| F-11 | No `CODEOWNERS`/`dependabot.yml`/`SECURITY.md` | **Implemented (dependabot.yml only)** | `.github/dependabot.yml` added (composer/pub/github-actions, weekly, no auto-merge). `CODEOWNERS`/`SECURITY.md` remain deferred as organizational/product-owner decisions, not application-security defects. |
| F-12 | No User account-management (suspend/reactivate/role-change) mutation surface | **Remains deferred** | New product behavior, not a security fix — explicitly excluded from Phase 22 per the product owner's authorization and DEC-044's original Known Limitation. |
| F-13 | ULID `public_id`s confirmed non-enumerable | **No action needed** | Verified-working-control finding, not a defect. |

**Everything else in §9 (Deferred Items) remains deferred exactly as originally recommended** — no refresh-token architecture, no new infrastructure, no SIEM/monitoring platform, no unrelated dependency upgrades, no speculative enterprise hardening.

**Quality gates (implementation session):** `composer validate --strict`, `composer audit --locked` (no vulnerabilities), `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` (0 errors), and the full `php artisan test` suite (1,080/1,080 passing — 1,068 Phase 1–21 baseline + 12 new regression tests) all passed. See `docs/handoffs/V1_PHASE_22_HANDOFF.md` for full command output.
