# Architecture Review

This is the authoritative architecture review for the AI Commerce Platform, covering both the system-level design review and the subsequent database schema review. It documents what was found, what must change, what's recommended, and what was deliberately accepted as-is for MVP. See `system-architecture.md` for the resulting design and `../database/database-design.md` for the resulting schema.

**Historical record notice**: this document is preserved as-written and is not retroactively edited when the schema evolves. Several specific schema details referenced below (e.g. `products.sku`, the inventory ledger's `(order_id, reason)` idempotency key) were accurate at the time of this review but have since been superseded by **Database Design 2.0** (approved) — see `../database/database-design.md` for the current, authoritative schema. The findings, recommendations, and reasoning below remain valid as a record of what was decided and why; only specific column/table names it cites may be out of date.

## Review Scope

Two review passes were performed against `PRD.md` and `CLAUDE.md`:
1. A full system architecture review (multi-tenancy, auth/RBAC, order/payment/inventory, Redis/queues, analytics, AI agent, API surface, frontend, security, scalability).
2. A focused database schema review of the Phase 0 design, checking for missing columns, broken relationships, tenant-isolation consistency, constraint correctness, and Laravel+MySQL fit.

---

## Critical Issues (must fix before implementation)

1. **Webhook processing atomicity.** The Stripe webhook idempotency insert (`stripe_webhook_events`) and the order's `pending → paid` transition must occur within the same database transaction. As two separate steps, a crash between them leaves an event marked "processed" whose order was never actually marked paid — since the event is already recorded as processed, a Stripe retry would be silently ignored, permanently stranding a paid order in `pending`.
2. **Inconsistent application of the tenant-scoping rule.** `categories` was missing `organization_id` while its sibling table `products` had both `organization_id` and `store_id`; `payments` and `refunds` were missing both `organization_id` and `store_id` entirely, unlike `orders`. Isolation still functioned via the join chain in all cases, but the inconsistency undermines the design's core claim that tenant columns are applied structurally rather than case-by-case. **Resolved in `../database/database-design.md`**: `organization_id` added to `categories`; `organization_id` and `store_id` added to `payments` and `refunds`.
3. **Soft-delete + unique-constraint interaction.** `users.email`, `customers.(store_id, email)`, `stores.(organization_id, slug)`, and `products.(store_id, sku)` are all on soft-deletable tables. A soft-deleted row's unique value stays reserved in the index forever — and naively adding `deleted_at` into the unique index does **not** fix this, because MySQL treats every `NULL` as distinct in a unique index, so `unique(store_id, sku, deleted_at)` would fail to prevent duplicate *active* rows (both have `deleted_at = NULL`, and MySQL doesn't consider `NULL = NULL`). **Left as an explicit open decision** — see `../database/database-design.md` §"Open Decisions."
4. **`products.category_id` delete-behavior contradiction.** An earlier draft stated both `RESTRICT` and `SET NULL` in the same breath. Resolved: the authoritative behavior is `ON DELETE SET NULL` — categories can always be deleted; products just lose the reference.

## Recommended Improvements (should fix)

- Add `refunds.initiated_by_user_id` (nullable FK → users) for admin accountability, matching the pattern already used on `inventory_transactions.created_by_user_id` and `reports.generated_by_user_id`. **Applied in `../database/database-design.md`.**
- Drop the unused `cancellation` value from `inventory_transactions.reason` — the current state model produces no ledger row for a pre-payment cancellation (nothing was ever decremented), so this enum value is never actually written; post-payment reversals are already covered by `refund`. **Applied in `../database/database-design.md`.**
- Pin `order_number` generation to a collision-resistant scheme (ULID/UUID, optionally store-prefixed for readability) — explicitly avoid a per-store sequential counter, which would need its own concurrency-locking design analogous to inventory. **Adopted in `../database/database-design.md`.**
- Standardize all status/role/reason columns as `varchar` + a PHP 8.1+ native enum with an Eloquent cast, not MySQL's native `ENUM` column type — adding a new status value later (e.g. a hypothetical `partially_refunded`) becomes a data-compatible application change instead of a schema migration. **Adopted in `../database/database-design.md`.**
- Decide and document whether soft-deleting a `store` cascades (via a model observer) to its `products`/`categories`/`customers`, or whether store deactivation is blocked while active resources exist. MySQL's `ON DELETE CASCADE`/`RESTRICT` clauses never fire under Laravel's `SoftDeletes` trait (which performs an `UPDATE`, not a `DELETE`), so this must be an explicit application-layer decision if cascading soft-deletes are wanted. **Left as an open decision** — see below.
- Pin MySQL to ≥8.0.16 (or simply target current 8.0/8.4) — `CHECK` constraints are silently unenforced on earlier 8.0.x releases.
- Document `refunds.order_id` as a value always derived from `refunds.payment_id`'s order (never independently supplied) to eliminate drift risk between the two, without needing a schema-level enforcement mechanism.

## MVP Decisions Intentionally Accepted

These were considered and deliberately kept as-is — not oversights:

