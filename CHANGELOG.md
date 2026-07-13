# Changelog

All notable changes to **WooCommerce Maya Gateway** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
the project loosely follows semantic versioning.

## [1.1.0] — 2026-07-13

The plugin is now installable as a Composer dependency, and the project
has moved to the RogueDex org.

### Fixed

- **Fatal error when installed by Composer.** The main plugin file
  required its own `vendor/autoload.php` unconditionally. A Composer
  install has no `vendor/` inside the plugin directory — the classes are
  autoloaded from the project root instead — so loading the plugin took
  the site down. The require is now conditional and the boot is gated on
  the classes actually being resolvable, so one codebase serves both a
  Composer install and the standalone zip (which does bundle `vendor/`).
- **Stale admin and block assets after an upgrade.** The enqueued asset
  version was a hardcoded `1.0.0`, so browsers kept serving cached CSS
  and JS across releases. Both call sites now read `WC_MAYA_VERSION`.

### Changed

- **Org rename to RogueDex.** Composer package
  `roguetech-philippines/woocommerce-maya-gateway` →
  `roguedex-labs/woocommerce-maya-gateway`; PHP namespace
  `RogueTechPhilippines\MayaGateway` → `RogueDex\MayaGateway`.

  **No settings or order data are affected.** The gateway id
  (`maya_checkout`), its settings option, the `_maya_*` order meta, the
  `wc_maya_*` hooks, the `wc-maya/v1` webhook route and the
  `wc-maya-gateway` text domain are all unchanged — they name the
  product, not the org. Existing orders, saved credentials and the
  webhook already registered with Maya keep working across the upgrade.

  Consumers of the PHP API (anyone type-hinting or extending these
  classes) must update their imports. Nobody hooking the documented
  filters and actions needs to change anything.
- `Update URI: false` so wordpress.org cannot serve updates for a
  colliding plugin slug now that Composer manages this plugin.
- Corrected the deprecated `GPL-3.0` SPDX id to `GPL-3.0-or-later`.
- The release zip no longer carries dev files, and builds from
  `composer.lock` so a given tag always produces the same bytes.

## [1.0.0] — 2026-05-26

First production release. Rebuilt from the ground up against the legacy
`wc-maya-payment-gateway` plugin; same Maya contracts (signature algorithm,
RSA public keys, IP allowlist, idempotency-key shape), modern architecture.
See [docs/REBUILD_PLAN.md](docs/REBUILD_PLAN.md) and the per-phase tours
under [docs/rebuild-overview/](docs/rebuild-overview/) for the full story.

### Added

- **Hosted-checkout flow.** Customer redirects to a Maya-hosted payment
  page; signed `PAYMENT_SUCCESS` webhook is the authoritative state change.
- **Manual capture** (authorize → capture later). Modes: `none`,
  `normal`, `final`, `preauthorization`. Capture panel + button on the
  order edit screen (HPOS-compatible).
- **Refund + void** with smart-pick: full void when Maya still permits
  it, otherwise refund. Multi-capture orders split a partial refund
  across captures chronologically (port of the legacy plugin's
  `process_refund` algorithm, now exhaustively unit-tested).
- **Webhook reception** with RSA-SHA256 signature verification, ±300s
  timestamp tolerance, source-IP allowlist. REST endpoint
  (`/wp-json/wc-maya/v1/webhook`) primary, `wc-api=maya_webhook` shim
  for migrating merchants.
- **Webhook registration.** Saving gateway settings idempotently
  reconciles the managed event set in Maya Manager: delete the five
  managed events on this account → recreate them all. Unmanaged
  webhooks left alone.
- **WC Blocks (Cart & Checkout) integration.** Gateway appears in the
  block-based checkout with title/description/icon and a hosted-checkout
  redirect flow.
- **Audit log viewer.** "Maya events" tab under WooCommerce → Status →
  parses the `wc-maya-gateway` log channel into a filterable table
  (timestamp, level, message, decoded context).
- **Action Scheduler retry safety net.** Transient dispatch failures
  (order-not-found at webhook time, Maya lookup error in the manual-
  capture branch) are replayed up to four times with exponential
  backoff (1m / 4m / 16m / 64m).
- **Webhook simulator** (sandbox-only). Admin button dispatches a forged
  payload through the webhook pipeline in-process, so local development
  can exercise dispatch without a public tunnel or public bypass header.
- **Test connection** probe — creates a small sandbox checkout session
  and verifies the secret key can list webhooks, end-to-end, before the
  merchant fires their first real order.
- **HPOS + cart_checkout_blocks compatibility** declared in the main
  plugin file.
- **i18n.** Bundled `languages/wc-maya-gateway.pot` generated from 139
  strings extracted from `src/` and `templates/`.

### Testing

- 200+ Pest unit tests, ~600 assertions. Brain Monkey for WP functions,
  Mockery for `WC_Order` / `WC_Logger` / `WP_REST_Request`.
- Pure-static planners and parsers (
  `PaymentProcessor::build_payload`, `RefundProcessor::plan_capture_actions`,
  `RefundProcessor::remaining_refundable`, `EventLogParser::parse_line`,
  `RetryQueue::should_schedule`, `RetryQueue::plan_delay`,
  `MayaBlocksPaymentMethod::build_payment_method_data`) — every key
  decision tree is exercised without booting WordPress.

### Compatibility

- PHP **8.3+** (uses `final readonly` classes, enums with helpers, match,
  constructor promotion).
- WordPress **7.0+**.
- WooCommerce **10.6+**, tested up to **10.7**.

### Migration from `wc-maya-payment-gateway`

Settings are stored under a new option key
(`woocommerce_maya_checkout_settings`); meta keys also diverged
(`_maya_*`). There is **no automatic migration** — deactivate the legacy
plugin and configure the new one. Existing webhook registrations in the
Maya Manager will be recreated by the new plugin's reconciler on first
settings save.
