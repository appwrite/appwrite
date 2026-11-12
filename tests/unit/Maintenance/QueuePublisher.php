<?php

declare(strict_types=1);

namespace Tests\Unit\Maintenance;

use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;
use Utopia\Schedule\Clock\Test as TestClock;

/**
 * A queue that consumers drain oldest first at a fixed rate on the test clock.
 * It starts with $prefill messages published $age seconds before it is first
 * read, and never drains below empty, so a queue that sat idle has no credit
 * to swallow a later burst. A rate of zero never drains.
 *
 * Setting $refuse refuses that publish attempt, counted from 1 across every
 * queue, and each one after it, until it is set back to null.
 */
final class QueuePublisher implements Synchronous
{
    public ?int $refuse = null;

    private int $attempts = 0;

    /** @var array<string, list<array<string, mixed>>> */
    private array $payloads = [];

    /** @var array<string, list<float>> */
    private array $times = [];

    /** @var array<string, list<float>> when each message still waiting was published, oldest first */
    private array $waiting = [];

    /** @var array<string, float> */
    private array $credit = [];

    /** @var array<string, float> */
    private array $observed = [];

    public function __construct(
        private readonly TestClock $clock,
        private readonly float $rate,
        private readonly int $prefill = 0,
        private readonly int $age = 0,
    ) {
    }

    #[\Override]
    public function publish(Queue $queue, array $payload): bool
    {
        if (++$this->attempts >= ($this->refuse ?? \PHP_INT_MAX)) {
            return false;
        }

        $this->drain($queue);
        $now = $this->now();
        $this->payloads[$queue->name][] = $payload;
        $this->times[$queue->name][] = $now;
        $this->waiting[$queue->name][] = $now;

        return true;
    }

    #[\Override]
    public function publishMany(Queue $queue, array $payloads): bool
    {
        foreach ($payloads as $payload) {
            if (!$this->publish($queue, $payload)) {
                return false;
            }
        }

        return true;
    }

    #[\Override]
    public function retry(Queue $queue, ?int $limit = null): void
    {
    }

    #[\Override]
    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        if ($failedJobs) {
            return $this->getFailedCount($queue);
        }

        $this->drain($queue);

        return \count($this->waiting[$queue->name]);
    }

    #[\Override]
    public function getQueueAge(Queue $queue): int
    {
        $this->drain($queue);
        $oldest = $this->waiting[$queue->name][0] ?? null;

        return $oldest === null ? 0 : (int) ($this->now() - $oldest);
    }

    #[\Override]
    public function getFailedCount(Queue $queue): int
    {
        return 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payloads(string $queue): array
    {
        return $this->payloads[$queue] ?? [];
    }

    /**
     * When each payload was published, on the test clock.
     *
     * @return list<float>
     */
    public function times(string $queue): array
    {
        return $this->times[$queue] ?? [];
    }

    private function drain(Queue $queue): void
    {
        $now = $this->now();
        $this->waiting[$queue->name] ??= \array_fill(0, $this->prefill, $now - $this->age);
        $credit = ($this->credit[$queue->name] ?? 0.0) + $this->rate * ($now - ($this->observed[$queue->name] ?? $now));

        while ($credit >= 1.0 && $this->waiting[$queue->name] !== []) {
            \array_shift($this->waiting[$queue->name]);
            $credit--;
        }

        $this->credit[$queue->name] = $this->waiting[$queue->name] === [] ? 0.0 : $credit;
        $this->observed[$queue->name] = $now;
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
