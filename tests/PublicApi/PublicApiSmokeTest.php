<?php

namespace Tests\PublicApi;

use Tests\TestCase;

class PublicApiSmokeTest extends TestCase
{
    public function test_api_roots_return_nest_compatible_health_payloads(): void
    {
        foreach ([
            '/api/dashboard/v1',
            '/api/mobile/v1',
            '/api/driver/v1',
            '/api/driver-app/v1',
        ] as $uri) {
            $this->getJson($uri)
                ->assertOk()
                ->assertJsonPath('statusCode', 200)
                ->assertJsonPath('status', 'success')
                ->assertJsonStructure([
                    'serverDate',
                    'statusCode',
                    'status',
                    'path',
                    'duration',
                    'method',
                    'data' => [
                        'url',
                        'status',
                        'message',
                        'date',
                    ],
                ]);
        }
    }

    public function test_public_file_upload_validates_missing_file_without_authentication(): void
    {
        $this->postJson('/api/dashboard/v1/files-upload')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR');
    }
}
