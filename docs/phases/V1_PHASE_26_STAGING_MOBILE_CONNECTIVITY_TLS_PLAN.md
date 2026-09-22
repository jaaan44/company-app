# Phase 26 — Staging Mobile Connectivity & TLS — Discovery & Planning Report

**Status:** GATE 1 AUTHORIZED AND IMPLEMENTED (repository-side only) — see the Gate 1 Addendum at the end of this document. Gate 2 (VPS/DNS/TLS/real-device deployment) remains NOT AUTHORIZED. This document is the second of two discovery/planning deliverables for Phase 26 (mirroring the `V1_PHASE_24_STAGING_DEPLOYMENT_PLAN.md`/`V1_PHASE_22_SECURITY_AUDIT.md` precedent: a planning document precedes a scoped implementation authorization, it is not itself the authorization). Sections 1–18 below are preserved exactly as originally written (per `CLAUDE.md`'s "do not rewrite historical decisions unnecessarily") — they reflect this document's own planning and open questions at the time it was authored; the Gate 1 Addendum records what was actually authorized and implemented afterward.

**Date:** 2026-09-22. **Session type:** repository inspection (git range analysis, Compose/runbook mechanism analysis, Laravel proxy-trust analysis) plus incorporation of the product owner's own live VPS inspection findings (reported to this session as text, not independently re-verified by this session — no VPS access exists here). **Depends on:** the prior Phase 26 discovery session's report (chat-only, not committed) and everything Phase 24/25 established.

**Repository state:** `main` at `4ce9f6ea9d9ac86ef57da5e6b067cde37ca09df9`, working tree clean, no open PRs (re-confirmed this session).

---

## 1. Executive Summary

The product owner ran the live VPS inspection this session's prior discovery report requested. The findings resolve nearly every open question from that report:

- A host-level Nginx (1.24.0) already owns 80/443 and already reverse-proxies one other project (`bikeworkshop.storm-ark.com`) to a loopback container port, with Certbot (2.9.0, `certbot.timer` enabled/active) already managing that project's certificate. **This is exactly the architecture this document's predecessor recommended** — Phase 26 extends an already-proven pattern rather than inventing one.
- UFW is active, default-deny, and currently allows only 22/80/443 (+ IPv6 equivalents) — Company App's port 8012 has no allow rule and is not publicly reachable, exactly as Phase 24 left it.
- The Company App staging stack (`company-app-api`/`-nginx`/`-mysql`) is running and healthy, serving `GET /api/v1/health` → `200` from the VPS itself.
- Two new findings change this plan's shape versus the prior report's assumptions:
  1. **The deployed staging checkout (`/home/deploy/company-app/company-app-build/company-app`, HEAD `eb22782`) is one merge behind current `main` (`4ce9f6e`).** §2 below inspects the exact range and finds it safe, but not required, to update as part of Phase 26 — see the recommendation there.
  2. **Compose environment variables are supplied via an explicit `--env-file .env.staging` flag on every operational command, not Compose's automatic `.env` auto-load convention.** The "variable not set" warnings the operator saw were caused by running `docker compose ... ps` without that flag — not a real deployment problem. §3 below has the exact evidence and the corrected command form.

This document resolves all thirteen planning questions the product owner posed, designs a strict gated implementation sequence, and defines rollback for every step. **It authorizes nothing.**

---

## 2. Deployed Code Baseline (Planning Question 1)

`git log --oneline eb227826aea776a303e05126743fa9c056ea2852..4ce9f6ea9d9ac86ef57da5e6b067cde37ca09df9` and `git diff --stat` over the same range were run against this session's own repository checkout (not the VPS) to establish exactly what the deployed staging checkout is missing:

| Commit | What it is |
|---|---|
| `230cf9a` | Dependabot: `laravel/framework` 13.31.0 → 13.32.0 (`apps/api/composer.lock` only) |
| `2270126` | Dependabot: `flutter_secure_storage` 11.1.0 → 11.2.0 (`apps/mobile` only — irrelevant to the server) |
| `d9a0935` | Phase 24 documentation-only closure (Addendum 3, docs only) |
| `d211a44` | Roadmap re-baseline + Phase 25 definition (docs only) |
| `d531047` | Dependabot: `larastan/larastan`/`phpstan/phpstan` (dev-only, `apps/api/composer.lock` only) |
| `f5d096e` | Dependabot: `livewire/livewire` 4.4.6 (`apps/api/composer.lock` only) |
| `ae82094`/`fe84737` | Phase 25: Mobile Application Foundation & Navigation Shell — **`apps/mobile/**` only**, plus docs |

Full `--stat` confirms: **every non-doc, non-mobile change in this range touches `apps/api/composer.lock` only** — no `apps/api/app|config|routes|database/migrations` file changed at all. There are no new migrations, no route changes, no controller/model changes, no config changes. Phase 25 (the largest single change) is 100% Flutter client code — it has no server-side footprint whatsoever.

**Conclusion, evidence-based:** the currently deployed API (`eb22782`) already serves every endpoint the Flutter mobile app calls, identically to current `main`. **Updating the staging checkout is not required for Phase 26's core objective** (TLS + real-device mobile connectivity) — the mobile app's Login flow will behave identically against either commit.

**Recommendation:** update anyway, but as an explicit, separately-gated, low-risk first step — not because Phase 26 needs it, but because (a) it is fully mechanical (`git pull` + one `docker compose ... build app` to rebuild the image against the new `composer.lock` — no migration, no seeder, no config change), (b) it picks up a Laravel patch release, and (c) leaving staging permanently one release behind `main` is exactly the kind of silent drift that makes a future phase's staging verification misleading. This is **optional** in the sense that Phase 26 does not depend on it — the product owner may decline it and every other step in §8 still proceeds unchanged. If accepted, it is Step 1 of §8's sequence, performed and fully verified (health check, Administrator login) while the surface is still internal-only (before any DNS/TLS work), so a problem here is caught with the smallest possible blast radius and is trivially distinguishable from any later TLS-related problem.

---

## 3. Compose Environment Mechanism (Planning Question 2)

`docs/DEPLOYMENT_STAGING.md` was re-inspected line-by-line for every `docker compose` invocation it documents (lines 77–356). Every operational command that needs the database credentials — `build`, `up`, `restart` — is shown with an **explicit `--env-file .env.staging` flag**:

```
docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging build
docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging up -d
docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging restart
```

Compose's *automatic* environment-file loading only applies to a file literally named `.env` in the invocation directory — never a differently-named file such as `.env.staging`. This is deliberate, not an oversight: it avoids any ambiguity with the unrelated `apps/api/.env` (Laravel's own runtime config, a bind-mounted file, not a Compose substitution source) sitting in a different directory. **The operator's `docker compose -p company-app -f docker-compose.staging.yml ps` command omitted `--env-file .env.staging`**, which is why Compose printed "variable not set" warnings while resolving the `${DB_*}` interpolations it needs to *display* — this has no effect on the already-running containers, whose environment was fixed at creation time by an earlier `up --env-file .env.staging` invocation, exactly as the operator's own observation ("containers remain healthy") confirms.

