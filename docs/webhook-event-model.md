# Webhook event model — findings, proposal & references

**Status:** implemented (2026-07-03)
**Date:** 2026-07-03
**Scope:** `woocommerce-maya-gateway` — how Maya webhook events map to WooCommerce order state.
**Context:** This plugin is a rewrite of the unmaintained `paymaya-checkout-for-woocommerce`
("cynder") plugin. A parity code review plus cross-referencing the Maya, PayMongo, and
Stripe webhook docs surfaced that the rewrite reacts to **deprecated checkout-level
failure events** in a way that can prematurely fail in-flight orders.

---

## 1. Findings

### Finding 1 (primary) — the rewrite fails orders on `CHECKOUT_FAILURE`; the reference plugin did not

- **Where:** `src/Value/WebhookEvent.php` — `is_terminal_failure()` returns `true` for
  `CheckoutFailure` and `CheckoutDropout`; `src/Webhook/Registrar.php` — `MANAGED_EVENTS`
  registers `CHECKOUT_SUCCESS` / `CHECKOUT_FAILURE`.
- **Behavior:** a `CHECKOUT_FAILURE` webhook → `EventDispatcher` calls
  `update_status('failed')`, firing WooCommerce's failed-order email and stock restoration.
- **Legacy behavior:** the cynder plugin routed `CHECKOUT_*` to a **no-op passthrough**
  (`handle_webhook_request`) and only failed orders on `PAYMENT_FAILED` / `PAYMENT_EXPIRED`
  / `AUTH_FAILED`.
