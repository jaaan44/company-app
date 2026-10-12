# Phase 30A — UAT Preparation (Deployment, APK + Staging Data)

*Operator runbook. No AI session has run anything in this file against the staging VPS or on the Windows build machine: this AI session cannot reach the VPS or build an APK. Every step marked **[Operator]** is pending until its output is recorded. The data script in §7 was rehearsed on disposable scratch databases only (see `docs/testing/TEST_STATUS.md`, "Phase 30A — UAT preparation").*

> **Final status (2026-10-12):** the operator ran §2 (staging redeploy to `0e7fce6`), §3 (UAT APK, SHA-256 `7EAB7F9F…31B7`) and §5 (UAT30A data; `verify` all OK on MySQL); the product owner reported UAT-30A-01…08 **PASS**. **Phase 30A is formally closed.** Record: `docs/testing/TEST_STATUS.md`, "Phase 30A — staging deployment, UAT data and physical-device UAT". The sections below are kept as written; §7's script is unchanged. The UAT30A data stays on staging; any cleanup needs its own authorization.

**Status (as written):** UAT-30A-01…08 are `NOT RUN`. This runbook prepares the deployment, the APK and the data; it runs no scenario.

**How each operator step is written.** Every step says:
- **Where:** the machine and directory.
- **Run:** the exact commands, to copy as they are.
- **What it does.**
- **Expected:** what a good result looks like, and when to **STOP**.
- **Paste back:** what to send to the AI session. **Never paste** anything from a credentials file, a password, or a password manager.

**Lessons carried over from Phases 27–29C:**
- **No password ever leaves the VPS terminal, and none is ever typed or pasted into a shell.**
  - Passwords go to a `0600` file, then into a password manager, and the file is shredded.
  - Paste a password **only into the app's sign-in field**, never into a terminal, a chat, an AI tool, a ticket or a document.
  - `seed` and `rotate` write passwords **only** to STDOUT, as `email password` lines. Every other line goes to STDERR, so the redirected file holds nothing else.
