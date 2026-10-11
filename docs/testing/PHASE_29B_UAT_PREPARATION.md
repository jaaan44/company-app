# Phase 29B — UAT Preparation (Deployment, APK + Staging Data)

*Operator runbook. No AI session has run anything in this file against the staging VPS or on the Windows build machine: this AI session cannot reach the VPS or build an APK. Every step marked **[Operator]** is pending until its output is recorded. The data script in §7 was rehearsed on a disposable scratch database only (see `docs/testing/TEST_STATUS.md`, "Phase 29B — UAT preparation").*

> **Final status (2026-10-10):** the operator ran §2 (staging redeploy to `7e29ffe`), §3 and §5; the product owner reported UAT-29B-01…08 **PASS**. **Phase 29B is formally closed.** Record: `docs/testing/TEST_STATUS.md`, "Phase 29B — staging deployment, UAT data and physical-device UAT". Correction learned in use: the §2 backup listing must be `ls -lt /home/deploy/backups/company-app-*.sql | head -3` (the `| tail -2` form sorts by name and can miss the new dump). The sections below are kept as written. The UAT29B data stays on staging; any cleanup needs its own authorization.

**Status (as written):** UAT-29B-01…08 are `NOT RUN`. This runbook prepares the deployment, the APK and the data; it runs no scenario.

**How each operator step is written.** Every step says:
- **Where:** the machine and directory.
- **Run:** the exact commands, to copy as they are.
- **What it does.**
- **Expected:** what a good result looks like, and when to **STOP**.
- **Paste back:** what to send to the AI session. **Never paste** anything from a credentials file, a password, or a password manager.

**Lessons carried over from Phases 27–29A:**
- **No password ever leaves the VPS terminal.**
  - Passwords go to a `0600` file, then into a password manager, and the file is shredded.
  - Never paste one into any chat, AI tool, ticket or document.
  - `seed` and `rotate` write passwords **only** to STDOUT, as `email password` lines. Every other line goes to STDERR, so the redirected file holds nothing else.
