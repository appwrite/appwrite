<?php

declare(strict_types=1);

namespace Utopia\SMTP\Auth;

/**
 * Google's and Microsoft's bearer-token mechanism. Not an RFC, but it is the
 * only way into either service without a password.
 */
final readonly class XOAuth2 implements Authenticator
{
    public function __construct(
        private string $username,
        #[\SensitiveParameter]
        private string $token,
    ) {}

    public function mechanism(): string
    {
        return 'XOAUTH2';
    }

    public function initial(): string
    {
        return "user={$this->username}\1auth=Bearer {$this->token}\1\1";
    }

    public function respond(string $challenge, int $step): string
    {
        // A challenge here is the server reporting why the token was refused.
        // Answering with nothing ends the exchange so the failure surfaces.
        return '';
    }
}
