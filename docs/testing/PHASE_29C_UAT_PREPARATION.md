# Phase 29C — UAT Preparation (Deployment, APK + Staging Data)

*Operator runbook. No AI session has run anything in this file against the staging VPS or on the Windows build machine: this AI session cannot reach the VPS or build an APK. Every step marked **[Operator]** is pending until its output is recorded. The data script in §7 was rehearsed on disposable scratch databases only (see `docs/testing/TEST_STATUS.md`, "Phase 29C — UAT preparation").*

**Status (as written):** UAT-29C-01…08 are `NOT RUN`. This runbook prepares the deployment, the APK and the data; it runs no scenario. When 29C closes, **Phase 29 closes with it**.

**How each operator step is written.** Every step says:
- **Where:** the machine and directory.
- **Run:** the exact commands, to copy as they are.
- **What it does.**
- **Expected:** what a good result looks like, and when to **STOP**.
- **Paste back:** what to send to the AI session. **Never paste** anything from a credentials file, a password, or a password manager.

**Lessons carried over from Phases 27–29B:**
- **No password ever leaves the VPS terminal, and none is ever typed or pasted into a shell.**
  - Passwords go to a `0600` file, then into a password manager, and the file is shredded.
  - Paste a password **only into the app's sign-in field** — never into a terminal (a pasted password can become a file name or a shell-history line; Phase 29B found one this way), a chat, an AI tool, a ticket or a document.
  - `seed` and `rotate` write passwords **only** to STDOUT, as `email password` lines. Every other line goes to STDERR, so the redirected file holds nothing else.
