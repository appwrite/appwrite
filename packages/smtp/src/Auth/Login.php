<?php

declare(strict_types=1);

namespace Utopia\SMTP\Auth;

/**
 * The undocumented mechanism every server implements anyway: username, then
 * password, each in answer to a prompt whose wording nobody agrees on.
 */
final readonly class Login implements Authenticator
{
    public function __construct(
        private string $username,
        #[\SensitiveParameter]
        private string $password,
    ) {}

    public function mechanism(): string
    {
        return 'LOGIN';
    }

    public function initial(): ?string
    {
        return null;
    }

    public function respond(string $challenge, int $step): string
    {
        // The prompts are base64 and their wording is not standardised, so the
        // order is the only thing worth trusting.
        return $step === 0 ? $this->username : $this->password;
    }
}
