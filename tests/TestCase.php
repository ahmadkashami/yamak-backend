<?php

namespace Tests;

use App\Services\FakeWhatsAppService;
use App\Services\WhatsAppService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Swap the real WhatsApp service for an in-memory fake and return it so
     * tests can assert on the recorded calls without making real HTTP requests
     * or needing WA credentials in the environment.
     */
    protected function fakeWhatsApp(): FakeWhatsAppService
    {
        $fake = new FakeWhatsAppService();
        $this->app->instance(WhatsAppService::class, $fake);
        return $fake;
    }
}
