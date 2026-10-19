# ADR 003: Stripe Connect with destination charges, webhooks applied once

## Context

The platform takes payments for many stores. The money belongs to each
store; the platform keeps a fee. Payments must never be faked, applied
twice or lost, including delayed methods (boleto) and money that arrives
after an order expired.

## Decision

- Each store connects a Stripe Express account (owners only). Checkout is a
  Stripe Checkout session created on the platform as a destination charge
  (`transfer_data.destination`) with an `application_fee_amount`
  (`PLATFORM_FEE_BPS`, 5% by default).
- Sessions last 31 minutes (Stripe's minimum is 30) and the order's stock
  hold is extended past the session's end, so a customer paying at the last
  minute still has their stock.
- Only a signed webhook marks an order paid. Events are verified, stored by
  Stripe event ID (redeliveries are acknowledged and skipped) and applied by
  a queued job. The handler locks the event row and marks it processed in the
  same transaction as its effects, so a retried job also applies it once.
- The paid amount must equal the order total. A wrong amount, a payment for
  an order already expired or cancelled, or a second payment for a paid
  order is marked `needs_refund` and logged, never silently accepted.
- A scheduled task re-queues stored events whose job never ran.
- `PaymentGateway` hides Stripe: tests use a fake and sign webhooks locally
  with the same verification code; `StripeGateway`'s requests are checked
  through stripe-php with an in-memory HTTP client.

## Consequences

- No card data touches the platform; stores see payouts in their own
  Stripe dashboard.
- Refunds are manual (Stripe dashboard), guided by the flagged payments.
- Running payments locally needs test-mode keys and the Stripe CLI
  (`docker compose --profile stripe`); without keys, checkout answers 503 and
  everything else works.