**Correct, safe command form for every future Compose operation on this stack (read-only or mutating alike):**

```sh
cd /home/deploy/company-app/company-app-build/company-app
docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging <command>
```

Never omit `--env-file .env.staging`, including for `ps`/`logs`/`config` — the warning is harmless for pure inspection, but the habit of always including the flag avoids ever running a `build`/`up`/`restart` without it by mistake, which would attempt to (re)create the `mysql` service with empty credential values. No secret value is reproduced anywhere in this document.

---

## 4. Loopback Binding (Planning Question 3)

Changing `docker-compose.staging.yml`'s `nginx` service from `"8012:80"` to `"127.0.0.1:8012:80"` is a **service-definition change**, not a runtime setting — Docker Compose detects it as part of the `nginx` service's config hash and will recreate (stop, remove, create, start) the `nginx` container on the next `up`, while leaving `app` and `mysql` untouched (their own service definitions are unaffected; `docker compose` only recreates services whose own definition changed, confirmed by Compose's own per-service diffing behavior — Phase 24's own runbook already relies on this same behavior for its "Build & redeploy" section, lines 254–255, which rebuilds `app nginx` without ever touching `mysql`).

This container is fully stateless (no named volume, no data) — recreating it is a clean stop/start with no data-loss surface at all, unlike `app` (has the `app-storage` volume, unaffected either way) or `mysql` (has `mysql-data`, never touched by this change).

