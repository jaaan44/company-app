# Phase 37 — UAT Preparation (Web Hardening for Staff on Phones — Deployment and UAT)

*Operator runbook. No AI session has run anything in this file against the staging VPS; this AI session cannot reach it. Every step marked **[Operator]** is pending until its output is recorded.*

**Status:** UAT-37-01…08 are `NOT RUN`.
- **No APK, no migration, no script.** The Flutter app is paused (DEC-067).
- **No new account is created.** The scenarios use the existing UAT accounts:
  - Lia `uat30b.staff` and Ben `uat30b.manager`;
  - Nina `uat36a.nina@company-app.test` from 36A;
  - your Administrator.

  Their passwords are in your password manager.

**How each operator step is written:**
- **Where:** the machine, the user and the directory.
- **Run:** the exact commands, one block at a time.
- **Expected:** what a good result looks like, and when to **STOP**.
- **Paste back:** what to send to the AI session. **Never paste** a password, anything from your password manager, `.env.staging`, or anything containing `MYSQL_ROOT_PASSWORD`.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **UAT source baseline (deploy)** | `bbe729172bae423dc535e582a6ce3ded8c78689a` | PR #135 merge into `main`: Phase 37. |
| Running staging implementation (before §2) | `0797e0c235645df8534243a535205de7dec0977b` | Phases 36B–36D (confirmed). |

**What changes between them:**
- **The nginx config** (`docker/nginx/default.conf`) adds gzip, a one-year cache for `/build/` and four security headers, so **both images are rebuilt**. CI already checked the built nginx image.
- **The web app** gains:
  - "Keep me signed in";
  - error pages;
  - the offline banner;
  - the phone fixes;
  - the link and unlink account screens.

  The JS and CSS file names change.
- **The API** caps `per_page` at 100, takes `%` and `_` literally in searches, and uses company days in the Audit log.
- **No migration.** `composer.lock`, `package-lock.json`, the Dockerfiles, the compose file and the environment variables are unchanged.

## 2. Staging deployment — **[Operator, VPS]**

**Where:** the VPS, as `deploy`, in `/home/deploy/company-app/company-app-build/company-app`. Run each block separately.

**Block 1: get the code**
```sh
cd /home/deploy/company-app/company-app-build/company-app
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
git fetch origin
git status --porcelain
git merge --ff-only bbe729172bae423dc535e582a6ce3ded8c78689a
git rev-parse HEAD
```
**Expected:**
- `git status --porcelain` prints nothing;
- then `Updating 0797e0c..bbe7291` and `Fast-forward`;
- the last line is `bbe729172bae423dc535e582a6ce3ded8c78689a`.

**If the `Updating` line starts from anything other than `0797e0c`: STOP** and paste it.

**Block 2: back up the database** (a precaution; nothing changes the schema)
```sh
$C exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > /home/deploy/backups/company-app-$(date +%Y%m%d-%H%M%S).sql
ls -lt /home/deploy/backups/company-app-*.sql | head -2
tail -c 200 "$(ls -t /home/deploy/backups/company-app-*.sql | head -1)" | grep -- '-- Dump completed'
```
**Expected:** the newest file is from now and not empty, and a `-- Dump completed on …` line appears. **Without it: STOP.**

*Tip: paste the first line (`$C exec mysql …`) as **one line**, exactly as shown.*

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
- `company-app-api` and `company-app-nginx` created just now and `Up`;
- `company-app-mysql` `healthy`.

