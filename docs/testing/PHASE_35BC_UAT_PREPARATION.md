# Phases 35B + 35C — UAT Preparation (Admin Backoffice and Roles & Permissions — Deployment and UAT)

*Operator runbook. No AI session has run anything in this file against the staging VPS; this AI session cannot reach it. Every step marked **[Operator]** is pending until its output is recorded.*

**Status:** UAT-35BC-01…08 are `NOT RUN`.
- **No APK is needed.** 35B and 35C change no mobile code.
- **No migration, no data script, and no account or password is created.**
- **Accounts for UAT:** the existing UAT30B accounts (`uat30b.staff`, `uat30b.manager`) and an Administrator account, all already in your password manager.

**How each operator step is written:**
- **Where:** the machine, the user and the directory.
- **Run:** the exact commands, one block at a time.
- **Expected:** what a good result looks like, and when to **STOP**.
- **Paste back:** what to send to the AI session. **Never paste** a password, anything from your password manager, `.env.staging`, or anything containing `MYSQL_ROOT_PASSWORD`.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **UAT source baseline (deploy)** | `680444e31093f3aa5b9820ce88642be8a49d4c65` | PR #119 merge into `main`: Phases 35B + 35C. |
| Running staging implementation (before §2) | `999380e80dc0f8b07347ae318a20be4f771e625e` | Phase 35A. |

**What changes between them:**
- **Web:**
  - the Admin Backoffice at `/admin` (`/home` now redirects there);
  - the Dashboard, the section pages, Roles & permissions, Users and roles, and the Audit log;
  - CSS and JS (new asset names).
- **API:** four new endpoints behind `authorization.manage` (`/api/v1/roles…`, `/api/v1/users…`).
- Everything else in between is documentation.
- **No migration** (still 46). `composer.lock`, `package-lock.json`, the Dockerfiles, the nginx config and the environment variables are unchanged.

## 2. Staging deployment — **[Operator, VPS]**

**Where:** the VPS, as `deploy`, in `/home/deploy/company-app/company-app-build/company-app`. Run each block separately.

**Block 1: get the code**
```sh
cd /home/deploy/company-app/company-app-build/company-app
git status --porcelain
git fetch origin
git merge --ff-only 680444e31093f3aa5b9820ce88642be8a49d4c65
git rev-parse HEAD
```
**Expected:**
- `git status --porcelain` prints nothing;
- then `Updating 999380e..680444e` and `Fast-forward`;
- the last line is `680444e31093f3aa5b9820ce88642be8a49d4c65`.

**If the `Updating` line starts from anything other than `999380e`: STOP** and paste it.

**Block 2: back up the database**
```sh
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
$C exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  > /home/deploy/backups/company-app-$(date +%Y%m%d-%H%M%S).sql
ls -lt /home/deploy/backups/company-app-*.sql | head -3
tail -c 200 "$(ls -t /home/deploy/backups/company-app-*.sql | head -1)" | grep -- '-- Dump completed'
```
**Expected:** the newest file is from now and not empty, and a `-- Dump completed on …` line appears.

**Block 3: build, on its own**
```sh
$C build app nginx && echo "BUILD OK"
```
**Expected:** the last line is `BUILD OK`. **`ERROR` or `failed to solve`: STOP** and paste the error lines; the running site stays as it is.

**Block 4: restart and check**
```sh
$C up -d --no-deps --force-recreate app nginx
$C exec app php artisan migrate:status | tail -2
$C exec app php artisan config:cache
$C exec app php artisan route:cache
$C exec app php artisan view:cache
$C exec app sh -c 'ls public/build/assets'
$C exec nginx sh -c 'ls /var/www/html/public/build/assets'
$C ps
```
**Expected:**
- the last migrations show `Ran`, with none pending (still 46);
- the same **new** `app-….css` and `app-….js` names in both containers;
- in `$C ps`, `company-app-api` and `company-app-nginx` were both created seconds ago.

`--force-recreate` replaces nginx even where its image content is unchanged, so both containers are fresh. Here the CSS has changed anyway.

**Block 5: smoke tests**
```sh
B=https://company-staging.storm-ark.com
for p in admin admin/work admin/administration/roles app; do
  curl -s -o /dev/null -w "$p %{http_code} -> %{redirect_url}\n" $B/$p
done
curl -s -o /dev/null -w "home %{http_code} -> %{redirect_url}\n" $B/home
for p in roles users dashboard; do
  curl -s -o /dev/null -w "api $p %{http_code}\n" -H 'Accept: application/json' $B/api/v1/$p
done
CSS=$(curl -s $B/login | grep -o '/build/assets/app-[A-Za-z0-9_-]*\.css' | head -1); curl -s -o /dev/null -w "css %{http_code} %{content_type}\n" "$B$CSS"
```
**Expected:**
- `admin`, `admin/work`, `admin/administration/roles` and `app` each show `302 -> https://company-staging.storm-ark.com/login`;
- `home 302 -> https://company-staging.storm-ark.com/admin`;
- three `api … 401` lines;
- `css 200 text/css`.

