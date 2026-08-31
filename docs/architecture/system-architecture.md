# System Architecture

Status: approved design (Phase 0). No application code exists yet. This document describes the intended architecture derived from `PRD.md` and refined through the architecture review process — see `architecture-review.md` for the review findings that shaped these decisions, and `../database/database-design.md` for the data model.

## 1. Component Overview

| Component | Responsibility |
|---|---|
| **React frontend** | Two experiences on one codebase: customer storefront (browse/cart/checkout/order history) and admin dashboard (products/orders/customers/inventory/analytics/AI/reports). Owns presentation and client-side UX only — no business logic, no direct DB/Stripe/LLM access. |
| **Laravel API** | System of record for business logic: auth, RBAC, tenant enforcement, order/payment orchestration, inventory mutation, analytics computation, AI tool implementations. No other component talks to MySQL, Stripe, or the LLM except through Laravel. |
| **MySQL** | Durable transactional store: organizations, stores, users, products, orders, payments, inventory, customers. Source of truth for everything except ephemeral cache/queue state. |
| **Redis** | Four roles sharing one engine: analytics cache (cache-aside), queue backend, rate limiting, and authenticated-customer cart storage (ephemeral, TTL'd — see §7 Cart Architecture). Not a source of truth for anything. |
| **Queue workers** | Execute everything that shouldn't block an HTTP response or a webhook ack: post-payment inventory/analytics/notifications, AI report/insight generation, cleanup sweeps. |
| **Stripe** | Payment processing (PaymentIntents, refunds) and the webhook event source. Treated as an untrusted external actor whose events must be signature-verified and de-duplicated. |
| **Laravel AI SDK / LLM** | Orchestrates tool selection and natural-language synthesis. Never touches the database directly — calls Laravel-implemented tools that enforce the same authorization as the REST API. |

Core discipline: **Laravel is the only component with database access**, and **tenant/authorization enforcement happens once, in one place, reused by every consumer** — REST controllers and AI tools alike.

The Laravel API also serves a structurally separate **Platform Admin** surface (platform operator: organization approval/rejection/suspension, platform-wide visibility) — architecturally distinct from the merchant/customer-facing API, not tenant-scoped, and not built yet (design only — see §3).

## 2. Multi-Tenancy

Hierarchy: `Platform → Organization → Store → {store-scoped resources}`. Platform Admin sits above the tenant hierarchy, not inside it — it manages *which organizations exist and may operate*, not any organization's internal data.

Every organization has an approval/suspension lifecycle (`organizations.status`: `pending → active`, with `rejected` and `suspended` branches, each carrying its own actor+timestamp audit columns — see `../database/database-design.md`). A new organization starts `pending` and requires Platform Admin approval before it becomes `active`. This is a Platform Admin operation, independent of the Organization/Store/RBAC hierarchy described below.

- **Tenant context** is resolved once per request in middleware, immediately after authentication, and bound into the container as a single `TenantContext` value object (org id, store id where applicable, acting user, role). Controllers, policies, Eloquent scopes, and AI tools all read from this one bound object rather than re-deriving tenant identity ad hoc.
- **Organization isolation**: every tenant-scoped table carries `organization_id` directly (denormalized, not only reachable via a join through `store`) — see `../database/database-design.md` for the exact table-by-table application of this rule.
- **Store-level authorization**: Organization Owner implicitly has access to all stores in their org; Store Admin/Staff are explicitly scoped via a `store_user` pivot.
- **Eloquent scoping**: a global scope, applied via a shared base model/trait, automatically appends the bound `TenantContext`'s org (and store) constraints to every query against tenant-scoped models — replacing hand-written `where('organization_id', ...)` calls with something structural.
- **Policies**: one Policy per tenant-scoped model; controllers never contain raw ownership checks, only `$this->authorize()` calls.
- **Queued jobs**: a queue worker has no HTTP request and no `auth()->user()`. Every job receives tenant identifiers as explicit constructor arguments and re-establishes a `TenantContext` at the top of `handle()`.
- **AI tools**: tenant context is bound server-side from the authenticated request *before* the agent runs and injected directly into each tool call by the backend — never supplied or influenced by the LLM.

## 3. Authentication & RBAC

- **Laravel Sanctum** (SPA cookie-based auth), not JWT.
- **Three separate identities, three separate tables, no shared guard**: `platform_admins` (platform operator — outside the tenant hierarchy entirely), `users` (org-scoped, RBAC roles, merchant admin/staff dashboard), and `customers` (store-scoped shoppers, storefront-only). None of the three share a table, and no identity is ever authenticated through another's guard.
- **Platform Admin is not a fourth RBAC rung.** It is not nested inside, and does not extend, the Organization Owner ⊃ Store Admin ⊃ Staff capability ladder below — it is a structurally separate domain with its own responsibilities (review/approve/reject/suspend organizations; platform-wide read visibility into merchants/stores/customers; platform operational status). A Platform Admin is never also a `users`/`organization_user` row, and a merchant `users` row is never granted platform-level capability.
- **Roles**, strictly nested (merchant side only): **Organization Owner** ⊃ **Store Admin** ⊃ **Staff**, stored via an `organization_user` pivot (role per org-membership) rather than a column on `users`. No `platform_admin` role is added to `users` or `organization_user`.
- **Authn vs. authz separation**: Sanctum answers "who is this"; Policies/Gates evaluated against the bound `TenantContext` + role answer "what can they do." This logic lives in one place and is reused identically by HTTP requests and AI tool calls. Platform Admin authorization is a separate policy space — it is never evaluated against a `TenantContext`, since it isn't scoped to any organization/store.
- Customer accounts are required for MVP (no guest checkout) — the PRD's stated order-history capability implies an account. **Clarification**: guest browsing and guest cart-building are permitted (see §7 Cart Architecture) — "no guest checkout" means checkout itself always requires an authenticated `customers` row; it does not mean an account is needed merely to browse or add items to a cart.
- **Shared token infrastructure, separate guards**: all three identities use Sanctum's existing polymorphic `personal_access_tokens` table (`tokenable_type`/`tokenable_id` already supports multiple model types) — no separate token schema per identity. Each identity gets its own Laravel auth guard/provider and its own login endpoint; a token issued to a `platform_admins` row is never valid against a merchant/customer-scoped route, and vice versa.

## 4. Order & Payment Lifecycle

**Revised (design finalized, NOT YET IMPLEMENTED)** — see `../database/database-design.md` §"Order & Payment State Models" §9–§14 (Database Design 2.6) for the full, authoritative detail. Summary:

```
Cart → Stripe PaymentIntent → DB transaction: Pending Order + Order Items
     + Order Address + Payment + inventory CLAIM (atomic, locked)
     → Customer completes payment → Stripe Webhook → Paid Order (no inventory
     change — already claimed) → Queue: Update Analytics, Send Notifications
```

**"Cart" here is ephemeral, not a MySQL table** (Database Design 2.2 — see §7 Cart Architecture): guest carts live in browser `localStorage`, authenticated-customer carts live in Redis. Neither is durable, and neither is trusted at checkout. The durable record begins at **Pending Order** — checkout revalidates every cart line against MySQL (`product_variant_id`, quantity, price, availability, inventory) before an order is ever created.

- **The Stripe PaymentIntent is created before any local database write** — not the reverse. This isn't a schema technicality: it's what lets the entire local write (`orders` + `order_items` + `order_addresses` + `payments` + the inventory claim + its ledger row) be **one single atomic transaction**, since Stripe has already resolved by the time that transaction opens. The order-first alternative was evaluated and rejected — it structurally requires a second, separate transaction once Stripe responds, producing a real, visible intermediate state (a `pending` order with claimed inventory and no payment) that PaymentIntent-first never produces.
- **Inventory is claimed atomically at checkout, inside this same transaction** — not at the `pending → paid` transition. This is a change from the original design (see below, §5) made specifically to close a real gap: without an atomic claim at order-creation time, two competing orders could both believe they held the same unit, discoverable only when a payment later succeeded against depleted stock. Claiming at checkout, under the same lock, makes that conflict discoverable and resolvable immediately (checkout rejected with 422, or a failing retry cancelling the order) instead of surfacing later at payment time.
- The webhook handler verifies the Stripe signature, checks event idempotency, and transitions `pending → paid` inside a short transaction with a row lock — **it does not touch inventory**, since it was already claimed at checkout. Heavier side effects (analytics, notifications) are queued, not inline.
- **Idempotency operates at multiple layers**: a durable, DB-backed key on `orders`/`payments` for checkout/retry submissions (Redis is a performance optimization only, never the correctness mechanism), Stripe's own idempotency key on the PaymentIntent-creation call (supplementary), webhook event-level (unique Stripe event id), the inventory ledger's `dedup_key` (one claim/release pair per payment attempt), and job-level (each queued job checks whether its effect already exists before applying, since Laravel queue delivery is at-least-once).
- **The webhook event insert and the order status transition must occur within the same database transaction** — as two separate steps, a crash between them would leave an event marked "processed" whose order was never actually marked paid, with no future retry path (a customer paid, but the order is permanently stuck pending).
- **A failed or cancelled payment attempt releases its inventory claim immediately** (not deferred to expiry) — this is deliberate: holding a claim through a failure would let one customer with a failing card block every other customer from scarce/contended stock for the full expiry window. A retry must atomically re-claim the same inventory before a new PaymentIntent is created; if the re-claim fails, the order is cancelled (`item_no_longer_available`), and a genuinely new checkout is required only at that point — a retry never creates a new Order on its own.
- Stale pending orders (no attempt ever submitted) are expired by a scheduled sweep, anchored to the current payment attempt's age, not the order's — see database-design.md §12 for why a retry legitimately extends this window.
- Refunds are admin-initiated, call Stripe, and are confirmed via webhook using the same idempotency pattern as payments. MVP scope is full-order refunds only. Refund initiation itself is not yet implemented.
- **`orders.status` (fulfillment lifecycle) and `payments.status` (payment-attempt lifecycle) are two separate state machines**, not one — an order has exactly one fulfillment status at a time, but can have many payment attempts (one row per PaymentIntent, including retries after failure). A failed or retried payment attempt never changes `orders.status`; only a payment reaching `succeeded` triggers `pending → paid`, and only a refund reaching `succeeded` triggers `→ refunded`. At most one non-terminal (`requires_payment`/`processing`) Payment may exist per order at any time, enforced under the order row lock. Full authoritative detail — including webhook, refund, failed-payment, retry, and inventory-claim behavior — lives in `../database/database-design.md` §"Order & Payment State Models."

## 5. Inventory & Concurrency

One `inventory` row per variant (current state) plus an append-only `inventory_transactions` ledger. All mutation goes through a single service method that: opens a transaction → `SELECT ... FOR UPDATE`s the inventory row → checks sufficient stock → decrements/increments and inserts the corresponding ledger row → commits.

This gives, by construction: no overselling (the row lock serializes concurrent claims), no negative inventory (application guard + `CHECK` constraint), and correct behavior under simultaneous checkouts (the losing transaction blocks briefly, then re-evaluates fresh data and either fails the checkout or fails a retry's re-claim).

**Revised (design finalized, NOT YET IMPLEMENTED) — inventory is claimed atomically at checkout, not at the `paid` transition.** The original MVP decision recorded here was to decrement "at the `paid` transition, not at add-to-cart," explicitly to avoid the complexity of a reservation/soft-hold system. That decision is superseded: decrementing at `paid` left a real gap (see `../database/database-design.md` §"Concurrency Review" item 10, closed in Database Design 2.6) where two competing pending orders could reference the same stock with the conflict only surfacing if a payment later succeeded against depleted inventory. The adopted resolution still does **not** build a reservation/soft-hold system (no `reserved_quantity` column, no TTL-based holds) — it instead claims inventory using the exact same locked service, at the exact same moment the pending order itself is created (checkout time), which closes the gap without the added schema/infrastructure a reservation model would need. A failed payment releases the claim immediately; a retry must re-claim before trying again. See database-design.md §9 for the full comparison against a reservation model and against payment-first-with-manual-capture, both of which were considered and not adopted.

## 6. Redis & Queue Architecture

Four roles: analytics cache (cache-aside, tenant-namespaced keys), Laravel queue backend, rate limiting, and authenticated-customer cart storage (ephemeral, TTL'd — see §7). Not a source of truth for anything, including the cart role — cart loss is an accepted, low-stakes failure mode (see §7).

**Synchronous**: auth, catalog reads, cart mutations, order creation, AI Q&A tool execution and the LLM round-trip.

**Queued**: all webhook post-processing (inventory, analytics, notifications), AI Insight generation, AI Report generation, pending-order expiry sweeps.

**Retries/failures**: bounded retry counts with backoff; exhausted retries land in `failed_jobs` rather than vanishing. Every job must be idempotent by design (at-least-once delivery), matching the `inventory_transactions` uniqueness guard described in `../database/database-design.md`.

**Eviction/isolation policy** (cache role vs. cart role): mixing an evictable analytics cache with cart state in the same Redis instance is a real operational consideration — a `maxmemory-policy` tuned for free cache eviction (e.g. `allkeys-lru`) could evict cart keys under memory pressure. **Left as a future operational decision** (accept cart loss as low-stakes, consistent with §7's cache-aside/eviction posture; or isolate cart keys via a separate logical DB / protected keyspace / `volatile-ttl` policy) — not resolved now.

## 7. Cart Architecture

**Database Design 2.2**: there is no `carts` or `cart_items` MySQL table. Cart state for MVP is ephemeral and lives entirely outside MySQL:

- **Guest customers**: cart lives in the browser (`localStorage`). Never sent to or stored by the backend until checkout. Fully client-controlled — see "Untrusted input" below.
- **Authenticated customers**: cart lives in Redis. The key is **derived server-side** from the authenticated request's resolved tenant/customer context (organization, store, customer id) — **never** from client-supplied `organization_id`/`store_id`/`customer_id`, matching the same discipline already required for `TenantContext` elsewhere (§2, §9). The key carries a **TTL** (inactive carts expire; exact duration is an implementation detail, not fixed here).
- **Concurrency primitive (intended, not yet built)**: a Redis **Hash** per cart, field = `product_variant_id`, value = quantity, mutated via atomic `HINCRBY`. This gives the same no-lost-update guarantee the old MySQL `unique(cart_id, product_variant_id)` + upsert pattern provided, without the read-modify-write race a single JSON-blob cart would introduce. Not implemented yet — documented here as the intended direction for whoever builds the cart service.
- **Merge-on-login**: when a guest with a `localStorage` cart authenticates, that cart should merge into their Redis cart. The exact merge strategy (sum quantities, keep-max, reject stale/discontinued lines, etc.) is **intentionally left as a future implementation decision** — not resolved now.
- **Untrusted input**: whether from `localStorage` or Redis, Laravel treats cart contents as untrusted input at checkout. Only `product_variant_id` and quantity are read from it — price, name, availability, and totals are never accepted from the client (see §9's existing "order totals always server-side" rule, which this reinforces rather than changes) and are always looked up/recalculated fresh from MySQL.
- **Deleted/archived variants**: because there is no `cart_items` row and thus no `CASCADE` FK, a variant that's deleted or archived after being added to a cart is **not** automatically pruned. Checkout (and any cart-read path) must defensively detect a stale `product_variant_id` and handle it explicitly (reject the line, flag it to the customer, etc.) rather than assuming referential integrity the database no longer enforces for cart state.
- **No guest checkout, unchanged**: a guest may build a cart, but checkout still requires an authenticated `customers` row — the durable `orders.customer_id` FK requires one, and the authenticated cart itself is keyed by customer. See §3's clarification.
- **Do not reintroduce a MySQL `carts`/`cart_items` table** without explicitly reconsidering and re-approving this architecture.

Full schema-level rationale: `../database/database-design.md` §"Cart — intentionally NOT a MySQL table."

## 8. Analytics Architecture

Query transactional tables directly for MVP, with Redis caching results (cache-aside, short TTL). No pre-aggregation/materialized-rollup pipeline — not justified at MVP data volume, and it would introduce staleness/consistency questions (when do you re-aggregate after a late refund?) that aren't worth solving yet.

One `AnalyticsService` (or equivalent) is called by both the REST analytics endpoints and the AI's `getSales`/`comparePeriods`/etc. tools — a single definition of "revenue" and "average order value," so the AI's numbers can never drift from the dashboard's numbers.

## 9. AI Agent Architecture

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

## 10. API Architecture

Resource groups: `/api/auth/*`, `/api/organizations`, `/api/stores`, `/api/products`, `/api/categories`, `/api/cart`, `/api/checkout`, `/api/webhooks/stripe`, `/api/orders`, `/api/customers`, `/api/me/orders`, `/api/inventory`, `/api/analytics/*`, `/api/ai/*` (ask, investigate, reports). `/api/cart` now fronts the Redis-backed authenticated cart (§7), not a MySQL resource.

A separate `/api/platform/*` namespace (Platform Admin: organization review/approve/reject/suspend, platform-wide read views) is implied by the Platform Admin identity domain in §3 — not implemented in this design pass, noted here so a future session doesn't accidentally fold it into the merchant-facing `/api/organizations` routes, which remain tenant-scoped and unrelated to platform-level review.

**Endpoints that must not exist**: no public "call this AI tool directly" endpoint (tools are internal to agent orchestration only); no endpoint accepting a client-supplied `organization_id`/`store_id` for a privileged operation (tenant scope always comes from the authenticated session); no inventory "set quantity" shortcut outside the locked/audited service. The Stripe webhook route is the one deliberate exception to normal auth — excluded from Sanctum/CSRF, verified by Stripe's signature instead.

**Order totals are always recalculated server-side** from product price + tax/discount rules — never trusted from the client. **Cart contents are equally untrusted** (§7): whether sourced from `localStorage` or the Redis-backed `/api/cart`, checkout reads only `product_variant_id`/quantity from the cart and never accepts price, name, or availability from it.

## 11. Frontend Architecture

- **React Query** owns all server state (products, orders, cart, analytics, AI responses) — this includes the **authenticated** customer's cart, fetched/mutated through `/api/cart` like any other server resource.
- **Guest cart is an explicit, narrow exception**: it lives in browser `localStorage`, not React Query and not a global client store — it isn't server state (nothing is persisted server-side for a guest) and isn't cross-cutting app state either, just per-browser persisted data until merge-on-login (§7).
- **Local component state** for ephemeral UI only.
- **No Redux/Zustand** introduced by default — a global client store is added only if a genuine cross-cutting, non-server state need shows up, not preemptively. The guest cart's use of `localStorage` does not change this — it's a narrow, self-contained exception, not a precedent for a general client store.
- **Auth state** via a thin Context wrapping a React-Query-backed `useUser()` call.
- Route protection via a role-aware `<ProtectedRoute>` wrapper; admin navigation is role-aware (Owner sees all stores in their org; Store Admin/Staff see only assigned store(s), with a store switcher if applicable).