- **Run the stages in order** and stop at the first unexpected output.
- **Extract the script from merged `origin/main`** and check its SHA-256 before running it.
- **After recreating containers, check that MySQL is reachable** before anything else (Phase 28): `migrate:status` failing with a DNS error for `mysql` means the MySQL container is on the wrong network. Recreate it from `docker-compose.staging.yml` (`$C up -d mysql`, then `$C restart app`) — never `down -v`.
- **Backup listing (29A correction):** use `ls -l /home/deploy/backups/company-app-*.sql | tail -2`, not `ls -l /home/deploy/backups/ | tail -1`, which can show another directory.
- **Always run the APK build (29A correction)** and show its output, even if an earlier build exists: a stale `build\` output is otherwise indistinguishable.

**The data is dated.** Work dates are set relative to the **company day** (`Asia/Manila`) on which `seed` runs: two logs "today", one "yesterday", one three days ago, and older ones. If UAT happens on a later day, re-run `seed` that morning (§5 step 5) so "Today" and "Yesterday" mean what the scenarios expect.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **Phase 29B implementation and UAT source baseline** | `7e29ffe809c1282c4c9f7e177087cee02fe8410d` | PR #65 merge into `main`. The source for both the staging deployment **and** the final UAT APK. |
| Running staging implementation (before §2) | `3632ce1e821ccaca205f19abf5196e3df54fd35b` | Phase 29A (PR #61). It still has the UTC "today" rule for work dates (R-8) and no `meta.company_day` on `/me/work-logs`, so staging **must** be redeployed before UAT-29B. |
| Changes between them | — | `apps/api`: work-log Form Requests now share `LimitsWorkDateToCompanyToday` (company-timezone "today", one message); `GET /me/work-logs` gains an `id` tie-breaker and `meta.company_day`. The rest is Phase 29B mobile code and docs (PRs #62–#65). **No migration** (still 45 Ran), **no new route**, no environment-variable change, `composer.lock` unchanged. |

## 2. Staging deployment — **[Operator, VPS]**

**Where:** the VPS, as the `deploy` user, in the staging checkout.

**What it does:** moves the checkout from `3632ce1` to `7e29ffe` (fast-forward only), takes a MySQL backup, rebuilds and restarts only `app` and `nginx` (MySQL and its data are not touched), confirms no migration is pending, and rebuilds the Laravel caches. This follows `docs/DEPLOYMENT_STAGING.md` §9.

**Run** (one block at a time):

```sh
cd /home/deploy/company-app/company-app-build/company-app
git status --porcelain                  # expect: only "?? uat27_data.php" (known stray file) or nothing
git fetch origin
git merge --ff-only 7e29ffe809c1282c4c9f7e177087cee02fe8410d
git rev-parse HEAD                      # must print 7e29ffe809c1282c4c9f7e177087cee02fe8410d
```

```sh
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
$C exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  > /home/deploy/backups/company-app-$(date +%Y%m%d-%H%M%S).sql
ls -l /home/deploy/backups/company-app-*.sql | tail -2     # the newest dump: name and size
$C build app nginx
$C up -d --no-deps app nginx            # mysql untouched
$C exec app php artisan migrate:status  # expect all 45 Ran, none pending (Phase 29B adds none)
$C exec app php artisan config:cache
$C exec app php artisan route:cache
$C exec app php artisan view:cache
$C exec app php artisan config:show scheduling.company_timezone   # Asia/Manila
ss -ltnp | grep 8012                    # only 127.0.0.1:8012
$C ps                                   # mysql "healthy", app and nginx "Up"
```

**Expected:**
- `git merge` says `Fast-forward`; `git rev-parse HEAD` prints the full `7e29ffe…` SHA. **STOP** if the merge is refused (the checkout has local changes or is not on `3632ce1`'s line).
- The newest dump is today's and is several MB (similar to the 29A dump); a size of `0` means the dump failed — **STOP**.
- `migrate:status`: 45 rows, all `Ran`. A DNS error for `mysql`: see the MySQL lesson above.
- The timezone line ends in `Asia/Manila`.

**Smoke tests** (same VPS, any directory):

```sh
B=https://company-staging.storm-ark.com
curl -s -o /dev/null -w 'up %{http_code}\n'       $B/up                                                 # 200
curl -s -o /dev/null -w 'login %{http_code}\n'    $B/login                                              # 200
curl -s -o /dev/null -w 'home %{http_code}\n'     -H 'Accept: application/json' $B/api/v1/me/home       # 401
curl -s -o /dev/null -w 'mytasks %{http_code}\n'  -H 'Accept: application/json' $B/api/v1/me/tasks      # 401
curl -s -o /dev/null -w 'worklogs %{http_code}\n' -H 'Accept: application/json' $B/api/v1/me/work-logs  # 401, not 404
curl -s -o /dev/null -w 'projects %{http_code}\n' -H 'Accept: application/json' $B/api/v1/projects      # 401
```

**Expected:** `up 200`, `login 200`, then `401` for the other four. A `404` or `500` means the deployment is wrong — **STOP**.

**Paste back:** the `git rev-parse HEAD` line; the `ls -l …| tail -2` lines (file name and size only); the `migrate:status` summary (count of Ran / pending); the timezone line; the six smoke-test lines; the `ss` line; the `$C ps` table. **Never paste:** `.env.staging` contents or anything containing `MYSQL_ROOT_PASSWORD`.

## 3. Final UAT APK — **[Operator, Windows build machine]**

**Where:** PowerShell, in the repository root on the Windows build machine.

**What it does:** checks out exactly `7e29ffe`, runs the same Flutter checks CI runs (with per-feature counts), **builds** the release APK against staging, and prints its size, SHA-256, manifest facts and signer.

**Run:**

```powershell
git fetch origin
git checkout --detach 7e29ffe809c1282c4c9f7e177087cee02fe8410d
git rev-parse HEAD                 # must print 7e29ffe809c1282c4c9f7e177087cee02fe8410d
git status --porcelain             # must print nothing
cd apps/mobile

flutter --version
dart --version
java -version
flutter doctor -v                  # record the Java/Gradle JDK and Android SDK/build-tools lines

flutter pub get
dart format --output=none --set-exit-if-changed .
flutter analyze
flutter test                          # expect 421/421 ("All tests passed!", +421)
flutter test test/features/work_logs  # expect 91/91
flutter test test/features/tasks      # expect 95/95
flutter test test/features/home       # expect 66/66
flutter test test/core/network        # expect 29/29

# ALWAYS run this step, even if an APK already exists.
flutter build apk --release --dart-define=API_BASE_URL=https://company-staging.storm-ark.com/api/v1

