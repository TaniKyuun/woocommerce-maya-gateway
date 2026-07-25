# Go-live & incident runbook — WooCommerce Maya Gateway

This is the operational companion to the code. The plugin can be technically
correct and still lose money if nobody watches it or knows what to do when a
webhook goes missing. Read this before taking real payments.

## Trust model (why this matters)

Maya is a **hosted** gateway: the customer pays on Maya's page, and the store is
told the result two ways — the customer's browser returning, and a separate
signed server-to-server **webhook**. **Only the signed webhook completes an
order.** The browser return never marks an order paid. Order state is
**monotonic: once paid, an order is never demoted** — a late or replayed
failure webhook is ignored (see `EventDispatcher::dispatch`).

## Webhook events

The plugin subscribes to **payment-level events only**: `PAYMENT_SUCCESS`, `PAYMENT_FAILED`,
`PAYMENT_EXPIRED`, `PAYMENT_CANCELLED`. `PAYMENT_SUCCESS` completes the order; the other three map
to WooCommerce `failed` (retryable). **Abandonment is handled by `PAYMENT_EXPIRED`, not any
checkout-level event.** The deprecated `CHECKOUT_*` family is not acted on and is deleted from the
merchant's Maya account on settings-save (see docs/webhook-event-model.md). After saving, "Refresh
from Maya" should show exactly those four `PAYMENT_*` rows and no `CHECKOUT_*`.

## Hard gates before ANY real-money checkout

The charge path (webhook-completes-order, forgeries rejected, paid-is-a-floor) is
live-validated. These reversal/infra gates are **not** yet proven and block production:

- [ ] **Reversal proven once.** Run a real **refund + void + manual capture** end-to-end
      against Maya (needs a Payments/Vault-scoped key). Never take real money you have
      not proven you can give back — the refund path has only ever been unit-tested.
- [ ] **Stable ingress.** Do NOT run production behind an ephemeral quick tunnel: if it
      drops, Maya webhooks vanish and orders sit `pending` (customer charged, no order) —
      silently. Use real hosting or a named/monitored tunnel with a **fixed** webhook URL
      registered in Maya.
- [ ] **Named on-call.** Decide who watches the `wc-maya-gateway` log for the first ~100
      live payments and who can refund manually, out of hours.

## Pre-flight checklist (before enabling for customers)

- [ ] **API key scopes:** confirm your Maya keys have BOTH the **Checkout** and the
      **Payments/Vault** scope. Checkout (create session + webhooks) works with the
      Checkout scope alone, but **refunds, voids, and manual capture** call the
      Payments API (`/payments/v1/...`) and will fail with `HTTP 401` / `K007
      "Invalid key scope"` if the key lacks Payments scope. A Checkout-only sandbox
      app exhibits exactly this — enable the Payments product / add a Vault-scoped
      key before relying on refunds.
- [ ] In a development checkout with development dependencies, run `ddev exec -d /var/www/html/web/app/plugins/woocommerce-maya-gateway composer test`.
- [ ] Sandbox keys entered; **Test mode ON**; place a full sandbox order and confirm the **webhook** (not the redirect) moves the order to *Processing/Completed*.
- [ ] Abandon a payment at Maya's page → order does **not** complete.
- [ ] Exercise: manual capture, partial capture, void, refund in sandbox; confirm each in both WooCommerce and the Maya dashboard.
- [ ] Break the webhook on purpose: wrong signature, expired timestamp (>5 min), off-allowlist IP → each is rejected and appears in **WooCommerce → Status → Logs → wc-maya-gateway**.
- [ ] Replay a captured `PAYMENT_FAILED` after a success → order stays paid (monotonic-state check).
- [ ] Confirm **Action Scheduler is running** (WooCommerce → Status → Scheduled Actions): a stalled cron means the webhook-replay safety net never fires.
- [ ] Host clock is on **NTP** (the webhook timestamp check is ±5 min; large clock skew rejects every webhook — see the tolerance filter below only as a last resort).
- [ ] Switch to production keys; **Test mode OFF**; do **one** low-value real purchase with your own card, then refund it, watching the money move and return.

