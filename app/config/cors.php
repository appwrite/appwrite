<?php

/**
 * CORS Configuration
 *
 * Centralised list of allowed methods, headers, and exposed headers for CORS responses.
 */
return [
    'allowedMethods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    'allowedHeaders' => [
        'Accept',
        'Origin',
        'Cookie',
        'Set-Cookie',
        // Content
        'Content-Type',
        'Content-Range',
        // Appwrite
        'X-Appwrite-Project',
        'X-Appwrite-Key',
        'X-Appwrite-Dev-Key',
        'X-Appwrite-Locale',
        'X-Appwrite-Mode',
        'X-Appwrite-JWT',
        'X-Appwrite-Response-Format',
        'X-Appwrite-Timeout',
        'X-Appwrite-ID',
        'X-Appwrite-Timestamp',
        'X-Appwrite-Session',
        'X-Appwrite-Platform',
        // SDK generator
        'X-SDK-Version',
        'X-SDK-Name',
        'X-SDK-Language',
        'X-SDK-Platform',
        'X-SDK-GraphQL',
        'X-SDK-Profile',
        // Caching
        'Range',
        'Cache-Control',
        'Expires',
        'Pragma',
        // Server to server
        'X-Fallback-Cookies',
        'X-Requested-With',
        'X-Forwarded-For',
        'X-Forwarded-User-Agent',
    ],
    'exposedHeaders' => [
        'X-Appwrite-Session',
        'X-Fallback-Cookies',
    ],
];
