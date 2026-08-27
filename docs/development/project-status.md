# Project Status

This document is the persistent source of truth for the current development state of the AI Commerce Platform. It exists so that development can continue correctly across sessions without relying on prior conversation context. Read this before starting significant work; update it after completing a significant phase.

## Current Phase

**Phase 2F — Inventory migrations. Complete and verified.** Phase 2E (Payments), Phase 2D (Orders), Phase 2C (Catalog), Phase 2B (Customer Domain), Phase 2A (Platform & Tenant Identity), Phase 1 (Docker Infrastructure Bootstrap), and Phase 0 (Architecture & Database Design) are all complete and approved. Phase 2F implemented, migrated, and verified the inventory domain: `inventory`, `inventory_transactions` — the final table pair in the originally-scoped Phase 2 migration plan (2A through 2F). **No models, policies, controllers, the locked inventory-mutation service, authentication flows, or API endpoints exist yet** — migrations only, per the incremental-by-functional-area plan. Full detail: "Phase 2F (Database Migrations)" section below (see "Phase 2A/2B/2C/2D/2E (Database Migrations)" for the prior batches).

**Development Environment**: see `docs/development/development-environment.md` for the full, permanent record of the verified local environment — exact versions, Docker architecture, service configuration, credential flow, and commands. That document is now the source of truth for environment detail; the "Infrastructure (Phase 1)" section below is a summary, not a duplicate.

