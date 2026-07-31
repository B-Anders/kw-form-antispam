<?php

declare(strict_types=1);

/**
 * Bootstrap for the dev-only ALTCHA differential test harness.
 *
 * Loads the official altcha-org/altcha library (the oracle) via Composer and the
 * plugin's ALTCHA core directly from source. The plugin core has no WordPress
 * dependencies, so no WP test scaffolding is involved.
 */

$vendor = __DIR__ . '/../vendor/autoload.php';
if (!is_file($vendor)) {
    fwrite(STDERR, "Run `composer install` in tools/oracle first.\n");
    exit(1);
}
require_once $vendor;

$core = __DIR__ . '/../../../plugin/includes/altcha/autoload.php';
if (!is_file($core)) {
    fwrite(STDERR, "Cannot find the plugin ALTCHA core at {$core}.\n");
    exit(1);
}
require_once $core;
