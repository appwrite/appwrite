<?php

namespace Appwrite\GraphQL\Promises\Adapter;

use Appwrite\GraphQL\Promises\Adapter;
use Appwrite\Promises\Swoole as SwoolePromise;
use GraphQL\Executor\Promise\Promise as GQLPromise;

class Swoole extends Adapter
{
    public function create(callable $resolver): GQLPromise
    {
        $promise = new SwoolePromise(function ($resolve, $reject) use ($resolver) {
            $resolver($resolve, $reject);
        });

        return new GQLPromise($promise, $this);
    }

    public function createFulfilled($value = null): GQLPromise
    {
        // Create without executor and resolve immediately (no coroutine)
        $promise = new SwoolePromise();
        $promise->resolve($value);

        return new GQLPromise($promise, $this);
    }

    public function createRejected(\Throwable $reason): GQLPromise
    {
        // Create without executor and reject immediately (no coroutine)
        $promise = new SwoolePromise();
        $promise->reject($reason);

        return new GQLPromise($promise, $this);
    }

    public function all(iterable $promisesOrValues): GQLPromise
    {
        $promisesOrValues = \is_array($promisesOrValues) ? $promisesOrValues : \iterator_to_array($promisesOrValues);
        $total = \count($promisesOrValues);

        if ($total === 0) {
            return $this->createFulfilled([]);
        }

        // Create a promise whose executor uses Channel to wait for all input promises
        $promise = new SwoolePromise(function ($resolve, $reject) use ($promisesOrValues, $total) {
            $channel = new \Swoole\Coroutine\Channel($total);
            $result = [];
            $error = null;

            foreach ($promisesOrValues as $index => $promiseOrValue) {
                if ($promiseOrValue instanceof GQLPromise) {
                    $result[$index] = null;
                    // Spawn a coroutine to wait for each promise
                    \go(function () use ($promiseOrValue, $index, &$result, &$error, $channel) {
                        /** @var SwoolePromise $adopted */
                        $adopted = $promiseOrValue->adoptedPromise;

                        // Poll until the promise is settled
                        while ($adopted->isPending()) {
                            \Swoole\Coroutine::sleep(0.001);
                        }

                        if ($adopted->isFulfilled()) {
                            $result[$index] = $adopted->getResult();
                        } else {
                            if ($error === null) {
                                $error = $adopted->getResult();
                            }
                        }
                        $channel->push(true);
                    });
                } else {
                    $result[$index] = $promiseOrValue;
                    $channel->push(true);
                }
            }

            // Wait for all coroutines to complete
            for ($i = 0; $i < $total; $i++) {
                $channel->pop();
            }
            $channel->close();

            if ($error !== null) {
                $reject($error);
            } else {
                \ksort($result);
                $resolve($result);
            }
        });

        return new GQLPromise($promise, $this);
    }
}
