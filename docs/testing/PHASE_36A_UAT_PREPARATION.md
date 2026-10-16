# Phase 36A — UAT Preparation (People & Sign-in Accounts — Deployment and UAT)

*Operator runbook. No AI session has run anything in this file against the staging VPS; this AI session cannot reach it. Every step marked **[Operator]** is pending until its output is recorded.*

**Status:** UAT-36A-01…08 are `NOT RUN`.
- **No APK is needed:** 36A changes no mobile code. The installed app works as it is.
- **No script and no seed.** You create the test staff record and its sign-in account **on the screens** during UAT.
- **This deploy runs one migration** (a new column). Staging does not run migrations automatically, so Block 5 runs it.

**How each operator step is written:**
- **Where:** the machine, the user and the directory.
- **Run:** the exact commands, one block at a time.
- **Expected:** what a good result looks like, and when to **STOP**.
- **Paste back:** what to send to the AI session. **Never paste** a password (temporary or chosen), anything from your password manager, `.env.staging`, or anything containing `MYSQL_ROOT_PASSWORD`.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **UAT source baseline (deploy)** | `ea7750526da4b37a5b980e583ec7994f0f5fdee0` | PR #127 merge into `main`: Phase 36A. |
| Running staging implementation (before §2) | `2ca60921c257ca06e9f46acf74fbf076fdc3becd` | Phase 35D. **Assumed:** the 35D deploy output was never pasted. Block 1 checks it. |

**What changes between them:**
- **New screens:** Admin → People (Staff records; Departments, teams and positions), Users and roles (New account, Reset password, Suspend / Reactivate), Choose your password, and Change password.
- **New API routes:** `POST /users`, `POST /users/{id}/reset-password`, `PUT /users/{id}/status`, `PUT /me/password`.
- **One migration,** `2026_10_16_000000_add_must_change_password_to_users_table`. It adds one column, `users.must_change_password`, default `false` (46 → 47 migrations). Existing accounts are unaffected.
- **The web assets change** (new screens), so the CSS file name changes. The image builds them.
- `composer.lock`, `package-lock.json`, the Dockerfiles, the nginx config and the environment variables are unchanged.

## 2. Staging deployment — **[Operator, VPS]**

**Where:** the VPS, as `deploy`, in `/home/deploy/company-app/company-app-build/company-app`. Run each block separately.

**Block 1: get the code**
```sh
cd /home/deploy/company-app/company-app-build/company-app
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
git fetch origin
git status --porcelain
git merge --ff-only ea7750526da4b37a5b980e583ec7994f0f5fdee0
git rev-parse HEAD
```
**Expected:**
- `git status --porcelain` prints nothing;
- then `Updating 2ca6092..ea77505` and `Fast-forward`;
- the last line is `ea7750526da4b37a5b980e583ec7994f0f5fdee0`.

**If the `Updating` line starts from anything other than `2ca6092`: don't stop, but paste it.** It tells us which earlier deploy really ran; the fast-forward includes everything in between.

**Block 2: back up the database** (required: this deploy changes the schema)
```sh
$C exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  > /home/deploy/backups/company-app-$(date +%Y%m%d-%H%M%S).sql
ls -lt /home/deploy/backups/company-app-*.sql | head -3
tail -c 200 "$(ls -t /home/deploy/backups/company-app-*.sql | head -1)" | grep -- '-- Dump completed'
```
**Expected:** the newest file is from now and not empty, and a `-- Dump completed on …` line appears. **Without it: STOP.**

**Block 3: build, on its own**
```sh
$C build app nginx && echo "BUILD OK"
```
**Expected:** the last line is `BUILD OK`. **`ERROR` or `failed to solve`: STOP** and paste the error lines; the running site stays as it is.

**Block 4: restart**
```sh
$C up -d --no-deps --force-recreate app nginx
$C exec app php artisan migrate:status | tail -3
```
**Expected:** the last line shows `2026_10_16_000000_add_must_change_password_to_users_table` as **`Pending`**, and the line above it ends in `Ran`.

