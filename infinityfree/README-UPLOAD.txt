SignalForge for InfinityFree
============================

This package is already configured to talk to the live orchestration service:
  https://signalforge-orchestrator.onrender.com

The API token is already filled in at api/config.php -> orchestrator_token.
Do NOT post this zip or api/config.php publicly - it contains that token.

------------------------------------------------------------
UPLOAD STEPS
------------------------------------------------------------
1. Log in to InfinityFree -> Control Panel -> Online File Manager
   (or connect with FTP using the credentials in your control panel).

2. Open the htdocs folder. Delete InfinityFree's default files
   (index2.html, and any default index.html) so they don't shadow the app.

3. Upload EVERYTHING inside this zip into htdocs, keeping the structure:
      htdocs/index.html
      htdocs/tracker.js
      htdocs/.htaccess
      htdocs/assets/...
      htdocs/api/config.php
      htdocs/api/index.php
      htdocs/api/install.php
      htdocs/api/lib.php
      htdocs/api/orchestrator.php
      htdocs/api/remote.php
      htdocs/api/track.php

   Tip: upload the zip, then use the file manager's "Extract" on it,
   then delete the zip. That preserves the folder structure.

4. Edit htdocs/api/config.php and fill in your database values:
      db_host, db_name, db_user, db_pass
   (Control Panel -> MySQL Databases). Leave the orchestrator_* values as they are.

5. Set a real 'secret' and 'install_key' in the same file
   (any long random text). Do not leave the CHANGE-ME placeholders.

------------------------------------------------------------
CREATE THE TABLES (first install only)
------------------------------------------------------------
6. Visit:
      https://YOUR-SITE.infinityfreeapp.com/api/install.php?key=YOUR_INSTALL_KEY
   You should see "OK - tables are ready."

   This creates all 8 tables, including agent_runs and agent_tasks.
   The schema already contains the engine and remoteId columns, so a fresh
   install needs no migration. If you installed an EARLIER version of
   SignalForge, re-running install.php is still safe: it uses
   CREATE TABLE IF NOT EXISTS and adds any missing columns.

7. DELETE api/install.php once the tables exist.

------------------------------------------------------------
VERIFY IT WORKS
------------------------------------------------------------
8. Open https://YOUR-SITE.infinityfreeapp.com and register an account.

9. Go to the "Agent mesh" page. The engine strip at the top should say
   the LangGraph service is reachable (green). If it says "asleep" or
   "unreachable", see the cold-start note below.

10. Click "Run orchestration". All five agents should complete and a
    combined verdict should appear.

------------------------------------------------------------
IMPORTANT: RENDER FREE-TIER SLEEP
------------------------------------------------------------
The Render service sleeps after 15 minutes with no traffic. The first
request after a sleep can take up to ~60 seconds to wake it.

What this package does about it:
  - The run call waits up to 60 seconds (orchestrator_timeout), so a cold
    start usually still succeeds.
  - The dashboard's health probe only waits 8 seconds
    (orchestrator_health_timeout), so the page never freezes for a minute.
    It may briefly show "asleep" - that is expected, not an error.
  - Mode is 'auto': if the service is unreachable, SignalForge falls back
    to its built-in PHP engine so the site keeps working. Runs made that
    way are tagged engine=local.

To wake the service before a demo, just open
  https://signalforge-orchestrator.onrender.com/health
in a browser and wait for the JSON to appear.

If you would rather see a hard error than a silent local fallback, change
orchestrator_mode to 'remote' in api/config.php.

------------------------------------------------------------
TROUBLESHOOTING
------------------------------------------------------------
- "The orchestration service is unreachable" -> the Render service is
  asleep or down. Open /health in a browser to wake it, then retry.
- Agents complete but numbers look empty -> your workspace has no traffic
  yet. Send some events through tracker.js first.
- 401 from the service -> the token in api/config.php does not match
  SIGNALFORGE_API_TOKEN in Render. Fix it in Render, then update config.php.
- Blank page -> check that .htaccess uploaded (it is a hidden file; some
  FTP clients hide dotfiles).