git -C ../.. status --porcelain     # still nothing
$apk = "build\app\outputs\flutter-apk\app-release.apk"
Get-Item $apk | Select-Object FullName, Length, LastWriteTime
Get-FileHash -Algorithm SHA256 $apk
$bt = "$env:LOCALAPPDATA\Android\Sdk\build-tools\<ver>"     # the build-tools version from flutter doctor
& "$bt\aapt2.exe" dump badging $apk | Select-String "package:|compileSdkVersion|sdkVersion|targetSdkVersion|uses-permission"
& "$bt\apksigner.bat" verify --print-certs $apk
```

**Expected:**
- `git rev-parse HEAD` prints the full `7e29ffe…` SHA and `git status --porcelain` prints nothing (before and after the build). **STOP** otherwise.
- `dart format` changes nothing; `flutter analyze` says `No issues found!`.
- The test counts above, exactly. A different count means a different checkout — **STOP**.
- `flutter build apk` ends with `√ Built build\app\outputs\flutter-apk\app-release.apk` and its size; `LastWriteTime` is **now**.
- `package: name='com.companyapp.mobile'`, `uses-permission: name='android.permission.INTERNET'` present; a debug signer (known, deferred to Phase 38).

**Paste back:** all of the output above (it contains no secrets), including the build line. This APK is the only authorized APK for Phase 29B UAT; it replaces the Phase 29A APK (`D6242D79…1460`) on the test device. Do not change signing, dependencies or source.

**Install** (same PowerShell, phone connected with USB debugging):

```powershell
adb devices                        # the phone's serial, state "device"
adb install -r $apk                # expect: "Performing Streamed Install" then "Success"
```

**Paste back:** both outputs.

## 4. UAT data plan

**Accounts:** two dedicated `UAT29B` accounts. No existing user or business record is modified; the UAT27, UAT28 and UAT29A data is left as it is.

| Key | Email | Role | Staff profile | Used by |
|---|---|---|---|---|
| staff | `uat29b.staff@company-app.test` | staff | `UAT29B-001`, Lena UAT29B-Cruz | 29B-01…08 |
| noprofile | `uat29b.noprofile@company-app.test` | staff | none | 29B-01 (no-profile state) |

**Projects** (Lena is a member of all three):

| Code | Name | Status | Purpose |
|---|---|---|---|
| `UAT29B-P1` | UAT29B Boiler Upgrade | active | Shown in the picker |
| `UAT29B-P2` | UAT29B Roof Repair | active | Shown in the picker; `unjoin` removes Lena for the UAT-29B-04 server error |
| `UAT29B-P3` | UAT29B Archive | completed | **Hidden** in the picker (closed project) |

**Tasks** (assigned to Lena, due in 7 days):

| Task | Status | Project | Purpose |
|---|---|---|---|
| `[UAT29B] Inspect boiler` | In progress | P1 | In the picker; "Log work" from its detail (UAT-29B-03) |
| `[UAT29B] Call the vendor` | To do | none | In the picker (independent task) |
| `[UAT29B] Finished job` | Completed | P1 | Not in the picker (closed); Done tab; **has** "Log work" |
| `[UAT29B] Cancelled job` | Cancelled | P1 | Not in the picker; Done tab; **no** "Log work" (UAT-29B-03) |

**Work logs** for Lena (30, all with descriptions starting `[UAT29B]`). `D` is the company day on which `seed` last ran:

| Date | Duration | For | Description |
|---|---|---|---|
| D | 30 min | UAT29B Boiler Upgrade | `[UAT29B] Planning meeting` |
| D | 1 h 30 min | [UAT29B] Inspect boiler | `[UAT29B] Checked boiler pressure` |
| D−1 | 45 min | [UAT29B] Call the vendor | `[UAT29B] Called the vendor about parts` |
| D−3 | 2 h | UAT29B Roof Repair | `[UAT29B] Measured the roof` |
| D−10 … D−35 | 16 … 41 min | UAT29B Boiler Upgrade | `[UAT29B] Routine entry 01` … `26` (one per day) |

**What the app should show** for `uat29b.staff` under More → My work logs:
- Groups headed **Today** (2 logs), **Yesterday** (1), then dated headers ("Wed 7 Oct" style).
- Newest first; within Today, Planning meeting above Checked boiler pressure (entered later, listed first).
- 30 logs over **2 pages** (25 + 5): scrolling to the end loads Routine entry 22…26, with no duplicates or gaps.
- The add form's picker: **My open tasks** = Inspect boiler, Call the vendor; **My projects** = UAT29B Boiler Upgrade, UAT29B Roof Repair (not Archive). Lena's UAT29A-era data does not exist, so nothing else should appear.

`uat29b.noprofile` sees "No staff profile is linked to this account." on My work logs.

## 5. Staging data preparation — **[Operator, VPS]**

All steps: **Where:** the VPS, in the staging checkout, after §2.

**Step 0. Get the script and check it.**

**What it does:** copies the PHP block in §7 of this file, as merged on `origin/main`, into your home directory. It does not change the deployed checkout. The SHA-256 proves the file is exactly the reviewed and rehearsed text.

```sh
cd /home/deploy/company-app/company-app-build/company-app
git fetch origin main
git show origin/main:docs/testing/PHASE_29B_UAT_PREPARATION.md | awk '/^```php$/{f=1;next} /^```$/{f=0} f' > "$HOME/uat29b_data.php"
sha256sum "$HOME/uat29b_data.php"
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
U="$HOME/uat29b_data.php"
```

**Expected:** the SHA printed equals the one in §7. **Any mismatch: STOP.** (`C` and `U` must be set again in every new shell.)

**Paste back:** the `sha256sum` line.

**Step 1. Read-only plan.**

**What it does:** prints the company day, whether the UAT29B accounts and records already exist, and whether the three roles exist. It changes nothing.

```sh
$C exec -T app php -- plan < "$U"
```

**Expected:**
```
stage=plan utc_now=<now> company_day=<today> (Asia/Manila)
staff     absent
noprofile absent
UAT29B staff record: absent
UAT29B projects: 0 of 3
UAT29B tasks: 0 of 4
UAT29B planned logs: 0 of 30
roles present: 3/3
```
**STOP** if a UAT29B account already exists that nobody can account for, or roles are not `3/3`.

**Paste back:** the whole output.

**Step 2. Seed.**

**What it does:** in one database transaction, creates the two accounts, Lena's staff record, the three projects and memberships, four tasks and 30 work logs (§4). It writes the two new passwords, and nothing else, into a new file that only you can read.

```sh
CRED="$HOME/uat29b-credentials.txt"
test ! -e "$CRED" && (umask 077; set -o noclobber; $C exec -T app php -- seed < "$U" > "$CRED"); echo "seed exit=$?"
stat -c '%a %U' "$CRED"      # must be: 600 <your user>
wc -l < "$CRED"              # must be: 2
```

**Expected** (on screen, from STDERR):
```
stage=seed utc_now=<now> company_day=<today> (Asia/Manila)
seed: staff created
seed: noprofile created
seed: 4 tasks and 30 planned logs set for company day <today>
seed: done; 2 new account(s)
seed exit=0
600 deploy
2
```
- **Non-zero exit:** nothing was changed (one transaction). **STOP** and paste the screen output — never the file.
- **Mode is not `600`:** run `shred -u "$CRED"` and **STOP**.
- **Otherwise:**
  1. Open the file privately: `less "$CRED"` (press `q` to leave).
  2. Copy both passwords into your password manager.
  3. Run `shred -u "$CRED"`, then `ls "$CRED"` — expect `No such file or directory`.

**Paste back:** the screen output above and the `ls` line. **Never paste** the file's contents.

**Step 3. Verify.**

**What it does:** read-only. Runs the real `GET /me/work-logs` controller as `uat29b.staff` (both pages), the real `GET /me/tasks?state=open` and `GET /projects?member=` controllers that feed the form's picker, and `GET /me/work-logs` as `uat29b.noprofile`. This is also the **MySQL check** for the new list order (only SQLite was used before).

```sh
$C exec -T app php -- verify < "$U"
```

**Expected:**
```
stage=verify utc_now=<now> company_day=<D> (Asia/Manila)
company_day: {"date":"<D>","timezone":"Asia\/Manila"}
== /me/work-logs page 1/2: 25 of 30
   <D>    30 min   UAT29B Boiler Upgrade — [UAT29B] Planning meeting
   <D>    90 min   [UAT29B] Inspect boiler — [UAT29B] Checked boiler pressure
   <D−1>  45 min   [UAT29B] Call the vendor — [UAT29B] Called the vendor about parts
   <D−3>  120 min  UAT29B Roof Repair — [UAT29B] Measured the roof
   <D−10> 16 min   UAT29B Boiler Upgrade — [UAT29B] Routine entry 01
   … one line per day, down to Routine entry 21 (<D−30>)
