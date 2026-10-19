# ADR 001: One database, isolated by PostgreSQL row-level security

## Context

Beauty Hub hosts many stores. Each store's catalog, customers, carts,
orders, coupons and stock must be invisible to every other store. A
mistake here is not a bug report, it is a data leak.

Options considered:

1. **A database (or schema) per store**, e.g. with `stancl/tenancy`.
   Strong physical separation, but every migration runs N times,
   connections multiply, and cross-store work (scheduled jobs, platform
   reports) means switching connections.
2. **One database, a `tenant_id` column and an Eloquent global scope.**
   Simple, but isolation depends on every query going through Eloquent and
   nobody forgetting the scope: raw SQL, the query builder, a `withoutGlobalScopes()`
   or a validation rule (`exists:`) all bypass it.
3. **One database, `tenant_id`, and PostgreSQL row-level security (RLS)**
   underneath the Eloquent scope.

## Decision

Option 3, implemented in the app (no tenancy package), with these rules:

- The store comes from the subdomain (`{store}.beautyhub.example`) and is
  copied into the PostgreSQL session as `app.tenant_id` (`set_config`), by
  `App\Tenancy\TenantContext`. It is re-applied whenever a connection is
  re-established.
- Every tenant-scoped table gets `ENABLE` + `FORCE ROW LEVEL SECURITY` and a
  policy `tenant_id = current_tenant_id()`, for reads and writes
  (`TenantIsolation::enable()` in migrations). No tenant set means no rows.
- The application connects as `beauty_app`, a role that owns no table and has
  no `BYPASSRLS`. Migrations run as the owner role (`--database=owner`).
- References between tenant tables are composite foreign keys,
  `(tenant_id, x_id) → x (tenant_id, id)`: PostgreSQL checks foreign keys
  without RLS, so a plain key could point at another store's row.
- Views are created `WITH (security_invoker = true)`; a plain view runs as
  its owner and would bypass RLS.
- Queued jobs carry the store in Laravel's Context and run inside it; the
  worker sets or clears the store before every job, because it reuses its
  database connection.
- Architecture tests fail if a table with `tenant_id` lacks the forced
  policy, if a model on such a table lacks `BelongsToTenant`, if a view is
  not `security_invoker`, or if the app role could bypass RLS.

## Consequences

- Isolation holds even for raw SQL and validation rules: `exists:brands,id`
  only sees the current store's brands, and a token issued by one store is
  not found at another.
- Platform-wide work must loop over stores (`App\Tenancy\EachStore`).
  Framework commands that assume global visibility silently do nothing:
  `sanctum:prune-expired` deletes zero tokens, which is why `tokens:prune`
  exists.
- Tables written by framework code that knows nothing about tenants
  (`password_reset_tokens`, `kit_components` via `belongsToMany`) default
  `tenant_id` to `current_tenant_id()`.
- A person who works at two stores has two accounts.
