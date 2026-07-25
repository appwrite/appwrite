<?php

namespace Appwrite\Promises;

/** @phpstan-consistent-constructor */
abstract class Promise
{
    protected const STATE_PENDING = 1;
    protected const STATE_FULFILLED = 0;
    protected const STATE_REJECTED = -1;

    protected int $state = self::STATE_PENDING;

    private mixed $result;

    public function __construct(?callable $executor = null)
    {
        if (\is_null($executor)) {
            return;
        }
        $resolve = function ($value) {
            $this->setState($this->setResult($value));
        };
        $reject = function ($value) {
            $this->setResult($value);
            $this->setState(self::STATE_REJECTED);
        };
        $this->execute($executor, $resolve, $reject);
    }

    abstract protected function execute(
        callable $executor,
        callable $resolve,
        callable $reject
    ): void;

    /**
     * Create a new promise from the given callable.
     *
     * @param callable $promise
     * @return self
     */
    public static function create(callable $promise): self
    {
        return new static($promise);
    }

    /**
     * Resolve promise with given value.
     *
     * @param mixed $value
     * @return self
     */
    public static function resolve(mixed $value): self
    {
        return new static(function (callable $resolve) use ($value) {
            $resolve($value);
        });
    }

    /**
     * Rejects the promise with the given reason.
     *
     * @param mixed $value
     * @return self
     */
    public static function reject(mixed $value): self
    {
        return new static(function (callable $resolve, callable $reject) use ($value) {
            $reject($value);
        });
    }

    /**
     * Catch any exception thrown by the executor.
     *
     * @param callable $onRejected
     * @return self
     */
    public function catch(callable $onRejected): self
    {
        return $this->then(null, $onRejected);
    }

    /**
     * Execute the promise.
     *
     * @param callable|null $onFulfilled
     * @param callable|null $onRejected
     * @return self
     */
    public function then(
        ?callable $onFulfilled = null,
        ?callable $onRejected = null
    ): self {
        if ($this->isRejected() && $onRejected === null) {
            return $this;
        }
        if ($this->isFulfilled() && $onFulfilled === null) {
            return $this;
        }
        return self::create(function (callable $resolve, callable $reject) use ($onFulfilled, $onRejected) {
            while ($this->isPending()) {
                usleep(25000);
            }
            $callable = $this->isFulfilled() ? $onFulfilled : $onRejected;
            if (!\is_callable($callable)) {
                if ($this->isRejected()) {
                    $reject($this->result);
                    return;
                }

                $resolve($this->result);
                return;
            }
            try {
                $resolve($callable($this->result));
            } catch (\Throwable $error) {
                $reject($error);
            }
        });
    }

    /**
     * Returns a promise that completes when all passed in promises complete.
     *
     * @param iterable|self[] $promises
     * @return self
     */
    abstract public static function all(iterable $promises): self;

    /**
     * Set the resolved result, adopting nested promises while preserving
     * whether the adopted promise fulfilled or rejected.
     *
     * @param mixed $value
     * @return int
     */
    protected function setResult(mixed $value): int
    {
        if (!\is_callable([$value, 'then'])) {
            $this->result = $value;
            return self::STATE_FULFILLED;
        }

        $state = self::STATE_PENDING;

        $value->then(
            function ($value) use (&$state) {
                $state = $this->setResult($value);
            },
            function ($value) use (&$state) {
                $this->result = $value;
                $state = self::STATE_REJECTED;
            }
        );

        while ($state === self::STATE_PENDING) {
            usleep(25000);
        }

        return $state;
    }

    /**
     * Change promise state
     *
     * @param integer $state
     * @return void
     */
    protected function setState(int $state): void
    {
        $this->state = $state;
    }

    /**
     * Promise is pending
     *
     * @return boolean
     */
    protected function isPending(): bool
    {
        return $this->state == self::STATE_PENDING;
    }

    /**
     * Promise is fulfilled
     *
     * @return boolean
     */
    protected function isFulfilled(): bool
    {
        return $this->state == self::STATE_FULFILLED;
    }

    /**
     * Promise is rejected
     *
     * @return boolean
     */
    protected function isRejected(): bool
    {
        return $this->state == self::STATE_REJECTED;
    }
}
