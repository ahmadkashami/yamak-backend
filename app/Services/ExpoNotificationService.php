<?php

namespace App\Services;

use GuzzleHttp\Client;
use RuntimeException;

class ExpoNotificationService
{
    private const PUSH_URL = 'https://exp.host/--/api/v2/push/send';

    public function __construct(private readonly Client $client)
    {
    }

    public function sendToToken(string $token, array $notification): array
    {
        return $this->sendToTokens([$token], $notification);
    }

    public function sendToTokens(array $tokens, array $notification): array
    {
        $messages = collect($tokens)
            ->filter(fn ($token) => is_string($token) && $this->isPushTokenValid($token))
            ->map(fn (string $token) => [
                'to' => $token,
                'sound' => 'default',
                'title' => $notification['title'] ?? '',
                'body' => $notification['body'] ?? '',
                'data' => $this->dataPayload($notification['data'] ?? []),
            ])
            ->values()
            ->all();

        if ($messages === []) {
            throw new RuntimeException('No valid Expo push token found.');
        }

        $options = [
            'headers' => $this->headers(),
            'json' => count($messages) === 1 ? $messages[0] : $messages,
        ];

        $response = $this->client->post(self::PUSH_URL, $options);

        return json_decode((string) $response->getBody(), true) ?: [];
    }

    public function isPushTokenValid(string $token): bool
    {
        return (bool) preg_match('/^Expo(nent)?PushToken\[[^\]]+\]$/', $token);
    }

    private function headers(): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $accessToken = config('services.expo.access_token');

        if ($accessToken) {
            $headers['Authorization'] = 'Bearer '.$accessToken;
        }

        return $headers;
    }

    private function dataPayload(array $data): array
    {
        return collect($data)
            ->map(fn ($value) => is_scalar($value) ? $value : json_encode($value))
            ->all();
    }
}