## Kill switch

Disable the gateway: **WooCommerce → Settings → Payments → Maya Checkout → toggle off.**
New orders can no longer select Maya. In-flight webhooks for existing orders
still process (idempotent + monotonic, so this is safe).

## Rollback

- **Config rollback:** flip Test mode back on / disable the gateway (above).
- **Version rollback:** Composer/Bedrock restores the prior deployed dependency-update commit and `composer.lock`; manual installs reinstall a previous *published* release asset. If no usable prior release exists, issue a corrective roll-forward release. Settings persist in `woocommerce_maya_checkout_settings`; order data is untouched.

## Incident: "money left my account but I have no order"

This is the failure mode to rehearse. Cause is usually a webhook that was
delayed, dropped, or rejected.

1. Find the order (by customer email / amount / time) in **WooCommerce → Orders**.
2. Open the order and read the **Maya event history** (per-order webhook log,
   meta `_maya_webhook_log`) plus **Status → Logs → wc-maya-gateway** — did a
   webhook arrive? Was it rejected (signature/timestamp/IP), or never seen?
3. Cross-check the **Maya Manager dashboard** for a matching successful payment
   by reference number (the WC order id) — this is the source of truth that the
   charge is real.
4. If the charge is real but the order didn't complete: either let Maya's retry
   / the plugin's Action Scheduler replay land, use **Simulate webhook** (sandbox
   only) to reproduce, or complete the order manually and reconcile.
5. If the charge should not stand: issue a refund (below).

**Who may refund:** refunds go through WooCommerce's native order **Refund**
button, which requires the `manage_woocommerce` capability and a nonce — so only
shop managers/admins can trigger one, and every refund is recorded as an order
note. There is no other refund path in this plugin. Refund amounts are validated
against Maya (partial voids are rejected; the planner can never refund more than
was captured), and over-refunds are additionally capped by WooCommerce and
rejected by Maya server-side.

## Operational filters (no release needed)

- **Maya changed its signing key:** add the new PEM via `wc_maya_webhook_public_keys`.
- **Maya changed its egress IPs:** patch via `wc_maya_webhook_allowed_ips`
  (returning `[]` disables the IP check entirely — signature verification still
  applies). This is code-level on purpose; it is not an admin toggle.
- **Persistent clock skew you can't fix via NTP:** widen the freshness window via
  `wc_maya_webhook_timestamp_tolerance_ms` (cannot be narrowed below the 5-min
  default). Prefer fixing the clock.
- **React to a confirmed payment:** hook the `wc_maya_payment_confirmed` action
  (`$order_id, $payload`).

## Logs & PII

Card data and customer PII are redacted from logs (`Logger::REDACT_KEYS`).
**Logs written before upgrading to the version that added webhook-payload
redaction may still contain PII** — rotate/purge old `wc-maya-gateway-*.log`
files before sharing them.

## Known follow-ups (deferred, not blockers)

- **Settlement reconciliation view:** the per-order webhook snapshot (`_maya_webhook_log`)
  already captures the data; a "WC paid vs Maya settlement" report is not yet built.
- **Automated/remote key rotation:** keys are bundled + filterable; there is no
  auto-fetch from Maya yet.
- **Simultaneous duplicate-delivery side-effects:** monotonic state prevents double
  payment; a truly concurrent double-delivery could still produce a duplicate order
  note (never a double charge). A DB-unique-constraint claim is the future fix.
- **Multi-capture partial-refund is non-atomic.** When a refund must span several
  captured payments and one action fails mid-sequence, earlier actions (e.g. a void)
  have already moved money on Maya while WooCommerce marks the refund *failed* (no
  `WC_Order_Refund` created). It is surfaced loudly (`wc_maya_refund_partial_failure`
  + per-action order notes), not silent — but the operator must reconcile against the
  Maya dashboard. Single-capture and full refunds are unaffected.
