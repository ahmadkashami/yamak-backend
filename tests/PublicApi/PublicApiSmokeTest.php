<?php

namespace Tests\PublicApi;

use App\Services\CloudinaryUploadService;
use Illuminate\Http\UploadedFile;
use Mockery;
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
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'size limit : 1mb , file type : jpg|jpeg|png ');
    }

    public function test_public_file_upload_returns_cloudinary_payload(): void
    {
        $cloudinary = Mockery::mock(CloudinaryUploadService::class);
        $cloudinary->shouldReceive('upload')
            ->once()
            ->andReturn([
                'secure_url' => 'https://res.cloudinary.com/yamak/image/upload/demo.png',
                'public_id' => 'dev/images/demo',
            ]);

        $this->instance(CloudinaryUploadService::class, $cloudinary);

        $this->post('/api/dashboard/v1/files-upload', [
            'file' => UploadedFile::fake()->image('demo.png')->size(100),
        ])
            ->assertOk()
            ->assertJsonPath('statusCode', 200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.secure_url', 'https://res.cloudinary.com/yamak/image/upload/demo.png');
    }
}
