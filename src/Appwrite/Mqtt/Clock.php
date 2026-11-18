<?php

namespace Appwrite\Mqtt;

/** A logical clock for detecting an access change that drifts in while an operation is in flight. */
class Clock
{
    private int $ticks = 0;

    private int $inFlight = 0;

    /** @var array<string, array<string, int>> project => userId => tick, kept only while an operation is in flight */
    private array $stamps = [];

    /** Mark an operation in flight and read the clock; pair every call with stop(). */
    public function start(): int
    {
        $this->inFlight++;

        return $this->ticks;
    }

    /** End an in-flight operation, clearing the ticks once none remain to observe them. */
    public function stop(): void
    {
        if (--$this->inFlight <= 0) {
            $this->inFlight = 0;
            $this->stamps = [];
        }
    }

    /** Record an access change for an identity, but only while an operation is in flight to see it. */
    public function set(string $projectId, string $userId): void
    {
        if ($this->inFlight > 0) {
            $this->stamps[$projectId][$userId] = ++$this->ticks;
        }
    }

    /** Whether an access change for the identity was recorded after $reading. */
    public function isDrifted(string $projectId, string $userId, int $reading): bool
    {
        return ($this->stamps[$projectId][$userId] ?? 0) > $reading;
    }
}
