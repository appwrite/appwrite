<?php

declare(strict_types=1);

namespace Tests\Unit\Maintenance;

use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;
use Utopia\Schedule\Clock\Test as TestClock;

/**
 * A queue that consumers drain at a fixed rate on the test clock. Its size
 * is what was prefilled and published minus what has drained since, and it
 * never drains below empty, so a queue that sat idle has no credit to
 * swallow a later burst. A rate of zero never drains.
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

    /** @var array<string, float> */
    private array $levels = [];

    /** @var array<string, float> */
    private array $observed = [];

    public function __construct(
        private readonly TestClock $clock,
        private readonly float $rate,
        private readonly int $prefill = 0,
    ) {
    }

    #[\Override]
    public function publish(Queue $queue, array $payload): bool
    {
        if (++$this->attempts >= ($this->refuse ?? \PHP_INT_MAX)) {
            return false;
        }

        $this->drain($queue);
        $this->payloads[$queue->name][] = $payload;
        $this->times[$queue->name][] = (float) $this->clock->now()->format('U.u');
        $this->levels[$queue->name]++;

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

        return (int) \ceil($this->levels[$queue->name]);
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
        $now = (float) $this->clock->now()->format('U.u');
        $level = $this->levels[$queue->name] ?? (float) $this->prefill;
        $elapsed = $now - ($this->observed[$queue->name] ?? $now);

        $this->levels[$queue->name] = \max(0.0, $level - $this->rate * $elapsed);
        $this->observed[$queue->name] = $now;
    }
}
