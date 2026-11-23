<?php

declare(strict_types=1);

namespace Utopia\NATS\Auth;

interface Authenticator
{
    /**
     * Return fields to merge into the CONNECT JSON payload.
     *
     * @param string|null $nonce Server nonce for challenge-response auth (NKey)
     * @return array<string, mixed>
     */
    public function authenticate(?string $nonce = null): array;
}
