<?php

namespace Utopia\Queue\Adapter;

use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Swoole\Process;
use Utopia\DI\Container;
use Utopia\Queue\Adapter;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

/**
 * Run-to-completion adapter for queue workers that run as Kubernetes Jobs — for
 * example Jobs that KEDA spawns off the queue depth. Unlike the long-running
 * Swoole/Workerman adapters, there is no worker pool: the current process drains
 * the queue and returns, so the Job completes. One pod is one worker.
 *
 * Producers still enqueue with any Publisher (e.g. the Redis broker); this only
 * changes how the messages are consumed.
 *
 * With Swoole loaded, the whole worker lifecycle runs inside one coroutine
 * scheduler: timers and signal watchers registered by workerStart hooks must be
 * created and cleared within the same scheduler, or Coroutine\run never returns
 * and the Job never completes. SIGTERM/SIGINT trigger stop() so pod termination
 * finishes the in-flight message instead of stranding it in the processing
 * list (pcntl conflicts with Swoole's signal handling, so Process::signal is
 * used; without Swoole, pcntl when available).
 */
class KubernetesJob extends Adapter
{
    /** @var callable[] */
    protected array $onWorkerStart = [];

    /** @var callable[] */
    protected array $onWorkerStop = [];

    public function start(): self
    {
        $lifecycle = function (): void {
            try {
                foreach ($this->onWorkerStart as $callback) {
                    $callback('0');
                }
            } finally {
                foreach ($this->onWorkerStop as $callback) {
                    $callback('0');
                }
            }
        };

        if (!\extension_loaded('swoole') || Coroutine::getCid() >= 0) {
            $lifecycle();

            return $this;
        }

        Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);

        $error = null;

        Coroutine\run(function () use (&$error, $lifecycle): void {
            try {
                $lifecycle();
            } catch (\Throwable $thrown) {
                $error = $thrown;
            } finally {
                // Message handling can leave background coroutines parked on
                // reads that never return (connection-pool keepalives, cache
                // multiplexer readers, event-bus subscribers). Coroutine\run
                // waits for every coroutine, so without cancelling them here a
                // finished worker never exits and the Job hangs until its
                // deadline instead of completing.
                foreach (Coroutine::listCoroutines() as $cid) {
                    if ($cid !== Coroutine::getCid()) {
                        Coroutine::cancel($cid);
                    }
                }
            }
        });

        if ($error instanceof \Throwable) {
            throw $error;
        }

        return $this;
    }

    /**
     * Flips the stop flag only. Closing the consumer here would break the
     * in-flight commit/reject; the blocking receive() unblocks within
     * RECEIVE_TIMEOUT, and the server's workerStop callback closes the
     * consumer once the drain returns.
     */
    public function stop(): self
    {
        $this->stopped = true;

        return $this;
    }

    /**
     * Drain each queue until empty, then return. Processes messages until a
     * receive() times out or stop() is called, so the Job completes rather
     * than blocking forever like the long-running adapters.
     *
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

        $swoole = \extension_loaded('swoole');

        if ($swoole) {
            Process::signal(SIGTERM, fn(): \Utopia\Queue\Adapter\KubernetesJob => $this->stop());
            Process::signal(SIGINT, fn(): \Utopia\Queue\Adapter\KubernetesJob => $this->stop());
        } elseif (\function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, $this->stop(...));
            pcntl_signal(SIGINT, $this->stop(...));
        }

        try {
            foreach ($queues as $spec) {
                $this->drain(
                    $spec['queue'],
                    $messageCallback,
                    $successCallback,
                    $errorCallback,
                    $spec['consumer'] ?? null,
                    $swoole,
                );
            }
        } finally {
            if ($swoole) {
                Process::signal(SIGTERM, null);
                Process::signal(SIGINT, null);
            }
        }
    }

    /**
     * @param callable(Message): void $messageCallback
     * @param callable(Message): void $successCallback
     * @param callable(?Message, \Throwable): void $errorCallback
     */
    private function drain(
        Queue $queue,
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
        ?Consumer $consumer,
        bool $swoole,
    ): void {
        $consumer ??= $this->consumer;

        while (!$this->isStopped()) {
            $message = $consumer->receive($queue, static::RECEIVE_TIMEOUT)[0] ?? null;

            if (!$message instanceof Message) {
                break;
            }

            $this->context = new Container($this->resources());

            // One child coroutine per message, awaited before the next
            // receive: sequential like the rest of the drain, but each
            // handler gets a fresh coroutine stack, exactly as the Swoole
            // adapter's per-message coroutines provide. Running handlers
            // inline reused the lifecycle coroutine's stack, and deeply
            // recursive handlers overflowed it — a segfault with no PHP
            // trace, the message stranded in the processing list.
            if ($swoole && Coroutine::getCid() >= 0) {
                $waitGroup = new WaitGroup(1);
                Coroutine::create(function () use ($waitGroup, $message, $messageCallback, $successCallback, $errorCallback, $queue, $consumer): void {
                    try {
                        $this->processFrom($message, $messageCallback, $successCallback, $errorCallback, $queue, $consumer);
                    } finally {
                        $waitGroup->done();
                    }
                });
                $waitGroup->wait();
            } else {
                $this->processFrom($message, $messageCallback, $successCallback, $errorCallback, $queue, $consumer);
            }
        }
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
