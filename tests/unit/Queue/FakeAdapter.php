<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Utopia\Queue\Adapter;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

final class FakeConsumer implements Consumer
{
    public function receive(Queue $queue, int $timeout): ?Message
    {
        return null;
    }

    public function commit(Queue $queue, Message $message): void
    {
    }

    public function reject(Queue $queue, Message $message): void
    {
    }

    public function close(): void
    {
    }
}

final class RecordingAdapter extends Adapter
{
    /**
     * @var list<array{queue: string, maxCoroutines: int}>
     */
    public array $consumed = [];

    public function __construct(string $queue = 'v1-functions')
    {
        parent::__construct(new FakeConsumer(), 1, $queue);
    }

    public function start(): self
    {
        return $this;
    }

    public function stop(): self
    {
        return $this;
    }

    public function workerStart(callable $callback): self
    {
        return $this;
    }

    public function workerStop(callable $callback): self
    {
        return $this;
    }

    public function consumeQueue(
        Queue $queue,
        int $maxCoroutines,
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
        ?Consumer $consumer = null,
    ): void {
        $this->consumed[] = [
            'queue' => $queue->name,
            'maxCoroutines' => $maxCoroutines,
        ];
    }
}
