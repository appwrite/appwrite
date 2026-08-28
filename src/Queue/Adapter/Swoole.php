<?php

namespace Utopia\Queue\Adapter;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Swoole\Process;
use Utopia\DI\Container;
use Utopia\Queue\Adapter;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
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

    /** @var Consumer[] */
    protected array $consumers = [];

    public function __construct(
        Consumer|callable $consumer,
        int $workerNum,
        string $namespace = 'utopia-queue',
        Container $resources = new Container(),
    ) {
        parent::__construct($consumer, $workerNum, $namespace, $resources);
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
                    foreach ($this->consumers as $consumer) {
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

    /**
     * @param array<int, array{queue: Queue, maxCoroutines: int, consumer?: Consumer}> $queues
     */
    #[\Override]
    public function consume(
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
        array $queues,
    ): void {
        $this->stopped = false;

        if ($queues === []) {
            throw new \LogicException('At least one queue is required');
        }

        // Single queue: same hot path as pre-multi-queue main — bind
        // $this->queue/$this->consumer and keep the Coroutine::create capture
        // list identical (no per-message queue/consumer args).
        if (\count($queues) === 1) {
            $spec = $queues[0];
            $previousConsumer = $this->consumer;
            $this->queue = $spec['queue'];
            $this->consumer = $spec['consumer'] ?? $this->consumer;
            if ($this->consumer !== $previousConsumer) {
                $this->consumers[] = $this->consumer;
            }

            try {
                $this->consumeBound($spec['maxCoroutines'], $messageCallback, $successCallback, $errorCallback);
            } finally {
                $this->consumer = $previousConsumer;
            }

            return;
        }

        // Independent loop per queue so each cap is isolated (a databases loop
        // at maxCoroutines=1 cannot share a pool with functions=8).
        $waitGroup = new WaitGroup();

        foreach ($queues as $spec) {
            $waitGroup->add();
            Coroutine::create(function () use ($spec, $messageCallback, $successCallback, $errorCallback, $waitGroup): void {
                try {
                    $this->run(
                        $spec['queue'],
                        $spec['maxCoroutines'],
                        $messageCallback,
                        $successCallback,
                        $errorCallback,
                        $spec['consumer'] ?? $this->consumer,
                    );
                } finally {
                    $waitGroup->done();
                }
            });
        }

        $waitGroup->wait();
    }

    /**
     * Receive on one loop with `$this->queue` / `$this->consumer` already bound.
     * Structure matches origin/main's Swoole::consume body.
     *
     * @param callable(Message): void $messageCallback
     * @param callable(Message): void $successCallback
     * @param callable(?Message, \Throwable): void $errorCallback
     */
    protected function consumeBound(
        int $maxCoroutines,
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
    ): void {
        $slots = new Channel($maxCoroutines);
        $waitGroup = new WaitGroup();

        while (!$this->isStopped()) {
            $slots->push(true);

            $message = $this->nextMessage($errorCallback);

            if (!$message instanceof Message) {
                $slots->pop();
                continue;
            }

            $waitGroup->add();

            Coroutine::create(function () use ($message, $messageCallback, $successCallback, $errorCallback, $slots, $waitGroup): void {
                try {
                    $this->process($message, $messageCallback, $successCallback, $errorCallback);
                } catch (\Throwable $error) {
                    // process() is total; net for a stray throw so it isn't lost
                    error_log('Uncaught error while processing queue message: ' . $error->getMessage());
                } finally {
                    $this->releaseSlot($waitGroup, $slots);
                }
            });
        }

        $waitGroup->wait();
    }

    /**
     * Concurrent multi-queue loop: queue/consumer stay on the stack so sibling
     * loops do not race `$this->queue`.
     *
     * A slot is reserved before the receive, never after: a message popped with
     * no capacity to run it would sit captive in this loop — out of the broker,
     * unprocessed, invisible to every idle sibling consumer — for as long as the
     * in-flight handlers hold the pool. Blocking without a message leaves it in
     * the broker for whichever consumer frees up first.
     */
    #[\Override]
    protected function run(
        Queue $queue,
        int $maxCoroutines,
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
        Consumer $consumer,
    ): void {
        if ($consumer !== $this->consumer) {
            $this->consumers[] = $consumer;
        }

        $slots = new Channel($maxCoroutines);
        $waitGroup = new WaitGroup();

        while (!$this->isStopped()) {
            $slots->push(true);

            $message = $this->nextMessageFrom($errorCallback, $queue, $consumer);

            if (!$message instanceof Message) {
                $slots->pop();
                continue;
            }

            $waitGroup->add();

            Coroutine::create(function () use ($message, $messageCallback, $successCallback, $errorCallback, $slots, $waitGroup, $queue, $consumer): void {
                try {
                    $this->processFrom($message, $messageCallback, $successCallback, $errorCallback, $queue, $consumer);
                } catch (\Throwable $error) {
                    // processFrom() is total; net for a stray throw so it isn't lost
                    error_log('Uncaught error while processing queue message: ' . $error->getMessage());
                } finally {
                    $this->releaseSlot($waitGroup, $slots);
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

    #[\Override]
    protected function releaseContext(): void
    {
        if (Coroutine::getCid() !== -1) {
            $context = Coroutine::getContext();
            unset($context[self::CONTEXT_KEY]);
        }

        parent::releaseContext();
    }

    /**
     * Pop the concurrency slot even if wait-group accounting throws, so a
     * failed teardown cannot permanently cap the worker below maxCoroutines.
     */
    private function releaseSlot(WaitGroup $waitGroup, Channel $slots): void
    {
        try {
            $waitGroup->done();
        } finally {
            $slots->pop();
        }
    }

    protected function reap(): void
    {
        while (($ret = Process::wait(false)) !== false) {
            unset($this->workers[$ret['pid']]);
        }
    }

    public function stop(): self
    {
        // Flip the flag only — same as main. Closing consumers here races with
        // in-flight commit/reject after the handler that called stop(), and is
        // unnecessary to end the loop (the next receive returns and isStopped
        // is checked). SIGTERM still closes every consumer so a blocking
        // receive unblocks on worker shutdown.
        $this->stopped = true;

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