**Anything else: STOP** and paste the output.

**Paste back:** the output of Blocks 1–5. For the build, its last few lines are enough.

## 3. UAT-time notes (for **execution**)

- **Where:** a computer browser and a phone browser at `https://company-staging.storm-ark.com`, plus the phone app.
- **Sign-in:** paste passwords from your password manager into the sign-in fields only.
- **Afterwards:** UAT-35BC-04 and 06 change a permission and a role. Both are put back within the scenario, and the audit log keeps a record of them.

**UAT-35BC-01: Dashboard** (computer, Administrator)
1. Sign in as your Administrator. In the portal's sidebar, click **Admin**. You land on **Dashboard** at `/admin`.
2. The cards show People (active staff), Clients, Projects (by status), Tasks (overdue in red, then by status), Work logged (hours this month), Leave (waiting for a decision), Service reports, Incidents (open, then by severity) and Coming up. Compare a few with what you know is on staging, for example the number of active staff and clients.
3. Type `https://company-staging.storm-ark.com/home` in the address bar. You end up on the same Dashboard.

**UAT-35BC-02: Period**
1. Set **From** to the 1st of last month and **To** to its last day, then click **Show**. The line under the form says **Period: …**, and Work logged, Leave approved, Service reports and Incidents by severity change to that month.
2. Set **To** earlier than **From**, then click **Show**. A red message appears under the dates.
3. Click **This month**. The line says **This month: …** again.

**UAT-35BC-03: Navigation and sections**
1. The sidebar lists **Dashboard, People, Clients, Work, HR, Operations, Communication, Reports, Administration**, and the page you're on is highlighted.
2. Open each of the eight sections. Each lists what it will hold, marked **Coming in Phase 36**. **Administration** instead lists **Roles & permissions**, **Users and roles** and **Audit log** as links.
3. Moving between sections doesn't reload the page; a thin indigo bar runs across the top.
4. **Staff portal** (bottom of the sidebar) takes you back to the portal.

**UAT-35BC-04: Roles & permissions, a real change** (computer, and a second browser or the phone)
1. **Administration → Roles & permissions.** At the top, **Always Administrators only** lists Access the Admin Backoffice and Manage users' roles and permissions. Neither has a checkbox anywhere.
2. In **Clients**, untick **Staff** on **View clients and contacts**. The bar at the bottom says **1 role changed, not saved yet**.
3. Click **Save changes**. The dialog lists **Staff — No longer: View clients and contacts**. Click **Save**: **"Permissions saved."**
4. In another browser (or a private window, or the phone's browser), sign in as `uat30b.staff`, then go to **More → Clients**. It says **"You don't have access to clients."**
5. Back in Roles & permissions, click **Reset to defaults**. The dialog from **Save changes** lists **Staff — Can now: View clients and contacts**. Click **Save**.
6. As `uat30b.staff`, reload **Clients**. The list is back.

**UAT-35BC-05: Roles & permissions, the ticking rules**
1. In **Tasks**, tick **Staff** on **Add tasks in any project**. **View all tasks** for Staff is ticked by itself.
2. Untick **Staff** on **View all tasks**. **Add tasks in any project** is unticked by itself.
3. Tick something else, then click **Undo changes**. Everything is back as saved, and **Save changes** is greyed out.

**UAT-35BC-06: Users and roles**
1. **Administration → Users and roles.** Your own account shows **"You can't change your own role."** and has no menu.
2. Search `uat30b.staff`. Set its role to **Manager**, then click **Change role**. The dialog asks **"Make … Manager?"**. Confirm: **"… is now Manager."**
3. Set it back to **Staff** the same way: **"… is now Staff."**

**UAT-35BC-07: Audit log**
1. **Administration → Audit log.** At the top are your changes from 04 and 06: `user.role_changed` (twice) and `role.permissions_updated` (twice), each with your name, the time and "from web".
2. Click **Details** on one. It shows **Before** and **After**, for example the role `staff` → `manager`, or the permissions with `removed` / `added`.
3. Choose **user.role_changed** in **Action**. Only those entries remain.

**UAT-35BC-08: Who can't, phone, dark, zoom, keyboard**
1. As `uat30b.manager` and as `uat30b.staff`, the portal shows **no Admin** link. Typing `…/admin` shows a **403** page.
2. **Phone** (Administrator): `/admin` shows a **menu** button (☰). It opens the list from the left; **×** or a link closes it. Nothing scrolls sideways.
3. **Dark mode**, and **200% zoom** on a computer: everything is readable, and nothing scrolls sideways.
4. **Keyboard only**: Tab reaches the sections, the checkboxes, **Save changes** and the dialogs' buttons, with a visible outline. **Esc** closes a dialog without saving.

**Afterwards:** report each scenario as **PASS**, or **FAIL with what you saw**. Only you record a `PASS`.
