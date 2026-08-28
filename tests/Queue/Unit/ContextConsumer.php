<?php

declare(strict_types=1);

namespace Tests\Unit;

use Swoole\Coroutine;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

final class ContextConsumer implements Consumer
{
    /** @var list<string> */
    public array $committed = [];

    /** @var list<string> */
    public array $rejected = [];

    /** @param list<Message> $messages */
    public function __construct(private array $messages) {}

    #[\Override]
    public function receive(Queue $queue, int $timeout): ?Message
    {
        unset($queue, $timeout);

        if ($this->messages === []) {
            if (\extension_loaded('swoole') && Coroutine::getCid() !== -1) {
                Coroutine::sleep(0.001);
            }

            return null;
        }

        return array_shift($this->messages);
    }

    #[\Override]
    public function commit(Queue $queue, Message $message): void
    {
        $this->committed[] = $queue->name . ':' . $message->getPid();
    }

    #[\Override]
    public function reject(Queue $queue, Message $message): void
    {
        $this->rejected[] = $queue->name . ':' . $message->getPid();
    }

    #[\Override]
    public function close(): void {}
}
