# ADR 002: Stock by lot, first-expiring first

## Context

Cosmetics expire. Selling a sunscreen that expires next week produces
complaints and returns, and ANVISA or a manufacturer can recall a specific
production lot. A single "quantity" per product answers neither "which
unit ships next?" nor "who received lot X?".

## Decision

- Stock lives in `stock_lots`: one row per manufacturer lot of a variant,
  with its expiry date, units on hand and units reserved
  (`CHECK (quantity_reserved <= quantity_on_hand)`).
- Each store sets a minimum shelf life (30 days by default). Lots expiring
  within it are not sellable; they still show in the stock report.
- Availability is a view, `variant_stock`, computed from sellable lots. A
  kit has no lots: it is available as often as its scarcest component
  allows and ships with the earliest component expiry.
- Reservation (`StockAllocator`) takes from the earliest-expiring sellable
  lot first (FEFO). It locks every candidate lot in ID order, so concurrent
  checkouts queue instead of deadlocking, and computes the whole allocation
  before writing, so an order reserves everything or nothing.
- `order_allocations` records which lot each unit came from. Release
  (cancel, expiry) and sale (payment) replay exactly those rows, and a lot
  recall lists every paid order that received it.
- Every stock change goes to an append-only ledger, `stock_movements`.

## Consequences

- The storefront can show the expiry month of the unit that would ship,
  without exposing stock counts.
- Concurrency is proven with real processes: 8 customers racing for 3 units
  end with exactly 3 orders. Without the row locks the `CHECK` constraint is
  the backstop, and the test sees errors instead of clean 409s.
- Returns do not restock automatically; staff adjust the lot, with a reason
  in the ledger.