== /me/work-logs page 2/2: 5 of 30
   <D−31> … Routine entry 22  …  <D−35> … Routine entry 26
picker tasks: [UAT29B] Inspect boiler | [UAT29B] Call the vendor
picker projects: UAT29B Boiler Upgrade | UAT29B Roof Repair (hidden: UAT29B Archive)
noprofile /me/work-logs: 403 No staff record is linked to this account.
```
**STOP** if the order differs (Planning meeting must be above Checked boiler pressure), the counts differ, or a line is missing.

**Paste back:** the whole output (no secrets in it).

**Step 4. The "today" check (R-8 / UAT-29B-06).**

**What it does:** read-only. Inside this one PHP process only, sets the clock to **00:30 Manila on the next company day** — the window where the old rule rejected "today" — and runs the real work-log validation (`StoreMyWorkLogRequest`) as `uat29b.staff` for that company "today" and "tomorrow". Nothing is saved; the server's real clock is not touched.

```sh
$C exec -T app php -- todaycheck < "$U"; echo "todaycheck exit=$?"
```

**Expected** (with `<D+1>` the next company day):
```
clock: <D+1>T00:30:00+08:00 (Asia/Manila) = <D>T16:30:00+00:00 UTC; company today <D+1>, UTC date <D>
work_date <D+1> (company today): accepted
work_date <D+2> (company tomorrow): rejected: {"work_date":["The work date cannot be later than today."]}
R-8 check: OK
todaycheck exit=0
```
`R-8 check: PROBLEM` (exit 1) means the deployed code still has the UTC rule: **STOP** and check §2.

**Paste back:** the whole output.

**Step 5. On the UAT day, if it is not the seeding day.**

**What it does:** moves the planned logs to the new company day, restores any planned log, task or membership that a scenario changed, and creates no account (so it prints no password). Logs the tester typed in the app are left alone.

```sh
$C exec -T app php -- seed < "$U" > /dev/null; echo "seed exit=$?"
$C exec -T app php -- verify < "$U"
```

**Expected:** `seed: staff already existed (password unchanged)`, the same for `noprofile`, `seed: done; 0 new account(s)`, `seed exit=0`; then `verify` as in step 3 with the new dates, plus any logs the tester added. Never re-run it in the middle of a scenario.

**Paste back:** both outputs.

**Step 6. Exposure check.**

**What it does:** read-only. For each UAT29B account, counts API tokens (app sign-ins), web sessions, and login/logout audit events.

```sh
$C exec -T app php -- exposure < "$U"
```

**Expected before UAT:**
```
staff     api_tokens=0 last_used=- web_sessions=0 audit: none
noprofile api_tokens=0 last_used=- web_sessions=0 audit: none
```

**Paste back:** the output (no secrets in it).

**If a password is ever exposed:**
1. Run step 6 (`exposure`) first and paste its output.
2. Run `rotate`: exactly two new passwords, all or nothing, and the accounts' tokens and sessions are revoked.
   ```sh
   CRED="$HOME/uat29b-credentials.txt"
   test ! -e "$CRED" && (umask 077; set -o noclobber; $C exec -T app php -- rotate < "$U" > "$CRED"); echo "rotate exit=$?"
   ```
3. Follow the same `stat`, `wc` (2), password-manager and `shred` steps as for `seed`.
4. **A non-zero `rotate` exit changed nothing**, even if STDERR already printed a `rotated …` line: everything is rolled back, and the old passwords still work. Shred the (empty) file and paste the screen output.

## 6. UAT-time notes (for **execution**, not preparation)

- **Device:** the §3 APK replaces the Phase 29A APK. Sign in as `uat29b.staff` with the password from the password manager.
- **UAT-29B-01:** More → **My work logs**. Expect the §4 list: Today (2), Yesterday (1), dated groups; scroll to the end and page 2 loads Routine entry 22…26 with no duplicates. Then sign out, sign in as `uat29b.noprofile`, More → My work logs: "No staff profile is linked to this account." Sign back in as `uat29b.staff`.
- **UAT-29B-02:** on My work logs, tap **Add**. The date defaults to today ("Date worked"); "What was this for?" lists **My open tasks** (Inspect boiler, Call the vendor) and **My projects** (Boiler Upgrade, Roof Repair — **no** Archive, **no** Finished job or Cancelled job). Choose UAT29B Roof Repair, 1 h, any description, save: "Work logged." and it appears under Today.
- **UAT-29B-03:** Tasks → open **Inspect boiler** → **Log work**: the form shows the task as what it's for, fixed. Save; the log appears under Today. Then Tasks → Done → **Cancelled job**: there is **no** Log work button.
- **UAT-29B-04:**
  - **Form checks:** leave the description empty, set 0 minutes, and try to pick a date after today (the picker does not offer it): "Describe the work.", "Enter how long you worked.".
  - **Server error:** open the add form and choose **UAT29B Roof Repair**; leave the form open. On the VPS run `$C exec -T app php -- unjoin < "$U"` (expected: `unjoin: Lena removed from 'UAT29B Roof Repair' (rows=1); re-run seed to restore`). Save in the app: "You must be a member of this project to log work against it." appears at the top and everything typed is kept. Afterwards run step 5's `seed` to restore the membership.
- **UAT-29B-05:** open a log: "Edit work log", what it was for is read-only. Change the minutes, go back without saving: "Discard changes?" with **Keep editing** / **Discard**. Save a change: "Changes saved.". Delete one (use a log the tester added, or re-run `seed` afterwards): "Delete this work log?", then "Work log deleted.".
- **UAT-29B-06:** if UAT can be run between 00:00 and 07:59 Manila, add a log for the default date (today): it saves, and appears under **Today**. Otherwise, §5 step 4 (`todaycheck`) is the staging evidence for this scenario; record its output.
- **UAT-29B-07:**
  - **Offline:** fill in the add form, switch on airplane mode, save: "Couldn't save … Check your connection and try again." and the input is kept. Reconnect and save: it saves.
  - **Revoked:** on the VPS run `$C exec -T app php -- revoke < "$U"` (expected `revoke: uat29b.staff api_tokens_revoked=1` or more), then pull to refresh My work logs: it returns to Login with "Your session has ended. Please sign in again."
- **UAT-29B-08:** turn on system dark mode and the largest font; check My work logs, the add form (including the picker) and the edit form.
- **Afterwards:** run §5 step 6 (`exposure`) once more and paste it. The UAT29B data stays in place; any cleanup needs its own authorization.

## 7. Script — `uat29b_data.php`

**Revision 1 (2026-10-10), SHA-256 `327b56b761740ab8c7f771451250e17690c39933462d6240aadba8a7bb10ad0f`** (the exact text below, with a trailing newline).

```php
<?php

