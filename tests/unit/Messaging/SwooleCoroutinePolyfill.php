<?php

/**
 * Fiber-backed polyfill for the small slice of the Swoole coroutine API the messaging worker uses
 * (`Swoole\Coroutine\batch`, `Swoole\Coroutine\run`, `Swoole\Coroutine\Channel`, `Swoole\Coroutine::sleep`).
 *
 * Swoole 6.x cannot be compiled against the PHP 8.5 ZTS build used in this environment, so the worker's
 * concurrency primitives are exercised here with cooperative fibers instead. Every definition is guarded by
 * `extension_loaded('swoole')`, so this file is completely inert when the real extension is present (CI).
 *
 * The scheduler models Swoole's blocking-acquire semantics faithfully: when a task throws
 * {@see \Utopia\Lock\Exception\Contention} (the synchronous Semaphore path's signal that all permits are
 * held), the task is suspended and retried on a later tick — exactly as a blocking `acquire` would wait for a
 * permit. Because `Semaphore::withLock` acquires before invoking its callback, a retried task never
 * double-executes its body, which the test suite asserts.
 */

namespace Swoole {
    if (! \extension_loaded('swoole')) {
        class Coroutine
        {
            public static ?\Fiber $current = null;

            public static function getCid(): int
            {
                return self::$current !== null ? 1 : -1;
            }

            public static function sleep(float $seconds): void
            {
                if (self::$current !== null) {
                    \Fiber::suspend();
                }
            }
        }
    }
}

namespace Swoole\Coroutine {
    if (! \extension_loaded('swoole')) {
        /**
         * Minimal coroutine channel. Used by {@see \Utopia\Lock\Semaphore} as its permit store.
         */
        class Channel
        {
            /**
             * @var array<mixed>
             */
            private array $buffer = [];

            public function __construct(private readonly int $capacity = 1)
            {
            }

            public function push(mixed $data, float $timeout = 0): bool
            {
                // Enforce capacity so a full channel reports back-pressure exactly like a real Swoole channel.
                // A full Semaphore permit channel then fails to acquire, which surfaces as a
                // {@see \Utopia\Lock\Exception\Contention} that the batch() scheduler defers to a later tick —
                // making the concurrency bound genuinely enforced rather than silently unbounded.
                if (\count($this->buffer) >= $this->capacity) {
                    return false;
                }

                $this->buffer[] = $data;

                return true;
            }

            public function pop(float $timeout = 0): mixed
            {
                return \array_shift($this->buffer);
            }

            public function isFull(): bool
            {
                return \count($this->buffer) >= $this->capacity;
            }

            public function isEmpty(): bool
            {
                return \count($this->buffer) === 0;
            }
        }
    }
}

namespace Swoole\Coroutine {
    use Swoole\Coroutine;
    use Utopia\Lock\Exception\Contention;

    if (! \function_exists('Swoole\Coroutine\run')) {
        function run(callable $callback): void
        {
            $fiber = new \Fiber($callback);
            $previous = Coroutine::$current;
            Coroutine::$current = $fiber;
            $fiber->start();

            while (! $fiber->isTerminated()) {
                $fiber->resume();
            }

            Coroutine::$current = $previous;
        }
    }

    if (! \function_exists('Swoole\Coroutine\batch')) {
        /**
         * @param array<callable> $tasks
         * @return array<mixed>
         */
        function batch(array $tasks, float $timeout = -1): array
        {
            $results = [];
            $done = [];
            $fibers = [];

            foreach ($tasks as $key => $task) {
                $fibers[$key] = new \Fiber($task);
            }

            $previous = Coroutine::$current;

            while (\count($done) < \count($tasks)) {
                $progressed = false;

                foreach ($fibers as $key => $fiber) {
                    if (isset($done[$key])) {
                        continue;
                    }

                    Coroutine::$current = $fiber;

                    try {
                        if (! $fiber->isStarted()) {
                            $fiber->start();
                        } elseif (! $fiber->isTerminated()) {
                            $fiber->resume();
                        }

                        // Any successful start/resume advances a fiber (toward a yield or termination), which
                        // counts as progress; fibers advance monotonically so this cannot spin forever.
                        $progressed = true;

                        if ($fiber->isTerminated()) {
                            $results[$key] = $fiber->getReturn();
                            $done[$key] = true;
                        }
                    } catch (Contention) {
                        // All permits held: model a blocking acquire by retrying the task on a later tick.
                        $fibers[$key] = new \Fiber($tasks[$key]);
                        $progressed = true;
                    }
                }

                if (! $progressed) {
                    break;
                }
            }

            Coroutine::$current = $previous;
            \ksort($results);

            return $results;
        }
    }
}
