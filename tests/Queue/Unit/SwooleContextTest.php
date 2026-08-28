<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use stdClass;
use Swoole\Coroutine;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;
use WeakReference;

final class SwooleContextTest extends TestCase
{
    public function testSuccessReleasesCoroutineContextAfterCallbacks(): void
    {
        $consumer = new ContextConsumer([$this->message('first', 'jobs')]);
        $adapter = new ContextSwoole($consumer);
        $during = [];
        $after = [];

        Coroutine\run(function () use ($adapter, &$during, &$after): void {
            $adapter->consume(
                function (Message $message) use ($adapter, &$during, &$after): void {
                    unset($message);
                    $reference = $adapter->retain();
                    $during['message'] = $adapter->hasMessageContext();
                    Coroutine::defer(function () use ($adapter, $reference, &$after): void {
                        $after['context'] = $adapter->hasMessageContext();
                        $after['alive'] = $reference->get() instanceof stdClass;
                    });
                },
                function (Message $message) use ($adapter, &$during): void {
                    unset($message);
                    $during['success'] = $adapter->hasMessageContext();
                    $adapter->stop();
                },
                function (): void {},
                [[
                    'queue' => new Queue('jobs'),
                    'maxCoroutines' => 1,
                ]],
            );
        });

        $this->assertSame(['message' => true, 'success' => true], $during);
        $this->assertSame(['context' => false, 'alive' => false], $after);
        $this->assertSame(['jobs:first'], $consumer->committed);
        $this->assertSame([], $consumer->rejected);
    }

    public function testErrorReleasesCoroutineContextAfterTheErrorCallback(): void
    {
        $consumer = new ContextConsumer([$this->message('broken', 'jobs')]);
        $adapter = new ContextSwoole($consumer);
        $during = [];
        $after = [];
        $errors = [];

        Coroutine\run(function () use ($adapter, &$during, &$after, &$errors): void {
            $adapter->consume(
                function (Message $message) use ($adapter, &$during, &$after): never {
                    unset($message);
                    $reference = $adapter->retain();
                    $during['message'] = $adapter->hasMessageContext();
                    Coroutine::defer(function () use ($adapter, $reference, &$after): void {
                        $after['context'] = $adapter->hasMessageContext();
                        $after['alive'] = $reference->get() instanceof stdClass;
                    });

                    throw new \RuntimeException('handler failed');
                },
                function (): void {},
                function (?Message $message, \Throwable $error) use ($adapter, &$during, &$errors): void {
                    $during['error'] = $adapter->hasMessageContext();
                    $errors[] = [$message?->getPid(), $error->getMessage()];
                    $adapter->stop();
                },
                [[
                    'queue' => new Queue('jobs'),
                    'maxCoroutines' => 1,
                ]],
            );
        });

        $this->assertSame(['message' => true, 'error' => true], $during);
        $this->assertSame(['context' => false, 'alive' => false], $after);
        $this->assertSame([], $consumer->committed);
        $this->assertSame(['jobs:broken'], $consumer->rejected);
        $this->assertSame([['broken', 'handler failed']], $errors);
    }

    public function testEachQueueReleasesItsOwnCoroutineContext(): void
    {
        $first = new ContextConsumer([$this->message('first-message', 'first')]);
        $second = new ContextConsumer([$this->message('second-message', 'second')]);
        $adapter = new ContextSwoole(new ContextConsumer([]));
        $during = [];
        $after = [];
        $successes = [];

        Coroutine\run(function () use ($adapter, $first, $second, &$during, &$after, &$successes): void {
            $adapter->consume(
                function (Message $message) use ($adapter, &$during, &$after): void {
                    $reference = $adapter->retain();
                    $during[$message->getPid()] = $adapter->hasMessageContext();
                    Coroutine::defer(function () use ($adapter, $message, $reference, &$after): void {
                        $after[$message->getPid()] = [
                            'context' => $adapter->hasMessageContext(),
                            'alive' => $reference->get() instanceof stdClass,
                        ];
                    });
                },
                function (Message $message) use ($adapter, &$successes): void {
                    $successes[] = $message->getPid();
                    if (\count($successes) === 2) {
                        $adapter->stop();
                    }
                },
                function (): void {},
                [
                    [
                        'queue' => new Queue('first'),
                        'maxCoroutines' => 1,
                        'consumer' => $first,
                    ],
                    [
                        'queue' => new Queue('second'),
                        'maxCoroutines' => 1,
                        'consumer' => $second,
                    ],
                ],
            );
        });

        ksort($during);
        ksort($after);
        sort($successes);

        $this->assertSame(['first-message' => true, 'second-message' => true], $during);
        $this->assertSame([
            'first-message' => ['context' => false, 'alive' => false],
            'second-message' => ['context' => false, 'alive' => false],
        ], $after);
        $this->assertSame(['first-message', 'second-message'], $successes);
        $this->assertSame(['first:first-message'], $first->committed);
        $this->assertSame(['second:second-message'], $second->committed);
    }

    public function testReleasedContextDoesNotConsumeCoroutineCapacity(): void
    {
        $consumer = new ContextConsumer([
            $this->message('first', 'jobs'),
            $this->message('second', 'jobs'),
        ]);
        $adapter = new ContextSwoole($consumer);
        $successes = [];

        Coroutine\run(function () use ($adapter, &$successes): void {
            $adapter->consume(
                function (Message $message) use ($adapter): void {
                    unset($message);
                    $adapter->retain();
                },
                function (Message $message) use ($adapter, &$successes): void {
                    $successes[] = $message->getPid();
                    if (\count($successes) === 2) {
                        $adapter->stop();
                    }
                },
                function (): void {},
                [[
                    'queue' => new Queue('jobs'),
                    'maxCoroutines' => 1,
                ]],
            );
        });

        $this->assertSame(['first', 'second'], $successes);
        $this->assertSame(['jobs:first', 'jobs:second'], $consumer->committed);
    }

    private function message(string $pid, string $queue): Message
    {
        return new Message([
            'pid' => $pid,
            'queue' => $queue,
            'timestamp' => 1,
            'payload' => [],
        ]);
    }
}

final class ContextSwoole extends Swoole
{
    public function __construct(Consumer $consumer)
    {
        parent::__construct($consumer, 1);
    }

    public function hasMessageContext(): bool
    {
        if (Coroutine::getCid() === -1) {
            return false;
        }

        return isset(Coroutine::getContext()[self::CONTEXT_KEY]);
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
