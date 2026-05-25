<?php

namespace Tests\Unit;

use App\Services\FirebaseNotificationService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use RuntimeException;
use Tests\TestCase;

class FirebaseNotificationServiceTest extends TestCase
{
    public function test_it_requires_a_configured_credentials_path(): void
    {
        config([
            'services.firebase.credentials' => null,
            'services.firebase.project_id' => 'test-project',
        ]);

        $service = new FirebaseNotificationService($this->fakeClient());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Firebase credentials are not configured.');

        $service->sendToToken('test-fcm-token', [
            'title' => 'Hello',
            'body' => 'Body',
        ]);
    }

    public function test_it_rejects_invalid_credentials_files(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'firebase-credentials-');
        file_put_contents($path, json_encode(['project_id' => 'test-project']));

        config([
            'services.firebase.credentials' => $path,
            'services.firebase.project_id' => 'test-project',
        ]);

        $service = new FirebaseNotificationService($this->fakeClient());

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Firebase credentials file is invalid.');

            $service->sendToTopic('all', [
                'title' => 'Hello',
                'body' => 'Body',
            ]);
        } finally {
            @unlink($path);
        }
    }

    private function fakeClient(): Client
    {
        return new Client([
            'handler' => HandlerStack::create(new MockHandler([])),
        ]);
    }
}
