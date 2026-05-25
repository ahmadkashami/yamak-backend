<?php

namespace Tests\Unit;

use App\Services\ExpoNotificationService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use RuntimeException;
use Tests\TestCase;

class ExpoNotificationServiceTest extends TestCase
{
    public function test_it_sends_valid_expo_push_tokens(): void
    {
        config(['services.expo.access_token' => 'test-expo-token']);

        $history = [];
        $client = $this->fakeClient([
            new Response(200, [], json_encode([
                'data' => [
                    'status' => 'ok',
                    'id' => 'ticket-1',
                ],
            ])),
        ], $history);

        $service = new ExpoNotificationService($client);

        $response = $service->sendToToken('ExponentPushToken[test-token]', [
            'title' => 'Hello',
            'body' => 'Body',
            'data' => ['requestId' => 123],
        ]);

        $request = $history[0]['request'];
        $payload = json_decode((string) $request->getBody(), true);

        $this->assertSame('ok', $response['data']['status']);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://exp.host/--/api/v2/push/send', (string) $request->getUri());
        $this->assertSame('Bearer test-expo-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('ExponentPushToken[test-token]', $payload['to']);
        $this->assertSame('default', $payload['sound']);
        $this->assertSame('Hello', $payload['title']);
        $this->assertSame(123, $payload['data']['requestId']);
    }

    public function test_it_rejects_invalid_expo_tokens_before_sending(): void
    {
        $history = [];
        $service = new ExpoNotificationService($this->fakeClient([], $history));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No valid Expo push token found.');

        $service->sendToToken('invalid-token', [
            'title' => 'Hello',
            'body' => 'Body',
        ]);

        $this->assertSame([], $history);
    }

    private function fakeClient(array $responses, array &$history): Client
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::history($history));

        return new Client(['handler' => $handler]);
    }
}
