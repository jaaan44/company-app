# Phase 34A — UAT Preparation (Staff Web Portal: Deployment and UAT)

*Operator runbook. No AI session has run anything in this file against the staging VPS; this AI session cannot reach it. Every step marked **[Operator]** is pending until its output is recorded.*

**Status:** UAT-34A-01…06 are `NOT RUN`. This runbook deploys 34A to staging and lists what to check. **No APK is needed**: 34A changes no mobile code. **No new account or password is created**: the scenarios use the four UAT30B accounts already in your password manager, plus your own administrator account.

**How each operator step is written:**
- **Where:** the machine, the user and the directory.
- **Run:** the exact commands, one block at a time.
- **Expected:** what a good result looks like, and when to **STOP**.
- **Paste back:** what to send to the AI session. **Never paste** a password, anything from your password manager, `.env.staging`, or anything containing `MYSQL_ROOT_PASSWORD`.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **UAT source baseline (deploy)** | `d3cf84d13f20a50f4bd67fec7195ac1ecb517820` | PR #89 merge into `main`: Phase 34A. |
| Running staging implementation (before §2) | `d6dd29a4d10fd39f6e72a998c9aa0880926e4010` | The combined 30B + 31–33 round. |

**What changes between them:**
- **Web:** the shared login, `/` → `/app`, the portal pages, and `AuditSource::Web`. **No migration** (still 45 Ran); `composer.lock` and the environment variables are unchanged.
- **Images:** both staging images gain a Node stage that builds the CSS and JS. **The first build downloads `node:22-alpine` and the npm packages, so it takes a few minutes longer.** CI built both images successfully on this exact change (the "Staging images" check on PR #89).

## 2. Staging deployment — **[Operator, VPS]**

**Where:** the VPS, as `deploy`, in `/home/deploy/company-app/company-app-build/company-app`. Run each block separately.

**Block 1: get the code**
```sh
cd /home/deploy/company-app/company-app-build/company-app
git status --porcelain
git fetch origin
git merge --ff-only d3cf84d13f20a50f4bd67fec7195ac1ecb517820
git rev-parse HEAD
```
**Expected:** `git status --porcelain` prints nothing; then `Updating d6dd29a..d3cf84d` and `Fast-forward`; the last line is `d3cf84d13f20a50f4bd67fec7195ac1ecb517820`.

**Block 2: back up the database**
```sh
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
$C exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  > /home/deploy/backups/company-app-$(date +%Y%m%d-%H%M%S).sql
ls -lt /home/deploy/backups/company-app-*.sql | head -3
tail -c 200 "$(ls -t /home/deploy/backups/company-app-*.sql | head -1)" | grep -- '-- Dump completed'
```
**Expected:** the newest file is from now and not empty, and a `-- Dump completed on …` line appears. The `Using a password on the command line` warning is normal.

**Block 3: build, on its own**
```sh
$C build app nginx && echo "BUILD OK"
```
**Expected:**
- the output shows `[app assets …] RUN npm ci` and `RUN npm run build` steps (and the same for nginx);
- the last line is **`BUILD OK`**.

**`ERROR` or `failed to solve`: STOP** and paste the error lines. Don't run Block 4; the running site stays as it is.

**Block 4: restart and check**
```sh
$C up -d --no-deps app nginx
$C exec app php artisan migrate:status | tail -3
$C exec app php artisan config:cache
$C exec app php artisan route:cache
$C exec app php artisan view:cache
$C exec app php artisan config:show scheduling.company_timezone
$C exec app sh -c 'ls public/build/manifest.json && ls public/build/assets'
$C exec nginx sh -c 'ls /var/www/html/public/build/assets'
ss -ltnp | grep 8012
$C ps
```
**Expected:**
- the last migrations show `Ran`, with none pending;
- the timezone line ends `Asia/Manila`;
- `public/build/manifest.json` is listed, followed by one `app-….css` and one `app-….js`, **the same two names in both containers**;
- the `ss` line shows only `127.0.0.1:8012`;
- in `$C ps`, `company-app-api` and `company-app-nginx` were both **created seconds or minutes ago**.

**Block 5: smoke tests** (same VPS, any directory)
```sh
B=https://company-staging.storm-ark.com
curl -s -o /dev/null -w 'root %{http_code} -> %{redirect_url}\n' $B/
curl -s -o /dev/null -w 'app %{http_code} -> %{redirect_url}\n' $B/app
curl -s -o /dev/null -w 'login %{http_code}\n' $B/login
CSS=$(curl -s $B/login | grep -o '/build/assets/app-[A-Za-z0-9_-]*\.css' | head -1); echo "css path: $CSS"
curl -s -o /dev/null -w 'css %{http_code} %{content_type}\n' "$B$CSS"
for p in me/home me/profile staff me/leave-requests conversations service-reports; do
  curl -s -o /dev/null -w "$p %{http_code}\n" -H 'Accept: application/json' $B/api/v1/$p
done
curl -s -o /dev/null -w 'up %{http_code}\n' $B/up
```
**Expected:**
- `root 302 -> https://company-staging.storm-ark.com/app`;
- `app 302 -> https://company-staging.storm-ark.com/login`;
- `login 200`;
- a `css path: /build/assets/app-….css` line, then `css 200 text/css`;
- `401` for the six API paths, so the mobile API is unchanged;
- `up 200`.

**Anything else: STOP.** For example, `https` turning into `http` in a redirect means the proxy settings are off. Paste the output.

**Paste back:** all the output from Blocks 1–5 except the long build log (its last ~5 lines are enough). None of it contains secrets.

## 3. UAT-time notes (for **execution**)

- **Browsers:** a desktop browser (Chrome, Edge or Firefox) **and** your phone's browser, at `https://company-staging.storm-ark.com`.
- **Accounts:** the UAT30B accounts from your password manager, plus your own administrator account. Paste a password only into the sign-in page's password field.
- Use a private or incognito window when switching accounts, or **Log out** first.

**UAT-34A-01: sign-in and landing**
1. Open `https://company-staging.storm-ark.com/`. It goes to the **Sign in to Company App** page.
2. Sign in as `uat30b.staff@company-app.test` (Lia) → **Home**: "Hello, Lia…". The header has **Home / Staff directory / My profile / Log out**, and **no "Admin"**.
3. Change the address to `/home` → **403**: staff can't open the Admin Backoffice. Go back.
4. Log out. Sign in as `uat30b.manager` (Ben) → Home, no Admin link.
5. Log out. Sign in as `uat30b.noprofile` → Home with "Your account isn't linked to an employee profile…".
6. Log out. Sign in as **your administrator account** → Home with an **Admin** link. Click it → the Admin placeholder page opens.

**UAT-34A-02: Home** (as Lia, desktop)

Compare it with the phone's Home for the same account:
- the greeting, and "Position · Department" and team;
- **Today · <weekday date>** with Manila times, or "Nothing scheduled for you today.";
- **Needs attention:** My tasks (open, overdue, due today), Messages and Notifications counts;
- **Latest announcements**, up to 3.

The numbers and titles match the phone. The tiles are not links yet; they become links in 34B and 34D.

**UAT-34A-03: My profile** (as Lia)
1. **My profile** shows Lia's name, **Work** (Manager, Company email, Company phone), **Employment** (Employee number, Hire date) and **Account** (Login email, Role), the same as the phone's My profile.
2. Click **Copy email** → "Copied" appears; paste it somewhere (for example the address bar) to check it's the company email. Do the same with **Copy phone**, if a phone is set.
3. As `uat30b.noprofile`: My profile shows "No staff profile is linked to this account." and the Account section.

**UAT-34A-04: Staff directory**
1. **Staff directory** lists active staff by name, with "Position · Department", 25 per page. If there are more than 25, **Previous / Next** and "Page 1 of N" appear.
2. Type `UAT30B` in the search box. After a moment, only the UAT30B people show, and the address bar shows `?q=UAT30B`.
3. Click **Kai UAT30B-Tan** → his page shows Position, Department, Team, Manager (Ben UAT30B-Cruz), and the company email and phone with Copy buttons.
4. Press the browser's **Back** button → the directory, still filtered by "UAT30B".
5. Search for `zzzz` → **No one matches "zzzz".** Click **Clear search** → the full list.

**UAT-34A-05: Log out, ended sessions, suspension**
1. As Lia, click **Log out** → the Sign in page. Press Back and reload → still the Sign in page.
2. **Ended session.** Sign in as `uat30b.colleague` (Kai) in the browser and leave Home open. On the VPS:
   ```sh
   cd /home/deploy/company-app/company-app-build/company-app
   C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
   $C exec app php artisan tinker --execute="echo DB::table('sessions')->where('user_id', App\Models\User::where('email','uat30b.colleague@company-app.test')->value('id'))->delete().' session(s) ended'.PHP_EOL;"
   ```
   **Expected:** `1 session(s) ended` (or more). In the browser, click **Staff directory** → the Sign in page.
3. **Suspension.** Sign in as Kai again and leave Home open. On the VPS:
   ```sh
   $C exec app php artisan tinker --execute="echo App\Models\User::where('email','uat30b.colleague@company-app.test')->update(['status'=>'suspended']).' suspended'.PHP_EOL;"
   ```
   **Expected:** `1 suspended`. In the browser, click **My profile** → the Sign in page with **"Your account is not currently active. Contact an administrator."** Signing in as Kai now fails with the generic "These credentials do not match our records."

   **Then restore Kai, which is required:**
   ```sh
   $C exec app php artisan tinker --execute="echo App\Models\User::where('email','uat30b.colleague@company-app.test')->update(['status'=>'active']).' active again'.PHP_EOL;"
   ```
   **Expected:** `1 active again`. Signing in as Kai works again.
4. On the Sign in page, enter Lia's email with a wrong password → **"These credentials do not match our records."**

**UAT-34A-06: dark mode, zoom, phone, keyboard**
1. **Dark mode:** switch your computer or phone to dark mode. Home, My profile, the directory and the Sign in page turn dark and stay readable.
2. **Zoom:** in the desktop browser press **Ctrl +** up to **200%**. The navigation wraps onto more lines, cards stack, there's **no sideways scrolling**, and everything can be clicked.
3. **Phone browser:** open the site on your phone and repeat 34A-02…04 briefly. The text is readable, buttons are easy to tap, and there's no sideways scrolling.
4. **Keyboard only** (desktop): reload Home and press **Tab**. The first thing focused is **"Skip to content"**; press Enter, and the next Tab goes into the page. Keep tabbing: every link and button gets a visible outline, in a sensible order, and Enter activates it.

**Afterwards:** nothing to clean up. Kai is active again, and no data was changed except his status, briefly, and his ended sessions. Report each scenario as **PASS**, or **FAIL with what you saw**. Only you record a `PASS`.
