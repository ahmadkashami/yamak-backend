<?php

namespace Tests\Unit;

use App\Services\CloudinaryUploadService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class CloudinaryUploadServiceTest extends TestCase
{
    public function test_it_sends_uploads_with_configured_timeout(): void
    {
        config([
            'services.cloudinary.cloud_name' => 'test-cloud',
            'services.cloudinary.api_key' => 'test-key',
            'services.cloudinary.api_secret' => 'test-secret',
            'services.cloudinary.timeout' => 12,
        ]);

        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'secure_url' => 'https://example.test/image.png',
                'resource_type' => 'image',
                'format' => 'png',
                'bytes' => 67,
            ])),
        ]));
        $handler->push(Middleware::history($history));

        $path = tempnam(sys_get_temp_dir(), 'cloudinary-upload-');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));

        try {
            $service = new CloudinaryUploadService(new Client(['handler' => $handler]));
            $response = $service->upload($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame('https://example.test/image.png', $response['secure_url']);
        $this->assertSame(12, $history[0]['options']['connect_timeout']);
        $this->assertSame(12, $history[0]['options']['timeout']);
    }
}
