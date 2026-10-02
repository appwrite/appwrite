<?php

class HeaderBlocklistFixture
{
    // ruleid: php.appwrite.header-blocklist-filter
    private const BLOCKED_HEADERS = [
        'host',
        'metadata',
    ];

    // ok: php.appwrite.header-blocklist-filter
    private const ALLOWED_HEADERS = [
        'accept',
        'accept-language',
    ];
}
