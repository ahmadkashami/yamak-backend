<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class FirebaseNotificationService
{
    public function __construct(private readonly Client $client)
    {
    }

    public function sendToToken(string $token, array $notification): array
    {
        return $this->send([
            'token' => $token,
            'notification' => $this->notificationPayload($notification),
            'data' => $this->dataPayload($notification['data'] ?? []),
            'apns' => $this->apnsPayload(),
        ]);
    }

    public function sendToTopic(string $topic, array $notification): array
    {
        return $this->send([
            'topic' => $topic,
            'notification' => $this->notificationPayload($notification),
            'data' => $this->dataPayload($notification['data'] ?? []),
            'apns' => $this->apnsPayload(),
        ]);
    }

    private function send(array $message): array
    {
        $projectId = $this->projectId();

        $response = $this->client->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
            'headers' => [
                'Authorization' => 'Bearer '.$this->accessToken(),
                'Accept' => 'application/json',
            ],
            'json' => [
                'message' => $message,
            ],
        ]);

        return json_decode((string) $response->getBody(), true) ?: [];
    }

    private function accessToken(): string
    {
        return Cache::remember('firebase_access_token', now()->addMinutes(50), function () {
            $credentials = $this->credentials();
            $now = time();
            $assertion = $this->base64UrlEncode(json_encode([
                'alg' => 'RS256',
                'typ' => 'JWT',
            ])).'.'.$this->base64UrlEncode(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

            openssl_sign($assertion, $signature, $credentials['private_key'], 'sha256WithRSAEncryption');
            $jwt = $assertion.'.'.$this->base64UrlEncode($signature);

            $response = $this->client->post('https://oauth2.googleapis.com/token', [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ],
            ]);

            $payload = json_decode((string) $response->getBody(), true) ?: [];

            if (empty($payload['access_token'])) {
                throw new RuntimeException('Firebase access token could not be created.');
            }

            return $payload['access_token'];
        });
    }

    private function credentials(): array
    {
        $path = config('services.firebase.credentials');

        if (! $path || ! file_exists($path)) {
            throw new RuntimeException('Firebase credentials are not configured.');
        }

        $credentials = json_decode(file_get_contents($path), true);

        if (! is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            throw new RuntimeException('Firebase credentials file is invalid.');
        }

        return $credentials;
    }

    private function projectId(): string
    {
        $configuredProjectId = config('services.firebase.project_id');

        if ($configuredProjectId) {
            return $configuredProjectId;
        }

        $credentials = $this->credentials();

        if (empty($credentials['project_id'])) {
            throw new RuntimeException('Firebase project id is not configured.');
        }

        return $credentials['project_id'];
    }

    private function notificationPayload(array $notification): array
    {
        return [
            'title' => $notification['title'] ?? '',
            'body' => $notification['body'] ?? '',
        ];
    }

    private function dataPayload(array $data): array
    {
        return collect($data)
            ->map(fn ($value) => is_scalar($value) ? (string) $value : json_encode($value))
            ->all();
    }

    private function apnsPayload(): array
    {
        return [
            'payload' => [
                'aps' => [
                    'sound' => 'default',
                ],
            ],
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