- **No guest checkout.** Customer accounts are required. The PRD's stated "view order history" capability implies an account, and the PRD never describes a guest flow.
- **`billing_*` fields and the standalone `addresses` table removed for MVP.** The PRD's checkout flow never describes a separate billing-address step (Stripe's own payment element typically collects/validates this itself), and the PRD's Customer Management section never lists a saved-address-book feature — only "Addresses" as a data point and "Shipping information" as a checkout field, both satisfied by the immutable shipping snapshot already on `orders`.
- **Both `payments.currency` and `orders.currency` retained, not treated as redundant.** Stripe requires a currency on every PaymentIntent regardless; keeping both lets a real mismatch between intended and actually-processed currency be detected rather than assumed away. No multi-currency *configuration* exists for MVP — it's a fixed default (`usd`).
- **`store_user` kept as a proper pivot table** rather than a single nullable `store_id` column on `users` — deliberately chosen for future multi-store staff assignment; low incremental cost now, expensive to retrofit later.
- **Composite foreign keys for cross-table tenant consistency** (e.g. enforcing `categories.store_id` matches `products.store_id` at the database level) were considered and deferred — real defense-in-depth, but app-layer validation is sufficient at this scale; the invariant is documented rather than DB-enforced.
- **`email_verified_at` left in place** on both `users` and `customers` despite email verification being an explicitly future PRD feature (§19) — negligible cost to leave a nullable, currently-unused column in place now versus a migration to add it later.
- **Full-refund-only scope** (no partial refunds, no `refund_id`-keyed ledger) — an already-agreed MVP simplification; the inventory ledger's idempotency key (`order_id`, `reason`) depends on this.
- **`organization_user.user_id` uniquely constrained** — a user belongs to exactly one organization for MVP. The pivot shape itself already supports multi-org membership when needed; this is a single index, easily lifted later.

## Security Risk Ranking (system-level review)

| Risk | Rank |
|---|---|
| Checkout total trusted from client instead of recalculated server-side | Critical |
| Stripe webhook signature not verified / endpoint spoofable | Critical |
| Cross-tenant data leakage via missing global scope or policy gap | Critical |
| AI tool accepting tenant params from LLM output (prompt-injection path to cross-tenant access) | Critical |
| Secrets (Stripe/OpenAI keys) committed, logged, or leaked into AI prompt context | Critical |
| Mass assignment allowing `organization_id`/`role`/`store_id` via request body | High |
| IDOR via route-bound resources without an explicit policy check | High |
| Missing rate limiting on auth endpoints (brute force) and AI endpoints (cost abuse) | High |
| Sensitive customer PII exposed in AI tool responses, logs, or analytics exports | High |
| CORS misconfiguration | Medium |
| CSRF (Sanctum SPA flow misconfigured, or webhook route accidentally CSRF-protected) | Medium |
| SQL injection (Eloquent/query builder safe by default; risk only in hand-written raw SQL) | Low |
| Catalog scraping / storefront API abuse | Low |

## Scalability: MVP vs. Future

**MVP-appropriate as specified**: single MySQL primary, single Redis instance serving cache+queue+rate-limit, direct-query analytics with short-TTL caching, synchronous webhook-triggered queue dispatch.

**Explicitly deferred**: MySQL read replicas, pre-aggregated analytics/materialized rollups, splitting Redis cache from Redis queue, dedicated queue workers by priority, multi-region deployment, CDN for product images — all future/AWS-phase per the PRD, not MVP.

## Interview / Portfolio Value

The architectural decisions most worth explaining in a senior engineering interview, and why:

1. **Server-injected tenant context for AI tools (LLM never supplies org/store).** Shows the LLM isn't treated as a trusted execution boundary; prevents prompt-injection-driven data leakage. Trade-off: the agent can't "explore" outside its fixed tool parameters — a deliberate rigidity.
2. **Idempotent webhook processing via a unique event-id constraint + atomic transactional status transition.** Real payment systems must handle at-least-once delivery correctly; prevents duplicate fulfillment or a silently-stranded paid order.
3. **Order-item snapshotting (immutable price/name/SKU at purchase time).** Historical order integrity survives product price changes or deletion; accepted trade-off of some data duplication over strict normalization.
4. **Transactional row-locked inventory decrement instead of a reservation system.** Demonstrates concurrency correctness without over-building; explicit trade-off of simplicity (decrement-at-payment) over a "more real-time-accurate" hold system.
5. **Direct-query analytics with cache-aside Redis instead of a pre-aggregation pipeline.** Judgment about when *not* to add data-engineering complexity; will need revisiting at real scale, and knowing exactly when is itself the signal.
6. **Structural tenant scoping (global scope + bound context) over scattered manual `where()` calls.** Security-by-construction — makes an entire bug class structurally harder to introduce.

## Final Recommendations Recap

**Must fix before development**: tenant-context implementation pattern, webhook idempotency mechanism, customer identity model, inventory model shape, server-side order-total recalculation — all resolved in `system-architecture.md` / `../database/database-design.md`.

**Recommended for MVP**: Sanctum + per-model Policies + global tenant scope; pending-order-first payment flow with queued post-payment jobs; row-locked transactional inventory decrement; direct-query + Redis-cached analytics; the seven defined AI tools shared across Q&A/Investigation/Insights/Reports; rate limiting on auth and AI endpoints; AI report persistence only (no persisted Q&A/insight history).

**Defer until later**: inventory reservation/soft-hold system; pre-aggregated analytics; multi-organization membership per user; a real coupon/discount-code engine; a full tax-jurisdiction engine; persisted AI conversation history; read replicas/multi-region/dedicated queue infrastructure; social login/email verification.

## Architecture Score: 7.5 / 10

The plan's instincts are right where it counts: multi-tenancy is treated as a structural problem rather than a per-query convention, the AI agent is designed as a tool-calling application feature rather than an authorization shortcut, idempotency and concurrency are named as first-class concerns, and MVP scope is disciplined about not over-building. The schema review surfaced real but fixable inconsistencies (tenant-column coverage, a delete-behavior contradiction, the soft-delete/unique-constraint interaction, webhook/order-transition atomicity) — all now resolved or explicitly tracked as open decisions — which is exactly what this review phase was for.
