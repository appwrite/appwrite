<?php

use Utopia\Validator\Assoc;
use Utopia\Validator\WhiteList;

class UnboundedMapFixture
{
    public function badHeaders(): void
    {
        $this
            // ruleid: php.appwrite.unbounded-map-to-outbound
            ->label('scope', 'avatars.read')
            ->param('headers', [], new Assoc(), 'HTTP headers to send.');
    }

    public function badCookies(): void
    {
        $this
            // ruleid: php.appwrite.unbounded-map-to-outbound
            ->label('scope', 'public')
            ->param('cookies', [], new Assoc(), 'Cookie bag.');
    }

    public function allowlistedValidator(): void
    {
        $this
            // ok: php.appwrite.unbounded-map-to-outbound
            ->label('scope', 'avatars.read')
            ->param('headers', [], new WhiteList(['accept', 'accept-language']), 'Allowed headers.');
    }

    public function privilegedScope(): void
    {
        $this
            // ok: php.appwrite.unbounded-map-to-outbound
            ->label('scope', 'users.write')
            ->param('headers', [], new Assoc(), 'Server-only headers.');
    }
}
