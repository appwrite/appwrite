<?php

namespace Appwrite\Promises;

class Swoole extends Promise
{
    /**
     * Callbacks waiting for this promise to settle
     * Each entry is [Promise, callable|null, callable|null]
     *
     * @var array<array{self, callable|null, callable|null}>
     */
    protected array $waiting = [];

    public function __construct(?callable $executor = null)
    {
        if ($executor === null) {
            return;
        }

        $resolve = function ($value) {
            $this->doResolve($value);
        };
        $reject = function ($reason) {
            $this->doReject($reason);
        };

        $this->execute($executor, $resolve, $reject);
    }

    protected function execute(
        callable $executor,
        callable $resolve,
        callable $reject
    ): void {
        \go(function () use ($executor, $resolve, $reject) {
            try {
                $executor($resolve, $reject);
            } catch (\Throwable $exception) {
                $reject($exception);
            }
        });
    }

    /**
     * Internal resolve that triggers waiting callbacks
     */
    protected function doResolve(mixed $value): void
    {
        if ($this->state !== self::STATE_PENDING) {
            return;
        }

        // Handle thenable values
        if (\is_object($value) && \method_exists($value, 'then')) {
            $value->then(
                fn($v) => $this->doResolve($v),
                fn($r) => $this->doReject($r)
            );
            return;
        }

        $this->result = $value;
        $this->state = self::STATE_FULFILLED;
        $this->processWaiting();
    }

    /**
     * Internal reject that triggers waiting callbacks
     */
    protected function doReject(mixed $reason): void
    {
        if ($this->state !== self::STATE_PENDING) {
            return;
        }

        $this->result = $reason;
        $this->state = self::STATE_REJECTED;
        $this->processWaiting();
    }

    /**
     * Process all waiting callbacks
     */
    protected function processWaiting(): void
    {
        foreach ($this->waiting as [$promise, $onFulfilled, $onRejected]) {
            $callback = $this->state === self::STATE_FULFILLED ? $onFulfilled : $onRejected;

            if ($callback === null) {
                // Pass through the value/reason
                if ($this->state === self::STATE_FULFILLED) {
                    $promise->doResolve($this->result);
                } else {
                    $promise->doReject($this->result);
                }
            } else {
                // Run callback synchronously
                try {
                    $result = $callback($this->result);
                    $promise->doResolve($result);
                } catch (\Throwable $e) {
                    $promise->doReject($e);
                }
            }
        }
        $this->waiting = [];
    }

    /**
     * Override then to use callback-based approach instead of busy-waiting
     */
    public function then(
        ?callable $onFulfilled = null,
        ?callable $onRejected = null
    ): self {
        $promise = new self();

        if ($this->state === self::STATE_PENDING) {
            // Queue the callbacks for later
            $this->waiting[] = [$promise, $onFulfilled, $onRejected];
        } else {
            // Already settled, process immediately
            $callback = $this->state === self::STATE_FULFILLED ? $onFulfilled : $onRejected;

            if ($callback === null) {
                if ($this->state === self::STATE_FULFILLED) {
                    $promise->doResolve($this->result);
                } else {
                    $promise->doReject($this->result);
                }
            } else {
                // Run callback synchronously
                try {
                    $result = $callback($this->result);
                    $promise->doResolve($result);
                } catch (\Throwable $e) {
                    $promise->doReject($e);
                }
            }
        }

        return $promise;
    }

    /**
     * Override resolve to use internal method
     */
    public function resolve(mixed $value): self
    {
        $this->doResolve($value);
        return $this;
    }

    /**
     * Override reject to use internal method
     */
    public function reject(mixed $reason): self
    {
        $this->doReject($reason);
        return $this;
    }

    /**
     * Returns a promise that completes when all passed in promises complete.
     *
     * @param iterable $promisesOrValues Array of promises and/or plain values
     * @return self
     */
    public static function all(iterable $promisesOrValues): self
    {
        $promisesOrValues = \is_array($promisesOrValues)
            ? $promisesOrValues
            : \iterator_to_array($promisesOrValues);

        $total = \count($promisesOrValues);
        $promise = new self();

        if ($total === 0) {
            $promise->doResolve([]);
            return $promise;
        }

        $count = 0;
        $result = [];
        $rejected = false;

        $resolveIfDone = static function () use (&$count, $total, &$result, &$rejected, $promise): void {
            if (!$rejected && $count === $total) {
                \ksort($result);
                $promise->doResolve($result);
            }
        };

        foreach ($promisesOrValues as $index => $promiseOrValue) {
            if ($promiseOrValue instanceof Promise) {
                $result[$index] = null;
                $promiseOrValue->then(
                    static function ($value) use (&$result, $index, &$count, $resolveIfDone) {
                        $result[$index] = $value;
                        ++$count;
                        $resolveIfDone();
                        return $value;
                    },
                    static function ($error) use (&$rejected, $promise) {
                        if (!$rejected) {
                            $rejected = true;
                            $promise->doReject($error);
                        }
                    }
                );
            } else {
                $result[$index] = $promiseOrValue;
                ++$count;
            }
        }

        $resolveIfDone();

        return $promise;
    }
}