**Safe command:**
```sh
docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging up -d nginx
```
Compose recreates only `nginx`. Verify immediately after: `docker compose -p company-app -f docker-compose.staging.yml ps` shows `app`/`mysql` with unchanged `CreatedAt`/uptime (proving they were untouched) and `nginx` freshly recreated; `curl http://127.0.0.1:8012/api/v1/health` from the VPS itself still returns `200` (proving the loopback bind didn't break local reachability — it only removes the `0.0.0.0` bind, which UFW was already blocking externally, so no externally-visible behavior changes at all — this step is pure defense-in-depth).

**Decision: recommended, low-risk, include in Phase 26.**

---

## 5. Host-Nginx Plan (Planning Question 4)

No new Nginx installation — extend the existing host Nginx (1.24.0) that already serves `bikeworkshop.storm-ark.com` with a second, independent `server_name` block, mirroring its proven `proxy_pass`/header-forwarding pattern exactly. A new site file, e.g. `/etc/nginx/sites-available/company-staging.storm-ark.com`, symlinked into `sites-enabled/` (matching whatever convention the Bicycle Workshop site already uses — confirm its exact file location during VPS inspection before creating the new one, so the new file lives alongside it consistently).

**Initial (pre-certificate) vhost — HTTP only, for Certbot's HTTP-01 challenge and to prove the proxy chain before TLS exists:**

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name company-staging.storm-ark.com;

    location / {
        proxy_pass http://127.0.0.1:8012;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

This intentionally reuses the exact four `proxy_set_header` lines the product owner reported the Bicycle Workshop vhost already sends — no new pattern is introduced. Certbot's `--nginx` plugin (already installed, already used for the sibling site) is then run against this file (§6) — it edits this same file in place to add the `listen 443 ssl` block and the HTTP→HTTPS redirect, which is the standard, minimal-touch Certbot workflow and exactly how the Bicycle Workshop site itself almost certainly reached its current state.

**Decision:** one new site file, zero changes to the existing Bicycle Workshop site, zero new Nginx installation.

---

## 6. DNS Plan (Planning Question 5)

**Exact record required (not created by this session):**

| Type | Name | Value |
|---|---|---|
| A | `company-staging.storm-ark.com` | the VPS's public IPv4 address |
| AAAA (only if the VPS has a public IPv6 address the operator wants to use — optional) | `company-staging.storm-ark.com` | the VPS's public IPv6 address |

Created by the product owner in whatever DNS provider hosts the `storm-ark.com` zone (this session has no DNS access and none is inferred). Verification once created (from an external network, not the VPS itself): `dig +short company-staging.storm-ark.com` resolves to the expected IP.

---

## 7. Certbot/TLS Plan (Planning Question 6)

Existing Certbot (2.9.0) and its already-enabled/active `certbot.timer` are reused as-is — no new installation, no new renewal mechanism. Once DNS (§6) resolves and the plain-HTTP vhost (§5) is live and reloaded:

```sh
sudo certbot --nginx -d company-staging.storm-ark.com
```

This is the same `--nginx` plugin invocation pattern the existing `bikeworkshop.storm-ark.com` certificate almost certainly used (a plugin already proven working on this exact host). It performs the HTTP-01 challenge against the vhost just created, obtains the certificate, and edits the site file to add the `443 ssl` server block plus (by default) an HTTP→HTTPS redirect for port 80 — Certbot's own standard, minimal-touch behavior. No manual certificate/key file handling is needed.

**Renewal:** the existing `certbot.timer` already covers every certificate Certbot manages on this host, including the new one, automatically — no per-site renewal configuration is needed. Confirm coverage with `sudo certbot certificates` (lists both `bikeworkshop.storm-ark.com` and, once issued, `company-staging.storm-ark.com`) and `sudo certbot renew --dry-run` (safe, non-mutating, exercises the renewal path for every managed certificate without actually replacing any of them).

---

## 8. Gated Implementation Sequence

Each step has a prerequisite, the action, a verification, and a rollback. **No step should begin until the previous step's verification has passed.** This sequence supersedes the illustrative one in the task prompt where it differs — repository/loopback changes are grouped and executed before any DNS/TLS work, since they are independently verifiable against the existing HTTP-only, VPS-internal-only surface and should not be entangled with the higher-risk host-Nginx/Certbot/firewall work.

### Step 0 — Pre-flight (no change)
- **Prerequisite:** none.
- **Action:** run the full VPS inspection checklist from the prior discovery report once more (stack status, UFW status, disk space, existing site file location/content for `bikeworkshop.storm-ark.com`) to catch any drift since the report that produced §1's findings.
- **Verification:** `docker compose ... ps` (with `--env-file .env.staging`) shows all three containers `Up`/`healthy`; `curl http://127.0.0.1:8012/api/v1/health` → `200`; `sudo ufw status verbose` matches the reported ruleset; `sudo certbot certificates` shows the existing Bicycle Workshop cert with its reported expiry.
- **Rollback:** N/A (read-only).

### Step 1 — Repository-side Phase 26 changes (prepared, committed to a PR, but the PR is not deployed by this step)
- **Prerequisite:** explicit authorization to implement Phase 26 (this document does not grant it).
- **Action:** implement, in one PR: (a) `docker-compose.staging.yml`'s loopback-binding change (§4); (b) `bootstrap/app.php`'s trusted-proxy middleware (§9, once finalized); (c) `apps/api/.env.staging.example`'s `APP_URL`/`SESSION_SECURE_COOKIE` guidance comments updated to reference the real hostname and the correct sequencing; (d) doc updates (§13 below); (e) the `CURRENT_STATE.md` PR #37 correction (§13). CI (backend/mobile workflows) must pass.
- **Verification:** CI green; PR reviewed and merged to `main`.
- **Rollback:** revert the PR (`git revert`) before it is ever deployed — zero VPS impact, since nothing has touched the VPS yet at this point.

### Step 2 — Update the staging checkout (optional, §2's recommendation) and/or deploy Step 1's merged commit
- **Prerequisite:** Step 1 merged (or, if the product owner declines §2's checkout update, this step deploys only Step 1's own new commit on top of the existing `eb22782` checkout — a `git fetch`/`cherry-pick` rather than a full `pull` — the plan below assumes the full update, since Step 1's trusted-proxy change itself must reach the VPS one way or another).
- **Action, on the VPS, from `/home/deploy/company-app/company-app-build/company-app`:**
  ```sh
  git fetch origin main
  git log HEAD..origin/main --oneline        # confirm exactly the commits §2 already reviewed
  git status                                   # confirm .env.staging and apps/api/.env remain untracked/unaffected
  git merge --ff-only origin/main              # fails loudly rather than silently diverging if history isn't a clean fast-forward
  docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging build app nginx
  docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging up -d
  ```
- **Verification:** `docker compose ... ps` → all three `Up`/`healthy`; `curl http://127.0.0.1:8012/api/v1/health` → `200`; a real Administrator login against the API succeeds (mirrors UAT-24-02); `docker compose ... exec app php artisan migrate:status` shows no pending migrations (expected — §2 confirmed none exist in this range).
- **Rollback:** `git reset --hard eb227826aea776a303e05126743fa9c056ea2852` (safe — this only resets the checkout, and no migration ran, so there is nothing to reverse at the database level) followed by the same `build`/`up` sequence to redeploy the previous, already-proven image. `.env.staging`/`apps/api/.env` are untracked/git-ignored and are never touched by `git reset` — confirm with `git status` before and after.

### Step 3 — Docker loopback-binding hardening
- **Prerequisite:** Step 2 verified healthy.
- **Action:** `docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging up -d nginx` (§4).
- **Verification:** §4's verification (unchanged `app`/`mysql`, `nginx` recreated, health check still `200` from the VPS itself).
- **Rollback:** revert the Compose file line to `"8012:80"`, `up -d nginx` again.

### Step 4 — DNS record creation
- **Prerequisite:** Steps 1–3 verified; product owner has access to the `storm-ark.com` zone.
- **Action:** product owner creates the A record (§6). **Not performed by this session.**
- **Verification:** `dig +short company-staging.storm-ark.com` from an external network resolves to the VPS's public IP. Allow for propagation delay before proceeding.
- **Rollback:** delete the DNS record — no VPS-side effect either way, since nothing on the VPS depends on DNS resolving yet at this point.

### Step 5 — Initial HTTP-only host-Nginx vhost
- **Prerequisite:** Step 4 verified (DNS resolves).
- **Action:** create the new site file (§5), `sudo ln -s` into `sites-enabled/` alongside the existing Bicycle Workshop site, `sudo nginx -t`, `sudo systemctl reload nginx`.
- **Verification:** `sudo nginx -t` passes before reload; `curl http://company-staging.storm-ark.com/api/v1/health` from an **external** network → `200`, proving the full DNS→host-Nginx→loopback→Docker-nginx→PHP-FPM chain works over plain HTTP; the existing `curl https://bikeworkshop.storm-ark.com/...` (or equivalent) still returns its expected response, unaffected.
- **Rollback:** `sudo rm /etc/nginx/sites-enabled/company-staging.storm-ark.com` (leaving `sites-available/` for reference), `sudo nginx -t && sudo systemctl reload nginx` — restores host Nginx to exactly its pre-Step-5 state; Bicycle Workshop's own site file is never touched by this step, so it cannot be affected.

### Step 6 — Certificate issuance
- **Prerequisite:** Step 5 verified (external HTTP reachability proven — Certbot's HTTP-01 challenge needs this).
- **Action:** `sudo certbot --nginx -d company-staging.storm-ark.com` (§7).
- **Verification:** Certbot reports success; `sudo nginx -t` passes; `curl -v https://company-staging.storm-ark.com/api/v1/health` from an external network → `200` with a certificate that validates with no `-k`/`--insecure`; `sudo certbot certificates` lists both sites with correct expiries; `sudo certbot renew --dry-run` succeeds for both.
- **Rollback:** `sudo certbot delete --cert-name company-staging.storm-ark.com` (removes only the new certificate — Certbot scopes this per-certificate, the Bicycle Workshop certificate is a separate `--cert-name` and is untouched), then either restore Step 5's pre-Certbot vhost file from the backup taken before running Certbot (§10) or re-run Step 5 from this document's template.

### Step 7 — `APP_URL` transition
- **Prerequisite:** Step 6 verified (HTTPS genuinely works end-to-end externally).
- **Action:** on the VPS, edit `apps/api/.env`'s `APP_URL` from `http://localhost:8012` to `https://company-staging.storm-ark.com`, then `docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging exec app php artisan config:clear && ... exec app php artisan config:cache` (a cached config from the earlier runbook run must be invalidated for the new value to take effect).
- **Verification:** `docker compose ... exec app php artisan about` shows the new `APP_URL`; any Laravel-generated absolute URL (if applicable) reflects `https://company-staging.storm-ark.com`; the health/login smoke tests from Step 6 still pass.
- **Rollback:** edit `APP_URL` back to `http://localhost:8012`, re-run `config:clear`/`config:cache`. No container recreation needed either direction (env value only).

### Step 8 — Trusted-proxy verification
- **Prerequisite:** Step 7 verified; Step 1's `bootstrap/app.php` change already deployed as part of Step 2.
- **Action:** none (the code change is already live) — this step is pure verification.
- **Verification:** trigger a request that reveals Laravel's view of scheme/IP (e.g., a route that echoes `$request->isSecure()`/`$request->ip()` via `tinker` against a real incoming request's logged values, or inspect `storage/logs/laravel.log` for any URL Laravel generates during the Step 7/9 smoke tests — confirm it is `https://`, not `http://`). See §9 for exactly what "correct" looks like.
- **Rollback:** if scheme detection is wrong, this is a Step-1-code-level fix (adjust the `trustProxies` configuration), redeployed via the same mechanism as Step 2 — not a runtime rollback, since the change is inert (does nothing) until a forwarding header actually arrives, so leaving it in place misconfigured is not itself unsafe, just ineffective until corrected.

### Step 9 — `SESSION_SECURE_COOKIE` transition
- **Prerequisite:** Steps 6–8 all verified — real HTTPS, correct `APP_URL`, correct scheme detection.
- **Action:** edit `apps/api/.env`'s `SESSION_SECURE_COOKIE` from `false` to `true`, `config:clear`/`config:cache` as in Step 7.
- **Verification:** **Admin Backoffice login over `https://company-staging.storm-ark.com` succeeds and the session persists across a page navigation** (the specific failure mode this flag guards against — a secure-only cookie silently dropped over what Laravel might still perceive as an insecure connection if Step 8 were wrong). This is the single most important verification in this sequence, per the task's own explicit caution.
- **Rollback:** edit back to `false`, `config:clear`/`config:cache` — immediate, no container recreation, safe to do at the first sign of a broken Admin session.

### Step 10 — Flutter staging build & real-device validation
- **Prerequisite:** Step 9 verified.
- **Action:** `flutter build apk --dart-define=API_BASE_URL=https://company-staging.storm-ark.com/api/v1` (or `flutter run` for a connected device) — no source change (§11). Execute §M's real-device validation plan from the prior discovery report (login, session-restore-after-relaunch, all five tabs, dark-mode toggle, logout) on a real device over a real external network.
- **Verification:** every scenario in the prior report's §M list passes; **no HTTP fallback, no certificate warning, no LAN/VPN dependency** — each explicitly re-checked.
- **Rollback:** N/A — this step makes no server/infra change; a failure here means returning to whichever earlier step's verification it implicates (most likely Step 8/9 if it's an auth/session issue, or DNS/TLS if it's a reachability issue).

### Step 11 — Documentation & UAT log closure
- **Prerequisite:** Step 10 complete (pass or fail — document the real outcome either way).
- **Action:** update `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/DECISIONS.md` (new DEC for the TLS architecture), `docs/02_ARCHITECTURE.md` §31, `docs/05_SECURITY_MODEL.md`, `docs/DEPLOYMENT_STAGING.md` §12/§13, add Phase 26's own UAT rows to `docs/testing/UAT_LOG.md`, write the Phase 26 handoff. Record Step 10's actual results as `NOT RUN`→ready-for-product-owner-confirmation, or, if the product owner themself performed Step 10 and reports success, that report may be recorded — but only the product owner's own `PASS` claim is ever written as `PASS` (`CLAUDE.md` §7).
- **Verification:** documentation review.
- **Rollback:** N/A (docs only).

---

## 9. Trusted-Proxy Analysis (Planning Question 9) — Evidence-Based

**Topology:** `Client (HTTPS)` → `Host Nginx (443, terminates TLS)` → `HTTP, loopback, 127.0.0.1:8012` → `Docker nginx (company-app-nginx)` → `FastCGI, container-internal network` → `PHP-FPM/Laravel`.

**Evidence from `docker/nginx/default.conf` (read in full this session and the prior one):** the vhost's only FastCGI-relevant lines are
```
location ~ \.php$ {
    fastcgi_pass app:9000;
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_hide_header X-Powered-By;
}
```
There is **no explicit `fastcgi_param HTTP_X_FORWARDED_PROTO ...`/`HTTP_X_FORWARDED_FOR ...`/`HTTP_X_REAL_IP ...` line anywhere in this file.** This matters because it means forwarding depends entirely on nginx's *own default* FastCGI behavior, not on anything this vhost explicitly configures.

nginx's `ngx_http_fastcgi_module` implements the standard CGI/1.1 meta-variable convention: **every HTTP request header nginx itself received is automatically passed to the FastCGI backend as an `HTTP_<UPPERCASED_HEADER_NAME>` parameter**, independent of the `fastcgi_params` include file's contents (that file supplies the *non-header-derived* CGI variables — `SCRIPT_FILENAME`, `QUERY_STRING`, `REQUEST_METHOD`, etc. — not header passthrough, which nginx's FastCGI implementation performs unconditionally). This is the same well-established behavior that already lets this application's PHP code read `$_SERVER['HTTP_AUTHORIZATION']`/`HTTP_USER_AGENT`/every other ordinary request header today, with zero explicit `fastcgi_param` line for any of them in this file. **Conclusion: `docker/nginx/default.conf` requires no change** — whatever `X-Forwarded-Proto`/`X-Forwarded-For`/`X-Real-Ip`/`Host` headers the host Nginx sets when it proxies to `127.0.0.1:8012` will reach Docker nginx, and Docker nginx will pass them through to PHP-FPM automatically, exactly as every other header already does. This is inferred from nginx's documented, standard FastCGI behavior, not independently executed in this sandbox (no live nginx/PHP-FPM pair to test against) — **Step 8 of §8 exists specifically to confirm this inference against the real stack** before relying on it for Step 9's cookie-security flip.

**What Laravel needs:** Laravel 13's `bootstrap/app.php` currently configures no `trustProxies` at all (confirmed absent in both this session and the prior one). Without it, Symfony/Laravel's `Request` trusts only the immediate `REMOTE_ADDR` it sees and ignores `X-Forwarded-*` headers entirely — meaning `$request->isSecure()` would report `false` even over a fully working HTTPS chain, and `SESSION_SECURE_COOKIE=true` (Step 9) would then break every Admin Backoffice session, exactly the failure mode the task warned against.

**The specific IP Laravel would see as `REMOTE_ADDR`** is not host Nginx's real address — it is whatever address Docker's port-publishing/NAT layer presents for the connection arriving at the `nginx` container's published `80` (mapped from loopback `8012`), typically the Docker bridge network's gateway address on the `company-app-staging` network. This address is **not fixed by any file in this repository** (the network's subnet is Compose-default-allocated, not pinned in `docker-compose.staging.yml`) and could change if the network is ever recreated — so hardcoding a specific IP/CIDR as the "trusted proxy" would be fragile and would require re-verification (`docker network inspect company-app-staging`) after any future stack recreation, an ongoing maintenance burden with no repository-visible guarantee of stability.

**Recommendation — trust all, but only because the network boundary already does the real work:** configure Laravel to trust all proxies for forwarded-header purposes (`trustProxies(at: '*', headers: ...)`, Laravel 13's documented mechanism, the direct equivalent of the pre-Laravel-11 `TrustProxies::class` with `protected $proxies = '*'`). This is **not** "blindly trusting arbitrary public proxies" — after Step 3 (§4, loopback binding) and given UFW never opens 8012 (§ Firewall Recommendation, prior report §K, unchanged by these findings), **the only process on Earth that can ever open a TCP connection to Docker nginx's published port is something already running on this VPS** — in practice, only host Nginx, since nothing else on the host has any reason to. The Docker-internal hop (Docker nginx → PHP-FPM) is container-to-container on an isolated bridge network with only this application's own three services on it — no other tenant, no other project, can reach it either. "Trust all" here means "trust the two things that are structurally the only possible source of a request at all," which is exactly the scenario Laravel's own documentation names as the correct use of `at: '*'` (a single operator-controlled load balancer/ingress with no other path in) — not a general-purpose public API trusting arbitrary intermediaries.

```php
// bootstrap/app.php, added to the existing ->withMiddleware(...) closure
$middleware->trustProxies(
    at: '*',
    headers: Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO,
);
```

**Alternative considered and rejected for now:** pinning the exact Docker network gateway CIDR (discoverable via `docker network inspect company-app-staging` on the VPS). More "precise" in principle, but defends only against a threat that doesn't exist on this network (no other container/tenant shares it), while adding a real, silent-failure-prone maintenance dependency (a stack recreation could shift the subnet with no repository-visible warning). Not recommended unless a future security review specifically demands it.

---

## 10. Port 8442 (Planning Question 10)

**Formally retire the 8442 reservation as an active Phase 26 requirement.** Under the host-Nginx-terminates-TLS architecture, `docker-compose.staging.yml`'s already-unmapped `8442` line stays exactly as it is today — commented out, undocumented as "coming soon," and never bound. Phase 26's own documentation update (§8 Step 11) should record this explicitly: the original DEC-047 reservation is superseded by DEC-047's own architecture extending correctly to a host-level TLS terminator instead, and 8442 is retired, not merely "still pending." No firewall rule for 8442 is ever added. If a future, unrelated need for a second, directly-TLS-terminated port ever arises, that is a new decision for whichever phase names it — not assumed here.

---

## 11. Flutter Configuration (Planning Question 11)

**No source change required**, confirmed again this session (`apps/mobile/lib/core/config/app_config.dart` unchanged since the prior report, DEC-021's `--dart-define` mechanism already environment-agnostic). Phase 26's only mobile-facing deliverable is the exact documented build command:
```sh
flutter build apk --dart-define=API_BASE_URL=https://company-staging.storm-ark.com/api/v1
flutter run --dart-define=API_BASE_URL=https://company-staging.storm-ark.com/api/v1
```
recorded in `docs/DEPLOYMENT_STAGING.md` §12 (replacing its current `http://<VPS-IP-or-domain>:8012` placeholder) as part of §8 Step 11.

---

## 12. Phase 25 UAT Rows (Planning Question 12)

`UAT-25-01` through `UAT-25-04` remain `NOT RUN` in `docs/testing/UAT_LOG.md` — untouched by this planning session, exactly as instructed. §8 Step 10 is the concrete point where a real device first exists with real staging connectivity — the natural moment to actually execute all four scenarios, alongside the new Phase 26-specific scenarios. Recording any of them `PASS` remains exclusively the product owner's own act, never this session's or any implementing session's (`CLAUDE.md` §7).

---

## 13. Stale `CURRENT_STATE.md` Correction (Planning Question 13)

`docs/CURRENT_STATE.md` line 7 currently reads "PR #37 ... opened against `main`, not yet merged" — confirmed stale; PR #37 is merged (`4ce9f6e`). This document does not correct it now (this planning session, like its predecessor, makes no code/doc commits without authorization) — it is queued as part of §8 Step 11's documentation-closure step, alongside every other `CURRENT_STATE.md` update Phase 26 itself will need to make anyway (advancing the "current phase"/"completed" sections), so it is fixed exactly once, in context, rather than as a separate throwaway edit now.

---

## 14. Rollback Plan (consolidated)

See each step in §8 for its specific rollback; consolidated here per the task's request:

| Component | Rollback |
|---|---|
| Host Nginx vhost | Remove the `sites-enabled/` symlink, `nginx -t && systemctl reload nginx`. Bicycle Workshop's own file is never edited by any Phase 26 step, so it cannot be affected either way. |
| Certbot | `certbot delete --cert-name company-staging.storm-ark.com` — scoped to the one new certificate; the existing Bicycle Workshop certificate is a separate `--cert-name` and untouched by any Phase 26 action. |
| `APP_URL` | Edit back to `http://localhost:8012` in `apps/api/.env`, `config:clear`/`config:cache` — no container recreation. |
| `SESSION_SECURE_COOKIE` | Edit back to `false`, `config:clear`/`config:cache` — no container recreation; this is the fastest, lowest-risk rollback in the entire sequence and should be the first thing tried if Admin Backoffice login breaks at Step 9. |
| Laravel trusted-proxy change | Code-level revert (`git revert` the Step 1 commit or a targeted follow-up commit), redeployed via Step 2's mechanism — inert (does nothing) until an actual forwarding proxy sends the headers, so it is never itself the cause of an outage, only ever a misconfiguration to correct. |
| Docker 8012 loopback binding | Revert the one Compose line, `up -d nginx` — recreates only the stateless `nginx` container. |
| Staging checkout update | `git reset --hard eb227826aea776a303e05126743fa9c056ea2852` (safe — no migration ran in this range, §2), rebuild `app`/`nginx`, `up -d`. `.env.staging`/`apps/api/.env` are git-ignored and untouched by `git reset` either direction. |

**Explicitly protected, by design, at every step:** SSH access (no Phase 26 step ever touches port 22 or its UFW rule); Bicycle Workshop (its own site file, certificate, and Docker stack are never referenced or edited by any Phase 26 action); Company App MySQL data and the `mysql-data`/`app-storage` volumes (no step in §8 runs `down -v`, deletes a volume, or touches the `mysql`/`app` services' own definitions at all — only `nginx`'s port mapping changes); existing certificates (Certbot's per-`--cert-name` scoping keeps the two sites' certificates fully independent); every other project on this shared VPS (no step opens a new firewall port beyond what already exists, installs new host-level software, or modifies any file outside this project's own Compose directory and its one new Nginx site file).

**Pre-change backup, before Step 6 specifically:** `sudo cp /etc/nginx/sites-available/company-staging.storm-ark.com /etc/nginx/sites-available/company-staging.storm-ark.com.pre-certbot` immediately before running Certbot, so Step 6's rollback has an exact file to restore rather than relying on reconstructing §5's template from memory.

---

## 15. Acceptance Criteria

- `https://company-staging.storm-ark.com/api/v1/health` returns `200` from an external network with a certificate that validates with no warnings.
- `http://company-staging.storm-ark.com/...` redirects to HTTPS (Certbot's default behavior) rather than serving the application over plain HTTP.
- Admin Backoffice login succeeds over HTTPS with `SESSION_SECURE_COOKIE=true` active, and the session persists across navigation.
- A real device, over a real external network (not the VPS's own LAN), completes: unauthenticated launch → login → shell with all five tabs reachable → relaunch with session restored → logout → back to login — with no HTTP fallback, no certificate warning.
- UAT-25-01 through UAT-25-04 and the new Phase 26 UAT rows are all at least attempted and accurately recorded (`NOT RUN`/ready-for-owner-review, or product-owner-reported `PASS`) — none marked `PASS` by an AI session.
- Port 8442 formally retired in documentation; 8012 never publicly reachable (UFW unchanged for that port).
- Bicycle Workshop and every other VPS tenant unaffected — reverified after Step 6 specifically (the highest-risk step for a shared host-Nginx config error).
- All required documentation (`CLAUDE.md` §6) updated; a Phase 26 handoff written.

## 16. Explicit Non-Goals

Unchanged from the predecessor discovery report: no business-module mobile screen; no Admin Backoffice UI change; no change to local-dev `docker-compose.yml`; no shared application-runtime reverse proxy (only a thin per-project TLS/vhost router at the host level); no object-storage provisioning; no DB-aware `/api/v1/health`; no CD/deploy-workflow automation; no production environment work; no rotation of the preserved `APP_KEY`/DB credentials. **Newly explicit, from this session's findings:** no re-architecture of Docker-internal networking to pin a trusted-proxy CIDR (§9's rejected alternative); no second host-level Nginx instance; no use of port 8442 in any form.

## 17. Risks/Open Questions Remaining

1. **§9's FastCGI header-forwarding claim is evidence-based inference, not this-session-verified execution** — Step 8 exists specifically to close this gap against the real stack before Step 9 (the cookie-security flip) depends on it.
2. **The exact `sites-available` convention and file content for `bikeworkshop.storm-ark.com`** hasn't been read by this session (no VPS access) — Step 5 should confirm it matches this document's assumed structure before creating a sibling file, and adapt if the real file differs materially (e.g., if it uses an `include`d snippet for the proxy headers rather than inline `proxy_set_header` lines, the new file should follow the same convention for consistency).
3. **Time elapsed since the VPS inspection that produced this session's input** — Step 0 exists specifically to catch any drift.
4. **The product owner's decision on §2's optional checkout update** is still open — §8's sequence assumes "yes" (folding the trusted-proxy code change into the same deploy); if declined, Step 1's trusted-proxy change still needs its own, smaller deployment path (a targeted `git cherry-pick` of just that commit onto `eb22782`, or an accepted minor exception to "don't update the checkout") — worth the product owner's explicit call before Step 1 is authorized, not assumed by this document.

## 18. Recommended Next Action

Product owner reviews this document and either (a) authorizes Phase 26 implementation exactly as sequenced in §8, (b) authorizes it with named modifications, or (c) requests further discovery on any of §17's open items (most usefully, the Bicycle Workshop site file's actual content, readable in one more read-only VPS command: `cat /etc/nginx/sites-available/bikeworkshop.storm-ark.com` or wherever it actually lives). This document does not proceed to implementation on its own.

---

## Gate 1 Addendum (2026-09-22, same day): Authorization, Real Framework Evidence, and What Was Implemented

The product owner reviewed this document and authorized **Gate 1 only** — repository-side changes required to prepare Gate 2, explicitly excluding any VPS/DNS/Nginx/Certbot/firewall/deployment/environment/real-device action. This addendum records what that authorization resolved and what was actually built; §1–18 above are left unchanged as the historical planning record.

### §17 item 2 resolved: the real Bicycle Workshop vhost

The product owner inspected `/etc/nginx/sites-available/bikeworkshop.storm-ark.com` directly and reported its relevant structure:

```nginx
server_name bikeworkshop.storm-ark.com;

location / {
    proxy_pass http://127.0.0.1:8013;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

with Certbot-managed `443 ssl` directives and a Certbot-managed port-80 redirect block. This matches §5's assumed template almost exactly, with one addition this document's original template omitted: **`proxy_http_version 1.1;`** — worth carrying into Gate 2's new vhost for the same reason the sibling site has it (HTTP/1.0 is `proxy_pass`'s default, which disables keep-alive to the upstream and can behave incorrectly with chunked responses; `1.1` is the correct, modern choice and costs nothing to match). No other deviation from §5's assumption. §17 item 2 is now closed — no further VPS inspection is needed for this question.

### §9 (trusted-proxy analysis) superseded by real framework evidence, not merely inferred behavior

§9's original analysis reasoned from nginx's documented FastCGI header-forwarding convention and Laravel's publicly documented `trustProxies` API, without direct access to either this sandbox's live nginx/PHP-FPM pair or the installed framework source. Gate 1 obtained the real evidence:

- **Laravel 13.32.0's actual `Illuminate\Foundation\Configuration\Middleware::trustProxies()` signature** (fetched directly from the exact tagged source, `https://raw.githubusercontent.com/laravel/framework/v13.32.0/src/Illuminate/Foundation/Configuration/Middleware.php`, and cross-checked against `composer.lock`'s resolved version — `composer install` itself could not complete in this sandbox for unrelated dev-dependency network reasons, but the single file needed was fetched directly):
  ```php
  public function trustProxies(array|string|null $at = null, ?int $headers = null)
  {
      if (! is_null($at)) { TrustProxies::at($at); }
      if (! is_null($headers)) { TrustProxies::withHeaders($headers); }
      return $this;
  }
  ```
- **`Illuminate\Http\Middleware\TrustProxies`'s own default `$headers` property** (same commit): `Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PREFIX | Request::HEADER_X_FORWARDED_AWS_ELB`. Because `Middleware::trustProxies()` only calls `TrustProxies::withHeaders()` `if (! is_null($headers))`, **omitting `$headers` entirely leaves this comprehensive default in effect** — which already includes every header the planned host-Nginx vhost sends (`X-Forwarded-For`/`-Host`/`-Port`/`-Proto`).

**Conclusion, superseding §9's original code sketch:** the smallest correct call is `$middleware->trustProxies(at: '*')` — **omitting** the `headers:` argument entirely, rather than this document's original suggestion of manually re-specifying a four-flag bitwise-OR. This is smaller (one named argument, not two), strictly equivalent in effect for this topology (the manually-specified four flags are a subset of the framework's own default six), and removes a place a future edit could accidentally narrow the trusted set incorrectly. §9's core safety argument (`at: '*'` is sound specifically because the loopback bind + UFW make the host Nginx the only possible request source) is unchanged and reaffirmed by this evidence, not revised.

### What Gate 1 actually implemented

Branch `claude/phase-26-staging-connectivity-tls`, from `main` at `4ce9f6ea9d9ac86ef57da5e6b067cde37ca09df9`:

1. `docker-compose.staging.yml` — `nginx` service loopback-bound (`127.0.0.1:8012:80`); `8442` reservation retired in the committed comments.
2. `apps/api/bootstrap/app.php` — `$middleware->trustProxies(at: '*')` added to the existing `->withMiddleware()` closure, per the resolved analysis above.
3. `apps/api/.env.staging.example` — `APP_URL`/`SESSION_SECURE_COOKIE` comments updated with the approved hostname and exact Gate 2 sequencing.
4. `docs/DEPLOYMENT_STAGING.md` §12/§13 rewritten; a `--env-file .env.staging` callout added.
5. `docs/02_ARCHITECTURE.md` (new §31a), `docs/05_SECURITY_MODEL.md`, `docs/DECISIONS.md` (new DEC-050), `docs/CURRENT_STATE.md` (advanced to Phase 26 Gate 1; PR #37 stale-merge statement corrected), `docs/CHANGELOG.md`, `docs/testing/UAT_LOG.md` (four new `NOT RUN` Phase 26 rows) all updated.
6. This document itself — this addendum.

**Not implemented (Gate 2, separately authorized):** every item in §8's Steps 4–11 — DNS, the host-Nginx vhost, Certbot, any UFW change, deploying this branch's changes to the actual staging checkout, the `APP_URL`/`SESSION_SECURE_COOKIE` live flips, and all real-device validation. See the implementation session's own final report for exact test/CI results and the PR link.
