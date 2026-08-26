# Development Environment

This document is the source of truth for the local Docker development environment — exact verified versions, service architecture, configuration, and the commands used to run it. It describes infrastructure only; see `docs/development/project-status.md` for what business functionality has and hasn't been implemented, and `docs/architecture/system-architecture.md` / `docs/database/database-design.md` for the approved application architecture this infrastructure exists to run.

## Environment Baseline

**Date**: 2026-08-26
**Status**: Verified

| Component | Version | Location | Status |
|---|---|---|---|
| PHP | 8.5.9 | Docker `app` | Verified |
| Laravel | 13.29.0 | `backend/` | Verified |
| Composer | 2.10.2 | Docker `app` | Verified |
| React | 19.2.8 | `frontend/` | Verified |
| TypeScript | 6.0.3 | `frontend/` | Verified |
| Vite | 8.2.2 | `frontend/` | Verified |
| Node.js | 24.19.0 | Docker `node` | Verified |
| MySQL | 8.4.11 | Docker `mysql` | Verified |
| Redis | 7.4.11 | Docker `redis` | Verified |
| Nginx | 1.30.4 | Docker `nginx` | Verified |
| Docker | 29.7.2 | Host | Verified |
| Docker Compose | v5.4.0 | Host | Verified |

Every version above was read from inside the actual running container/environment (`php -v`, `php artisan --version`, `composer --version`, package.json-resolved versions inside `node_modules`, `mysqladmin`/`artisan db:show`, `redis-server --version`, `nginx -v`, `docker --version`) — not copied from a config file's declared version range.

**Base images** (Docker Compose):

| Service | Image |
|---|---|
| `app`, `queue` | `php:8.5-fpm` (custom-built, see `docker/php/Dockerfile`) |
| `mysql` | `mysql:8.4` |
| `redis` | `redis:7-alpine` |
| `node` | `node:24-alpine` |
| `nginx` | `nginx:stable-alpine` |

These are the primary version identifiers. Image digests are not pinned in `docker-compose.yml` (tags only) — acceptable for local development; digest pinning would be a deliberate future hardening step, not part of this baseline.

---

## Docker Architecture

```
Browser
   |
   +--> Nginx :8080
             |
             +--> PHP-FPM app :9000
                      |
                      +--> MySQL :3306
                      |
                      +--> Redis :6379

Laravel Queue Worker (queue)
        |
        v
      Redis :6379
        |
        v
      MySQL :3306   (queue worker also needs DB access — job/cache/failed_jobs tables)

Browser
   |
   +--> Vite :5173   (frontend/, independent of the PHP-FPM path above)
```

The browser only ever talks to two ports: `8080` (Nginx, backend) and `5173` (Vite, frontend). It never reaches MySQL, Redis, PHP-FPM, or any Stripe/LLM API directly — those all stay behind Laravel, per the approved architecture's core rule that Laravel is the only component with database access.

**Service purposes:**

| Service | Purpose |
|---|---|
| `app` | PHP-FPM — executes the Laravel application. Not exposed to the host; only `nginx` reaches it, over the internal network at `app:9000`. |
| `nginx` | Reverse proxy / static file server. The only backend entry point exposed to the browser (`:8080`). Serves `backend/public`, forwards `.php` requests to `app:9000`, and blocks direct access to `.env`, `.git`, `vendor`, and other sensitive paths. |
| `mysql` | The durable source of truth for all business data (once migrations/models exist). |
| `redis` | Queue backend today; infrastructure is also in place for future analytics caching and rate limiting (not implemented yet — see `project-status.md`). Never the source of truth for anything. |
| `queue` | Runs `php artisan queue:work` against the Redis queue connection — the same Laravel codebase/image as `app`, just a different running process, so long-running jobs never block web requests. |
| `node` | Runs the Vite dev server for `frontend/` (React 19.2 + TypeScript). Talks to the Laravel API over plain HTTP when the API exists — never touches MySQL/Redis/Stripe/LLM APIs directly. |

---

## Docker Compose

Defined in the project-root `docker-compose.yml`. All 6 services share one private bridge network, `app-network` — internal service-to-service communication uses Docker service names (`mysql`, `redis`, `app`), never `localhost`, since `localhost` inside a container refers to that container itself, not another one.

### Ports

| Service | Host binding | Container port | Notes |
|---|---|---|---|
| `nginx` | `8080` (all interfaces) | `80` | Browser-facing. |
| `node` | `5173` (all interfaces) | `5173` | Browser-facing (Vite dev server). |
| `mysql` | `127.0.0.1:3306` | `3306` | Localhost-only — for local DB tools (TablePlus, DBeaver, etc.); never reachable from outside the machine. |
| `redis` | *(none)* | `6379` | No host port mapping at all — fully internal to `app-network`, unreachable from the host or outside. |
| `app` / `queue` | *(none)* | `9000` | PHP-FPM is never exposed to the host; only `nginx` reaches it, internally. |

