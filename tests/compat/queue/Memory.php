<?php

namespace Tests\Compat\Queue;

use Tests\Compat\PhpStd\Typed;
use Utopia\Queue\Consumer;
use Utopia\Queue\Consumer\Bounded;
use Utopia\Queue\Consumer\Exclusive;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

/**
 * A consumer over a list of envelopes that records what the worker did with
 * each message: the test double the PHP tests call MemoryConsumer. Options:
 * `fail` (methods that throw: commit, reject, receive), `exclusive`,
 * `ceiling`, `maintain` ("ok", "fail" or absent).
 */
class Memory implements Consumer
{
    /** @var list<string> */
    public array $log = [];

    /** @var list<Message> */
    protected array $pending = [];

    /** @var list<string> */
    protected array $fail = [];

    public static function make(array $a): self
    {
        $consumer = match (true) {
            $a['exclusive'] ?? false => new MemoryExclusive(),
            isset($a['ceiling']) => new MemoryBounded($a['ceiling']),
            ($a['maintain'] ?? null) !== null => new MemoryMaintained($a['maintain']),
            default => new self(),
        };
        foreach ($a['messages'] ?? [] as $envelope) {
            $consumer->pending[] = new Message(Typed::decode($envelope));
        }
        $consumer->fail = $a['fail'] ?? [];

        return $consumer;
    }

    public function receive(Queue $queue, int $timeout, int $n = 1): array
    {
        if (\in_array('receive', $this->fail, true)) {
            array_splice($this->fail, (int) array_search('receive', $this->fail, true), 1);
            $this->log[] = 'receive:failed';
            throw new \RuntimeException('broker unreachable');
        }
        $batch = array_splice($this->pending, 0, max(1, $n));
        if ($batch === [] && \Swoole\Coroutine::getCid() >= 0) {
            // As MemoryConsumer: an empty receive yields, like a broker's wait.
            \Swoole\Coroutine::sleep(0.001);

            return [];
        }
        $this->log[] = 'receive:' . \count($batch);

        return $batch;
    }

    public function commit(Queue $queue, Message $message): void
    {
        $this->log[] = 'commit:' . $message->getPid();
        if (\in_array('commit', $this->fail, true)) {
            throw new \RuntimeException('commit failed');
        }
    }

    public function reject(Queue $queue, Message $message): void
    {
        $this->log[] = 'reject:' . $message->getPid() . ':' . ($message->isTerminal() ? 'terminal' : 'retry');
        if (\in_array('reject', $this->fail, true)) {
            throw new \RuntimeException('reject failed');
        }
    }

    public function close(): void
    {
        $this->log[] = 'close';
    }
}

final class MemoryExclusive extends Memory implements Exclusive
{
}

final class MemoryBounded extends Memory implements Bounded
{
    public function __construct(private readonly int $ceiling)
    {
    }

    public function inFlightCeiling(): int
    {
        return $this->ceiling;
    }
}

final class MemoryMaintained extends Memory
{
    public function __construct(private readonly string $mode)
    {
    }

    public function maintain(): void
    {
        $this->log[] = 'maintain';
        if ($this->mode === 'fail') {
            throw new \RuntimeException('pool is unreachable');
        }
    }
}