/*
 * Phase 29B UAT data — operator tool (NOT application code; never commit to
 * apps/api, never expose over HTTP).
 *
 * Run inside the staging `app` container, reading this file from stdin:
 *
 *   $C exec -T app php -- <stage> < uat29b_data.php
 *
 * Stages:
 *   plan       read-only: the company day, existing UAT29B accounts, staff,
 *              projects, tasks and logs, roles
 *   seed       idempotent: 2 accounts, 1 staff record, 3 projects (two with
 *              Lena as a member, one completed), 4 tasks and 30 UAT29B work
 *              logs dated relative to TODAY's company date. A generated
 *              password is written to STDOUT, as an "email password" line,
 *              ONLY for an account created by this run — redirect STDOUT to
 *              a new 0600 file. Re-running resets the UAT29B logs, tasks and
 *              memberships to the plan for the current company day (logs the
 *              tester typed are left alone); passwords never change.
 *   verify     read-only: runs the real GET /me/work-logs, GET /me/tasks and
 *              GET /projects?member= controllers as the UAT29B accounts and
 *              prints what the app will show
 *   todaycheck read-only (UAT-29B-06 / R-8): runs the real work-log
 *              validation as uat29b.staff with this process's clock set to
 *              00:30 Manila — today must pass, tomorrow must fail. Nothing
 *              is saved.
 *   unjoin     UAT-29B-04 only: removes Lena's membership of
 *              "UAT29B Roof Repair" (re-run `seed` to restore it)
 *   revoke     UAT-29B-07 only: deletes uat29b.staff's API tokens
 *   exposure   read-only: per UAT29B account, API tokens, web sessions and
 *              login/logout audit events
 *   rotate     new password for EXACTLY the two UAT29B accounts, all-or-
 *              nothing; revokes their API tokens and web sessions. STDOUT
 *              carries only "email password" lines.
 *
 * Every status line, header and error goes to STDERR, so STDOUT of `seed`
 * and `rotate` is only ever credentials. Only "UAT29B"-prefixed records and
 * the uat29b.* accounts are created or changed. No truncation, no
 * migrations, no other business data edited.
 */

