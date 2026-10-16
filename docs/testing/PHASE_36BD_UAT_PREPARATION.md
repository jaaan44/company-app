# Phases 36B–36D — UAT Preparation (HR, Work & Operations, Reports — Deployment and UAT)

*Operator runbook. No AI session has run anything in this file against the staging VPS; this AI session cannot reach it. Every step marked **[Operator]** is pending until its output is recorded.*

**Status:** UAT-36BD-01…08 are `NOT RUN`.
- **No APK, no migration, no script.** 36B–36D change no mobile code and no database schema.
- **No account or password is created.** You make the test data yourself, as Lia, in the portal (§4, Setup).

**How each operator step is written:**
- **Where:** the machine, the user and the directory.
- **Run:** the exact commands, one block at a time.
- **Expected:** what a good result looks like, and when to **STOP**.
- **Paste back:** what to send to the AI session. **Never paste** a password, anything from your password manager, `.env.staging`, or anything containing `MYSQL_ROOT_PASSWORD`.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **UAT source baseline (deploy)** | `0797e0c235645df8534243a535205de7dec0977b` | PR #131 merge into `main`: Phases 36B–36D. |
| Running staging implementation (before §2) | `ea7750526da4b37a5b980e583ec7994f0f5fdee0` | Phase 36A (confirmed). |

**What changes between them:**
- **New Backoffice screens:** HR (leave requests, types, balances), Work (work logs, schedule), Operations (service reports, incidents), Reports (seven, with CSV) and Audit log filters.
- **The web assets change,** so the CSS file name changes. The image builds them.
- **No migration.** `composer.lock`, `package-lock.json`, the Dockerfiles, the nginx config and the environment variables are unchanged.

## 2. Staging deployment — **[Operator, VPS]**

**Where:** the VPS, as `deploy`, in `/home/deploy/company-app/company-app-build/company-app`. Run each block separately.

**Block 1: get the code**
```sh
cd /home/deploy/company-app/company-app-build/company-app
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
git fetch origin
git status --porcelain
git merge --ff-only 0797e0c235645df8534243a535205de7dec0977b
git rev-parse HEAD
```
**Expected:**
- `git status --porcelain` prints nothing;
- then `Updating ea77505..0797e0c` and `Fast-forward`;
- the last line is `0797e0c235645df8534243a535205de7dec0977b`.

**If the `Updating` line starts from anything other than `ea77505`: STOP** and paste it.

**Block 2: back up the database** (a precaution; nothing changes the schema)
```sh
$C exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > /home/deploy/backups/company-app-$(date +%Y%m%d-%H%M%S).sql
ls -lt /home/deploy/backups/company-app-*.sql | head -2
tail -c 200 "$(ls -t /home/deploy/backups/company-app-*.sql | head -1)" | grep -- '-- Dump completed'
```
**Expected:** the newest file is from now and not empty, and a `-- Dump completed on …` line appears. **Without it: STOP.**

*Tip: paste the first line (`$C exec mysql …`) as **one line**, exactly as shown, so the terminal doesn't split it.*

**Block 3: build, on its own**
```sh
$C build app nginx && echo "BUILD OK"
```
**Expected:** the last line is `BUILD OK`. **`ERROR` or `failed to solve`: STOP** and paste the error lines; the running site stays as it is.

**Block 4: restart, caches**
```sh
$C up -d --no-deps --force-recreate app nginx
$C exec app php artisan migrate:status | tail -2
$C exec app php artisan config:cache
$C exec app php artisan route:cache
$C exec app php artisan view:cache
$C ps
```
**Expected:**
- the last migration is `2026_10_16_000000_add_must_change_password_to_users_table … Ran` (nothing pending);
- three `… cached successfully` lines;
- `company-app-api` and `company-app-nginx` created just now and `Up`.

