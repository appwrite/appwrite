<?php

namespace Appwrite\GraphQL\Promises;

use Appwrite\Promises\Promise;
use GraphQL\Executor\Promise\Promise as GQLPromise;
use GraphQL\Executor\Promise\PromiseAdapter;

abstract class Adapter implements PromiseAdapter
{
    /**
     * Returns true if the given value is a {@see Promise}.
     *
     * @param $value
     * @return bool
     */
    public function isThenable($value): bool
    {
        return $value instanceof Promise;
    }

    /**
     * Converts a {@see Promise} into a {@see GQLPromise}
     *
     * @param mixed $thenable
     * @return GQLPromise
     * @throws \Exception
     */
    public function convertThenable(mixed $thenable): GQLPromise
    {
        if (!$thenable instanceof Promise) {
            throw new \Exception('Expected instance of Promise got: ' . \gettype($thenable));
        }

        return new GQLPromise($thenable, $this);
    }

    /**
     * Returns a promise that resolves when the passed in promise resolves.
     *
     * @param GQLPromise $promise
     * @param callable|null $onFulfilled
     * @param callable|null $onRejected
     * @return GQLPromise
     */
    public function then(
        GQLPromise $promise,
        ?callable $onFulfilled = null,
        ?callable $onRejected = null
    ): GQLPromise {
        /** @var Promise $adoptedPromise */
        $adoptedPromise = $promise->adoptedPromise;

        return new GQLPromise($adoptedPromise->then($onFulfilled, $onRejected), $this);
    }

    /**
     * Create a new promise with the given resolver function.
     *
     * @param callable $resolver
     * @return GQLPromise
     */
    abstract public function create(callable $resolver): GQLPromise;

    /**
     * Create a new promise that is fulfilled with the given value.
     *
     * @param mixed $value
     * @return GQLPromise
     */
    abstract public function createFulfilled(mixed $value = null): GQLPromise;

    /**
     * Create a new promise that is rejected with the given reason.
     *
     * @param mixed $reason
     * @return GQLPromise
     */
    abstract public function createRejected(mixed $reason): GQLPromise;

    /**
     * Create a new promise that resolves when all passed in promises resolve.
     *
     * @param array $promisesOrValues
     * @return GQLPromise
     */
    abstract public function all(array $promisesOrValues): GQLPromise;
}