use App\Enums\AccountStatus;
use App\Enums\ProjectMembershipRole;
use App\Enums\ProjectStatus;
use App\Enums\StaffStatus;
use App\Enums\TaskStatus;
use App\Http\Controllers\Api\V1\Projects\ProjectController;
use App\Http\Controllers\Api\V1\Tasks\MyTaskController;
use App\Http\Controllers\Api\V1\WorkLogs\MyWorkLogController;
use App\Http\Requests\WorkLogs\StoreMyWorkLogRequest;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use App\Support\CompanyTimezone;
use App\Support\Reporting\OverdueTasks;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
    'staff' => ['uat29b.staff@company-app.test', 'UAT29B Staff', Role::STAFF, 'UAT29B-001'],
    'noprofile' => ['uat29b.noprofile@company-app.test', 'UAT29B No-Profile', Role::STAFF, null],
];

// project_code => [name, status, Lena is a member?]
$projects = [
    'UAT29B-P1' => ['UAT29B Boiler Upgrade', ProjectStatus::Active, true],
    'UAT29B-P2' => ['UAT29B Roof Repair', ProjectStatus::Active, true],    // UAT-29B-04 `unjoin`
    'UAT29B-P3' => ['UAT29B Archive', ProjectStatus::Completed, true],     // hidden in the picker
];

// title => [status, project_code or null]
$tasks = [
    '[UAT29B] Inspect boiler' => [TaskStatus::InProgress, 'UAT29B-P1'],
    '[UAT29B] Call the vendor' => [TaskStatus::Todo, null],
    '[UAT29B] Finished job' => [TaskStatus::Completed, 'UAT29B-P1'],
    '[UAT29B] Cancelled job' => [TaskStatus::Cancelled, 'UAT29B-P1'],
];

// description (the key) => [days before the company today, minutes, task title or null, project_code or null]
$logs = [
    '[UAT29B] Checked boiler pressure' => [0, 90, '[UAT29B] Inspect boiler', null],
    '[UAT29B] Planning meeting' => [0, 30, null, 'UAT29B-P1'],
    '[UAT29B] Called the vendor about parts' => [1, 45, '[UAT29B] Call the vendor', null],
    '[UAT29B] Measured the roof' => [3, 120, null, 'UAT29B-P2'],
];
for ($i = 1; $i <= 26; $i++) {
    $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $logs["[UAT29B] Routine entry {$n}"] = [9 + $i, 15 + $i, null, 'UAT29B-P1'];
}

$today = OverdueTasks::todayInCompanyTimezone();
$err("stage={$stage} utc_now=".now('UTC')->toIso8601String()." company_day={$today} (".CompanyTimezone::value().')');

$uat29bOnly = function (User $user): void {
    if (! preg_match('/^uat29b\.[a-z.]+@company-app\.test$/', $user->email)) {
        fwrite(STDERR, "refusing non-UAT29B account\n");
        exit(1);
    }
};

