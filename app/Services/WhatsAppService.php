<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WhatsAppService
{
    public function sendAuthMessage(?string $to, string $code): ?array
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'preview_url' => false,
            'recipient_type' => 'individual',
            'to' => $this->normalizePhone($to),
            'type' => 'template',
            'template' => [
                'name' => config('services.whatsapp.auth_template', 'yamak_auth'),
                'language' => [
                    'code' => config('services.whatsapp.language', 'en'),
                ],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            [
                                'type' => 'text',
                                'text' => $code,
                            ],
                        ],
                    ],
                    [
                        'type' => 'button',
                        'sub_type' => 'url',
                        'index' => '0',
                        'parameters' => [
                            [
                                'type' => 'text',
                                'text' => $code,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function sendMessage(?string $to, string $message): ?array
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'preview_url' => false,
            'recipient_type' => 'individual',
            'to' => $this->normalizePhone($to),
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $message,
            ],
        ]);
    }

    private function send(array $payload): ?array
    {
        try {
            $url = $this->endpoint();

            return Http::withToken((string) config('services.whatsapp.access_token'))
                ->acceptJson()
                ->post($url, $payload)
                ->throw()
                ->json();
        } catch (RequestException|RuntimeException $exception) {
            Log::warning('WhatsApp message delivery failed.', [
                'status' => $exception instanceof RequestException ? $exception->response->status() : null,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function endpoint(): string
    {
        $version = config('services.whatsapp.version');
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $accessToken = config('services.whatsapp.access_token');

        if (! $version || ! $phoneNumberId || ! $accessToken) {
            throw new RuntimeException('WhatsApp credentials are not configured.');
        }

        return "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";
    }

    private function normalizePhone(?string $phone): string
    {
        return ltrim((string) $phone, '+');
    }
}