**Block 5: smoke tests and the header check** (read-only)
```sh
B=https://company-staging.storm-ark.com
for p in app app/tasks admin; do curl -s -o /dev/null -w "$p %{http_code} -> %{redirect_url}\n" $B/$p; done
curl -s -o /dev/null -w "api staff %{http_code}\n" -H "Accept: application/json" $B/api/v1/staff
curl -s -w "\nerror page %{http_code}\n" $B/app/nothing-here | grep -o "<title>[^<]*\|error page.*"
curl -s $B/login | grep -c "Keep me signed in on this device"
CSS=$($C exec -T nginx sh -c 'basename $(ls /var/www/html/public/build/assets/*.css | head -1)')
echo "css file: $CSS"
echo "--- container nginx, direct:"
curl -s -D - -o /dev/null -H "Accept-Encoding: gzip" http://127.0.0.1:8012/build/assets/$CSS | grep -i "^HTTP\|content-encoding\|cache-control\|x-content-type\|referrer-policy\|x-frame\|permissions-policy"
echo "--- public site, sign-in page:"
curl -s -D - -o /dev/null $B/login | grep -i "^HTTP\|x-content-type\|referrer-policy\|x-frame\|permissions-policy"
```
**Expected:**
- **The three pages:** `app`, `app/tasks` and `admin` each show `302 -> https://company-staging.storm-ark.com/login`.
- `api staff 401`.
- **The error page:** `<title>Page not found — Company App` and `error page 404`.
- The count line is `1`, from the "Keep me signed in" checkbox.
- `css file: app-….css`.
- **Container nginx, direct:**
  - `HTTP/1.1 200 OK`;
  - `Content-Encoding: gzip`;
  - `Cache-Control: public, max-age=31536000, immutable`;
  - `X-Content-Type-Options: nosniff`;
  - `Referrer-Policy: strict-origin-when-cross-origin`;
  - `X-Frame-Options: SAMEORIGIN`;
  - `Permissions-Policy: camera=(self), geolocation=(self), …`.
- **Public sign-in page:** an `HTTP/2 200` line and the same four security headers.
  - Seeing a header **twice** is not a failure: the host nginx or Cloudflare may add its own copy. Just paste it.

**Anything else: STOP** and paste the output.

**Paste back:** the output of Blocks 1–5. For the build, its last few lines are enough.

## 3. If something goes wrong (rollback) — **[Operator, VPS]** only if the AI session asks

```sh
git checkout --detach 0797e0c235645df8534243a535205de7dec0977b
$C build app nginx && $C up -d --no-deps --force-recreate app nginx
```
Nothing in the database needs undoing.

## 4. UAT-time notes (for **execution**)

**What you need:**
- **your own iPhone (Safari)** and **your own Android phone (Chrome)**, each using the staff portal at `https://company-staging.storm-ark.com` (not the Flutter app);
- **a computer** with a desktop browser for the Administrator (and for Nina in 37-06).

Sign in as **Lia** (`uat30b.staff`) on the phones, and as your **Administrator** on the computer. Paste passwords from your password manager into the sign-in fields only, never anywhere else.

**Report** each scenario as **PASS**, or **FAIL with what you saw** (a screenshot helps). Only you record a `PASS`.

**UAT-37-01: Keep me signed in** (both phones, then the computer)
1. On each phone, open the site: the sign-in page shows **Keep me signed in on this device**, unticked, with "For 30 days. Leave it off on a shared phone or computer." under it.
2. Tap Email: the keyboard is an email keyboard (with `@`) and does not capitalise the first letter. Its key says **Next**; in Password it says **Go**.
3. Tick **Keep me signed in** and sign in as Lia: Home opens.
4. **Close the browser completely** (swipe it away from the app switcher). Reopen it later, ideally after 2 hours or the next day, and go to the site: you are **still signed in**, on Home.

**UAT-37-02: The eight everyday tasks, one-handed** (each phone, as Lia)
1. **Home** loads.
2. **Tasks** → open a task → tap **In progress**: "Status changed." *(Choose a different task on the second phone.)*
3. **More → Work logs → +**: log 30 minutes today, description `[UAT37] log` → Save: it's in the list.
4. **More → Leave → +**: a day next month, reason `[UAT37] leave` → Request: it shows **Pending**.
5. **More → Reports → +** (service report): any client, today, work done `[UAT37] service`. On its page, **Add a photo or PDF** → take or choose a photo: it **uploads straight away** ("Uploading…"), with **no Upload button**, and appears under Photos and files.
6. **More → Reports → Report an incident**: **What happened** and the other long fields are **multi-line boxes**. **Severity** is four large pills, and the chosen one is highlighted. Description `[UAT37] incident` → Report incident.
7. **Messages**: open a conversation and send `[UAT37] hello`.
8. Throughout: nothing needs pinch-zoom; tapping a field doesn't zoom the page; the keyboard never hides the button you need (scroll if needed); nothing scrolls sideways.

