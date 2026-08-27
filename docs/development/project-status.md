# Project Status

This document is the persistent source of truth for the current development state of the AI Commerce Platform. It exists so that development can continue correctly across sessions without relying on prior conversation context. Read this before starting significant work; update it after completing a significant phase.

## Current Phase

**Phase 2B — Customer Domain migrations. Complete and verified.** Phase 2A (Platform & Tenant Identity), Phase 1 (Docker Infrastructure Bootstrap), and Phase 0 (Architecture & Database Design, including the Database Design 2.1 Platform Admin correction) are all complete and approved. Phase 2B implemented, migrated, and verified the customer domain: `customers` and `customer_addresses`. **No models, policies, controllers, authentication flows, or API endpoints exist yet** — migrations only, per the incremental-by-functional-area plan. Full detail: "Phase 2B (Database Migrations)" section below (see "Phase 2A (Database Migrations)" for the prior batch).

**Development Environment**: see `docs/development/development-environment.md` for the full, permanent record of the verified local environment — exact versions, Docker architecture, service configuration, credential flow, and commands. That document is now the source of truth for environment detail; the "Infrastructure (Phase 1)" section below is a summary, not a duplicate.

**Database Design 2.2 is APPROVED** as the authoritative database design. Version 2.0 substantially upgraded the original MVP schema into a production-quality e-commerce data model — product variants/options, customer saved addresses, immutable order address snapshots, and Stripe saved payment methods — and resolved its one remaining open decision (order/payment status separation). Version 2.1, approved 2026-08-26, added **Platform Admin as a third, structurally separate identity domain** (`platform_admins` table, outside the Organization → Store → merchant-user hierarchy) plus an organization approval/suspension lifecycle (`organizations.status`: `pending → active`, with `rejected`/`suspended` branches and per-action audit columns). **Version 2.2, also approved 2026-08-26, removes `carts`/`cart_items` from the MySQL schema entirely** — carts are intentionally ephemeral for MVP (guest: browser `localStorage`; authenticated: Redis, tenant/customer-namespaced, TTL'd) and never persisted in MySQL; MySQL remains the durable source of truth beginning at the pending order. Both corrections were made deliberately *before* the relevant Phase 2 migrations were written — Platform Admin before Phase 2A began, and the Cart correction before Phase 2D (not yet started) — see "Platform Admin (Database Design 2.1)" and "Cart Architecture (Database Design 2.2)" under Database Decisions below. Phase 2A's six tables and Phase 2B's two tables (eight total) are now migrated and verified; the remaining tables in the schema have **not** been migrated yet.

## Completed

**Design / review work (all documentation, no code):**
- `PRD.md` authored — full product requirements and MVP scope.
- `CLAUDE.md` authored and corrected — Laravel Sanctum auth, pending-order-first payment flow, correct Laravel commands, React Query-first frontend state approach, structural multi-tenancy scoping strategy, AI-as-application-feature security framing, development rules, code-quality conventions, testing strategy, tech stack, a caution against relying on outdated Laravel AI SDK documentation, and project philosophy.
- Full system architecture review completed — covering architecture overview, multi-tenancy, auth/RBAC, order & payment architecture, inventory & concurrency, Redis/queue architecture, analytics architecture, AI agent architecture, API architecture, React architecture, a ranked security review, a scalability review, interview/portfolio-value analysis, and final recommendations with an architecture score of 7.5/10.
- Phase 0 database schema design completed — full entity list, ERD, relationship explanation, tenant isolation strategy, order/payment/inventory state models, constraints, and concurrency considerations.
- A focused schema review of that design — identifying and resolving critical issues, recommending improvements, and explicitly recording MVP simplifications that were deliberately accepted rather than overlooked.
- Documentation consolidated into the approved structure: `docs/architecture/architecture-review.md`, `docs/architecture/system-architecture.md`, `docs/database/database-design.md` (moved from `docs/architecture/` to `docs/database/`), with empty `docs/api/`, `docs/decisions/`, `docs/development/` reserved for future use.
- A documentation consistency check across `PRD.md`, `CLAUDE.md`, and the three architecture/database documents, surfacing one unresolved contradiction (see Known Issues).
- Persistent project-state tracking established (`docs/development/project-status.md`, plus the "Project State Management" section added to `CLAUDE.md`).
- **Database Design 2.0 — APPROVED**: the schema was upgraded from a minimal MVP catalog into a proper product/variant model. Major additions: `product_options`/`product_option_values`/`product_variants`/`product_variant_option_values` (Product Variant established as the single sellable unit — `cart_items`, `order_items`, `inventory`, and `inventory_transactions` all now reference `product_variant_id`, never `product_id`, directly); `customer_addresses` (reinstated saved-address book); `order_addresses` (immutable per-order shipping snapshot, replacing the old inline `shipping_*` columns on `orders`); `payment_methods` (Stripe saved payment methods) and `customers.stripe_customer_id`; a corrected inventory idempotency key (`inventory_transactions` now keyed by `order_item_id`, not `order_id` — the old key was a real bug once orders could contain multiple line items); an explicit soft-delete strategy (mutate-on-delete) and cascading-soft-delete policy (blocked at the application layer, not cascaded). Full detail in `docs/database/database-design.md`.
- **Order/payment status separation resolved and approved** — see "Final Resolution" under Database Decisions below.
- **Platform Admin architecture correction (Database Design 2.1)** — identified before any Phase 2 migration was written: `platform_admins` added as a third identity domain, structurally separate from `users`/`organization_user`/`store_user` and from `customers`; `organizations` gained an approval/suspension lifecycle (`status`, `status_reason`, `approved_at`/`approved_by_platform_admin_id`, `rejected_at`/`rejected_by_platform_admin_id`, `suspended_at`/`suspended_by_platform_admin_id`). `CLAUDE.md`, `docs/architecture/system-architecture.md`, `docs/database/database-design.md`, and `PRD.md` (new §3.4) all updated. See "Platform Admin (Database Design 2.1)" under Database Decisions below for full detail.
- **Cart Architecture correction (Database Design 2.2)** — identified and approved before Phase 2D (Cart & Orders, not yet started) was reached: `carts`/`cart_items` removed from the MySQL schema entirely. Cart state for MVP is ephemeral — guest carts in browser `localStorage`, authenticated-customer carts in Redis (server-derived tenant/customer key, TTL'd) — with checkout treating cart contents as untrusted input and revalidating/recalculating everything against MySQL. `CLAUDE.md`, `docs/architecture/system-architecture.md` (new §7 "Cart Architecture"), and `docs/database/database-design.md` all updated; no Phase 2A/2B migration was affected. See "Cart Architecture (Database Design 2.2)" under Database Decisions below for full detail.
- **Phase 1 — Docker Infrastructure Bootstrap (implementation, not documentation)**: Laravel 13 scaffolded under `backend/`, React 19.2 + TS + Vite scaffolded under `frontend/`, full Docker Compose stack (app, nginx, mysql, redis, queue, node) built and verified running, Sanctum + API routing foundation installed. See "Infrastructure (Phase 1)" below for exact versions, verification results, and commands.
- **Phase 2A — Platform & Tenant Identity migrations (implementation, verified)**: six migrations created and run against MySQL — `platform_admins`, `organizations`, `stores`, `add_soft_deletes_to_users_table`, `organization_user`, `store_user`. Verified via `php artisan migrate --pretend` (SQL reviewed before running), `php artisan migrate` (batch 2, all six `Ran`), `php artisan db:table` on all six tables (columns, defaults, FK actions, indexes, unique constraints all confirmed matching Database Design 2.1), `php artisan migrate:status`, and `php artisan test` (2/2 passing — Laravel's default example tests; no schema-specific tests exist yet). See "Phase 2A (Database Migrations)" below for full detail.
- **Phase 2B — Customer Domain migrations (implementation, verified)**: two migrations created and run against MySQL — `create_customers_table`, `create_customer_addresses_table`. Verified via `php -l` on both files, `php artisan migrate --pretend` (SQL reviewed before running), `php artisan migrate` (batch 3, both `Ran`), `php artisan db:table` on both tables (columns, defaults, FK actions — including the `RESTRICT`/`RESTRICT`/`CASCADE` behavior decided for the previously-unresolved `customers` tenant FKs — indexes, unique constraints all confirmed matching the approved Phase 2B plan), `php artisan migrate:status`, and `php artisan test` (2/2 passing). See "Phase 2B (Database Migrations)" below for full detail.

**Implementation work completed:** infrastructure (Phase 1) and Phase 2A/2B migrations (above) — all verified working via actual command execution, not assumed. No models, policies, controllers, business API routes, or authentication flows exist yet; no tests beyond Laravel's default example tests.

## Current Task

None in progress. Phase 2B is complete and verified; the Cart Architecture correction (Database Design 2.2) has since been completed as a documentation-only change. Phase 2C has **not** started. The project is paused awaiting explicit instruction to begin Phase 2C.

## Next Step

Phase 2C — Catalog: `products`, `categories`, `product_options`, `product_option_values`, `product_variants`, `product_variant_option_values`, `product_images`, once explicitly approved. **Not started** — the Cart Architecture correction (Database Design 2.2, above) was completed first, before Phase 2C began, the same way the Platform Admin correction preceded Phase 2A. Do not begin Phase 2C without explicit approval, consistent with the incremental-by-functional-area approach used for Phases 2A/2B (Design → Review → Migration → Run migration → Verify → Sign-off → Next phase).

Planned subsequent phases after 2C (functional-area batches, each requiring sign-off before the next):
- **2D — Orders**: `orders`, `order_items`, `order_addresses` (renamed from "Cart & Orders" — `carts`/`cart_items` are no longer part of the MySQL schema at all; see Database Design 2.2)
- **2E — Payments**: `payments`, `payment_methods`, `refunds`, `stripe_webhook_events`
- **2F — Inventory**: `inventory`, `inventory_transactions`
- Later phases cover analytics/AI reports (`reports` table) and are not yet broken down in detail.

## Phase 2A (Database Migrations)

**Status: complete and verified**, 2026-08-26. Six migration files under `backend/database/migrations/`, run against the `mysql` container's `ai_commerce` database in batch 2:

| Migration | Table / change | Key facts verified in the running database |
|---|---|---|
| `2026_08_26_220100_create_platform_admins_table` | `platform_admins` (new) | `email` unique; no `organization_id`/`store_id`; no `role`; `deleted_at` present (soft delete). |
| `2026_08_26_220200_create_organizations_table` | `organizations` (new) | `status` varchar default `pending`; `status_reason` nullable; `approved_at`/`rejected_at`/`suspended_at` nullable; `approved_by_platform_admin_id`/`rejected_by_platform_admin_id`/`suspended_by_platform_admin_id` each FK → `platform_admins.id` `ON DELETE SET NULL`; index on `status`; unique `slug`; `deleted_at` present. |
| `2026_08_26_220300_create_stores_table` | `stores` (new) | `organization_id` FK → `organizations.id` `ON DELETE RESTRICT`; `status` default `active`; unique `(organization_id, slug)`; index `organization_id`; `deleted_at` present. |
| `2026_08_26_220400_add_soft_deletes_to_users_table` | `users` (altered) | `deleted_at` added to the existing default-Laravel `users` table; no other column changed. |
| `2026_08_26_220500_create_organization_user_table` | `organization_user` (new) | `organization_id`/`user_id` FKs `ON DELETE CASCADE`; `role` varchar not null; unique `user_id` (one org per user, MVP); index `organization_id`. |
| `2026_08_26_220600_create_store_user_table` | `store_user` (new) | `user_id`/`store_id` FKs `ON DELETE CASCADE`; no role column; unique `(user_id, store_id)`; index `store_id`. |

Verification method: `migrate --pretend` reviewed before running; `migrate` executed; `php artisan db:table <table>` run against all six tables and cross-checked column-by-column against `docs/database/database-design.md`; `migrate:status` confirms all six in batch 2 as `Ran`; `php artisan test` passes (2/2 — Laravel defaults only, no schema-specific tests written yet, consistent with migrations-only scope). No conflicts found between the implementation and Database Design 2.1. `organization_user`/`store_user` column/FK/index choices were inferred (the design doc says "unchanged — see prior design," not reproduced there) — flagged, not objected to.

Not yet done (by design, next phases): Eloquent models, the mutate-on-delete soft-delete observers, RBAC policies, TenantContext middleware, any seeders, and Phase 2B onward's tables.

## Phase 2B (Database Migrations)

**Status: complete and verified**, 2026-08-26. Two migration files under `backend/database/migrations/`, run against the `mysql` container's `ai_commerce` database in batch 3:

| Migration | Table / change | Key facts verified in the running database |
|---|---|---|
| `2026_08_26_220700_create_customers_table` | `customers` (new) | `organization_id`/`store_id` FKs → `organizations.id`/`stores.id`, both `ON DELETE RESTRICT` (previously-unresolved decision, confirmed by you this phase); unique `(store_id, email)`; unique `stripe_customer_id` (nullable-safe); index `organization_id`; `deleted_at` present (soft delete). |
| `2026_08_26_220800_create_customer_addresses_table` | `customer_addresses` (new) | `customer_id` FK → `customers.id`, `ON DELETE CASCADE`; no `organization_id`/`store_id` (scoped via `customers` parent); no unique constraint (the "one `is_default` per customer" rule is an application invariant, not DB-enforced, per the design); index `customer_id`; no `deleted_at` (hard-delete child, not in the soft-delete table list). |

Verification method: `php -l` on both files; `migrate --pretend` reviewed before running; `migrate` executed; `php artisan db:table <table>` run against both tables and cross-checked column-by-column against `docs/database/database-design.md`; `migrate:status` confirms both in batch 3 as `Ran`; `php artisan test` passes (2/2 — Laravel defaults only). No conflicts found between the implementation and Database Design 2.1.

Not yet done (by design, next phases): Eloquent models, the address-service `is_default` invariant, RBAC/policies, TenantContext middleware, any seeders, and Phase 2C onward's tables.

## Not Started

- Database migrations for the approved business schema (products, product options/variants, categories, orders, payments, refunds, inventory, reports, etc.) — `platform_admins`, `organizations`, `stores`, `users` (soft-delete), `customers`, and `customer_addresses` are done, see Phase 2A/2B above. Note: `carts`/`cart_items` are **not** on this list at all — per Database Design 2.2, they are never migrated to MySQL; cart state is ephemeral (guest `localStorage`, authenticated Redis).
- Platform Admin authentication, authorization, and API surface (`/api/platform/*`) — design only so far, per Database Design 2.1
- Eloquent models
- TenantContext / multi-tenancy middleware and Eloquent global scopes
- RBAC / Policies
- Authentication implementation (login/register/logout/password reset) — Sanctum is installed but no auth flow exists
- Customer authentication
- Product management, product variants/options, categories, product images
- Inventory management business logic
- Cart / checkout business logic — will use browser `localStorage` (guest) and Redis (authenticated, server-derived tenant/customer key) per Database Design 2.2, not MySQL `carts`/`cart_items` tables
- Order business logic
- Stripe integration (PaymentIntents, webhooks, refunds)
- Business queue jobs
- Redis analytics caching and rate limiting rules
- Analytics service/endpoints
- AI agent, AI tools, and Laravel AI SDK integration
- Report generation
- REST business API endpoints
- Admin dashboard and storefront UI (React business features)
- Business test coverage (Pest/PHPUnit, Playwright)
- CI/CD (GitHub Actions)
- Deployment (Laravel Cloud)

## Infrastructure (Phase 1)

Complete and verified by actually running every command below — not assumed.

**Technology (as actually installed, verified inside the running containers):**

| Component | Version |
|---|---|
| PHP | 8.5.9 |
| Laravel | 13.29.0 |
| Composer | 2.10.2 |
| React | 19.2.8 |
| React DOM | 19.2.8 |
| TypeScript | 6.0.3 |
| Vite | 8.2.2 |
| Node.js | 24.19.0 (`node:24-alpine`) |
| MySQL | 8.4.11 |
| Redis | 7.4.11 |
| Nginx | 1.30.4 |
| Docker | 29.7.2 |
| Docker Compose | v5.4.0 |

**Infrastructure — DONE (verified):**
- Docker Compose stack (`app`, `nginx`, `mysql`, `redis`, `queue`, `node`) — all 6 services build and run; `mysql`/`redis` pass healthchecks before dependents start.
- PHP-FPM (`app`) — PHP 8.5.9 confirmed, all required extensions present (PDO, pdo_mysql, mbstring, bcmath, intl, xml, ctype, fileinfo, tokenizer, openssl, pcntl, redis/PhpRedis, zip).
- Nginx — serves Laravel on `http://localhost:8080` (HTTP 200 confirmed); `.env`, `.git`, `vendor`, `composer.json` all confirmed returning 404, not served.
- MySQL — Laravel connects, runs migrations, and executes real queries (`php artisan db:show`, `php artisan migrate`, a live `SELECT 1+1` all confirmed) — not just "container is running."
- Redis — Laravel connects via the real `Redis` facade (`ping()` and a `set`/`get` round trip both confirmed via PhpRedis), not faked.
- Queue worker — a temporary `TestInfrastructureJob` was dispatched and confirmed processed (Laravel → Redis → queue worker → job executed, verified via both worker logs and an independent Redis marker written by the job); removed after verification.
- Laravel — `php artisan --version` confirms Laravel Framework 13.29.0; default framework test suite passes (2 tests, 2 assertions).
- React/Vite — serves on `http://localhost:5173` (HTTP 200 confirmed, HMR client + React refresh present); exact installed versions confirmed as above.
- Sanctum foundation — `laravel/sanctum` v4.3.3 installed, `php artisan install:api` run (creates `routes/api.php`, publishes the `personal_access_tokens` migration). No login/register/logout/RBAC/policies implemented — foundation only, as scoped.

**Not part of Phase 1 (unchanged, still not started):** database migrations for the approved business schema, TenantContext, RBAC, authentication flows, and everything else listed under "Not Started" above.

**Project structure**: Laravel lives entirely under `backend/` (not the repo root); React lives entirely under `frontend/`. Docker/nginx/mysql config lives under `docker/`.

**Root `.env` vs. `backend/.env`** — the chosen simplest-maintainable pattern: the project-root `.env` (gitignored; `.env.example` committed) is the single source of truth for MySQL credentials (`MYSQL_DATABASE`/`MYSQL_USER`/`MYSQL_PASSWORD`/`MYSQL_ROOT_PASSWORD`). `docker-compose.yml` both configures the `mysql` service from these values *and* injects the same values as real container environment variables into `app`/`queue` (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, plus `DB_HOST=mysql`/`REDIS_HOST=redis`/`QUEUE_CONNECTION=redis`). Because Laravel's `.env` loader (`vlucas/phpdotenv`) never overwrites a variable that's already set in the real process environment, these injected values always win — `backend/.env`'s own `DB_*` lines are inert fallbacks (kept in sync for clarity, and for the rare case of running artisan outside `docker-compose exec`), never a second source of truth that could drift from the root `.env`.

**Port bindings**: Nginx `8080` (all interfaces, browser-facing) · Vite `5173` (all interfaces, browser-facing) · MySQL `127.0.0.1:3306` only (local dev-tool access, e.g. TablePlus/DBeaver — never exposed beyond localhost) · Redis has no host port mapping at all (fully internal to the Docker network) · PHP-FPM (`app:9000`) is never exposed to the host, only reachable from `nginx` over the internal network.

**MySQL data persistence**: `mysql_data` is a named Docker volume — survives `docker compose down` and container recreation (confirmed: data survived an app/queue rebuild+recreate during this session). `docker compose down -v` would delete it and should not be used as a routine shutdown command.

**Development commands:**

```
Start:            docker compose up -d
Stop:              docker compose down            (never `down -v` unless intentionally wiping the DB)
Status:            docker compose ps
Logs (all):        docker compose logs
Logs (one):        docker compose logs -f queue
Laravel shell:     docker compose exec app bash
Artisan:           docker compose exec app php artisan ...
Composer:          docker compose exec app composer ...
Frontend/npm:      docker compose exec node npm ...
Backend tests:     docker compose exec app php artisan test
```

Laravel: http://localhost:8080 — React/Vite: http://localhost:5173

**Warnings / notes for the next session:**
- A real, now-fixed issue: PHP-FPM's `www-data` worker user cannot write to `storage/`/`bootstrap/cache` on this Windows → Docker Desktop (WSL2) bind mount (files land owned in a way non-root can't write to, regardless of `chmod` from the Windows side — `chmod` via Git Bash on the host is a no-op on NTFS). Fixed via `docker/php/entrypoint.sh`, which `chmod -R 777`s those two directories as root on every container start before dropping to the configured command. This is a local-dev-only concession specific to the Windows bind-mount path — irrelevant to a real (non-bind-mounted) deployment. Attempting to instead run the php-fpm pool itself as root was tried first and rejected: PHP-FPM hard-refuses to start with `user`/`group` set to root ("please specify user and group other than root").
- A transient, self-resolving error was observed the first time the `queue` container started before `php artisan migrate` had run (Laravel's default `CACHE_STORE=database` means the queue worker's restart-signal check needs a `cache` table that didn't exist yet). Resolved automatically once migrations ran; not an infrastructure defect, just documented here so it isn't mistaken for one if seen again on a completely fresh `docker compose up` before ever running `php artisan migrate`.
- Laravel's default skeleton ships auto-generated `CLAUDE.md`/`AGENTS.md` files (instructing an agent to install `laravel/boost`) inside a fresh `backend/` — these were deleted after scaffolding since they weren't requested and this repo's root `CLAUDE.md` already governs the whole project.
- `backend/database/database.sqlite` (Laravel 11+'s default local-dev DB) was removed since the project targets MySQL, not SQLite.
- Docker itself is installed at a non-default path on this machine (`C:\Users\guoch\AppData\Local\Programs\DockerDesktop\`, not `Program Files`) and is not on this shell session's PATH — commands in this session used the full path. A fresh terminal (or a PATH update) should resolve `docker` normally going forward.
- `docker compose exec` defaults to `root` unless `-u` is passed (there's no `USER` directive in the Dockerfile) — this is fine for local dev/debugging exec sessions; the actual php-fpm request-handling workers run as `www-data` per the pool config.

## Important Architectural Decisions

These are established and must not be silently changed by a future session. See `docs/architecture/system-architecture.md` for full detail.

- Laravel is the only component with database access.
- React communicates with Laravel exclusively through the API — no direct DB/Stripe/LLM access from the frontend.
- MySQL is the durable source of truth.
- Redis is used for cache, queue backend, rate limiting, and authenticated-customer cart storage (ephemeral, TTL'd, server-derived tenant/customer key — Database Design 2.2), but is not a source of truth for anything, including the cart role.
- **Platform Admin is a third, structurally separate identity domain** — `platform_admins`, outside the Organization → Store → merchant-user hierarchy, with its own Sanctum guard. It is never merged into `users`/`organization_user`/`store_user`, never granted a role in the merchant RBAC ladder, and never evaluated against a tenant `TenantContext` (it isn't scoped to any organization/store).
- Tenant context (organization, store, user, role) is resolved server-side, once per request, and bound for reuse by controllers, policies, scopes, and AI tools.
- AI tools must never receive organization/store identity from the LLM — tenant context is injected server-side into every tool call.
- The REST API and AI tools share the same authorization rules and the same underlying application services — there is no separate, looser data-access path for AI.
- Stripe webhook processing is signature-verified and idempotent.
- Webhook event insertion (`stripe_webhook_events`) and the order's `pending → paid` state transition must occur within the same database transaction.
- `orders.status` (fulfillment lifecycle) and `payments.status` (payment-attempt lifecycle) are separate state machines — never merge them into a single column; payment status at the order level is always derived, never stored as `orders.payment_status`.
- Inventory mutation uses row-level locking (`SELECT ... FOR UPDATE`) plus an append-only transaction ledger (`inventory_transactions`); `inventory.quantity_on_hand` is a maintained materialization of that ledger.
- Inventory is never directly modified by request handlers — all mutation goes through one locked service method.
- Order totals are always calculated server-side, never accepted from the client.
- No guest checkout for MVP — customer accounts are required. **Clarification (Database Design 2.2)**: guest browsing and guest cart-building (via `localStorage`) are permitted; "no guest checkout" refers only to the checkout/payment step itself, which always requires an authenticated `customers` account.
- **Carts are not persisted in MySQL for MVP** (Database Design 2.2) — guest: `localStorage`; authenticated: Redis. Checkout treats cart contents as untrusted input and always revalidates `product_variant_id`/quantity and recalculates price/totals against MySQL. Do not reintroduce a MySQL `carts`/`cart_items` table without explicitly reconsidering and re-approving this architecture.
- MVP does not use inventory reservations/soft-holds — inventory decrements only at the `paid` transition.
- Analytics use direct transactional queries against MySQL with Redis cache-aside caching — no pre-aggregated/materialized rollup tables for MVP.
- AI Q&A and Insights are not persisted for MVP — computed on demand, optionally Redis-cached.
- AI Reports are persisted (the one durable AI-related table).

## Database Decisions

Full detail lives in `docs/database/database-design.md` — this is a summary, not a substitute. Reflects **Database Design 2.2 (APPROVED)**.

### Final Resolution — Order/Payment Status Separation (CLOSED)

- `orders.status` and `payments.status` are **separate state machines** — confirmed structurally, not just by convention: one order has exactly one fulfillment status at a time, but can have many payment attempts (1 order : N `payments` rows, one per PaymentIntent including retries), each running its own independent instance of the payment lifecycle.
- `orders.status` represents the **order/fulfillment lifecycle**: `pending → paid → processing → shipped → completed`, with `cancelled`/`refunded` branches. `paid` and `refunded` are the order's own fulfillment milestones, not a mirror of payment-attempt detail.
- `payments.status` represents the **individual payment-attempt lifecycle**: `requires_payment → processing → {succeeded | failed}`, or `→ canceled`. Terminal states are never reopened; a retry is always a new row.
- **Payment status at the order level is derived, never stored.** There is no `orders.payment_status` column. When an order needs to display a payment status, it is computed from the order's associated payment attempt(s) — satisfying PRD.md §7.1's "Payment Status" field as a view-level concept, not a second independently-writable column that could drift from `payments`.
- **A payment reaching `succeeded` is the sole controlled trigger for `orders.status: pending → paid`.**
- **A refund reaching `succeeded` is the sole controlled trigger for `orders.status: {paid,...} → refunded`.**
- No other payment or refund state (`processing`, `failed`, `canceled`, refund `pending`/`failed`) ever mutates `orders.status`.

Full transition tables, webhook/refund/failed-payment/retry behavior, and diagrams: `docs/database/database-design.md` §"Order & Payment State Models — Authoritative Interaction Model."

### Platform Admin (Database Design 2.1, approved 2026-08-26)

Identified and corrected before any Phase 2 migration was written — PRD.md originally described only Customer / Store Admin / Organization Owner (§3.1–3.3), with no platform-operator role. `platform_admins` is a new, standalone table:

- No `organization_id`/`store_id` — ever. Never referenced by `organization_user`/`store_user` as a member.
- No `role` column for MVP — a single flat Platform Admin capability set, not a tiered platform-role system (nothing requires that yet).
- `organizations` gains a lifecycle: `status` (`pending`→`active`, or `rejected`/`suspended`), default `pending` — **a new organization requires Platform Admin approval before it can operate.**
- Explicit per-action audit column pairs (not a single `reviewed_by`/`reviewed_at`, which would lose history across repeated status changes): `approved_at`/`approved_by_platform_admin_id`, `rejected_at`/`rejected_by_platform_admin_id`, `suspended_at`/`suspended_by_platform_admin_id`, plus `status_reason`. All three `*_by_platform_admin_id` columns are nullable FKs → `platform_admins.id`, `ON DELETE SET NULL`. Rejection got its own audit pair (not just `status_reason`) specifically for symmetry/accountability with approval — see `docs/database/database-design.md` for the full reasoning.
- No separate `audit_logs` table added — these column pairs are the only lifecycle-audit facts currently required.
- Three identity tables now exist platform-wide: `platform_admins`, `users` (merchant), `customers` (shopper) — each with its own Sanctum guard, all sharing Sanctum's existing polymorphic `personal_access_tokens` table (no schema change needed there).

Full schema, rationale, and ERD: `docs/database/database-design.md`.

### Cart Architecture (Database Design 2.2, approved 2026-08-26)

Identified and approved before Phase 2D (Cart & Orders, not yet started) was reached — no migration existed for `carts`/`cart_items` at the time of this correction, so nothing needed to be reverted.

- **No `carts`/`cart_items` MySQL tables.** Cart state for MVP is ephemeral: guest carts live in browser `localStorage`; authenticated-customer carts live in Redis, keyed by a **server-derived** tenant/customer namespace (never client-supplied), with a TTL.
- **Redis remains non-durable, not a source of truth** — the cart role is held to the same standard as Redis's other three roles. Cart loss (eviction, TTL expiry, cleared browser) is an accepted, low-stakes failure mode; the durable record begins at the **pending order**, not at any cart representation.
- **Cart contents are untrusted input.** Checkout reads only `product_variant_id`/quantity from the cart; price, availability, inventory, and totals are always revalidated/recalculated from MySQL — reinforcing, not changing, the existing "order totals always server-side" rule.
- **No FK-based cleanup anymore** — a deleted/archived `product_variant` is not automatically pruned from a cart the way the old `CASCADE` FK on `cart_items` would have done; checkout (and any cart-read path) must defensively handle a stale `product_variant_id` reference.
- **Intended concurrency primitive (not yet built)**: a Redis Hash per cart (field = `product_variant_id`, value = quantity), mutated via atomic `HINCRBY` — the same no-lost-update guarantee the old `unique(cart_id, product_variant_id)` + upsert pattern provided.
- **Merge-on-login strategy** and **Redis eviction/isolation policy** (cache role vs. cart role sharing one Redis instance) are both **intentionally left as future implementation/operational decisions** — not resolved now.
- **No guest checkout, unchanged**: guest browsing/cart-building is permitted; checkout still requires an authenticated `customers` account.
- **Do not reintroduce a MySQL `carts`/`cart_items` table** without explicitly reconsidering and re-approving this architecture.

Full schema-level rationale: `docs/database/database-design.md` §"Cart — intentionally NOT a MySQL table." Full architectural detail: `docs/architecture/system-architecture.md` §7 "Cart Architecture."

- **Tenant hierarchy**: `Organization → Store → store-scoped resources`. Platform Admin (`platform_admins`) sits above this hierarchy, not inside it.
- **Tenant columns**: every top-level, independently-queried tenant-scoped table carries `organization_id` (and `store_id`, where applicable) directly — `products`, `categories`, `orders`, `payments`, `refunds`, and now `product_variants` (promoted in 2.0, since variants are queried independently — SKU lookups, low-stock reports — not only reached through a parent). Pure child/detail tables (`order_items`, `product_images`, `inventory_transactions`, `product_options`, `product_option_values`, `product_variant_option_values`, `customer_addresses`, `payment_methods`, `order_addresses`) rely on their parent's tenant columns instead.
- **Users vs. customers**: two separate identities, unchanged.
- **RBAC structure / store-user assignment**: unchanged — `organization_user` (one org per user for MVP), `store_user`.
- **The sellable unit is the Product Variant, not the Product** (Database Design 2.0's central change). `products` is catalog/marketing data only — no price, no SKU. `product_variants` carries the canonical `sku` and `price`; a product without meaningful variation still gets exactly one "default" variant, so there is only ever one inventory/pricing system, never two competing ones. `order_items`, `inventory`, and `inventory_transactions` all reference `product_variant_id` (cart state also references it, ephemerally, outside MySQL — see Cart Architecture above).
- **Product options/variants are relational, not JSON**: `product_options` → `product_option_values` → `product_variants` → `product_variant_option_values` (pivot). Options are scoped per-product (no shared global option library — avoids building a full PIM). Duplicate variant combinations are prevented by a database-enforced `unique(product_id, option_signature)`, where `option_signature` is an application-maintained, sorted signature of the variant's option-value set.
- **Order creation before payment**: unchanged.
- **Order item snapshots**: `order_items` now also snapshots `selected_options` (JSON — a frozen historical snapshot, not a live relational structure) alongside `product_name`, `sku` (the variant's SKU), and `unit_price`. Both `product_id` and `product_variant_id` are nullable with `ON DELETE SET NULL`.
- **Customer addresses**: `customer_addresses` (reinstated — live, mutable, reusable) is distinct from `order_addresses` (new — immutable per-order shipping snapshot, replacing the old inline `shipping_*` columns on `orders`). No billing address is stored for MVP; Stripe's own payment collection handles billing-address/AVS needs.
- **Stripe customer identity & saved payment methods**: `customers.stripe_customer_id` (nullable, unique). `payment_methods` stores only Stripe identifiers and non-sensitive display metadata (brand/last4/expiry) — never card numbers or CVV — and is kept structurally separate from `payments` (saved/reusable data vs. one payment attempt).
- **Payment and refund structure**: unchanged in shape; `payments` gains a nullable `payment_method_id` reference. MVP scope remains full-order refunds only, but the schema is already shaped so partial refunds could be added later without restructuring (see inventory idempotency fix below).
- **Inventory design**: `inventory` (one row per **variant**, current `quantity_on_hand`, plus a new nullable `low_stock_threshold` for derived low-stock queries — no separate low-stock table) plus `inventory_transactions` (append-only ledger: `sale`/`restock`/`adjustment`/`refund`).
- **Inventory idempotency key corrected**: `inventory_transactions` is now keyed by `(order_item_id, reason)`, not `(order_id, reason)`. The old key was a real bug once an order can contain multiple line items — it could only guarantee one `'sale'` row per whole *order*, not per line item, meaning a multi-item order would either fail to insert its second item's transaction or silently under-track inventory. This was found and fixed during the Database Design 2.0 pass, not merely renamed.
- **Stripe webhook idempotency**: unchanged, confirmed still sufficient after 2.0's additions.
- **Order numbers, enum representation, MySQL version requirement**: unchanged (ULID order numbers; varchar + PHP enum casts, not MySQL `ENUM`; MySQL ≥8.0.16).
- **Soft-delete strategy — now resolved (was open)**: mutate-on-delete. A soft-deleted row's unique value (`email`, `slug`, `sku`) is suffixed at delete time (e.g. via a model `deleting` observer), freeing it for reuse rather than permanently reserving it. Applied to `organizations`, `stores`, `users`, `customers`, `products`, `product_variants`, `categories`.
- **Cascading soft-deletes — now resolved (was open)**: soft-deleting a `store` does **not** cascade to its `products`/`categories`/`customers`. Deactivation is blocked at the application layer while active resources exist, rather than silently cascading.

## Open Decisions

**0.** None remain. The last open item — PRD.md §7.1 "Payment Status" vs. "Order Status" — is **CLOSED**; see "Final Resolution" under Database Decisions above.

## Known Issues

None currently blocking. Two minor documentation-staleness items were noted during the last consistency review and are worth a cleanup pass at some point, but neither blocks implementation:
- `docs/architecture/system-architecture.md` §5 still describes "one `inventory` row per product" — now stale, should read "per product variant" per Database Design 2.0.
- `docs/architecture/architecture-review.md` (a historical review record, intentionally not rewritten) contains a couple of now-superseded schema references (e.g. a `products.sku` constraint, the old `(order_id, reason)` inventory idempotency key) from before Database Design 2.0 — a short superseding note has been added there rather than altering its historical findings.

## Files and Documentation

| File | Purpose |
|---|---|
| `PRD.md` | Product requirements and MVP scope. Source of truth for *what* the product must do. |
| `CLAUDE.md` | Operating guidance for Claude Code in this repository — architecture summary, corrected technical decisions (Sanctum, payment flow, commands, frontend state, etc.), development rules, code-quality conventions, tech stack. |
| `docs/architecture/architecture-review.md` | The authoritative review record — critical issues, recommended improvements, MVP decisions intentionally accepted, security risk ranking, scalability notes, interview/portfolio value, final score. |
| `docs/architecture/system-architecture.md` | The resulting approved system design — component responsibilities, multi-tenancy, auth/RBAC, order/payment lifecycle, inventory/concurrency, Redis/queues, analytics, AI agent, API surface, frontend architecture. |
| `docs/database/database-design.md` | The approved database schema (Database Design 2.2) — every table, ERD, state models, constraints, and concurrency considerations. No open decisions remain. |
| `docs/development/project-status.md` | This file — persistent development-state tracker, read/updated across sessions instead of relying on conversation memory. |
| `docs/development/development-environment.md` | Permanent record of the verified local Docker development environment — exact versions, architecture, service config, credential flow, commands. |
| `docs/api/`, `docs/decisions/`, `docs/development/` (besides this file) | Reserved, currently empty — for future API reference docs, architecture decision records, and other development documentation as the project progresses. |
| `backend/` | The Laravel 13 application (PHP 8.5). Infrastructure only — no business models/controllers/migrations yet. |
| `frontend/` | The React 19.2 + TypeScript + Vite application. Infrastructure shell only — no business UI yet. |
| `docker/php/Dockerfile`, `docker/php/entrypoint.sh` | PHP 8.5-FPM image (shared by `app` and `queue`) and its startup permission fix (see Infrastructure section above). |
| `docker/nginx/default.conf` | Nginx config — Laravel front-controller routing, denies `.env`/`.git`/`vendor`/etc. |
| `docker/mysql/my.cnf` | MySQL server configuration (no credentials). |
| `docker-compose.yml` | Defines all 6 services, the private `app-network`, and the `mysql_data`/`frontend_node_modules` volumes. |
| `.env` / `.env.example` | Root Docker Compose environment (MySQL credentials) — see "Root `.env` vs. `backend/.env`" above. |

## Development Rules

1. Read `CLAUDE.md` before making significant changes.
2. Read `project-status.md` before making significant changes.
3. Read the relevant architecture/database documentation before modifying the corresponding implementation.
4. Treat approved architecture and database design as authoritative.
5. Do not silently redesign approved architecture.
6. Do not introduce new dependencies without justification.
7. Do not implement speculative features.
8. Do not mark work as completed until it has actually been implemented and verified.
9. After completing a significant development phase, update `project-status.md`.
10. Record important decisions and deviations from the approved design.
11. Keep documentation and implementation consistent.
12. If implementation conflicts with the approved architecture, stop and report the conflict instead of silently changing the architecture.
13. Never rely solely on previous conversation context to understand project state.
14. Git history and project documentation should be treated as persistent project history.
15. Before starting a new major phase, verify the current state of the repository using `git status` and the relevant documentation.
