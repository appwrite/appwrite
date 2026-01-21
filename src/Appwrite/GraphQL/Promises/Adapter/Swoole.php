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

        // Create the combined promise without executor
        $combinedPromise = new SwoolePromise();

        $count = 0;
        $result = [];
        $rejected = false;

        $checkComplete = static function () use (&$count, $total, &$result, &$rejected, $combinedPromise): void {
            if (!$rejected && $count === $total) {
                \ksort($result);
                $combinedPromise->resolve($result);
            }
        };

        foreach ($promisesOrValues as $index => $promiseOrValue) {
            if ($promiseOrValue instanceof GQLPromise) {
                $result[$index] = null;
                /** @var SwoolePromise $adopted */
                $adopted = $promiseOrValue->adoptedPromise;
                $adopted->then(
                    static function ($value) use (&$result, $index, &$count, $checkComplete) {
                        $result[$index] = $value;
                        ++$count;
                        $checkComplete();
                        return $value;
                    },
                    static function ($error) use (&$rejected, $combinedPromise) {
                        if (!$rejected) {
                            $rejected = true;
                            $combinedPromise->reject($error);
                        }
                    }
                );
            } else {
                $result[$index] = $promiseOrValue;
                ++$count;
            }
        }

        $checkComplete();

        return new GQLPromise($combinedPromise, $this);
    }
}