- **Run the stages in order** and stop at the first unexpected output.
- **Extract the script from merged `origin/main`** and check its SHA-256 before running it.
- **After recreating containers, check that MySQL is reachable** (Phase 28). A DNS error for `mysql` in `migrate:status` means the MySQL container is on the wrong network: recreate it from `docker-compose.staging.yml` (`$C up -d mysql`, then `$C restart app`). Never run `down -v`.
- **Backup check (29C correction):** a new dump can be **smaller** than the last one (expired `sessions` rows are gone). Check that it ends with `-- Dump completed` and is not empty; never compare sizes.
- **Always run the APK build** and show its output, even if an earlier build exists.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **Phase 30A implementation and UAT source baseline** | `0e7fce6afd243ecb27cec3123214cd8fbb8e1a20` | PR #73 merge into `main`. The source for both the staging deployment **and** the final UAT APK. |
| Running staging implementation (before §2) | `c79ed90cac0263a7f7e452c0f7d0861d4adac380` | Phase 29C (PR #69). |
| Changes between them | — | `apps/api`: the new `MyScheduleController` and its route `GET /api/v1/me/schedule`; company-day boundaries in `ScheduleController` (`GET /schedule`); their tests. The rest is Phase 30A mobile code and docs (PRs #70–#73). **No migration** (still 45 Ran), no environment-variable change, `composer.lock` unchanged. The new route needs the route cache rebuilt (§2 does it). |

## 2. Staging deployment — **[Operator, VPS]**

**Where:** the VPS, as the `deploy` user, in the staging checkout.

**What it does:**
- Moves the checkout from `c79ed90` to `0e7fce6` (fast-forward only).
- Takes a MySQL backup.
- Rebuilds and restarts only `app` and `nginx`. MySQL and its data are not touched.
- Confirms no migration is pending and rebuilds the Laravel caches.

This follows `docs/DEPLOYMENT_STAGING.md` §9.

**Run** (one block at a time):

```sh
cd /home/deploy/company-app/company-app-build/company-app
git status --porcelain                  # expect: nothing
git fetch origin
git merge --ff-only 0e7fce6afd243ecb27cec3123214cd8fbb8e1a20
git rev-parse HEAD                      # must print 0e7fce6afd243ecb27cec3123214cd8fbb8e1a20
```

```sh
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
$C exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  > /home/deploy/backups/company-app-$(date +%Y%m%d-%H%M%S).sql
ls -lt /home/deploy/backups/company-app-*.sql | head -3     # newest first
tail -c 200 "$(ls -t /home/deploy/backups/company-app-*.sql | head -1)" | grep -- '-- Dump completed'
$C build app nginx
$C up -d --no-deps app nginx            # mysql untouched
$C exec app php artisan migrate:status  # expect all 45 Ran, none pending
$C exec app php artisan config:cache
$C exec app php artisan route:cache
$C exec app php artisan view:cache
$C exec app php artisan route:list --path=me/schedule              # one GET route
$C exec app php artisan config:show scheduling.company_timezone   # Asia/Manila
ss -ltnp | grep 8012                    # only 127.0.0.1:8012
$C ps                                   # mysql "healthy", app and nginx "Up"
```

**Expected:**
- **`git status --porcelain`:** prints nothing. If it lists a file, **STOP** and paste its name. If the name looks like a password, paste nothing and just say so.
- **`git merge`:** prints `Updating c79ed90..0e7fce6` and `Fast-forward`. `git rev-parse HEAD` prints the full `0e7fce6…` SHA.
- **`ls -lt`:** the first line is today's `company-app-<date>-<time>.sql`, with a size that is not `0`.
- **Dump check:** the `grep` prints `-- Dump completed on …`. No line, or a `0` size, means the dump failed: **STOP**.
- **`migrate:status`:** 45 rows, all `Ran`.
- **`route:list`:** one line, `GET|HEAD api/v1/me/schedule … me.schedule.index`.
- **Timezone:** the line ends in `Asia/Manila`.

**Smoke tests** (same VPS, any directory):

```sh
B=https://company-staging.storm-ark.com
curl -s -o /dev/null -w 'up %{http_code}\n'          $B/up                                                       # 200
curl -s -o /dev/null -w 'login %{http_code}\n'       $B/login                                                    # 200
curl -s -o /dev/null -w 'home %{http_code}\n'        -H 'Accept: application/json' $B/api/v1/me/home             # 401
curl -s -o /dev/null -w 'my-schedule %{http_code}\n' -H 'Accept: application/json' "$B/api/v1/me/schedule?from=2026-10-12&to=2026-10-18"   # 401
curl -s -o /dev/null -w 'schedule %{http_code}\n'    -H 'Accept: application/json' "$B/api/v1/schedule?from=2026-10-12&to=2026-10-18"      # 401
curl -s -o /dev/null -w 'entries %{http_code}\n'     -H 'Accept: application/json' $B/api/v1/schedule-entries    # 401
```

**Expected:** `up 200`, `login 200`, then `401` for the other four. A `404` (the route cache is old) or `500`: **STOP**.

**Paste back:**
- the `git status` and `git rev-parse HEAD` lines;
- the three `ls -lt` lines and the `-- Dump completed` line;
- the `migrate:status` summary (count of Ran / pending);
- the `route:list` line and the timezone line;
- the six smoke-test lines;
- the `ss` line and the `$C ps` table.

**Never paste:** `.env.staging` contents or anything containing `MYSQL_ROOT_PASSWORD`.

## 3. Final UAT APK — **[Operator, Windows build machine]**

**Where:** PowerShell, in the repository root on the Windows build machine.

**What it does:**
- Checks out exactly `0e7fce6`.
- Runs the same Flutter checks CI runs, with per-area test counts.
- **Builds** the release APK against staging.
- Prints the APK's size, SHA-256, manifest facts and signer, then installs it.

**Run:**

```powershell
git fetch origin
git checkout --detach 0e7fce6afd243ecb27cec3123214cd8fbb8e1a20
git rev-parse HEAD                 # must print 0e7fce6afd243ecb27cec3123214cd8fbb8e1a20
git status --porcelain             # must print nothing
cd apps/mobile

flutter --version
dart --version
java -version

flutter pub get
dart format --output=none --set-exit-if-changed .
flutter analyze
flutter test                          # expect +513 "All tests passed!"
flutter test test/features/schedule   # expect +39
flutter test test/features/home       # expect +67
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
- **Checkout:** `git rev-parse HEAD` prints the full `0e7fce6…` SHA, and both `git status --porcelain` print nothing. Otherwise **STOP**.
- **Checks:** `dart format` changes nothing, and `flutter analyze` says `No issues found!`.
- **Test counts:** exactly the counts above. A different count means a different checkout: **STOP**.
- **Build:** `flutter build apk` ends with `√ Built build\app\outputs\flutter-apk\app-release.apk`, and `LastWriteTime` is **now**.
- **Manifest:** `package: name='com.companyapp.mobile'`, and `uses-permission: name='android.permission.INTERNET'` is present. **No location permission:** 30A reads no location.
- **Signer:** a debug signer. This is known and deferred to Phase 38.
- **Install:** `adb install` ends with `Success`.

**Paste back:** all of the output above, including the build line and the `adb` lines. It contains no secrets.

This APK is the only authorized APK for Phase 30A UAT. It replaces the Phase 29C APK on the test device. Do not change signing, dependencies or source.

## 4. UAT data plan

**Accounts:** four dedicated `UAT30A` accounts. No existing user or business record is modified. The UAT27, UAT28 and UAT29A/B/C data is left as it is.

| Key | Email | Role | Staff profile | Used by |
|---|---|---|---|---|
| staff | `uat30a.staff@company-app.test` | staff | `UAT30A-001`, Rosa UAT30A-Garcia (reports to Ben) | 30A-01…05, 07, 08 |
| manager | `uat30a.manager@company-app.test` | manager | `UAT30A-002`, Ben UAT30A-Cruz | 30A-06 |
| admin | `uat30a.admin@company-app.test` | administrator | `UAT30A-003`, Ada UAT30A-Admin | 30A-06 |
| noprofile | `uat30a.noprofile@company-app.test` | staff | none | 30A-06 (no-profile state) |

Plus `UAT30A-010`, Noel UAT30A-Reyes. Noel is a staff record **without** an account: Rosa's colleague, who also reports to Ben.

**Everything is dated from `D`, the company (Manila) day of the last `seed`.** Times are Manila wall-clock times.

**Schedule entries:**

| Title | Type | Organizer | People | When | Shows for |
|---|---|---|---|---|---|
| `[UAT30A] Early briefing` | Meeting | Rosa | — | D 07:00–07:30 (project UAT30A Plant Room) | Rosa (UAT-30A-03) |
| `[UAT30A] Client visit — Acme` | Client visit | Rosa | Noel | D 14:00–15:30 | Rosa |
| `[UAT30A] Night pour` | Service appointment | **Noel** | Rosa | D+1 22:00 – D+2 02:00 | Rosa, on **both** days (UAT-30A-04) |
| `[UAT30A] Site shutdown` | Company event | Rosa | — | all day, D+3 and D+4 | Rosa, on both days |
| `[UAT30A] Toolbox talk` | Training | Rosa | — | D+3 08:00–08:30 | Rosa, **after** Site shutdown |
| `[UAT30A] Planning review` | Meeting | Rosa | — | D+8 09:00–10:00 | Rosa, next week |
| `[UAT30A] Last week's retro` | Meeting | Rosa | — | D−3 16:00–17:00 | Rosa, previous week |
| `[UAT30A] Noel's private appointment` | Meeting | Noel | — | D 10:00–11:00 | **nobody** (private, DEC-040) |
| `[UAT30A] Manager 1:1 prep` | Meeting | Ben | — | D 11:00–11:30 | Ben |
| `[UAT30A] Admin budget review` | Meeting | Ada | — | D 13:00–14:00 | Ada |

**Tasks:**

| Title | Assignee | Status | Due | Shows for |
|---|---|---|---|---|
| `[UAT30A] Order filters` | Rosa | To do | D (project UAT30A Plant Room) | Rosa |
| `[UAT30A] Submit the weekly report` | Rosa | In progress | D+5 | Rosa |
| `[UAT30A] Already done` | Rosa | Completed | D | **nobody** (closed) |
| `[UAT30A] Noel's inspection` | Noel | To do | D | **nobody** (not Ben's own) |
| `[UAT30A] Approve the rota` | Ben | To do | D | Ben |

**Leave** uses an **inactive** leave type, `UAT30A Vacation leave` (code `UAT30A-VL`). It is inactive so no leave form ever offers it. The schedule titles leave with its type's name.

| Reason | Who | Status | Dates | Shows for |
|---|---|---|---|---|
| `[UAT30A] Family trip` | Rosa | Approved | D+5 – D+6 | Rosa, on both days |
| `[UAT30A] Pending request` | Rosa | Pending | D+1 | **nobody** (not approved) |
| `[UAT30A] Noel's day off` | Noel | Approved | D | **nobody** (not Ben's own) |

Also created: the project `UAT30A-P01` "UAT30A Plant Room", with Rosa as a member.

**What the app should show** as `uat30a.staff`, in the week from D:

| Day | Rows (in this order) |
|---|---|
| D (**Today**) | Order filters (All day · Task · To do · UAT30A Plant Room); Early briefing (07:00 – 07:30 · Meeting · UAT30A Plant Room); Client visit — Acme (14:00 – 15:30 · Client visit) |
| D+1 (**Tomorrow**) | Night pour (22:00 – until *D+2's day*) |
| D+2 | Night pour (From *D+1's day* – 02:00) |
| D+3 | Site shutdown (All day · Company event); Toolbox talk (08:00 – 08:30 · Training) |
| D+4 | Site shutdown (All day) |
| D+5 | Submit the weekly report (All day · Task · In progress); UAT30A Vacation leave (All day · Leave) |
| D+6 | UAT30A Vacation leave (All day · Leave) |

## 5. Staging data preparation — **[Operator, VPS]**

All steps: **Where:** the VPS, in the staging checkout, after §2.

**Step 0. Get the script and check it.**

**What it does:** copies the PHP block in §7 of this file, as merged on `origin/main`, into your home directory. It does not change the deployed checkout. The SHA-256 proves the file is exactly the reviewed and rehearsed text.

```sh
cd /home/deploy/company-app/company-app-build/company-app
git fetch origin main
git show origin/main:docs/testing/PHASE_30A_UAT_PREPARATION.md | awk '/^```php$/{f=1;next} /^```$/{f=0} f' > "$HOME/uat30a_data.php"
sha256sum "$HOME/uat30a_data.php"
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
U="$HOME/uat30a_data.php"
```

**Expected:** the SHA printed equals the one in §7. **Any mismatch: STOP.** (`C` and `U` must be set again in every new shell.)

**Paste back:** the `sha256sum` line.

**Step 1. Read-only plan.**

**What it does:** prints the company day, whether the UAT30A accounts and records already exist, and whether the three roles exist. It changes nothing.

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
UAT30A staff records: 0 of 4
UAT30A schedule entries: 0 of 10
UAT30A tasks: 0 of 5
UAT30A leave requests: 0 of 3
UAT30A project: 0 of 1; leave type: 0 of 1
roles present: 3/3
```
**STOP** if a UAT30A account already exists that nobody can account for, or the roles are not `3/3`.

**Paste back:** the whole output.

**Step 2. Seed.**

**What it does:** in one database transaction, creates the §4 accounts, staff records, project, entries, tasks, leave type and leave requests. It writes the four new passwords, and nothing else, into a new file only you can read.

```sh
CRED="$HOME/uat30a-credentials.txt"
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
seed: 10 entries, 5 tasks, 3 leave requests set for company day <today>
seed: done; 4 new account(s)
seed exit=0
600 deploy
4
```
- **Non-zero exit:** nothing was changed (one transaction). **STOP** and paste the screen output. Never paste the file.
- **Mode is not `600`:** run `shred -u "$CRED"` and **STOP**.
- **Otherwise:**
  1. Open the file privately: `less "$CRED"` (press `q` to leave).
  2. Copy all four passwords into your password manager. Do not paste them anywhere else.
  3. Run `shred -u "$CRED"`, then `ls "$CRED"`. Expect `No such file or directory`.

**Paste back:** the screen output above and the `ls` line. **Never paste** the file's contents.

**Step 3. Verify.**

**What it does:** read-only.
- Runs the real `/me/schedule`, `/schedule-entries/{id}`, `/me/home` and `/schedule` controllers as the UAT30A accounts, the way the app calls them.
- Prints each day as the app lays it out, with a `check … OK` line for each week and role.
- Is the **MySQL check** of the 30A queries (only SQLite was used before).

```sh
$C exec -T app php -- verify < "$U"
```

**Expected** (dates and weekdays follow D; this example is for D = Monday 12 Oct):
```
stage=verify utc_now=<now> company_day=<D> (Asia/Manila)
staff this week <D>…<D+6> (HTTP 200, company_day=<D> +08:00):
   D+0  Mon 12 Oct
      All day · [UAT30A] Order filters · task todo · UAT30A Plant Room
      07:00 – 07:30 · [UAT30A] Early briefing · meeting · UAT30A Plant Room
      14:00 – 15:30 · [UAT30A] Client visit — Acme · client_visit
   D+1  Tue 13 Oct
      22:00 – until Wed 14 Oct · [UAT30A] Night pour · service_appointment
   D+2  Wed 14 Oct
      From Tue 13 Oct – 02:00 · [UAT30A] Night pour · service_appointment
   D+3  Thu 15 Oct
      All day · [UAT30A] Site shutdown · company_event
      08:00 – 08:30 · [UAT30A] Toolbox talk · training
   D+4  Fri 16 Oct
      All day · [UAT30A] Site shutdown · company_event
   D+5  Sat 17 Oct
      All day · [UAT30A] Submit the weekly report · task in_progress
      All day · UAT30A Vacation leave · leave
   D+6  Sun 18 Oct
      All day · UAT30A Vacation leave · leave
   check this week: OK
staff next week <D+7>…<D+13> (…):
   D+7  …  Nothing scheduled.
   D+8  …
      09:00 – 10:00 · [UAT30A] Planning review · meeting
   (D+9 … D+13: Nothing scheduled.)
   check next week: OK
staff previous week <D-7>…<D-1> (…):
   (D-7 … D-4: Nothing scheduled.)
   D-3  …
      16:00 – 17:00 · [UAT30A] Last week's retro · meeting
   (D-2, D-1: Nothing scheduled.)
   check previous week: OK
entry as staff: [UAT30A] Early briefing: 200 type=meeting project=UAT30A Plant Room organizer=Rosa UAT30A-Garcia people=0
entry as staff: [UAT30A] Night pour: 200 type=service_appointment project=- organizer=Noel UAT30A-Reyes people=1
entry as staff: [UAT30A] Noel's private appointment: 404 (private: correct)
entry as manager: [UAT30A] Noel's private appointment: 404 (private: correct)
staff Home Today: [UAT30A] Order filters | [UAT30A] Early briefing | [UAT30A] Client visit — Acme
legacy /schedule <D>: includes [UAT30A] Early briefing (07:00 Manila)
manager this week: [UAT30A] Approve the rota | [UAT30A] Manager 1:1 prep
   check manager: OK
admin this week: [UAT30A] Admin budget review
   check admin: OK
noprofile items: null (no-profile state)
```
**STOP** if any `check` line is not `OK`, or any line says `UNEXPECTED` or `MISSING`.

**Paste back:** the whole output. It contains no secrets.

**Step 4. Exposure check.**

**What it does:** read-only. For each UAT30A account, counts API tokens (app sign-ins), web sessions, and login/logout audit events.

```sh
$C exec -T app php -- exposure < "$U"
```

**Expected before UAT:** four lines, each `api_tokens=0 last_used=- web_sessions=0 audit: none`.

**Paste back:** the output.

**Step 5. On the UAT day, if it is not the seeding day, or to repeat a scenario.**

**What it does:** re-dates everything to the new company day and restores any changed record (for example a task completed during UAT-30A-05). It creates no account, so it prints no password.

```sh
$C exec -T app php -- seed < "$U" > /dev/null; echo "seed exit=$?"
$C exec -T app php -- verify < "$U"
```

**Expected:** `already existed (password unchanged)` for all four, `seed: done; 0 new account(s)` and `seed exit=0`. Then `verify` as in step 3, with every `check` `OK`. Pull to refresh in the app (or tap **Today**) afterwards.

**If a password is ever exposed:**
1. Run step 4 (`exposure`) and paste its output.
2. Run `rotate`. It makes exactly four new passwords, all or nothing, and revokes the accounts' tokens and sessions.
   ```sh
   CRED="$HOME/uat30a-credentials.txt"
   test ! -e "$CRED" && (umask 077; set -o noclobber; $C exec -T app php -- rotate < "$U" > "$CRED"); echo "rotate exit=$?"
   ```
3. Follow the same `stat`, `wc` (4), password-manager and `shred` steps as for `seed`.
4. **A non-zero `rotate` exit changed nothing**, even if STDERR already printed `rotated …` lines. Everything is rolled back, and the old passwords still work. Shred the (empty) file and paste the screen output.

## 6. UAT-time notes (for **execution**, not preparation)

- **Device:** the §3 APK replaces the Phase 29C APK. Sign in as `uat30a.staff`, pasting the password from the password manager into the app's sign-in field.
- **Times:** everything below is Manila time and follows D, the day of the last `seed` (§5 step 5 re-dates it). If UAT runs past midnight, tap **Today** or pull to refresh.
- **UAT-30A-01:** open the **Schedule** tab.
  - The week bar reads D – D+6 (for example "12 – 18 Oct").
  - Headers: **Today**, **Tomorrow**, then dated days ("Wed 14 Oct").
  - The rows are as in the §4 table, in that order.
  - All-day rows read "All day". This week has no empty day; next week's empty days read "Nothing scheduled."
  - Nothing from Noel's private appointment, Already done, the pending request or Noel's inspection or day off.
- **UAT-30A-02:**
  - **Today** is greyed out on the current week.
  - **Next week:** D+7 – D+13, with only Planning review (09:00 – 10:00) on D+8. **Today** is now enabled; tap it to return.
  - **Previous week:** D−7 – D−1, with Last week's retro on D−3, and D−1 headed **Yesterday**. Tap **Today** again.
  - **Pull down** to refresh: the week stays on screen.
- **UAT-30A-03:**
  - On **Today**, Early briefing reads **07:00 – 07:30**. It is on today, not on yesterday (go Previous week: it is not on D−1).
  - Then open the **Home** tab: Today lists Order filters, Early briefing and Client visit — Acme, at the same times.
- **UAT-30A-04:**
  - Night pour appears on D+1 ("22:00 – until …") **and** on D+2 ("From … – 02:00").
  - Site shutdown appears on D+3 and D+4. On D+3 it comes **before** Toolbox talk (all-day items first).
- **UAT-30A-05:**
  - **Task:** tap **Order filters**. Its task detail opens in the **Tasks** tab. Go back to the Schedule tab.
  - **Meeting:** tap **Client visit — Acme**. The detail shows:
    - the title, "Client visit";
    - **When** "<D's day> · 14:00 – 15:30";
    - Organizer Rosa UAT30A-Garcia;
    - **People (1)** with Noel UAT30A-Reyes;
    - the description "Bring the signed quote.";
    - no buttons.

    Back keeps the same week.
  - **Shared meeting:** tap **Night pour**. Organizer Noel UAT30A-Reyes, People (1) Rosa UAT30A-Garcia, and a two-line description.
  - **Leave:** the **UAT30A Vacation leave** rows have no chevron, and tapping does nothing.
  - **From Home:** open the **Home** tab and tap **Early briefing**. The same kind of detail opens, **in the Schedule tab** ("<D's day> · 07:00 – 07:30", Project UAT30A Plant Room, "No other people.").
  - *Optional:* from Schedule, open **Order filters**, set it to **Completed**, then return to the Schedule tab. It is gone without a pull. Afterwards, run §5 step 5 to restore it.
- **UAT-30A-06:**
  - Sign out and sign in as `uat30a.manager`. Schedule shows **only** Approve the rota and Manager 1:1 prep. None of Rosa's or Noel's items appear, although both report to Ben.
  - As `uat30a.admin`: **only** Admin budget review.
  - As `uat30a.noprofile`: "No staff profile is linked to this account."
  - Sign back in as `uat30a.staff`.
- **UAT-30A-07:**
  - **Offline:** switch on airplane mode.
    - Pull to refresh: "Couldn't refresh. Showing earlier information."
    - Tap **Next week**: "Couldn't load your schedule. Check your connection." with **Try again**.
    - Reconnect and tap Try again: **next week** loads (not the week before).
    - Tap **Today**.
  - **Revoked:**
    1. On the VPS, run `$C exec -T app php -- revoke < "$U"`. Expect `revoke: uat30a.staff api_tokens_revoked=1` or more.
    2. In the app, pull to refresh. It returns to Login with "Your session has ended. Please sign in again."
- **UAT-30A-08:** with system dark mode and the largest font, check:
  - the Schedule tab (scroll the whole week, including the week bar);
  - the Night pour detail (scroll to the end).
- **Afterwards:** run §5 step 4 (`exposure`) once more and paste it. The UAT30A data stays in place; any cleanup needs its own authorization.

## 7. Script — `uat30a_data.php`

**Revision 1 (2026-10-12), SHA-256 `50187156dbda9a3fa1f76f198b76c75c808e1522a0656731e9a1eb39ba13676b`** (the exact text below, with a trailing newline).

```php
<?php

/*
 * Phase 30A UAT data — operator tool (NOT application code; never commit to
 * apps/api, never expose over HTTP).
 *
 * Run inside the staging `app` container, reading this file from stdin:
 *
 *   $C exec -T app php -- <stage> < uat30a_data.php
 *
 * Stages:
 *   plan      read-only: the company day, existing UAT30A accounts and
 *             records, roles
 *   seed      idempotent: 4 accounts (staff, manager, admin, no-profile),
 *             4 staff records (3 linked, 1 without an account; the staff
 *             member and the colleague report to the manager), 1 project,
 *             10 schedule entries, 5 tasks, 1 inactive leave type and
 *             3 leave requests — all dated from the company day of this
 *             run. A generated password is written to STDOUT, as an
 *             "email password" line, ONLY for an account created by this
 *             run — redirect STDOUT to a new 0600 file. Re-running re-dates
 *             and restores the plan; passwords never change.
 *   verify    read-only: runs the real /me/schedule, /schedule-entries/{id},
 *             /schedule and /me/home controllers as the UAT30A accounts and
 *             prints what the app will show, day by day
 *   revoke    UAT-30A-07 only: deletes uat30a.staff's API tokens
 *   exposure  read-only: per UAT30A account, API tokens, web sessions and
 *             login/logout audit events
 *   rotate    new password for EXACTLY the four UAT30A accounts, all-or-
 *             nothing; revokes their API tokens and web sessions. STDOUT
 *             carries only "email password" lines.
 *
 * Every status line, header and error goes to STDERR, so STDOUT of `seed`
 * and `rotate` is only ever credentials. Only "UAT30A"-prefixed records and
 * the uat30a.* accounts are created or changed. No truncation, no
 * migrations, no other business data edited.
 */

use App\Enums\AccountStatus;
use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeStatus;
use App\Enums\ProjectMembershipRole;
use App\Enums\ProjectStatus;
use App\Enums\ScheduleEntryActivityType;
use App\Enums\StaffStatus;
use App\Enums\TaskStatus;
use App\Http\Controllers\Api\V1\Home\MyHomeController;
use App\Http\Controllers\Api\V1\Scheduling\MyScheduleController;
use App\Http\Controllers\Api\V1\Scheduling\ScheduleController;
use App\Http\Controllers\Api\V1\Scheduling\ScheduleEntryController;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\ScheduleEntry;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use App\Support\CompanyTimezone;
use App\Support\Reporting\OverdueTasks;
use App\Support\Scheduling\ScheduleEntryTiming;
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
    'staff' => ['uat30a.staff@company-app.test', 'UAT30A Staff', Role::STAFF, 'UAT30A-001'],
    'manager' => ['uat30a.manager@company-app.test', 'UAT30A Manager', Role::MANAGER, 'UAT30A-002'],
    'admin' => ['uat30a.admin@company-app.test', 'UAT30A Admin', Role::ADMINISTRATOR, 'UAT30A-003'],
    'noprofile' => ['uat30a.noprofile@company-app.test', 'UAT30A No-Profile', Role::STAFF, null],
];

// employee_number => [first name, last name, manager's employee_number]
$staffRecords = [
    'UAT30A-002' => ['Ben', 'UAT30A-Cruz', null],
    'UAT30A-001' => ['Rosa', 'UAT30A-Garcia', 'UAT30A-002'],
    'UAT30A-003' => ['Ada', 'UAT30A-Admin', null],
    'UAT30A-010' => ['Noel', 'UAT30A-Reyes', 'UAT30A-002'],   // no account: Rosa's colleague
];

$projectCode = 'UAT30A-P01';
$projectName = 'UAT30A Plant Room';
$leaveTypeCode = 'UAT30A-VL';
$leaveTypeName = 'UAT30A Vacation leave';

// title => [activity, creator, participants, [start day, start time], [end day, end time] (or days only when all-day), all-day, in project?, description]
// Days are offsets from the company day D; times are company (Manila) wall-clock times.
$A = ScheduleEntryActivityType::class;
$entries = [
    '[UAT30A] Early briefing' => [$A::Meeting, 'UAT30A-001', [], [0, '07:00'], [0, '07:30'], false, true, 'Gate checks before the shift.'],
    '[UAT30A] Client visit — Acme' => [$A::ClientVisit, 'UAT30A-001', ['UAT30A-010'], [0, '14:00'], [0, '15:30'], false, false, 'Bring the signed quote.'],
    '[UAT30A] Night pour' => [$A::ServiceAppointment, 'UAT30A-010', ['UAT30A-001'], [1, '22:00'], [2, '02:00'], false, false, "Concrete pour overnight.\nSafety boots required."],
    '[UAT30A] Site shutdown' => [$A::CompanyEvent, 'UAT30A-001', [], [3], [4], true, false, null],
    '[UAT30A] Toolbox talk' => [$A::Training, 'UAT30A-001', [], [3, '08:00'], [3, '08:30'], false, false, null],
    '[UAT30A] Planning review' => [$A::Meeting, 'UAT30A-001', [], [8, '09:00'], [8, '10:00'], false, false, null],
    "[UAT30A] Last week's retro" => [$A::Meeting, 'UAT30A-001', [], [-3, '16:00'], [-3, '17:00'], false, false, null],
    "[UAT30A] Noel's private appointment" => [$A::Meeting, 'UAT30A-010', [], [0, '10:00'], [0, '11:00'], false, false, 'Never shown to anyone else.'],
    '[UAT30A] Manager 1:1 prep' => [$A::Meeting, 'UAT30A-002', [], [0, '11:00'], [0, '11:30'], false, false, null],
    '[UAT30A] Admin budget review' => [$A::Meeting, 'UAT30A-003', [], [0, '13:00'], [0, '14:00'], false, false, null],
];

// title => [assignee, status, due day offset, in project?]
$tasks = [
    '[UAT30A] Order filters' => ['UAT30A-001', TaskStatus::Todo, 0, true],
    '[UAT30A] Submit the weekly report' => ['UAT30A-001', TaskStatus::InProgress, 5, false],
    '[UAT30A] Already done' => ['UAT30A-001', TaskStatus::Completed, 0, false],
    "[UAT30A] Noel's inspection" => ['UAT30A-010', TaskStatus::Todo, 0, false],
    '[UAT30A] Approve the rota' => ['UAT30A-002', TaskStatus::Todo, 0, false],
];

// reason => [staff, status, start day, end day]
$leave = [
    '[UAT30A] Family trip' => ['UAT30A-001', LeaveRequestStatus::Approved, 5, 6],
    '[UAT30A] Pending request' => ['UAT30A-001', LeaveRequestStatus::Pending, 1, 1],
    "[UAT30A] Noel's day off" => ['UAT30A-010', LeaveRequestStatus::Approved, 0, 0],
];

// What each account's week (D…D+6) must contain, exactly.
$expectedWeek = [
    'staff' => [
        '[UAT30A] Order filters', '[UAT30A] Early briefing', '[UAT30A] Client visit — Acme', '[UAT30A] Night pour',
        '[UAT30A] Site shutdown', '[UAT30A] Toolbox talk', '[UAT30A] Submit the weekly report', $leaveTypeName,
    ],
    'manager' => ['[UAT30A] Approve the rota', '[UAT30A] Manager 1:1 prep'],
    'admin' => ['[UAT30A] Admin budget review'],
];

$today = OverdueTasks::todayInCompanyTimezone();
$tz = CompanyTimezone::value();
$err("stage={$stage} utc_now=".now('UTC')->toIso8601String()." company_day={$today} ({$tz})");

$day = fn (int $offset) => Carbon::parse($today)->addDays($offset)->toDateString();

$uat30aOnly = function (User $user): void {
    if (! preg_match('/^uat30a\.[a-z.]+@company-app\.test$/', $user->email)) {
        fwrite(STDERR, "refusing non-UAT30A account\n");
        exit(1);
    }
};

$users = fn () => collect($accounts)->map(fn ($a) => User::query()->where('email', $a[0])->first());
$staffId = fn (string $number) => Staff::query()->where('employee_number', $number)->value('id');

/** Calls a real controller as $user and returns [status, decoded body]. */
$call = function (User $user, string $uri, array $query, callable $invoke): array {
    $request = Request::create($uri, 'GET', $query);
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
    $err('UAT30A staff records: '.Staff::query()->where('employee_number', 'like', 'UAT30A-%')->count().' of '.count($staffRecords));
    $err('UAT30A schedule entries: '.ScheduleEntry::query()->where('title', 'like', '[UAT30A]%')->count().' of '.count($entries));
    $err('UAT30A tasks: '.Task::query()->where('title', 'like', '[UAT30A]%')->count().' of '.count($tasks));
    $err('UAT30A leave requests: '.LeaveRequest::query()->where('reason', 'like', '[UAT30A]%')->count().' of '.count($leave));
    $err('UAT30A project: '.Project::query()->where('project_code', $projectCode)->count().' of 1; leave type: '.LeaveType::query()->where('code', $leaveTypeCode)->count().' of 1');
    $err('roles present: '.Role::query()->whereIn('name', [Role::ADMINISTRATOR, Role::MANAGER, Role::STAFF])->count().'/3');
    exit(0);
}

if ($stage === 'seed') {
    $clash = Staff::query()->where('employee_number', 'like', 'UAT30A-%')->whereNotNull('user_id')
        ->whereHas('user', fn ($q) => $q->where('email', 'not like', 'uat30a.%'))->exists();
    if ($clash) {
        $err('seed: a UAT30A staff record is linked to a non-UAT30A account; nothing changed');
        exit(1);
    }

    $passwords = [];
    DB::transaction(function () use ($accounts, $staffRecords, $projectCode, $projectName, $leaveTypeCode, $leaveTypeName, $entries, $tasks, $leave, $today, $tz, $day, $staffId, &$passwords, $err) {
        foreach ($staffRecords as $number => [$first, $last]) {
            $staff = Staff::query()->firstOrNew(['employee_number' => $number]);
            $staff->fill(['first_name' => $first, 'last_name' => $last, 'status' => StaffStatus::Active]);
            $staff->save();
        }
        foreach ($staffRecords as $number => [, , $manager]) {
            Staff::query()->where('employee_number', $number)->update(['manager_id' => $manager === null ? null : $staffId($manager)]);
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

        $project = Project::query()->firstOrNew(['project_code' => $projectCode]);
        $project->fill([
            'name' => $projectName,
            'status' => ProjectStatus::Active,
            'description' => 'Phase 30A UAT project.',
            'client_id' => null,
            'start_date' => $day(-60),
            'target_end_date' => $day(90),
            'completed_date' => null,
            'notes' => 'UAT30A internal note',
        ]);
        $project->save();
        ProjectMembership::query()->updateOrCreate(
            ['project_id' => $project->id, 'staff_id' => $staffId('UAT30A-001')],
            ['role' => ProjectMembershipRole::Member],
        );

        foreach ($entries as $title => [$activity, $creator, $participants, $start, $end, $allDay, $inProject, $description]) {
            $timing = $allDay
                ? ScheduleEntryTiming::resolve($day($start[0]), $day($end[0]), true)
                : ScheduleEntryTiming::resolve(
                    Carbon::parse($day($start[0]).' '.$start[1], $tz)->toIso8601String(),
                    Carbon::parse($day($end[0]).' '.$end[1], $tz)->toIso8601String(),
                    false,
                );
            $entry = ScheduleEntry::query()->firstOrNew(['title' => $title]);
            $entry->fill([
                'creator_staff_id' => $staffId($creator),
                'description' => $description,
                'activity_type' => $activity,
                'starts_at' => $timing['starts_at'],
                'ends_at' => $timing['ends_at'],
                'is_all_day' => $allDay,
                'project_id' => $inProject ? $project->id : null,
            ]);
            $entry->save();
            // Exactly the planned participants (a re-run undoes any change).
            $entry->participants()->sync(array_map($staffId, $participants));
        }

        foreach ($tasks as $title => [$assignee, $status, $due, $inProject]) {
            $task = Task::query()->firstOrNew(['title' => $title]);
            $task->fill([
                'project_id' => $inProject ? $project->id : null,
                'status' => $status,
                'assignee_staff_id' => $staffId($assignee),
                'created_by_user_id' => $staffUserId,
                'due_date' => $day($due),
                'completed_at' => $status === TaskStatus::Completed ? now() : null,
            ]);
            $task->save();
        }

        // Inactive, so no leave form ever offers it; /me/schedule titles
        // leave with its type's name.
        $type = LeaveType::query()->firstOrNew(['code' => $leaveTypeCode]);
        $type->fill(['name' => $leaveTypeName, 'description' => 'Phase 30A UAT only.', 'is_paid' => true, 'status' => LeaveTypeStatus::Inactive, 'sort_order' => 999]);
        $type->save();

        foreach ($leave as $reason => [$number, $status, $startDay, $endDay]) {
            $request = LeaveRequest::query()->firstOrNew(['staff_id' => $staffId($number), 'reason' => $reason]);
            $request->fill([
                'leave_type_id' => $type->id,
                'start_date' => $day($startDay),
                'end_date' => $day($endDay),
                'total_days' => $endDay - $startDay + 1,
                'status' => $status,
                'created_by_user_id' => $staffUserId,
            ]);
            $request->save();
        }

        $err('seed: '.count($entries).' entries, '.count($tasks).' tasks, '.count($leave)." leave requests set for company day {$today}");
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

    $schedule = fn (User $user, int $from, int $to) => $call($user, '/api/v1/me/schedule', ['from' => $day($from), 'to' => $day($to)],
        fn ($r) => app(MyScheduleController::class)->index($r));

    // The app's day rule: an item is on a company day when it overlaps
    // [start, end); its time reads "From …" / "until …" past the day.
    $printWeek = function (array $data, int $from) use ($day, $tz): array {
        $titles = [];
        for ($o = $from; $o < $from + 7; $o++) {
            $date = $day($o);
            $start = Carbon::parse($date, $tz)->startOfDay()->utc();
            $end = $start->copy()->addDay();
            $lines = [];
            foreach ($data['items'] as $item) {
                $s = Carbon::parse($item['starts_at']);
                $e = Carbon::parse($item['ends_at']);
                if (! ($s->lt($end) && ($e->gt($start) || $s->gte($start)))) {
                    continue;
                }
                $titles[$item['title']] = true;
                $time = $item['is_all_day'] ? 'All day'
                    : ($s->lt($start) ? 'From '.$s->copy()->tz($tz)->format('D j M') : $s->copy()->tz($tz)->format('H:i'))
                        .' – '.($e->gt($end) ? 'until '.$e->copy()->tz($tz)->format('D j M') : $e->copy()->tz($tz)->format('H:i'));
                $label = match ($item['source_type']) {
                    'schedule_entry' => $item['activity_type'],
                    'task' => 'task '.$item['task_status'],
                    default => 'leave',
                };
                $lines[] = "      {$time} · {$item['title']} · {$label}".($item['project'] ? " · {$item['project']['name']}" : '');
            }
            $offset = $o >= 0 ? "D+{$o}" : "D{$o}";
            echo '   '.str_pad($offset, 5).Carbon::parse($date)->format('D j M').($lines === [] ? '  Nothing scheduled.' : '').PHP_EOL;
            foreach ($lines as $line) {
                echo $line.PHP_EOL;
            }
        }

        return array_keys($titles);
    };
    $check = function (string $what, array $got, array $want): void {
        sort($got);
        sort($want);
        echo $got === $want ? "   check {$what}: OK".PHP_EOL
            : "   check {$what}: UNEXPECTED — got [".implode(' | ', $got).'] want ['.implode(' | ', $want).']'.PHP_EOL;
    };

    // Staff: this week, next week, previous week.
    $staffUser = $all['staff'];
    foreach ([[0, 'this week'], [7, 'next week'], [-7, 'previous week']] as [$from, $label]) {
        [$status, $body] = $schedule($staffUser, $from, $from + 6);
        echo "staff {$label} {$body['data']['range']['from']}…{$body['data']['range']['to']} (HTTP {$status}, company_day={$body['data']['company_day']['date']} {$body['data']['company_day']['utc_offset']}):".PHP_EOL;
        $got = $printWeek($body['data'], $from);
        $check($label, $got, match ($from) {
            0 => $expectedWeek['staff'],
            7 => ['[UAT30A] Planning review'],
            default => ["[UAT30A] Last week's retro"],
        });
    }

    // Entry details, as the app opens them.
    $entry = fn (string $title) => ScheduleEntry::query()->where('title', $title)->first();
    foreach ([['staff', '[UAT30A] Early briefing'], ['staff', '[UAT30A] Night pour'], ['staff', "[UAT30A] Noel's private appointment"], ['manager', "[UAT30A] Noel's private appointment"]] as [$key, $title]) {
        [$status, $body] = $call($all[$key], '/api/v1/schedule-entries/x', [], fn ($r) => app(ScheduleEntryController::class)->show($r, $entry($title)));
        $d = $body['data'] ?? null;
        $private = str_contains($title, 'private');
        echo "entry as {$key}: {$title}: ".($d
            ? "200 type={$d['activity_type']} project=".($d['project']['name'] ?? '-').' organizer='.($d['creator']['display_name'] ?? '-').' people='.count($d['participants']).($private ? ' — UNEXPECTED: private entry visible' : '')
            : "{$status}".($private ? ' (private: correct)' : ' — UNEXPECTED')).PHP_EOL;
    }

    // Home's Today and the legacy /schedule for the same day (R-5).
    [, $home] = $call($staffUser, '/api/v1/me/home', [], fn ($r) => app(MyHomeController::class)->show($r));
    echo 'staff Home Today: '.implode(' | ', array_column($home['data']['today']['items'], 'title')).PHP_EOL;
    [, $legacy] = $call($staffUser, '/api/v1/schedule', ['from' => $day(0), 'to' => $day(0), 'per_page' => 100], fn ($r) => app(ScheduleController::class)->index($r));
    $legacyTitles = array_column($legacy['data'], 'title');
    echo 'legacy /schedule '.$day(0).': '.(in_array('[UAT30A] Early briefing', $legacyTitles, true) ? 'includes' : 'MISSING — UNEXPECTED:').' [UAT30A] Early briefing (07:00 Manila)'.PHP_EOL;

    // Manager and admin: their own items only (R-2), never Rosa's or Noel's.
    foreach (['manager', 'admin'] as $key) {
        [, $body] = $schedule($all[$key], 0, 6);
        echo "{$key} this week: ".implode(' | ', array_column($body['data']['items'], 'title')).PHP_EOL;
        $check("{$key}", array_column($body['data']['items'], 'title'), $expectedWeek[$key]);
    }

    [, $none] = $schedule($all['noprofile'], 0, 6);
    echo 'noprofile items: '.($none['data']['items'] === null ? 'null (no-profile state)' : 'UNEXPECTED: a list').PHP_EOL;
    exit(0);
}

if ($stage === 'revoke') {
    $user = $users()['staff'];
    if ($user === null) {
        $err('revoke: uat30a.staff absent');
        exit(1);
    }
    $uat30aOnly($user);
    $err('revoke: uat30a.staff api_tokens_revoked='.$user->tokens()->delete());
    exit(0);
}

if ($stage === 'exposure') {
    foreach ($users() as $key => $user) {
        if ($user === null) {
            echo "{$key}: absent".PHP_EOL;

            continue;
        }
        $uat30aOnly($user);
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
        $err('rotate: expected all four UAT30A accounts to exist; nothing changed');
        exit(1);
    }
    $all->each($uat30aOnly);
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
