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
            $total = \count($promisesOrValues);
            if ($total === 0) {
                $resolve([]);
                return;
            }

            $result = [];
            $completed = 0;
            $rejected = false;
            $channel = new Channel($total);

            foreach ($promisesOrValues as $index => $promiseOrValue) {
                if ($promiseOrValue instanceof GQLPromise) {
                    $result[$index] = null;
                    $promiseOrValue->then(
                        function ($value) use ($index, &$result, &$completed, $channel) {
                            $result[$index] = $value;
                            $completed++;
                            $channel->push(true);
                            return $value;
                        },
                        function ($error) use (&$rejected, $channel, $reject) {
                            if (!$rejected) {
                                $rejected = true;
                                $reject($error);
                            }
                            $channel->push(false);
                        }
                    );
                } else {
                    $result[$index] = $promiseOrValue;
                    $completed++;
                    $channel->push(true);
                }
            }

            // Wait for all promises to complete
            for ($i = 0; $i < $total; $i++) {
                $channel->pop();
            }
            $channel->close();

            if (!$rejected) {
                \ksort($result);
                $resolve($result);
            }
        });
    }
}
