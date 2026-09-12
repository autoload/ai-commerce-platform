# AI Commerce Platform

A multi-tenant, multi-store e-commerce platform (Laravel 13 + React 19/TypeScript) built as a portfolio project demonstrating production-style backend and frontend engineering: multi-tenancy, RBAC, Stripe payments with idempotent webhooks, concurrency-safe inventory, and a structured commerce data model. An LLM-powered business analytics assistant is part of the long-term product design (see "AI Assistant" below) but **is not implemented yet** — no AI/LLM code exists in this repository today.

## Project Status

🚧 **Active development. Commerce platform functionality is substantially implemented; AI features are not started.**

- ✅ Architecture and database design
- ✅ Multi-tenant auth (Platform Admin / Merchant / Customer, three separate Sanctum guards) and RBAC (Owner ⊃ Store Admin ⊃ Staff)
- ✅ Organization lifecycle (Platform Admin approve/reject/suspend/reactivate)
- ✅ Store, Category, Product, and Inventory management
- ✅ Customer-facing storefront (catalog, cart, checkout, order history)
- ✅ Stripe PaymentIntents, idempotent webhooks, payment retry, expiry sweep
- ✅ Orders (merchant lifecycle), Refunds, and known late-payment edge-case handling (see "Known Edge-Case Handling")
- ✅ Analytics v1 (sales/orders/products/customers, store-scoped)
- ⬜ AI Assistant / AI Tools / Insights / Reports — not started (see "AI Assistant")
- ⬜ CI/CD pipeline

See [`docs/development/project-status.md`](docs/development/project-status.md) for the detailed, phase-by-phase development history.

## What This Is

Two goals drive this project: (1) a working commerce platform — products, cart, checkout, Stripe payments, inventory, order management, refunds, analytics — and (2) demonstrating production-grade backend/frontend patterns: strict tenant isolation, policy-based authorization, webhook idempotency, row-locked inventory concurrency, and a from-scratch order/payment state machine. Per the project's own stated principle: *build a real business workflow first, then use AI to make it smarter* — the current focus is the commerce workflow; the AI layer is deliberately sequenced after it and has not begun.

## Key Features

**Multi-tenancy & Auth**
- Three structurally separate identity domains, each with its own Sanctum guard: `platform_admins`, `users` (merchant Owner/Store Admin/Staff), `customers`. No shared table, no shared guard.
- Tenant context (organization/store) is resolved server-side from the authenticated identity via middleware — never from client-supplied IDs.
- RBAC: **Owner** ⊃ **Store Admin** ⊃ **Staff**, enforced through Laravel Policies (not ad-hoc controller checks). Platform Admin is a separate, non-nested identity domain that manages the SaaS platform itself (organization approval/suspension), not merchant data.

**Merchant / Back Office**
- Organization lifecycle: every organization starts `pending` and requires Platform Admin approval (`approve`/`reject`/`suspend`/`reactivate`) before it can create stores.
- Store, Category, and Product management (each product currently ships with exactly one default variant — see "Known Limitations").
- Row-locked, ledger-backed Inventory adjustments (`restock`/`adjustment` merchant-driven; `checkout`/`release`/`refund` system-driven).
- Read-only Customer directory with per-customer order-count/net-sales aggregates.
- Order list/detail and a whitelisted merchant status-transition endpoint (`pending→cancelled`, `paid→processing→shipped→completed`).
- Full-refund initiation against a succeeded Stripe payment, reconciled via webhook.
- Store-scoped Analytics (sales summary, order-status breakdown, product performance, customer breakdown) — see "Analytics v1".

**Customer Storefront**
- Public, unauthenticated catalog browsing.
- Guest cart (browser `localStorage`) that merges into a Redis-backed authenticated cart on login.
- Checkout requiring authentication (no guest checkout) with Stripe PaymentIntents.
- Customer order history and detail.

## Architecture

