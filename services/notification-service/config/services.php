<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'internal' => [
        'token' => env('INTERNAL_SERVICE_TOKEN'),
    ],

    'keycloak' => [
        'issuer' => env('KEYCLOAK_ISSUER', 'http://localhost:8080/realms/stayhub'),
        'jwks_url' => env('KEYCLOAK_JWKS_URL', 'http://keycloak:8080/realms/stayhub/protocol/openid-connect/certs'),
        'audience' => env('KEYCLOAK_AUDIENCE', 'stayhub-api'),
        'allow_test_identity_headers' => env('ALLOW_TEST_IDENTITY_HEADERS', false),
    ],

];
