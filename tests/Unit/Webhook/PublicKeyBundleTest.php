<?php

/**
 * Unit tests for PublicKeyBundle.
 *
 * @package RogueDex\MayaGateway\Tests\Unit\Webhook
 */

declare(strict_types=1);

namespace RogueDex\MayaGateway\Tests\Unit\Webhook;

use Brain\Monkey\Filters;
use RogueDex\MayaGateway\Webhook\PublicKeyBundle;

test('exposes two PEMs per environment', function (): void {
    expect(PublicKeyBundle::SANDBOX_PEMS)->toHaveCount(2);
    expect(PublicKeyBundle::PRODUCTION_PEMS)->toHaveCount(2);
});

test('for_environment returns validated keys by sandbox flag', function (): void {
    expect(PublicKeyBundle::for_environment(true))->toBe(array_map('trim', PublicKeyBundle::SANDBOX_PEMS));
    expect(PublicKeyBundle::for_environment(false))->toBe(array_map('trim', PublicKeyBundle::PRODUCTION_PEMS));
});

test('every PEM is parseable by OpenSSL', function (): void {
    foreach (array_merge(PublicKeyBundle::SANDBOX_PEMS, PublicKeyBundle::PRODUCTION_PEMS) as $pem) {
        $key = openssl_pkey_get_public($pem);
        expect($key)->not->toBeFalse();
    }
});

test('the public-keys filter accepts a real rotated RSA key', function (): void {
    $rotated_key = PublicKeyBundle::SANDBOX_PEMS[0];
    Filters\expectApplied('wc_maya_webhook_public_keys')->andReturn([ "  {$rotated_key}  " ]);

    expect(PublicKeyBundle::for_environment(false))->toBe([ trim($rotated_key) ]);
});

test('an empty/invalid filter result falls back to the bundled keys (never disables verification)', function (): void {
    Filters\expectApplied('wc_maya_webhook_public_keys')->andReturn([]);

    expect(PublicKeyBundle::for_environment(true))->toBe(PublicKeyBundle::SANDBOX_PEMS);
});

test('an all-invalid key override falls back to bundled keys', function (): void {
    Filters\expectApplied('wc_maya_webhook_public_keys')->andReturn([ 'not-a-pem', 123 ]);

    expect(PublicKeyBundle::for_environment(false))->toBe(PublicKeyBundle::PRODUCTION_PEMS);
});

test('a mixed key override retains only valid RSA public keys', function (): void {
    $rsa_key = PublicKeyBundle::SANDBOX_PEMS[0];
    Filters\expectApplied('wc_maya_webhook_public_keys')->andReturn([ 'not-a-pem', $rsa_key ]);

    expect(PublicKeyBundle::for_environment(false))->toBe([ trim($rsa_key) ]);
});
