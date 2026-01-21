<?php

namespace Appwrite\Promises;

/**
 * Swoole-compatible promise implementation that executes callbacks synchronously.
 * This works with Swoole's WaitGroup pattern used in the GraphQL controller.
 */
class Swoole extends Promise
{
    public const PENDING = 'pending';
    public const FULFILLED = 'fulfilled';
    public const REJECTED = 'rejected';

    public string $state = self::PENDING;
    public mixed $result = null;

    /**
     * Promises created in `then` method of this promise and awaiting resolution
     *
     * @var array<array{self, callable|null, callable|null}>
     */
    protected array $waiting = [];

    public function __construct(?callable $executor = null)
    {
        if ($executor === null) {
            return;
        }

        // Execute the executor synchronously
        try {
            $executor(
                fn ($value) => $this->resolve($value),
                fn ($reason) => $this->reject($reason)
            );
        } catch (\Throwable $e) {
            $this->reject($e);
        }
    }

    protected function execute(
        callable $executor,
        callable $resolve,
        callable $reject
    ): void {
        // Not used - we execute synchronously in constructor
    }

    /**
     * Resolve the promise with a value
     */
    public function resolve(mixed $value): self
    {
        if ($this->state !== self::PENDING) {
            return $this;
        }

        // Handle thenable values
        if (\is_object($value) && \method_exists($value, 'then')) {
            $value->then(
                fn ($v) => $this->resolve($v),
                fn ($r) => $this->reject($r)
            );
            return $this;
        }

        $this->state = self::FULFILLED;
        $this->result = $value;
        $this->processWaiting();

        return $this;
    }

    /**
     * Reject the promise with a reason
     */
    public function reject(mixed $reason): self
    {
        if ($this->state !== self::PENDING) {
            return $this;
        }

        $this->state = self::REJECTED;
        $this->result = $reason;
        $this->processWaiting();

        return $this;
    }

    /**
     * Process waiting callbacks immediately (synchronously)
     */
    protected function processWaiting(): void
    {
        foreach ($this->waiting as [$promise, $onFulfilled, $onRejected]) {
            if ($this->state === self::FULFILLED) {
                try {
                    $promise->resolve($onFulfilled === null ? $this->result : $onFulfilled($this->result));
                } catch (\Throwable $e) {
                    $promise->reject($e);
                }
            } elseif ($this->state === self::REJECTED) {
                try {
                    if ($onRejected === null) {
                        $promise->reject($this->result);
                    } else {
                        $promise->resolve($onRejected($this->result));
                    }
                } catch (\Throwable $e) {
                    $promise->reject($e);
                }
            }
        }

        $this->waiting = [];
    }

    public function then(
        ?callable $onFulfilled = null,
        ?callable $onRejected = null
    ): self {
        if ($this->state === self::REJECTED && $onRejected === null) {
            return $this;
        }

        if ($this->state === self::FULFILLED && $onFulfilled === null) {
            return $this;
        }

        $promise = new self();

        if ($this->state === self::PENDING) {
            // Promise not settled yet - queue the callbacks
            $this->waiting[] = [$promise, $onFulfilled, $onRejected];
        } else {
            // Promise already settled - execute callback immediately
            if ($this->state === self::FULFILLED) {
                try {
                    $promise->resolve($onFulfilled === null ? $this->result : $onFulfilled($this->result));
                } catch (\Throwable $e) {
                    $promise->reject($e);
                }
            } else {
                try {
                    if ($onRejected === null) {
                        $promise->reject($this->result);
                    } else {
                        $promise->resolve($onRejected($this->result));
                    }
                } catch (\Throwable $e) {
                    $promise->reject($e);
                }
            }
        }

        return $promise;
    }

    public function isPending(): bool
    {
        return $this->state === self::PENDING;
    }

    public function isFulfilled(): bool
    {
        return $this->state === self::FULFILLED;
    }

    public function isRejected(): bool
    {
        return $this->state === self::REJECTED;
    }

    public function getResult(): mixed
    {
        return $this->result;
    }

    public static function all(iterable $promisesOrValues): self
    {
        $promisesOrValues = \is_array($promisesOrValues)
            ? $promisesOrValues
            : \iterator_to_array($promisesOrValues);

        $total = \count($promisesOrValues);
        $all = new self();

        if ($total === 0) {
            $all->resolve([]);
            return $all;
        }

        $count = 0;
        $result = [];
        $rejected = false;

        $resolveAllWhenFinished = static function () use (&$count, $total, $all, &$result, &$rejected): void {
            if (!$rejected && $count === $total) {
                $all->resolve($result);
            }
        };

        foreach ($promisesOrValues as $index => $promiseOrValue) {
            if ($promiseOrValue instanceof self) {
                $result[$index] = null;
                $promiseOrValue->then(
                    static function ($value) use (&$result, $index, &$count, $resolveAllWhenFinished) {
                        $result[$index] = $value;
                        ++$count;
                        $resolveAllWhenFinished();
                    },
                    static function ($error) use (&$rejected, $all) {
                        if (!$rejected) {
                            $rejected = true;
                            $all->reject($error);
                        }
                    }
                );
            } else {
                $result[$index] = $promiseOrValue;
                ++$count;
            }
        }

        $resolveAllWhenFinished();

        return $all;
    }

    /**
     * Static queue methods for graphql-php compatibility (not used in sync mode)
     */
    public static function runQueue(): void
    {
        // No-op in synchronous mode
    }

    public static function getQueue(): \SplQueue
    {
        static $queue;
        return $queue ??= new \SplQueue();
    }
}
