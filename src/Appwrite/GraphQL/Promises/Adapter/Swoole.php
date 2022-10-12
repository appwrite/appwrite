<?php

namespace Appwrite\GraphQL\Promises\Adapter;

use Appwrite\GraphQL\Promises\Adapter;
use GraphQL\Executor\Promise\Promise as GQLPromise;

class Swoole extends Adapter
{
    public function create(callable $resolver): GQLPromise
    {
        $promise = new Swoole(function ($resolve, $reject) use ($resolver) {
            $resolver($resolve, $reject);
        });

        return new GQLPromise($promise, $this);
    }

    public function createFulfilled($value = null): GQLPromise
    {
        $promise = new Swoole(function ($resolve, $reject) use ($value) {
            $resolve($value);
        });

        return new GQLPromise($promise, $this);
    }

    public function createRejected($reason): GQLPromise
    {
        $promise = new Swoole(function ($resolve, $reject) use ($reason) {
            $reject($reason);
        });

        return new GQLPromise($promise, $this);
    }

    public function all(array $promisesOrValues): GQLPromise
    {
        return new GQLPromise(Swoole::all($promisesOrValues), $this);
    }
}
