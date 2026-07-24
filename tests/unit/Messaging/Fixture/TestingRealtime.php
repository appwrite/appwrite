<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging\Fixture;

use Appwrite\Messaging\Adapter\Realtime;

final class TestingRealtime extends Realtime
{
    public function getPublishedMessage(
        string $projectId,
        array $payload,
        array $events,
        array $channels,
        array $roles,
        array $options = [],
    ): array {
        return $this->getMessage(
            projectId: $projectId,
            payload: $payload,
            events: $events,
            channels: $channels,
            roles: $roles,
            options: $options,
        );
    }
}
