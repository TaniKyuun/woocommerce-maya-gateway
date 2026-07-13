<?php

/**
 * Plugin Name:       WooCommerce Maya Gateway
 * Plugin URI:        https://github.com/roguedex-labs/woocommerce-maya-gateway
 * Description:       Maya payment gateway for WooCommerce (Philippines).
 * Version:           1.0.0
 * Author:            RogueDex
 * Author URI:        https://github.com/roguedex-labs
 * License:           GPL-3.0-or-later
 * Text Domain:       wc-maya-gateway
 * Domain Path:       /languages
 * Update URI:        false
 * Requires at least: 7.0
 * Requires PHP:      8.3
 * WC requires at least: 10.6
 * WC tested up to:   10.7
 *
 * @package RogueDex\MayaGateway
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use RogueDex\MayaGateway\Plugin;

define('WC_MAYA_PLUGIN_FILE', __FILE__);

// Keep in step with the Version: header above, readme.txt's Stable tag, and the
// CHANGELOG. Asset enqueues read this for cache-busting.
define('WC_MAYA_VERSION', '1.0.0');

/**
 * Declare WooCommerce feature compatibility.
 *
 * - `custom_order_tables` (HPOS): we read/write order meta via `WC_Order` only
 *   — no direct `posts`/`postmeta` SQL — so the gateway is HPOS-safe.
 * - `cart_checkout_blocks`: enables the block-based Cart and Checkout entry
 *   that {@see \RogueDex\MayaGateway\Blocks\MayaBlocksPaymentMethod} provides.
 *
 * This runs before the autoload guard below: it needs nothing of ours, and if
 * the guard ever trips while the plugin is still active, staying silent here
 * would let WooCommerce read us as HPOS-incompatible and disable HPOS
 * store-wide.
 */
add_action(
    'before_woocommerce_init',
    static function (): void {
        if (class_exists(FeaturesUtil::class)) {
            FeaturesUtil::declare_compatibility('custom_order_tables', WC_MAYA_PLUGIN_FILE, true);
            FeaturesUtil::declare_compatibility('cart_checkout_blocks', WC_MAYA_PLUGIN_FILE, true);
        }
    },
);

/*
 * Composer-managed installs (Bedrock) autoload this plugin from the project
 * root: Composer merges our PSR-4 map into the root autoloader, resolving it
 * against the installed path, and wp-config.php requires that autoloader before
 * WordPress boots. There is no vendor/ inside the plugin directory in that
 * layout — a bundled one exists only in the standalone release zip. So this
 * require is conditional, and the class check below is what actually decides
 * whether we can run.
 */
$wc_maya_autoload = __DIR__ . '/vendor/autoload.php';

if (is_readable($wc_maya_autoload)) {
    require_once $wc_maya_autoload;
}

if (! class_exists(Plugin::class)) {
    add_action(
        'admin_notices',
        static function (): void {
            echo '<div class="error"><p>'
                . esc_html__(
                    'WooCommerce Maya Gateway could not load its classes. Install it with Composer, or use the release zip.',
                    'wc-maya-gateway',
                )
                . '</p></div>';
        },
    );

    return;
}

add_action('plugins_loaded', [ Plugin::class, 'init' ]);
