# AGENTS.md — working in this repo

Read this before changing anything. The user of this repo is a
developer deploying a security-hardened Laravel starter on Railway;
keep it runnable, minimal, and honest.

## Stack (pinned — do not bump majors casually)

PHP **8.5.12** (exact, via `php:8.5.12-fpm-alpine` in the Dockerfile)
+ Laravel **13.38.0** + Sanctum **4.3.3** + Postgres 18 (Railway
service; SQLite only for local parity testing) + nginx (Alpine).
Versions verified 2026-10-27 — Packagist for Laravel/Sanctum,
Docker Hub for the PHP tags; see README "Stack & versions".

## The one rule that matters

**Never fake a capability.** `/api/health` reports the REAL database
status — a 200 with `db: "error"` is a failure, not a pass. The
Postgres path is implemented but NOT live-tested locally (tests run
on SQLite); the README says so plainly and that note stays until a
live Postgres round-trip actually passes. No APP_KEY means the
container refuses to boot — it never silently runs unencrypted.
Anything not configured reports clearly instead of crashing.

## Why this template exists

Railway already has Laravel templates (Laravel 13 + PHP 8.4 +
MySQL; an official Laravel starter with Postgres) — but none is
security-hardened, none is pinned to the patched PHP 8.5.12, and
none combines Laravel 13 + Postgres + Sanctum + hardening. This one
exists because of two October 2026 disclosures:

- **php2shell** — CVE-2026-109618 / CVE-2026-109619 (CVSS v4.0 9.2):
  unauthenticated RCE in PHP itself, fixed in PHP 8.5.12 / 8.4.27 /
  8.3.36 / 8.2.35. Residual RCE via `session.upload_progress`
  (CVE-2026-115560) is fixed only in the NEXT bugfix release; the
  interim mitigation (`session.upload_progress.enabled=0`) is in
  `docker/php/security.ini`.
- **TokenGuard bypass** — CVE-2026-114509 (CVSS 9.2): unauthenticated
  login-as-another-user on MySQL/MariaDB apps using the `token`
  auth guard. Fixed in laravel/framework 12.68.0+ / 13.29.0+. This
  starter uses Sanctum (not affected) and NEVER the token guard.
  Companion CVE-2026-114508 (Eloquent model-binding data
  destruction) was fixed in the same releases.

## Layout

- `Dockerfile` — multi-stage. Build stage: `php:8.5.12-cli-alpine`
  + Composer, installs deps. Runtime stage: `php:8.5.12-fpm-alpine`
  + nginx, no build tools, runs as `www-data`. php-fpm + nginx on
  purpose: mod_php runs the interpreter inside the web server, so
  the php2shell exploit runs before application code — never use it.
- `docker/nginx.conf` — template (`${PORT}` substituted at start).
  Only `public/index.php` is ever executed; any other `.php` path
  returns 404; hidden files are never served.
- `docker/php/security.ini` — `session.upload_progress.enabled=0`
  (CVE-2026-115560), `expose_php=0`, strict session cookies,
  `display_errors=0`.
- `docker/php/php-fpm.conf` — TCP-loopback pool (127.0.0.1:9000),
  non-root, dynamic PM. Loopback, not a Unix socket under /run:
  www-data could not create the socket file there.
- `docker/entrypoint.sh` — renders nginx config, clears caches,
  REFUSES to boot without `APP_KEY`, runs migrations, seeds
  idempotently, starts php-fpm + nginx.
- `railway.toml` — `builder = "DOCKERFILE"` (Railpack resolves PHP
  fuzzily; only a Dockerfile pins the exact patch), healthcheck
  `/api/health`, pre-deploy `php artisan migrate --force`.
- `app/Http/Middleware/SecurityHeaders.php` — CSP, HSTS,
  X-Content-Type-Options, X-Frame-Options, Referrer-Policy,
  Permissions-Policy on every response.
- `app/Http/Controllers/Api/AuthController.php` — register / login /
  logout with Sanctum tokens. Validation on every input. Login is
  rate-limited (5/min); registration 10/min.
- `app/Http/Controllers/Api/HealthController.php` — `GET
  /api/health`: 200 with real DB status (`ok` / `error`), Laravel
  + PHP versions, Sanctum status. A degraded DB is reported, not
  hidden.
- `routes/api.php` — public health, throttled auth routes, one
  Sanctum-protected route (`GET /api/me`).
- `database/seeders/DatabaseSeeder.php` — idempotent demo user
  (`demo@example.com` / `Demo1234!` — demo-only credentials, safe
  to keep in the repo because they seed a fresh throwaway database;
  change them before real use).
- `config/session.php` — `secure=true`, `http_only=true`,
  `same_site=lax`, `APP_DEBUG=false` by default in production.
- `tests/Feature/` — health, auth flow (register → login → protected
  route → logout), security headers, rate limiting. Run against the
  REAL app with SQLite; no mocks of the HTTP layer.

## Commands

```bash
cp .env.example .env   # then set APP_KEY (php artisan key:generate)
composer install
php artisan migrate --seed
php artisan serve --port=8000
php artisan test       # full suite (SQLite, real HTTP)
composer audit         # must be clean before any release
```

## Do / Don't

- DO re-verify a version on Packagist / Docker Hub before pinning
  it, and update the README version table in the same change.
- DO run `php artisan test` + `composer audit` after changes and fix
  real errors. Do not claim a test passes if it did not. Do not
  claim a flow works from a status code alone — check the response
  body, including the DB status in `/api/health`.
- DO keep `.env.example` placeholders-only. There are no required
  secrets in this repo beyond `APP_KEY` and `DATABASE_URL`, and
  neither has a value in code — missing `APP_KEY` is a fatal boot
  error, by design. One documented exception: `phpunit.xml` carries
  a test-only `APP_KEY` (commented "never use it in production") —
  the suite requires it (removing it fails with
  `MissingAppKeyException`); it is a throwaway test key, not the
  production key, and must never be copied into `.env` or a
  deployment.
- DON'T add the `token` auth guard anywhere. Sanctum is the only
  token auth in this starter (CVE-2026-114509).
- DON'T claim the Postgres path is tested without a real Postgres
  round-trip. Local tests run on SQLite only; the README says so
  plainly, and that note stays until a live Postgres round-trip
  (migrate + seed + login through Postgres) actually passes.
- DON'T enable `session.upload_progress` or mod_php. Both are
  disabled on purpose (CVE-2026-115560 / php2shell).
- DON'T set `APP_DEBUG=true` in production, and don't add a feature
  that needs a new env var without documenting it in `.env.example`
  and README.
- DON'T push, deploy, or publish from here. This repo is prepared
  locally; pushing and Railway template creation are separate,
  human-approved steps.

## Railway notes

- Add a Postgres service; set `DB_CONNECTION=pgsql` and
  `DATABASE_URL` as a reference variable,
  `${{Postgres.DATABASE_URL}}`, on the app service.
- Set `APP_KEY` (generate with `php artisan key:generate --show`)
  and `APP_URL` (the public domain) as service variables.
- The Dockerfile is the PHP pin: Railway builds the exact
  `php:8.5.12-fpm-alpine` image. If that tag ever disappears from
  Docker Hub, the build fails loudly instead of silently using a
  different PHP — that is the intended behaviour.
- Live deploy and the Postgres path are NOT yet tested — see README
  "What is still unproven".
