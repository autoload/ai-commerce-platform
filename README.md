# AI Commerce Platform

A multi-tenant, multi-store e-commerce SaaS platform with an LLM-powered business analytics assistant. Merchants manage products, inventory, orders, and payments across one or more stores under an organization; store admins can ask natural-language questions about their business data, answered by an AI agent that calls authorized, tenant-scoped tools — never the database directly.

Built as a portfolio project demonstrating production-style patterns: multi-tenancy, RBAC, webhook idempotency, concurrency-safe inventory, and a tool-calling AI agent with enforced data isolation.

## Project Status

🚧 **Infrastructure / bootstrap stage. Business features are not implemented yet.**

- ✅ Architecture and database design — documented and approved
- ✅ Docker development environment — built and verified
- ⬜ Database migrations for the approved schema
- ⬜ Authentication, RBAC, multi-tenancy enforcement
- ⬜ Products, inventory, cart, checkout, orders
- ⬜ Stripe integration
- ⬜ Analytics and AI agent
- ⬜ Storefront and admin dashboard UI

See [`docs/development/project-status.md`](docs/development/project-status.md) for the authoritative, up-to-date status of every area.

## Technology Stack

Locked versions, verified inside the running development environment (not just declared in a config file):

| Layer | Technology | Version |
|---|---|---|
| Backend | PHP | 8.5.9 |
| Backend | Laravel | 13.29.0 |
| Backend | Composer | 2.10.2 |
| Backend | Laravel Sanctum | installed (foundation only) |
| Frontend | React | 19.2.8 |
| Frontend | TypeScript | 6.0.3 |
| Frontend | Vite | 8.2.2 |
| Frontend | Node.js | 24.19.0 |
| Database | MySQL | 8.4.11 |
| Cache / Queue | Redis | 7.4.11 |
| Web server | Nginx | 1.30.4 |
| Infrastructure | Docker | 29.7.2 |
| Infrastructure | Docker Compose | v5.4.0 |

## Architecture

```
Browser
   │
   ├──> Nginx :8080 ──> PHP-FPM (Laravel) :9000 ──┬──> MySQL :3306   (durable source of truth)
   │                                                └──> Redis :6379  (queue / cache — not source of truth)
   │
   └──> Vite dev server :5173 (React + TypeScript)
```

- **Laravel is the only component with database access** — the React frontend, AI tools, and any external service all go through the Laravel API.
- **MySQL is the durable source of truth**; Redis is queue backend + (future) cache/rate-limiting, never authoritative data.
- A dedicated **queue worker** runs `php artisan queue:work` against Redis, separate from the web-facing PHP-FPM process.
- Multi-tenancy (`Organization → Store → resources`), RBAC, and the AI agent's tool-calling design are documented in full in the architecture docs linked below — this README intentionally doesn't repeat them.

## Project Structure

```
ai-commerce-platform/
├── backend/        Laravel 13 application (PHP 8.5)
├── frontend/       React 19.2 + TypeScript + Vite application
├── docker/         Dockerfile, Nginx config, MySQL config
├── docs/
│   ├── architecture/    Approved system architecture + review record
│   ├── database/        Approved database design
│   └── development/     Project status + development environment reference
├── docker-compose.yml
├── .env.example    Docker Compose environment placeholders
└── .gitignore
```

## Local Development Setup

**Prerequisites**: Docker Desktop (with WSL2 backend on Windows).

```bash
# 1. Copy environment placeholders and fill in local values
cp .env.example .env
cp backend/.env.example backend/.env

# 2. Build and start the stack
docker compose up -d --build

# 3. Confirm everything is running
docker compose ps
```

## Environment Configuration

Two `.env` files, never committed — only their `.env.example` counterparts are:

- **Root `.env`** — MySQL credentials (`MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`). `docker-compose.yml` injects these into the `app`/`queue` containers as `DB_*` environment variables, which take precedence over anything in `backend/.env`.
- **`backend/.env`** — full Laravel configuration. Its own `DB_*`/`REDIS_*` values are fallbacks; `DB_HOST=mysql` and `REDIS_HOST=redis` (Docker service names, not `localhost`) matter most if running artisan outside `docker compose exec`.

Full explanation of the credential flow: [`docs/development/development-environment.md`](docs/development/development-environment.md#environment--secrets).

## Docker Commands

```bash
docker compose up -d           # start everything
docker compose up -d --build   # start, rebuilding images first
docker compose ps              # service status
docker compose logs -f         # follow all logs
docker compose logs -f queue   # follow one service's logs
docker compose down            # stop (keeps the mysql_data volume)
docker compose down -v         # stop AND delete volumes — deletes the database, use deliberately
```

## Laravel Commands

```bash
docker compose exec app php artisan about
docker compose exec app php artisan migrate
docker compose exec app php artisan tinker
docker compose exec app composer install
```

## Frontend Commands

```bash
docker compose exec node npm install
docker compose exec node npm run dev
docker compose exec node npm run build
```

## Testing

```bash
docker compose exec app php artisan test
```

## Development URLs

| Service | URL |
|---|---|
| Laravel (via Nginx) | http://localhost:8080 |
| React (Vite dev server) | http://localhost:5173 |
| MySQL (local tools only) | `127.0.0.1:3306` |

## Documentation

This README is intentionally an entry point, not a reference — full detail lives here:

- [`PRD.md`](PRD.md) — product requirements and MVP scope
- [`docs/architecture/system-architecture.md`](docs/architecture/system-architecture.md) — approved system architecture
- [`docs/architecture/architecture-review.md`](docs/architecture/architecture-review.md) — architecture review record
- [`docs/database/database-design.md`](docs/database/database-design.md) — approved database design
- [`docs/development/development-environment.md`](docs/development/development-environment.md) — full local environment reference
- [`docs/development/project-status.md`](docs/development/project-status.md) — current development status (source of truth)
- [`CLAUDE.md`](CLAUDE.md) — operating guidance for AI-assisted development on this repository
