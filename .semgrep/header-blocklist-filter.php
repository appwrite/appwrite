<?php

class HeaderBlocklistFixture
{
    // ruleid: php.appwrite.header-blocklist-filter
    private const BLOCKED_HEADERS = [
        'host',
        'metadata',
    ];

    // ruleid: php.appwrite.header-blocklist-filter
    private const DISALLOWED_HOSTS = [
        'localhost',
    ];

    // ruleid: php.appwrite.header-blocklist-filter
    private const FORBIDDEN_REQUEST_HEADERS = [
        'authorization',
    ];

    public function runtimeList(): void
    {
        // ruleid: php.appwrite.header-blocklist-filter
        $deniedHosts = [
            '169.254.169.254',
        ];
    }

    // ok: php.appwrite.header-blocklist-filter
    private const ALLOWED_HEADERS = [
        'accept',
        'accept-language',
    ];
}
