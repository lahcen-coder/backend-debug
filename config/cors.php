<?php

return [

    /*
     * Paths that will be handled by CORS middleware.
     */
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'up'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/'),
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    /*
     * Must be true for Sanctum cookie-based auth.
     * For token-based auth (Bearer) this can be false, but true is safer.
     */
    'supports_credentials' => true,

];
