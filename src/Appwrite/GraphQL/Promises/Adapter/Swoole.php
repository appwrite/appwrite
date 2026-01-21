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

            $result = \array_fill(0, $count, null);
            $pending = $count;
            $rejected = false;

            foreach ($promisesOrValues as $index => $promiseOrValue) {
                if ($promiseOrValue instanceof GQLPromise) {
                    $promiseOrValue->then(
                        function ($value) use ($index, &$result, &$pending, &$rejected, $resolve) {
                            if ($rejected) {
                                return;
                            }
                            $result[$index] = $value;
                            $pending--;
                            if ($pending === 0) {
                                \ksort($result);
                                $resolve($result);
                            }
                        },
                        function ($error) use (&$rejected, $reject) {
                            if (!$rejected) {
                                $rejected = true;
                                $reject($error);
                            }
                        }
                    );
                } else {
                    $result[$index] = $promiseOrValue;
                    $pending--;
                    if ($pending === 0 && !$rejected) {
                        \ksort($result);
                        $resolve($result);
                    }
                }
            }
        });
    }
}
