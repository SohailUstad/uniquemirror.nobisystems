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

// Merge this entry into the returned array in config/services.php.
'ai' => [
    'endpoint' => env('AI_API_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
    'api_key' => env('AI_API_KEY'),
    'model' => env('AI_MODEL', 'gpt-4o-mini'),
    'max_input_tokens' => (int) env('AI_MAX_INPUT_TOKENS', 64),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 128),
    'confirmation_ttl_minutes' => (int) env('AI_CONFIRMATION_TTL_MINUTES', 10),
],

'fcm' => [
    'project_id' => env('FCM_PROJECT_ID'),
    'client_email' => env('FCM_CLIENT_EMAIL'),
    'private_key' => str_replace('\\n', "\n", (string) env('FCM_PRIVATE_KEY')),
],

];
