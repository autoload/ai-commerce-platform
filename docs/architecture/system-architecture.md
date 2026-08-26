# System Architecture

Status: approved design (Phase 0). No application code exists yet. This document describes the intended architecture derived from `PRD.md` and refined through the architecture review process — see `architecture-review.md` for the review findings that shaped these decisions, and `../database/database-design.md` for the data model.

## 1. Component Overview

| Component | Responsibility |
|---|---|
| **React frontend** | Two experiences on one codebase: customer storefront (browse/cart/checkout/order history) and admin dashboard (products/orders/customers/inventory/analytics/AI/reports). Owns presentation and client-side UX only — no business logic, no direct DB/Stripe/LLM access. |
| **Laravel API** | System of record for business logic: auth, RBAC, tenant enforcement, order/payment orchestration, inventory mutation, analytics computation, AI tool implementations. No other component talks to MySQL, Stripe, or the LLM except through Laravel. |
| **MySQL** | Durable transactional store: organizations, stores, users, products, orders, payments, inventory, customers. Source of truth for everything except ephemeral cache/queue state. |
| **Redis** | Three roles sharing one engine: analytics cache (cache-aside), queue backend, rate limiting. Not a source of truth for anything. |
| **Queue workers** | Execute everything that shouldn't block an HTTP response or a webhook ack: post-payment inventory/analytics/notifications, AI report/insight generation, cleanup sweeps. |
| **Stripe** | Payment processing (PaymentIntents, refunds) and the webhook event source. Treated as an untrusted external actor whose events must be signature-verified and de-duplicated. |
| **Laravel AI SDK / LLM** | Orchestrates tool selection and natural-language synthesis. Never touches the database directly — calls Laravel-implemented tools that enforce the same authorization as the REST API. |

Core discipline: **Laravel is the only component with database access**, and **tenant/authorization enforcement happens once, in one place, reused by every consumer** — REST controllers and AI tools alike.

## 2. Multi-Tenancy

Hierarchy: `Organization → Store → {store-scoped resources}`.

- **Tenant context** is resolved once per request in middleware, immediately after authentication, and bound into the container as a single `TenantContext` value object (org id, store id where applicable, acting user, role). Controllers, policies, Eloquent scopes, and AI tools all read from this one bound object rather than re-deriving tenant identity ad hoc.
- **Organization isolation**: every tenant-scoped table carries `organization_id` directly (denormalized, not only reachable via a join through `store`) — see `../database/database-design.md` for the exact table-by-table application of this rule.
- **Store-level authorization**: Organization Owner implicitly has access to all stores in their org; Store Admin/Staff are explicitly scoped via a `store_user` pivot.
- **Eloquent scoping**: a global scope, applied via a shared base model/trait, automatically appends the bound `TenantContext`'s org (and store) constraints to every query against tenant-scoped models — replacing hand-written `where('organization_id', ...)` calls with something structural.
- **Policies**: one Policy per tenant-scoped model; controllers never contain raw ownership checks, only `$this->authorize()` calls.
- **Queued jobs**: a queue worker has no HTTP request and no `auth()->user()`. Every job receives tenant identifiers as explicit constructor arguments and re-establishes a `TenantContext` at the top of `handle()`.
- **AI tools**: tenant context is bound server-side from the authenticated request *before* the agent runs and injected directly into each tool call by the backend — never supplied or influenced by the LLM.

## 3. Authentication & RBAC

- **Laravel Sanctum** (SPA cookie-based auth), not JWT.
- **Two separate identities**: `users` (org-scoped, RBAC roles, admin/staff dashboard) and `customers` (store-scoped shoppers, storefront-only). They do not share a table or guard.
- **Roles**, strictly nested: **Organization Owner** ⊃ **Store Admin** ⊃ **Staff**, stored via an `organization_user` pivot (role per org-membership) rather than a column on `users`.
- **Authn vs. authz separation**: Sanctum answers "who is this"; Policies/Gates evaluated against the bound `TenantContext` + role answer "what can they do." This logic lives in one place and is reused identically by HTTP requests and AI tool calls.
- Customer accounts are required for MVP (no guest checkout) — the PRD's stated order-history capability implies an account.

## 4. Order & Payment Lifecycle

```
Cart → Pending Order → Stripe PaymentIntent → Customer completes payment
     → Stripe Webhook → Paid Order → Queue:
          - Update Inventory
          - Update Analytics
          - Send Notifications
```

- The order is created **before** payment, in a pending state — never fabricated for the first time inside the webhook handler.
- Order + OrderItem creation (with snapshotted product name/SKU/price) happens in one DB transaction, committed *before* the Stripe API call — a DB transaction is never held open across a network call.
- The webhook handler verifies the Stripe signature, checks event idempotency, and transitions `pending → paid` inside a short transaction with a row lock; heavier side effects (inventory, analytics, notifications) are queued, not inline.
- **Idempotency operates at three layers**: webhook event-level (unique Stripe event id), order-state-level (the `pending → paid` transition can only happen once, guarded by transaction + lock), and job-level (each queued job checks whether its effect already exists before applying, since Laravel queue delivery is at-least-once).
- **The webhook event insert and the order status transition must occur within the same database transaction** — as two separate steps, a crash between them would leave an event marked "processed" whose order was never actually marked paid, with no future retry path (a customer paid, but the order is permanently stuck pending).
- Failed payments never touch inventory (nothing was reserved). Stale pending orders are expired by a scheduled sweep.
- Refunds are admin-initiated, call Stripe, and are confirmed via webhook using the same idempotency pattern as payments. MVP scope is full-order refunds only.
- **`orders.status` (fulfillment lifecycle) and `payments.status` (payment-attempt lifecycle) are two separate state machines**, not one — an order has exactly one fulfillment status at a time, but can have many payment attempts (one row per PaymentIntent, including retries after failure). A failed or retried payment attempt never changes `orders.status`; only a payment reaching `succeeded` triggers `pending → paid`, and only a refund reaching `succeeded` triggers `→ refunded`. Full authoritative detail — including webhook, refund, failed-payment, and retry behavior — lives in `../database/database-design.md` §"Order & Payment State Models."

