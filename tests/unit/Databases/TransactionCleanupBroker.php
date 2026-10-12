<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;

final class TransactionCleanupBroker implements Synchronous
{
    /** @var list<array<string, mixed>> */
    public array $published = [];

    /**
     * @param callable(): bool $answer
     */
    public function __construct(private readonly mixed $answer)
    {
    }

    public function publish(Queue $queue, array $payload): bool
    {
        $accepted = ($this->answer)();
        if ($accepted) {
            $this->published[] = $payload;
        }

        return $accepted;
    }

    public function publishMany(Queue $queue, array $payloads): bool
    {
        return false;
    }

    public function retry(Queue $queue, ?int $limit = null): void
    {
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        return 0;
    }

    public function getFailedCount(Queue $queue): int
    {
        return 0;
    }
}
