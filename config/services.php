<?php

return [

    'gemini' => [
        'key'   => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-1.5-flash-latest'),
    ],

    'openai' => [
        'key'   => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
        // Optional hard cap on completion tokens. Leave UNSET so each model uses
        // its own native maximum — sending a value larger than the model allows
        // makes OpenAI reject the request with HTTP 400 and fails every analysis.
        'max_tokens' => env('OPENAI_MAX_TOKENS'),
    ],

    'ai' => [
        'primary' => env('AI_PRIMARY', 'gemini'),
    ],

    'stripe' => [
        'secret'         => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'mailgun' => [
        'domain'   => env('MAILGUN_DOMAIN'),
        'secret'   => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme'   => 'https',
    ],

];
