<?php

use Utopia\Validator\URL;
use Appwrite\Network\Validator\PublicURL;

class GuestUrlFixture
{
    public function badGuestUrl(): void
    {
        $this
            // ruleid: php.appwrite.guest-url-without-publicurl
            ->label('scope', 'avatars.read')
            ->param('url', '', new URL(), 'Website to fetch.');
    }

    public function goodPublicUrl(): void
    {
        $this
            // ok: php.appwrite.guest-url-without-publicurl
            ->label('scope', 'avatars.read')
            ->param('url', '', new PublicURL(), 'Website to fetch.');
    }

    public function privilegedUrl(): void
    {
        $this
            // ok: php.appwrite.guest-url-without-publicurl
            ->label('scope', 'webhooks.write')
            ->param('url', '', new URL(), 'Webhook URL.');
    }
}
