# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Status

This repository currently contains only `PRD.md` and this file — no application code has been scaffolded yet (no `backend/`, `frontend/`, `composer.json`, `package.json`, or `docker-compose.yml`). Do not initialize Laravel or React until explicitly asked to. When scaffolding is added, this file should be updated with the actual commands (`composer.json` scripts, `package.json` scripts, `artisan` commands, etc.) rather than assumed defaults.

The full product specification lives in `PRD.md` — read it before starting implementation work. The summary below is the architecture distilled from that spec, useful for orienting quickly without re-reading the whole document.

## Project State Management

`docs/development/project-status.md` is the persistent, authoritative record of the project's current development state — current phase, completed work, current task, next step, open decisions, and known issues. It exists specifically so the project does not depend on any single Claude Code conversation's context.

- Read `docs/development/project-status.md` before starting any significant development work.
- Update `docs/development/project-status.md` after completing any significant development phase.
- Conversation context is temporary. Never treat it as the project's permanent memory — a new session (or a compacted/summarized one) must be able to pick up the project correctly from the repository alone.
- The repository's documentation (`PRD.md`, `CLAUDE.md`, `docs/architecture/*`, `docs/database/*`, `docs/development/project-status.md`) and Git history are the persistent source of truth for what the project is and what state it's in — not anything said earlier in a chat.

## What This Is

A multi-tenant, multi-store e-commerce SaaS platform with an LLM-powered business analytics assistant, built as a portfolio project. Two goals drive every decision: (1) a working commerce platform (products, cart, checkout, Stripe payments, inventory, analytics), and (2) demonstrating production-grade patterns — multi-tenancy, RBAC, webhook idempotency, concurrency-safe inventory, and a tool-calling AI agent with enforced data isolation. Per the PRD's stated principle: "Build a real business workflow first, then use AI to make the workflow smarter" — the AI is not a ChatGPT wrapper bolted on top.

Prioritize a clean, understandable MVP over unnecessary complexity in every section below.

## Core Architecture

### Authentication

- Laravel Sanctum for authentication (not JWT, unless a specific future requirement emerges).
- React maintains authenticated user state on the frontend.
- All protected API requests require authentication.
- Authorization (what an authenticated user is allowed to do) is enforced through Laravel Policies / Gates, not ad-hoc checks scattered in controllers.

### Multi-Tenancy

Hierarchy:

```
Organization
  → Stores
      → Store-level resources (Products, Orders, Inventory, ...)
```

Organization-level resources (e.g. the organization itself, its users/roles) do not necessarily have a `store_id` — don't force one onto everything for consistency.

Do **not** implement isolation by copy-pasting `->where('organization_id', ...)->where('store_id', ...)` into every query by hand. Instead, define a consistent tenant context/scoping strategy — e.g. middleware that resolves and binds the current organization/store, combined with Eloquent global scopes and/or Policies that consult that bound context. The goal is that tenant isolation is structural (hard to forget), not a convention every query author has to remember.

Requirements:
- Strict organization-level data isolation; store-level authorization where applicable.
- Tenant context established consistently across the request lifecycle.
- Laravel Policies / authorization used for access checks, not inline conditionals.
- Queue jobs must explicitly carry and restore the tenant/store context they need — a queued job runs outside the request lifecycle, so it cannot rely on `auth()->user()` or request-bound context being present.
- AI tools must enforce the same authorization rules as the equivalent REST endpoints.
- Never allow cross-organization data access, under any code path.

### RBAC

Three roles, strictly nested in capability: **Organization Owner** (manages org, stores, users, roles, org-wide analytics/AI) ⊃ **Store Admin** (manages products/orders/customers for their store, analytics, AI) ⊃ **Staff** (view orders, limited store data only). Authorization must be enforced identically for regular API requests and AI tool invocations — there is no separate, looser path for AI-originated data access.

### Payment → Order → Inventory Flow

```
Checkout
  → Create Pending Order (status: pending / payment-required)
  → Create Stripe PaymentIntent
  → Customer completes payment
  → Stripe Webhook received
  → Mark Order as Paid
  → Queue post-payment operations:
       - Update Inventory
       - Update Analytics
       - Send Notifications
```

Key constraints:
- The order is created **before** payment, in a pending/payment-required state — it must not be created for the first time inside the webhook handler.
- The webhook handler's job is to transition an existing order to Paid and enqueue the follow-on work; it should stay fast, and all heavier side effects belong in queue jobs.
- Stripe webhooks can be delivered more than once for the same event. Webhook processing must be idempotent (e.g. record processed event IDs, or make the "mark as paid" transition itself idempotent) — never assume at-most-once delivery.
- Inventory decrements must use database transactions with appropriate row locking to prevent overselling and negative stock under concurrent purchases. This is a required correctness property, not a nice-to-have.

### AI Agent — Tool-Calling, Not Direct DB Access

```
Admin/User
  → AI Agent
  → AI Tools
  → Authorized application services / queries
  → Data
  → AI Agent
  → Response
```