### Dependencies & healthchecks

- `mysql` and `redis` each have a healthcheck (`mysqladmin ping` / `redis-cli ping`, 5s interval).
- `app` and `queue` both declare `depends_on: mysql: condition: service_healthy` and `redis: condition: service_healthy` — they wait for MySQL/Redis to actually be ready to accept connections, not merely for the container process to have started. This was a deliberate design point (see `project-status.md`'s architectural decisions): "container started" and "service ready" are treated as different things.
- `nginx` depends on `app` (`service_started` — nginx can start before Laravel is fully warmed up; requests just wait on the upstream).

### Restart behavior

`queue` has `restart: unless-stopped` — if the worker process crashes, Docker restarts it automatically; it also comes back up after a host/Docker restart unless explicitly stopped. No other service has an explicit restart policy set.

### Environment variables

`app` and `queue` receive `DB_CONNECTION`, `DB_HOST=mysql`, `DB_PORT=3306`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `REDIS_HOST=redis`, `REDIS_PORT=6379`, and `QUEUE_CONNECTION=redis` directly as container environment variables in `docker-compose.yml`, with the `DB_*` credential values interpolated from the root `.env` (`${MYSQL_DATABASE}` etc.). See "Environment / Secrets" below for why this — not `backend/.env` — is the authoritative source for these values.

### Volumes

| Mount | Type | Purpose |
|---|---|---|
| `./backend:/var/www/html` | bind (rw, on `app`/`queue`) | Live Laravel source — host edits are picked up immediately, no image rebuild needed. |
| `./backend:/var/www/html:ro` | bind (read-only, on `nginx`) | Nginx only ever needs to *read* `public/` and serve static files; read-only limits its blast radius. |
| `mysql_data:/var/lib/mysql` | named volume | MySQL's data directory. Persists across `docker compose down` and container recreation — confirmed by surviving an `app`/`queue` image rebuild during this environment's setup. Only `docker compose down -v` deletes it. |
| `./frontend:/app` | bind (rw, on `node`) | Live React source, same reasoning as the backend bind mount. |
| `frontend_node_modules:/app/node_modules` | named volume | Deliberately shadows the (empty, host-side) `node_modules` under the bind mount above, so the container manages its own dependency install and host/container `node_modules` never conflict or overwrite each other. |
| `./docker/nginx/default.conf`, `./docker/mysql/my.cnf` | bind (read-only) | Config files, edited on the host, read by their respective containers. |

Redis has no volume — persistence is intentionally not configured for local development (acceptable since Redis holds no durable business data; see "Redis Configuration" below).

---

## Project Structure

```
ai-commerce-platform/
├── backend/                 # Laravel 13 application (PHP 8.5) — stays here, never the repo root
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   ├── public/
│   ├── resources/
│   ├── routes/
│   ├── storage/
│   ├── tests/
│   ├── artisan
│   ├── composer.json
│   └── .env                 # gitignored — see Environment / Secrets
│
├── frontend/                # React 19.2 + TypeScript + Vite — stays here, never the repo root
│   ├── src/
│   ├── public/
│   ├── package.json
│   └── vite.config.ts
│
├── docker/
│   ├── nginx/default.conf
│   ├── php/Dockerfile
│   ├── php/entrypoint.sh
│   └── mysql/my.cnf
│
├── docs/
│   ├── architecture/         # approved system architecture + review record
│   ├── database/              # approved database design
│   └── development/           # this file, project-status.md
│
├── docker-compose.yml
├── .env                      # gitignored — root Docker Compose credentials
├── .env.example               # committed — placeholders only
├── .dockerignore
└── .gitignore
```

**Directory responsibilities**: `backend/` is the entire Laravel application and nothing else lives inside it that isn't Laravel's own structure. `frontend/` is the entire React application. `docker/` holds only configuration consumed by the images/services (no application code). `docs/` is documentation only, never read by any running service.

---

## PHP / Laravel Environment

**Verified installed**: PHP 8.5.9 (FPM), Laravel 13.29.0, Composer 2.10.2.

**Verified installed PHP extensions** (checked via `php -m` inside the built image — nothing below is assumed):

PDO, `pdo_mysql`, `mbstring`, `bcmath`, `intl`, `xml`, `ctype`, `fileinfo`, `tokenizer`, `openssl`, `json`, `pcntl`, `redis` (PhpRedis), `zip`.

**`docker/php/Dockerfile`**: builds from `php:8.5-fpm`, installs the extensions above via `docker-php-ext-install` and PhpRedis via `pecl`, and copies the Composer 2 binary in from the official `composer:2` image (no curl+shell bootstrap). Shared by both `app` and `queue` — same image, different `command:` in `docker-compose.yml`.

**`docker/php/entrypoint.sh` — the Windows bind-mount permission workaround**: PHP-FPM's worker pool runs as `www-data` (standard, unchanged — `www-data` was never modified to run as root; that approach was tried and rejected because PHP-FPM hard-refuses to start with `user`/`group` set to root). The problem this script solves: on this Windows host, files under a Docker Desktop (WSL2) bind mount of `./backend` land in a way that only `root` can write to, regardless of what `chmod` shows or is run from the Windows side (`chmod` via a Windows-side shell is a no-op on the underlying NTFS filesystem) — so `www-data` gets `Permission denied` writing to `storage/` and `bootstrap/cache`, and every request fails. The entrypoint script runs as root (the container's default starting user, before php-fpm drops privileges for its workers), does `chmod -R 777 storage bootstrap/cache`, and then execs the real command (`php-fpm`, or `php artisan queue:work` for the `queue` service).