**UAT-37-03: Offline** (one phone, as Lia)
1. Open **Work logs → +** and type a description.
2. Turn on **airplane mode**: a red bar appears at the top, "You're offline. Changes won't be saved until you reconnect."
3. Tap **Save**: nothing is saved, and the red bar stays. Your text is still in the form.
4. Turn airplane mode **off**: the bar goes. Tap **Save**: it saves.

**UAT-37-04: An expired page** (one phone, as Lia, **without** Keep me signed in)
1. If the phone is still signed in from 37-01, **sign out** (More → Log out), then sign in **without** ticking Keep me signed in.
2. Open a task and leave that tab open.
3. Open a **second tab**, go to the site, and **log out** there (More → Log out).
4. Go back to the first tab and tap **In progress** (or any button): **no browser pop-up** appears. You land on the sign-in page with "Please sign in to continue."
5. Sign in: you are back **on that task**, not on Home.

**UAT-37-05: Error pages** (a phone, then the computer)
1. As Lia on a phone, open `https://company-staging.storm-ark.com/app/nothing-here`: **Page not found**, in the app's look, with **Go to Home**. Tap it: Home opens.
2. As Lia, open `https://company-staging.storm-ark.com/admin`: **You can't open this page**, with **Go to Home**.
3. Switch the phone to **dark mode** and reload either page: it is dark and readable.

**UAT-37-06: Keep me signed in ends** (Nina's account; a phone and the computer)
1. On a phone, in a private tab, sign in as **Nina** (`uat36a.nina@company-app.test`) **with** Keep me signed in ticked.
2. **(a) Password change:** on the computer, sign in as Nina → **More → My profile → Change password**. Choose a new password: make it in your password manager first, update Nina's entry, and paste it only into the fields. Expect "Password changed." On the phone, close the private tab, open a new one and go to the site: you must **sign in again**. Sign in with the new password and Keep me signed in ticked.
3. **(b) Suspension:** on the computer, as your **Administrator**, go to **Admin → Administration → Users and roles** → Nina → **Suspend**, then **Reactivate**. On the phone, reload: you are **signed out** and must sign in again.

**UAT-37-07: Backoffice on the computer** (Administrator)
1. **Admin → People → Staff records** → open **[UAT36A] Nina Novak**: her sign-in account is shown with **Unlink**.
2. **Unlink** → the dialog explains the account stays → **Unlink**: "Sign-in account unlinked." The record now says **No sign-in account**, with **Or link an existing account**.
3. Type `nina` in that search: `uat36a.nina@company-app.test` is listed. Click it: "Sign-in account linked.", and the account is shown again. *(This puts things back as they were.)*
4. **Administration → Audit log**, with **From** and **To** set to today: the link and unlink just made are listed, and nothing from before midnight Manila time.
5. **Operations → Incidents** → open your `[UAT37] incident` → **Assign** to Lia → **Start investigation** → **Resolve** with nothing filled: "Describe the resolution before resolving this incident." *(You may stop here, or resolve it with a resolution and a corrective action.)*

**UAT-37-08: Searching and speed**
1. In the portal on a phone: **More → Staff directory**, search `%`: **no one** (or only names containing `%`), not the whole list. Search `_`: the same.
2. On mobile data (Wi-Fi off), open a few pages: it feels as fast as before, or faster.
3. §2 Block 5's header check (pasted by the operator) showed `Content-Encoding: gzip` and the four security headers.

**Afterwards:** report each scenario. The `[UAT37]` records stay on staging; cleaning them up needs its own authorization.
