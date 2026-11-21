# ---------------------------------------------------------------------------
# SignalForge - PHP app + Apache, for a host that gives you a real database.
#
# This is the recommended way to run the app now. The previous host
# (InfinityFree) deleted the account and its MySQL server, which is what broke
# login and register. A container host (Render, Railway, Fly) keeps the app and
# its database together, so one cannot vanish without the other.
#
# Build:  docker build -t signalforge .
# Run:    docker run -p 8080:80 \
#           -e DATABASE_URL="mysql://user:pass@host:3306/dbname" \
#           -e SF_SECRET="$(openssl rand -hex 32)" \
#           -e SF_INSTALL_KEY="some-random-string" \
#           signalforge
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
COPY . /var/www/html/

# Apache needs to read .htaccess, and the app writes nothing to disk.
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Render and most container hosts inject $PORT. Apache listens on 80 by default;
# this keeps it working if the platform expects a different port.
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

EXPOSE 80

# The health endpoint is the readiness probe.
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r '$r=@file_get_contents("http://127.0.0.1/api/health.php"); exit($r===false?1:0);'
