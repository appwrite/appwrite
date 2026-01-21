<?php

namespace Appwrite\GraphQL\Promises\Adapter;

use Appwrite\GraphQL\Promises\Adapter;
use Appwrite\Promises\Swoole as SwoolePromise;
use GraphQL\Executor\Promise\Promise as GQLPromise;

class Swoole extends Adapter
{
    /**
     * Wait for promise completion and return the result.
     * Since callbacks are executed synchronously, the promise should already be settled.
     *
     * @param GQLPromise $promise
     * @return mixed
     * @throws \Throwable
     */
    public function wait(GQLPromise $promise): mixed
    {
        /** @var SwoolePromise $swoolePromise */
        $swoolePromise = $promise->adoptedPromise;

        if ($swoolePromise->state === SwoolePromise::FULFILLED) {
            return $swoolePromise->result;
        }

        if ($swoolePromise->state === SwoolePromise::REJECTED) {
            throw $swoolePromise->result;
        }

        // Promise should already be settled in synchronous mode
        throw new \Exception('Could not resolve promise - still pending');
    }

    public function create(callable $resolver): GQLPromise
    {
        // Create without executor - don't enqueue anything
        $promise = new SwoolePromise();

        try {
            // Call resolver synchronously - it may call resolve/reject
            $resolver(
                [$promise, 'resolve'],
                [$promise, 'reject']
            );
        } catch (\Throwable $e) {
            $promise->reject($e);
        }

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
