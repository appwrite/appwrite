<?php

declare(strict_types=1);

namespace Tests\Unit\Presences\Mock;

use Appwrite\Event\Realtime;

/**
 * Realtime queue that records each triggered event instead of publishing it.
 */
final class RecordingRealtime extends Realtime
{
    /**
     * @var list<array{event: string, presenceId: mixed, payload: array<string, mixed>}>
     */
    public array $triggered = [];

    #[\Override]
    public function trigger(): string|bool
    {
        $this->triggered[] = [
            'event' => $this->getEvent(),
            'presenceId' => $this->getParam('presenceId'),
            'payload' => $this->getPayload(),
        ];

        return true;
    }
}
