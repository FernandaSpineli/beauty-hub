Multi-store beauty e-commerce. Every store has its own subdomain, and the
API of a store is served there: `https://{store}.beautyhub.example/api/v1/...`.
Accounts, tokens, carts and orders exist only at the store where they were
created; the same email can have separate accounts at different stores.

**Authentication.** Send `Authorization: Bearer <token>`, with a token from
`POST /v1/auth/login` or `/v1/auth/register`. A token works only at the store
that issued it.

**Errors** always use the same envelope, with a stable `code` to branch on:
`{"error": {"code": "INSUFFICIENT_STOCK", "message": "...", "details": [...], "request_id": "..."}}`.

**Money** is in centavos (BRL). **Idempotency:** `POST /v1/orders` accepts an
`Idempotency-Key` header; retrying with the same key returns the same order.
