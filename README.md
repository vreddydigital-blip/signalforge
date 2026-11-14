# SignalForge — InfinityFree / PHP edition

A/B testing and CRO dashboard. The website is a React app (built to static files). The backend is plain PHP + MySQL,
so it runs on InfinityFree's free hosting.

```
client/            React dashboard (source)
infinityfree/      PHP backend + .htaccess   -> becomes htdocs/api/*
  api/index.php      dashboard API (login, experiments, results)
  api/orchestrator.php  multi-agent orchestration engine (5 agents, durable task queue)
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

## Multi-agent orchestration

The **Agent mesh** page runs five specialist agents in parallel over your live experiment data:

| Agent | What it does |
|---|---|
| Signal Analyst | Scores every experiment for the probability a challenger beats control |
| Segment Scout | Splits exposures by device, paid traffic and repeat visits to find who benefits |
| Tracking Auditor | Checks event freshness, conversion coverage and traffic-split integrity |
| Sample Planner | Estimates the visitors and days each active test still needs |
| Brief Writer | Turns the weakest result into a framework-grounded brief for the next test |

**How it runs on shared hosting.** InfinityFree has no background workers and no process forking, so a run is a
durable task queue rather than a thread pool. `orchestrator.start` writes one run row plus one task row per agent;
the dashboard then calls `orchestrator.tick`, which claims the next two queued tasks, executes them against your
real `experiments` / `variants` / `visitor_events` rows, and saves each agent's JSON output. The board therefore
shows several agents progressing side by side. Close the tab mid-run and the run resumes where it stopped when you
reopen the page — nothing is lost, because every task's state is in MySQL.

Every number an agent reports is computed from your own rows. An agent with no data says so instead of inventing a
figure, and a segment with no control conversions reports "no lift to report" rather than a misleading 0%.

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
- The orchestration layer is real (it reads and writes MySQL), but it is not a general-purpose LLM agent runtime: the
  five agents are deterministic analyses of your experiment data, not free-form reasoning agents. Each tick is one HTTP
  request, so a run advances only while the Agent mesh page is open.

## Security notes
- `account.setPlan` lets a signed-in user change their own plan. That is intentional for this demo build (the plan
  selector in the header is a preview control). If you sell plans, remove that route and set `plan` server-side only.
- `api/config.php` holds your database password. It is a PHP file, so requesting it directly returns a blank page,
  but never commit real credentials to a public repository — edit it on the server after upload.