$users = fn () => collect($accounts)->map(fn ($a) => User::query()->where('email', $a[0])->first());
$lena = fn () => Staff::query()->where('employee_number', 'UAT29B-001')->first();
$projectId = fn (string $code) => Project::query()->where('project_code', $code)->value('id');

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
    $err('UAT29B staff record: '.($lena() ? 'exists' : 'absent'));
    $err('UAT29B projects: '.Project::query()->where('project_code', 'like', 'UAT29B-%')->count().' of '.count($projects));
    $err('UAT29B tasks: '.Task::query()->where('title', 'like', '[UAT29B]%')->count().' of '.count($tasks));
    $err('UAT29B planned logs: '.WorkLog::query()->where('description', 'like', '[UAT29B]%')->count().' of '.count($logs));
    $err('roles present: '.Role::query()->whereIn('name', [Role::ADMINISTRATOR, Role::MANAGER, Role::STAFF])->count().'/3');
    exit(0);
}

if ($stage === 'seed') {
    $clash = Staff::query()->where('employee_number', 'like', 'UAT29B-%')->whereNotNull('user_id')
        ->whereHas('user', fn ($q) => $q->where('email', 'not like', 'uat29b.%'))->exists();
    if ($clash) {
        $err('seed: a UAT29B staff record is linked to a non-UAT29B account; nothing changed');
        exit(1);
    }

    $passwords = [];
    DB::transaction(function () use ($accounts, $projects, $tasks, $logs, $today, $lena, $projectId, &$passwords, $err) {
        $staff = Staff::query()->firstOrNew(['employee_number' => 'UAT29B-001']);
        $staff->fill(['first_name' => 'Lena', 'last_name' => 'UAT29B-Cruz', 'status' => StaffStatus::Active]);
        $staff->save();

        $staffUser = null;
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
                $staffUser = $user;
            }
            $err("seed: {$key} ".(isset($passwords[$email]) ? 'created' : 'already existed (password unchanged)'));
        }

        foreach ($projects as $code => [$name, $status, $member]) {
            $project = Project::query()->firstOrNew(['project_code' => $code]);
            $project->fill(['name' => $name, 'status' => $status, 'description' => 'Phase 29B UAT only']);
            $project->save();
            if ($member) {
                ProjectMembership::query()->firstOrCreate(
                    ['project_id' => $project->id, 'staff_id' => $staff->id],
                    ['role' => ProjectMembershipRole::Member],
                );
            }
        }

        foreach ($tasks as $title => [$status, $code]) {
            $task = Task::query()->firstOrNew(['title' => $title]);
            $task->fill([
                'status' => $status,
                'project_id' => $code === null ? null : $projectId($code),
                'assignee_staff_id' => $staff->id,
                'created_by_user_id' => $staffUser?->id,
                'due_date' => Carbon::parse($today)->addDays(7)->toDateString(),
                'completed_at' => $status === TaskStatus::Completed ? now()->subDay() : null,
            ]);
            $task->save();
        }

        $base = Carbon::parse($today);
        foreach ($logs as $description => [$daysAgo, $minutes, $taskTitle, $code]) {
            $taskId = $taskTitle === null ? null : Task::query()->where('title', $taskTitle)->value('id');
            $log = WorkLog::query()->firstOrNew(['staff_id' => $staff->id, 'description' => $description]);
            $log->fill([
                'task_id' => $taskId,
                // As the API does: a task log's project is the task's project.
                'project_id' => $taskId !== null
                    ? Task::query()->whereKey($taskId)->value('project_id')
                    : $projectId($code),
                'work_date' => $base->copy()->subDays($daysAgo)->toDateString(),
                'duration_minutes' => $minutes,
                'created_by_user_id' => $staffUser?->id,
            ]);
            $log->save();
        }
        $err('seed: '.count($tasks).' tasks and '.count($logs)." planned logs set for company day {$today}");
    });

    foreach ($passwords as $email => $password) {
        echo "{$email} {$password}".PHP_EOL;
    }
    $err('seed: done; '.count($passwords).' new account(s)');
    exit(0);
}

