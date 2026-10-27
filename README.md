# Security-Hardened Laravel Starter — Deploy on Railway

A production-wired, security-hardened **Laravel 13** starter:
**PHP 8.5.12** (exact pin) + **Postgres** + **Sanctum** API auth,
behind **php-fpm + nginx** — no mod_php, no token guard, no demo
stubs. One-click deploy on Railway; the demo flow (seeded user →
Sanctum login → protected route) works end to end, and the health
endpoint reports the real database status.

**Card description (for publishing):** Hardened Laravel 13 starter — PHP 8.5.12, Postgres, Sanctum auth.

## Security story — why this template exists

Two October 2026 disclosures make an unhardened Laravel deploy a
bad default:

### php2shell — unauthenticated RCE in PHP itself

- **CVE-2026-109618** (OOM corruption) and **CVE-2026-109619**
  (stale global state), both **CVSS v4.0 9.2**, unauthenticated
  remote code execution. Fixed in PHP **8.5.12 / 8.4.27 / 8.3.36 /
  8.2.35** (php.net advisory, 21 Oct 2026). PHP's advice: *"There
  are no complete mitigations; updating is the only safe choice."*
- **CVE-2026-115560** (follow-up advisory, 23 Oct 2026): a residual
  RCE via `session.upload_progress`, fixed only in the *next*
  bugfix release after 8.5.12. Interim mitigation is
  `session.upload_progress.enabled=0` — set in
  `docker/php/security.ini`, and this starter never uses
  upload-progress tracking, so it costs nothing.
- **This template's answer:** the Dockerfile pins
  `php:8.5.12-fpm-alpine` **exactly** (Railpack resolves PHP
  fuzzily — no exact-patch pin is documented — so only a Dockerfile
  guarantees the patched release on Railway), and the container
  runs **php-fpm + nginx, never mod_php**: mod_php runs the
  interpreter inside the web server process, so the exploit runs
  before any application code can defend itself. nginx only ever
  executes `public/index.php`; any other `.php` path returns 404.

### TokenGuard bypass — unauthenticated login as another user

- **CVE-2026-114509** (CVSS 9.2, Critical): on MySQL/MariaDB apps
  using Laravel's `token` auth guard, an unauthenticated attacker
  can log in as another user. Fixed in laravel/framework
  **12.68.0+ / 13.29.0+** (25 Aug 2026). **Sanctum and Passport
  are NOT affected.** Companion **CVE-2026-114508** (Eloquent
  model-binding data destruction) was fixed in the same releases.
- **This template's answer:** Laravel 13.38.0 (well above the
  13.29.0 floor), **Sanctum only — the `token` guard is never
  configured anywhere in this repo**, and `composer audit` is run
  clean at build time.

### Hardening checklist (all implemented, not aspirational)

| Control | Where |
| --- | --- |
| PHP 8.5.12 exact pin (patched RCEs) | `Dockerfile` (`php:8.5.12-fpm-alpine`, `php:8.5.12-cli-alpine`) |
| No mod_php; php-fpm + nginx, single PHP entry point | `Dockerfile`, `docker/nginx.conf` |
| `session.upload_progress.enabled=0` (CVE-2026-115560) | `docker/php/security.ini` |
| `expose_php=0`, `display_errors=0`, errors to stderr | `docker/php/security.ini`, `docker/php/php-fpm.conf` |
| Laravel ≥ 13.29.0 (TokenGuard fix); Sanctum, never token guard | `composer.json`, `routes/api.php` |
| `APP_DEBUG=false` by default; app refuses to boot without `APP_KEY` | `.env.example`, `docker/entrypoint.sh` |
| Secure session cookies (`secure`, `http_only`, `same_site=lax`, strict mode) | `config/session.php`, `docker/php/security.ini` |
| Security headers (CSP, HSTS, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy) | `app/Http/Middleware/SecurityHeaders.php` (+ nginx backstop) |
| Rate limiting: login 5/min, register 10/min, API 60/min | `routes/api.php`, `bootstrap/app.php` |
| Validation on every input | `AuthController` (Form Request rules) |
| Sanctum tokens stored hashed (sha256), revocable on logout | `AuthController`, Sanctum default |
| `composer audit` clean | run at build time; see "Local parity test" |
| No secrets in the repo; `.env.example` placeholders only | whole repo; verified by secret scan, see below |
| Non-root container user, no build tools in runtime image | `Dockerfile` (`USER www-data`) |