Initial AI tools:
- `getSales`
- `getOrders`
- `getProducts`
- `getCustomers`
- `getRefunds`
- `getInventory`
- `comparePeriods`

Hard rules:
- The LLM must **never** have direct database access — it only sees data returned by tool functions.
- AI tools must receive only authorized data, respect organization/store permissions, and reuse the application's existing authorization rules rather than reimplementing them.
- AI tools must validate their inputs and avoid exposing sensitive information beyond what the calling user is authorized to see.
- AI tools must never bypass tenant isolation, under any circumstance.
- **AI is an application feature, not an authorization layer.** It sits on top of the same auth/tenant rules as the rest of the app — it does not define or relax them.

Beyond single-question answering, the AI layer has three escalating capabilities described in the PRD: **Insights** (proactively surfaced problems/trends/warnings/recommendations), **Investigation** (admin flags a problem like "revenue decreased 12%" and the AI drills into sales-by-product, refunds, inventory, etc. to explain why), and **Reports** (generated weekly business summaries combining the above). All three are built on the same tool set — there is no separate data path for insights vs. Q&A vs. reports.

**Laravel AI SDK docs**: do not rely on any previously-referenced Laravel 11 AI SDK documentation URL — it's outdated for this project's Laravel 13 target. Before implementing AI SDK functionality, look up and verify the current official Laravel AI SDK documentation for installation, APIs, and conventions appropriate to Laravel 13. If the current official URL isn't already known with confidence, verify it (e.g. via web search) rather than inventing or guessing one.

### Redis Usage

Three distinct roles, not just caching: analytics query caching (cache-aside pattern: check Redis → miss → MySQL → populate cache), Laravel queue backend, and rate limiting.

### Frontend State Management

- React Query owns server state (data fetched from the API) — this covers the large majority of state needs in this app.
- Do not introduce Redux or Zustand by default. Add a client-side global state solution only when there's a clear, specific need React Query + local component state can't cover.
- If/when global client state becomes necessary, a `store/` directory should be added at that point — not scaffolded preemptively.

## API Surface (Planned)

`/api/auth`, `/api/products`, `/api/categories`, `/api/cart`, `/api/checkout`, `/api/orders`, `/api/customers`, `/api/inventory`, `/api/analytics`, `/api/ai`, `/api/reports` — all requiring auth, authorization, and tenant-scoping as described above.

## Commands (once scaffolded)

Correct forms to use — do not assume other variants exist:

