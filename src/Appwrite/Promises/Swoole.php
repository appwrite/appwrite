<?php

namespace Appwrite\Promises;

/**
 * Swoole-compatible promise implementation that runs synchronously
 * but can wait for external async operations via Swoole channels.
 */
class Swoole extends Promise
{
    public const PENDING = 'pending';
    public const FULFILLED = 'fulfilled';
    public const REJECTED = 'rejected';

    public string $promiseState = self::PENDING;
    public mixed $promiseResult = null;

    /**
     * Callbacks waiting for this promise to settle
     *
     * @var array<array{self, callable|null, callable|null}>
     */
    protected array $waiting = [];

    public function __construct(?callable $executor = null)
    {
        if ($executor === null) {
            return;
        }

        try {
            $executor(
                fn($value) => $this->doResolve($value),
                fn($reason) => $this->doReject($reason)
            );
        } catch (\Throwable $e) {
            $this->doReject($e);
        }
    }

    protected function execute(
        callable $executor,
        callable $resolve,
        callable $reject
    ): void {
        // Not used - we execute synchronously in constructor
    }

    protected function doResolve(mixed $value): void
    {
        if ($this->promiseState !== self::PENDING) {
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

        $this->promiseState = self::FULFILLED;
        $this->promiseResult = $value;
        $this->processWaiting();
    }

    protected function doReject(mixed $reason): void
    {
        if ($this->promiseState !== self::PENDING) {
            return;
        }

        $this->promiseState = self::REJECTED;
        $this->promiseResult = $reason;
        $this->processWaiting();
    }

    protected function processWaiting(): void
    {
        foreach ($this->waiting as [$promise, $onFulfilled, $onRejected]) {
            $callback = $this->promiseState === self::FULFILLED ? $onFulfilled : $onRejected;

            if ($callback === null) {
                if ($this->promiseState === self::FULFILLED) {
                    $promise->doResolve($this->promiseResult);
                } else {
                    $promise->doReject($this->promiseResult);
                }
            } else {
                try {
                    $result = $callback($this->promiseResult);
                    $promise->doResolve($result);
                } catch (\Throwable $e) {
                    $promise->doReject($e);
                }
            }
        }
        $this->waiting = [];
    }

    public function then(
        ?callable $onFulfilled = null,
        ?callable $onRejected = null
    ): self {
        $promise = new self();

        if ($this->promiseState === self::PENDING) {
            $this->waiting[] = [$promise, $onFulfilled, $onRejected];
        } else {
            $callback = $this->promiseState === self::FULFILLED ? $onFulfilled : $onRejected;

            if ($callback === null) {
                if ($this->promiseState === self::FULFILLED) {
                    $promise->doResolve($this->promiseResult);
                } else {
                    $promise->doReject($this->promiseResult);
                }
            } else {
                try {
                    $result = $callback($this->promiseResult);
                    $promise->doResolve($result);
                } catch (\Throwable $e) {
                    $promise->doReject($e);
                }
            }
        }

        return $promise;
    }

    public function resolve(mixed $value): self
    {
        $this->doResolve($value);
        return $this;
    }

    public function reject(mixed $reason): self
    {
        $this->doReject($reason);
        return $this;
    }

    public function isPending(): bool
    {
        return $this->promiseState === self::PENDING;
    }

    public function isFulfilled(): bool
    {
        return $this->promiseState === self::FULFILLED;
    }

    public function isRejected(): bool
    {
        return $this->promiseState === self::REJECTED;
    }

    public function getResult(): mixed
    {
        return $this->promiseResult;
    }

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

        $checkComplete = static function () use (&$count, $total, &$result, &$rejected, $promise): void {
            if (!$rejected && $count === $total) {
                \ksort($result);
                $promise->doResolve($result);
            }
        };

        foreach ($promisesOrValues as $index => $promiseOrValue) {
            if ($promiseOrValue instanceof self) {
                $result[$index] = null;
                $promiseOrValue->then(
                    static function ($value) use (&$result, $index, &$count, $checkComplete) {
                        $result[$index] = $value;
                        ++$count;
                        $checkComplete();
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

        $checkComplete();

        return $promise;
    }
}