**Block 5: smoke tests** (read-only)
```sh
B=https://company-staging.storm-ark.com
for p in admin/hr/leave-requests admin/hr/leave-balances admin/work/work-logs admin/work/schedule admin/operations/incidents admin/reports/tasks; do curl -s -o /dev/null -w "$p %{http_code} -> %{redirect_url}\n" $B/$p; done
curl -s -o /dev/null -w "admin/reports/nonsense %{http_code}\n" $B/admin/reports/nonsense
```
**Expected:**
- the six pages each show `302 -> https://company-staging.storm-ark.com/login`;
- `admin/reports/nonsense 404` (only the seven report names exist).

**Anything else: STOP** and paste the output.

**Paste back:** the output of Blocks 1–5. For the build, its last few lines are enough.

## 3. If something goes wrong (rollback) — **[Operator, VPS]** only if the AI session asks

```sh
git checkout --detach ea7750526da4b37a5b980e583ec7994f0f5fdee0
$C build app nginx && $C up -d --no-deps --force-recreate app nginx
```
Nothing in the database needs undoing.

## 4. UAT-time notes (for **execution**)

**Where:**
- a desktop browser signed in as an **Administrator**;
- a **second (private) window** for Lia (`uat30b.staff`) and Ben (`uat30b.manager`) in the portal;
- the phone with the app, signed in as Lia;
- all at `https://company-staging.storm-ark.com`. Paste passwords from your password manager into the sign-in fields only.

**Setup (as Lia, in the portal; about 5 minutes).** These are the records the scenarios use. Mark each with `[UAT36BD]`.
1. **Leave → Request leave**, twice: two short requests next month, for example `[UAT36BD] one` and `[UAT36BD] two`. Both show Pending.
2. **Work logs → Log work**: one log for today, `[UAT36BD] log`.
3. **Reports → New service report**, twice, for any client, today, `[UAT36BD] service A` and `[UAT36BD] service B`. Add a photo to A. **Submit** both.
4. **Reports → Report an incident**: today, any type and severity, description `[UAT36BD] incident`. Leave "immediate action" empty.

**Report** each scenario as **PASS**, or **FAIL with what you saw**. Only you record a `PASS`.

**UAT-36BD-01: Leave requests** (Administrator)
1. **Admin → HR**: every item is a link; nothing says "Coming in Phase 36".
2. **Leave requests**: Status **Pending** is preselected; both `[UAT36BD]` requests are listed. Try the Type and From/To filters.
3. Open `[UAT36BD] one` → **Approve** → note `UAT note` → **Approve**: "Request approved.", and the History shows your note. Lia's app (Leave) shows it Approved, with the note in its history.
4. Open `[UAT36BD] two` → **Reject** → **Reject** with nothing typed: "Give a reason (at least 3 characters)." Type `UAT reason` → **Reject**: "Request rejected."
5. Open `[UAT36BD] one` again → **Cancel request** → **Cancel request**: "Request cancelled."

**UAT-36BD-02: Request leave for someone** (Administrator, then Ben)
1. **Leave requests → Request leave for someone** → **Request leave** with nothing filled: messages for the person, the type and the reason.
2. Person: search `Kai`, choose **Kai UAT30B-Tan**; an unpaid type; two days next month; reason `[UAT36BD] for Kai` → **Request leave**: "Leave requested for Kai UAT30B-Tan.", status **Pending**.
3. As **Ben** in the portal: **Leave → Team requests** lists it.

**UAT-36BD-03: Leave types** (Administrator)
1. **HR → Leave types → New leave type**: `[UAT36BD] Study Leave`, Paid ticked → **Add leave type**.
2. **Edit** it → Status **Inactive** → **Save changes**: it shows **Inactive**.
3. **Remove** a type that has requests (for example UAT31 Annual Leave) → **Remove**: refused, "…cannot be deleted. To stop it being used, set it to Inactive instead."
4. **Remove** `[UAT36BD] Study Leave` → **Remove**: "Leave type removed."

