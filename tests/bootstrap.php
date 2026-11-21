<?php

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
*/

use App\Support\DatabaseSafety;

$cachedConfig = __DIR__.'/../bootstrap/cache/config.php';

if (is_file($cachedConfig)) {
    unlink($cachedConfig);
}

require __DIR__.'/../vendor/autoload.php';

// Gate before bootstrap/app.php is loaded or the Console Kernel boots. Laravel
// providers are registered during Kernel bootstrap and may resolve DB
// connections; this check must therefore run first.
DatabaseSafety::assertPreBootstrapTestingEnvironment();

$app = require __DIR__.'/../bootstrap/app.php';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Defence in depth after .env, config and provider bootstrap are effective.
DatabaseSafety::assertSafeTestingEnvironment();

return $app;
