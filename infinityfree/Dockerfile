# ---------------------------------------------------------------------------
# SignalForge - PHP app + Apache, for a host that gives you a real database.
#
# This is the recommended way to run the app now. The previous host
# (InfinityFree) deleted the account and its MySQL server, which is what broke
# login and register. A container host (Railway, Render, Fly) keeps the app and
# its database together, so one cannot vanish without the other.
#
# IMPORTANT - BUILD CONTEXT
# Railway (and Render) always use the REPOSITORY ROOT as the build context,
# even when the Dockerfile lives in a subdirectory. Every COPY path below is
# therefore relative to the repo root, not to this file. Building from inside
# this directory will fail with "COPY ... no such file or directory".
#
# Build (from the repository root):
#   docker build -f infinityfree/Dockerfile -t signalforge .
#
# Run:
#   docker run -p 8080:80 \
#     -e DATABASE_URL="mysql://user:pass@host:3306/dbname" \
#     -e SF_SECRET="$(openssl rand -hex 32)" \
#     -e SF_INSTALL_KEY="some-random-string" \
#     signalforge
#
# Then open /api/install.php?key=SF_INSTALL_KEY once, and /api/health.php.
# ---------------------------------------------------------------------------
FROM php:8.2-apache

# pdo_mysql is the only extension the app needs beyond the defaults.
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers deflate expires

# Serve from /var/www/html (the Apache default), which is the web root.
WORKDIR /var/www/html

# Copy the app. api/config.php stays a stub - real values come from the
# environment, so no credentials are ever baked into the image.
# Paths are repo-root relative: see BUILD CONTEXT above.
COPY infinityfree/ /var/www/html/

# Apache needs to read .htaccess, and the app writes nothing to disk.
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Container hosts (Railway, Render, Fly) inject $PORT and route traffic to it.
# Apache's default vhost listens on 80, so without this the app is unreachable
# on any platform that does not use port 80. The entrypoint rewrites the listen
# port at start-up from $PORT, defaulting to 80 when it is not set.
COPY infinityfree/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENV PORT=80
EXPOSE 80

# The health endpoint is the readiness probe. It uses $PORT so it follows the
# platform's port instead of assuming 80.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r '$p=getenv("PORT")?:"80"; $r=@file_get_contents("http://127.0.0.1:".$p."/api/health.php"); exit($r===false?1:0);'

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
