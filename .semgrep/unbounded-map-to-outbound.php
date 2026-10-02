<?php

use Utopia\Validator\Assoc;
use Utopia\Validator\WhiteList;

class UnboundedMapFixture
{
    public function bad(): void
    {
        $this
            // ruleid: php.appwrite.unbounded-map-to-outbound
            ->label('scope', 'avatars.read')
            ->param('headers', [], new Assoc(), 'HTTP headers to send.');
    }

    public function alsoBad(): void
    {
        $this
            // ruleid: php.appwrite.unbounded-map-to-outbound
            ->label('scope', 'public')
            ->param('query', [], new Assoc(), 'Query bag.');
    }

    public function allowlisted(): void
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
