<?php

namespace App\Services;

use GuzzleHttp\Client;
use RuntimeException;

class CloudinaryUploadService
{
    public function __construct(private readonly Client $client)
    {
    }

    public function upload(string $path, string $folder = 'dev/images'): array
    {
        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');

        if (! $cloudName || ! $apiKey || ! $apiSecret) {
            throw new RuntimeException('Cloudinary credentials are not configured.');
        }

        $timestamp = time();
        $signature = sha1("folder={$folder}&timestamp={$timestamp}{$apiSecret}");

        $response = $this->client->post("https://api.cloudinary.com/v1_1/{$cloudName}/image/upload", [
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => fopen($path, 'r'),
                ],
                [
                    'name' => 'folder',
                    'contents' => $folder,
                ],
                [
                    'name' => 'api_key',
                    'contents' => $apiKey,
                ],
                [
                    'name' => 'timestamp',
                    'contents' => (string) $timestamp,
                ],
                [
                    'name' => 'signature',
                    'contents' => $signature,
                ],
            ],
        ]);

        return json_decode((string) $response->getBody(), true) ?: [];
    }
}