if ($stage === 'verify') {
    $all = $users();
    $staffUser = $all['staff'];
    if ($staffUser === null) {
        echo 'staff: absent'.PHP_EOL;
        exit(0);
    }

    // My work logs, exactly as the app pages them.
    $page = 1;
    do {
        [$status, $body] = $call($staffUser, '/api/v1/me/work-logs', ['per_page' => 25, 'page' => $page],
            fn ($r) => app(MyWorkLogController::class)->myIndex($r));
        if ($page === 1) {
            echo 'company_day: '.json_encode($body['meta']['company_day']).PHP_EOL;
        }
        echo "== /me/work-logs page {$page}/{$body['meta']['last_page']}: ".count($body['data'])." of {$body['meta']['total']}".PHP_EOL;
        foreach ($body['data'] as $l) {
            $what = $l['task']['title'] ?? $l['project']['name'];
            echo '   '.$l['work_date'].'  '.str_pad($l['duration_minutes'].' min', 9).$what.' — '.$l['description'].PHP_EOL;
        }
    } while ($page++ < $body['meta']['last_page']);

    // The picker: my open tasks, and my projects minus completed/cancelled.
    [, $tasksBody] = $call($staffUser, '/api/v1/me/tasks', ['state' => 'open', 'per_page' => 50],
        fn ($r) => app(MyTaskController::class)->index($r));
    echo 'picker tasks: '.implode(' | ', array_column($tasksBody['data']['tasks'], 'title')).PHP_EOL;
    [, $projectsBody] = $call($staffUser, '/api/v1/projects', ['member' => $lena()->public_id, 'per_page' => 50],
        fn ($r) => app(ProjectController::class)->index($r));
    $open = array_filter($projectsBody['data'], fn ($p) => ! in_array($p['status'], ['completed', 'cancelled'], true));
    echo 'picker projects: '.implode(' | ', array_column($open, 'name'))
        .' (hidden: '.implode(', ', array_column(array_diff_key($projectsBody['data'], $open), 'name')).')'.PHP_EOL;

    if ($all['noprofile'] !== null) {
        [$status, $body] = $call($all['noprofile'], '/api/v1/me/work-logs', ['per_page' => 25],
            fn ($r) => app(MyWorkLogController::class)->myIndex($r));
        echo "noprofile /me/work-logs: {$status} {$body['message']}".PHP_EOL;
    }
    exit(0);
}

if ($stage === 'todaycheck') {
    $staffUser = $users()['staff'];
    if ($staffUser === null) {
        $err('todaycheck: uat29b.staff absent; run seed first');
        exit(1);
    }

    // This process only: the clock at 00:30 on the next company morning —
    // the window where the old UTC rule rejected "today".
    $tz = CompanyTimezone::value();
    $clock = Carbon::parse(Carbon::now($tz)->toDateString().' 00:30:00', $tz)->addDay();
    Carbon::setTestNow($clock);
    $companyToday = OverdueTasks::todayInCompanyTimezone();
    $tomorrow = Carbon::parse($companyToday)->addDay()->toDateString();
    echo "clock: {$clock->toIso8601String()} ({$tz}) = ".$clock->copy()->utc()->toIso8601String().' UTC; company today '.$companyToday.', UTC date '.$clock->copy()->utc()->toDateString().PHP_EOL;

    $check = function (string $date) use ($staffUser, $projectId): string {
        $request = StoreMyWorkLogRequest::create('/api/v1/me/work-logs', 'POST', [
            'project_id' => Project::query()->whereKey($projectId('UAT29B-P1'))->value('public_id'),
            'work_date' => $date,
            'duration_minutes' => 30,
            'description' => 'todaycheck (not saved)',
        ]);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->setUserResolver(fn () => $staffUser);
        try {
            $request->validateResolved();

            return 'accepted';
        } catch (ValidationException $e) {
            return 'rejected: '.json_encode($e->errors());
        }
    };

    $todayResult = $check($companyToday);
    $tomorrowResult = $check($tomorrow);
    Carbon::setTestNow();
    echo "work_date {$companyToday} (company today): {$todayResult}".PHP_EOL;
    echo "work_date {$tomorrow} (company tomorrow): {$tomorrowResult}".PHP_EOL;
    $ok = $todayResult === 'accepted' && str_contains($tomorrowResult, 'The work date cannot be later than today.');
    echo 'R-8 check: '.($ok ? 'OK' : 'PROBLEM').PHP_EOL;
    exit($ok ? 0 : 1);
}

if ($stage === 'unjoin') {
    $staff = $lena();
    $id = $projectId('UAT29B-P2');
    if ($staff === null || $id === null) {
        $err('unjoin: run seed first');
        exit(1);
    }
    $removed = ProjectMembership::query()->where('project_id', $id)->where('staff_id', $staff->id)->delete();
    $err("unjoin: Lena removed from 'UAT29B Roof Repair' (rows={$removed}); re-run seed to restore");
    exit(0);
}

if ($stage === 'revoke') {
    $user = $users()['staff'];
    if ($user === null) {
        $err('revoke: uat29b.staff absent');
        exit(1);
    }
    $uat29bOnly($user);
    $err('revoke: uat29b.staff api_tokens_revoked='.$user->tokens()->delete());
    exit(0);
}

if ($stage === 'exposure') {
    foreach ($users() as $key => $user) {
        if ($user === null) {
            echo "{$key}: absent".PHP_EOL;

            continue;
        }
        $uat29bOnly($user);
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
    if ($all->contains(null) || $all->count() !== 2) {
        $err('rotate: expected both UAT29B accounts to exist; nothing changed');
        exit(1);
    }
    $all->each($uat29bOnly);
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