- **Run the stages in order** and stop at the first unexpected output.
- **Extract the script from merged `origin/main`** and check its SHA-256 before running it.
- **After recreating containers, check that MySQL is reachable** (Phase 28): a DNS error for `mysql` in `migrate:status` means the MySQL container is on the wrong network — recreate it from `docker-compose.staging.yml` (`$C up -d mysql`, then `$C restart app`), never `down -v`.
- **Backup listing (29B correction):** `ls -lt /home/deploy/backups/company-app-*.sql | head -3` — newest first. Sorting by name puts the dated dumps before the old `pre-gate2b` ones, so `| tail -2` misses the new dump.
- **Always run the APK build** and show its output, even if an earlier build exists.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **Phase 29C implementation and UAT source baseline** | `c79ed90cac0263a7f7e452c0f7d0861d4adac380` | PR #69 merge into `main`. The source for both the staging deployment **and** the final UAT APK. |
| Running staging implementation (before §2) | `7e29ffe809c1282c4c9f7e177087cee02fe8410d` | Phase 29B (PR #65). The API there already serves every 29C read; only the list order (R-19) differs. Redeploy anyway, so staging runs the reviewed code. |
| Changes between them | — | `apps/api`: an `id` tie-breaker in five controllers (projects, clients, contacts, members, milestones) and their tests. The rest is Phase 29C mobile code and docs (PRs #66–#69). **No migration** (still 45 Ran), **no route**, no environment-variable change, `composer.lock` unchanged. |

## 2. Staging deployment — **[Operator, VPS]**

**Where:** the VPS, as the `deploy` user, in the staging checkout.

**What it does:** moves the checkout from `7e29ffe` to `c79ed90` (fast-forward only), takes a MySQL backup, rebuilds and restarts only `app` and `nginx` (MySQL and its data are not touched), confirms no migration is pending, and rebuilds the Laravel caches. This follows `docs/DEPLOYMENT_STAGING.md` §9.

**Run** (one block at a time):

```sh
cd /home/deploy/company-app/company-app-build/company-app
git status --porcelain                  # expect: nothing
git fetch origin
git merge --ff-only c79ed90cac0263a7f7e452c0f7d0861d4adac380
git rev-parse HEAD                      # must print c79ed90cac0263a7f7e452c0f7d0861d4adac380
```

```sh
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
$C exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  > /home/deploy/backups/company-app-$(date +%Y%m%d-%H%M%S).sql
ls -lt /home/deploy/backups/company-app-*.sql | head -3     # newest first
$C build app nginx
$C up -d --no-deps app nginx            # mysql untouched
$C exec app php artisan migrate:status  # expect all 45 Ran, none pending
$C exec app php artisan config:cache
$C exec app php artisan route:cache
$C exec app php artisan view:cache
$C exec app php artisan config:show scheduling.company_timezone   # Asia/Manila
ss -ltnp | grep 8012                    # only 127.0.0.1:8012
$C ps                                   # mysql "healthy", app and nginx "Up"
```

**Expected:**
- `git status --porcelain` prints nothing. If it lists a file, **STOP**: paste its name — or, if the name looks like a password, paste nothing and just say so.
- `git merge` prints `Updating 7e29ffe..c79ed90` and `Fast-forward`; `git rev-parse HEAD` prints the full `c79ed90…` SHA.
- The first `ls -lt` line is today's `company-app-<date>-<time>.sql`, larger than 131,099 bytes (the 29B dump). A size of `0` means the dump failed — **STOP**.
- `migrate:status`: 45 rows, all `Ran`.
- The timezone line ends in `Asia/Manila`.

**Smoke tests** (same VPS, any directory):

```sh
B=https://company-staging.storm-ark.com
curl -s -o /dev/null -w 'up %{http_code}\n'        $B/up                                                 # 200
curl -s -o /dev/null -w 'login %{http_code}\n'     $B/login                                              # 200
curl -s -o /dev/null -w 'home %{http_code}\n'      -H 'Accept: application/json' $B/api/v1/me/home       # 401
curl -s -o /dev/null -w 'projects %{http_code}\n'  -H 'Accept: application/json' $B/api/v1/projects      # 401
curl -s -o /dev/null -w 'clients %{http_code}\n'   -H 'Accept: application/json' $B/api/v1/clients       # 401
curl -s -o /dev/null -w 'contacts %{http_code}\n'  -H 'Accept: application/json' $B/api/v1/contacts      # 401
```

**Expected:** `up 200`, `login 200`, then `401` for the other four. A `404` or `500` — **STOP**.

**Paste back:** the `git status` and `git rev-parse HEAD` lines; the three `ls -lt` lines; the `migrate:status` summary (count of Ran / pending); the timezone line; the six smoke-test lines; the `ss` line; the `$C ps` table. **Never paste:** `.env.staging` contents or anything containing `MYSQL_ROOT_PASSWORD`.

## 3. Final UAT APK — **[Operator, Windows build machine]**

**Where:** PowerShell, in the repository root on the Windows build machine.

**What it does:** checks out exactly `c79ed90`, runs the same Flutter checks CI runs (with per-area counts), **builds** the release APK against staging, prints its size, SHA-256, manifest facts and signer, and installs it.

**Run:**

```powershell
git fetch origin
git checkout --detach c79ed90cac0263a7f7e452c0f7d0861d4adac380
git rev-parse HEAD                 # must print c79ed90cac0263a7f7e452c0f7d0861d4adac380
git status --porcelain             # must print nothing
cd apps/mobile

flutter --version
dart --version
java -version

flutter pub get
dart format --output=none --set-exit-if-changed .
flutter analyze
flutter test                          # expect +473 "All tests passed!"
flutter test test/features/projects   # expect +52
flutter test test/features/people     # expect +84
flutter test test/features/tasks      # expect +95

# ALWAYS run this step, even if an APK already exists.
flutter build apk --release --dart-define=API_BASE_URL=https://company-staging.storm-ark.com/api/v1

git -C ../.. status --porcelain     # still nothing
$apk = "build\app\outputs\flutter-apk\app-release.apk"
Get-Item $apk | Select-Object FullName, Length, LastWriteTime
Get-FileHash -Algorithm SHA256 $apk
$bt = "$env:LOCALAPPDATA\Android\Sdk\build-tools\36.0.0"
& "$bt\aapt2.exe" dump badging $apk | Select-String "package:|compileSdkVersion|sdkVersion|targetSdkVersion|uses-permission"
& "$bt\apksigner.bat" verify --print-certs $apk

adb devices                        # the phone's serial, state "device"
adb install -r $apk                # expect "Performing Streamed Install" then "Success"
```

**Expected:**
- `git rev-parse HEAD` prints the full `c79ed90…` SHA; both `git status --porcelain` print nothing. **STOP** otherwise.
- `dart format` changes nothing; `flutter analyze` says `No issues found!`.
- The test counts above, exactly. A different count means a different checkout — **STOP**.
- `flutter build apk` ends with `√ Built build\app\outputs\flutter-apk\app-release.apk`; `LastWriteTime` is **now**.
- `package: name='com.companyapp.mobile'`; `uses-permission: name='android.permission.INTERNET'` present; a debug signer (known, deferred to Phase 38).
- `adb install` ends with `Success`.

**Paste back:** all of the output above (it contains no secrets), including the build line and the `adb` lines. This APK is the only authorized APK for Phase 29C UAT; it replaces the Phase 29B APK on the test device. Do not change signing, dependencies or source.

## 4. UAT data plan

**Accounts:** four dedicated `UAT29C` accounts. No existing user or business record is modified; the UAT27, UAT28, UAT29A and UAT29B data is left as it is.

| Key | Email | Role | Staff profile | Used by |
|---|---|---|---|---|
| staff | `uat29c.staff@company-app.test` | staff | `UAT29C-001`, Mia UAT29C-Santos | 29C-01…05, 07, 08 |
| manager | `uat29c.manager@company-app.test` | manager | `UAT29C-002`, Marco UAT29C-Lim | 29C-06 |
| admin | `uat29c.admin@company-app.test` | administrator | `UAT29C-003`, Ada UAT29C-Admin | 29C-06 |
| noprofile | `uat29c.noprofile@company-app.test` | staff | none | 29C-01 (no-profile state) |

Plus `UAT29C-010`, Noel UAT29C-Reyes: a staff record **without** an account, a member of Boiler Upgrade and the lead of the hidden project.

**Projects** (`D` = the company day of the last `seed`; start D−60 and target end D+90 for all):

| Code | Name | Status | Client | Mia | Others | Milestones | Purpose |
|---|---|---|---|---|---|---|---|
| `UAT29C-P01` | UAT29C Boiler Upgrade | Active | UAT29C Acme Facilities | **Project lead** | Noel (member), Marco (member) | 3: Design sign-off (Completed, D−5), Boiler delivery (Pending, D+10), Old supplier visit (Cancelled, D+20) | 29C-02, 03; the Manager's project |
| `UAT29C-P02` | UAT29C Roof Repair | On hold | UAT29C Dormant Holdings (**inactive**) | member | Ada (member) | none | 29C-05 inactive client; the Admin's project |
| `UAT29C-P03` | UAT29C Archive Move | Completed (D−30) | none | member | — | none | Completed date; no client ("Not set") |
| `UAT29C-P04` | UAT29C Long Programme | Planned | UAT29C Beta Works | member | — | **52** (Stage 01…52, weekly) | "Showing 50 of 52" |
| `UAT29C-P05` | UAT29C Hidden Site | Active | UAT29C Acme Facilities | **not a member** | Noel (lead) | none | 29C-03 "You don't have access to this project." |
| `UAT29C-R01…R24` | UAT29C Routine Site 01…24 | Active | none | member | — | none | A second page (28 member projects) |

**Tasks** (assigned to Mia, due D+7): `[UAT29C] Inspect the boiler` (In progress, Boiler Upgrade) and `[UAT29C] Check the hidden site` (To do, **Hidden Site** — her task in a project she can't see).

**Clients and contacts:**

| Code | Name | Status | Details | Contacts |
|---|---|---|---|---|
| `UAT29C-C1` | UAT29C Acme Facilities | Active | email, phone, website, address (12 Harbor Road, Makati, Metro Manila 1200, Philippines) | Maria UAT29C-Cruz (**Primary**, Facilities Manager, email + phone); Leo UAT29C-Tan (email only); Olga UAT29C-Former (**inactive**, hidden) |
| `UAT29C-C2` | UAT29C Dormant Holdings | **Inactive** | phone only | none |
| `UAT29C-C3` | UAT29C Beta Works | Active | email; Cebu City, Philippines | none |

Every UAT29C project, client and contact has an internal `notes` value; the app must never show it.

**What the app should show** as `uat29c.staff`:
- **Projects:** 28, in name order (Archive Move, Boiler Upgrade, Long Programme, Roof Repair, Routine Site 01…24), over **2 pages**; Boiler Upgrade marked **Project lead**; Hidden Site absent.
- **Clients:** every active client on staging (other phases' too). Search **"UAT29C"**: Acme Facilities and Beta Works, but not Dormant Holdings.

## 5. Staging data preparation — **[Operator, VPS]**

All steps: **Where:** the VPS, in the staging checkout, after §2.

**Step 0. Get the script and check it.**

**What it does:** copies the PHP block in §7 of this file, as merged on `origin/main`, into your home directory. It does not change the deployed checkout. The SHA-256 proves the file is exactly the reviewed and rehearsed text.

```sh
cd /home/deploy/company-app/company-app-build/company-app
git fetch origin main
git show origin/main:docs/testing/PHASE_29C_UAT_PREPARATION.md | awk '/^```php$/{f=1;next} /^```$/{f=0} f' > "$HOME/uat29c_data.php"
sha256sum "$HOME/uat29c_data.php"
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
U="$HOME/uat29c_data.php"
```

**Expected:** the SHA printed equals the one in §7. **Any mismatch: STOP.** (`C` and `U` must be set again in every new shell.)

**Paste back:** the `sha256sum` line.

**Step 1. Read-only plan.**

**What it does:** prints the company day, whether the UAT29C accounts and records already exist, and whether the three roles exist. It changes nothing.

```sh
$C exec -T app php -- plan < "$U"
```

**Expected:**
```
stage=plan utc_now=<now> company_day=<today> (Asia/Manila)
staff     absent
manager   absent
admin     absent
noprofile absent
UAT29C staff records: 0 of 4
UAT29C projects: 0 of 29
UAT29C clients: 0 of 3
UAT29C tasks: 0 of 2
roles present: 3/3
```
**STOP** if a UAT29C account already exists that nobody can account for, or roles are not `3/3`.

**Paste back:** the whole output.

**Step 2. Seed.**

**What it does:** in one database transaction, creates the §4 accounts, staff records, clients, contacts, projects, memberships, milestones and tasks. It writes the four new passwords, and nothing else, into a new file only you can read.

```sh
CRED="$HOME/uat29c-credentials.txt"
test ! -e "$CRED" && (umask 077; set -o noclobber; $C exec -T app php -- seed < "$U" > "$CRED"); echo "seed exit=$?"
stat -c '%a %U' "$CRED"      # must be: 600 deploy
wc -l < "$CRED"              # must be: 4
```

**Expected** (on screen, from STDERR):
```
stage=seed utc_now=<now> company_day=<today> (Asia/Manila)
seed: staff created
seed: manager created
seed: admin created
seed: noprofile created
seed: 29 projects, 3 clients, 3 contacts, 2 tasks set for company day <today>
seed: done; 4 new account(s)
seed exit=0
600 deploy
4
```
- **Non-zero exit:** nothing was changed (one transaction). **STOP** and paste the screen output — never the file.
- **Mode is not `600`:** run `shred -u "$CRED"` and **STOP**.
- **Otherwise:**
  1. Open the file privately: `less "$CRED"` (press `q` to leave).
  2. Copy all four passwords into your password manager. Do not paste them anywhere else.
  3. Run `shred -u "$CRED"`, then `ls "$CRED"` — expect `No such file or directory`.

**Paste back:** the screen output above and the `ls` line. **Never paste** the file's contents.

**Step 3. Verify.**

**What it does:** read-only. Runs the real project, member, milestone, client and contact controllers as the UAT29C accounts, the way the app calls them, and prints what the app will show. This is also the **MySQL check** of the R-19 order (only SQLite was used before).

```sh
$C exec -T app php -- verify < "$U"
```

**Expected:**
```
stage=verify utc_now=<now> company_day=<D> (Asia/Manila)
staff projects: 28 over 2 page(s); UAT29C 28
   UAT29C Archive Move [completed]
   UAT29C Boiler Upgrade [active, lead]
   UAT29C Long Programme [planned]
   UAT29C Roof Repair [on_hold]
   UAT29C Routine Site 01 [active]
   … 23 more
search "boiler": UAT29C Boiler Upgrade
UAT29C-P01 UAT29C Boiler Upgrade: client=UAT29C Acme Facilities my_role=project_lead members 3/3 (Mia UAT29C-Santos:project_lead, Noel UAT29C-Reyes:member, Marco UAT29C-Lim:member) milestones 3/3
UAT29C-P04 UAT29C Long Programme: client=UAT29C Beta Works my_role=member members 1/1 (Mia UAT29C-Santos:member) milestones 50/52
UAT29C-P05 (not a member): 403 You do not have access to view this project.
task '[UAT29C] Check the hidden site': assignee=Mia project=UAT29C Hidden Site
clients (active, "UAT29C"): UAT29C Acme Facilities | UAT29C Beta Works
UAT29C-C1 active contacts: Maria UAT29C-Cruz (primary) | Leo UAT29C-Tan
UAT29C-C2 UAT29C Dormant Holdings: status=inactive (opens from UAT29C Roof Repair)
manager member projects: UAT29C Boiler Upgrade [active] (without member= the API would list 29 UAT29C projects)
admin member projects: UAT29C Roof Repair [on_hold] (without member= the API would list 29 UAT29C projects)
noprofile staff record: none
```
**STOP** if any count differs, the order differs, or a line says `UNEXPECTED`.

**Paste back:** the whole output (no secrets in it).

**Step 4. Exposure check.**

**What it does:** read-only. For each UAT29C account, counts API tokens (app sign-ins), web sessions, and login/logout audit events.

```sh
$C exec -T app php -- exposure < "$U"
```

**Expected before UAT:** four lines, each `api_tokens=0 last_used=- web_sessions=0 audit: none`.

**Paste back:** the output.

**Step 5. On the UAT day, if it is not the seeding day — or to repeat a scenario.**

**What it does:** re-dates the milestones and the completed date to the new company day and restores any planned record or membership; creates no account, so it prints no password.

```sh
$C exec -T app php -- seed < "$U" > /dev/null; echo "seed exit=$?"
$C exec -T app php -- verify < "$U"
```

**Expected:** `already existed (password unchanged)` for all four, `seed: done; 0 new account(s)`, `seed exit=0`; then `verify` as in step 3.

**If a password is ever exposed:**
1. Run step 4 (`exposure`) and paste its output.
2. Run `rotate`: exactly four new passwords, all or nothing, and the accounts' tokens and sessions are revoked.
   ```sh
   CRED="$HOME/uat29c-credentials.txt"
   test ! -e "$CRED" && (umask 077; set -o noclobber; $C exec -T app php -- rotate < "$U" > "$CRED"); echo "rotate exit=$?"
   ```
3. Follow the same `stat`, `wc` (4), password-manager and `shred` steps as for `seed`.
4. **A non-zero `rotate` exit changed nothing**, even if STDERR already printed `rotated …` lines: everything is rolled back, and the old passwords still work. Shred the (empty) file and paste the screen output.

## 6. UAT-time notes (for **execution**, not preparation)

- **Device:** the §3 APK replaces the Phase 29B APK. Sign in as `uat29c.staff`, pasting the password from the password manager into the app's sign-in field.
- **UAT-29C-01:** More → **Projects**. Expect the §4 list: Archive Move (Completed), Boiler Upgrade (Active, **Project lead**), Long Programme (Planned), Roof Repair (On hold), then Routine Site 01…; no Hidden Site. Scroll to the end: page 2 loads (Routine Site 22…24) with no duplicates. Search "boiler": only Boiler Upgrade; "zzz": `No project matches "zzz".`. Then sign out, sign in as `uat29c.noprofile`, More → Projects: "No staff profile is linked to this account." and no search box. Sign back in as `uat29c.staff`.
- **UAT-29C-02:** open **Boiler Upgrade**: code `UAT29C-P01`, Active chip, Client UAT29C Acme Facilities, My role Project lead, start and target end dates, the description, **Members (3)** with Mia first (lead), and Milestones (Design sign-off Completed, Boiler delivery Pending, Old supplier visit Cancelled) with no "overdue". No "internal note" text anywhere. Open **Archive Move**: a Completed date, Client "Not set". Open **Long Programme**: the milestones end with "Showing 50 of 52".
- **UAT-29C-03:** in Boiler Upgrade tap **Noel UAT29C-Reyes**: his Staff directory entry. Back, tap the **client**: UAT29C Acme Facilities. Then Tasks → **[UAT29C] Inspect the boiler** → tap its Project: Boiler Upgrade opens in the More tab; switch back to Tasks — the task is still open. Then Tasks → **[UAT29C] Check the hidden site** → tap its Project: "You don't have access to this project." with Try again; you stay signed in.
- **UAT-29C-04:** More → **Clients**: active clients (staging's others too). Search "UAT29C": Acme Facilities and Beta Works only — **not** Dormant Holdings. Clear the search.
- **UAT-29C-05:** open **UAT29C Acme Facilities**: email, phone, website and the address, each with Copy (tap one: "Copied"); **Contacts (2)**: Maria UAT29C-Cruz with **Primary** and her job title, email and phone; Leo UAT29C-Tan with email only; no Olga. Then Projects → Roof Repair → its client **UAT29C Dormant Holdings**: an **Inactive** chip, "Not set" for the missing values, "No active contacts."
- **UAT-29C-06:** sign in as `uat29c.manager`: Projects lists **only** Boiler Upgrade. As `uat29c.admin`: **only** Roof Repair. (Both could see all 29 through the API; the app shows member projects only, R-20.) Sign back in as `uat29c.staff`.
- **UAT-29C-07:**
  - **Offline:** switch on airplane mode, pull to refresh Projects ("Couldn't refresh. Showing earlier information.") and open a project you haven't opened ("Couldn't load this project. Check your connection." with Try again). Reconnect and Try again: it loads.
  - **Revoked:** on the VPS run `$C exec -T app php -- revoke < "$U"` (expected `revoke: uat29c.staff api_tokens_revoked=1` or more), then pull to refresh in the app: it returns to Login with "Your session has ended. Please sign in again."
- **UAT-29C-08:** system dark mode and the largest font: the Projects list, a project detail (scroll to the end), the Clients list and a client detail (scroll to the end).
- **Afterwards:** run §5 step 4 (`exposure`) once more and paste it. The UAT29C data stays in place; any cleanup needs its own authorization.

## 7. Script — `uat29c_data.php`

**Revision 1 (2026-10-11), SHA-256 `1c151c4d4c9fcc7b94918da4a4ed526ac5b9accaa2f415b570702b93a52a4a64`** (the exact text below, with a trailing newline).

```php
<?php

/*
 * Phase 29C UAT data — operator tool (NOT application code; never commit to
 * apps/api, never expose over HTTP).
 *
 * Run inside the staging `app` container, reading this file from stdin:
 *
 *   $C exec -T app php -- <stage> < uat29c_data.php
 *
 * Stages:
 *   plan      read-only: the company day, existing UAT29C accounts and
 *             records, roles
 *   seed      idempotent: 4 accounts (staff, manager, admin, no-profile),
 *             4 staff records (3 linked, 1 without an account), 29 projects
 *             (28 with Mia as a member, 1 hidden from her), memberships,
 *             milestones, 2 tasks, 3 clients and 3 contacts. A generated
 *             password is written to STDOUT, as an "email password" line,
 *             ONLY for an account created by this run — redirect STDOUT to a
 *             new 0600 file. Re-running restores the plan; passwords never
 *             change.
 *   verify    read-only: runs the real project, member, milestone, client
 *             and contact controllers as the UAT29C accounts and prints what
 *             the app will show
 *   revoke    UAT-29C-07 only: deletes uat29c.staff's API tokens
 *   exposure  read-only: per UAT29C account, API tokens, web sessions and
 *             login/logout audit events
 *   rotate    new password for EXACTLY the four UAT29C accounts, all-or-
 *             nothing; revokes their API tokens and web sessions. STDOUT
 *             carries only "email password" lines.
 *
 * Every status line, header and error goes to STDERR, so STDOUT of `seed`
 * and `rotate` is only ever credentials. Only "UAT29C"-prefixed records and
 * the uat29c.* accounts are created or changed. No truncation, no
 * migrations, no other business data edited.
 */

use App\Enums\AccountStatus;
use App\Enums\ClientStatus;
use App\Enums\ContactStatus;
use App\Enums\ProjectMembershipRole;
use App\Enums\ProjectMilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\StaffStatus;
use App\Enums\TaskStatus;
use App\Http\Controllers\Api\V1\Clients\ClientController;
use App\Http\Controllers\Api\V1\Clients\ContactController;
use App\Http\Controllers\Api\V1\Projects\ProjectController;
use App\Http\Controllers\Api\V1\Projects\ProjectMembershipController;
use App\Http\Controllers\Api\V1\Projects\ProjectMilestoneController;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ProjectMilestone;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use App\Support\CompanyTimezone;
use App\Support\Reporting\OverdueTasks;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Any failure: exit 1 with a short message on STDERR only — never on STDOUT
// (which may be a credentials file), never with SQL bindings (password hashes).
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, 'ERROR '.$e::class.': '.preg_replace('/ \(Connection: .*$/s', '', $e->getMessage()).PHP_EOL);
    exit(1);
});

$stage = $argv[1] ?? 'plan';
$err = fn (string $line) => fwrite(STDERR, $line.PHP_EOL);

// key => [email, user name, role, employee_number of the linked staff record or null]
$accounts = [
    'staff' => ['uat29c.staff@company-app.test', 'UAT29C Staff', Role::STAFF, 'UAT29C-001'],
    'manager' => ['uat29c.manager@company-app.test', 'UAT29C Manager', Role::MANAGER, 'UAT29C-002'],
    'admin' => ['uat29c.admin@company-app.test', 'UAT29C Admin', Role::ADMINISTRATOR, 'UAT29C-003'],
    'noprofile' => ['uat29c.noprofile@company-app.test', 'UAT29C No-Profile', Role::STAFF, null],
];

// employee_number => [first name, last name]
$staffRecords = [
    'UAT29C-001' => ['Mia', 'UAT29C-Santos'],
    'UAT29C-002' => ['Marco', 'UAT29C-Lim'],
    'UAT29C-003' => ['Ada', 'UAT29C-Admin'],
    'UAT29C-010' => ['Noel', 'UAT29C-Reyes'],   // no account: a plain team member
];

// client_code => [name, status, email, phone, website, address_line1, city, state, postal, country]
$clients = [
    'UAT29C-C1' => ['UAT29C Acme Facilities', ClientStatus::Active, 'office@uat29c-acme.test', '+63 2 8000 2901', 'https://uat29c-acme.test', '12 Harbor Road', 'Makati', 'Metro Manila', '1200', 'Philippines'],
    'UAT29C-C2' => ['UAT29C Dormant Holdings', ClientStatus::Inactive, null, '+63 2 8000 2902', null, null, null, null, null, null],
    'UAT29C-C3' => ['UAT29C Beta Works', ClientStatus::Active, 'hello@uat29c-beta.test', null, null, null, 'Cebu City', null, null, 'Philippines'],
];

// [client_code, first, last, job title, email, phone, primary?, status]
$contacts = [
    ['UAT29C-C1', 'Maria', 'UAT29C-Cruz', 'Facilities Manager', 'maria@uat29c-acme.test', '+63 917 290 0001', true, ContactStatus::Active],
    ['UAT29C-C1', 'Leo', 'UAT29C-Tan', null, 'leo@uat29c-acme.test', null, false, ContactStatus::Active],
    ['UAT29C-C1', 'Olga', 'UAT29C-Former', 'Former contact', 'olga@uat29c-acme.test', null, false, ContactStatus::Inactive],
];

// project_code => [name, status, client_code, description, members: employee_number => role]
$L = ProjectMembershipRole::ProjectLead;
$M = ProjectMembershipRole::Member;
$projects = [
    'UAT29C-P01' => ['UAT29C Boiler Upgrade', ProjectStatus::Active, 'UAT29C-C1', 'Replace the plant-room boiler and controls.',
        ['UAT29C-001' => $L, 'UAT29C-010' => $M, 'UAT29C-002' => $M]],
    'UAT29C-P02' => ['UAT29C Roof Repair', ProjectStatus::OnHold, 'UAT29C-C2', null,
        ['UAT29C-001' => $M, 'UAT29C-003' => $M]],
    'UAT29C-P03' => ['UAT29C Archive Move', ProjectStatus::Completed, null, 'Move the archive to the new store.',
        ['UAT29C-001' => $M]],
    'UAT29C-P04' => ['UAT29C Long Programme', ProjectStatus::Planned, 'UAT29C-C3', 'A long programme with many milestones.',
        ['UAT29C-001' => $M]],
    'UAT29C-P05' => ['UAT29C Hidden Site', ProjectStatus::Active, 'UAT29C-C1', 'Mia is not a member (UAT-29C-03).',
        ['UAT29C-010' => $L]],
];
for ($i = 1; $i <= 24; $i++) {
    $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $projects["UAT29C-R{$n}"] = ["UAT29C Routine Site {$n}", ProjectStatus::Active, null, null, ['UAT29C-001' => $M]];
}

// project_code => list of [title, days from company today, status]
$milestones = [
    'UAT29C-P01' => [
        ['UAT29C Design sign-off', -5, ProjectMilestoneStatus::Completed],
        ['UAT29C Boiler delivery', 10, ProjectMilestoneStatus::Pending],
        ['UAT29C Old supplier visit', 20, ProjectMilestoneStatus::Cancelled],
    ],
    'UAT29C-P04' => array_map(
        fn ($i) => ['UAT29C Stage '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 7 * $i, ProjectMilestoneStatus::Pending],
        range(1, 52),
    ),
];

// title => [project_code, status]
$tasks = [
    '[UAT29C] Inspect the boiler' => ['UAT29C-P01', TaskStatus::InProgress],
    '[UAT29C] Check the hidden site' => ['UAT29C-P05', TaskStatus::Todo],
];

$today = OverdueTasks::todayInCompanyTimezone();
$err("stage={$stage} utc_now=".now('UTC')->toIso8601String()." company_day={$today} (".CompanyTimezone::value().')');

$uat29cOnly = function (User $user): void {
    if (! preg_match('/^uat29c\.[a-z.]+@company-app\.test$/', $user->email)) {
        fwrite(STDERR, "refusing non-UAT29C account\n");
        exit(1);
    }
};

$users = fn () => collect($accounts)->map(fn ($a) => User::query()->where('email', $a[0])->first());
$staffId = fn (string $number) => Staff::query()->where('employee_number', $number)->value('id');
$projectBy = fn (string $code) => Project::query()->where('project_code', $code)->first();

/** Calls a real controller as $user and returns [status, decoded body]. */
$call = function (User $user, string $uri, array $query, callable $invoke): array {
    $request = Request::create($uri, 'GET', $query);
    // Bind first: the paginator reads ?page= from the bound request, and
    // binding it re-installs the auth guard's user resolver.
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $user);

    try {
        $result = $invoke($request);
    } catch (HttpException $e) {
        return [$e->getStatusCode(), ['message' => $e->getMessage()]];
    }
    $response = $result instanceof Responsable ? $result->toResponse($request) : $result;

    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
};

if ($stage === 'plan') {
    foreach ($users() as $key => $user) {
        $err(str_pad($key, 10).($user ? "exists (status={$user->status->value}, staff=".($user->staff ? 'yes' : 'no').')' : 'absent'));
    }
    $err('UAT29C staff records: '.Staff::query()->where('employee_number', 'like', 'UAT29C-%')->count().' of '.count($staffRecords));
    $err('UAT29C projects: '.Project::query()->where('project_code', 'like', 'UAT29C-%')->count().' of '.count($projects));
    $err('UAT29C clients: '.Client::query()->where('client_code', 'like', 'UAT29C-%')->count().' of '.count($clients));
    $err('UAT29C tasks: '.Task::query()->where('title', 'like', '[UAT29C]%')->count().' of '.count($tasks));
    $err('roles present: '.Role::query()->whereIn('name', [Role::ADMINISTRATOR, Role::MANAGER, Role::STAFF])->count().'/3');
    exit(0);
}

if ($stage === 'seed') {
    $clash = Staff::query()->where('employee_number', 'like', 'UAT29C-%')->whereNotNull('user_id')
        ->whereHas('user', fn ($q) => $q->where('email', 'not like', 'uat29c.%'))->exists();
    if ($clash) {
        $err('seed: a UAT29C staff record is linked to a non-UAT29C account; nothing changed');
        exit(1);
    }

    $passwords = [];
    DB::transaction(function () use ($accounts, $staffRecords, $clients, $contacts, $projects, $milestones, $tasks, $today, $staffId, $projectBy, &$passwords, $err) {
        foreach ($staffRecords as $number => [$first, $last]) {
            $staff = Staff::query()->firstOrNew(['employee_number' => $number]);
            $staff->fill(['first_name' => $first, 'last_name' => $last, 'status' => StaffStatus::Active]);
            $staff->save();
        }

        $staffUserId = null;
        foreach ($accounts as $key => [$email, $name, $roleName, $number]) {
            $user = User::query()->firstOrNew(['email' => $email]);
            if (! $user->exists) {
                $passwords[$email] = Str::password(20, symbols: false);
                $user->password = Hash::make($passwords[$email]);
            }
            $user->name = $name;
            $user->role_id = Role::query()->where('name', $roleName)->value('id');
            $user->status = AccountStatus::Active;
            $user->save();

            if ($number !== null) {
                Staff::query()->where('employee_number', $number)->update(['user_id' => $user->id]);
            }
            if ($key === 'staff') {
                $staffUserId = $user->id;
            }
            $err("seed: {$key} ".(isset($passwords[$email]) ? 'created' : 'already existed (password unchanged)'));
        }

        foreach ($clients as $code => [$name, $status, $email, $phone, $website, $line1, $city, $state, $postal, $country]) {
            $client = Client::query()->firstOrNew(['client_code' => $code]);
            $client->fill([
                'name' => $name, 'status' => $status, 'email' => $email, 'phone' => $phone, 'website' => $website,
                'address_line1' => $line1, 'address_line2' => null, 'city' => $city, 'state_province' => $state,
                'postal_code' => $postal, 'country' => $country, 'notes' => 'UAT29C internal note (never shown in the app)',
            ]);
            $client->save();
        }

        foreach ($contacts as [$code, $first, $last, $title, $email, $phone, $primary, $status]) {
            $clientId = Client::query()->where('client_code', $code)->value('id');
            $contact = Contact::query()->firstOrNew(['client_id' => $clientId, 'email' => $email]);
            $contact->fill([
                'first_name' => $first, 'last_name' => $last, 'job_title' => $title, 'phone' => $phone,
                'is_primary' => $primary, 'status' => $status, 'notes' => 'UAT29C internal note',
            ]);
            $contact->save();
        }

        $base = Carbon::parse($today);
        foreach ($projects as $code => [$name, $status, $clientCode, $description, $members]) {
            $project = Project::query()->firstOrNew(['project_code' => $code]);
            $project->fill([
                'name' => $name,
                'status' => $status,
                'description' => $description,
                'client_id' => $clientCode === null ? null : Client::query()->where('client_code', $clientCode)->value('id'),
                'start_date' => $base->copy()->subDays(60)->toDateString(),
                'target_end_date' => $base->copy()->addDays(90)->toDateString(),
                'completed_date' => $status === ProjectStatus::Completed ? $base->copy()->subDays(30)->toDateString() : null,
                'notes' => 'UAT29C internal note (never shown in the app)',
            ]);
            $project->save();

            // Exactly the planned memberships (a re-run undoes any change).
            $wanted = collect($members)->mapWithKeys(fn ($role, $number) => [$staffId($number) => $role]);
            ProjectMembership::query()->where('project_id', $project->id)
                ->whereIn('staff_id', Staff::query()->where('employee_number', 'like', 'UAT29C-%')->pluck('id'))
                ->whereNotIn('staff_id', $wanted->keys())->delete();
            foreach ($wanted as $sid => $role) {
                ProjectMembership::query()->updateOrCreate(
                    ['project_id' => $project->id, 'staff_id' => $sid],
                    ['role' => $role],
                );
            }
        }

        foreach ($milestones as $code => $list) {
            $project = $projectBy($code);
            foreach ($list as [$title, $days, $status]) {
                $milestone = ProjectMilestone::query()->firstOrNew(['project_id' => $project->id, 'title' => $title]);
                $milestone->fill(['due_date' => $base->copy()->addDays($days)->toDateString(), 'status' => $status]);
                $milestone->save();
            }
        }

        foreach ($tasks as $title => [$code, $status]) {
            $task = Task::query()->firstOrNew(['title' => $title]);
            $task->fill([
                'project_id' => $projectBy($code)->id,
                'status' => $status,
                'assignee_staff_id' => $staffId('UAT29C-001'),
                'created_by_user_id' => $staffUserId,
                'due_date' => $base->copy()->addDays(7)->toDateString(),
                'completed_at' => null,
            ]);
            $task->save();
        }
        $err('seed: '.count($projects).' projects, '.count($clients).' clients, '.count($contacts).' contacts, '.count($tasks)." tasks set for company day {$today}");
    });

    foreach ($passwords as $email => $password) {
        echo "{$email} {$password}".PHP_EOL;
    }
    $err('seed: done; '.count($passwords).' new account(s)');
    exit(0);
}

if ($stage === 'verify') {
    $all = $users();
    if ($all->contains(null)) {
        echo 'accounts missing: run seed first'.PHP_EOL;
        exit(1);
    }
    $myProjects = function (User $user) use ($call): array {
        $member = $user->staff->public_id;
        $names = [];
        $page = 1;
        do {
            [, $body] = $call($user, '/api/v1/projects', ['member' => $member, 'per_page' => 25, 'page' => $page],
                fn ($r) => app(ProjectController::class)->index($r));
            foreach ($body['data'] as $p) {
                $names[] = $p['name'].' ['.$p['status'].($p['my_role'] === 'project_lead' ? ', lead' : '').']';
            }
        } while ($page++ < $body['meta']['last_page']);

        return [$names, $body['meta']['last_page']];
    };

    // Staff: the Projects list, as the app pages it.
    $staffUser = $all['staff'];
    [$names, $pages] = $myProjects($staffUser);
    $uat = array_values(array_filter($names, fn ($n) => str_starts_with($n, 'UAT29C')));
    echo 'staff projects: '.count($names).' over '.$pages.' page(s); UAT29C '.count($uat).PHP_EOL;
    foreach (array_slice($uat, 0, 5) as $n) {
        echo "   {$n}".PHP_EOL;
    }
    echo '   … '.(count($uat) - 5).' more'.PHP_EOL;
    [, $search] = $call($staffUser, '/api/v1/projects', ['member' => $staffUser->staff->public_id, 'q' => 'boiler', 'per_page' => 25],
        fn ($r) => app(ProjectController::class)->index($r));
    echo 'search "boiler": '.implode(' | ', array_column($search['data'], 'name')).PHP_EOL;

    // Staff: project detail parts.
    foreach (['UAT29C-P01', 'UAT29C-P04'] as $code) {
        $project = $projectBy($code);
        [, $p] = $call($staffUser, '/api/v1/projects/x', [], fn ($r) => app(ProjectController::class)->show($r, $project));
        [, $m] = $call($staffUser, '/api/v1/projects/x/members', ['per_page' => 50], fn ($r) => app(ProjectMembershipController::class)->index($r, $project));
        [, $ms] = $call($staffUser, '/api/v1/projects/x/milestones', ['per_page' => 50], fn ($r) => app(ProjectMilestoneController::class)->index($r, $project));
        echo "{$code} {$p['data']['name']}: client=".($p['data']['client']['name'] ?? '-').' my_role='.($p['data']['my_role'] ?? '-')
            .' members '.count($m['data']).'/'.$m['meta']['total'].' ('.implode(', ', array_map(fn ($x) => $x['staff']['display_name'].':'.$x['role'], $m['data'])).')'
            .' milestones '.count($ms['data']).'/'.$ms['meta']['total'].PHP_EOL;
    }
    $hidden = $projectBy('UAT29C-P05');
    [$status, $body] = $call($staffUser, '/api/v1/projects/x', [], fn ($r) => app(ProjectController::class)->show($r, $hidden));
    echo 'UAT29C-P05 (not a member): '.($status === 403 ? "403 {$body['message']}" : "{$status} — UNEXPECTED: Mia can see it; re-run seed").PHP_EOL;
    $task = Task::query()->where('title', '[UAT29C] Check the hidden site')->first();
    echo "task '{$task->title}': assignee=Mia project=".$task->project->name.PHP_EOL;

    // Clients, as the app lists them (search "UAT29C" to isolate the UAT ones).
    [, $c] = $call($staffUser, '/api/v1/clients', ['status' => 'active', 'q' => 'UAT29C', 'per_page' => 25], fn ($r) => app(ClientController::class)->index($r));
    echo 'clients (active, "UAT29C"): '.implode(' | ', array_column($c['data'], 'name')).PHP_EOL;
    $acme = Client::query()->where('client_code', 'UAT29C-C1')->first();
    [, $k] = $call($staffUser, '/api/v1/contacts', ['client' => $acme->public_id, 'status' => 'active', 'per_page' => 50], fn ($r) => app(ContactController::class)->index($r));
    echo 'UAT29C-C1 active contacts: '.implode(' | ', array_map(fn ($x) => $x['full_name'].($x['is_primary'] ? ' (primary)' : ''), $k['data'])).PHP_EOL;
    $dormant = Client::query()->where('client_code', 'UAT29C-C2')->first();
    echo "UAT29C-C2 {$dormant->name}: status={$dormant->status->value} (opens from UAT29C Roof Repair)".PHP_EOL;

    // Manager and admin: member projects only (R-20), versus all.
    foreach (['manager', 'admin'] as $key) {
        $user = $all[$key];
        [$names] = $myProjects($user);
        [, $everything] = $call($user, '/api/v1/projects', ['q' => 'UAT29C', 'per_page' => 50], fn ($r) => app(ProjectController::class)->index($r));
        echo "{$key} member projects: ".implode(' | ', $names).' (without member= the API would list '.$everything['meta']['total'].' UAT29C projects)'.PHP_EOL;
    }

    echo 'noprofile staff record: '.($all['noprofile']->staff ? 'LINKED (unexpected)' : 'none').PHP_EOL;
    exit(0);
}

if ($stage === 'revoke') {
    $user = $users()['staff'];
    if ($user === null) {
        $err('revoke: uat29c.staff absent');
        exit(1);
    }
    $uat29cOnly($user);
    $err('revoke: uat29c.staff api_tokens_revoked='.$user->tokens()->delete());
    exit(0);
}

if ($stage === 'exposure') {
    foreach ($users() as $key => $user) {
        if ($user === null) {
            echo "{$key}: absent".PHP_EOL;

            continue;
        }
        $uat29cOnly($user);
        $tokens = $user->tokens()->get(['created_at', 'last_used_at']);
        $audit = DB::table('audit_logs')->where('actor_user_id', $user->id)
            ->whereIn('action', ['auth.login_succeeded', 'auth.logout'])
            ->selectRaw('action, count(*) as n, max(created_at) as last')->groupBy('action')->get()
            ->map(fn ($r) => "{$r->action}={$r->n} (last {$r->last})")->implode(', ');
        echo str_pad($key, 10)."api_tokens={$tokens->count()} last_used=".($tokens->max('last_used_at') ?? '-')
            .' web_sessions='.DB::table('sessions')->where('user_id', $user->id)->count()
            .' audit: '.($audit ?: 'none').PHP_EOL;
    }
    exit(0);
}

if ($stage === 'rotate') {
    $all = $users();
    if ($all->contains(null) || $all->count() !== 4) {
        $err('rotate: expected all four UAT29C accounts to exist; nothing changed');
        exit(1);
    }
    $all->each($uat29cOnly);
    $passwords = [];
    DB::transaction(function () use ($all, &$passwords, $err) {
        foreach ($all as $user) {
            $passwords[$user->email] = Str::password(20, symbols: false);
            $user->password = Hash::make($passwords[$user->email]);
            $user->setRememberToken(Str::random(60));
            $user->save();
            $revoked = $user->tokens()->delete();
            $sessions = DB::table('sessions')->where('user_id', $user->id)->delete();
            $err("rotated {$user->email}: api_tokens_revoked={$revoked} web_sessions_removed={$sessions}");
        }
    });
    foreach ($passwords as $email => $password) {
        echo "{$email} {$password}".PHP_EOL;
    }
    $err('rotate: done ('.count($passwords).' accounts)');
    exit(0);
}

$err("unknown stage: {$stage}");
exit(1);
```
