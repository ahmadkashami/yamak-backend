<?php

$cachedConfig = __DIR__.'/../bootstrap/cache/config.php';

if (is_file($cachedConfig)) {
    unlink($cachedConfig);
}

require __DIR__.'/../vendor/autoload.php';

/*
 * B3.6-G1R fail-closed entry gate.
 *
 * PHPUnit applies phpunit.xml <php><env> values before loading this script, so
 * the app bootstrapped here sees the *effective* test configuration. The guard
 * opens no connection and runs no query; it aborts the whole run before any
 * test can migrate, truncate or write to a database.
 */
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

App\Support\DatabaseSafety::assertSafeTestingEnvironment();
