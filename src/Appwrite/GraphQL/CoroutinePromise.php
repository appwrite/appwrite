<?php

namespace Appwrite\GraphQL;

use Swoole\Coroutine\Channel;
use function Co\go;

/**
 * Inspired by https://github.com/streamcommon/promise/blob/master/lib/ExtSwoolePromise.php
 *
 * @package Appwrite\GraphQL
 */
class CoroutinePromise
{
    const STATE_PENDING = 1;
    const STATE_FULFILLED = 0;
    const STATE_REJECTED = -1;

    protected int $state = self::STATE_PENDING;

    private mixed $result;

    public function __construct(?callable $executor = null)
    {
        if (\is_null($executor)) {
            return;
        }
        if (!\extension_loaded('swoole')) {
            throw new \RuntimeException('Swoole ext missing!');
        }
        $resolve = function ($value) {
            $this->setResult($value);
            $this->setState(self::STATE_FULFILLED);
        };
        $reject = function ($value) {
            if ($this->isPending()) {
                $this->setResult($value);
                $this->setState(self::STATE_REJECTED);
            }
        };

        go(function (callable $executor, callable $resolve, callable $reject) {
            try {
                $executor($resolve, $reject);
            } catch (\Throwable $exception) {
                $reject($exception);
            }
        }, $executor, $resolve, $reject);
    }

    /**
     * Create a new promise from the given callable.
     *
     * @param callable $promise
     * @return CoroutinePromise
     */
    final public static function create(callable $promise): CoroutinePromise
    {
        return new static($promise);
    }

    /**
     * Resolve promise with given value.
     *
     * @param mixed $value
     * @return CoroutinePromise
     */
    final public static function resolve(mixed $value): CoroutinePromise
    {
        return new static(function (callable $resolve) use ($value) {
            $resolve($value);
        });
    }

    /**
     * Rejects the promise with the given reason.
     *
     * @param mixed $value
     * @return CoroutinePromise
     */
    final public static function reject(mixed $value): CoroutinePromise
    {
        return new static(function (callable $resolve, callable $reject) use ($value) {
            $reject($value);
        });
    }

    /**
     * Catch any exception thrown by the executor.
     *
     * @param callable $onRejected
     * @return CoroutinePromise
     */
    final public function catch(callable $onRejected): CoroutinePromise
    {
        return $this->then(null, $onRejected);
    }

    /**
     * Execute the promise.
     *
     * @param callable|null $onFulfilled
     * @param callable|null $onRejected
     * @return CoroutinePromise
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): CoroutinePromise
    {
        return self::create(function (callable $resolve, callable $reject) use ($onFulfilled, $onRejected) {
            while ($this->isPending()) {
                usleep(25000);
            }
            $callable = $this->isFulfilled() ? $onFulfilled : $onRejected;
            if (!is_callable($callable)) {
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
     * @param iterable|CoroutinePromise[] $promises
     * @return CoroutinePromise
     */
    public static function all(iterable $promises): CoroutinePromise
    {
        return self::create(function (callable $resolve, callable $reject) use ($promises) {
            $ticks = count($promises);

            $firstError = null;
            $channel = new Channel($ticks);
            $result = [];
            $key = 0;

            foreach ($promises as $promise) {
                if (!$promise instanceof CoroutinePromise) {
                    $channel->close();
                    throw new \RuntimeException(
                        'Supported only Appwrite\GraphQL\SwoolePromise instance'
                    );
                }
                $promise->then(function ($value) use ($key, $result, $channel) {
                    $result[$key] = $value;
                    $channel->push(true);
                    return $value;
                }, function ($error) use ($channel, &$firstError) {
                    $channel->push(true);
                    if ($firstError === null) {
                        $firstError = $error;
                    }
                });
                $key++;
            }
            while ($ticks--) {
                $channel->pop();
            }
            $channel->close();

            if ($firstError !== null) {
                $reject($firstError);
                return;
            }
            $resolve($result);
        });
    }

    /**
     * Set resolved result
     *
     * @param mixed $value
     * @return void
     */
    private function setResult(mixed $value): void
    {
        if (!$value instanceof CoroutinePromise) {
            $this->result = $value;
            return;
        }

        $resolved = false;
        $callable = function ($value) use (&$resolved) {
            $this->setResult($value);
            $resolved = true;
        };
        $value->then($callable, $callable);

        while (!$resolved) {
            usleep(25000);
        }
    }

    /**
     * Change promise state
     *
     * @param integer $state
     * @return void
     */
    final protected function setState(int $state): void
    {
        $this->state = $state;
    }

    /**
     * Promise is pending
     *
     * @return boolean
     */
    final protected function isPending(): bool
    {
        return $this->state == self::STATE_PENDING;
    }

    /**
     * Promise is fulfilled
     *
     * @return boolean
     */
    final protected function isFulfilled(): bool
    {
        return $this->state == self::STATE_FULFILLED;
    }
}