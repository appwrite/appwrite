<?php

namespace Appwrite\GraphQL;

use GraphQL\Error\InvariantViolation;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Utils\Utils;
use function Co\go;
use function Co\run;

class SwoolePromiseAdapter implements PromiseAdapter
{
    public function isThenable($value): bool
    {
        return $value instanceof Promise;
    }

    public function convertThenable($thenable): Promise
    {
        if (!$thenable instanceof Promise) {
            throw new InvariantViolation('Expected instance of SwoolePromise, got ' . Utils::printSafe($thenable));
        }
        return new Promise($thenable, $this);
    }

    public function then(Promise $promise, ?callable $onFulfilled = null, ?callable $onRejected = null): Promise
    {
        /** @var SwoolePromise $adoptedPromise */
        $adoptedPromise = $promise->adoptedPromise;

        return new Promise($adoptedPromise->then($onFulfilled, $onRejected), $this);
    }

    public function create(callable $resolver): Promise
    {
        $promise = new SwoolePromise();
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
        $promise = new SwoolePromise();

        return new Promise($promise->resolve($value), $this);
    }

    public function createRejected($reason): Promise
    {
        $promise = new SwoolePromise();

        return new Promise($promise->reject($reason), $this);
    }

    public function all(array $promisesOrValues): Promise
    {
        $all = new SwoolePromise();

        $total = count($promisesOrValues);
        $count = 0;
        $result = [];

        run(function ($promisesOrValues, $all, $total, &$count, $result) {
            foreach ($promisesOrValues as $index => $promiseOrValue) {
                go(function ($index, $promiseOrValue, $all, $total, &$count, $result) {
                    if (!($promiseOrValue instanceof SwoolePromise)) {
                        $result[$index] = $promiseOrValue;
                        $count++;
                        return;
                    }
                    $result[$index] = null;
                    $promiseOrValue->then(
                        static function ($value) use ($index, &$count, $total, &$result, $all): void {
                            $result[$index] = $value;
                            $count++;
                            if ($count < $total) {
                                return;
                            }
                            $all->resolve($result);
                        },
                        [$all, 'reject']
                    );
                }, $index, $promiseOrValue, $all, $total, $count, $result);
            }
        }, $promisesOrValues, $all, $total, $count, $result);

        if ($count === $total) {
            $all->resolve($result);
        }

        return new Promise($all, $this);
    }
}