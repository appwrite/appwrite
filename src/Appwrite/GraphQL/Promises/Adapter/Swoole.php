<?php

namespace Appwrite\GraphQL\Promises\Adapter;

use Appwrite\GraphQL\Promises\Adapter;
use Appwrite\Promises\Swoole as SwoolePromise;
use GraphQL\Executor\Promise\Promise as GQLPromise;

class Swoole extends Adapter
{
    /**
     * Create a new promise
     *
     * @param callable $resolver
     *
     * @return GQLPromise
     */
    public function create(callable $resolver): GQLPromise
    {
        $promise = new SwoolePromise(function ($resolve, $reject) use ($resolver) {
            try {
                $resolver($resolve, $reject);
            } catch (\Throwable $exception) {
                $reject($exception);
            } catch (\Exception $exception) {
                $reject($exception);
            }
        });

        return new GQLPromise($promise, $this);
    }

    /**
     * Create a fulfilled promise
     *
     * @param null $value
     *
     * @return GQLPromise
     */
    public function createFulfilled($value = null): GQLPromise
    {
        return $this->create(function ($resolve) use ($value) {
            $resolve($value);
        });
    }

    /**
     * Create a rejected promise
     *
     * @param $reason
     *
     * @return GQLPromise
     */
    public function createRejected($reason): GQLPromise
    {
        return $this->create(function ($resolve, $reject) use ($reason) {
            $reject($reason);
        });
    }

    /**
     * Create a promise that is fulfilled with an array of fulfillment values for
     * the passed promises, or rejected with the reason of the first passed
     * promise that is rejected.
     *
     * @param array $promisesOrValues
     *
     * @return GQLPromise
     */
    public function all(array $promisesOrValues): GQLPromise
    {
        return new GQLPromise(SwoolePromise::all($promisesOrValues), $this);
    }
}