**This is LOCAL DEVELOPMENT ONLY.** It exists specifically because of this Windows bind-mount path and should not be carried into a production image — a production deployment doesn't bind-mount source from a Windows host, so this specific failure mode doesn't occur there, and a production image should keep the default, more restrictive `www-data`-owned filesystem.

---

## MySQL Configuration

**Verified**: MySQL 8.4.11. Service name `mysql`; internal host `mysql`, internal port `3306` (external/host access only via `127.0.0.1:3306`, for local DB tools).

**Credential flow**:

```
Root .env  (MYSQL_DATABASE / MYSQL_USER / MYSQL_PASSWORD / MYSQL_ROOT_PASSWORD)
    ↓
docker-compose.yml  (configures the mysql service AND injects the same
                      values into app/queue as DB_DATABASE/DB_USERNAME/DB_PASSWORD)
    ↓
Container environment variables (app, queue, mysql)
    ↓
Laravel  (DB_CONNECTION=mysql, DB_HOST=mysql, DB_PORT=3306, DB_DATABASE, DB_USERNAME, DB_PASSWORD)
```

Because Laravel's `.env` loader never overwrites a value already present in the real process environment, the container-injected values always win — `backend/.env`'s own `DB_*` lines are inert fallbacks, not a second place credentials need to be kept in sync by hand. **Actual passwords are not written anywhere in this document** — see the root `.env`/`.env.example` files for the variable names (values only exist in the gitignored `.env`).

`mysql_data` is the persistent named Docker volume backing MySQL's data directory — see "Volumes" above.

---

## Redis Configuration

**Verified**: Redis 7.4.11. Service name `redis`; internal host `redis`, internal port `6379`. No host port mapping — Redis is reachable only from inside `app-network`.

Laravel: `REDIS_HOST=redis`, `REDIS_PORT=6379`, `QUEUE_CONNECTION=redis`.

**Redis is not the durable source of truth. MySQL is.** Today, Redis's only actual role in this environment is the queue backend (verified working — see Verification Baseline). The approved architecture also calls for Redis to eventually serve analytics caching and rate limiting, but **neither of those is implemented** — documenting that plan here would misrepresent current state; see `docs/architecture/system-architecture.md` for the full planned role and `project-status.md` for what's actually built.

---

## Queue Worker

Service `queue` runs `php artisan queue:work --sleep=1 --tries=3` against the `redis` connection, using the same image/environment as `app`.

```
app (or anything dispatching a job)
   ↓
Redis (queue storage)
   ↓
queue worker (php artisan queue:work)
   ↓
job execution
```

**Verified working** during this environment's setup: a temporary infrastructure-only job was dispatched and confirmed processed end-to-end (via both the worker's own logs and an independent Redis marker the job wrote), then removed — no permanent/business job exists in the codebase as a result of this verification.

---

## Nginx

Config: `docker/nginx/default.conf`. Listens on container port `80`, published to the host as `8080`.

- `root /var/www/html/public;` — Laravel's standard public document root.
- PHP requests are forwarded to `app:9000` over the internal network (fastcgi).
- Front-controller routing (`try_files ... /index.php?$query_string`) — standard Laravel routing works.
- Blocks direct access (404) to dotfiles (`.env`, `.git`, etc.), `vendor/`, `composer.json`/`composer.lock`, and Laravel's internal `storage/framework`, `storage/logs`, `bootstrap/cache` paths.

**Verified**: `http://localhost:8080` returns the Laravel application (HTTP 200); `.env`, `.git/config`, `vendor/autoload.php`, and `composer.json` all confirmed returning 404 rather than their contents.

---

## Frontend

