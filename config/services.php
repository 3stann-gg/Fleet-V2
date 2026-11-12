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

    'tomtom' => [
        'key' => env('TOMTOM_API_KEY'),
        'planned_departure_enabled' => env('FLEET_ROUTE_PLANNED_DEPARTURE_ENABLED', false) === true,
        'planned_proof_issuance_enabled' => env('FLEET_ROUTE_PLANNED_PROOF_ISSUANCE_ENABLED', false) === true,
        'planned_apply_enabled' => env('FLEET_ROUTE_PLANNED_APPLY_ENABLED', false) === true,
        'planned_save_enabled' => env('FLEET_ROUTE_PLANNED_SAVE_ENABLED', false) === true,
    ],

    'route_estimates' => [
        'provenance_recording_enabled' => env('FLEET_ROUTE_PROVENANCE_RECORDING_ENABLED', false) === true,
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
        'fallback_model' => env(
            'GEMINI_FALLBACK_MODEL',
            'gemini-3.5-flash-lite'
        ),
    ],

    'google_maps' => [
        'key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    'admin_seed' => [
        'password' => env('ADMIN_SEED_PASSWORD'),
    ],

];