## 5. Inventory & Concurrency

One `inventory` row per product (current state) plus an append-only `inventory_transactions` ledger. All mutation goes through a single service method that: opens a transaction → `SELECT ... FOR UPDATE`s the inventory row → checks sufficient stock → decrements and inserts the corresponding ledger row → commits.

This gives, by construction: no overselling (the row lock serializes concurrent decrements), no negative inventory (application guard + `CHECK` constraint), and correct behavior under simultaneous checkouts (the losing transaction blocks briefly, then re-evaluates fresh data).

**Deliberately not built for MVP**: an inventory reservation/soft-hold system. Decrementing atomically at the `paid` transition — not at add-to-cart — is simpler, still fully correct against overselling, and avoids the added complexity (TTL-based holds, expiry sweepers) that a reservation model requires.

## 6. Redis & Queue Architecture

Three roles: analytics cache (cache-aside, tenant-namespaced keys), Laravel queue backend, and rate limiting.

**Synchronous**: auth, catalog reads, cart mutations, order creation, AI Q&A tool execution and the LLM round-trip.

**Queued**: all webhook post-processing (inventory, analytics, notifications), AI Insight generation, AI Report generation, pending-order expiry sweeps.

**Retries/failures**: bounded retry counts with backoff; exhausted retries land in `failed_jobs` rather than vanishing. Every job must be idempotent by design (at-least-once delivery), matching the `inventory_transactions` uniqueness guard described in `../database/database-design.md`.

## 7. Analytics Architecture

Query transactional tables directly for MVP, with Redis caching results (cache-aside, short TTL). No pre-aggregation/materialized-rollup pipeline — not justified at MVP data volume, and it would introduce staleness/consistency questions (when do you re-aggregate after a late refund?) that aren't worth solving yet.

One `AnalyticsService` (or equivalent) is called by both the REST analytics endpoints and the AI's `getSales`/`comparePeriods`/etc. tools — a single definition of "revenue" and "average order value," so the AI's numbers can never drift from the dashboard's numbers.

## 8. AI Agent Architecture

```
User → AI Agent → Tools → Authorized application services → Database
     ← Tools ← Agent ← Response
```

Tools: `getSales`, `getOrders`, `getProducts`, `getCustomers`, `getRefunds`, `getInventory`, `comparePeriods`.

- Each tool is a thin wrapper around the same application service the REST endpoints use — never a separate AI-only data-access path.
- Tool arguments from the LLM are validated exactly as an API request would be — never trusted as pre-sanitized.
- **Tenant context is injected server-side into every tool call; it is never an argument the LLM supplies or can override.** The LLM chooses which tool and what business parameters (date range, filters) — never whose data.
- Because authorization is enforced at the tool layer regardless of what the LLM "requests," prompt injection (via a malicious question, or via injected content inside returned data) can at worst produce a refused/erroring tool call — never actual cross-tenant access.
- Tool responses are structured JSON, minimal fields only, role-gated (the tool list itself is filtered by the caller's role, not just the data inside).
- **Insights, Investigation, Q&A, and Reports are different orchestration patterns over the identical tool set**, not separate data-access implementations — Q&A is a single agent turn, Investigation chains several tool calls, Insights and Reports are scheduled jobs running a fixed/broader tool-call set.

## 9. API Architecture

Resource groups: `/api/auth/*`, `/api/organizations`, `/api/stores`, `/api/products`, `/api/categories`, `/api/cart`, `/api/checkout`, `/api/webhooks/stripe`, `/api/orders`, `/api/customers`, `/api/me/orders`, `/api/inventory`, `/api/analytics/*`, `/api/ai/*` (ask, investigate, reports).

**Endpoints that must not exist**: no public "call this AI tool directly" endpoint (tools are internal to agent orchestration only); no endpoint accepting a client-supplied `organization_id`/`store_id` for a privileged operation (tenant scope always comes from the authenticated session); no inventory "set quantity" shortcut outside the locked/audited service. The Stripe webhook route is the one deliberate exception to normal auth — excluded from Sanctum/CSRF, verified by Stripe's signature instead.

**Order totals are always recalculated server-side** from product price + tax/discount rules — never trusted from the client.

## 10. Frontend Architecture

- **React Query** owns all server state (products, orders, cart, analytics, AI responses).
- **Local component state** for ephemeral UI only.
- **No Redux/Zustand** introduced by default — a global client store is added only if a genuine cross-cutting, non-server state need shows up, not preemptively.
- **Auth state** via a thin Context wrapping a React-Query-backed `useUser()` call.
- Route protection via a role-aware `<ProtectedRoute>` wrapper; admin navigation is role-aware (Owner sees all stores in their org; Store Admin/Staff see only assigned store(s), with a store switcher if applicable).
