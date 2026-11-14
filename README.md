# SignalForge — InfinityFree / PHP edition

A/B testing and CRO dashboard. The website is a React app (built to static files). The backend is plain PHP + MySQL,
so it runs on InfinityFree's free hosting.

```
client/            React dashboard (source)
infinityfree/      PHP backend + .htaccess   -> becomes htdocs/api/*
  api/index.php      dashboard API (login, experiments, results)
  api/track.php      public tracking endpoint used by tracker.js
  api/install.php    one-time table creator (delete after use)
  api/config.php     your database details and secrets
client/public/tracker.js   the snippet script customers add to their site
server/, drizzle/, Dockerfile   old Node backend - NOT used on InfinityFree, safe to ignore/delete
```

## Deploy on InfinityFree

### 1. Create the database
InfinityFree Client Area -> your hosting account -> **Control Panel** -> **MySQL Databases** -> create a database named
`signalforge`. Note four values: **MySQL hostname** (like `sql123.infinityfree.com`), **database name** (starts with `if0_`),
**username** (`if0_...`) and your **password** (the account/vPanel password).

### 2. Build the website files (pick one)
**A. No tools needed - GitHub builds it for you**
1. Create a GitHub repository and upload this project.
2. Open the **Actions** tab -> **Build for InfinityFree** -> **Run workflow** (it also runs on every push).
3. When it finishes, open the run and download the artifact **signalforge-infinityfree** (a zip). Unzip it.

**B. On your computer** (Node 22 + pnpm installed)
```
pnpm install
pnpm build:infinityfree
```
The ready-to-upload folder is `dist/infinityfree`.

### 3. Upload
Connect with an FTP client (FileZilla; FTP details are in the InfinityFree account page) or the online File Manager.
Delete the default files inside `htdocs`, then upload **everything inside the folder from step 2** into `htdocs`
(you should see `index.html`, `assets/`, `tracker.js`, `.htaccess` and `api/` directly in htdocs).

### 4. Configure
Open `htdocs/api/config.php` (File Manager -> edit) and fill in the four database values, a long random `secret`,
and an `install_key`.

### 5. Create the tables
Visit `https://YOUR-SITE/api/install.php?key=YOUR_INSTALL_KEY`. You should see "OK - tables are ready."
Then **delete `api/install.php`** from htdocs.

### 6. Use it
Open your site, create an account, create an experiment, launch it, and copy the snippet from **Tracking setup**
into the `<head>` of the page you want to test.

## Using the tracker
- Experiments run on the page URL you entered; only experiments set to **Active** are served.
- Each visitor is assigned a variant (stable per visitor, honours the traffic %). `<html>` gets the classes
  `sf-exp-<experimentId>` and `sf-exp-<experimentId>-v<variantId>`, and `SignalForge.getVariant(id)` returns the assignment.
- Conversions: add `data-sf-conversion="Click on primary CTA"` to an element (text must match the experiment's
  conversion event) or call `SignalForge.track("Click on primary CTA")`. `"conversion"` matches every experiment.
- Per-variant CSS/JS is supported by the API (`experiments.updateVariantCode`) but has no editor screen yet.

## Known limits of free hosting
- InfinityFree shows a browser-check page to requests that lack its cookie. The snippet and tracking calls add `?i=1`,
  which is InfinityFree's documented way to skip that check. I could not test this against a live account, so verify it
  with the browser dev tools (Network tab) on your own page; if `tracker.js` or `track.php` return HTML instead of
  JavaScript/JSON, tracking is being blocked by the host.
- InfinityFree enforces daily hit and CPU limits; a high-traffic site will exceed them.
- Needs PHP 7.4 or newer (choose it in the control panel if offered) and MySQL with PDO (standard).
- The Insights models, Intelligence Center panels, workspace settings and tracking-health text are still static UI.

## Security notes
- `account.setPlan` lets a signed-in user change their own plan. That is intentional for this demo build (the plan
  selector in the header is a preview control). If you sell plans, remove that route and set `plan` server-side only.
- `api/config.php` holds your database password. It is a PHP file, so requesting it directly returns a blank page,
  but never commit real credentials to a public repository — edit it on the server after upload.
