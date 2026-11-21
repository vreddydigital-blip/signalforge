#!/bin/sh
# ---------------------------------------------------------------------------
# SignalForge container entrypoint.
#
# Two jobs:
#
# 1. PORT. Container hosts (Railway, Render, Fly) inject $PORT and send traffic
#    to that port. Apache's packaged vhost listens on 80, so we rewrite the
#    listen port before starting Apache. Without this the container starts fine
#    but nothing can reach it. Defaults to 80 when $PORT is unset, so
#    `docker run -p 8080:80` still works.
#
# 2. MPM. mod_php (libphp.so) only works with the prefork MPM, and Apache
#    refuses to start when two MPM modules are loaded:
#      AH00534: apache2: Configuration error: More than one MPM loaded.
#    The php:*-apache base image is rebuilt regularly, so its MPM state cannot
#    be assumed. We normalise it here, at the moment Apache actually starts,
#    and fail loudly with the real state if it still cannot start. Doing this
#    at runtime (not only at build time) means a base-image change can never
#    silently crash-loop the deploy again.
# ---------------------------------------------------------------------------
set -e

PORT="${PORT:-80}"

# ---- 1. MPM: exactly one, and it must be prefork --------------------------
# a2dismod/a2enmod are idempotent and print "already disabled/enabled".
a2dismod mpm_event mpm_worker >/dev/null 2>&1 || true
a2enmod mpm_prefork >/dev/null 2>&1 || true

# Report what is actually enabled, so a future failure is diagnosable from the
# deploy log alone instead of needing a shell in the container.
echo "SignalForge: MPM modules enabled: $(ls /etc/apache2/mods-enabled/ 2>/dev/null | grep -i '^mpm_.*\.load$' | tr '\n' ' ')"

# ---- 2. PORT --------------------------------------------------------------
# Apache's default site listens on 80; ports.conf also has a Listen 80 line.
sed -ri "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# ---- 3. Asset versioning --------------------------------------------------
# index.html references /assets/app.css and /assets/app.js without a hash, and
# .htaccess caches .js/.css for an hour. Without a version marker a browser
# keeps the OLD bundle for up to an hour after a deploy, so a fix can look
# broken to a user who already has the page open. Rewriting the references to
# include a content hash makes each deploy a new URL, which the cache cannot
# serve stale. Runs at container start, so it always matches the image.
if [ -f /var/www/html/index.html ]; then
    for asset in app.css app.js; do
        if [ -f "/var/www/html/assets/${asset}" ]; then
            hash=$(md5sum "/var/www/html/assets/${asset}" | cut -c1-10)
            # Strip any existing ?v= first so a container restart (which re-runs
            # this script on the same image) cannot stack query strings.
            sed -i -E "s|/assets/${asset}(\\?v=[a-f0-9]+)*|/assets/${asset}?v=${hash}|g" /var/www/html/index.html
            echo "SignalForge: ${asset} -> v=${hash}"
        fi
    done
fi

# ---- 4. Validate before exec ---------------------------------------------
# If the config is broken, print the reason and exit non-zero. A clear log
# beats a crash loop.
if ! apache2ctl -t 2>&1; then
    echo "SignalForge: FATAL - Apache configuration is invalid (see above)." >&2
    exit 1
fi

echo "SignalForge: Apache listening on port ${PORT}"

exec "$@"