**Database Design 2.5 is APPROVED** as the authoritative database design (corrected from a stale "2.4" reference previously left in this document — see "Residual documentation gap" under "Phase 2E (Database Migrations)" below for how that happened and was closed). Version 2.0 substantially upgraded the original MVP schema into a production-quality e-commerce data model — product variants/options, customer saved addresses, immutable order address snapshots, and Stripe saved payment methods — and resolved its one remaining open decision (order/payment status separation). Version 2.1 added Platform Admin as a third, structurally separate identity domain plus an organization approval/suspension lifecycle. Version 2.2 removed `carts`/`cart_items` from the MySQL schema entirely. Version 2.3 resolved three Phase 2C catalog design-review findings. Version 2.4 resolved the Phase 2D Orders design review. **Version 2.5, a documentation-only reconciliation, brought the document into full agreement with the already-implemented Phase 2E `payments`/`refunds`/`stripe_webhook_events` migrations** — `refunds` gained a complete formal column table (previously the only table in the document without one), `stripe_webhook_events` gained exact types/nullability, and `payments.organization_id`/`store_id`'s `ON DELETE RESTRICT` was made explicit. See "Platform Admin (Database Design 2.1)," "Cart Architecture (Database Design 2.2)," "Catalog Design Review Corrections (Database Design 2.3)," "Phase 2D Orders Design Review Resolutions (Database Design 2.4)," under Database Decisions for full detail on 2.1–2.4. Phase 2A's six tables, Phase 2B's two tables, Phase 2C's seven tables, Phase 2D's three tables, Phase 2E's four tables, and Phase 2F's two tables (twenty-four total) are now migrated and verified — this completes every table in the originally-scoped Phase 2A–2F migration plan. Only `reports` (AI-generated artifacts, not yet broken down into its own phase) remains unmigrated from the full Database Design 2.5 schema.

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
- **Catalog Design Review corrections (Database Design 2.3)** — identified during the Phase 2C design review and resolved before any Phase 2C migration was written: `categories.deleted_at` added (correcting an internal documentation contradiction), the variant option-value cardinality rule documented as an application-layer invariant, and product soft-delete confirmed as never blocked by active variants/options (unlike store deactivation). `docs/database/database-design.md` and `docs/development/project-status.md` updated; no migration, model, controller, or config file touched. See "Catalog Design Review Corrections (Database Design 2.3)" under Database Decisions below for full detail.
- **Phase 1 — Docker Infrastructure Bootstrap (implementation, not documentation)**: Laravel 13 scaffolded under `backend/`, React 19.2 + TS + Vite scaffolded under `frontend/`, full Docker Compose stack (app, nginx, mysql, redis, queue, node) built and verified running, Sanctum + API routing foundation installed. See "Infrastructure (Phase 1)" below for exact versions, verification results, and commands.
- **Phase 2A — Platform & Tenant Identity migrations (implementation, verified)**: six migrations created and run against MySQL — `platform_admins`, `organizations`, `stores`, `add_soft_deletes_to_users_table`, `organization_user`, `store_user`. Verified via `php artisan migrate --pretend` (SQL reviewed before running), `php artisan migrate` (batch 2, all six `Ran`), `php artisan db:table` on all six tables (columns, defaults, FK actions, indexes, unique constraints all confirmed matching Database Design 2.1), `php artisan migrate:status`, and `php artisan test` (2/2 passing — Laravel's default example tests; no schema-specific tests exist yet). See "Phase 2A (Database Migrations)" below for full detail.
- **Phase 2B — Customer Domain migrations (implementation, verified)**: two migrations created and run against MySQL — `create_customers_table`, `create_customer_addresses_table`. Verified via `php -l` on both files, `php artisan migrate --pretend` (SQL reviewed before running), `php artisan migrate` (batch 3, both `Ran`), `php artisan db:table` on both tables (columns, defaults, FK actions — including the `RESTRICT`/`RESTRICT`/`CASCADE` behavior decided for the previously-unresolved `customers` tenant FKs — indexes, unique constraints all confirmed matching the approved Phase 2B plan), `php artisan migrate:status`, and `php artisan test` (2/2 passing). See "Phase 2B (Database Migrations)" below for full detail.
- **Phase 2C — Catalog migrations (implementation, verified)**: seven migrations created and run against MySQL — `create_categories_table`, `create_products_table`, `create_product_options_table`, `create_product_option_values_table`, `create_product_variants_table`, `create_product_variant_option_values_table`, `create_product_images_table`. Verified via `php -l` on all 7 files, `php artisan migrate --pretend` (SQL reviewed before running and matched Database Design 2.3 exactly), `php artisan migrate` (batch 4, all 7 `Ran` after one fix — see "Phase 2C (Database Migrations)" below), `php artisan db:table` on all 7 tables (columns, defaults, FK actions, indexes, unique constraints all confirmed matching Database Design 2.3), full rollback/re-migrate exercise of all 7 `down()` methods, `php artisan migrate:status`, and `php artisan test` (2/2 passing). Phase 2A/2B tables confirmed untouched throughout. See "Phase 2C (Database Migrations)" below for full detail, including two flagged implementation deviations.
- **Phase 2D Orders Design Review resolutions (Database Design 2.4)** — a comprehensive 19-point design review of the already-specified `orders`/`order_items`/`order_addresses` schema (unchanged since Database Design 2.0), resolving four open questions before any Phase 2D migration was written: confirmed no `orders.shipping_total` column; recorded `paid → cancelled` as an explicitly disallowed transition (authoritative business invariant); recorded the late-payment/inventory-oversell scenario as a known decision deferred to Phase 2F; widened `orders`' customer-history index to `(store_id, customer_id, created_at)`. `docs/database/database-design.md`, `docs/architecture/system-architecture.md` (§4 transaction-boundary wording), and `docs/development/project-status.md` updated; no migration, model, controller, or config file touched. See "Phase 2D Orders Design Review Resolutions (Database Design 2.4)" under Database Decisions below for full detail.
- **Phase 2D — Orders migrations (implementation, verified)**: three migrations created and run against MySQL — `create_orders_table`, `create_order_items_table`, `create_order_addresses_table`. A mid-task conflict between the task's literal column lists and the already-approved Database Design 2.4 was caught and clarified with you before any file was written (see "Phase 2D (Database Migrations)" below). Verified via `php -l` on all 3 files, `php artisan migrate --pretend` (SQL reviewed before running and matched Database Design 2.4 exactly), `php artisan migrate` (batch 5, all 3 `Ran` on the first attempt), `php artisan db:table` on all 3 tables (columns, defaults, FK actions, indexes, unique constraints all confirmed matching Database Design 2.4), a raw `information_schema` query confirming the `CHECK (quantity > 0)` constraint on `order_items` (Laravel's MySQL grammar has no native `check()` support — confirmed by inspecting the vendor source), a raw `information_schema` query confirming none of `shipping_total`/`payment_status`/`fulfillment_status`/`deleted_at` exist anywhere in the 3 tables, full rollback/re-migrate exercise of all 3 `down()` methods (with the `CHECK` constraint re-verified afterward), `php artisan migrate:status`, and `php artisan test` (2/2 passing). Phase 2A/2B/2C tables confirmed untouched throughout. See "Phase 2D (Database Migrations)" below for full detail.
- **Phase 2E Payments Design Review** — a 25-point design review of `payments`/`payment_methods`/`refunds`/`stripe_webhook_events`, surfacing that `refunds` had **no formal column-type specification anywhere** in `database-design.md` (unlike every other table) — flagged as I-1, along with two minor gaps (I-2: `payments`' tenant-FK `ON DELETE` behavior unstated; I-3: `stripe_webhook_events` varchar lengths/`processed_at` nullability unstated). Read-only review, no files modified. You approved concrete resolutions for all three, implemented in Phase 2E below.
- **Phase 2E — Payments migrations (implementation, verified)**: four migrations created and run against MySQL — `create_payment_methods_table`, `create_payments_table`, `create_refunds_table`, `create_stripe_webhook_events_table`, implementing Database Design 2.4 plus the approved I-1/I-2/I-3 decisions. Verified via `php -l` on all 4 files, `php artisan migrate --pretend` (SQL reviewed before running), `php artisan migrate` (batch 6, all 4 `Ran` on the first attempt), `php artisan db:table` on all 4 tables, an `information_schema.COLUMNS` query cross-verifying exact nullability/defaults for every column (confirming `payments.status`/`currency` correctly have no default, while `refunds.status` correctly defaults to `'pending'`), an `information_schema.COLUMNS` query confirming `orders` has no Stripe-specific or `payment_status` column, full rollback/re-migrate exercise of all 4 `down()` methods in correct reverse-FK order, `php artisan migrate:status`, and `php artisan test` (2/2 passing). Phase 2A/2B/2C/2D tables (18 total) confirmed untouched throughout; `git diff` confirmed empty on every prior migration file and on `backend/app`/`backend/routes`. See "Phase 2E (Database Migrations)" below for full detail, including a residual documentation gap in `database-design.md` itself (since closed — see below).
- **Phase 2E Documentation Reconciliation (Database Design 2.5)** — a separate, documentation-only turn brought `database-design.md` into full agreement with the already-implemented Phase 2E migrations: `refunds` gained a complete formal column table (closing the I-1 gap), `stripe_webhook_events` gained exact types/nullability (I-3), and `payments.organization_id`/`store_id`'s `ON DELETE RESTRICT` was made explicit (I-2). No schema change — confirmed field-by-field against the actual migration files. Only `docs/database/database-design.md` was modified.
- **Phase 2F Inventory Design Review** — a 17-point read-only design review of the already-fully-specified `inventory`/`inventory_transactions` schema (unchanged since Database Design 2.0). Unlike Phase 2E's `refunds` gap, both tables were already complete with no missing types, FKs, or constraints — verdict: READY. Confirmed the G3 late-payment/inventory-oversell question needs no schema accommodation and re-scoped it explicitly to a future service-design phase (not a Phase 2F migration concern). Reconfirmed `system-architecture.md` §5's pre-existing "one inventory row per product" staleness is still present, cosmetic, and already tracked — left unmodified per instruction. No files modified.
- **Phase 2F — Inventory migrations (implementation, verified)**: two migrations created and run against MySQL — `create_inventory_table`, `create_inventory_transactions_table`, implementing Database Design 2.5 exactly with no schema deviation. Verified via `php -l` on both files, `php artisan migrate --pretend` (SQL matched exactly; every identifier name checked against MySQL's 64-character limit, longest was 58 characters), `php artisan migrate` (batch 7, both `Ran` on the first attempt), `php artisan db:table` on both tables, an `information_schema.COLUMNS` query listing the complete column set for both (confirming no `organization_id`/`store_id`/`product_id`/`reserved_quantity`/`available_quantity`/`deleted_at`/`before_quantity`/`after_quantity` anywhere), the `CHECK (quantity_on_hand >= 0)` constraint verified via `information_schema.CHECK_CONSTRAINTS`, full rollback/re-migrate exercise of both `down()` methods (with the `CHECK` constraint re-verified afterward), `php artisan migrate:status`, and `php artisan test` (2/2 passing). Phase 2A–2E tables (22 total) confirmed untouched throughout; `git diff` confirmed empty on every prior migration file and on `backend/app`/`backend/routes`. This completes every table in the originally-scoped Phase 2A–2F migration plan. See "Phase 2F (Database Migrations)" below for full detail.

**Implementation work completed:** infrastructure (Phase 1) and Phase 2A/2B/2C/2D/2E/2F migrations (above) — all verified working via actual command execution, not assumed. No models, policies, controllers, business API routes, or authentication flows exist yet; no tests beyond Laravel's default example tests.

## Current Task

None in progress. Phase 2F is complete and verified — every table in the originally-scoped Phase 2A–2F migration plan is now migrated. The project is paused awaiting explicit instruction on what comes next (Eloquent models/application layer, or the not-yet-scoped `reports` table / analytics phase).

## Next Step

No further Phase 2 migration batches are planned beyond what's now complete — `inventory`/`inventory_transactions` (Phase 2F) close out the schema originally broken into Phases 2A–2F. Remaining work: `reports` (AI-generated artifacts, not yet broken into its own phase) is the only table left unmigrated from the full Database Design 2.5 schema; everything else is application-layer (Eloquent models, TenantContext middleware, RBAC/policies, the locked inventory-mutation service, checkout/payment/webhook services including G3's resolution, seeders, API endpoints, frontend). Do not begin any of this without explicit approval, consistent with the incremental-by-functional-area approach used throughout Phase 2 (Design → Review → Migration → Run migration → Verify → Sign-off → Next phase).

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

## Phase 2C (Database Migrations)

**Status: complete and verified**, 2026-08-27. Seven migration files under `backend/database/migrations/`, run against the `mysql` container's `ai_commerce` database, all in batch 4:

| Migration | Table / change | Key facts verified in the running database |
|---|---|---|
| `2026_08_27_100100_create_categories_table` | `categories` (new) | `organization_id`/`store_id` FKs → `organizations.id`/`stores.id`, both `ON DELETE RESTRICT` (inferred from the design's undocumented ON DELETE for these two columns, applying the same convention already established for `customers` in Phase 2B — see "Deviations" below); unique `(store_id, slug)`; `deleted_at` present (soft delete — Database Design 2.3 correction). |
| `2026_08_27_100200_create_products_table` | `products` (new) | `organization_id`/`store_id` FKs `ON DELETE RESTRICT` (same inferred convention); `category_id` FK → `categories.id`, nullable, `ON DELETE SET NULL` (explicitly documented); unique `(store_id, slug)`; indexes `(store_id, status)`, `(store_id, category_id)`; `deleted_at` present. |
| `2026_08_27_100300_create_product_options_table` | `product_options` (new) | `product_id` FK → `products.id`, `ON DELETE CASCADE`; unique `(product_id, name)`; no tenant columns (pure child of `products`, per design); no `deleted_at` (not in the soft-delete table list). |
| `2026_08_27_100400_create_product_option_values_table` | `product_option_values` (new) | `product_option_id` FK → `product_options.id`, `ON DELETE CASCADE`; unique `(product_option_id, value)`; no tenant columns; no `deleted_at`. |
| `2026_08_27_100500_create_product_variants_table` | `product_variants` (new) | `organization_id`/`store_id` FKs `ON DELETE RESTRICT` (inferred convention, direct tenant columns per design's stated rationale); `product_id` FK → `products.id`, `ON DELETE CASCADE`; unique `(store_id, sku)` and `(product_id, option_signature)`; index `(store_id, status)`; `deleted_at` present. `option_signature` column created as a plain `varchar(255) not null` — no generation logic implemented (deliberately deferred to the service layer, per Database Design 2.3's documented application-layer invariant). |
| `2026_08_27_100600_create_product_variant_option_values_table` | `product_variant_option_values` (new) | `product_variant_id` FK → `product_variants.id`, `ON DELETE CASCADE`; `product_option_value_id` FK → `product_option_values.id`, `ON DELETE RESTRICT` (the deliberately asymmetric pair, confirmed); unique `(product_variant_id, product_option_value_id)` under an explicit shorter constraint name, `pvov_variant_id_option_value_id_unique` (MySQL's default 64-character identifier limit rejected Laravel's auto-generated name — see "Deviations" below); no `deleted_at`. |
| `2026_08_27_100700_create_product_images_table` | `product_images` (new) | `product_id` FK → `products.id`, `ON DELETE CASCADE`; `product_variant_id` FK → `product_variants.id`, nullable, `ON DELETE CASCADE` (not `SET NULL`, per design); indexes `(product_id, sort_order)`, `(product_variant_id, sort_order)`; no unique constraint (the `is_primary` one-per-parent rule is an application invariant, not DB-enforced, per the design); no `deleted_at`. |

**Deviations from the literal design doc (both flagged, neither a design change):**
1. **`organization_id`/`store_id` `ON DELETE` behavior on `categories`/`products`/`product_variants`** — Database Design 2.3 documents these columns as `FK, not null` but doesn't state an explicit `ON DELETE` action (the same gap that existed for `customers` in Phase 2B, where `RESTRICT` was explicitly decided). Implemented as `RESTRICT`, consistent with that established, documented precedent (`stores.organization_id`, `customers.organization_id`/`store_id`) for every top-level tenant-scoped table in this schema. Not an invented behavior — an applied convention.
2. **`product_variant_option_values`'s unique constraint needed an explicit shorter name.** Laravel's auto-generated identifier for `unique(product_variant_id, product_option_value_id)` (`product_variant_option_values_product_variant_id_product_option_value_id_unique`, 82 characters) exceeds MySQL's 64-character identifier limit and failed at migration time (`SQLSTATE[42000]: ... Identifier name ... is too long`). Fixed by supplying an explicit constraint name, `pvov_variant_id_option_value_id_unique` — same columns, same semantics, same enforcement; only the constraint's name differs from what an unnamed call would have generated. The partially-created table (both FKs applied, unique constraint failed) was dropped and the migration re-run cleanly before this was recorded as `Ran`.

**Verification method**: `php -l` on all 7 files (inside the `app` container); `migrate --pretend` reviewed before running (SQL matched Database Design 2.3 exactly, table-by-table); `migrate` executed (failed once on `product_variant_option_values` per Deviation 2 above, fixed, re-run); `php artisan db:table <table>` run against all 7 tables and cross-checked column-by-column, FK-by-FK, index-by-index against `docs/database/database-design.md` — full match; `migrate:status` confirms all 7 as `Ran`, batch 4; **rollback verified**: all 7 migrations' `down()` methods were exercised via `migrate:rollback` (in two steps, respecting FK dependency order) and confirmed to drop cleanly, then re-migrated to restore the final state; Phase 2A/2B tables (batches 1–3) confirmed untouched throughout (`migrate:status` before/after, and row counts — all zero, no seed data exists yet); `php artisan test` passes (2/2 — Laravel defaults only, no schema-specific tests exist yet).

Not yet done (by design, next phases): Eloquent models, the three Database Design 2.3 application-layer invariants (variant option-value cardinality enforcement, `option_signature` generation, product-soft-delete query scoping — all now documented, none implemented), RBAC/policies, TenantContext middleware, any seeders, and Phase 2D onward's tables.

## Phase 2D (Database Migrations)

**Status: complete and verified**, 2026-08-27. Three migration files under `backend/database/migrations/`, run against the `mysql` container's `ai_commerce` database, all in batch 5:

| Migration | Table / change | Key facts verified in the running database |
|---|---|---|
| `2026_08_27_110100_create_orders_table` | `orders` (new) | `organization_id`/`store_id`/`customer_id` FKs, all `ON DELETE RESTRICT` (explicitly documented in Database Design 2.4, not inferred this time); unique `order_number`; indexes `(store_id, status, created_at)`, `(store_id, customer_id, created_at)` (the 2.4-widened index), `(organization_id, created_at)`; no `shipping_total`/`payment_status`/`fulfillment_status`/`deleted_at` columns (confirmed absent via `information_schema` query, not just by omission from the migration). |
| `2026_08_27_110200_create_order_items_table` | `order_items` (new) | `order_id` FK `ON DELETE CASCADE`; `product_id`/`product_variant_id` FKs, both nullable, `ON DELETE SET NULL`; `selected_options` json nullable; a raw `CHECK (quantity > 0)` constraint added via `DB::statement` (Laravel's MySQL grammar has no native `check()` — confirmed by inspecting `vendor/laravel/framework`), verified present via `information_schema.CHECK_CONSTRAINTS` and confirmed it survives a full rollback/re-migrate cycle. |
| `2026_08_27_110300_create_order_addresses_table` | `order_addresses` (new) | `order_id` FK `ON DELETE CASCADE`; unique `(order_id, type)`; only `created_at` present, no `updated_at` (immutable-after-creation, matching the design); uses the Database Design 2.4-approved field set (`recipient_name`/`line1`/`line2`/`state`) — a mid-task clarification was needed and resolved before implementation (see "Deviations" below). |

**Deviations from the literal task instructions (both resolved before implementation, neither a design change):**
1. **The task message's literal column lists for `orders` and `order_addresses` conflicted with the already-approved Database Design 2.4.** `orders`' list omitted `status_reason` (present in the approved design); `order_addresses`' list used a different, unreviewed field set (`first_name`/`last_name`/`company`/`address_line1`/`address_line2`/`province`) that didn't match the approved design or the already-migrated `customer_addresses` table it's meant to mirror. Per CLAUDE.md's rule to stop and report conflicts rather than silently resolve them, this was raised via a clarifying question before any file was written. Resolved: implement exactly per the approved Database Design 2.4 in both cases (`status_reason` included; `recipient_name`/`line1`/`line2`/`state` used) — no design change, no schema deviation from what was already approved.
2. **`orders.type`'s stray `->default('shipping')`, added then removed before validation.** While writing the migration, a default value was added to `order_addresses.type` that isn't specified anywhere in Database Design 2.4 (only "not null" is documented, no default). Caught and removed during self-review, before running `migrate --pretend`. Mentioned here only because the task asked not to silently introduce additional constraints — this one was caught and reverted within the same turn, never reached the database.

**Verification method**: `php -l` on all 3 files; `migrate --pretend` reviewed before running (SQL matched Database Design 2.4 exactly, table-by-table, including the widened index); `migrate` executed cleanly on the first attempt (batch 5, all 3 `Ran`); `php artisan db:table <table>` run against all 3 tables and cross-checked column-by-column, FK-by-FK, index-by-index against `docs/database/database-design.md`; the `CHECK` constraint (not visible in `db:table`'s output) verified separately via a direct `information_schema.TABLE_CONSTRAINTS`/`CHECK_CONSTRAINTS` join; a direct `information_schema.COLUMNS` query confirmed none of `shipping_total`/`payment_status`/`fulfillment_status`/`deleted_at` exist on any of the 3 tables; **rollback verified**: all 3 `down()` methods exercised via `migrate:rollback --step=3` (correct reverse-FK order: `order_addresses` → `order_items` → `orders`) and confirmed to drop cleanly, then re-migrated to restore the final state, with the `CHECK` constraint re-verified present afterward; `migrate:status` confirms all 3 in batch 5 as `Ran`; Phase 2A/2B/2C tables (batches 1–4) confirmed untouched throughout (`migrate:status` before/after, and row counts on all 15 prior tables — all zero, no seed data exists yet); `php artisan test` passes (2/2 — Laravel defaults only, no schema-specific tests exist yet).

Not yet done (by design, next phases): Eloquent models, checkout/order-creation service logic (including the atomic `orders`+`order_items`+`order_addresses` transaction), RBAC/policies, TenantContext middleware, any seeders, and Phase 2E onward's tables (`payments`, `refunds`, `stripe_webhook_events`).

## Phase 2E (Database Migrations)

**Status: complete and verified**, 2026-08-27. Four migration files under `backend/database/migrations/`, run against the `mysql` container's `ai_commerce` database, all in batch 6:

| Migration | Table / change | Key facts verified in the running database |
|---|---|---|
| `2026_08_27_120100_create_payment_methods_table` | `payment_methods` (new) | `customer_id` FK `ON DELETE CASCADE`; unique `stripe_payment_method_id`; `exp_month`/`exp_year` as unsigned tinyint/smallint per the design; `deleted_at` present (soft delete — a removed card can still be referenced historically by `payments.payment_method_id`). |
| `2026_08_27_120200_create_payments_table` | `payments` (new) | `organization_id`/`store_id`/`order_id` FKs `ON DELETE RESTRICT` (org/store per the newly-approved I-2 decision; order per the pre-existing explicit design); `payment_method_id` FK nullable `ON DELETE SET NULL`; unique `stripe_payment_intent_id`; indexes `(order_id, status)`, `(store_id, status)`; `status` and `currency` correctly carry **no default** (none specified anywhere in Database Design 2.4 or the approval — confirmed via `information_schema`, not silently added). |
| `2026_08_27_120300_create_refunds_table` | `refunds` (new) | Implemented exactly per the approved I-1 schema: `organization_id`/`store_id`/`order_id`/`payment_id` FKs `ON DELETE RESTRICT`, `initiated_by_user_id` nullable FK → `users`, `ON DELETE SET NULL`; unique `stripe_refund_id`; `status` defaults to `'pending'` (the one column in this whole phase with an explicit default, per I-1); indexes `(order_id, status)`, `(store_id, status)`. This closes the design-review gap (I-1) where `refunds` previously had no formal column-type specification anywhere in `database-design.md`. |
| `2026_08_27_120400_create_stripe_webhook_events_table` | `stripe_webhook_events` (new) | Implemented exactly per the approved I-3 schema: no foreign keys (standalone, tenant-independent by design); unique `stripe_event_id`; `processed_at` **not nullable** (confirmed via `information_schema` — no "nullable" tag in `db:table`'s output either); `payload` json nullable; only `created_at`, no `updated_at`; index `(type, processed_at)`. |

**Verification method**: `php -l` on all 4 files; `migrate --pretend` reviewed before running (SQL matched Database Design 2.4 plus the approved I-1/I-2/I-3 decisions exactly, table-by-table); `migrate` executed cleanly on the first attempt (batch 6, all 4 `Ran`); `php artisan db:table <table>` run against all 4 tables and cross-checked column-by-column, FK-by-FK, index-by-index; a direct `information_schema.COLUMNS` query cross-verified exact nullability and defaults for every column across all 4 tables (catching that `payments.status`/`currency` correctly have no default, unlike `refunds.status`); a direct `information_schema.COLUMNS` query on `orders` confirmed no Stripe-specific or `payment_status` column exists there; **rollback verified**: all 4 `down()` methods exercised via `migrate:rollback --step=4` (correct reverse-FK order: `stripe_webhook_events` → `refunds` → `payments` → `payment_methods`) and confirmed to drop cleanly, then re-migrated to restore the final state; `migrate:status` confirms all 4 in batch 6 as `Ran`; Phase 2A/2B/2C/2D tables (batches 1–5, 18 tables) confirmed untouched throughout (`migrate:status` before/after, and row counts on all 18 — all zero, no seed data exists yet); `php artisan test` passes (2/2 — Laravel defaults only, no schema-specific tests exist yet); `git diff` confirmed empty on every Phase 2A–2D migration file and on `backend/app`/`backend/routes` (no application code touched).

**Residual documentation gap — since closed.** At the time this phase's migrations were implemented, `docs/database/database-design.md` still contained only a prose-level description of `refunds` and a partially-detailed `stripe_webhook_events` spec — this turn's instructions restricted documentation updates to this file only. A **separate, subsequent documentation-only turn brought `database-design.md` to version 2.5**, bringing it into full agreement with these migrations: `refunds` gained a complete formal column table, `stripe_webhook_events` gained exact types/nullability, and `payments.organization_id`/`store_id`'s `ON DELETE RESTRICT` was made explicit — closing this gap. `database-design.md` is now the authoritative record of that detail; this file's own top-of-document version reference has been updated accordingly (see "Current Phase" above).

## Phase 2F (Database Migrations)

**Status: complete and verified**, 2026-08-27. Two migration files under `backend/database/migrations/`, run against the `mysql` container's `ai_commerce` database, both in batch 7:

| Migration | Table / change | Key facts verified in the running database |
|---|---|---|
| `2026_08_27_130100_create_inventory_table` | `inventory` (new) | `product_variant_id` FK `ON DELETE CASCADE`, unique (true 1:1 with `product_variants`); `quantity_on_hand` (int, default 0) with a raw `CHECK (quantity_on_hand >= 0)` constraint (Laravel has no native `check()`, same pattern as Phase 2D's `order_items.quantity`), verified via `information_schema.CHECK_CONSTRAINTS`; `low_stock_threshold` nullable; no `organization_id`/`store_id`/`product_id`/`reserved_quantity`/`available_quantity`/`deleted_at` — confirmed absent via a direct `information_schema.COLUMNS` query against the full column set, not just by omission from the migration. |
| `2026_08_27_130200_create_inventory_transactions_table` | `inventory_transactions` (new) | `product_variant_id` FK `ON DELETE RESTRICT`; `order_id`/`order_item_id` FKs, both nullable, `ON DELETE RESTRICT`; `created_by_user_id` FK → `users`, nullable, `ON DELETE SET NULL`; unique `(order_item_id, reason)` (MySQL's NULL-distinct semantics correctly allow unlimited manual adjustments where `order_item_id` is null); indexes `(product_variant_id, created_at)`, `order_id`, `order_item_id`; only `created_at`, no `updated_at` and no `deleted_at` (append-only ledger); no `before_quantity`/`after_quantity`/tenant columns — confirmed absent via the same full-column-set query. |

**Verification method**: `php -l` on both files; `migrate --pretend` reviewed before running (SQL matched the approved schema exactly, and every generated identifier name checked against MySQL's 64-character limit — longest was 58 characters, no shortening needed this time, unlike Phase 2C); `migrate` executed cleanly on the first attempt (batch 7, both `Ran`); `php artisan db:table` on both tables; a direct `information_schema.COLUMNS` query listing the complete column set for both tables (confirming exactly the approved columns, nothing extra, nothing missing); the `CHECK` constraint verified via `information_schema.CHECK_CONSTRAINTS`; **rollback verified**: both `down()` methods exercised via `migrate:rollback --step=2` (correct reverse order: `inventory_transactions` → `inventory`) and confirmed to drop cleanly, then re-migrated to restore the final state, with the `CHECK` constraint re-verified present afterward; `migrate:status` confirms both in batch 7 as `Ran`; Phase 2A–2E tables (batches 1–6, 22 tables) confirmed untouched throughout (`migrate:status` before/after, and row counts on all 17 named tables — all zero, no seed data exists yet); `php artisan test` passes (2/2 — Laravel defaults only, no schema-specific tests exist yet); `git diff` confirmed empty on every prior migration file and on `backend/app`/`backend/routes` (no application code touched).

**G3 status, explicitly not resolved here (by design and by instruction)**: the late-payment/inventory-oversell business policy (what happens when a payment succeeds against inventory a different order already consumed) remains open. This phase's design review confirmed the *schema* needs no change to accommodate whatever policy is eventually chosen — the locked service's rejection guard is already policy-agnostic — so the question is now explicitly re-scoped to a future checkout/webhook **service-design** phase, not attached to "Phase 2F" generally. No inventory column, table, constraint, or migration logic was added for this.

**Documentation note**: `system-architecture.md` §5's stale "one `inventory` row per product" wording (should read "per product variant") was reconfirmed as present and unchanged during this phase's design review. Per this turn's explicit instruction, it was not modified — it remains exactly as already tracked in this file's Known Issues section below.

Not yet done (by design, next phases): Eloquent models, the locked inventory-mutation service itself, checkout/webhook service logic (including G3's resolution), RBAC/policies, TenantContext middleware, any seeders, and whatever phase comes after Phase 2F (analytics/AI reports, `reports` table — not yet broken down in detail).

Not yet done (by design, next phases): Eloquent models, Stripe API integration, webhook handler implementation, payment/refund service logic, RBAC/policies, TenantContext middleware, any seeders, and Phase 2F onward's tables (`inventory`, `inventory_transactions`).

## Not Started

- Database migrations for the approved business schema (inventory, reports, etc.) — `platform_admins`, `organizations`, `stores`, `users` (soft-delete), `customers`, `customer_addresses`, `categories`, `products`, `product_options`, `product_option_values`, `product_variants`, `product_variant_option_values`, `product_images`, `orders`, `order_items`, `order_addresses`, `payment_methods`, `payments`, `refunds`, and `stripe_webhook_events` are done, see Phase 2A/2B/2C/2D/2E above. Note: `carts`/`cart_items` are **not** on this list at all — per Database Design 2.2, they are never migrated to MySQL; cart state is ephemeral (guest `localStorage`, authenticated Redis).
- Platform Admin authentication, authorization, and API surface (`/api/platform/*`) — design only so far, per Database Design 2.1
- Eloquent models
- TenantContext / multi-tenancy middleware and Eloquent global scopes
- RBAC / Policies
- Authentication implementation (login/register/logout/password reset) — Sanctum is installed but no auth flow exists
- Customer authentication
- Product management business logic — catalog *tables* are migrated (Phase 2C), but the `option_signature` generation, variant option-value cardinality enforcement, and product-soft-delete query scoping (all documented as application-layer invariants in Database Design 2.3) are not yet implemented; no models/controllers/services exist
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

### Catalog Design Review Corrections (Database Design 2.3, approved 2026-08-27)

Identified and resolved during the Phase 2C design review — before any Phase 2C migration was written, the same pattern as the Platform Admin (2.1) and Cart Architecture (2.2) corrections before their respective phases. No migration existed for any of the 7 catalog tables at the time, so nothing needed to be reverted.

- **`categories.deleted_at` added — documentation correction, not a design change.** The design review surfaced an internal contradiction in `database-design.md`: the `categories` table's own "Rejected" note said "categories already soft-delete," and the Soft-Delete Strategy section already listed `categories.slug` in its mutate-on-delete column list — but the `categories` column table itself never actually listed a `deleted_at` column. Resolved in favor of the two statements that assumed soft-delete: `categories` formally has `deleted_at` (nullable timestamp) and follows the same mutate-on-delete `slug`-suffixing pattern as `products`/`stores`. No `status` column was added (re-confirmed rejected, unchanged).
- **Variant option-value cardinality — documented as an application-layer invariant, not a schema change.** `product_variant_option_values`' constraints prevent a duplicate link but not an invalid *set* — nothing stops a variant from linking to two values of the same option, or omitting a required option. Decision: for a given variant, at most one selected `product_option_value` per `product_option`; if the product defines options, a variant must have exactly one value for each; the option-less "default" variant is the sole exception. This must be enforced by the service layer atomically together with `option_signature` recomputation whenever a variant's option-value links change. `option_signature`'s DB-level unique constraint remains the mechanism that prevents duplicate *combinations* — this invariant is a separate, complementary rule about what makes a combination *valid* in the first place.
- **Product soft-delete vs. active variants/options — resolved, deliberately asymmetric to the store-level rule.** A `product` may be soft-deleted even while it still has active `product_variants`/`product_options` — this is **not** blocked at the application layer, unlike store deactivation (§ Cascading soft-deletes, above). Rationale: the store-level block protects other tenants' independently-visible top-level resources (products, categories, customers); a product's variants/options are pure children with no independent visibility to protect, so there's nothing distinct for a block to guard. On product soft-delete, child rows are neither cascaded nor mutated — they persist untouched (preserving historical order/inventory references) but become unreachable through normal storefront/catalog queries, which scope on `products.deleted_at IS NULL`. No new DB constraint, column, or migration.

Full detail and rationale for all three: `docs/database/database-design.md` (Database Design 2.3 changelog entry, the `categories` table definition, the `product_variant_option_values` section, and "Soft-Delete Strategy").

### Phase 2D Orders Design Review Resolutions (Database Design 2.4, approved 2026-08-27)

Identified and resolved during the Phase 2D design review — before any Phase 2D migration was written, the same pattern as the Platform Admin (2.1), Cart Architecture (2.2), and Catalog (2.3) corrections before their respective phases. `orders`/`order_items`/`order_addresses` were already fully specified since Database Design 2.0 and unchanged by 2.1–2.3; this pass validated that existing design against a full 19-point review and resolved four open questions (G1–G4):

- **G1 — No `orders.shipping_total` column (confirmed, no schema change).** PRD §7.1's authoritative order-field list omits shipping; PRD §29 excludes Shipping Provider Integration from MVP scope entirely. "Shipping information" in PRD §5.5's checkout summary means the shipping *address* (already captured by `order_addresses`), not a cost line. The existing (unmodified since 2.0) schema was already correct; this makes the omission an explicit, confirmed decision rather than an unreviewed gap.
- **G2 — `paid → cancelled` recorded as an explicitly disallowed transition (authoritative business invariant, no schema change).** `cancelled` is reachable only from `pending`. A `paid`-or-later order can only be stopped via the refund flow. Not a new rule — already implied by the existing transition table — but now named explicitly under "Order & Payment State Models" §3 because it constrains future Store Admin order-action UI (a "Cancel" action must be unavailable, or must route to "Refund," once an order is `paid` or later).
- **G3 — Late-payment / inventory-oversell scenario recorded as a known, explicitly deferred Phase 2F decision (no schema change, no behavior implemented).** Phase 2D creates `pending` orders with no inventory reservation (MVP has no soft-hold system), so multiple pending orders can reference the same remaining stock; if a payment later succeeds against depleted inventory, the resulting webhook-level behavior (auto-refund, manual reconciliation, or otherwise) is explicitly **not** decided now. Documented under "Concurrency Review" item 10 and "Open Decisions" in `database-design.md` so it isn't lost before Phase 2F is designed.
- **G4 — `orders`' customer-history index widened**: `(store_id, customer_id)` → `(store_id, customer_id, created_at)` — supports the customer `/orders` history query (newest-first) without a filesort, same tenant/customer lookup prefix preserved. The one genuine schema change in this pass.

Also applied: `docs/architecture/system-architecture.md` §4's checkout transaction-boundary sentence was extended to explicitly name `order_addresses` alongside `orders`/`order_items` — a documentation-completeness fix, not an architectural change (the three tables were already implicitly expected to be created atomically; the sentence just didn't enumerate all three).

Full detail and rationale for all four: `docs/database/database-design.md` (Database Design 2.4 changelog entry, the `orders` table definition, "Order & Payment State Models" §3, "Concurrency Review" item 10, and "Open Decisions").

## Open Decisions

**One item deferred (non-blocking); the prior historical item remains closed.**

- **CLOSED**: PRD.md §7.1 "Payment Status" vs. "Order Status" — see "Final Resolution" under Database Decisions above.
- **DEFERRED to Phase 2F (2.4, not blocking Phase 2D)**: the late-payment/inventory-oversell webhook-level consequence (G3, above) — must be resolved when Phase 2F (Inventory) is designed, not before.

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