**Verified**: React 19.2.8, TypeScript 6.0.3, Vite 8.2.2, Node.js 24.19.0. Lives in `frontend/`; dev server at `http://localhost:5173`.

**Docker development configuration** (`frontend/vite.config.ts`):
- `server.host = true` — Vite's default dev server binds to `127.0.0.1` only, which is unreachable from outside its own container. Setting this to `true` binds `0.0.0.0`, so the host (and the published port mapping) can actually reach it.
- `server.port = 5173`, `strictPort: true` — matches the published Docker port exactly.
- `server.watch.usePolling = true` — Windows host → Docker Desktop (WSL2) bind mounts don't reliably propagate native filesystem-change-notification events into the container, which would otherwise make Vite's HMR miss host-side file edits. Polling trades a small, constant amount of CPU for actually detecting those edits — necessary specifically because of the Windows bind-mount path, same underlying class of issue as the PHP permission workaround above.

---

## Network Architecture

All 6 services share one private Docker bridge network (`app-network`). Containers address each other by **service name**, never `localhost` — inside a container, `localhost` means that container itself, not a neighboring one. Concretely:

```
Laravel (app, queue) → mysql:3306
Laravel (app, queue) → redis:6379
nginx                → app:9000
```

MySQL and Redis are not publicly exposed: MySQL is reachable from the host only via `127.0.0.1:3306` (never from another machine on the network), and Redis has no host port mapping at all.

---

## Environment / Secrets

Four env-related files exist, with a clear split between what's committed and what isn't:

| File | Committed? | Purpose |
|---|---|---|
| `.env` (root) | **No** — gitignored | Real local-dev MySQL credentials. Authoritative source for `MYSQL_*`/`DB_*` values during `docker compose up` (see credential-flow diagram above). |
| `.env.example` (root) | Yes | Placeholders only (`change_me`) — what a fresh clone copies to `.env` and fills in. |
| `backend/.env` | **No** — gitignored | Full Laravel configuration. Its `DB_*`/credential values are present for completeness/fallback only — the real values in effect come from the root `.env` via `docker-compose.yml` injection, not from this file, whenever running under Docker Compose. |
| `backend/.env.example` | Yes | Laravel's config shape with placeholders — `DB_HOST=mysql`/`REDIS_HOST=redis` (Docker service names) already set correctly, `DB_PASSWORD=change_me` etc. |

No actual passwords, `APP_KEY` values, API keys, or tokens of any kind appear in this document, in any `.env.example` file, or in `docker-compose.yml` (which references credentials only via `${VAR}` substitution from the root `.env` — never a literal value).

---

## Start / Stop Commands

```
Start:                 docker compose up -d
Start (rebuild first): docker compose up -d --build
Check status:          docker compose ps
Logs (all):            docker compose logs
Follow logs:            docker compose logs -f
Stop:                  docker compose down
```

**Stop and remove volumes — only when explicitly intended:**
```
docker compose down -v
```
This deletes persistent Docker volumes, **including the MySQL data volume (`mysql_data`)** — it is not a routine shutdown command and should never be run reflexively.

## Development Commands

```
# Laravel
docker compose exec app php artisan about
docker compose exec app php artisan test
docker compose exec app php artisan migrate
docker compose exec app php artisan tinker

# Composer (installing what's already declared — do not run `composer update`
# casually, since that can change locked dependency versions)
docker compose exec app composer install

# Frontend
docker compose exec node npm install
docker compose exec node npm run dev
docker compose exec node npm run build

# Queue
docker compose logs -f queue

# MySQL (use the credentials from your local .env — never paste the actual
# password into a shared file, command history, or documentation)
docker compose exec mysql mysql -u <username> -p

# Redis
docker compose exec redis redis-cli ping
```

---

## Verification Baseline

Performed during this environment's initial setup; recorded here rather than re-run, per the principle of not repeating expensive operations without reason.

| Check | Result |
|---|---|
| Docker Compose (`docker compose ps`, all 6 services) | PASS |
| PHP | PASS — 8.5.9 |
| Laravel | PASS — 13.29.0 |
| Composer | PASS — 2.10.2 |
| MySQL (real connection + `migrate` + live query) | PASS — 8.4.11 |
| Redis (real `ping()` + set/get round trip) | PASS — 7.4.11 |
| Queue (job dispatched and confirmed processed) | PASS |
| Nginx (`http://localhost:8080` → 200; sensitive paths → 404) | PASS |
| React | PASS — 19.2.8 |
| TypeScript | PASS — 6.0.3 |
| Vite (`http://localhost:5173` → 200, HMR active) | PASS — 8.2.2 |
| Laravel test suite | PASS — 2 tests / 2 assertions |

All 6 containers were re-confirmed still running (`docker compose ps`) immediately before this document was written, without re-running the full verification suite.
