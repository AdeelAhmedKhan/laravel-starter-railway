# syntax=docker/dockerfile:1
# Security-hardened Laravel 13 starter — exact PHP pin.
#
# WHY A DOCKERFILE (and not Railpack/Nixpacks): Railway's default
# builder (Railpack) resolves PHP versions fuzzily and documents no
# exact-patch pin; Nixpacks is in maintenance mode and tracks only
# major.minor. The only reliable way to guarantee the exact patched
# PHP on Railway is a Dockerfile. Railway auto-detects a Dockerfile
# at the repo root (builder = "DOCKERFILE" in railway.toml).
#
# WHY php-fpm + nginx (and NEVER mod_php): the October 2026 php2shell
# RCEs (CVE-2026-109618 / CVE-2026-109619, CVSS v4.0 9.2) are
# unauthenticated remote code execution in PHP itself; mod_php runs
# the interpreter inside the web server process, so the exploit runs
# before any application code can defend itself. php-fpm keeps PHP in
# a separate process behind nginx, which only ever forwards requests
# to `public/index.php` — no PHP file is ever executed from a URL
# path. See README "Security story".
#
# Tags verified on Docker Hub 2026-10-27 (hub.docker.com/v2):
#   php:8.5.12-fpm-alpine  (pushed 2026-10-25, amd64/arm64)
#   php:8.5.12-cli-alpine  (pushed 2026-10-25, amd64/arm64)
# Both are the 8.5.12 bugfix release that patches CVE-2026-109618
# and CVE-2026-109619. Do not change the tag without re-verifying it
# on Docker Hub first.

# ------------------------------------------------------------------
# Stage 1 — build: Composer install (no dev deps, no scripts that
# need a database). Uses the CLI variant of the SAME pinned PHP so
# the lockfile is resolved and autoloaded against 8.5.12.
# ------------------------------------------------------------------
FROM php:8.5.12-cli-alpine AS build

RUN apk add --no-cache git unzip libzip-dev libpq-dev sqlite-dev icu-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql pdo_sqlite bcmath intl zip pcntl opcache

COPY --from=composer:2.9.8 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && php artisan config:clear 2>/dev/null || true

# ------------------------------------------------------------------
# Stage 2 — runtime: php-fpm + nginx. No Composer, no git, no build
# tools in the final image. Runs as a non-root user.
# ------------------------------------------------------------------
FROM php:8.5.12-fpm-alpine AS runtime

RUN apk add --no-cache nginx libzip libpq sqlite-libs icu \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql pdo_sqlite bcmath intl zip pcntl opcache \
    && rm -rf /var/cache/apk/*

# PHP hardening: see docker/php/security.ini for what each line does.
COPY docker/php/security.ini /usr/local/etc/php/conf.d/99-security.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/10-opcache.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf

# nginx: template with ${PORT} substituted at container start.
COPY docker/nginx.conf /etc/nginx/nginx.conf.template

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /app
COPY --from=build --chown=www-data:www-data /app /app

# Writable dirs for a non-root runtime user.
RUN mkdir -p /app/storage/logs /app/storage/framework/cache/data \
        /app/storage/framework/sessions /app/storage/framework/views \
        /app/bootstrap/cache /var/log/nginx /var/lib/nginx /run/nginx \
        /var/lib/nginx/tmp/client_body /var/lib/nginx/tmp/proxy \
        /var/lib/nginx/tmp/fastcgi /var/lib/nginx/tmp/uwsgi \
        /var/lib/nginx/tmp/scgi \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache \
        /var/log/nginx /var/lib/nginx /run/nginx \
    && chmod -R 775 /app/storage /app/bootstrap/cache

USER www-data
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