## Stack & versions (pinned, verified 2026-10-27)

| Piece | Version | Source |
| --- | --- | --- |
| PHP | **8.5.12** (exact) | Docker Hub `php:8.5.12-fpm-alpine` + `php:8.5.12-cli-alpine`, both pushed 2026-10-25, amd64/arm64 |
| Laravel framework | **13.38.0** | Packagist `laravel/framework` v13.38.0 (published 2026-10-20); Laravel 13 released 17 Mar 2026, requires PHP ^8.3 |
| Sanctum | **4.3.3** | Packagist `laravel/sanctum` v4.3.3 |
| Postgres | 18 (Railway service) | Railway Postgres service; `DATABASE_URL=${{Postgres.DATABASE_URL}}` |
| SQLite | stdlib (local parity tests only) | PHP `pdo_sqlite` |
| nginx | Alpine package (version follows the base image) | `apk` in the runtime image |
| Composer | 2.9.8 | `composer:2.9.8` image (build stage only) |

Laravel 12 is security-fixes-only until 24 Feb 2027, so Laravel 13
is the right major. `composer.lock` pins every transitive
dependency exactly; `composer.json` requires
`laravel/framework: 13.38.0` (exact) with the 13.29.0 TokenGuard
floor noted.

## What you get

| Kind | Route | What it does |
| --- | --- | --- |
| Health | `GET /api/health` | 200 with **real** DB status (`ok` / `error`), PHP + Laravel versions, Sanctum status. Railway uses it as the deploy healthcheck. A degraded DB is reported, not hidden. |
| Auth | `POST /api/auth/register` | Validated registration; returns a Sanctum token. Rate-limited 10/min. |
| Auth | `POST /api/auth/login` | Validated login; returns a Sanctum token. Rate-limited 5/min. |
| Auth | `POST /api/auth/logout` | Revokes the current token. Requires Sanctum auth. |
| Protected | `GET /api/me` | Returns the authenticated user. Requires Sanctum auth; 401 without a token. |
| Landing | `GET /` | Short JSON pointer to the API routes and the README. |

Plus:

- **Migrations run on deploy** — `preDeployCommand` in
  `railway.toml` runs `php artisan migrate --force`, and the
  entrypoint re-runs it as a backstop, so a failed migration stops
  the deploy before the app starts.
- **Idempotent seeding** — the demo user (`demo@example.com` /
  `Demo1234!`) is created with `firstOrCreate`; re-running the
  seeder never duplicates it. Demo-only credentials: change them
  before real use.
- **Honest health** — if Postgres/SQLite is unreachable,
  `/api/health` still answers but reports `db: "error"` with the
  message, and returns HTTP 503, so Railway will not route traffic
  to a broken app.

## Run it locally

```bash
cp .env.example .env
php artisan key:generate          # fills APP_KEY in .env
composer install
php artisan migrate --seed        # SQLite by default locally
php artisan serve --port=8000
```

Try it:

```bash
curl localhost:8000/api/health
curl -X POST localhost:8000/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"demo@example.com","password":"Demo1234!"}'
# copy the token, then:
curl localhost:8000/api/me -H 'Authorization: Bearer <token>'
curl -X POST localhost:8000/api/auth/logout \
  -H 'Authorization: Bearer <token>'
```

Tests (SQLite, real HTTP, no mocks of the HTTP layer):

```bash
php artisan test
composer audit
```

## Deploy on Railway (exact steps)

1. Deploy this repo from GitHub (or from the Railway marketplace
   template once published).