**Block 5: run the migration, then the caches**
```sh
$C exec app php artisan migrate --force
$C exec app php artisan migrate:status | tail -2
$C exec app php artisan config:cache
$C exec app php artisan route:cache
$C exec app php artisan view:cache
$C ps
```
**Expected:**
- `INFO  Running migrations.` and `2026_10_16_000000_add_must_change_password_to_users_table … DONE`;
- the last two migrations show `Ran`;
- the three caches succeed;
- in `$C ps`, `company-app-api` and `company-app-nginx` were created just now.

**On `FAIL` or any error: STOP** and paste it.

**Block 6: smoke tests** (read-only)
```sh
B=https://company-staging.storm-ark.com
for p in app admin/people/staff admin/people/organization admin/administration/users/new password app/profile/password; do curl -s -o /dev/null -w "$p %{http_code} -> %{redirect_url}\n" $B/$p; done
curl -s -o /dev/null -w "api POST users %{http_code}\n" -X POST -H 'Accept: application/json' $B/api/v1/users
curl -s -o /dev/null -w "api PUT me/password %{http_code}\n" -X PUT -H 'Accept: application/json' $B/api/v1/me/password
$C exec app php artisan tinker --execute="echo 'accounts that must change: '.App\Models\User::where('must_change_password', true)->count();"
```
**Expected:**
- the six pages each show `302 -> https://company-staging.storm-ark.com/login`;
- `api POST users 401` and `api PUT me/password 401`;
- `accounts that must change: 0` (nobody is affected by the migration).

**Anything else: STOP** and paste the output.

**Paste back:** the output of Blocks 1–6. For the build, its last few lines are enough.

## 3. If something goes wrong (rollback) — **[Operator, VPS]** only if the AI session asks

```sh
$C exec app php artisan migrate:rollback --step=1 --force
git checkout --detach 2ca60921c257ca06e9f46acf74fbf076fdc3becd
$C build app nginx && $C up -d --no-deps --force-recreate app nginx
```
The rollback drops the new column. The database backup from Block 2 is the last resort.

## 4. UAT-time notes (for **execution**)

**Setup:**
- **Where:**
  - a desktop browser, signed in as an **Administrator**;
  - a **private (incognito) window** for the new person;
  - the phone with the app;
  - all at `https://company-staging.storm-ark.com`.
- **The test person** is created by you in UAT-36A-01/02:
  - staff record `[UAT36A] Nina Novak`, employee number `UAT36A-001`;
  - sign-in email `uat36a.nina@company-app.test`.
- **Passwords (important):**
  - **The temporary password** appears once on your Administrator screen. Click **Copy** and save it straight into your password manager as a new entry for `uat36a.nina@company-app.test`. Paste it **only** into the sign-in page's password field and the "Temporary password" field.
  - **The password you choose** for Nina in UAT-36A-03: make one in your password manager (at least 12 characters) and update the same entry.
  - **Never paste** either one into a chat, an AI tool or a terminal.
- **Report** each scenario as **PASS**, or **FAIL with what you saw**. Only you record a `PASS`.

**UAT-36A-01: Staff records** (Administrator)
1. **Admin → People.** "Staff records" and "Departments, teams and positions" are links (no "Coming in Phase 36").
2. **Staff records:** search `uat30b` (the list narrows), then set **Status: Separated** (only separated records, or "No staff records match.").
3. **New staff record** → **Add staff record** with nothing filled in: "Give the employee number.", "Give the first name.", "Give the last name.".
4. Fill in:
   - employee number `UAT36A-001`;
   - first name `[UAT36A] Nina`;
   - last name `Novak`;
   - company email `uat36a.nina@company-app.test`;
   - a department of your choice;
   - Manager: search `Ben`, choose **Ben UAT30B-Cruz**.
5. Click **Add staff record**. Her record opens with "Staff record added.", the manager, and **"No sign-in account."**.
6. **Edit** → preferred name `Nin` → **Save changes**: "Changes saved.", and the preferred name shows.

