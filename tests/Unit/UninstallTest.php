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

    Functions\when('is_multisite')->justReturn(false);

    Functions\expect('delete_option')
        ->once()
        ->with('woocommerce_maya_checkout_settings');

    Functions\expect('as_unschedule_all_actions')
        ->once()
        ->with('wc_maya_replay_webhook', null, 'wc-maya-gateway');

    require __DIR__ . '/../../uninstall.php';
});

test('uninstall cleans each multisite blog and restores context', function (): void {
    if (! defined('WP_UNINSTALL_PLUGIN')) {
        define('WP_UNINSTALL_PLUGIN', true);
    }

    Functions\when('is_multisite')->justReturn(true);
    Functions\expect('get_sites')->once()->with([ 'fields' => 'ids' ])->andReturn([ 2, 7 ]);
    Functions\expect('switch_to_blog')->once()->with(2);
    Functions\expect('switch_to_blog')->once()->with(7);
    Functions\expect('delete_option')->twice()->with('woocommerce_maya_checkout_settings');
    Functions\expect('as_unschedule_all_actions')->twice()->with('wc_maya_replay_webhook', null, 'wc-maya-gateway');
    Functions\expect('restore_current_blog')->twice();

    require __DIR__ . '/../../uninstall.php';
});
