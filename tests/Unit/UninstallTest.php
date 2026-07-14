<?php

/**
 * Unit tests for uninstall cleanup.
 *
 * @package RogueDex\MayaGateway\Tests\Unit
 */

declare(strict_types=1);

namespace RogueDex\MayaGateway\Tests\Unit;

use Brain\Monkey\Functions;

test('uninstall removes settings and all replay jobs regardless of action args', function (): void {
    if (! defined('WP_UNINSTALL_PLUGIN')) {
        define('WP_UNINSTALL_PLUGIN', true);
    }

    Functions\expect('delete_option')
        ->once()
        ->with('woocommerce_maya_checkout_settings');

    Functions\expect('as_unschedule_all_actions')
        ->once()
        ->with('wc_maya_replay_webhook', null, 'wc-maya-gateway');

    require __DIR__ . '/../../uninstall.php';
});
