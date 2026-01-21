<?php

namespace Appwrite\Promises;

use Swoole\Coroutine\Channel;

class Swoole extends Promise
{
    public function __construct(?callable $executor = null)
    {
        parent::__construct($executor);
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
     * Returns a promise that completes when all passed in promises complete.
     *
     * @param iterable $promisesOrValues Array of promises and/or plain values
     * @return Promise
     */
    public static function all(iterable $promisesOrValues): Promise
    {
        return self::create(function (callable $resolve, callable $reject) use ($promisesOrValues) {
            $result = [];
            $error = null;
            $promiseCount = 0;
            $key = 0;

            // First pass: count promises and store plain values
            $promiseKeys = [];
            foreach ($promisesOrValues as $promiseOrValue) {
                if ($promiseOrValue instanceof Promise) {
                    $promiseKeys[] = $key;
                    $promiseCount++;
                } else {
                    $result[$key] = $promiseOrValue;
                }
                $key++;
            }

            // If no promises, resolve immediately
            if ($promiseCount === 0) {
                \ksort($result);
                $resolve($result);
                return;
            }

            $channel = new Channel($promiseCount);
            $key = 0;

            foreach ($promisesOrValues as $promiseOrValue) {
                if ($promiseOrValue instanceof Promise) {
                    $currentKey = $key;
                    $promiseOrValue->then(
                        function ($value) use ($currentKey, &$result, $channel) {
                            $result[$currentKey] = $value;
                            $channel->push(true);
                            return $value;
                        },
                        function ($err) use ($channel, &$error) {
                            $channel->push(true);
                            if ($error === null) {
                                $error = $err;
                            }
                        }
                    );
                }
                $key++;
            }

            // Wait for all promises
            $remaining = $promiseCount;
            while ($remaining-- > 0) {
                $channel->pop();
            }
            $channel->close();

            if ($error !== null) {
                $reject($error);
                return;
            }

            \ksort($result);
            $resolve($result);
        });
    }
}
