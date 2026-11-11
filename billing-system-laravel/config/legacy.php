<?php

return [
    'jwt' => [
        // Required: provide the same secret as Spring's jwt.secret.
        'secret' => env('LEGACY_JWT_SECRET'),
        // Spring expresses expiration in milliseconds; default is 24 hours.
        'expiration_ms' => (int) env('LEGACY_JWT_EXPIRATION_MS', 86400000),
    ],
];