```
Browser
   │
   ├──> Nginx :8080 ──> PHP-FPM (Laravel) :9000 ──┬──> MySQL :3306   (durable source of truth)
   │                                                └──> Redis :6379  (queue / cache / auth-cart — never source of truth)
   │
   └──> Vite dev server :5173 (React + TypeScript)
```

- **Laravel is the only component with database access** — the React frontend and any external service go through the Laravel API.
- **MySQL is the durable source of truth**; Redis serves four distinct roles (queue backend, analytics-cache-aside, rate limiting groundwork, authenticated-cart storage) and is never authoritative for any of them.
- A dedicated **queue worker** (`php artisan queue:work`) and **scheduler** (`php artisan schedule:work`, currently running the payment-expiry sweep) run as separate containers from the web-facing PHP-FPM process.

Tenant hierarchy:
```
Platform (platform_admins — outside the tenant hierarchy)
  → Organization
      → Stores
          → Store-level resources (Products, Orders, Inventory, Categories, Customers, ...)
```

Full detail: [`docs/architecture/system-architecture.md`](docs/architecture/system-architecture.md) and [`docs/database/database-design.md`](docs/database/database-design.md).

## Technology Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.5, Laravel 13, Laravel Sanctum |
| Payments | Stripe (`stripe/stripe-php`) — PaymentIntents, webhooks, refunds |
| Database | MySQL 8.4 |
| Cache / Queue | Redis 7 |
| Backend testing | PHPUnit (class-based; not Pest) |
| Frontend | React 19, TypeScript, Vite, React Query (`@tanstack/react-query`), React Router, Tailwind CSS v4 |
| Frontend payments | `@stripe/stripe-js`, `@stripe/react-stripe-js` |
| Frontend testing | Playwright (E2E) |
| Infrastructure | Docker / Docker Compose |

No AI SDK, OpenAI, or Anthropic package is present in `backend/composer.json` or `frontend/package.json` — the AI Assistant described in the product design has not been started.

## Authentication & Multi-Tenancy

- **Platform Admin** (`platform_admins` table, `auth:platform_admin` guard) — manages organization approval lifecycle and platform-wide visibility. Structurally outside the Organization→Store hierarchy; has no role/tier system in this MVP (guard membership alone is the authorization check).
- **Merchant** (`users` table, `auth:merchant` guard) — belongs to exactly one Organization via an `organization_user` row carrying a role (`owner`/`store_admin`/`staff`). Store-level access for Store Admin/Staff requires an explicit `store_user` row; Owner has implicit access to every store in their organization.
- **Customer** (`customers` table, `auth:customer` guard) — scoped to a single store; `customers.email` is unique per store, not globally.

Tenant context (organization/store) is resolved by middleware from the authenticated identity's own membership rows — every store-scoped controller resolves `{store}`/`{product}`/`{order}`/etc. explicitly scoped to that context rather than relying on implicit route-model binding, so a client-supplied ID belonging to another tenant reliably 404s rather than leaking existence.

## Cart Architecture

- **No `carts`/`cart_items` MySQL tables.** Guest carts live in browser `localStorage`; authenticated-customer carts live in Redis, keyed server-side by the resolved customer context, with a TTL. Cart state is intentionally not durable — the durable business record begins at the pending Order.
- Logging in merges a non-empty guest cart into the Redis-backed cart via `POST /api/cart/merge`.
- Checkout re-reads and revalidates price/availability from MySQL for every line item — cart contents (from either source) are treated as untrusted input.
- No guest checkout: browsing and cart-building are open to anyone, but the checkout/payment step requires an authenticated customer account.

## Checkout & Stripe

