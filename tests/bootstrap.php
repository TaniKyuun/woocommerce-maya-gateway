<?php

/**
 * Pest / PHPUnit bootstrap.
 *
 * @package RogueDex\MayaGateway\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/stubs.php';

// Normally defined by the main plugin file, which never runs under the unit
// tests. Asset-enqueue code reads it for cache-busting.
define('WC_MAYA_VERSION', '1.1.0');