- Seed the database: `php artisan db:seed`, or `php artisan migrate --seed` (not `php artisan seed`, which doesn't exist).
- PHP code style: `./vendor/bin/pint` (Laravel Pint). There is no `php artisan lint` command — don't assume one.
- Standard Laravel/Vite commands otherwise apply (`php artisan migrate`, `php artisan queue:work`, `php artisan test`, `npm run dev`, `npm run build`) once the projects exist — verify exact scripts against `composer.json`/`package.json` rather than assuming.

## Development Rules

- Read `PRD.md` before implementing features.
- Read relevant architecture documentation (including this file) before making architectural decisions.
- Do not implement multiple unrelated features at once.
- Work in small, verifiable milestones.
- Before implementing a major feature, explain the proposed approach first.
- Identify the files that will be modified before making significant changes.
- Implement the smallest reasonable change that satisfies the requirement.
- Run relevant tests after implementation.
- Report what changed and the test results.
- Do not introduce new dependencies without justification.
- Prefer Laravel conventions and built-in features over custom abstractions.
- Avoid unnecessary abstractions and over-engineering.
- Keep business logic testable.
- Do not modify unrelated files.
- Never expose secrets or API keys.
- Never commit `.env` files, credentials, or secrets.

## Code Quality

- Follow Laravel conventions; use Eloquent where appropriate.
- Form Requests for validation.
- Policies / Gates for authorization.
- Services only when business logic becomes sufficiently complex — avoid creating Service classes for simple CRUD.
- Jobs for asynchronous/long-running operations.
- Events/listeners only where they provide clear value.
- Database transactions for critical multi-step operations (payment, inventory).
- Laravel Pint for PHP formatting.

## Testing Strategy

Practical coverage, not exhaustive coverage — don't require excessive test coverage for trivial code.

**Backend** (Pest/PHPUnit): authentication, authorization, multi-tenant isolation, product CRUD, order lifecycle, payment webhook idempotency, inventory concurrency, AI tool authorization (tests should assert AI tools reject cross-tenant queries, not just that regular endpoints do), and other important business logic.

**Frontend** (Playwright for E2E): critical user flows — authentication, checkout, order management, admin dashboard.

## Tech Stack

**Backend**: PHP 8.5, Laravel 13, Laravel Sanctum, MySQL 8, Redis, Laravel Queue, Laravel AI SDK (verify current docs before use — see AI Agent section above).

**Frontend**: React 19, TypeScript, Vite, React Query, Tailwind CSS.

**External services**: Stripe, OpenAI.

**Infrastructure**: Docker / Docker Compose, GitHub Actions, Laravel Cloud (initial deployment).

Do not introduce additional infrastructure unless there's a clear requirement.

## MVP Definition of Done

The MVP is complete when this full path works end-to-end: register → create org → create store → admin creates product → customer browses/carts/checks out → pending order created → Stripe test payment → webhook processed idempotently → order marked paid → inventory/analytics updated via queue → admin views order and analytics → admin asks the AI a business question → agent selects tools → tenant-authorized data returned → AI produces an answer, an insight, and a report — and a second organization cannot see any of it. See `PRD.md` §31 for the authoritative list.

## Project Philosophy

This is both (1) a functional AI-powered commerce application and (2) a portfolio project demonstrating senior full-stack engineering skills: Laravel, React, TypeScript, REST APIs, authentication, RBAC, multi-tenancy, MySQL, Redis, queues, Stripe, webhooks, idempotency, concurrency, AI/LLM integration, AI agents and tools, testing, Docker, and CI/CD.

Prioritize a clean, understandable MVP over unnecessary complexity.

## Git & GitHub Rules

### General Rules

- Git is used to track all project changes.
- `main` is the stable branch.
- Never rewrite Git history.
- Never use `git reset --hard` unless explicitly instructed.
- Never use `git push --force` or `git push --force-with-lease`.
- Never delete or overwrite commits.
- Never modify files unrelated to the current task.

### Commit Rules

- Do NOT create commits automatically.
- Do NOT commit unless the user explicitly asks you to commit.
- The user is responsible for deciding when changes should be committed.
- Before a commit, always review:
  - `git status`
  - `git diff`
  - relevant tests
- Never create meaningless commits such as:
  - `update`
  - `changes`
  - `fix`
  - `test`
  - `final`
- Commit messages must use Conventional Commits.

### Commit Message Format

Use:

`<type>: <short description>`

Allowed types:

- `feat:` new functionality
- `fix:` bug fix
- `refactor:` code restructuring without behavior change
- `test:` tests
- `docs:` documentation
- `chore:` maintenance/configuration
- `perf:` performance improvement
- `build:` build/dependency changes
- `ci:` CI/CD changes

Examples:

- `docs: finalize architecture documentation`
- `feat: add initial database schema`
- `feat: add tenant models`
- `fix: prevent duplicate payment processing`
- `test: add payment webhook tests`
- `refactor: extract inventory transaction service`

### Commit Scope

Prefer small, logical commits.

A commit should represent one coherent change.

Good:

- `docs: finalize architecture documentation`
- `feat: add database migrations`
- `feat: add tenant authorization`
- `test: add inventory service tests`

Avoid combining unrelated changes into one commit.

### Before Commit

If the user explicitly asks Claude to commit:

1. Run `git status`.
2. Review the complete `git diff`.
3. Check for unintended changes.
4. Run the relevant tests.
5. Confirm no secrets or sensitive files are included.
6. Stage only the files related to the current task.
7. Create a Conventional Commit.
8. Show the user the commit hash and summary.

Never blindly run:

`git add .`

Prefer explicitly staging the intended files.

### Push Rules

- Do NOT push automatically.
- Do NOT push to `main` unless the user explicitly asks.
- Do NOT force push.
- The user decides when changes are pushed to GitHub.
- Before pushing, verify the current branch and remote.

### Branch Rules

- `main` is the stable branch.
- Feature work should normally use a separate branch.
- Recommended branch naming:

`feature/<short-description>`
`fix/<short-description>`
`refactor/<short-description>`
`docs/<short-description>`

Examples:

- `feature/database-schema`
- `feature/stripe-webhooks`
- `fix/inventory-race-condition`
- `docs/architecture`

Do not create unnecessary branches for trivial documentation changes unless requested.

### Pull Request Rules

When working through a feature branch:

1. Complete implementation.
2. Run tests.
3. Review `git diff`.
4. Commit only after explicit user approval.
5. Push only after explicit user approval.
6. If requested, prepare a Pull Request description containing:
   - Summary
   - Changes
   - Tests
   - Important architectural decisions
   - Known limitations

### Sensitive Files

Never commit:

- `.env`
- `.env.*`
- API keys
- passwords
- access tokens
- private keys
- credentials
- production secrets
- local database files
- IDE-specific personal configuration

`.env.example` may be committed when it contains placeholders only.

### Gitignore

Before committing, verify that sensitive and generated files are covered by `.gitignore`.

Never modify `.gitignore` merely to make an unwanted file disappear from `git status`.

If a generated or sensitive file appears in Git tracking, investigate why before changing Git configuration.

### Generated Files

Do not commit:

- dependencies
- build artifacts
- logs
- caches
- temporary files
- local environment files

unless the project explicitly requires them to be version controlled.

### After Every Task

Unless the user explicitly requests a commit:

- Do not commit.
- Do not push.
- Report:
  - files created
  - files modified
  - tests run
  - `git status`
  - any remaining concerns

The user will decide whether the changes should be committed.