- A Stripe PaymentIntent is created **before** the local pending Order — this lets the entire local write (Order, OrderItems, OrderAddress, Payment, and an atomic, row-locked inventory claim) happen as one database transaction, since Stripe has already resolved by the time that transaction opens.
- **Inventory is claimed atomically at checkout** (not at payment success), under row-level locking — this is what prevents two concurrent checkouts from oversubscribing the same stock.
- A failed/cancelled payment attempt releases its inventory claim immediately; a retry re-claims inventory against the same order before a new PaymentIntent is created.
- Stripe webhooks (`payment_intent.*`, `refund.*`) are processed idempotently (a `stripe_webhook_events` dedup table plus terminal-status guards on `Payment`/`Order`) — the webhook handler transitions an already-existing Order/Payment; it never creates an Order.
- A scheduled expiry sweep (`payments:expire-stale`, run by the `scheduler` container) cancels pending Orders whose Payment has sat unconfirmed past a configurable window and releases their inventory claim.

**Requires a Stripe test-mode key pair** (`STRIPE_KEY`/`STRIPE_SECRET` in `backend/.env`) to function at all — without it, PaymentIntent creation fails and no Order can be created. Webhook delivery locally requires the [Stripe CLI](https://stripe.com/docs/stripe-cli) (`stripe listen --forward-to http://localhost:8080/api/webhooks/stripe`), whose printed signing secret goes into `STRIPE_WEBHOOK_SECRET`.

## Order Lifecycle

```
pending → paid → processing → shipped → completed
   │                  │           │          │
   └──> cancelled      └──────────┴──────────┴──> refunded
```

- `pending→cancelled` and the `paid→processing→shipped→completed` chain are the only merchant-triggerable transitions (a strict whitelist, not a general status field).
- `pending→paid` only ever happens via the Stripe webhook, never a merchant action.
- `{paid,processing,shipped,completed}→refunded` only ever happens via a successful Stripe refund reconciled through its webhook.

## Refunds

- **Full refunds only** (no partial refunds) — the refund amount is always derived server-side from the succeeded Payment, never client-supplied.
- Owner/Store Admin only (Staff cannot initiate a refund).
- The local `Refund` row is always inserted `pending`; the Stripe `refund.created`/`refund.updated` webhook is the sole authority for the `succeeded`/`failed` transition, at which point inventory is restored and the Order moves to `refunded`.

## Known Edge-Case Handling ("G3")

A documented, implemented set of detect-and-alert (and, for one case, admin-triggered compensation) behaviors for the residual race where a Stripe payment succeeds *after* its Order has already left `pending` through another path:

- **Merchant-cancellation case**: if a merchant cancels a pending Order and the customer's payment later succeeds anyway, the Order records an alarm `status_reason` and a critical log line; the Order is then eligible for a manual admin-triggered refund through the normal refund flow.
- **Expiry-sweep case**: if the scheduled expiry sweep has already cancelled a Payment and Stripe later reports it succeeded, the same detect-and-log behavior applies, plus a structurally separate, admin-triggered compensating refund path — without ever reopening the Order or "correcting" the Payment's terminal status.

Both are backed by automated tests exercising the real service/webhook code paths (including full checkout→cancel→late-webhook chains with a faked Stripe gateway).

## Analytics v1

Store-scoped, read-only, Owner/Store-Admin-only (`AnalyticsPolicy` explicitly excludes Staff):

- `GET /api/stores/{store}/analytics/sales` — gross/net sales, refunds, AOV, period-over-period growth.
- `GET /api/stores/{store}/analytics/orders` — order-status breakdown for the period.
- `GET /api/stores/{store}/analytics/products` — top products by revenue or quantity, with a daily trend.
- `GET /api/stores/{store}/analytics/customers` — new vs. returning customers, top customers by net sales.

All figures are derived from `orders`/`refunds` directly (cash-basis, anchored to `paid_at`/refund `created_at`) — there is no pre-aggregated analytics table. Date ranges are fixed presets (`today`, `last_7_days`, `last_30_days`, `this_month`, `last_month`); there is no arbitrary custom-range query parameter.

## AI Assistant

The product design (see `PRD.md`) specifies a tool-calling AI agent (`getSales`/`getOrders`/`getProducts`/`getCustomers`/`getRefunds`/`getInventory`/`comparePeriods` tools, with Insights/Investigation/Reports capabilities layered on top) that would query the same authorized, tenant-scoped services the rest of the app uses — never direct database access. **None of this is implemented.** There is no AI SDK dependency, no agent code, and no `/api/ai` or `/api/reports` route in the current codebase. This is intentionally sequenced after commerce completeness and analytics, per the project's stated development strategy.

## Frontend Routes (Main Pages)

| Area | Path | Notes |
|---|---|---|
| Platform Admin | `/admin/login`, `/admin/dashboard`, `/admin/organizations`, `/admin/organizations/:id` | Organization approve/reject/suspend/reactivate |
| Merchant auth | `/merchant/login`, `/merchant/register` | |
| Merchant back office | `/merchant/stores`, `/merchant/stores/:storeId`, `/merchant/stores/:storeId/{products,categories,orders,customers,analytics}` | Store-scoped management screens |
| Customer storefront | `/store/:storeId`, `/store/:storeId/products`, `/store/:storeId/products/:productId`, `/store/:storeId/cart`, `/store/:storeId/checkout`, `/store/:storeId/orders` | Public browsing + authenticated checkout/orders |
| Customer auth | `/store/:storeId/login`, `/store/:storeId/register` | |

## Backend API Overview

| Prefix | Guard | Purpose |
|---|---|---|
| `/api/auth/*` | `merchant` | Merchant registration/login |
| `/api/stores/*` | `merchant` | Store, Product, Category, Inventory, Order, Refund, Customer, Analytics management (all store-scoped) |
| `/api/platform/*` | `platform_admin` | Platform Admin auth + organization lifecycle |
| `/api/customers/auth/*`, `/api/customers/orders/*` | `customer` | Customer auth + order history |
| `/api/shop/stores/{store}/products*` | none (public) | Storefront catalog browsing |
| `/api/cart/*` | `customer` | Authenticated, Redis-backed cart |
| `/api/checkout` | `customer` | Checkout / PaymentIntent creation |
| `/api/orders/{order}/payment-retry` | `customer` | Payment retry on a pending order |
| `/api/webhooks/stripe` | none (Stripe-signature-verified) | Payment/refund webhook processing |

Full route list: `backend/routes/api.php`.

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

### Development URLs

| Service | URL |
|---|---|
| Laravel API (via Nginx) | http://localhost:8080 |
| React (Vite dev server) | http://localhost:5173 |
| MySQL (local tools only) | `127.0.0.1:3306` |

### Docker Commands

```bash
docker compose up -d           # start everything
docker compose up -d --build   # start, rebuilding images first
docker compose ps              # service status
docker compose logs -f         # follow all logs
docker compose down            # stop (keeps the mysql_data volume)
docker compose down -v         # stop AND delete volumes — deletes the database
```

### Laravel Commands

```bash
docker compose exec app php artisan about
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate --seed   # not `php artisan seed` — that command doesn't exist
docker compose exec app php artisan tinker
docker compose exec app ./vendor/bin/pint --test     # formatting check; there is no `php artisan lint`
```

### Frontend Commands

```bash
docker compose exec node npm install
docker compose exec node npm run dev
docker compose exec node npm run build   # tsc -b && vite build
docker compose exec node npm run lint    # oxlint
```

## Environment Variables

Two `.env` files, neither committed — only their `.env.example` counterparts are:

- **Root `.env`** — MySQL credentials (`MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`). `docker-compose.yml` injects these into the `app`/`queue`/`scheduler` containers as real `DB_*` environment variables, which take precedence over anything in `backend/.env` — this is deliberate, but see "Testing" below for a gotcha it caused.
- **`backend/.env`** — full Laravel configuration, including:
  - `STRIPE_KEY` / `STRIPE_SECRET` / `STRIPE_WEBHOOK_SECRET` — **required** for checkout, payment retry, expiry sweep, and refunds to function at all; blank by default in a fresh clone.
  - `STRIPE_CHECKOUT_EXPIRY_MINUTES` — how long a payment attempt may sit unconfirmed before the expiry sweep cancels it (default 30).
  - `REDIS_CART_DB` / `CART_TTL_SECONDS` — the authenticated cart's own Redis logical database and TTL.

## Database / Docker Services

`docker-compose.yml` defines 7 services: `app` (PHP-FPM), `queue` (`queue:work`), `scheduler` (`schedule:work` — runs the payment-expiry sweep), `nginx`, `mysql`, `redis`, `node` (Vite dev server). `app`/`queue`/`scheduler` share the same image/environment and bind-mount `./backend`.

## Testing

**Backend** (PHPUnit): 607 tests passing at the time of writing, covering authentication, authorization/RBAC, multi-tenant isolation, Store/Product/Category/Inventory/Order/Customer/Refund management, checkout/payment/webhook idempotency, inventory concurrency (row-locking, forced-rollback proofs), the late-payment edge cases, and Analytics.

```bash
docker compose exec app php artisan test
docker compose exec app ./vendor/bin/pint --test
```

> **Test database isolation**: `backend/phpunit.xml` forces `DB_CONNECTION=sqlite`/`DB_DATABASE=:memory:` (`force="true"`) specifically because `docker-compose.yml` injects real `DB_*` environment variables into the `app` container for the development MySQL database, and PHPUnit's `<env>` directives silently no-op against an already-set environment variable unless `force="true"` is specified. Without this, running tests inside the `app` container can silently run `RefreshDatabase` against the real development database. If you ever see `DB_CONNECTION`/`DB_DATABASE` in `phpunit.xml` without `force="true"`, treat it as a regression.

**Frontend** (Playwright E2E): critical flows — authentication, cart, checkout, order history — driven through the real UI, mocking only the backend API and Stripe.js at the network boundary.

```bash
docker compose exec node npm run test:e2e
```

## Project Status

Commerce-side functionality (multi-tenancy, RBAC, catalog, cart, checkout, payments, orders, refunds, analytics) is implemented and covered by an automated test suite. AI features are unstarted by design — the project's stated strategy is to complete and harden the commerce platform first. See [`docs/development/project-status.md`](docs/development/project-status.md) for the authoritative, phase-by-phase record.

## Known Limitations / Out of Scope

- **No product option/variant matrix** — every product ships with exactly one default variant; multi-variant products (size/color matrices) are not implemented.
- **No partial refunds** — refunds are full-amount only.
- **No CI/CD pipeline.**
- **No AI Assistant / Tools / Insights / Reports** — see "AI Assistant" above.
- **No password reset, discounts, or tax calculation.**
- Merchant-cancelling a still-`pending` order does not itself release its inventory claim (a known, documented gap — the claim is released by a subsequent expiry sweep or webhook path instead).
- Store Admin/Staff account creation has no invite API/UI — those roles' `organization_user`/`store_user` rows must currently be created directly (e.g. via `artisan tinker`), matching the project's own membership schema exactly.
- A Stripe test-mode key pair is required for any checkout/payment/refund flow to function; without one, only catalog/inventory/customer-management/analytics-with-no-data can be exercised.

## Future Work

Per the product design (`PRD.md`), not yet started or scoped: the AI Assistant/Tools/Insights/Reports layer, Job/queue-based post-payment processing (analytics/notifications currently run synchronously where they run at all), partial refunds, a Customer CRUD/CRM layer, CI/CD, and production deployment tooling.

## Documentation

- [`PRD.md`](PRD.md) — product requirements and MVP scope
- [`docs/architecture/system-architecture.md`](docs/architecture/system-architecture.md) — approved system architecture
- [`docs/architecture/architecture-review.md`](docs/architecture/architecture-review.md) — architecture review record
- [`docs/database/database-design.md`](docs/database/database-design.md) — approved database design
- [`docs/development/development-environment.md`](docs/development/development-environment.md) — full local environment reference
- [`docs/development/project-status.md`](docs/development/project-status.md) — current development status (source of truth)
- [`CLAUDE.md`](CLAUDE.md) — operating guidance for AI-assisted development on this repository