**UAT-36A-02: Create her sign-in account** (Administrator)
1. On her record, click **Create a sign-in account**. Name and email are filled in from the record. Leave Role as **Staff**.
2. Click **Create account**. A panel says **"Account created … It won't be shown again."** with the temporary password and **Copy**.
3. Copy it into your password manager (see Setup). Click **Done**.
4. **Users and roles** lists her with **Must choose a password** and a **Staff record** link.
5. **Administration → Audit log**, action `user.created`: one entry for her account. Open its **Details**: name, email, role and staff, and **no password**.

**UAT-36A-03: First sign-in** (phone, then the private window)
1. **Phone app:** sign in as `uat36a.nina@company-app.test` with the temporary password. It is refused with **"Set your password on the web first, then sign in here."**.
2. **Private window:** sign in with the same. **Choose your password** opens.
3. In the address bar, open `/app`. You come back to **Choose your password**.
4. Try each of these; each is refused with its message:
   - a new password `short`;
   - two different new passwords;
   - `uat36a.nina@company-app.test` as the password.
5. Enter the temporary password and your chosen password twice. **Set password and continue** opens the portal with **"Your password is set."**.

**UAT-36A-04: Her own password** (phone, then the private window)
1. **Phone app:** sign in with her chosen password. It works.
2. **Private window:** **My profile → Change password**. With a wrong current password: "That isn't your current password."
3. Change it properly (update your password manager first): **"Password changed."**.
4. **Phone app:** within a minute, or at its next action, it returns to **Login** (its sign-in ended). Sign in with the new password: it works.

**UAT-36A-05: Reset password** (Administrator, with her signed in on the phone and in the private window)
1. **Users and roles** → her row → **Reset password** → the dialog → **Reset password**. A panel shows a **new temporary password** once. Save it.
2. Your own row offers no **Reset password** and no **Suspend**.
3. **Private window:** reload. You are back at the sign-in page.
4. **Phone:** at its next action it returns to **Login**.
5. Sign in on the web with the new temporary password. **Choose your password** opens again; choose a new one (UAT-36A-03, step 5).

**UAT-36A-06: Suspend and reactivate** (Administrator)
1. Her row → **Suspend** → the dialog → **Suspend**: "… is suspended and signed out everywhere.", with a **Suspended** chip.
2. **Private window and phone:** both are signed out. Signing in shows the usual "These credentials do not match our records."
3. **Reactivate** → **Reactivate**: "… can sign in again." She signs in on the web and the phone with her password.
4. **Audit log**, action `user.status_changed`: two entries (active → suspended, suspended → active).

**UAT-36A-07: Status, check-ins and delete** (Administrator)
1. Open **Lia UAT30B-Ramos**'s record (Staff records, search `Lia`).
2. **Correct the status** → **In the field** → **Set status**: "Status changed." The phone app (as Lia) shows In the field on My status.
3. **Check-ins:** **Remove** one → **Remove**: "Check-in removed.", and it is gone from the list.
4. **Delete** on Lia's record → **Delete**. It is refused with a reason, such as "still has … and cannot be deleted. To keep the history, set their status to Separated instead."
5. **New staff record** `UAT36A-002`, `[UAT36A] Temp`, `Delete`. On its record, **Delete** → **Delete**: "Staff record deleted.", and it is gone from the list.

**UAT-36A-08: Departments, teams and positions; access; phone and accessibility**
1. **People → Departments, teams and positions** → **New department** `[UAT36A] Dept` → **Add department**. Then **Edit** it to `[UAT36A] Department` → **Save changes**.
2. **Teams** tab → **New team** `[UAT36A] Team`, department `[UAT36A] Department` → **Add team**.
3. **Departments** tab → **Remove** `[UAT36A] Department`: refused, "…still has teams… To keep it out of use, set it to Inactive instead."
4. **Positions** tab → **New position** `[UAT36A] Position` → **Add position**, then **Remove** it: "Position removed."
5. Sign in as `uat30b.manager` and open `/admin/people/staff`: **403**.
6. On a **phone browser**, with **dark mode**, 200% zoom and keyboard only (on the computer), open the new screens: no sideways scrolling, every control reachable, the focus visible.

**Afterwards:** report each scenario as **PASS**, or **FAIL with what you saw**. The `[UAT36A]` records stay on staging. Cleaning them up needs its own authorization.
