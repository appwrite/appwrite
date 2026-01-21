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

        // Extract adopted promises from GQLPromises
        $extracted = [];
        foreach ($promisesOrValues as $index => $promiseOrValue) {
            if ($promiseOrValue instanceof GQLPromise) {
                $extracted[$index] = $promiseOrValue->adoptedPromise;
            } else {
                $extracted[$index] = $promiseOrValue;
            }
        }

        // Use SwoolePromise::all to wait for all promises
        $combinedPromise = SwoolePromise::all($extracted);

        return new GQLPromise($combinedPromise, $this);
    }
}
