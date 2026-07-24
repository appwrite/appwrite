<?php

declare(strict_types=1);

namespace Tests\Unit\Event;

use Appwrite\Messaging\Adapter;

final class CapturingAdapter extends Adapter
{
    /**
     * @var list<array{projectId: string, payload: array, events: array, channels: array, roles: array, options: array}>
     */
    public array $messages = [];

    public int $failures = 0;

    public function subscribe(
        string $projectId,
        mixed $identifier,
        string $subscriptionId,
        array $roles,
        array $channels,
        array $queryGroup = [],
    ): void {
    }

    public function unsubscribe(mixed $identifier): void
    {
    }

    public function send(
        string $projectId,
        array $payload,
        array $events,
        array $channels,
        array $roles,
        array $options,
    ): void {
        if ($this->failures > 0) {
            $this->failures--;

            throw new \Exception('realtime delivery interrupted');
        }

        $this->messages[] = [
            'projectId' => $projectId,
            'payload' => $payload,
            'events' => $events,
            'channels' => $channels,
            'roles' => $roles,
            'options' => $options,
        ];
    }
}
