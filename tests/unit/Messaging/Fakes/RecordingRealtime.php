<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging\Fakes;

use Appwrite\Event\Realtime;

/** A realtime publisher that records each triggered event instead of sending it. */
final class RecordingRealtime extends Realtime
{
    /** @var list<array{project: string, event: string, params: array<string, mixed>, payload: array<string, mixed>}> */
    public array $triggered = [];

    public function trigger(): string|bool
    {
        $this->triggered[] = [
            'project' => $this->getProject()->getId(),
            'event' => $this->getEvent(),
            'params' => $this->getParams(),
            'payload' => $this->getPayload(),
        ];

        return true;
    }
}
