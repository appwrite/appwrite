<?php

use Utopia\Validator\Assoc;
use Utopia\Validator\WhiteList;

class UnboundedMapFixture
{
    public function badHeaders(): void
    {
        // ruleid: php.appwrite.unbounded-map-to-outbound
        $this
            ->label('scope', 'avatars.read')
            ->param('headers', [], new Assoc(), 'HTTP headers to send.');
    }

    public function badCookies(): void
    {
        // ruleid: php.appwrite.unbounded-map-to-outbound
        $this
            ->label('scope', 'public')
            ->param('cookies', [], new Assoc(), 'Cookie bag.');
    }

    public function badAnyName(): void
    {
        // ruleid: php.appwrite.unbounded-map-to-outbound
        $this
            ->label('scope', 'rows.write')
            ->param('forward', [], new Assoc(), 'Arbitrary bag.');
    }

    public function allowlistedValidator(): void
    {
        // ok: php.appwrite.unbounded-map-to-outbound
        $this
            ->label('scope', 'avatars.read')
            ->param('headers', [], new WhiteList(['accept', 'accept-language']), 'Allowed headers.');
    }

    public function privilegedScope(): void
    {
        // ok: php.appwrite.unbounded-map-to-outbound
        $this
            ->label('scope', 'users.write')
            ->param('headers', [], new Assoc(), 'Server-only headers.');
    }
}

class AllowlistedMapFixture
{
    private const ALLOWED_HEADERS = ['accept', 'accept-language'];

    public function __construct()
    {
        // ok: php.appwrite.unbounded-map-to-outbound
        $this
            ->label('scope', 'avatars.read')
            ->param('headers', [], new Assoc(), 'Filtered against ALLOWED_HEADERS.');
    }
}
