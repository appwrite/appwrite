<?php

namespace Appwrite\GraphQL\Promises\Adapter;

use Appwrite\GraphQL\Promises\Adapter;
use Appwrite\Promises\Swoole as SwoolePromise;
use GraphQL\Executor\Promise\Promise as GQLPromise;
use Swoole\Coroutine\Channel;

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
        $promise = new SwoolePromise(function ($resolve, $reject) use ($value) {
            $resolve($value);
        });

        return new GQLPromise($promise, $this);
    }

    public function createRejected(\Throwable $reason): GQLPromise
    {
        $promise = new SwoolePromise(function ($resolve, $reject) use ($reason) {
            $reject($reason);
        });

        return new GQLPromise($promise, $this);
    }

    public function all(iterable $promisesOrValues): GQLPromise
    {
        $promisesOrValues = \is_array($promisesOrValues) ? $promisesOrValues : \iterator_to_array($promisesOrValues);

        return $this->create(function (callable $resolve, callable $reject) use ($promisesOrValues) {
            $count = \count($promisesOrValues);
            if ($count === 0) {
                $resolve([]);
                return;
            }

            $result = [];
            $error = null;
            $channel = new Channel($count);

            foreach ($promisesOrValues as $index => $promiseOrValue) {
                if ($promiseOrValue instanceof GQLPromise) {
                    // Spawn a coroutine to wait for each promise
                    \go(function () use ($promiseOrValue, $index, &$result, &$error, $channel) {
                        /** @var SwoolePromise $adoptedPromise */
                        $adoptedPromise = $promiseOrValue->adoptedPromise;

                        // Wait for the promise to resolve using a polling approach
                        while ($adoptedPromise->isPending()) {
                            \Swoole\Coroutine::sleep(0.001);
                        }

                        if ($adoptedPromise->isFulfilled()) {
                            $result[$index] = $adoptedPromise->getResult();
                        } else {
                            if ($error === null) {
                                $error = $adoptedPromise->getResult();
                            }
                        }
                        $channel->push(true);
                    });
                } else {
                    $result[$index] = $promiseOrValue;
                    $channel->push(true);
                }
            }

            // Wait for all promises to complete
            for ($i = 0; $i < $count; $i++) {
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
