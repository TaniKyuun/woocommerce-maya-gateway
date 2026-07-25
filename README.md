# WooCommerce Maya Gateway

Production-grade WooCommerce payment gateway for [Maya](https://maya.ph)
(Philippines). Hosted checkout, signed webhooks, manual capture,
void / refund, block-based and classic checkout, HPOS, translatable.

## Requirements

- PHP **8.3+**
- WordPress **7.0+**
- WooCommerce **10.6+** (tested up to 10.7)

## Features

- Hosted Maya Checkout flow — customer enters card / wallet on a
  Maya-hosted page, signed webhook drives the order state machine.
- **Manual capture** with four modes (`none` / `normal` / `final` /
  `preauthorization`); Capture button + panel on the order edit screen.
- **Smart refund / void**: full void when Maya still permits it,
  otherwise refund; partial refunds across multiple captures are split
  chronologically.
- **Webhook reception** with RSA-SHA256 signature verification, ±300s
  timestamp tolerance, source-IP allowlist. REST endpoint
  (`/wp-json/wc-maya/v1/webhook`) primary, `wc-api=maya_webhook` shim
  for compatibility.
- **Idempotent webhook registration** — saving the gateway settings
  reconciles the five managed events with Maya Manager; unmanaged
  webhooks on the same account are left alone.
- **Block-based Cart and Checkout** integration alongside the classic
  shortcode checkout.
- **"Maya events" admin log viewer** under WooCommerce → Status, with
  level + free-text filters.
- **Action Scheduler retry** safety net for transient dispatch failures
  (order DB lag, Maya lookup hiccups) with exponential backoff.
- **Local-dev webhook simulator** — admin button dispatches a forged
  sandbox payload through the webhook pipeline in-process, so local
  debugging does not need a public tunnel or verification bypass header.
- **Test connection** probe — verifies both API keys end-to-end against
  Maya's sandbox before the first real order.
- **HPOS** (`custom_order_tables`) and `cart_checkout_blocks`
  compatibility declared.
- Translatable: bundled `languages/wc-maya-gateway.pot` covering 139
  strings.

## Installation

### With Composer (Bedrock, and any Composer-managed site)

Add the repository, require and allow `composer/installers`, and map its
`wordpress-plugin` installs in the **site root** `composer.json`:

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/roguedex-labs/woocommerce-maya-gateway" }
  ],
  "require": {
    "composer/installers": "^2.3",
    "roguedex-labs/woocommerce-maya-gateway": "^1.1"
  },
  "config": { "allow-plugins": { "composer/installers": true } },
  "extra": {
    "installer-paths": {
      "web/app/plugins/{$name}/": [ "type:wordpress-plugin" ]
    }
  }
}
```

Bedrock uses `web/app/plugins/{$name}/`; other Composer sites need an
equivalent site-root mapping. Then run:

```bash
composer update composer/installers roguedex-labs/woocommerce-maya-gateway
```

### From a release zip (sites not using Composer)

1. Download `wc-maya-gateway-<version>.zip` from the
   [releases page](https://github.com/roguedex-labs/woocommerce-maya-gateway/releases).
2. WordPress admin → Plugins → Add New → Upload Plugin → choose the zip.
3. Activate.

The zip is self-contained — no `vendor/`, nothing to install.

### Either way, then configure

WooCommerce → Settings → Payments → **Maya Checkout** → enter your sandbox or
production keys, click _Test connection_, then _Save changes_. Saving (with both
keys present and the gateway enabled) automatically registers the five managed
webhooks in your Maya Manager account.

### From source (developers)

```bash
git clone https://github.com/roguedex-labs/woocommerce-maya-gateway.git \
    wp-content/plugins/woocommerce-maya-gateway
cd wp-content/plugins/woocommerce-maya-gateway
composer install   # dev tooling only: pest, phpcs, php-cs-fixer
```

Then activate in WP admin as above.

## Configuration

| Setting                        | What it does                                                                                                            |
| ------------------------------ | ----------------------------------------------------------------------------------------------------------------------- |
| **Enable / Disable**           | Master switch for the gateway.                                                                                          |
| **Title / Description**        | Shown to the customer on classic + block checkout.                                                                      |
| **Sandbox mode**               | Toggles between Maya's sandbox (`pg-sandbox.paymaya.com`) and production (`pg.maya.ph`).                                |
| **Public key / Secret key**    | Checkout-product API keys from Maya Manager → Developers. Sandbox shared keys are documented at developers.maya.ph.     |
| **Test connection**            | Creates a small sandbox checkout + lists registered webhooks. Confirms both keys before merchant attempts a real order. |
| **Debug log**                  | Writes outgoing requests + successful response bodies to the `wc-maya-gateway` log channel (off by default).            |
| **Manual capture**             | Choose `None` for auto-capture, or `NORMAL` / `FINAL` / `PREAUTHORIZATION` to authorize-now-capture-later.              |
| **Local dev webhook URL**      | Optional. Public tunnel URL (ngrok / cloudflared) used as the callback host while developing locally.                   |
| **Registered webhooks (live)** | Live read-back of every webhook on the Maya account, marked **managed** or **external**.                                |
| **Simulate webhook**           | Sandbox-only. Dispatches a forged payload through the webhook pipeline in-process.                                      |

The plugin is organized by responsibility — see
[architecture](https://github.com/roguedex-labs/woocommerce-maya-gateway/blob/main/docs/architecture.md)
and the [rebuild overview](https://github.com/roguedex-labs/woocommerce-maya-gateway/tree/main/docs/rebuild-overview).

## Development

### Run the test suite

```bash
./vendor/bin/pest
```

The plugin ships **244 Pest unit tests, 770 assertions**, all pure-function
or Brain-Monkey-stubbed. No WordPress test scaffold required.

### Format

```bash
composer format       # apply php-cs-fixer
composer format:check # fail on drift
composer lint         # phpcs (WordPress + WooCommerce standards)
```

### Regenerate the .pot translation template

```bash
php bin/make-pot.php
```

Extracts every `__() / _e() / esc_html__() / esc_attr__() / _n() / _x()`
call across `src/` and `templates/` into
`languages/wc-maya-gateway.pot`.

### Cut a release
A release is a git tag; Composer resolves it from GitHub. See [releasing](https://github.com/roguedex-labs/woocommerce-maya-gateway/blob/main/docs/releasing.md)
for versioning, tagging, rollout, and rollback.

To build the optional zip for non-Composer sites:

```bash
bin/build-release.sh          # HEAD
bin/build-release.sh v1.1.0   # a tag
```

Produces `dist/wc-maya-gateway-<version>.zip` from `git archive`, containing only
the runtime files: `src/`, `assets/`, `templates/`, `languages/`, the main plugin
file, README, LICENSE, CHANGELOG. Tests, docs and tooling are excluded via the
`export-ignore` rules in `.gitattributes`.

There is **no `vendor/` in the zip** — the plugin has no runtime dependencies and
autoloads its own `src/`. The build refuses to run if that ever stops being true.

## License

GPL-3.0-or-later
