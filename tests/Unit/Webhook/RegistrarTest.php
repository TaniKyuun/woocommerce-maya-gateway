<?php

/**
 * Unit tests for the webhook Registrar.
 *
 * @package RogueDex\MayaGateway\Tests\Unit\Webhook
 */

declare(strict_types=1);

namespace RogueDex\MayaGateway\Tests\Unit\Webhook;

use Brain\Monkey\Functions;
use Mockery;
use RogueDex\MayaGateway\Api\Endpoints\Webhooks;
use RogueDex\MayaGateway\Util\Logger;
use RogueDex\MayaGateway\Value\WebhookRecord;
use RogueDex\MayaGateway\Webhook\Registrar;
use WP_Error;

beforeEach(function (): void {
    Functions\when('__')->alias(static fn(string $text, string $domain = ''): string => $text);
});

function wc_maya_existing_record(string $id, string $name, string $callback_url = 'https://old.example.test/cb'): WebhookRecord
{
    return WebhookRecord::from_array([
        'id'          => $id,
        'name'        => $name,
        'callbackUrl' => $callback_url,
        'createdAt'   => '2026-01-01T00:00:00Z',
        'updatedAt'   => '2026-01-01T00:00:00Z',
    ]);
}

test('managed_names covers exactly the PAYMENT_* events (no deprecated CHECKOUT_*)', function (): void {
    expect(Registrar::managed_names())->toEqualCanonicalizing([
        'PAYMENT_SUCCESS',
        'PAYMENT_FAILED',
        'PAYMENT_EXPIRED',
        'PAYMENT_CANCELLED',
    ]);
});

test('is_managed reports correctly for in-set and out-of-set names', function (): void {
    expect(Registrar::is_managed('PAYMENT_SUCCESS'))->toBeTrue();
    expect(Registrar::is_managed('CHECKOUT_DROPOUT'))->toBeFalse();
    expect(Registrar::is_managed('unrelated'))->toBeFalse();
});

test('reconcile rejects an empty callback URL', function (): void {
    $endpoint = Mockery::mock(Webhooks::class);
    $endpoint->shouldNotReceive('all');

    $result = (new Registrar($endpoint, new Logger(false)))->reconcile('   ');

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('wc_maya_registrar_empty_url');
});

test('reconcile bubbles list errors as WP_Error', function (): void {
    $endpoint = Mockery::mock(Webhooks::class);
    $endpoint->expects('all')->andReturn(new WP_Error('wc_maya_http_401', 'Unauthorized'));

    $result = (new Registrar($endpoint, new Logger(false)))->reconcile('https://example.test/cb');

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('wc_maya_http_401');
});

test('reconcile deletes managed and owned deprecated webhooks, skips others, recreates managed', function (): void {
    $endpoint = Mockery::mock(Webhooks::class);

    $endpoint->expects('all')->andReturn([
        wc_maya_existing_record('wh-1', 'PAYMENT_SUCCESS'),
        wc_maya_existing_record('wh-2', 'PAYMENT_FAILED'),
        wc_maya_existing_record('wh-3', 'CHECKOUT_FAILURE', 'https://new.example.test/cb'),
        wc_maya_existing_record('wh-4', 'CHECKOUT_DROPOUT', 'https://new.example.test/cb'),
        wc_maya_existing_record('wh-5', 'CHECKOUT_SUCCESS', 'https://merchant.example.test/cb'),
        wc_maya_existing_record('wh-6', 'merchant_custom_hook'),
    ]);

    $endpoint->expects('delete')->with('wh-1')->andReturn(wc_maya_existing_record('wh-1', 'PAYMENT_SUCCESS'));
    $endpoint->expects('delete')->with('wh-2')->andReturn(wc_maya_existing_record('wh-2', 'PAYMENT_FAILED'));
    $endpoint->expects('delete')->with('wh-3')->andReturn(wc_maya_existing_record('wh-3', 'CHECKOUT_FAILURE'));
    $endpoint->expects('delete')->with('wh-4')->andReturn(wc_maya_existing_record('wh-4', 'CHECKOUT_DROPOUT'));

    // Only the managed PAYMENT_* events are recreated — never the deprecated ones.
    foreach (Registrar::managed_names() as $event) {
        $endpoint->expects('create')->with($event, 'https://new.example.test/cb')
            ->andReturn(WebhookRecord::from_array([
                'id'          => 'new-' . $event,
                'name'        => $event,
                'callbackUrl' => 'https://new.example.test/cb',
            ]));
    }

    $result = (new Registrar($endpoint, new Logger(false)))->reconcile('https://new.example.test/cb');

    expect($result)->not->toBeInstanceOf(WP_Error::class);
    expect($result['deleted'])->toEqualCanonicalizing([ 'PAYMENT_SUCCESS', 'PAYMENT_FAILED', 'CHECKOUT_FAILURE', 'CHECKOUT_DROPOUT' ]);
    expect($result['skipped'])->toEqualCanonicalizing([ 'CHECKOUT_SUCCESS', 'merchant_custom_hook' ]);
    expect($result['errors'])->toBe([]);
    expect($result['created'])->toHaveCount(4);
});

test('reconcile records per-step errors without aborting the rest of the run', function (): void {
    $endpoint = Mockery::mock(Webhooks::class);

    $endpoint->expects('all')->andReturn([
        wc_maya_existing_record('wh-1', 'PAYMENT_SUCCESS'),
    ]);

    // Delete of the existing webhook fails.
    $endpoint->expects('delete')->with('wh-1')->andReturn(new WP_Error('wc_maya_http_500', 'oops'));

    // One create fails, the rest succeed.
    foreach (Registrar::managed_names() as $event) {
        if ('PAYMENT_FAILED' === $event) {
            $endpoint->expects('create')->with($event, 'https://x.test/cb')->andReturn(new WP_Error('wc_maya_http_409', 'dup'));
        } else {
            $endpoint->expects('create')->with($event, 'https://x.test/cb')->andReturn(WebhookRecord::from_array([
                'id'          => 'new-' . $event,
                'name'        => $event,
                'callbackUrl' => 'https://x.test/cb',
            ]));
        }
    }

    $result = (new Registrar($endpoint, new Logger(false)))->reconcile('https://x.test/cb');

    expect($result)->not->toBeInstanceOf(WP_Error::class);
    expect($result['deleted'])->toBe([]);
    expect($result['created'])->toHaveCount(3);
    expect($result['errors'])->toHaveCount(2);
    expect($result['errors'][0])->toContain('Delete PAYMENT_SUCCESS');
    expect($result['errors'][1])->toContain('Create PAYMENT_FAILED');
});
