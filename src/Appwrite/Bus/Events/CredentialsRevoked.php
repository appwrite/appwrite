<?php

namespace Appwrite\Bus\Events;

use Utopia\Bus\Event;

/**
 * Every way into an account but the address just proven was revoked, once that is committed.
 */
class CredentialsRevoked implements Event
{
    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $project
     */
    public function __construct(
        public readonly array $user,
        public readonly array $project,
    ) {
    }
}
