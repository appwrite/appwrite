<?php

namespace Appwrite\GraphQL;

use GraphQL\Error\InvariantViolation;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Utils\Utils;
use Swoole\Coroutine\Channel;
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
        $promise = new CoroutinePromise(function($resolve, $reject) use($resolver) {
            $resolver($resolve, $reject);
        });

        return new Promise($promise, $this);
    }

    public function createFulfilled($value = null): Promise
    {
        $promise = new CoroutinePromise(function($resolve, $reject) use($value) {
            $resolve($value);
        });

        return new Promise($promise, $this);
    }

    public function createRejected($reason): Promise
    {
        $promise = new CoroutinePromise(function ($resolve, $reject) use ($reason) {
            $reject($reason);
        });

        return new Promise($promise, $this);
    }

    public function all(array $promisesOrValues): Promise
    {
        $all = new CoroutinePromise(function (callable $resolve, callable $reject) use ($promisesOrValues) {
            $ticks = count($promisesOrValues);
            $firstError = null;
            $channel = new Channel($ticks);
            $result = [];
            $key = 0;

            foreach ($promisesOrValues as $promiseOrValue) {
                if (!$promiseOrValue instanceof Promise) {
                    $result[$key] = $promiseOrValue;
                    $channel->push(true);
                }
                $promiseOrValue->then(function ($value) use ($key, &$result, $channel) {
                    $result[$key] = $value;
                    $channel->push(true);
                }, function ($error) use ($channel, &$firstError) {
                    $channel->push(true);
                    if ($firstError === null) {
                        $firstError = $error;
                    }
                });
                $key++;
            }

            while ($ticks--) {
                $channel->pop();
            }
            $channel->close();

            if ($firstError !== null) {
                $reject($firstError);
                return;
            }

            $resolve($result);
        });

        return new Promise($all, $this);
    }
}