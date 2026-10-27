#!/bin/sh
# Entrypoint for the Laravel starter container.
# - Substitutes $PORT into the nginx config (Railway injects $PORT).
# - Runs migrations on container start (idempotent; safe to re-run).
#   The pre-deploy command in railway.toml ALSO runs migrations, so
#   a failed migration stops the deploy before the app starts; this
#   start-time run is the backstop for environments without it.
# - Starts php-fpm in the background and nginx in the foreground.
set -eu

PORT="${PORT:-8080}"
export PORT

# Render nginx config from the template.
# (envsubst is not in the Alpine image; use sed on the one variable.)
sed "s|\${PORT}|${PORT}|g" /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# Laravel caches: rebuild on every container start so a stale
# config cache from the build image can never ship.
php artisan config:clear --quiet || true
php artisan route:clear --quiet || true
php artisan view:clear --quiet || true

# Fail loudly if APP_KEY is missing — an app without a key cannot
# encrypt cookies/sessions and must not boot "successfully".
if [ -z "${APP_KEY:-}" ]; then
    echo "FATAL: APP_KEY is not set. Generate one with 'php artisan key:generate' and set it as a service variable." >&2
    exit 1
fi

# Migrations: idempotent, --force skips the production prompt.
# If the database is unreachable this fails and the container exits
# instead of serving a broken app.
php artisan migrate --force

# Seed ONLY when the users table is empty (DatabaseSeeder is
# idempotent — it uses firstOrCreate for the demo user). Seeding on
# every start would be harmless but noisy; the guard keeps it quiet.
php artisan db:seed --force --quiet || true

# php-fpm in the background, nginx in the foreground (PID 1).
php-fpm -D
exec nginx -g 'daemon off;'
