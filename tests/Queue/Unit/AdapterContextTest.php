<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use stdClass;
use Utopia\DI\Container;
use Utopia\Queue\Adapter;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;
use WeakReference;

final class AdapterContextTest extends TestCase
{
    public function testConsumeReleasesTheLastMessageContext(): void
    {
        $consumer = new ContextConsumer([$this->message('first')]);
        $adapter = new SequentialContextAdapter($consumer);
        $heldDuringSuccess = false;
        $reference = null;

        $adapter->consume(
            function (Message $message) use ($adapter, &$reference): void {
                unset($message);
                $reference = $adapter->retain();
            },
            function () use ($adapter, &$heldDuringSuccess): void {
                $heldDuringSuccess = $adapter->holdingContext();
                $adapter->stop();
            },
            function (): void {},
            [[
                'queue' => new Queue('jobs'),
                'maxCoroutines' => 1,
            ]],
        );

        $this->assertTrue($heldDuringSuccess);
        $this->assertFalse($adapter->holdingContext());
        $this->assertInstanceOf(WeakReference::class, $reference);
        $this->assertNull($reference->get());
        $this->assertSame(['jobs:first'], $consumer->committed);
        $this->assertSame([], $consumer->rejected);
    }

    public function testFailedMessageReleasesContextAfterTheErrorCallback(): void
    {
        $consumer = new ContextConsumer([$this->message('broken')]);
        $adapter = new SequentialContextAdapter($consumer);
        $heldDuringError = false;
        $reference = null;
        $errors = [];

        $adapter->consume(
            function (Message $message) use ($adapter, &$reference): never {
                unset($message);
                $reference = $adapter->retain();

                throw new \RuntimeException('handler failed');
            },
            function (): void {},
            function (?Message $message, \Throwable $error) use ($adapter, &$heldDuringError, &$errors): void {
                $heldDuringError = $adapter->holdingContext();
                $errors[] = [$message?->getPid(), $error->getMessage()];
                $adapter->stop();
            },
            [[
                'queue' => new Queue('jobs'),
                'maxCoroutines' => 1,
            ]],
        );

        $this->assertTrue($heldDuringError);
        $this->assertFalse($adapter->holdingContext());
        $this->assertInstanceOf(WeakReference::class, $reference);
        $this->assertNull($reference->get());
        $this->assertSame([], $consumer->committed);
        $this->assertSame(['jobs:broken'], $consumer->rejected);
        $this->assertSame([['broken', 'handler failed']], $errors);
    }

    private function message(string $pid): Message
    {
        return new Message([
            'pid' => $pid,
            'queue' => 'jobs',
            'timestamp' => 1,
            'payload' => [],
        ]);
    }
}

final class SequentialContextAdapter extends Adapter
{
    public function __construct(Consumer $consumer)
    {
        parent::__construct($consumer, 1);
    }

    public function start(): self
    {
        return $this;
    }

    public function stop(): self
    {
        $this->stopped = true;

        return $this;
    }

    public function workerStart(callable $callback): self
    {
        unset($callback);

        return $this;
    }

    public function workerStop(callable $callback): self
    {
        unset($callback);

        return $this;
    }

    public function holdingContext(): bool
    {
        return $this->context instanceof Container;
    }

    public function retain(): WeakReference
    {
        $resource = new stdClass();
        $reference = WeakReference::create($resource);
        $this->context()->set('resource', static fn(): stdClass => $resource);
        $this->context()->get('resource');

        return $reference;
    }
}
