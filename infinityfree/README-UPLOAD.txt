SignalForge - deployment guide
==============================

WHAT CHANGED AND WHY
--------------------
The app used to run on InfinityFree, with the database on a separate
InfinityFree MySQL server (sql210.infinityfree.com). That account was deleted
for inactivity, which took the database server with it. The hostname no longer
resolves in DNS, so every route that touches the database returned:

    Server error. Check api/config.php and that api/install.php has been run.

That message was misleading - config.php and install.php were both fine. The
database simply did not exist any more.

The code has been changed so this cannot happen quietly again:

  * The database connection is now configured by ENVIRONMENT VARIABLE
    (DATABASE_URL, or SF_DB_*), which is how a container host supplies it.
    A config file still works, but the hardcoded InfinityFree path is no
    longer the only option.
  * A failed connection now says WHY - DNS, bad password, missing database,
    TLS - instead of a generic 500.
  * api/health.php is a new diagnostic page. Open it first whenever something
    is wrong.
  * api/install.php now builds the COMPLETE schema (34 tables) in one run.
    Previously it created 8, and the v2 features needed a second, undocumented
    step (api/schema2.php) - so a "successful" install still left half the app
    returning 500s.

You must still create the database and set the credentials yourself. Nothing
here invents a database for you.


------------------------------------------------------------
OPTION A - CONTAINER HOST (recommended)
------------------------------------------------------------
Use this if you want the app and its database to live together, so one cannot
disappear without the other.

1. Push this folder to a git repository.

2. On Render (or Railway / Fly), create a new Web Service from that repo.
   The included Dockerfile builds it. Render will detect it automatically.

3. Add a MySQL-compatible database. Options that have a free tier:
     - TiDB Cloud Serverless  (MySQL-compatible, needs TLS)
     - Aiven for MySQL
     - Railway MySQL
   Copy its connection string.

4. Set these environment variables on the web service:
     DATABASE_URL   = mysql://USER:PASSWORD@HOST:PORT/DATABASE
     SF_SECRET      = <openssl rand -hex 32>
     SF_INSTALL_KEY = <any long random string>
   If the provider requires TLS (TiDB Cloud does), also set:
     SF_DB_SSL      = true
     SF_DB_SSL_CA   = /path/to/ca.pem   (only if you mount the CA bundle)

5. Deploy. Then open, once:
     https://YOUR-APP/api/install.php?key=YOUR_INSTALL_KEY
   You should see "OK - tables are ready." followed by the v2 schema lines.

6. Open:
     https://YOUR-APP/api/health.php
   Every check should read "ok": true.

7. Register an account and confirm the dashboard loads.


------------------------------------------------------------
OPTION B - SHARED HOST WITH PHP + MYSQL (InfinityFree, cPanel, etc.)
------------------------------------------------------------
Use this if you already have a host with a working MySQL database.

1. In your host's control panel, create a MySQL database and a user, and note:
     host, database name, username, password

2. Upload EVERYTHING in this folder into the web root (htdocs / public_html),
   keeping the structure:
       index.html
       tracker.js
       .htaccess
       assets/...
       api/config.php
       api/index.php
       api/install.php
       api/health.php
       api/lib.php
       api/schema2.php
       api/orchestrator.php
       api/remote.php
       api/track.php
   Tip: upload the zip, use the file manager's "Extract", then delete the zip.

3. Put the credentials OUTSIDE the web root. Copy sf-config.example.php to
   the directory ABOVE htdocs and rename it sf-config.php, then fill it in:
       <parent of htdocs>/sf-config.php
   On InfinityFree that is /home/uploads/sf-config.php.
   If you truly cannot write above the web root, you may instead edit
   api/config.php and return an array from it - but that file is inside the
   web root, so it is the least safe option.

4. Set a real 'secret' and 'install_key' in that file. Do not leave the
   CHANGE-ME placeholders. Generate a secret with: openssl rand -hex 32

5. Open, once:
       https://YOUR-SITE/api/install.php?key=YOUR_INSTALL_KEY
   You should see "OK - tables are ready." and the v2 schema lines.

6. DELETE api/install.php once the tables exist.

7. Open https://YOUR-SITE/api/health.php and confirm every check is green.

8. Register an account and confirm the dashboard loads.


------------------------------------------------------------
IF SOMETHING IS WRONG
------------------------------------------------------------
Open api/health.php first. It checks, in order:

  pdo / pdo_mysql        - are the PHP extensions present?
  database_configured    - did it find any connection details at all?
  session_secret         - is SF_SECRET set and long enough?
  database_connection    - can it actually connect? (says why if not)
  tables                 - are all 34 tables present? (names the missing ones)

The "nextStep" field at the bottom tells you the first thing to fix.

Add ?verbose=1 to also see which config files were read, which environment
variables were set, and the full table list. It never prints a password.

Common cases:

  "The hostname does not resolve in DNS"
      The database server no longer exists. Create a new database and point
      DATABASE_URL at it. This is what happened to the old InfinityFree setup.

  "Access denied for user"
      Wrong username or password in DATABASE_URL / db_user / db_pass.

  "Unknown database"
      The database name is wrong, or you have not created it yet.

  "MISSING: activity_log, ..."
      The install did not complete. Re-run api/install.php?key=...

  "MISSING or too short" for session_secret
      Set SF_SECRET to a random 32+ character string. Without it, logins
      cannot be signed and every session breaks.

  TLS / certificate errors
      Set SF_DB_SSL_CA to the provider's CA bundle, or SF_DB_SSL=false for a
      local server that does not use TLS.


------------------------------------------------------------
ORCHESTRATION SERVICE (optional)
------------------------------------------------------------
The multi-agent orchestration runs on a separate Render service:
    https://signalforge-orchestrator.onrender.com

Set SF_ORCHESTRATOR_URL and SF_ORCHESTRATOR_TOKEN to use it. Mode 'auto'
falls back to the built-in PHP engine when it is unreachable, so the app keeps
working either way. The free tier sleeps after 15 minutes idle; the first
request after a sleep can take up to ~60 seconds to wake it.

To wake it before a demo, open /health on that service in a browser.


------------------------------------------------------------
DATA: WHAT IS LOST, WHAT MIGHT BE RECOVERABLE
------------------------------------------------------------
The old database is gone, so anything that lived only in it is lost:

  LOST (unless you have a dump):
    - user accounts and password hashes
    - experiments, variants and their settings
    - visitor events and all recorded results
    - workspace settings, integrations, notes, tags
    - agent run history

  NOT LOST:
    - the application code (this package)
    - the static front end and assets
    - the orchestration service and its configuration

  POSSIBLY RECOVERABLE:
    - If you downloaded a database dump from the old host's /backups/ folder
      at any point, that dump can be imported into the new database and your
      users and experiment data come back. Check your downloads before you
      rebuild from scratch.
    - If you have the old MySQL credentials and the server were still up, a
      mysqldump would work - but the host no longer resolves, so it is not.

  After installing, you will need to register a new account. If you restore a
  dump, existing accounts and their passwords work as before.