2. **Add a Postgres service** to the project, then on the app
   service set **two** variables: `DB_CONNECTION=pgsql` and
   `DATABASE_URL` as a reference variable:
   `DATABASE_URL=${{Postgres.DATABASE_URL}}` (Railway fills in the
   real connection string; the name before the dot is the Postgres
   service's name — change it if yours is named differently).
   `DATABASE_URL` works because `config/database.php` accepts it
   (Laravel's own default is `DB_URL` — either works here).
3. **Set `APP_KEY`** as a service variable — generate it with
   `php artisan key:generate --show` and paste the value. The
   container **refuses to boot** without it.
4. Set `APP_URL` to the public domain once generated (Networking →
   Generate Domain). `APP_ENV` should stay `production` and
   `APP_DEBUG` must stay `false`.
5. Deploy. Railway builds the Dockerfile (exact PHP 8.5.12),
   runs `php artisan migrate --force` as the pre-deploy command,
   then healthchecks `GET /api/health` — it must answer 200 with
   `"db": "ok"`.
6. Verify with the same curl flow as "Run it locally", against your
   Railway domain.

> **Honest note — the Postgres path is implemented but not yet
> live-tested.** Migrations, the seeder, Sanctum login and
> `/api/health` are all written database-agnostically and were
> proven end to end against SQLite locally (see "Local parity
> test"), but no live round-trip against a real Postgres server
> has been run yet — this VM has no Postgres server (deliberately:
> a package install on 2026-10-26 wiped all local test databases,
> so no Postgres packages are installed here). Before relying on
> Postgres in production, run `php artisan migrate --seed` and
> `curl <domain>/api/health` on Railway and check
> `"db": "ok"`. This note stays until a live Postgres round-trip
> actually passes.

## What it costs

Railway charges for what the services actually use — there is no
fixed price for this template. A realistic small deployment is one
app service (1 vCPU, ~512 MB), roughly **~$5–10/mo**, plus a
Postgres service (Railway charges for that too; the smallest
Postgres is a few dollars a month). Verify against [Railway's
pricing page](https://railway.com/pricing) before promising a
number to anyone; Railway's prices change.

## How to add features

- **New API route:** add it to `routes/api.php`. Public routes go
  outside the `auth:sanctum` group; anything that reads or writes
  user data goes inside it. Add a throttle if it is
  abuse-sensitive (`throttle:60,1` is the API default).
- **New model:** `php artisan make:model Thing -m`, write the
  migration, add validation in a Form Request (never trust raw
  input), and add a Feature test that hits the real endpoint.
- **New auth rule:** keep Sanctum. Do **not** add the `token`
  guard (CVE-2026-114509). If you need OAuth, add Passport or
  Socialite — both are unaffected by that CVE.
- **File uploads:** keep `session.upload_progress.enabled=0`
  (CVE-2026-115560) and validate file type/size server-side; store
  uploads outside `public/` or behind a signed URL.
- **Queues / mail:** set `QUEUE_CONNECTION` and `MAIL_*` in the
  Railway variables; the `.env.example` documents both.

## Local parity test

Run 2026-10-27 on the build VM (Ubuntu 24.04). Full evidence:
`~/workspace/goals/railway-deployment-templates/agent_notes/local-test-laravel-2026-10-27.md`.

**Runtime actually tested on:** PHP **8.5.12** (ondrej/php PPA,
`8.5.12-1+ubuntu24.04.1+deb.sury.org+1`, `php -v` confirmed),
Composer 2.10.3. The tests ran on the same PHP patch release the
Dockerfile pins — not on 8.3. (No `ext-zip` locally: the archive
mirror had no libzip4t64; Composer falls back to the `unzip`
binary. The container image is unaffected — it uses the official
`php:8.5.12-*` images.)

- `php artisan test` — **11 passed, 61 assertions, exit 0**
  (SQLite `:memory:`, real HTTP, no mocks of the HTTP layer):
  health 200 with real DB status; register → login → protected
  route → logout → revoked token rejected; seeded demo user login;
  wrong-password 401; register validation (5 cases); security
  headers incl. no `X-Powered-By`; login throttling (5/min).
- `composer audit` — **clean: "No security vulnerability
  advisories found."**
- Live server (`php artisan serve`, fresh `migrate:fresh --seed`):
  `GET /api/health` → 200 with `"db":"ok"`, `"php_version":
  "8.5.12"`, `"laravel_version":"13.38.0"`, `"sanctum":
  "installed"`; register → 201; login → token; `GET /api/me`
  with token → 200; without token → **401** (also without an
  `Accept` header — that case was a 500 before the fix, see
  below); logout → 200; revoked token → 401; wrong password →
  401; 6 rapid failed logins → 429 after the 5/min limit.
- Degraded DB (separate server, `DB_DATABASE` pointed at a
  nonexistent path): `GET /api/health` → **503** with
  `"status":"degraded"`, `"db":"error"` and the real error
  message — a 200 with a dead DB is treated as a failure.
- `config/database.php` accepts Railway's `DATABASE_URL`
  (verified via tinker) as well as Laravel's own `DB_URL`.
- Secret scan of the whole repo (excluding `vendor/`): no real
  keys, passwords or tokens. `.env.example` is placeholders-only;
  the only key in the repo is a documented test-only `APP_KEY` in
  `phpunit.xml`. The demo seed credentials (`demo@example.com` /
  `Demo1234!`) are intentional demo-only values for a throwaway
  database.

**Two real bugs found and fixed during testing** (both proven by
a failing test / live curl before the fix, passing after):
1. Unauthenticated API requests without an `Accept` header
   returned **500** ("Route [login] not defined") instead of
   401 — Laravel's auth middleware redirects guests to a `login`
   route that does not exist in this API-only app, and the
   redirect is evaluated while *constructing* the exception.
   Fixed with `redirectGuestsTo(fn () => '/')` in
   `bootstrap/app.php` plus an explicit `AuthenticationException`
   → JSON 401 render for `api/*`, with a regression test.
2. `Auth::attempt()` in login started a web session, and
   Sanctum's guard prefers a session user (TransientToken) over
   the Bearer token — so logout deleted nothing (500 before the
   guard, then silent no-op). Fixed by verifying credentials
   with `Hash::check` and never starting a session; logout now
   also handles a TransientToken defensively instead of 500ing.

**What is still unproven** — see "What is still unproven" below;
the Postgres path and the live Railway deploy (including the
Docker build) have not been run.

## What is still unproven

- **Live deploy on Railway** — no Railway deploy has been run; the
  Dockerfile has not been built by Railway yet (no Docker on this
  VM). The Dockerfile pins verified tags, but a Railway build is
  the real proof.
- **Postgres path** — implemented, not live-tested (see the honest
  note in "Deploy on Railway"). Local tests run on SQLite only.
- **Live Stripe / payments** — not applicable; this template has
  no billing.
- **nginx + php-fpm under Railway's proxy** — the config trusts
  `X-Forwarded-*` only via Laravel's `TrustProxies` middleware;
  verify `APP_URL` and HTTPS detection on the first live deploy.

## Project layout

```
Dockerfile              multi-stage: php:8.5.12-cli-alpine build,
                        php:8.5.12-fpm-alpine + nginx runtime
docker/
  nginx.conf            template (${PORT}); only public/index.php runs
  php/security.ini      upload_progress off, expose_php off, strict cookies
  php/opcache.ini       production OPcache settings
  php/php-fpm.conf      TCP-loopback pool (127.0.0.1:9000), non-root
  entrypoint.sh         renders nginx, enforces APP_KEY, migrates, seeds,
                        starts php-fpm + nginx
railway.toml            builder DOCKERFILE, healthcheck, pre-deploy migrate
app/Http/Middleware/SecurityHeaders.php
app/Http/Controllers/Api/AuthController.php
app/Http/Controllers/Api/HealthController.php
routes/api.php          health, throttled auth, Sanctum-protected /api/me
database/seeders/DatabaseSeeder.php   idempotent demo user
config/session.php      secure / http_only / same_site=lax
tests/Feature/          health, auth flow, headers, rate limiting
composer.json / composer.lock   pinned dependencies
```

See `AGENTS.md` for the rules AI agents should follow in this repo.

## License

MIT — see `LICENSE`.
