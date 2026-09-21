<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use Swoole\Coroutine;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

/** Worker test input and outcomes; deliberately models no broker storage. */
final class MemoryConsumer implements Consumer
{
    /** @var list<Message> */
    public array $pending = [];
    /** @var list<Message> */
    public array $committed = [];
    /** @var list<Message> */
    public array $rejected = [];

    public function add(Queue $queue, array $payload): void
    {
        $this->pending[] = new Message(['pid' => bin2hex(random_bytes(8)), 'queue' => $queue->name, 'timestamp' => time(), 'payload' => $payload]);
    }

    public function receive(Queue $queue, int $timeout, int $n = 1): array
    {
        if ($this->pending === [] && Coroutine::getCid() >= 0) {
            Coroutine::sleep(0.001);
        }
        return array_splice($this->pending, 0, max(1, $n));
    }

    public function commit(Queue $queue, Message $message): void
    {
        $this->committed[] = $message;
    }

    public function reject(Queue $queue, Message $message): void
    {
        $this->rejected[] = $message;
    }

    public function close(): void {}
}
