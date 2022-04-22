<?php

namespace Appwrite\GraphQL;

use GraphQL\Error\InvariantViolation;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Utils\Utils;
use function Co\go;

class CoroutinePromiseAdapter implements PromiseAdapter
{
    public function isThenable($value): bool
    {
        return $value instanceof CoroutinePromise;
    }

    public function convertThenable($thenable): Promise
    {
        if (!$thenable instanceof CoroutinePromise) {
            throw new InvariantViolation('Expected instance of SwoolePromise, got ' . Utils::printSafe($thenable));
        }
        return new Promise($thenable, $this);
    }

    public function then(Promise $promise, ?callable $onFulfilled = null, ?callable $onRejected = null): Promise
    {
        /** @var CoroutinePromise $adoptedPromise */
        $adoptedPromise = $promise->adoptedPromise;

        return new Promise($adoptedPromise->then($onFulfilled, $onRejected), $this);
    }

    public function create(callable $resolver): Promise
    {
        $promise = new CoroutinePromise();
        try {
            $resolver(
                [$promise, 'resolve'],
                [$promise, 'reject'],
            );
        } catch (\Throwable $e) {
            $promise->reject($e);
        }
        return new Promise($promise, $this);
    }

    public function createFulfilled($value = null): Promise
    {
        $promise = new CoroutinePromise();

        return new Promise($promise->resolve($value), $this);
    }

    public function createRejected($reason): Promise
    {
        $promise = new CoroutinePromise();

        return new Promise($promise->reject($reason), $this);
    }

    public function all(array $promisesOrValues): Promise
    {
        $all = new CoroutinePromise();

        $total = count($promisesOrValues);
        $count = 0;
        $result = [];

        foreach ($promisesOrValues as $index => $promiseOrValue) {
            go(function ($index, $promiseOrValue, $all, $total, &$count, $result) {
                if (!($promiseOrValue instanceof CoroutinePromise)) {
                    $result[$index] = $promiseOrValue;
                    $count++;
                    return;
                }
                $result[$index] = null;
                $promiseOrValue->then(
                    function ($value) use ($index, &$count, $total, &$result, $all): void {
                        $result[$index] = $value;
                        if ($count++ === $total) {
                            $all->resolve($result);
                        }
                    },
                    [$all, 'reject']
                );
            }, $index, $promiseOrValue, $all, $total, $count, $result);
        }

        if ($count === $total) {
            $all->resolve($result);
        }

        return new Promise($all, $this);
    }
}