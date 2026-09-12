<?php

declare(strict_types=1);

/**
 * CORS configuration — delivery API only.
 *
 * Security model:
 *   - Delivery endpoints (/api/v1/content/*, /api/v1/health, etc.) are public
 *     and consumed by browser-based frontends; their CORS policy is explicit
 *     and configurable via DELIVERY_CORS_ALLOWED_ORIGINS.
 *   - Management endpoints (/api/v1/manage/*) are explicitly denied by
 *     DenyManagementCrossOriginMiddleware — cross-origin requests return 403.
 *     They are NOT listed here and therefore receive no CORS headers.
 *
 * Set DELIVERY_CORS_ALLOWED_ORIGINS to a comma-separated list of origins, e.g.
 *   https://mysite.com,https://preview.mysite.com
 * Leave as '*' only for fully public read-only APIs.
 */
return [

    'paths' => [
        'api/v1/content/*',
        'api/v1/health',
        'api/v1/openapi.json',
    ],

    'allowed_methods' => ['GET', 'HEAD', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('DELIVERY_CORS_ALLOWED_ORIGINS', '*'))),
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Authorization',
        'Content-Type',
        'Accept',
        'X-Requested-With',
    ],

    'exposed_headers' => [
        'ETag',
        'Surrogate-Key',
        'X-Cache',
        'Cache-Control',
        'X-Magna-Request-Id',
    ],

    'max_age' => 3600,

    'supports_credentials' => false,

];