- **Failure scenario:** a customer's card is declined on Maya's hosted page → `CHECKOUT_FAILURE`
  → order marked `failed` (customer emailed, stock restored) → customer retries and pays →
  `PAYMENT_SUCCESS` completes it. The order churned through a failed state and the customer
  got a spurious "payment failed" email. ("Paid is a floor" prevents the reverse — a stale
  failure can't un-pay a paid order — but does not prevent the premature failure above.)
- **Verdict:** CONFIRMED behavioral divergence. `CHECKOUT_*` are **deprecated** by Maya (see refs).

### Finding 2 — `PAYMENT_CANCELLED` is not subscribed

- **Where:** `src/Webhook/Registrar.php` — `MANAGED_EVENTS`.
- Maya lists `PAYMENT_CANCELLED` as a current event ("a payment is stopped or reversed by the
  customer or merchant" → "Cancel the order"). Neither the rewrite nor the legacy plugin
  registers it, so a cancelled payment currently delivers no signal. `is_terminal_failure()`
  already classifies `PaymentCancelled`, but it is never delivered because it isn't registered.

### Finding 3 — per-unit item amount can fail to reconcile with the line total

- **Where:** `src/Gateway/PaymentProcessor.php:266` — `amount.value = round(line_total / quantity, 2)`.
- A ₱100.00 line at quantity 3 → `amount = 33.33`, `totalAmount = 100.00`, but `33.33 × 3 = 99.99 ≠ 100.00`.
  Legacy sent the line total for **both** fields (internally inconsistent but accepted by Maya).
- **Verdict:** PLAUSIBLE (low likelihood — Maya tolerated the worse legacy inconsistency; top-level
  `totalAmount` stays authoritative). Surface only if Maya ever rejects an uneven-division cart.

### Finding 4 — re-payment overwrites the previous checkout id

- **Where:** `src/Gateway/PaymentProcessor.php:85` — `update_meta_data(META_CHECKOUT_ID, …)`.
- On a retry the prior Maya checkout id is lost; legacy preserved it as `_checkout_id_old`.
  Traceability/debugging only; not a functional break.

### Open item — `AUTHORIZED` registration for manual capture

- `AUTHORIZED` is a current Maya event required for the manual-capture flow, and it is **not** in
  `MANAGED_EVENTS`. In live testing it only existed because the Maya *application* had a webhook
  URL configured. Confirm manual-capture orders actually receive `AUTHORIZED` before relying on it.

### Improvements over the legacy plugin (for the record)

- **Signature check is safer:** rewrite verifies `1 === openssl_verify(...)`; the legacy
  `array_some` treated `openssl_verify`'s `-1` **error** return as valid.
- **Amount check is robust:** legacy used `PHP_FLOAT_EPSILON` (~2e-16) tolerance — a one-cent
  float difference would silently fail to complete a legit payment. Rewrite uses a 0.005
  tolerance **and** adds a currency check legacy lacked.
- **Richer checkout payload:** full buyer/billing/shipping (shipping falls back to billing) plus
  `buyer.birthday` for fraud/3DS; product id used for item `code` instead of hard-coded `'001'`.
- Monotonic "paid is a floor", per-order webhook de-dup ledger, filterable keys/IPs/timestamp,
  and PII/card log redaction are all net-new hardening.

---

## 2. Why there is no "checkout-level failure" safeguard (design principle)

A checkout session is a **retry surface, not a terminal outcome.** One session can absorb many
failed attempts before one succeeds. So "the checkout failed" is not a meaningful terminal
state — the buyer can keep trying. Only three signals are genuinely terminal:

1. **Success** → fulfill the order.
2. **Expiry** → the retry window closed with no success → *genuinely* abandoned. **This is the
   real safeguard** — it is time-based (session ran out), not attempt-based (one card bounced).
3. **Explicit cancellation** → cancel the order.

Reacting to a per-attempt failure is premature: you would email "payment failed" and restock
while the customer is still entering a second card. The abandonment safeguard is the **expiry**
event (`PAYMENT_EXPIRED`), never a checkout-failure event.

### Industry reference comparison

| Gateway | Fulfill on | Abandonment safeguard | Checkout-level "failed" event? |
| --- | --- | --- | --- |
| **Stripe** | `checkout.session.completed` | `checkout.session.expired` (default 24h) | ❌ none (only narrow `async_payment_failed` for delayed methods) |
| **PayMongo** | `checkout_session.payment.paid` | session status → `expired` (no webhook) | ❌ none |
| **Maya (current)** | `PAYMENT_SUCCESS` | `PAYMENT_EXPIRED` | ❌ `CHECKOUT_*` **deprecated** |
| **This plugin (today)** | `PAYMENT_SUCCESS` ✅ | `PAYMENT_EXPIRED` ✅ | ⚠️ **fires `failed` on `CHECKOUT_FAILURE`** |

All three gateways drive order state from **payment-level** events and have no generic
checkout-failure signal. Stripe — the reference implementation the industry copies — has never
had a `checkout.session.failed`; a declined card simply retries in-session.

---

## 3. Implemented solution

Order state is now driven purely by `PAYMENT_*` events; the deprecated `CHECKOUT_*` family is
no longer acted on and is actively cleaned up.

1. **`src/Value/WebhookEvent.php` — `is_terminal_failure()`**: removed `CheckoutFailure` and
   `CheckoutDropout`. Terminal set: `PaymentFailed`, `PaymentExpired`, `PaymentCancelled`,
   `AuthFailed`. A stray `CHECKOUT_*` now falls through to the dispatcher's no-op `ignored` branch.
2. **`src/Webhook/EventDispatcher.php` — `mark_failed()`**: **all** failure-family events map to
   WooCommerce **`failed`** (a *retryable* status), including `PAYMENT_CANCELLED`. We deliberately
   did **not** map to WooCommerce `cancelled`: `cancelled` restores stock and is not a payable
   status, so it would block the customer's retry — the opposite of the intent. The event only
   changes the order note.
3. **`src/Webhook/Registrar.php`**: `MANAGED_EVENTS` = `PAYMENT_SUCCESS`, `PAYMENT_FAILED`,
   `PAYMENT_EXPIRED`, `PAYMENT_CANCELLED`. Added `DEPRECATED_EVENT_NAMES`
   (`CHECKOUT_SUCCESS/FAILURE/DROPOUT/CANCELLED`); `reconcile()` now **deletes** any of those it
   finds on the merchant's Maya account (but never recreates them), so the migration actually
   removes existing deprecated subscriptions instead of orphaning them. (`AUTHORIZED` remains a
   documented open item, not in scope.)
4. **`PAYMENT_EXPIRED` is the abandonment safeguard** — the load-bearing terminal signal; kept.
5. **Simulator:** `PAYMENT_CANCELLED` added to `Simulator::ALLOWED_STATUSES` and the admin
   "Simulate webhook" dropdown so the path is one-click testable.
6. **Tests:** `WebhookEventTest` (terminal set), `EventDispatcherTest` (all-failure-family → failed;
   regression that `CHECKOUT_FAILURE`/`CHECKOUT_DROPOUT` no longer fail an order), `RegistrarTest`
   (managed set + deprecated-cleanup delete).

### Why "all failure-family → failed" (not per-Maya-intent statuses)

`PAYMENT_FAILED`, `PAYMENT_EXPIRED`, and `PAYMENT_CANCELLED` all mean the same thing to a hosted
gateway: no money moved, order still open for retry. WooCommerce `failed` is retryable and is the
conventional mapping; distinguishing `cancelled` would only remove that retryability and restore
stock. The purest model (only `PAYMENT_SUCCESS` changes status; everything else is a note) was
considered and deferred as a larger behavior change.

### Why "paid is a floor" remains correct

Stripe's `async_payment_failed` is the one case where a provisionally-"paid" order legitimately
reverses — and it exists only for *delayed* payment methods that settle later and bounce. Maya's
standard flows have no such provisional window: a `PAYMENT_SUCCESS` means settled, and genuine
reversals arrive as **refund** events on the separate `process_refund()` path. So monotonic
"paid is a floor" is safe here.

---

## 4. References

### Maya — Webhooks (developers.maya.ph/docs/webhooks)

- `CHECKOUT_SUCCESS`, `CHECKOUT_FAILED`, `CHECKOUT_DROPOUT`, `CHECKOUT_CANCELLED` are **deprecated**;
  advisory: *"Subscribe to the corresponding `PAYMENT_*` events as soon as possible."*
- `PAYMENT_SUCCESS` — *"a payment transaction is successfully completed"* → *"Fulfill the order, or send confirmation emails."*
- `PAYMENT_FAILED` — *"a payment attempt fails (e.g., insufficient funds, closed account, suspected fraud)"* → *"Notify the customer, allow retry…"*
- `PAYMENT_EXPIRED` — *"a transaction is not completed within the allowed time window (e.g., abandoned checkout, unfinished authentication)"* → *"Mark the order as abandoned, release reserved stock…"*
- `PAYMENT_CANCELLED` — *"a payment is stopped or reversed by the customer or merchant"* → *"Cancel the order…"*
- `AUTHORIZED` (cards) — *"an authorization hold is successfully placed on the customer's account."*

### PayMongo — Webhook events (docs.paymongo.com/docs/developer-tools-webhooks-events)

- `payment.paid` — *"Triggered when a payment is successfully completed."*
- `payment.failed` — *"Triggered when a payment fails."*
- Checkout Session status (docs.paymongo.com/reference/checkout-session-resource): *"Possible values are `active` and `expired`."*
- No `checkout_session.failed` / no checkout-level failure event; success is signalled by `checkout_session.payment.paid`.

### Stripe — Event types (docs.stripe.com/api/events/types)

- `checkout.session.completed` — *"Occurs when a Checkout Session has been successfully completed."*
- `checkout.session.expired` — *"Occurs when a Checkout Session is expired."*
- `checkout.session.async_payment_succeeded` — *"a payment intent using a delayed payment method finally succeeds."*
- `checkout.session.async_payment_failed` — *"a payment intent using a delayed payment method fails."* (delayed methods only — not card declines)
- `payment_intent.payment_failed` — *"Occurs when a PaymentIntent has failed the attempt to create a payment method or a payment."*
- **No `checkout.session.failed` event exists.**

### Legacy plugin (reference)

- `paymaya-checkout-for-woocommerce/classes/cynder-paymaya.php` — `handle_payment_webhook_request()`
  acts only on `PAYMENT_FAILED` / `PAYMENT_EXPIRED` / `AUTH_FAILED`; `handle_webhook_request()`
  (the `CHECKOUT_*` target) is a 200 passthrough.

*Docs gathered 2026-07-03 via web fetch; the Maya HackMD the legacy plugin linked is now
unavailable (403), but Maya's, PayMongo's, and Stripe's live docs independently agree on the
payment-level model.*
