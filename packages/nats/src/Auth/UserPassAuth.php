<?php

declare(strict_types=1);

namespace Utopia\NATS\Auth;

final class UserPassAuth implements Authenticator
{
    public function __construct(
        private readonly string $user,
        private readonly string $pass,
    ) {
    }

    public function authenticate(?string $nonce = null): array
    {
        return [
            'user' => $this->user,
            'pass' => $this->pass,
        ];
    }
}
