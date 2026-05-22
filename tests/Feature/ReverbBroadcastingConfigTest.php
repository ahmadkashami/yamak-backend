<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReverbBroadcastingConfigTest extends TestCase
{
    public function test_reverb_broadcasting_configuration_loads_without_real_secrets(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-reverb-key',
            'broadcasting.connections.reverb.secret' => 'test-reverb-secret',
            'broadcasting.connections.reverb.app_id' => 'test-reverb-app',
            'broadcasting.connections.reverb.options.host' => 'localhost',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
            'reverb.apps.apps.0.allowed_origins' => [
                'http://localhost:5173',
                'https://dashboard.example.test',
            ],
        ]);

        $this->assertSame('reverb', config('broadcasting.default'));
        $this->assertSame('reverb', config('broadcasting.connections.reverb.driver'));
        $this->assertSame('localhost', config('broadcasting.connections.reverb.options.host'));
        $this->assertSame(8080, config('broadcasting.connections.reverb.options.port'));
        $this->assertFalse(config('broadcasting.connections.reverb.options.useTLS'));
        $this->assertContains('https://dashboard.example.test', config('reverb.apps.apps.0.allowed_origins'));
    }
}
