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
        $total = \count($promisesOrValues);

        if ($total === 0) {
            return $this->createFulfilled([]);
        }

        // Shared state across callbacks
        $count = 0;
        $result = [];
        $rejected = false;
        $resolveCallback = null;
        $rejectCallback = null;

        $resolveAllWhenFinished = function () use (&$count, $total, &$result, &$rejected, &$resolveCallback): void {
            if (!$rejected && $count === $total && $resolveCallback !== null) {
                \ksort($result);
                $resolveCallback($result);
            }
        };

        // Create the combined promise - the executor captures the resolve/reject callbacks
        $combinedPromise = new SwoolePromise(function ($resolve, $reject) use (&$resolveCallback, &$rejectCallback) {
            $resolveCallback = $resolve;
            $rejectCallback = $reject;
        });

        // Register then callbacks on each input promise
        foreach ($promisesOrValues as $index => $promiseOrValue) {
            if ($promiseOrValue instanceof GQLPromise) {
                $result[$index] = null;
                $promiseOrValue->then(
                    static function ($value) use (&$result, $index, &$count, $resolveAllWhenFinished): void {
                        $result[$index] = $value;
                        ++$count;
                        $resolveAllWhenFinished();
                    },
                    static function ($error) use (&$rejected, &$rejectCallback): void {
                        if (!$rejected && $rejectCallback !== null) {
                            $rejected = true;
                            $rejectCallback($error);
                        }
                    }
                );
            } else {
                $result[$index] = $promiseOrValue;
                ++$count;
            }
        }

        // Check if all non-promise values already resolved everything
        $resolveAllWhenFinished();

        return new GQLPromise($combinedPromise, $this);
    }
}
