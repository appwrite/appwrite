<?php

use Utopia\Validator\Text;
use Utopia\Validator\URL;
use Appwrite\Network\Validator\PublicURL;

class GuestUrlFixture
{
    public function badGuestUrl(): void
    {
        // ruleid: php.appwrite.guest-url-without-publicurl
        $this
            ->label('scope', 'avatars.read')
            ->param('url', '', new URL(), 'Website to fetch.');
    }

    public function badGuestEndpointText(): void
    {
        // ruleid: php.appwrite.guest-url-without-publicurl
        $this
            ->label('scope', 'documents.write')
            ->param('webhookUrl', '', new Text(2048), 'Callback target.');
    }

    public function badPublicEndpoint(): void
    {
        // ruleid: php.appwrite.guest-url-without-publicurl
        $this
            ->label('scope', 'public')
            ->param('endpoint', '', new URL(['https']), 'Endpoint.');
    }

    public function goodPublicUrl(): void
    {
        // ok: php.appwrite.guest-url-without-publicurl
        $this
            ->label('scope', 'avatars.read')
            ->param('url', '', new PublicURL(), 'Website to fetch.');
    }

    public function goodRedirectValidator(): void
    {
        // ok: php.appwrite.guest-url-without-publicurl
        $this
            ->label('scope', 'sessions.write')
            ->param('url', '', fn ($redirectValidator) => $redirectValidator, 'Redirect.', false, ['redirectValidator']);
    }

    public function goodMixedParams(): void
    {
        // ok: php.appwrite.guest-url-without-publicurl
        $this
            ->label('scope', 'avatars.read')
            ->param('url', '', new PublicURL(), 'Website to fetch.')
            ->param('name', '', new Text(128), 'Name.');
    }

    public function privilegedUrl(): void
    {
        // ok: php.appwrite.guest-url-without-publicurl
        $this
            ->label('scope', 'webhooks.write')
            ->param('url', '', new URL(), 'Webhook URL.');
    }
}
