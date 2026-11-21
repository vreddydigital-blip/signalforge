#!/bin/sh
# ---------------------------------------------------------------------------
# SignalForge container entrypoint.
#
# Container hosts (Railway, Render, Fly) inject $PORT and send traffic to that
# port. Apache's packaged vhost listens on 80, so we rewrite the listen port
# before starting Apache. Without this the container starts fine but nothing
# can reach it.
#
# Defaults to 80 when $PORT is unset, so `docker run -p 8080:80` still works.
# ---------------------------------------------------------------------------
set -e

PORT="${PORT:-80}"

# Apache's default site listens on 80; ports.conf also has a Listen 80 line.
sed -ri "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# The health check and any internal probe should use the same port.
echo "SignalForge: Apache listening on port ${PORT}"

exec "$@"
