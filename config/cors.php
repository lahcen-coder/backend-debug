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
     * Token-based (Bearer) API — no cookies are sent, so credentials
     * are not required. Keeping this false avoids strict CORS preflight
     * requirements around the Access-Control-Allow-Credentials header.
     */
    'supports_credentials' => false,

];
