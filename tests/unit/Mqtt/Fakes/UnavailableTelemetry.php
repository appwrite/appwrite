<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt\Fakes;

use Utopia\Telemetry\Adapter\None;
use Utopia\Telemetry\Counter;

/** Fails every drop count, like a broken exporter, and records the failures. */
final class UnavailableTelemetry extends None
{
    public RecordingCounter $failed;

    public function __construct()
    {
        $this->failed = new RecordingCounter();
    }

    public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Counter
    {
        return match ($name) {
            'mqtt.messages.failed' => $this->failed,
            'mqtt.messages.dropped' => new class () extends Counter {
                public function add(float|int $amount, iterable $attributes = []): void
                {
                    throw new \RuntimeException('telemetry unavailable');
                }
            },
            default => parent::createCounter($name, $unit, $description, $advisory),
        };
    }
}
