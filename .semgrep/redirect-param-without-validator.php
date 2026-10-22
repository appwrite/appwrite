<?php

use Utopia\Validator\Host;
use Utopia\Validator\Text;
use Utopia\Validator\URL;

class RedirectParamFixture
{
    public function badSuccess(): void
    {
        // ruleid: php.appwrite.redirect-param-without-validator
        $this
            ->label('scope', 'sessions.write')
            ->param('success', '', new URL(), 'Success redirect.');
    }

    public function badFailureText(): void
    {
        // ruleid: php.appwrite.redirect-param-without-validator
        $this
            ->label('scope', 'users.write')
            ->param('failure', '', new Text(2048), 'Failure redirect.');
    }

    public function goodValidator(): void
    {
        // ok: php.appwrite.redirect-param-without-validator
        $this
            ->label('scope', 'sessions.write')
            ->param('success', '', fn ($redirectValidator) => $redirectValidator, 'Success redirect.', true, ['redirectValidator']);
    }

    public function goodHost(): void
    {
        // ok: php.appwrite.redirect-param-without-validator
        $this
            ->label('scope', 'sessions.write')
            ->param('failure', '', new Host($hostnames), 'Failure redirect.');
    }
}
