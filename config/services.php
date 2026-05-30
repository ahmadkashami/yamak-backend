<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'project_id' => env('FIREBASE_PROJECT_ID'),
    ],

    'expo' => [
        'access_token' => env('EXPO_ACCESS_TOKEN'),
    ],

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME', env('Cloudinary_Cn')),
        'api_key' => env('CLOUDINARY_API_KEY', env('Cloudinary_API_KEY')),
        'api_secret' => env('CLOUDINARY_API_SECRET', env('Cloudinary_SECRET')),
        'timeout' => env('CLOUDINARY_TIMEOUT', 30),
    ],

    'whatsapp' => [
        'version' => env('WA_VERSION'),
        'phone_number_id' => env('WA_PHONE_NUMBER_ID'),
        'access_token' => env('WA_ACCESS_TOKEN'),
        'auth_template' => env('WA_AUTH_TEMPLATE', 'yamak_auth'),
        'language' => env('WA_LANGUAGE', 'en'),
    ],

];
