<?php

namespace Appwrite\Bus\Events;

use Utopia\Bus\Event;

class SessionCreated implements Event
{
    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $project
     * @param array<string, mixed> $session
     */
    public function __construct(
        public readonly array $user,
        public readonly array $project,
        public readonly array $session,
        public readonly string $locale,
        public readonly bool $isFirstSession,
    ) {
    }
}