**UAT-36BD-04: Leave balances** (Administrator, then Lia's phone)
1. **HR → Leave balances**: search `Lia`, choose her; Year is the current year.
2. Each paid type shows "N left · N used · N pending"; unpaid types say "Unpaid — no balance".
3. In a paid type's **Allowance**, type `abc` → **Save**: "Use a whole number of days, 0 to 365."
4. Type a number 2 higher than now → **Save**: "Allowance saved.", and "left" goes up by 2.
5. Lia's app: **More → Leave** shows the new balance. *(Put the allowance back afterwards if you like.)*

**UAT-36BD-05: Work logs; Work links** (Administrator, then Lia)
1. **Work → Work logs**: Person `Lia` → her `[UAT36BD] log` is listed.
2. **Correct** → set the date to tomorrow → **Save correction**: "The work date cannot be later than today." Set today, 1 hour 30 minutes, edit the description → **Save correction**: "Work log corrected."
3. Lia's portal **Work logs** shows the corrected time.
4. **Remove** it → **Remove**: "Work log removed."; it's gone from Lia's list.
5. **Admin → Work → Tasks** opens the portal's tasks on the **All** tab; **Projects** opens All projects.

**UAT-36BD-06: Schedule** (Administrator, then Lia)
1. **Work → Schedule**: entries from today; set **From** earlier if the list is empty; try the Activity filter.
2. Open an entry → change its start and end times and add **Lia** under "Add people" → **Save changes**: "Changes saved." The times shown are Manila times. Lia's app (Schedule) shows it at the new time.
3. **New schedule entry** → a title → **Add entry**: refused with the API's reason ("No staff record is linked to this account." for an Administrator without a staff profile). This is expected.
4. Open the entry from step 2 → **Delete** → **Delete**: "Schedule entry deleted." *(Only if it is a test entry.)*

**UAT-36BD-07: Service reports and incidents** (Administrator, then Lia)
1. **Operations → Service reports**: Status **Submitted** is preselected; both `[UAT36BD]` reports are listed.
2. Open `service A`: the photo opens from **Photos and files**. **Mark reviewed** → **Mark reviewed**: "Report marked as reviewed."
3. Open `service B` → **Reject** → reason `UAT reason` → **Reject**: "Report rejected." Lia's app shows it Rejected with the reason.
4. **Operations → Incidents**: Status **Open** is preselected; `[UAT36BD] incident` is listed. Open it:
   - **Assign** → search `Lia`, choose her → **Assign**: "Assigned to Lia UAT30B-Ramos."
   - **Start investigation** → **Start investigation**: "Investigation started."
   - **Resolve** → **Resolve** with nothing filled: the API's message about a resolution or a corrective action. Fill **Resolution** and **Corrective action** → **Resolve**: "Incident resolved."
   - **Close** → **Close**: "Incident closed." Then **Reopen** → **Reopen**: "Incident reopened."
   - The **History** lists each step.

**UAT-36BD-08: Reports, Audit log, access and accessibility**
1. **Admin → Reports**: open each of the seven (Staff, Projects, Tasks, Work logs, Leave, Service reports, Incidents); each shows a table and its filters. Change a filter: the rows change.
2. On **Leave**, set Status Approved → **Download CSV**: a `.csv` downloads and opens in a spreadsheet with only approved rows.
3. **Administration → Audit log**: an entry `report.exported` is at the top. Set From/To to today. Click a **person's name** in an entry: "Only changes by …". **Clear filters**. Click a **record ID**: only that record's history. **Clear filters**.
4. As **Ben**, open `/admin/hr/leave-requests`: **403**.
5. On a **phone browser**, in **dark mode**, at **200% zoom**, and with the **keyboard only** (on the computer), open a few new screens: no sideways page scrolling (report tables scroll inside their box), every control reachable, the focus visible.

**Afterwards:** report each scenario. The `[UAT36BD]` records stay on staging; cleaning them up needs its own authorization.
