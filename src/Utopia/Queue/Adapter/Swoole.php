<?php

namespace Utopia\Queue\Adapter;

/**
 * In-tree override of utopia-php/queue Swoole adapter until per-queue
 * consume loops land in utopia-php/monorepo `packages/queue`.
 */

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Swoole\Process;
use Utopia\DI\Container;
use Utopia\Queue\Adapter;
use Utopia\Queue\Consumer;
use Utopia\Queue\Queue;

class Swoole extends Adapter
{
    protected const string CONTEXT_KEY = '__utopia__';

    /** @var Process[] */
    protected array $workers = [];

    /** @var callable[] */
    protected array $onWorkerStart = [];

    /** @var callable[] */
    protected array $onWorkerStop = [];

    protected int $maxCoroutines;

    /** @var Consumer[] */
    protected array $queueConsumers = [];

    public function __construct(
        Consumer $consumer,
        int $workerNum,
        string $queue,
        string $namespace = 'utopia-queue',
        int $maxCoroutines = 1,
        Container $resources = new Container(),
    ) {
        parent::__construct($consumer, $workerNum, $queue, $namespace, $resources);
        $this->maxCoroutines = max(1, $maxCoroutines);
    }

    public function start(): self
    {
        for ($i = 0; $i < $this->workerNum; $i++) {
            $this->spawnWorker($i);
        }

        Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);

        Coroutine\run(function (): void {
            Process::signal(SIGTERM, fn(): \Utopia\Queue\Adapter\Swoole => $this->stop());
            Process::signal(SIGINT, fn(): \Utopia\Queue\Adapter\Swoole => $this->stop());
            Process::signal(SIGCHLD, fn() => $this->reap());

            while (\count($this->workers) > 0) {
                Coroutine::sleep(1);
            }
        });

        return $this;
    }

    protected function spawnWorker(int $workerId): void
    {
        $process = new Process(function () use ($workerId): void {
            Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);

            Coroutine\run(function () use ($workerId): void {
                Process::signal(SIGTERM, function (): void {
                    $this->stopped = true;
                    $this->consumer->close();
                    foreach ($this->queueConsumers as $consumer) {
                        try {
                            $consumer->close();
                        } catch (\Throwable) {
                        }
                    }
                });

                foreach ($this->onWorkerStart as $callback) {
                    $callback((string) $workerId);
                }

                foreach ($this->onWorkerStop as $callback) {
                    $callback((string) $workerId);
                }
            });
        }, false, 0, false);

        $pid = $process->start();
        $this->workers[$pid] = $process;
    }

    #[\Override]
    public function consume(callable $messageCallback, callable $successCallback, callable $errorCallback): void
    {
        $this->consumeQueue(
            $this->queue,
            $this->maxCoroutines,
            $messageCallback,
            $successCallback,
            $errorCallback,
        );
    }

    /**
     * Receive on one loop, process each message on its own coroutine. The
     * channel caps concurrency at $maxCoroutines: push() blocks the loop while
     * the pool is full.
     *
     * A slot is reserved before the receive, never after: a message popped with
     * no capacity to run it would sit captive in this loop — out of the broker,
     * unprocessed, invisible to every idle sibling consumer — for as long as the
     * in-flight handlers hold the pool. Blocking without a message leaves it in
     * the broker for whichever consumer frees up first.
     */
    #[\Override]
    public function consumeQueue(
        Queue $queue,
        int $maxCoroutines,
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
        ?Consumer $consumer = null,
    ): void {
        $this->stopped = false;
        $consumer ??= $this->consumer;
        $this->queueConsumers[] = $consumer;
        $slots = new Channel(max(1, $maxCoroutines));
        $waitGroup = new WaitGroup();

        while (!$this->isStopped()) {
            $slots->push(true);

            $message = $this->nextMessage($errorCallback, $queue, $consumer);

            if (!$message instanceof \Utopia\Queue\Message) {
                $slots->pop();
                continue;
            }

            $waitGroup->add();

            Coroutine::create(function () use ($message, $messageCallback, $successCallback, $errorCallback, $slots, $waitGroup, $queue, $consumer): void {
                try {
                    $this->process($message, $messageCallback, $successCallback, $errorCallback, $queue, $consumer);
                } catch (\Throwable $error) {
                    error_log('Uncaught error while processing queue message: ' . $error->getMessage());
                } finally {
                    $waitGroup->done();
                    $slots->pop();
                }
            });
        }

        $waitGroup->wait();
    }

    /**
     * Independent consume loop per queue so each cap is isolated (a databases
     * loop at maxCoroutines=1 cannot share a pool with functions=8).
     *
     * @param array<int, array{queue: Queue, maxCoroutines: int, consumer?: Consumer}> $queues
     */
    #[\Override]
    public function consumeMany(
        array $queues,
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
    ): void {
        $this->stopped = false;
        $waitGroup = new WaitGroup();

        foreach ($queues as $spec) {
            $waitGroup->add();
            Coroutine::create(function () use ($spec, $messageCallback, $successCallback, $errorCallback, $waitGroup): void {
                try {
                    $this->consumeQueue(
                        $spec['queue'],
                        $spec['maxCoroutines'],
                        $messageCallback,
                        $successCallback,
                        $errorCallback,
                        $spec['consumer'] ?? null,
                    );
                } finally {
                    $waitGroup->done();
                }
            });
        }

        $waitGroup->wait();
    }

    #[\Override]
    public function context(): Container
    {
        // Each message runs in its own coroutine, so the container is created
        // lazily per coroutine and stays isolated across concurrent handlers.
        if (Coroutine::getCid() !== -1) {
            return Coroutine::getContext()[self::CONTEXT_KEY] ??= new Container($this->resources());
        }

        return $this->resources();
    }

    protected function reap(): void
    {
        while (($ret = Process::wait(false)) !== false) {
            unset($this->workers[$ret['pid']]);
        }
    }

    public function stop(): self
    {
        $this->stopped = true;

        foreach ($this->queueConsumers as $consumer) {
            try {
                $consumer->close();
            } catch (\Throwable) {
            }
        }

        foreach (array_keys($this->workers) as $pid) {
            Process::kill($pid, SIGTERM);
        }

        return $this;
    }

    public function workerStart(callable $callback): self
    {
        $this->onWorkerStart[] = $callback;
        return $this;
    }

    public function workerStop(callable $callback): self
    {
        $this->onWorkerStop[] = $callback;
        return $this;
    }
}
